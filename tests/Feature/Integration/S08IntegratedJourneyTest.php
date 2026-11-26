<?php

namespace Tests\Feature\Integration;

use App\Models\AchievementEntry;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\IncomingReceipt;
use App\Models\Payout;
use App\Models\PayoutFragment;
use App\Models\TrainingPurchase;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\MoneyMovementService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\Support\MarketplaceFixture;
use Tests\TestCase;

/** One persisted HTTP journey across the real billing, money, training and points services. */
class S08IntegratedJourneyTest extends TestCase
{
    use MarketplaceFixture, RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', config('database.default'));
        $this->assertStringContainsString('test', (string) config('database.connections.pgsql.database'));
        $this->assertStringContainsString('test', DB::connection()->getDatabaseName());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertStringContainsString('test', (string) config('database.connections.pgsql.database'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(Carbon::parse('2026-11-26 10:00:00', 'Asia/Jakarta'));
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('gcs');
        $this->seed(RolePermissionSeeder::class);
        $this->fixturePin = (string) random_int(100000, 999999);
        $this->fixtureBankNumber = '000'.random_int(1000000, 9999999);
        $this->founder = User::factory()->create(['name' => 'Pendiri Integrasi']);
        $this->sender = User::factory()->create(['name' => 'Anggota Integrasi', 'pin_hash' => Hash::make($this->fixturePin)]);
        $this->recipient = User::factory()->create(['name' => 'Pelatih Integrasi']);
        $this->admin = User::factory()->create(['name' => 'Admin Integrasi']);
        $this->community = Community::factory()->approved()->for($this->founder, 'owner')->create([
            'name' => 'Organisasi Integrasi', 'dues_amount' => 50000, 'entry_fee' => 10000,
            'dues_bank_name' => 'Bank Fixture', 'dues_account_number' => '00000011', 'dues_account_name' => 'Kas Integrasi',
        ]);
        CommunityMember::factory()->for($this->community)->for($this->recipient)->create(['role' => 'coach']);
        CommunityMember::factory()->for($this->community)->for($this->admin)->admin()->create();
        $this->ledger = app(LedgerService::class);
        $this->movement = app(MoneyMovementService::class);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_general_organization_registration_moderation_trial_and_platform_payment_are_distinct_and_connected(): void
    {
        Passport::actingAs($this->sender);
        $assets = [];
        foreach (['community-logo', 'community-photo'] as $index => $type) {
            $assets[] = $this->postJson('/api/v1/assets/upload', ['type' => $type, 'file' => UploadedFile::fake()->image('organization-'.$index.'.png', 30 + $index, 30)])->assertCreated()->json('data.id');
        }
        $created = $this->postJson('/api/v1/communities', [
            'name' => 'Organisasi Sosial Integrasi', 'short_name' => 'OSI', 'type' => 'lainnya', 'type_other' => 'Organisasi Sosial',
            'description' => 'Organisasi umum untuk kegiatan sosial dan pengelolaan iuran anggota secara tertib.',
            'address' => 'Alamat Organisasi Fixture Bandung', 'province_code' => '32', 'city_code' => '32.73',
            'contact_name' => 'Kontak Organisasi Fixture', 'contact_phone' => '080000000001',
            'contact_email' => 'integrasi@anaklangit.manahpro.web.id', 'logo_asset_id' => $assets[0], 'photo_asset_ids' => [$assets[1]],
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.type_label', 'Organisasi Sosial')->json('data.id');
        $community = Community::query()->findOrFail($created);
        $base = '/api/v1/communities/'.$created;
        $founder = $community->membershipOf($this->sender);
        $this->assertSame('creator', $founder->role->value);
        $this->assertSame('active', $founder->status->value);
        $this->getJson($base.'/billing?scope=platform')->assertOk()->assertJsonPath('data.entitlement.state', 'trial')->assertJsonPath('data.entitlement.trial_start', '2026-11-26')->assertJsonPath('data.entitlement.trial_end', '2026-12-03')->assertJsonCount(0, 'data.invoices');
        $this->getJson('/api/v1/public/communities?search=Organisasi Sosial Integrasi')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/admin/communities/'.$created.'/review', ['status' => 'approved', 'expected_status' => 'pending'])->assertForbidden();
        $moderator = User::factory()->create();
        $moderator->assignRole('super-admin');
        Passport::actingAs($moderator);
        $this->putJson('/api/v1/admin/communities/'.$created.'/review', ['status' => 'approved', 'expected_status' => 'pending'])->assertOk()->assertJsonPath('data.status', 'approved');
        $moderationInstant = $community->fresh()->reviewed_at;
        $this->getJson('/api/v1/public/communities?search=Organisasi Sosial Integrasi')->assertOk()->assertJsonPath('data.0.id', $created);
        Passport::actingAs($this->sender);
        $this->patchJson($base.'/billing/settings', ['dues_amount' => 0, 'entry_fee' => 0, 'period_months' => 1, 'dues_bank_name' => 'Bank Fixture', 'dues_account_number' => '00000022', 'dues_account_name' => 'Kas Organisasi Fixture'])->assertOk();
        $proof = $this->postJson('/api/v1/assets/upload', ['type' => 'topup-proof', 'file' => UploadedFile::fake()->image('organization-topup.png', 32, 30)])->assertCreated()->json('data.id');
        $receipt = $this->postJson($base.'/topups', ['claimed_amount' => 100000, 'proof_asset_id' => $proof, 'bank_reference' => 'S08-ORG-INCOMING', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->assertWallet(0, 0, 0);
        $this->postJson($base.'/topups/'.$receipt.'/verify', ['verified_amount' => 100000, 'bank_reference' => 'S08-ORG-VERIFIED', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->assertWallet(100000, 0, 0);
        $this->travelTo(Carbon::parse('2026-12-02 23:59:59', 'Asia/Jakarta'));
        $this->getJson($base.'/billing?scope=platform')->assertOk()->assertJsonPath('data.entitlement.active', true);
        $this->travelTo(Carbon::parse('2026-12-03 00:00:00', 'Asia/Jakarta'));
        $billing = $this->getJson($base.'/billing?scope=platform')->assertOk()->assertJsonPath('data.entitlement.state', 'expired')->assertJsonPath('data.entitlement.arrears_amount', 100000)->json('data');
        $pay = ['scope' => 'platform', 'invoice_ids' => array_column($billing['invoices'], 'id'), 'expected_amount' => 100000, 'idempotency_key' => (string) Str::uuid()];
        $this->postJson($base.'/billing/pay', $pay)->assertOk()->assertJsonPath('data.entitlement.active', true)->assertJsonPath('data.entitlement.arrears_amount', 0);
        $this->postJson($base.'/billing/pay', $pay)->assertOk();
        $this->assertWallet(0, 0, 0);
        $this->assertSame(100000, $this->ledger->platformAccount()->balance);
        $this->assertSame(0, $this->ledger->communityAccount($community)->balance);
        $this->assertSame('approved', $community->fresh()->status->value);
        $this->assertTrue($moderationInstant->equalTo($community->fresh()->reviewed_at));
        $this->assertSame('active', $founder->fresh()->status->value);
        $this->getJson($base.'/billing?scope=platform')->assertOk()->assertJsonPath('data.entitlement.active', true);
    }

    public function test_discovery_to_paid_membership_training_trade_transfer_manual_payout_and_migration_preserves_every_rupiah(): void
    {
        $base = '/api/v1/communities/'.$this->community->id;
        $this->getJson('/api/v1/public/communities?search=Organisasi Integrasi')->assertOk()->assertJsonPath('data.0.id', $this->community->id);
        $this->getJson('/api/v1/public/members?search=Pelatih Integrasi')->assertOk()->assertJsonPath('data.0.community.id', $this->community->id)->assertJsonMissingPath('data.0.email');
        $this->postJson($base.'/join')->assertUnauthorized();
        $this->postJson('/api/v1/marketplace/orders', [])->assertUnauthorized();
        $this->getJson('/api/v1/wallet')->assertUnauthorized();

        Passport::actingAs($this->sender);
        $memberId = $this->postJson($base.'/join')->assertOk()->assertJsonPath('data.status', 'active')->json('data.id');
        $this->getJson($base.'/billing')->assertOk()->assertJsonPath('data.entitlement.state', 'trial')->assertJsonPath('data.entitlement.trial_end', '2026-12-03');
        $this->putJson('/api/v1/bank-accounts/primary', $this->bankData())->assertOk();
        $proof = $this->postJson('/api/v1/assets/upload', ['type' => 'topup-proof', 'file' => UploadedFile::fake()->image('incoming.png', 24, 24)])->assertCreated()->json('data.id');
        $incoming = ['claimed_amount' => 1000000, 'proof_asset_id' => $proof, 'bank_reference' => 'S08-INCOMING', 'idempotency_key' => (string) Str::uuid()];
        $receipt = $this->postJson($base.'/topups', $incoming)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson($base.'/topups', $incoming)->assertCreated()->assertJsonPath('data.id', $receipt);
        $this->assertWallet(0, 0, 0);
        $this->postJson($base.'/topups/'.$receipt.'/verify', ['verified_amount' => 1000000, 'bank_reference' => 'S08-INCOMING', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();

        Passport::actingAs($this->admin);
        $verified = ['verified_amount' => 1000000, 'bank_reference' => 'S08-BANK-VERIFIED', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson($base.'/topups/'.$receipt.'/verify', $verified)->assertOk()->assertJsonPath('data.status', 'verified');
        $this->postJson($base.'/topups/'.$receipt.'/verify', $verified)->assertOk();
        $this->assertWallet(1000000, 0, 0);
        $this->assertDatabaseHas('incoming_receipts', ['id' => $receipt, 'reviewed_by' => $this->admin->id, 'verified_amount' => 1000000]);

        // The owner pays the separate platform obligation before the shared trial expires.
        $this->fund($this->founder, $this->community, $this->founder, 100000);
        Passport::actingAs($this->founder);
        $this->postJson($base.'/billing/renew', ['scope' => 'platform'])->assertOk();
        $this->postJson($base.'/billing/pay', ['scope' => 'platform', 'expected_amount' => 100000, 'idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.entitlement.active', true);
        $this->assertSame(100000, $this->ledger->platformAccount()->balance);
        $this->travelTo(Carbon::parse('2026-12-03 00:00:00', 'Asia/Jakarta'));
        Passport::actingAs($this->sender);
        $billing = $this->getJson($base.'/billing')->assertOk()->assertJsonPath('data.entitlement.state', 'expired')->assertJsonPath('data.entitlement.arrears_amount', 60000)->json('data');
        $this->getJson($base.'/training')->assertOk()->assertJsonPath('data.active', false);
        $this->postJson($base.'/training/purchases', ['sessions' => 7, 'expected_price' => 100003, 'catalog_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertStatus(402);
        $pay = ['scope' => 'member', 'invoice_ids' => array_column($billing['invoices'], 'id'), 'expected_amount' => 60000, 'idempotency_key' => (string) Str::uuid()];
        $this->postJson($base.'/billing/pay', $pay)->assertOk()->assertJsonPath('data.entitlement.active', true)->assertJsonPath('data.entitlement.arrears_amount', 0);
        $this->postJson($base.'/billing/pay', $pay)->assertOk();
        $this->getJson($base.'/card')->assertOk()->assertJsonPath('data.validity.status', 'active');
        $this->assertWallet(940000, 0, 0);

        Passport::actingAs($this->founder);
        $training = $base.'/training';
        $this->putJson($training.'/catalog', ['expected_version' => 0, 'enabled' => true, 'packages' => [['sessions' => 7, 'price' => 100003], ['sessions' => 14, 'price' => 180001], ['sessions' => 21, 'price' => 240001]]])->assertOk();
        $coach = $this->community->membershipOf($this->recipient);
        $locations = [];
        foreach (['Lapangan Integrasi A', 'Lapangan Integrasi B'] as $name) {
            $locations[] = $this->postJson($training.'/locations', ['name' => $name, 'address' => 'Alamat Fixture', 'active' => true, 'manager_member_ids' => [$coach->id], 'splits' => [['member_id' => $coach->id, 'amount' => 6000]]])->assertOk()->json('data.id');
        }
        Passport::actingAs($this->sender);
        $purchaseData = ['sessions' => 7, 'expected_price' => 100003, 'catalog_version' => 1, 'idempotency_key' => (string) Str::uuid()];
        $purchase = $this->postJson($training.'/purchases', $purchaseData)->assertCreated()->json('data.id');
        $this->postJson($training.'/purchases', $purchaseData)->assertCreated()->assertJsonPath('data.id', $purchase);
        $this->assertWallet(839997, 100003, 0);
        foreach ($locations as $index => $location) {
            Passport::actingAs($this->recipient);
            $session = $this->postJson($training.'/sessions', ['title' => 'Sesi Integrasi', 'location_id' => $location, 'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String(), 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
            Passport::actingAs($this->sender);
            $this->postJson($training.'/sessions/'.$session.'/check-in', ['idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.status', 'pending');
            $this->assertSame($index + 1, (int) AchievementEntry::query()->sum('points'));
            Passport::actingAs($this->recipient);
            $confirm = ['member_id' => $memberId, 'idempotency_key' => (string) Str::uuid()];
            $this->postJson($training.'/sessions/'.$session.'/confirm', $confirm)->assertOk()->assertJsonPath('data.allocation', 14286)->assertJsonPath('data.snapshot.platform_amount', 0);
            $this->postJson($training.'/sessions/'.$session.'/confirm', $confirm)->assertOk();
            $this->assertWallet(839997, 100003 - 14286 * ($index + 1), 0);
        }
        $this->assertSame(5, TrainingPurchase::query()->findOrFail($purchase)->remaining_sessions);
        $this->assertSame(3, (int) AchievementEntry::query()->sum('points'));
        $this->assertSame(12000, $this->ledger->wallet($this->recipient)['available']);
        $this->assertSame(76572, $this->ledger->communityAccount($this->community)->balance);
        $this->assertSame(100000, $this->ledger->platformAccount()->balance);

        // The same global wallet now buys a real product in another community.
        $other = Community::factory()->approved()->for($this->recipient, 'owner')->create(['name' => 'Katalog Lintas Komunitas']);
        Passport::actingAs($this->recipient);
        $product = $this->postJson('/api/v1/marketplace/products', [...$this->productData(stock: 2, price: 70000), 'community_id' => $other->id])->assertCreated()->json('data');
        $this->getJson('/api/v1/public/products?search=Raket Fixture')->assertOk()->assertJsonPath('data.items.0.id', $product['id']);
        Passport::actingAs($this->sender);
        $transfer = $this->transferData(40000);
        $transferId = $this->postJson('/api/v1/transfers', $transfer)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/transfers', $transfer)->assertCreated()->assertJsonPath('data.id', $transferId);
        $this->assertWallet(799997, 71431, 0);
        $order = ['product_id' => $product['id'], 'quantity' => 1, 'expected_version' => $product['version'], 'expected_unit_price' => 70000, 'pin' => $this->fixturePin, 'acknowledged' => true, 'idempotency_key' => (string) Str::uuid()];
        $orderId = $this->postJson('/api/v1/marketplace/orders', $order)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/marketplace/orders', $order)->assertCreated()->assertJsonPath('data.id', $orderId);
        $this->getJson('/api/v1/marketplace/orders/'.$orderId)->assertOk()->assertJsonPath('data.no_refund', true);
        $this->assertWallet(729997, 71431, 0);
        $this->assertSame(122000, $this->ledger->wallet($this->recipient)['available']);
        $this->assertNull($other->membershipOf($this->sender));

        $payoutData = $this->payoutData(70000);
        $payoutId = $this->postJson('/api/v1/payouts', $payoutData)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/payouts', $payoutData)->assertCreated()->assertJsonPath('data.id', $payoutId);
        $this->assertWallet(659997, 71431, 70000);
        $beforeFailures = $this->moneyState();
        $this->postJson('/api/v1/payouts', $this->payoutData(660000))->assertUnprocessable();
        $this->assertSame($beforeFailures, $this->moneyState());
        $payout = Payout::query()->findOrFail($payoutId);
        $part = $this->part($payout);
        Passport::actingAs($this->recipient);
        $this->getJson('/api/v1/payouts/'.$payoutId)->assertForbidden();
        $this->getJson($base.'/topups/'.$receipt)->assertForbidden();
        Passport::actingAs($this->admin);
        $this->postJson('/api/v1/payout-parts/'.$part->id.'/process', ['expected_version' => $part->version, 'note' => 'Proses sintetis integrasi', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        foreach ([20000, 50000] as $index => $amount) {
            $proof = $this->postJson('/api/v1/assets/upload', ['type' => 'payout-proof', 'file' => UploadedFile::fake()->image('outgoing-'.$index.'.png', 25 + $index, 24)])->assertCreated()->json('data.id');
            $this->postJson('/api/v1/payout-parts/'.$part->id.'/proofs', ['expected_version' => $part->fresh()->version, 'proof_asset_id' => $proof, 'amount' => $amount, 'bank_reference' => 'S08-OUTGOING-'.$index, 'account_number' => $this->fixtureBankNumber, 'account_name' => 'Pihak Ketiga Fixture', 'note' => 'Bukti sintetis integrasi tanpa transfer bank nyata', 'idempotency_key' => (string) Str::uuid()])->assertOk();
            $fragment = PayoutFragment::query()->where('proof_asset_id', $proof)->firstOrFail();
            $verify = ['expected_version' => $part->fresh()->version, 'idempotency_key' => (string) Str::uuid()];
            $url = '/api/v1/payout-parts/'.$part->id.'/fragments/'.$fragment->id.'/verify';
            $this->postJson($url, $verify)->assertOk()->assertJsonPath('data.status', $index === 0 ? 'uncertain' : 'succeeded');
            $this->postJson($url, $verify)->assertOk();
            $this->assertWallet(659997, 71431, $index === 0 ? 50000 : 0);
        }
        $this->getJson($base.'/payout-reconciliation')->assertOk()->assertJsonPath('data.balanced', true)->assertJsonPath('data.verified_amount', 70000);
        $this->assertSame(3, (int) AchievementEntry::query()->sum('points'));
        $this->assertSame(100000, $this->ledger->platformAccount()->balance);
        $this->assertNotNull(IncomingReceipt::query()->findOrFail($receipt)->reviewed_at);

        // Both manual achievement sources join final training sources, with a prospective central rule.
        $activity = ['member_id' => $memberId, 'type' => 'participation', 'title' => 'Kegiatan Integrasi', 'reference' => 'S08-PARTICIPATION', 'occurred_at' => now('Asia/Jakarta')->toDateString(), 'idempotency_key' => (string) Str::uuid()];
        $this->postJson($base.'/achievements', $activity)->assertCreated();
        $this->postJson($base.'/achievements', $activity)->assertCreated();
        $central = User::factory()->create();
        $central->assignRole('super-admin');
        Passport::actingAs($central);
        $this->travel(1)->seconds();
        $this->putJson('/api/v1/admin/achievement-rules', ['expected_version' => 1, 'points' => ['training_purchase' => 2, 'training_attendance' => 3, 'participation' => 4, 'champion' => 5], 'age_boundaries' => [12, 16, 19, 36, 51], 'reason' => 'Aturan prospektif integrasi'])->assertOk()->assertJsonPath('data.version', 2);
        Passport::actingAs($this->admin);
        $this->postJson($base.'/achievements', [...$activity, 'type' => 'champion', 'reference' => 'S08-CHAMPION', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.points', 5);
        $this->assertSame(9, (int) AchievementEntry::query()->sum('points'));
        $this->getJson('/api/v1/public/rankings?scope=communities&period=all')->assertOk()->assertJsonPath('data.items.0.points', 9);
        $this->getJson('/api/v1/public/rankings?scope=members&period=all&community_id='.$this->community->id)->assertOk()->assertJsonPath('data.items.0.points', 9);

        Passport::actingAs($this->recipient);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/marketplace/products', [...$this->productData(), 'community_id' => $other->id])->assertCreated();
        }
        $this->postJson('/api/v1/marketplace/products', [...$this->productData(), 'community_id' => $other->id])->assertUnprocessable();
        $advertisement = $this->postJson('/api/v1/advertisements', ['community_id' => $other->id, 'title' => 'Iklan Integrasi', 'body' => 'Katalog anggota lintas komunitas', 'target_product_id' => $product['id'], 'is_active' => true])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/public/advertisements?community_id='.$other->id)->assertOk()->assertJsonPath('data.items.0.id', $advertisement);
        $this->assertWallet(659997, 71431, 0);

        // Re-running the release migration command over populated financial data is a no-op.
        $snapshot = $this->persistentSnapshot();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame($snapshot, $this->persistentSnapshot());
        $this->assertWallet(659997, 71431, 0);
        Passport::actingAs($this->sender);
        $this->getJson('/api/v1/wallet/transactions')->assertOk()->assertJsonStructure(['data' => ['items' => [['actor', 'created_at', 'community_id']]]]);
        $this->getJson('/api/v1/achievements/me')->assertOk()->assertJsonPath('data.total_points', 9);
    }

    private function assertWallet(int $available, int $reserved, int $held): void
    {
        $wallet = $this->ledger->wallet($this->sender);
        $this->assertSame($available, $wallet['available']);
        $this->assertSame($reserved, $wallet['reserved']);
        $this->assertSame($held, $wallet['held']);
        $this->assertSame($available + $reserved + $held, $wallet['total']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
        $this->assertSame(0, DB::table('ledger_accounts')->where('allow_negative', false)->where('balance', '<', 0)->count());
    }

    /** @return array<string,mixed> */
    private function persistentSnapshot(): array
    {
        $snapshot = $this->moneyState();
        foreach (['incoming_receipts', 'billing_subjects', 'billing_invoices', 'billing_allocations', 'training_purchases', 'training_attendances', 'training_final_events', 'marketplace_products', 'marketplace_orders', 'member_transfers', 'member_advertisements', 'achievement_entries', 'achievement_rules'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
    }
}
