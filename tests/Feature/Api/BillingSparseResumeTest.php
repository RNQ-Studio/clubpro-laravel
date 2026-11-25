<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\BillingInvoice;
use App\Models\BillingSubject;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\DuesPayment;
use App\Models\User;
use App\Services\BillingService;
use App\Services\DuesService;
use App\Services\IncomingReceiptService;
use App\Services\LedgerService;
use App\Support\Enums\StorageType;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Sparse future legacy payments must not pay for uncovered time after a member returns. */
class BillingSparseResumeTest extends TestCase
{
    use RefreshDatabase;

    private User $founder;

    private User $member;

    private Community $community;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('local');
        Storage::fake('gcs');
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-02-15 12:00:00', 'Asia/Jakarta'));
        $this->founder = User::factory()->create();
        $this->community = Community::factory()->approved()->for($this->founder, 'owner')->create([
            'created_at' => '2025-12-01 00:00:00', 'dues_amount' => 50000, 'entry_fee' => 0,
            'dues_start_date' => '2026-01-10', 'dues_period_months' => 1,
            'dues_bank_name' => 'Bank Fixture', 'dues_account_number' => '1234567890',
            'dues_account_name' => 'Komunitas Fixture', 'billing_cutover_date' => '2026-02-15',
        ]);
        // Keep the platform valid independently, so member access tests cannot pass
        // merely because the community subscription happens to be expired.
        $this->fund($this->founder, 200000);
        app(BillingService::class)->pay($this->founder, $this->community, 'platform', [], (string) Str::uuid());
        $this->member = User::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** @return array<string,array{string}> */
    public static function futureCommitments(): array
    {
        return ['future month already paid' => ['approved'], 'future proof still pending' => ['pending']];
    }

