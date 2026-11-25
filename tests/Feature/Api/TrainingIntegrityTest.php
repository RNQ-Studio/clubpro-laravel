<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\LedgerJournal;
use App\Models\LedgerReservation;
use App\Models\MemberIdentity;
use App\Models\TrainingAttendance;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Independent regression coverage for atomic failure, privacy and historical snapshots. */
class TrainingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $founder;

    private User $athlete;

    private User $coach;

    private Community $community;

    private CommunityMember $athleteMember;

    private CommunityMember $coachMember;

    private CommunityMember $founderMember;

    private TrainingLocation $location;

    private TrainingService $training;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('gcs');
        $this->seed(RolePermissionSeeder::class);
        $this->founder = User::factory()->create(['name' => 'Pendiri Fixture']);
        $this->community = Community::factory()->approved()->for($this->founder, 'owner')->create([
            'dues_bank_name' => 'Bank Fixture', 'dues_account_number' => '00000987', 'dues_account_name' => 'Kas Latihan Fixture',
        ]);
        $this->athlete = User::factory()->create(['name' => 'Atlet Fixture']);
        $this->coach = User::factory()->create(['name' => 'Pelatih Fixture']);
        $this->athleteMember = CommunityMember::factory()->for($this->community)->for($this->athlete)->create();
        $this->coachMember = CommunityMember::factory()->for($this->community)->for($this->coach)->create(['role' => 'coach']);
        $this->founderMember = $this->community->members()->where('user_id', $this->founder->id)->firstOrFail();
        $this->training = app(TrainingService::class);
        $this->ledger = app(LedgerService::class);
        $this->training->saveCatalog($this->founder, $this->community, $this->catalog());
        $location = $this->training->saveLocation($this->founder, $this->community, $this->locationData());
        $this->location = TrainingLocation::query()->findOrFail($location['id']);

        $asset = Asset::factory()->create(['user_id' => $this->athlete->id, 'path' => 'training-integrity/'.Str::uuid().'.png', 'storage_type' => StorageType::Private, 'mime_type' => 'image/png']);
        Storage::disk('local')->put($asset->path, random_bytes(32));
        $receipts = app(IncomingReceiptService::class);
        $receipt = $receipts->submit($this->athlete, $this->community, ['purpose' => 'topup', 'claimed_amount' => 300000, 'proof_asset_id' => $asset->id, 'bank_reference' => 'integrity-fixture', 'idempotency_key' => (string) Str::uuid()]);
        $receipts->verify($this->founder, $receipt, 300000, 'INTEGRITY-'.Str::uuid(), (string) Str::uuid());
    }

    public function test_failure_during_lot_distribution_rolls_back_every_prior_write_and_retry_can_finish_once(): void
    {
        $purchase = $this->purchase();
        $session = $this->newSession();
        $before = $this->moneyState($purchase);
        $communityAccount = $this->ledger->communityAccount($this->community);
        $function = 'test_training_fail_lot_'.str_replace('-', '', (string) Str::uuid());
        $trigger = 'test_training_fail_lot';
        // The community residual is moved after the recipient's lot. A database
        // fault here occurs after journal/cache postings and the first lot move.
        DB::unprepared('CREATE FUNCTION '.$function.'() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.account_id = '.$communityAccount->id.' AND NEW.amount > 0 THEN RAISE EXCEPTION \'training lot fixture failure\'; END IF; RETURN NEW; END $$');
        DB::unprepared('CREATE TRIGGER '.$trigger.' BEFORE INSERT OR UPDATE ON ledger_lot_holdings FOR EACH ROW EXECUTE FUNCTION '.$function.'()');
        $body = ['member_id' => $this->athleteMember->id, 'idempotency_key' => 'partial-failure-retry'];
        try {
            $this->training->confirm($this->founder, $this->community, $session, $body);
            $this->fail('The injected residual-lot failure did not occur.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('training lot fixture failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER '.$trigger.' ON ledger_lot_holdings');
            DB::unprepared('DROP FUNCTION '.$function.'()');
        }

        $this->assertSame($before, $this->moneyState($purchase));
        $this->assertSame(0, TrainingAttendance::query()->count());
        $this->assertSame(1, TrainingFinalEvent::query()->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);

        $attendance = $this->training->confirm($this->founder, $this->community, $session, $body);
        $this->assertSame('confirmed', $attendance->status);
        $this->assertSame(6, $purchase->fresh()->remaining_sessions);
        $this->assertSame(85717, $purchase->fresh()->remaining_amount);
        $this->assertSame(199997, $this->ledger->wallet($this->athlete)['available']);
        $this->assertSame(6000, $this->ledger->wallet($this->coach)['available']);
        $this->assertSame(8286, $communityAccount->fresh()->balance);
        $this->assertSame($attendance->id, $this->training->confirm($this->founder, $this->community, $session, $body)->id);
        $this->assertSame(1, TrainingAttendance::query()->count());
        $this->assertTrue($this->training->report($this->founder, $this->community)['totals']['balanced']);
    }

    public function test_managing_one_location_does_not_expose_another_locations_attendance_or_other_recipients(): void
    {
        $this->purchase();
        $first = $this->confirm($this->newSession());
        $other = $this->training->saveLocation($this->founder, $this->community, [
            ...$this->locationData(), 'name' => 'Lokasi Di Luar Penugasan', 'manager_member_ids' => [],
            'splits' => [['member_id' => $this->coachMember->id, 'amount' => 3000], ['member_id' => $this->founderMember->id, 'amount' => 2000]],
        ]);
        $second = $this->confirm($this->newSession($other['id']));
        $managed = $this->training->report($this->coach, $this->community);
        $this->assertSame('managed_locations', $managed['scope']);
        $this->assertSame([$first->id], array_column($managed['attendances'], 'id'));
        $this->assertSame([$this->location->id], array_column($managed['locations'], 'location_id'));
        $this->assertSame(6000, collect($managed['recipients'])->firstWhere('member_id', $this->coachMember->id)['amount']);
        $this->assertNotContains($second->id, array_column($managed['attendances'], 'id'));
        $this->assertSame([], $managed['purchases']);
        $this->assertArrayNotHasKey('purchased_amount', $managed['totals']);
        $this->assertNull($managed['reconciliation']);

        $this->coachMember->update(['role' => 'member']);
        $own = $this->training->report($this->coach, $this->community);
        $this->assertSame('recipient', $own['scope']);
        $this->assertSame(9000, $own['totals']['allocated_amount']);
        $this->assertSame([$this->coachMember->id], array_column($own['recipients'], 'member_id'));
        $this->assertSame([], $own['attendances']);
        $this->assertSame([], $own['locations']);
        $this->assertSame([], $own['purchases']);

        $foreignFilter = $this->training->report($this->coach, $this->community, ['recipient_member_id' => $this->founderMember->id]);
        $this->assertSame(0, $foreignFilter['totals']['allocated_amount']);
        $this->assertSame([], $foreignFilter['recipients']);
    }

    public function test_price_role_name_and_location_changes_preserve_final_snapshots_and_only_next_confirmation_uses_new_rules(): void
    {
        $purchase = $this->purchase();
        $first = $this->confirm($this->newSession());
        $snapshot = $first->fresh()->snapshot;
        $journalMetadata = LedgerJournal::query()->findOrFail($first->journal_id)->metadata;
        $finalEvent = TrainingFinalEvent::query()->where('type', 'training_attendance_final')->where('source_id', $first->id)->firstOrFail();
        $eventPayload = $finalEvent->payload;
        $this->coachMember->update(['role' => 'officer']);
        MemberIdentity::query()->whereKey($this->coachMember->member_identity_id)->update(['name' => 'Pengurus Setelah Perubahan']);
        $this->training->saveCatalog($this->founder, $this->community, [
            ...$this->catalog(), 'expected_version' => 1,
            'packages' => [['sessions' => 7, 'price' => 200003], ['sessions' => 14, 'price' => 360001], ['sessions' => 21, 'price' => 480001]],
        ]);
        $this->training->saveLocation($this->founder, $this->community, [
            ...$this->locationData(), 'expected_version' => 1, 'name' => 'Nama Lokasi Baru',
            'splits' => [['member_id' => $this->coachMember->id, 'amount' => 1000]],
        ], $this->location);
        $second = $this->confirm($this->newSession());

        $this->assertSame($snapshot, $first->fresh()->snapshot);
        $this->assertSame($journalMetadata, LedgerJournal::query()->findOrFail($first->journal_id)->metadata);
        $this->assertSame($eventPayload, $finalEvent->fresh()->payload);
        $this->assertSame('Pelatih Fixture', $first->fresh()->snapshot['splits'][0]['name']);
        $this->assertSame('coach', $first->fresh()->snapshot['splits'][0]['role']);
        $this->assertSame('Lokasi Awal', $first->fresh()->snapshot['location_name']);
        $this->assertSame('Pengurus Setelah Perubahan', $second->snapshot['splits'][0]['name']);
        $this->assertSame('officer', $second->snapshot['splits'][0]['role']);
        $this->assertSame(1000, $second->snapshot['splits'][0]['amount']);
        $this->assertSame(2, $second->snapshot['location_version']);
        $this->assertSame('Nama Lokasi Baru', $second->snapshot['location_name']);
        $this->assertSame(14286, $second->allocation);
        $this->assertSame(100003, $purchase->fresh()->price);
        $this->assertSame(7, $purchase->fresh()->snapshot['sessions']);
        $this->assertSame(71431, $purchase->fresh()->remaining_amount);
        $this->assertSame(199997, $this->ledger->wallet($this->athlete)['available']);
        $data = $this->training->attendanceData($first->fresh());
        $this->assertSame('Lokasi Awal', $data['location_name']);
        $this->assertSame($finalEvent->id, $data['event_id']);
        $this->assertTrue($this->training->report($this->founder, $this->community)['totals']['balanced']);
    }

    public function test_database_guards_keep_final_purchase_attendance_and_events_immutable_even_through_direct_sql(): void
    {
        $purchase = $this->purchase();
        $attendance = $this->confirm($this->newSession());
        $event = TrainingFinalEvent::query()->where('type', 'training_attendance_final')->where('source_id', $attendance->id)->firstOrFail();
        $this->rejectSql(fn () => DB::table('training_purchases')->where('id', $purchase->id)->update(['price' => 1]));
        $this->rejectSql(fn () => DB::table('training_purchases')->where('id', $purchase->id)->update(['snapshot' => '{}']));
        $this->rejectSql(fn () => DB::table('training_attendances')->where('id', $attendance->id)->update(['allocation' => 1]));
        $this->rejectSql(fn () => DB::table('training_attendances')->where('id', $attendance->id)->update(['status' => 'pending']));
        $this->rejectSql(fn () => DB::table('training_final_events')->where('id', $event->id)->update(['payload' => '{}']));
        $this->rejectSql(fn () => DB::table('training_final_events')->where('id', $event->id)->delete());
        $this->rejectSql(fn () => DB::table('training_attendances')->where('id', $attendance->id)->delete());
        $this->assertSame(100003, $purchase->fresh()->price);
        $this->assertSame('confirmed', $attendance->fresh()->status);
        $this->assertSame(14286, $attendance->fresh()->allocation);
        $this->assertSame($event->payload, $event->fresh()->payload);
        $this->assertTrue($this->training->report($this->founder, $this->community)['totals']['balanced']);
    }

    private function rejectSql(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('Direct SQL changed immutable financial history.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('P0001', $exception->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function catalog(): array
    {
        return ['expected_version' => 0, 'enabled' => true, 'packages' => [['sessions' => 7, 'price' => 100003], ['sessions' => 14, 'price' => 180001], ['sessions' => 21, 'price' => 240001]]];
    }

    /** @return array<string,mixed> */
    private function locationData(): array
    {
        return ['name' => 'Lokasi Awal', 'address' => 'Alamat Fixture', 'active' => true, 'manager_member_ids' => [$this->coachMember->id], 'splits' => [['member_id' => $this->coachMember->id, 'amount' => 6000]]];
    }

    private function purchase(): TrainingPurchase
    {
        return $this->training->purchase($this->athlete, $this->community, ['sessions' => 7, 'expected_price' => 100003, 'catalog_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function newSession(?int $locationId = null): TrainingSession
    {
        $session = $this->training->createSession($this->founder, $this->community, ['location_id' => $locationId ?? $this->location->id, 'title' => 'Sesi Integritas', 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String(), 'idempotency_key' => (string) Str::uuid()]);

        return TrainingSession::query()->findOrFail($session['id']);
    }

    private function confirm(TrainingSession $session): TrainingAttendance
    {
        return $this->training->confirm($this->founder, $this->community, $session, ['member_id' => $this->athleteMember->id, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @return array<string,mixed> */
    private function moneyState(TrainingPurchase $purchase): array
    {
        $purchase->refresh();

        return [
            'available' => $this->ledger->wallet($this->athlete)['available'],
            'reserved' => $this->ledger->wallet($this->athlete)['reserved'],
            'recipient' => $this->ledger->wallet($this->coach)['available'],
            'community' => $this->ledger->communityAccount($this->community)->balance,
            'remaining_sessions' => $purchase->remaining_sessions,
            'remaining_amount' => $purchase->remaining_amount,
            'reservation_remaining' => LedgerReservation::query()->findOrFail($purchase->reservation_id)->remaining,
            'journals' => LedgerJournal::query()->count(),
            'entries' => DB::table('ledger_entries')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'lots' => DB::table('ledger_lot_holdings')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }
}
