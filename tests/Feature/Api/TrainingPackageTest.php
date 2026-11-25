<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\LedgerJournal;
use App\Models\LedgerReservation;
use App\Models\MemberIdentity;
use App\Models\TrainingFinalEvent;
use App\Models\TrainingLocation;
use App\Models\TrainingPurchase;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\IncomingReceiptService;
use App\Services\LedgerService;
use App\Services\TrainingService;
use App\Support\Enums\StorageType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TrainingPackageTest extends TestCase
{
    use RefreshDatabase;

    private User $founder;

    private User $athlete;

    private User $coach;

    private Community $community;

    private CommunityMember $member;

    private CommunityMember $trainer;

    private TrainingService $training;

    private LedgerService $ledger;

    private TrainingLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('gcs');
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(RolePermissionSeeder::class);
        $this->founder = User::factory()->create();
        $this->community = Community::factory()->approved()->for($this->founder, 'owner')->create(['dues_bank_name' => 'Bank Fixture', 'dues_account_number' => '00000123', 'dues_account_name' => 'Kas Latihan']);
        $this->athlete = User::factory()->create();
        $this->coach = User::factory()->create();
        $this->member = CommunityMember::factory()->create(['community_id' => $this->community->id, 'user_id' => $this->athlete->id]);
        $this->trainer = CommunityMember::factory()->create(['community_id' => $this->community->id, 'user_id' => $this->coach->id, 'role' => 'coach']);
        $this->training = app(TrainingService::class);
        $this->ledger = app(LedgerService::class);
        $this->training->saveCatalog($this->founder, $this->community, $this->catalog());
        $location = $this->training->saveLocation($this->founder, $this->community, $this->locationBody());
        $this->location = TrainingLocation::query()->findOrFail($location['id']);
        $this->fund($this->athlete, 1500000);
    }

    public function test_each_package_reserves_once_and_full_depletion_reconciles_exact_rupiah_without_second_debit(): void
    {
        foreach ([7 => 100003, 14 => 180001, 21 => 240001] as $sessions => $price) {
            $before = $this->ledger->wallet($this->athlete)['available'];
            $purchase = $this->buy($sessions, $price);
            $this->assertSame($before - $price, $this->ledger->wallet($this->athlete)['available']);
            $sum = 0;
            for ($i = 0; $i < $sessions; $i++) {
                $attendance = $this->training->confirm($this->coach, $this->community, $this->newSession(), ['member_id' => $this->member->id, 'idempotency_key' => (string) Str::uuid()]);
                $this->assertSame($purchase->id, $attendance->purchase_id);
                $this->assertSame($i === $sessions - 1 ? $price - intdiv($price, $sessions) * ($sessions - 1) : intdiv($price, $sessions), $attendance->allocation);
                $this->assertSame($before - $price, $this->ledger->wallet($this->athlete)['available']);
                $this->assertSame(0, $attendance->snapshot['platform_amount']);
                $sum += $attendance->allocation;
            }
            $this->assertSame($price, $sum);
            $purchase->refresh();
            $this->assertSame(0, $purchase->remaining_amount);
            $this->assertSame(0, $purchase->remaining_sessions);
            $this->assertSame(0, LedgerReservation::query()->findOrFail($purchase->reservation_id)->remaining);
        }
        $report = $this->training->report($this->founder, $this->community);
        $this->assertTrue($report['totals']['balanced']);
        $this->assertSame(520005, $report['totals']['purchased_amount']);
        $this->assertSame(520005, $report['totals']['allocated_amount']);
        $this->assertSame(42, $report['totals']['used_sessions']);
        $this->assertSame(0, $report['totals']['reserved_amount']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
        $this->assertSame(45, TrainingFinalEvent::query()->count());
    }

    public function test_purchase_retry_quote_insufficient_funds_and_monotonic_earliest_selection(): void
    {
        Passport::actingAs($this->athlete);
        $body = $this->buyBody();
        $one = $this->postJson($this->url('/purchases'), $body)->assertCreated()->json('data.id');
        $two = $this->postJson($this->url('/purchases'), $body)->assertCreated()->json('data.id');
        $this->assertSame($one, $two);
        $this->postJson($this->url('/purchases'), [...$body, 'expected_price' => 100004])->assertConflict();
        $later = $this->buy();
        $a = $this->training->confirm($this->coach, $this->community, $this->newSession(), ['member_id' => $this->member->id, 'idempotency_key' => 'first']);
        $this->assertSame($one, $a->purchase_id);
        $this->assertSame(7, $later->refresh()->remaining_sessions);
        $this->training->saveCatalog($this->founder, $this->community, [...$this->catalog(), 'expected_version' => 1]);
        $this->postJson($this->url('/purchases'), $this->buyBody())->assertConflict();
        $poor = User::factory()->create();
        CommunityMember::factory()->create(['community_id' => $this->community->id, 'user_id' => $poor->id]);
        Passport::actingAs($poor);
        $this->postJson($this->url('/purchases'), [...$this->buyBody(), 'catalog_version' => 2])->assertUnprocessable();
        $this->assertSame(2, TrainingPurchase::query()->count());
        $this->assertSame(0, $this->ledger->wallet($poor)['reserved']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_self_checkin_is_pending_and_staff_confirmation_is_final_idempotent_and_unique(): void
    {
        $purchase = $this->buy();
        $session = $this->newSession();
        $key = ['idempotency_key' => 'checkin-one'];
        Passport::actingAs($this->athlete);
        $id = $this->postJson($this->url('/sessions/'.$session->id.'/check-in'), $key)->assertOk()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertSame(7, $purchase->refresh()->remaining_sessions);
        $this->postJson($this->url('/sessions/'.$session->id.'/check-in'), $key)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($this->url('/sessions/'.$this->newSession()->id.'/check-in'), $key)->assertConflict();
        $body = ['member_id' => $this->member->id, 'idempotency_key' => 'confirm-one'];
        $this->postJson($this->url('/sessions/'.$session->id.'/confirm'), $body)->assertForbidden();
        Passport::actingAs($this->coach);
        $this->postJson($this->url('/sessions/'.$session->id.'/confirm'), $body)->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.confirmed_by', $this->coach->name);
        $this->postJson($this->url('/sessions/'.$session->id.'/confirm'), $body)->assertOk();
        $this->postJson($this->url('/sessions/'.$session->id.'/confirm'), [...$body, 'idempotency_key' => 'another'])->assertConflict();
        $this->assertSame(6, $purchase->refresh()->remaining_sessions);
        $this->assertSame(2, TrainingFinalEvent::query()->count());
    }

    public function test_two_locations_and_changed_settings_only_affect_next_confirmation_not_history(): void
    {
        $purchase = $this->buy();
        $first = $this->training->confirm($this->coach, $this->community, $this->newSession(), ['member_id' => $this->member->id, 'idempotency_key' => 'old']);
        $snapshot = $first->snapshot;
        $nextLocation = $this->training->saveLocation($this->founder, $this->community, [...$this->locationBody(), 'name' => 'Lapangan Kedua', 'splits' => [['member_id' => $this->trainer->id, 'amount' => 5000]]]);
        $second = $this->training->confirm($this->coach, $this->community, $this->newSession($nextLocation['id']), ['member_id' => $this->member->id, 'idempotency_key' => 'new']);
        $this->assertSame(5000, $second->snapshot['splits'][0]['amount']);
        $this->assertSame(6000, $first->refresh()->snapshot['splits'][0]['amount']);
        $this->assertSame($this->ledger->payloadHash($snapshot), $this->ledger->payloadHash($first->snapshot));
        $this->assertSame(5, $purchase->refresh()->remaining_sessions);
        $this->training->saveCatalog($this->founder, $this->community, [...$this->catalog(), 'expected_version' => 1, 'packages' => [['sessions' => 7, 'price' => 200003], ['sessions' => 14, 'price' => 360001], ['sessions' => 21, 'price' => 480001]]]);
        Passport::actingAs($this->founder);
        $this->putJson($this->url('/locations/'.$this->location->id), [...$this->locationBody(), 'expected_version' => 1, 'splits' => [['member_id' => $this->trainer->id, 'amount' => 20000]]])->assertUnprocessable();
        $this->assertSame(100003, $purchase->refresh()->price);
    }

    public function test_founder_only_settings_discount_and_split_validation_location_scope_and_time(): void
    {
        $admin = User::factory()->create();
        CommunityMember::factory()->create(['community_id' => $this->community->id, 'user_id' => $admin->id, 'role' => 'admin']);
        Passport::actingAs($admin);
        $this->putJson($this->url('/catalog'), [...$this->catalog(), 'expected_version' => 1])->assertForbidden();
        Passport::actingAs($this->founder);
        $this->putJson($this->url('/catalog'), [...$this->catalog(), 'expected_version' => 1, 'packages' => [['sessions' => 7, 'price' => 100003], ['sessions' => 14, 'price' => 200006], ['sessions' => 21, 'price' => 240001]]])->assertUnprocessable();
        $this->postJson($this->url('/locations'), [...$this->locationBody(), 'splits' => [['member_id' => $this->trainer->id, 'amount' => -1]]])->assertUnprocessable();
        $this->postJson($this->url('/locations'), [...$this->locationBody(), 'splits' => [['member_id' => $this->trainer->id, 'amount' => 11429]]])->assertUnprocessable();
        $outsider = Community::factory()->approved()->create();
        $otherMember = $outsider->members()->firstOrFail();
        $this->postJson($this->url('/locations'), [...$this->locationBody(), 'manager_member_ids' => [$otherMember->id]])->assertNotFound();
        $this->putJson($this->url('/locations/'.$this->location->id), $this->locationBody())->assertUnprocessable();
        $this->buy();
        $future = $this->newSession(null, now()->addDay()->toIso8601String(), now()->addDay()->addHour()->toIso8601String());
        Passport::actingAs($this->coach);
        $this->postJson($this->url('/sessions/'.$future->id.'/confirm'), ['member_id' => $this->member->id, 'idempotency_key' => 'future'])->assertUnprocessable();
        $this->member->update(['status' => 'archived']);
        $this->postJson($this->url('/sessions/'.$this->newSession()->id.'/confirm'), ['member_id' => $this->member->id, 'idempotency_key' => 'inactive'])->assertStatus(402);
        $this->assertSame(7, TrainingPurchase::query()->firstOrFail()->remaining_sessions);
    }

    public function test_correction_preserves_original_snapshot_events_and_reuses_restored_remainder_without_refund(): void
    {
        $purchase = $this->buy();
        $all = [];
        for ($i = 0; $i < 7; $i++) {
            $all[] = $this->training->confirm($this->coach, $this->community, $this->newSession(), ['member_id' => $this->member->id, 'idempotency_key' => 'consume-'.$i]);
        }
        $originalSnapshot = $all[0]->snapshot;
        $available = $this->ledger->wallet($this->athlete)['available'];
        foreach ([0, 6] as $index) {
            $body = ['reason' => 'Insiden teknis salah konfirmasi fixture', 'idempotency_key' => 'correct-'.$index];
            $corrected = $this->training->correct($this->founder, $this->community, $all[$index], $body);
            $this->assertSame('corrected', $corrected->status);
            $this->assertSame($corrected->id, $this->training->correct($this->founder, $this->community, $all[$index], $body)->id);
        }
        $this->assertSame(2, $purchase->refresh()->remaining_sessions);
        $this->assertSame(28573, $purchase->remaining_amount);
        $this->assertSame($available, $this->ledger->wallet($this->athlete)['available']);
        $this->assertSame($this->ledger->payloadHash($originalSnapshot), $this->ledger->payloadHash($all[0]->refresh()->snapshot));
        $this->assertSame(7, TrainingFinalEvent::query()->where('type', 'training_attendance_final')->count());
        $this->assertSame(2, TrainingFinalEvent::query()->where('type', 'training_attendance_corrected')->count());
        for ($i = 0; $i < 2; $i++) {
            $a = $this->training->confirm($this->coach, $this->community, $this->newSession(), ['member_id' => $this->member->id, 'idempotency_key' => 'reused-'.$i]);
            $this->assertSame($i === 0 ? 14286 : 14287, $a->allocation);
        }
        $this->assertTrue($this->training->report($this->founder, $this->community)['totals']['balanced']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_failed_correction_when_recipient_has_spent_rolls_back_everything(): void
    {
        $purchase = $this->buy();
        $attendance = $this->training->confirm($this->coach, $this->community, $this->newSession(), ['member_id' => $this->member->id, 'idempotency_key' => 'spend-confirm']);
        $wallet = $this->ledger->walletAccount(MemberIdentity::forUser($this->coach));
        $this->ledger->transfer($this->coach, 'spent', 'fixture_spent', $wallet, $this->ledger->communityAccount($this->community), 6000, $this->community);
        $before = LedgerJournal::query()->count();
        Passport::actingAs($this->founder);
        $this->postJson($this->url('/attendances/'.$attendance->id.'/correct'), ['reason' => 'Insiden salah absensi teknis', 'idempotency_key' => 'insufficient-correction'])->assertUnprocessable()->assertJsonPath('message', 'Dana penerima belum mencukupi untuk koreksi. Jatah dan dana tetap utuh.');
        $this->assertSame('confirmed', $attendance->refresh()->status);
        $this->assertSame(6, $purchase->refresh()->remaining_sessions);
        $this->assertSame($before, LedgerJournal::query()->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_report_scope_filters_and_reconciliation_detect_reservation_tampering(): void
    {
        $purchase = $this->buy();
        $session = $this->newSession();
        $this->training->confirm($this->coach, $this->community, $session, ['member_id' => $this->member->id, 'idempotency_key' => 'report']);
        $manager = $this->training->report($this->coach, $this->community);
        $this->assertSame('managed_locations', $manager['scope']);
        $this->assertArrayNotHasKey('purchased_amount', $manager['totals']);
        $this->assertSame([], $manager['purchases']);
        $own = $this->training->report($this->athlete, $this->community);
        $this->assertSame('recipient', $own['scope']);
        $this->assertSame([], $own['attendances']);
        $this->assertSame([], $own['recipients']);
        $global = $this->training->report($this->founder, $this->community, ['from' => now('Asia/Jakarta')->addDay()->toDateString()]);
        $this->assertSame(0, $global['totals']['filtered_allocated_amount']);
        $this->assertSame(14286, $global['totals']['allocated_amount']);
        LedgerReservation::query()->whereKey($purchase->reservation_id)->decrement('remaining', 1);
        $bad = $this->training->report($this->founder, $this->community);
        $this->assertFalse($bad['totals']['balanced']);
        $this->assertSame([$purchase->id], $bad['reconciliation']['reservation_errors']);
    }

    public function test_overview_history_remains_private_and_global_role_does_not_grant_founder_configuration(): void
    {
        $purchase = $this->buy();
        Passport::actingAs($this->athlete);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.self_member_id', $this->member->id)->assertJsonPath('data.can_configure', false)->assertJsonPath('data.purchases.0.id', $purchase->id)->assertJsonCount(0, 'data.members');
        Passport::actingAs($this->coach);
        $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'data.purchases');
        $outsider = User::factory()->create();
        $outsider->assignRole('super-admin');
        Passport::actingAs($outsider);
        $this->getJson($this->url())->assertForbidden();
        $this->putJson($this->url('/catalog'), [...$this->catalog(), 'expected_version' => 1])->assertForbidden();
        $this->getJson('/api/v1/finance/options')->assertOk()->assertJsonPath('data.training_version', 1)->assertJsonPath('data.training_ready', true);
    }

    public function test_real_http_workflow_location_session_purchase_checkin_confirmation_and_report(): void
    {
        Passport::actingAs($this->founder);
        $location = $this->postJson($this->url('/locations'), [...$this->locationBody(), 'name' => 'Lapangan HTTP'])->assertOk()->assertJsonPath('data.version', 1)->json('data.id');
        $session = $this->postJson($this->url('/sessions'), ['title' => 'Sesi HTTP', 'location_id' => $location, 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String(), 'idempotency_key' => 'http-session'])->assertCreated()->json('data.id');
        Passport::actingAs($this->athlete);
        $purchase = $this->postJson($this->url('/purchases'), $this->buyBody())->assertCreated()->json('data.id');
        $this->postJson($this->url('/sessions/'.$session.'/check-in'), ['idempotency_key' => 'http-checkin'])->assertOk()->assertJsonPath('data.status', 'pending');
        Passport::actingAs($this->coach);
        $id = $this->postJson($this->url('/sessions/'.$session.'/confirm'), ['member_id' => $this->member->id, 'idempotency_key' => 'http-confirm'])->assertOk()->assertJsonPath('data.purchase_id', $purchase)->assertJsonPath('data.allocation', 14286)->assertJsonPath('data.snapshot.residual', 8286)->json('data.id');
        Passport::actingAs($this->founder);
        $this->getJson($this->url('/report').'?location_id='.$location.'&recipient_member_id='.$this->trainer->id)->assertOk()->assertJsonPath('data.totals.balanced', true)->assertJsonPath('data.totals.filtered_allocated_amount', 6000)->assertJsonPath('data.reconciliation.ledger_balanced', true);
        $this->postJson($this->url('/attendances/'.$id.'/correct'), ['idempotency_key' => 'http-correct', 'reason' => 'Insiden teknis fixture HTTP salah konfirmasi'])->assertOk()->assertJsonPath('data.status', 'corrected')->assertJsonPath('data.corrected_by', $this->founder->name);
        $this->getJson($this->url('/report'))->assertOk()->assertJsonPath('data.totals.reserved_amount', 100003)->assertJsonPath('data.totals.allocated_amount', 0)->assertJsonPath('data.totals.balanced', true);
    }

    public function test_additive_migration_rerun_preserves_purchased_money_and_snapshots(): void
    {
        $purchase = $this->buy();
        $snapshot = $purchase->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_11_26_050001_create_training_module.php');
        $migration->up();
        $this->assertSame($snapshot, $purchase->fresh()->getAttributes());
        $this->assertSame(100003, LedgerReservation::query()->findOrFail($purchase->reservation_id)->remaining);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
        $migration->down();
        $this->assertNotNull($purchase->fresh());
    }

    public function test_inactive_location_cross_community_routes_and_expired_members_cannot_consume_reserve(): void
    {
        $purchase = $this->buy();
        $session = $this->newSession();
        $other = Community::factory()->approved()->for($this->founder, 'owner')->create();
        Passport::actingAs($this->founder);
        $this->postJson('/api/v1/communities/'.$other->id.'/training/sessions/'.$session->id.'/confirm', ['member_id' => $this->member->id, 'idempotency_key' => 'cross-community'])->assertNotFound();
        $this->location->update(['active' => false]);
        $this->postJson($this->url('/sessions/'.$session->id.'/confirm'), ['member_id' => $this->member->id, 'idempotency_key' => 'inactive-location'])->assertUnprocessable();
        $this->location->update(['active' => true]);
        $this->travel(8)->days();
        Passport::actingAs($this->athlete);
        $this->postJson($this->url('/purchases'), $this->buyBody())->assertStatus(402);
        $this->assertSame(7, $purchase->refresh()->remaining_sessions);
        $this->assertSame(100003, $purchase->remaining_amount);
        $this->travelBack();
    }

    public function test_domain_service_rejects_negative_or_fractional_money_without_writes_and_accepts_explicit_zero(): void
    {
        $count = TrainingLocation::query()->count();
        foreach ([-1, 0.5, '6000'] as $invalid) {
            try {
                $this->training->saveLocation($this->founder, $this->community, [...$this->locationBody(), 'splits' => [['member_id' => $this->trainer->id, 'amount' => $invalid]]]);
                $this->fail('Invalid domain split amount was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }
            $this->assertSame($count, TrainingLocation::query()->count());
            $this->assertSame(1, $this->location->fresh()->version);
        }
        foreach ([100003.5, '100003'] as $invalid) {
            $catalog = [...$this->catalog(), 'expected_version' => 1];
            $catalog['packages'][0]['price'] = $invalid;
            try {
                $this->training->saveCatalog($this->founder, $this->community, $catalog);
                $this->fail('Invalid domain package price was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }
            $this->assertSame(1, $this->training->catalog($this->community)->version);
            $this->assertSame(100003, $this->training->catalog($this->community)->packages[0]['price']);
        }
        $zero = $this->training->saveLocation($this->founder, $this->community, [...$this->locationBody(), 'name' => 'Pembagian Nol', 'splits' => [['member_id' => $this->trainer->id, 'amount' => 0]]]);
        $this->assertSame(0, $zero['splits'][0]['amount']);
        $purchase = $this->buy();
        $attendance = $this->training->confirm($this->coach, $this->community, $this->newSession($zero['id']), ['member_id' => $this->member->id, 'idempotency_key' => 'zero-valid']);
        $this->assertSame(14286, $attendance->snapshot['residual']);
        $this->assertSame(85717, $purchase->refresh()->remaining_amount);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    /** @return array<string,mixed> */
    private function catalog(): array
    {
        return ['expected_version' => 0, 'enabled' => true, 'packages' => [['sessions' => 7, 'price' => 100003], ['sessions' => 14, 'price' => 180001], ['sessions' => 21, 'price' => 240001]]];
    }

    /** @return array<string,mixed> */
    private function locationBody(): array
    {
        return ['name' => 'Lapangan Utama', 'address' => 'Alamat Fixture', 'active' => true, 'manager_member_ids' => [$this->trainer->id], 'splits' => [['member_id' => $this->trainer->id, 'amount' => 6000], ['member_id' => $this->community->members()->where('user_id', $this->founder->id)->firstOrFail()->id, 'amount' => 0]]];
    }

    /** @return array<string,mixed> */
    private function buyBody(int $sessions = 7, int $price = 100003): array
    {
        return ['sessions' => $sessions, 'expected_price' => $price, 'catalog_version' => 1, 'idempotency_key' => (string) Str::uuid()];
    }

    private function buy(int $sessions = 7, int $price = 100003): TrainingPurchase
    {
        return $this->training->purchase($this->athlete, $this->community, $this->buyBody($sessions, $price));
    }

    private function newSession(?int $location = null, ?string $starts = null, ?string $ends = null): TrainingSession
    {
        $data = $this->training->createSession($this->coach, $this->community, ['title' => 'Latihan Fixture', 'location_id' => $location ?? $this->location->id, 'starts_at' => $starts ?? now()->subHour()->toIso8601String(), 'ends_at' => $ends ?? now()->addHour()->toIso8601String(), 'idempotency_key' => (string) Str::uuid()]);

        return TrainingSession::query()->findOrFail($data['id']);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/communities/'.$this->community->id.'/training'.$suffix;
    }

    private function fund(User $user, int $amount): void
    {
        $asset = Asset::factory()->create(['user_id' => $user->id, 'path' => 'training/'.Str::uuid().'.png', 'category' => 'topup-proof', 'storage_type' => StorageType::Private, 'mime_type' => 'image/png']);
        Storage::disk('local')->put($asset->path, random_bytes(32));
        $receipts = app(IncomingReceiptService::class);
        $receipt = $receipts->submit($user, $this->community, ['purpose' => 'topup', 'claimed_amount' => $amount, 'proof_asset_id' => $asset->id, 'bank_reference' => 'training-fixture', 'idempotency_key' => (string) Str::uuid()]);
        $receipts->verify($this->founder, $receipt, $amount, 'training-fund-'.Str::uuid(), (string) Str::uuid());
    }
}