    #[DataProvider('futureCommitments')]
    public function test_resumed_time_requires_its_own_payment_without_losing_sparse_future_commitment(string $futureStatus): void
    {
        $membership = CommunityMember::factory()->for($this->community)->for($this->member)->create([
            'billing_scheme' => 'legacy_calendar', 'status' => 'archived',
            'joined_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-20 00:00:00',
        ]);
        $this->legacyPayment('2026-01', 'approved');
        $future = $this->legacyPayment('2026-05', $futureStatus);
        $this->assertSame(0, BillingSubject::query()->where('member_id', $membership->id)->count());
        Passport::actingAs($this->member);
        $this->postJson($this->base().'/join')->assertOk()->assertJsonPath('data.status', 'active');
        $summary = $this->getJson($this->billing())->assertOk()
            ->assertJsonPath('data.entitlement.state', 'expired')
            ->assertJsonPath('data.entitlement.active', false)
            ->assertJsonPath('data.entitlement.trial_start', null)
            ->assertJsonPath('data.entitlement.arrears_amount', 50000)->json('data');
        $this->getJson($this->base().'/members')->assertStatus(402);
        $subject = BillingSubject::query()->where('member_id', $membership->id)->sole();
        $current = $this->invoice($subject, '2026-02-15');
        $this->assertSame('2026-03-15', $current->period_end->toDateString());
        $this->assertSame(50000, $current->amount);
        $preserved = BillingInvoice::query()->where('subject_id', $subject->id)->where('legacy_month', '2026-05')->sole();
        $preservedId = $preserved->id;
        $this->assertSame('2026-05-11', $preserved->period_start->toDateString());
        $this->assertSame('2026-06-11', $preserved->period_end->toDateString());
        $this->assertSame(50000, $preserved->amount);
        $this->assertSame($futureStatus === 'approved' ? 'paid' : 'open', $preserved->status);
        $this->assertArchiveGapAndNoOverlaps($subject);
        $this->fund($this->member, 500000);
        $key = (string) Str::uuid();
        $this->pay($current, $key)->assertJsonPath('data.entitlement.active', true)
            ->assertJsonPath('data.entitlement.valid_until', '2026-03-15');
        $this->pay($current, $key)->assertJsonPath('data.entitlement.active', true);
        $this->getJson($this->base().'/members')->assertOk();
        if ($futureStatus === 'pending') {
            // The old pending proof can still settle the original future month.
            // It must not pay for the as-yet unissued March and April intervals.
            app(DuesService::class)->approve($this->founder, $future, 50000, 'SPARSE-FUTURE-'.Str::uuid());
            $this->assertSame('approved', $future->fresh()->status->value);
            $this->assertSame('paid', $preserved->fresh()->status);
        }
        $this->getJson($this->billing())->assertOk()
            ->assertJsonPath('data.entitlement.active', true)
            ->assertJsonPath('data.entitlement.valid_until', '2026-03-15');
        $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'Asia/Jakarta'));
        $this->getJson($this->billing())->assertOk()->assertJsonPath('data.entitlement.active', false)
            ->assertJsonPath('data.entitlement.arrears_amount', 50000);
        $march = $this->invoice($subject, '2026-03-15');
        $this->assertSame('2026-04-15', $march->period_end->toDateString());
        $this->pay($march)->assertJsonPath('data.entitlement.active', true)
            ->assertJsonPath('data.entitlement.valid_until', '2026-04-15');
        $this->travelTo(CarbonImmutable::parse('2026-04-15 12:00:00', 'Asia/Jakarta'));
        $this->getJson($this->billing())->assertOk()->assertJsonPath('data.entitlement.active', false)
            ->assertJsonPath('data.entitlement.arrears_amount', 43333);
        $bridge = $this->invoice($subject, '2026-04-15');
        $this->assertSame('2026-05-11', $bridge->period_end->toDateString());
        $this->assertSame(43333, $bridge->amount, '26 of 30 days, rounded to the nearest whole rupiah.');
        $this->assertIsInt($bridge->amount);
        $this->assertTrue($bridge->snapshot['prorated']);
        $this->assertSame(50000, $bridge->snapshot['nominal_amount']);
        $this->assertSame('2026-04-15', $bridge->snapshot['nominal_period_start']);
        $this->assertSame('2026-05-15', $bridge->snapshot['nominal_period_end']);
        $this->assertSame(30, $bridge->snapshot['nominal_days']);
        $this->assertSame(26, $bridge->snapshot['covered_days']);
        $this->assertSame('nearest_rupiah_minimum_one', $bridge->snapshot['rounding']);
        $this->assertSame('protected_legacy_commitment', $bridge->snapshot['reason']);
        $this->pay($bridge)->assertJsonPath('data.entitlement.active', true)
            ->assertJsonPath('data.entitlement.valid_until', '2026-06-11');
        $this->travelTo(CarbonImmutable::parse('2026-06-11 12:00:00', 'Asia/Jakarta'));
        $this->getJson($this->billing())->assertOk()->assertJsonPath('data.entitlement.active', false)
            ->assertJsonPath('data.entitlement.arrears_amount', 50000);
        $june = $this->invoice($subject, '2026-06-11');
        $this->assertSame('2026-07-11', $june->period_end->toDateString());
        $this->assertSame(50000, $june->amount);
        $preserved->refresh();
        $this->assertSame($preservedId, $preserved->id);
        $this->assertSame('2026-05-11', $preserved->period_start->toDateString());
        $this->assertSame('2026-06-11', $preserved->period_end->toDateString());
        $this->assertSame(50000, $preserved->amount);
        $this->assertSame(50000, $preserved->paid_amount);
        $this->assertSame('paid', $preserved->status);
        $this->assertNull($subject->fresh()->trial_start);
        $this->assertArchiveGapAndNoOverlaps($subject);
        $this->assertTrue(app(LedgerService::class)->reconcile()['balanced']);
    }

    private function assertArchiveGapAndNoOverlaps(BillingSubject $subject): void
    {
        $invoices = BillingInvoice::query()->where('subject_id', $subject->id)->where('kind', 'member')->where('status', '!=', 'void')->orderBy('period_start')->get();
        $previousEnd = null;
        foreach ($invoices as $invoice) {
            $start = $invoice->period_start->toDateString();
            $end = $invoice->period_end->toDateString();
            $this->assertFalse($start >= '2026-01-21' && $start < '2026-02-15', 'No invoice is issued for an archived interval.');
            if ($previousEnd !== null) {
                $this->assertGreaterThanOrEqual($previousEnd, $start, 'Future legacy rights and resumed charges cannot overlap.');
            }
            $previousEnd = $end;
        }
    }

    private function invoice(BillingSubject $subject, string $start): BillingInvoice
    {
        return BillingInvoice::query()->where('subject_id', $subject->id)->where('kind', 'member')->whereDate('period_start', $start)->sole();
    }

    private function legacyPayment(string $month, string $status): DuesPayment
    {
        $proof = $this->proof($this->member);
        $payment = DuesPayment::query()->forceCreate([
            'community_id' => $this->community->id, 'user_id' => $this->member->id,
            'months_count' => 1, 'months' => [$month], 'monthly_amount' => 50000,
            'entry_fee' => 0, 'total_amount' => 50000, 'proof_asset_id' => $proof->id,
            'status' => $status, 'created_at' => '2026-01-15 00:00:00',
            'reviewed_at' => $status === 'approved' ? '2026-01-15 00:00:00' : null,
            'reviewed_by' => $status === 'approved' ? $this->founder->id : null,
        ]);
        $this->community->duesMonths()->create([
            'user_id' => $this->member->id, 'month' => $month.'-01',
            'status' => $status === 'approved' ? 'paid' : 'pending', 'dues_payment_id' => $payment->id,
        ]);

        return $payment;
    }

    private function proof(User $owner): Asset
    {
        $proof = Asset::factory()->create(['user_id' => $owner->id, 'storage_type' => StorageType::Private, 'size' => 1000]);
        Storage::disk('local')->put($proof->path, 'sparse-resume-proof:'.Str::uuid());

        return $proof;
    }

    private function fund(User $owner, int $amount): void
    {
        $receipt = app(IncomingReceiptService::class)->submit($owner, $this->community, [
            'claimed_amount' => $amount, 'proof_asset_id' => $this->proof($owner)->id,
            'bank_reference' => 'SPARSE-FUND-'.Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
        ]);
        app(IncomingReceiptService::class)->verify($this->founder, $receipt, $amount, 'SPARSE-VERIFIED-'.Str::uuid(), (string) Str::uuid());
    }

    private function base(): string
    {
        return '/api/v1/communities/'.$this->community->id;
    }

    private function billing(): string
    {
        return $this->base().'/billing';
    }

    private function pay(BillingInvoice $invoice, ?string $key = null): TestResponse
    {
        return $this->postJson($this->billing().'/pay', [
            'scope' => 'member', 'invoice_ids' => [$invoice->id],
            'expected_amount' => $invoice->amount, 'idempotency_key' => $key ?? (string) Str::uuid(),
        ])->assertOk();
    }
}
