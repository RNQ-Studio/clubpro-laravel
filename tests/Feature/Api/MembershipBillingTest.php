<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\BillingInvoice;
use App\Models\BillingSubject;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\DuesMonth;
use App\Models\DuesPayment;
use App\Models\GuardianGrant;
use App\Models\IncomingReceipt;
use App\Models\MemberIdentity;
use App\Models\MemberInvitation;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CommunityMemberService;
use App\Services\DuesService;
use App\Services\GuardianService;
use App\Services\IncomingReceiptService;
use App\Services\LedgerService;
use App\Support\Enums\CommunityMemberRole;
use App\Support\Enums\CommunityMemberStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class MembershipBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $founder;

    private User $member;

    private Community $community;

    private CommunityMember $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('gcs');
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2026-01-24 10:00:00', 'Asia/Jakarta'));
        $this->founder = User::factory()->create();
        $this->community = Community::factory()->approved()->for($this->founder, 'owner')->create();
        $this->community->forceFill(['dues_amount' => 50000, 'entry_fee' => 10000, 'dues_start_date' => '2026-01-01', 'dues_bank_name' => 'Bank Fixture', 'dues_account_number' => '1234567890', 'dues_account_name' => 'Komunitas Fixture'])->save();
        $this->member = User::factory()->create();
        $this->membership = CommunityMember::factory()->for($this->community)->for($this->member)->create();
        app(BillingService::class)->initializeJoin($this->membership);
        app(BillingService::class)->platformSubject($this->community, true);
        Passport::actingAs($this->member);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_trial_is_seven_wib_dates_with_exclusive_boundary_and_no_reset_on_rejoin(): void
    {
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.entitlement.state', 'trial')->assertJsonPath('data.entitlement.trial_start', '2026-01-24')->assertJsonPath('data.entitlement.trial_end', '2026-01-31')->assertJsonCount(0, 'data.invoices');
        $this->travelTo(Carbon::parse('2026-01-30 23:59:59', 'Asia/Jakarta'));
        $this->getJson($this->url())->assertJsonPath('data.entitlement.active', true);
        $this->travelTo(Carbon::parse('2026-01-31 00:00:00', 'Asia/Jakarta'));
        $this->getJson($this->url())->assertJsonPath('data.entitlement.state', 'expired')->assertJsonPath('data.entitlement.arrears_amount', 60000);
        $this->membership->update(['status' => CommunityMemberStatus::Archived]);
        app(BillingService::class)->initializeJoin($this->membership);
        $this->assertSame(1, BillingSubject::query()->where('member_identity_id', $this->membership->member_identity_id)->count());
        $this->getJson($this->url())->assertJsonPath('data.entitlement.trial_end', '2026-01-31')->assertJsonPath('data.entitlement.active', false);
    }

    public function test_wallet_payment_is_atomic_oldest_first_reactivates_and_updates_card_and_income(): void
    {
        $this->fund($this->member, 200000);
        $this->travelTo(Carbon::parse('2026-03-01 12:00:00', 'Asia/Jakarta'));
        $before = $this->getJson($this->url())->assertOk()->json('data');
        $this->assertSame(110000, $before['entitlement']['arrears_amount']);
        $ids = array_column($before['invoices'], 'id');
        $key = (string) Str::uuid();
        $this->postJson($this->url('/pay'), ['scope' => 'member', 'invoice_ids' => $ids, 'idempotency_key' => $key])->assertOk()->assertJsonPath('data.entitlement.active', true)->assertJsonPath('data.entitlement.arrears_amount', 0);
        $this->assertSame(90000, app(LedgerService::class)->wallet($this->member)['available']);
        $this->assertSame(110000, app(LedgerService::class)->communityAccount($this->community)->balance);
        $this->assertSame(0, app(LedgerService::class)->platformAccount()->balance);
        $this->getJson('/api/v1/communities/'.$this->community->id.'/card')->assertOk()->assertJsonPath('data.validity.status', 'active')->assertJsonPath('data.entitlement.state', 'active')->assertJsonPath('data.validity.valid_until', '2026-03-30');
        $this->postJson($this->url('/pay'), ['scope' => 'member', 'invoice_ids' => $ids, 'idempotency_key' => $key])->assertOk();
        $this->assertSame(90000, app(LedgerService::class)->wallet($this->member)['available']);
        $this->postJson($this->url('/pay'), ['scope' => 'member', 'invoice_ids' => [$ids[0]], 'idempotency_key' => $key])->assertConflict();
    }

    public function test_insufficient_funds_roll_back_invoice_entry_activation_and_journal(): void
    {
        $this->travelTo(Carbon::parse('2026-01-31 12:00:00', 'Asia/Jakarta'));
        $before = DB::table('ledger_journals')->count();
        $this->postJson($this->url('/pay'), ['scope' => 'member', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame($before, DB::table('ledger_journals')->count());
        $this->assertDatabaseCount('billing_allocations', 0);
        $this->getJson($this->url())->assertJsonPath('data.entitlement.state', 'expired')->assertJsonPath('data.entitlement.arrears_amount', 60000);
    }

    public function test_partial_direct_receipts_do_not_activate_and_excess_explicitly_credits_payer_once(): void
    {
        $this->travelTo(Carbon::parse('2026-01-31 12:00:00', 'Asia/Jakarta'));
        $invoices = $this->getJson($this->url())->json('data.invoices');
        $receipt = $this->receipt($this->member, 20000, 'member', array_column($invoices, 'id'));
        app(IncomingReceiptService::class)->verify($this->founder, $receipt, 20000, 'PARTIAL-A', (string) Str::uuid());
        $this->getJson($this->url())->assertJsonPath('data.entitlement.state', 'expired')->assertJsonPath('data.entitlement.arrears_amount', 40000);
        $this->assertSame(20000, app(LedgerService::class)->communityAccount($this->community)->balance);
        $receipt = $this->receipt($this->member, 50000, 'member', [], true);
        $key = (string) Str::uuid();
        app(IncomingReceiptService::class)->verify($this->founder, $receipt, 50000, 'PARTIAL-B', $key);
        app(IncomingReceiptService::class)->verify($this->founder, $receipt, 50000, 'PARTIAL-B', $key);
        $this->getJson($this->url())->assertJsonPath('data.entitlement.state', 'active')->assertJsonPath('data.entitlement.arrears_amount', 0);
        $this->assertSame(10000, app(LedgerService::class)->wallet($this->member)['available']);
        $this->assertSame(60000, app(LedgerService::class)->communityAccount($this->community)->balance);
    }

    public function test_renewal_can_be_prepaid_without_future_arrears_and_anchor_31_does_not_drift(): void
    {
        $this->fund($this->member, 200000);
        $renewal = $this->postJson($this->url('/renew'), ['scope' => 'member'])->assertOk()->assertJsonPath('data.entitlement.arrears_amount', 0)->json('data.invoices');
        $this->assertSame('2026-01-31', $renewal[0]['period_start']);
        $this->postJson($this->url('/pay'), ['scope' => 'member', 'idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.entitlement.state', 'trial')->assertJsonPath('data.entitlement.valid_until', '2026-02-28');
        $this->getJson('/api/v1/communities/'.$this->community->id.'/card')->assertOk()->assertJsonPath('data.validity.valid_until', '2026-02-27');
        $this->travelTo(Carbon::parse('2026-01-28 12:00:00', 'Asia/Jakarta'));
        $this->artisan('billing:process')->assertSuccessful();
        $this->assertSame(0, DB::table('billing_notices')->where('user_id', $this->member->id)->where('type', 'expires_soon')->count());
        $this->travelTo(Carbon::parse('2026-02-28 12:00:00', 'Asia/Jakarta'));
        $data = $this->getJson($this->url())->json('data');
        $monthly = array_values(array_filter($data['invoices'], fn (array $row): bool => $row['kind'] === 'member'));
        $this->assertSame('2026-02-28', $monthly[1]['period_start']);
        $this->assertSame('2026-03-31', $monthly[1]['period_end']);
        $this->postJson($this->url('/pay'), ['scope' => 'member', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->travelTo(Carbon::parse('2026-03-31 12:00:00', 'Asia/Jakarta'));
        $rows = $this->getJson($this->url())->json('data.invoices');
        $this->assertSame('2026-04-30', end($rows)['period_end']);
    }

    public function test_platform_subscription_100000_is_separate_owner_obligation_even_when_founder_exempt(): void
    {
        $this->fund($this->founder, 150000);
        $this->travelTo(Carbon::parse('2026-01-31 12:00:00', 'Asia/Jakarta'));
        Passport::actingAs($this->founder);
        $this->getJson($this->url().'?scope=platform')->assertOk()->assertJsonPath('data.entitlement.arrears_amount', 100000);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.entitlement.state', 'exempt');
        $this->postJson($this->url('/pay'), ['scope' => 'platform', 'idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.entitlement.active', true);
        $this->assertSame(100000, app(LedgerService::class)->platformAccount()->balance);
        $this->assertSame(0, app(LedgerService::class)->communityAccount($this->community)->balance);
        Passport::actingAs($this->member);
        $this->getJson($this->url().'?scope=platform')->assertForbidden();
    }

    public function test_role_and_tariff_changes_only_affect_next_period_without_forgiving_arrears(): void
    {
        $billing = app(BillingService::class);
        $this->travelTo(Carbon::parse('2026-01-31 12:00:00', 'Asia/Jakarta'));
        $subject = $billing->refresh($billing->memberSubject($this->membership));
        $first = BillingInvoice::query()->where('subject_id', $subject->id)->where('kind', 'member')->firstOrFail();
        $this->assertSame(50000, $first->amount);
        app(CommunityMemberService::class)->setRole($this->founder, $this->membership, CommunityMemberRole::Coach);
        $this->getJson($this->url())->assertJsonPath('data.entitlement.arrears_amount', 60000)->assertJsonPath('data.entitlement.active', false);
        $billing->settings($this->founder, $this->community, ['dues_amount' => 90000, 'dues_period_months' => 2]);
        $this->travelTo(Carbon::parse('2026-02-28 12:00:00', 'Asia/Jakarta'));
        $billing->refresh($subject);
        $this->assertSame(50000, $first->refresh()->amount);
        $next = BillingInvoice::query()->where('subject_id', $subject->id)->whereDate('period_start', '2026-02-28')->firstOrFail();
        $this->assertSame(0, $next->amount);
        $this->assertSame('2026-04-30', $next->period_end->toDateString());
        $billing->settings($this->founder, $this->community, ['dues_amount' => 0]);
        $this->getJson($this->url())->assertJsonPath('data.entitlement.active', false)->assertJsonPath('data.entitlement.arrears_amount', 60000);
    }

    public function test_officer_is_not_exempt_while_admin_and_coach_are_exempt(): void
    {
        $this->travelTo(Carbon::parse('2026-01-31 12:00:00', 'Asia/Jakarta'));
        foreach (['officer' => 60000, 'admin' => 0, 'coach' => 0] as $role => $expected) {
            $user = User::factory()->create();
            $member = CommunityMember::factory()->for($this->community)->for($user)->create(['role' => $role, 'joined_at' => Carbon::parse('2026-01-24', 'Asia/Jakarta'), 'billing_scheme' => 'anniversary']);
            $state = app(BillingService::class)->entitlement(app(BillingService::class)->memberSubject($member));
            $this->assertSame($expected, $state['arrears_amount']);
        }
    }

    public function test_three_month_notices_are_idempotent_retain_delays_review_and_archive_keeps_history(): void
    {
        $this->travelTo(Carbon::parse('2026-01-28 12:00:00', 'Asia/Jakarta'));
        $this->artisan('billing:process')->assertSuccessful();
        $count = DB::table('billing_notices')->where('type', 'expires_soon')->count();
        $this->assertGreaterThan(0, $count);
        $this->artisan('billing:process')->assertSuccessful();
        $this->assertSame($count, DB::table('billing_notices')->where('type', 'expires_soon')->count());
        $this->travelTo(Carbon::parse('2026-04-30 12:00:00', 'Asia/Jakarta'));
        $this->artisan('billing:process')->assertSuccessful();
        $this->assertDatabaseHas('billing_notices', ['type' => 'inactive_review', 'user_id' => $this->founder->id]);
        Passport::actingAs($this->founder);
        $this->postJson($this->url('/lifecycle'), ['subject' => 'member', 'member_id' => $this->membership->id, 'action' => 'retain', 'reason' => 'Masih akan kembali'])->assertOk();
        $subject = app(BillingService::class)->memberSubject($this->membership);
        $this->assertFalse(app(BillingService::class)->reviewDue($subject->refresh()));
        $this->travelTo(Carbon::parse('2026-07-30 12:00:00', 'Asia/Jakarta'));
        $invoiceCount = BillingInvoice::query()->where('subject_id', $subject->id)->count();
        $this->postJson($this->url('/lifecycle'), ['subject' => 'member', 'member_id' => $this->membership->id, 'action' => 'archive', 'reason' => 'Tidak mengikuti kembali'])->assertOk();
        $this->assertSame('archived', $this->membership->refresh()->status->value);
        $this->assertGreaterThanOrEqual($invoiceCount, BillingInvoice::query()->where('subject_id', $subject->id)->count());
        $this->assertDatabaseHas('billing_decisions', ['subject_id' => $subject->id, 'action' => 'archive']);
    }

    public function test_legacy_migration_keeps_prepaid_calendar_months_proofs_and_no_double_bill(): void
    {
        $user = User::factory()->create();
        $member = CommunityMember::factory()->for($this->community)->for($user)->create(['billing_scheme' => 'legacy_calendar', 'joined_at' => '2025-12-01']);
        $member->forceFill(['entry_fee_paid_at' => now()])->save();
        $this->community->forceFill(['dues_start_date' => '2026-01-31'])->save();
        foreach (['2026-01', '2026-02', '2026-03'] as $month) {
            DuesMonth::query()->forceCreate(['community_id' => $this->community->id, 'user_id' => $user->id, 'month' => $month.'-01', 'status' => 'paid']);
        }
        $billing = app(BillingService::class);
        $subject = $billing->memberSubject($member);
        $state = $billing->entitlement($subject);
        $this->assertSame(0, $state['arrears_amount']);
        $this->assertSame('2026-05-01', $state['valid_until']);
        $this->assertSame('active', $state['state']);
        $billing->refresh($subject);
        $this->assertSame(3, BillingInvoice::query()->where('subject_id', $subject->id)->count());
        $this->assertSame('2026-04-30', app(DuesService::class)->cardValidity($this->community, $member)['valid_until']->toDateString());
        $this->assertDatabaseCount('ledger_journals', 0);
    }

    public function test_admin_can_pay_manual_member_from_own_wallet_without_child_wallet_access(): void
    {
        $identity = MemberIdentity::query()->create(['name' => 'Anak Fixture', 'is_public' => false]);
        $manual = $this->community->members()->create(['member_identity_id' => $identity->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);
        app(BillingService::class)->initializeJoin($manual);
        $this->fund($this->founder, 100000);
        $this->travelTo(Carbon::parse('2026-01-31 12:00:00', 'Asia/Jakarta'));
        Passport::actingAs($this->founder);
        $this->getJson($this->url().'?target_member_id='.$manual->id)->assertOk()->assertJsonPath('data.entitlement.arrears_amount', 60000);
        $this->postJson($this->url('/pay'), ['scope' => 'member', 'target_member_id' => $manual->id, 'idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.entitlement.active', true);
        $this->assertSame(40000, app(LedgerService::class)->wallet($this->founder)['available']);
        $this->assertSame(0, app(LedgerService::class)->walletAccount($identity)->balance);
        Passport::actingAs($this->member);
        $this->getJson($this->url().'?target_member_id='.$manual->id)->assertForbidden();
    }

    public function test_wib_midnight_and_year_boundary_trial_and_leap_february_anchor(): void
    {
        $this->travelTo(Carbon::parse('2027-12-24 18:00:00', 'UTC'));
        $community = Community::factory()->approved()->create();
        $platform = app(BillingService::class)->platformSubject($community, true);
        $this->assertSame('2027-12-25', $platform->trial_start->toDateString());
        $this->assertSame('2028-01-01', $platform->trial_end->toDateString());
        $this->travelTo(Carbon::parse('2028-01-31 12:00:00', 'Asia/Jakarta'));
        $subject = app(BillingService::class)->platformSubject($this->community);
        $subject->forceFill(['anchor_date' => '2028-01-31', 'trial_start' => '2028-01-24', 'trial_end' => '2028-01-31', 'next_offset' => 0])->save();
        app(BillingService::class)->refresh($subject);
        $invoice = BillingInvoice::query()->where('subject_id', $subject->id)->whereDate('period_start', '2028-01-31')->firstOrFail();
        $this->assertSame('2028-02-29', $invoice->period_end->toDateString());
        $this->travelTo(Carbon::parse('2028-02-29 12:00:00', 'Asia/Jakarta'));
        app(BillingService::class)->refresh($subject);
        $invoice = BillingInvoice::query()->where('subject_id', $subject->id)->whereDate('period_start', '2028-02-29')->firstOrFail();
        $this->assertSame('2028-03-31', $invoice->period_end->toDateString());
    }

    public function test_h_minus_three_uses_wib_midnight_and_catchup_is_once(): void
    {
        $billing = app(BillingService::class);
        $this->travelTo(Carbon::parse('2026-01-27 23:59:59', 'Asia/Jakarta'));
        $billing->process();
        $this->assertSame(0, DB::table('billing_notices')->where('user_id', $this->member->id)->where('type', 'expires_soon')->count());
        $this->travelTo(Carbon::parse('2026-01-28 00:00:00', 'Asia/Jakarta'));
        $billing->process();
        $this->assertSame(1, DB::table('billing_notices')->where('user_id', $this->member->id)->where('type', 'expires_soon')->count());
        $this->travelTo(Carbon::parse('2026-01-30 20:00:00', 'Asia/Jakarta'));
        $billing->process();
        $this->assertSame(1, DB::table('billing_notices')->where('user_id', $this->member->id)->where('type', 'expires_soon')->count());
    }

    public function test_zero_to_positive_legacy_tariff_only_bills_next_period_and_due_day_stays_snapshotted(): void
    {
        $user = User::factory()->create();
        $this->community->forceFill(['dues_amount' => 0, 'entry_fee' => 0, 'dues_start_date' => '2025-12-10'])->save();
        $member = CommunityMember::factory()->for($this->community)->for($user)->create(['billing_scheme' => 'legacy_calendar', 'joined_at' => '2025-12-01']);
        $billing = app(BillingService::class);
        $subject = $billing->memberSubject($member);
        $billing->refresh($subject);
        $billing->settings($this->founder, $this->community, ['dues_amount' => 50000, 'dues_start_date' => '2026-01-31']);
        $this->assertSame(0, $billing->entitlement($subject)['arrears_amount']);
        $this->assertSame(2, BillingInvoice::query()->where('subject_id', $subject->id)->where('amount', 0)->count());
        $this->travelTo(Carbon::parse('2026-02-10 23:59:59', 'Asia/Jakarta'));
        $this->assertSame(0, $billing->entitlement($subject)['arrears_amount']);
        $this->travelTo(Carbon::parse('2026-02-11 00:00:00', 'Asia/Jakarta'));
        $this->assertSame(50000, $billing->entitlement($subject)['arrears_amount']);
        $invoice = BillingInvoice::query()->where('subject_id', $subject->id)->where('amount', 50000)->firstOrFail();
        $this->assertSame('2026-02-11', $invoice->period_start->toDateString());
        $this->assertSame('2026-03-11', $invoice->period_end->toDateString());
    }

    public function test_guardian_can_pay_manual_invoice_from_own_wallet_and_revocation_denies_access(): void
    {
        $identity = MemberIdentity::query()->create(['name' => 'Anak Wali Fixture', 'is_public' => false]);
        $manual = $this->community->members()->create(['member_identity_id' => $identity->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);
        app(BillingService::class)->initializeJoin($manual);
        GuardianGrant::query()->create(['member_identity_id' => $identity->id, 'guardian_user_id' => $this->member->id, 'community_member_id' => $manual->id, 'verified_at' => now()]);
        $this->fund($this->member, 100000);
        $this->travelTo(Carbon::parse('2026-01-31 12:00:00', 'Asia/Jakarta'));
        $this->postJson($this->url('/pay'), ['scope' => 'member', 'target_member_id' => $manual->id, 'idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.entitlement.active', true);
        $this->assertSame(40000, app(LedgerService::class)->wallet($this->member)['available']);
        $this->getJson('/api/v1/dependents/'.$identity->id.'/memberships/'.$manual->id.'/card')->assertOk()->assertJsonPath('data.validity.status', 'active');
        GuardianGrant::query()->where('member_identity_id', $identity->id)->update(['revoked_at' => now()]);
        $this->getJson($this->url().'?target_member_id='.$manual->id)->assertForbidden();
    }

    public function test_card_exposes_platform_expiry_separately_from_member_trial(): void
    {
        $this->travelTo(Carbon::parse('2026-01-30 12:00:00', 'Asia/Jakarta'));
        $user = User::factory()->create();
        $member = CommunityMember::factory()->for($this->community)->for($user)->create();
        app(BillingService::class)->initializeJoin($member);
        Passport::actingAs($user);
        $this->travelTo(Carbon::parse('2026-01-31 12:00:00', 'Asia/Jakarta'));
        $this->getJson('/api/v1/communities/'.$this->community->id.'/card')->assertOk()->assertJsonPath('data.validity.status', 'active')->assertJsonPath('data.entitlement.state', 'trial')->assertJsonPath('data.platform_active', false);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.entitlement.active', true)->assertJsonPath('data.platform_active', false);
    }

    public function test_legacy_pending_cutover_gets_one_trial_and_card_without_approval_but_previous_paid_does_not_reset(): void
    {
        $this->community->forceFill(['billing_cutover_date' => '2026-01-24'])->save();
        $newUser = User::factory()->create();
        $pending = CommunityMember::factory()->for($this->community)->for($newUser)->pending()->create(['billing_scheme' => 'legacy_calendar', 'created_at' => '2025-12-01']);
        $previous = User::factory()->create();
        $previousMember = CommunityMember::factory()->for($this->community)->for($previous)->pending()->create(['billing_scheme' => 'legacy_calendar', 'joined_at' => '2025-12-01']);
        DuesMonth::query()->forceCreate(['community_id' => $this->community->id, 'user_id' => $previous->id, 'month' => '2026-01-01', 'status' => 'paid']);
        $this->artisan('billing:process')->assertSuccessful();
        $this->assertSame('active', $pending->refresh()->status->value);
        Passport::actingAs($newUser);
        $this->getJson('/api/v1/communities/'.$this->community->id.'/card')->assertOk()->assertJsonPath('data.entitlement.state', 'trial')->assertJsonPath('data.entitlement.trial_start', '2026-01-24');
        $subject = app(BillingService::class)->memberSubject($previousMember);
        $this->assertNull($subject->trial_start);
        $this->assertSame('legacy_calendar', $subject->scheme);
        $this->artisan('billing:process')->assertSuccessful();
        $this->assertSame(1, BillingSubject::query()->where('member_identity_id', $pending->member_identity_id)->count());
    }

    public function test_linking_manual_identity_to_existing_account_preserves_earliest_trial_and_all_debts(): void
    {
        $source = MemberIdentity::query()->create(['name' => 'Identitas Manual', 'is_public' => false]);
        $manual = $this->community->members()->create(['member_identity_id' => $source->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);
        $billing = app(BillingService::class);
        $billing->initializeJoin($manual);
        $sourceSubject = $billing->memberSubject($manual);
        $this->travelTo(Carbon::parse('2026-01-30 12:00:00', 'Asia/Jakarta'));
        $account = User::factory()->create(['birth_date' => null, 'gender' => null]);
        $canonicalMember = CommunityMember::factory()->for($this->community)->for($account)->create();
        $billing->initializeJoin($canonicalMember);
        $this->travelTo(Carbon::parse('2026-01-31 12:00:00', 'Asia/Jakarta'));
        $this->assertSame(60000, $billing->entitlement($sourceSubject)['arrears_amount']);
        $invitation = MemberInvitation::query()->create(['member_identity_id' => $source->id, 'community_member_id' => $manual->id, 'invited_by' => $this->founder->id, 'kind' => 'account', 'email' => $account->email, 'delivery_method' => 'in_app', 'token_hash' => hash('sha256', 'fixture'), 'expires_at' => now()->addDay()]);
        app(GuardianService::class)->claim($account, $invitation, null, true);
        Passport::actingAs($account);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.entitlement.trial_end', '2026-01-31')->assertJsonPath('data.entitlement.state', 'expired')->assertJsonPath('data.entitlement.arrears_amount', 60000);
        $this->assertSame($sourceSubject->id, $billing->memberSubject($canonicalMember->refresh())->id);
        $this->assertSame(2, BillingInvoice::query()->where('subject_id', $sourceSubject->id)->count());
    }

    public function test_platform_archive_hides_discovery_and_preserves_community_and_financial_history(): void
    {
        $this->travelTo(Carbon::parse('2026-04-30 12:00:00', 'Asia/Jakarta'));
        $billing = app(BillingService::class);
        $subject = $billing->refresh($billing->platformSubject($this->community));
        $administrator = User::factory()->create();
        $administrator->assignRole('super-admin');
        Passport::actingAs($administrator);
        $this->getJson($this->url('/inactive'))->assertOk()->assertJsonPath('data.community.id', $this->community->id)->assertJsonPath('data.community.entitlement.state', 'expired')->assertJsonPath('data.community.review_due', true)->assertJsonPath('data.community.can_decide', true);
        $this->postJson($this->url('/lifecycle'), ['subject' => 'platform', 'action' => 'retain', 'reason' => 'Komunitas ingin kembali'])->assertOk();
        $this->getJson($this->url('/inactive'))->assertOk()->assertJsonPath('data.community.review_due', false);
        $this->travelTo(Carbon::parse('2026-07-30 12:00:00', 'Asia/Jakarta'));
        $this->getJson($this->url('/inactive'))->assertOk()->assertJsonPath('data.community.review_due', true);
        $this->postJson($this->url('/lifecycle'), ['subject' => 'platform', 'action' => 'archive', 'reason' => 'Komunitas tidak melanjutkan layanan'])->assertOk();
        $this->assertDatabaseHas('communities', ['id' => $this->community->id]);
        $this->assertFalse(Community::query()->approved()->whereKey($this->community->id)->exists());
        $this->getJson('/api/v1/public/communities/'.$this->community->id)->assertNotFound();
        $this->getJson('/api/v1/public/members?community_id='.$this->community->id)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('archived', $billing->entitlement($subject)['state']);
        $this->assertGreaterThan(0, BillingInvoice::query()->where('subject_id', $subject->id)->count());
        $this->assertDatabaseHas('billing_decisions', ['subject_id' => $subject->id, 'actor_id' => $administrator->id, 'action' => 'archive']);
    }

    public function test_leave_and_rejoin_after_gap_keeps_trial_and_prior_debt_without_billing_archived_months(): void
    {
        $billing = app(BillingService::class);
        $this->travelTo(Carbon::parse('2026-01-31 12:00:00', 'Asia/Jakarta'));
        $this->getJson($this->url())->assertJsonPath('data.entitlement.arrears_amount', 60000);
        app(CommunityMemberService::class)->leave($this->member, $this->community);
        $this->travelTo(Carbon::parse('2026-04-05 12:00:00', 'Asia/Jakarta'));
        $this->artisan('billing:process')->assertSuccessful();
        $subject = $billing->memberSubject($this->membership);
        $this->assertSame(2, BillingInvoice::query()->where('subject_id', $subject->id)->count());
        $this->postJson('/api/v1/communities/'.$this->community->id.'/join')->assertStatus(402); // Community subscription expired; recovery payment remains available.
        app(CommunityMemberService::class)->join($this->member, $this->community);
        $state = $billing->entitlement($subject);
        $this->assertSame('2026-01-31', $state['trial_end']);
        $this->assertSame(110000, $state['arrears_amount']); // January debt+entry and one new prospective April period only.
        $this->assertSame('2026-04-05', $subject->refresh()->anchor_date->toDateString());
        $this->assertSame(3, BillingInvoice::query()->where('subject_id', $subject->id)->count());
        $this->assertFalse(BillingInvoice::query()->where('subject_id', $subject->id)->whereBetween('period_start', ['2026-02-01', '2026-04-04'])->exists());
    }

    public function test_legacy_approved_receipt_alone_prevents_trial_reset_even_if_calendar_row_is_missing(): void
    {
        $user = User::factory()->create();
        $member = CommunityMember::factory()->for($this->community)->for($user)->pending()->create(['billing_scheme' => 'legacy_calendar']);
        DuesPayment::query()->forceCreate(['community_id' => $this->community->id, 'user_id' => $user->id, 'months_count' => 1, 'months' => ['2026-01'], 'monthly_amount' => 50000, 'entry_fee' => 0, 'total_amount' => 50000, 'status' => 'approved']);
        $subject = app(BillingService::class)->memberSubject($member);
        $this->assertNull($subject->trial_start);
        $this->assertSame('legacy_calendar', $subject->scheme);
        app(BillingService::class)->refresh($subject);
        $invoice = BillingInvoice::query()->where('subject_id', $subject->id)->where('kind', 'member')->firstOrFail();
        $this->assertSame(50000, $invoice->amount);
        $this->assertSame('paid', $invoice->status);
        $this->assertDatabaseHas('dues_months', ['community_id' => $this->community->id, 'user_id' => $user->id, 'month' => '2026-01-01', 'status' => 'paid']);
    }

    public function test_confirmed_wallet_quote_mismatch_never_debits_or_creates_journal_and_retry_is_payload_bound(): void
    {
        $this->fund($this->member, 200000);
        $this->travelTo(Carbon::parse('2026-01-31 12:00:00', 'Asia/Jakarta'));
        $ids = array_column($this->getJson($this->url())->json('data.invoices'), 'id');
        $key = (string) Str::uuid();
        $journals = DB::table('ledger_journals')->count();
        $this->postJson($this->url('/pay'), ['scope' => 'member', 'invoice_ids' => $ids, 'expected_amount' => 50000, 'idempotency_key' => $key])->assertConflict();
        $this->assertSame($journals, DB::table('ledger_journals')->count());
        $this->assertSame(200000, app(LedgerService::class)->wallet($this->member)['available']);
        $this->postJson($this->url('/pay'), ['scope' => 'member', 'invoice_ids' => $ids, 'expected_amount' => 60000, 'idempotency_key' => $key])->assertOk();
        $this->postJson($this->url('/pay'), ['scope' => 'member', 'invoice_ids' => $ids, 'expected_amount' => 50000, 'idempotency_key' => $key])->assertConflict();
        $this->assertSame(140000, app(LedgerService::class)->wallet($this->member)['available']);
    }

    public function test_unrecorded_legacy_manual_nominal_is_not_invented_from_current_tariff(): void
    {
        $user = User::factory()->create();
        $member = CommunityMember::factory()->for($this->community)->for($user)->create(['billing_scheme' => 'legacy_calendar']);
        DuesMonth::query()->forceCreate(['community_id' => $this->community->id, 'user_id' => $user->id, 'month' => '2026-01-01', 'status' => 'paid']);
        $this->community->forceFill(['dues_amount' => 90000])->save();
        $billing = app(BillingService::class);
        $subject = $billing->refresh($billing->memberSubject($member));
        $invoice = BillingInvoice::query()->where('subject_id', $subject->id)->where('kind', 'member')->firstOrFail();
        $this->assertSame(0, $invoice->amount);
        $this->assertSame(0, $invoice->paid_amount);
        $this->assertSame('paid', $invoice->status);
        $this->assertTrue($invoice->snapshot['legacy_nominal_unrecorded']);
        $this->assertDatabaseCount('ledger_journals', 0);
    }

    public function test_pending_legacy_monthly_and_entry_snapshots_survive_tariff_change_before_approval(): void
    {
        $user = User::factory()->create();
        $member = CommunityMember::factory()->for($this->community)->for($user)->create(['billing_scheme' => 'legacy_calendar']);
        $proof = Asset::factory()->create(['user_id' => $user->id, 'size' => 1000]);
        Storage::disk('gcs')->put($proof->path, 'snapshot-proof-'.$proof->id);
        $payment = app(DuesService::class)->submitPayment($user, $this->community, 1, $proof->id, null);
        $this->community->forceFill(['dues_amount' => 90000, 'entry_fee' => 20000])->save();
        $billing = app(BillingService::class);
        $subject = $billing->refresh($billing->memberSubject($member));
        $monthly = BillingInvoice::query()->where('subject_id', $subject->id)->where('kind', 'member')->firstOrFail();
        $entry = BillingInvoice::query()->where('subject_id', $subject->id)->where('kind', 'entry')->firstOrFail();
        $this->assertSame(50000, $monthly->amount);
        $this->assertSame(10000, $entry->amount);
        app(DuesService::class)->approve($this->founder, $payment, 60000, 'LEGACY-SNAPSHOT-REAL');
        $this->assertSame('paid', $monthly->refresh()->status);
        $this->assertSame('paid', $entry->refresh()->status);
        $this->assertSame(60000, app(LedgerService::class)->communityAccount($this->community)->balance);
        $this->community->forceFill(['dues_amount' => 150000, 'entry_fee' => 30000])->save();
        $billing->refresh($subject);
        $this->assertSame(50000, $monthly->refresh()->amount);
        $this->assertSame(10000, $entry->refresh()->amount);
    }

    private function fund(User $user, int $amount): void
    {
        $receipt = $this->receipt($user, $amount, 'topup');
        app(IncomingReceiptService::class)->verify($this->founder, $receipt, $amount, 'FUND-'.Str::uuid(), (string) Str::uuid());
    }

    /** @param list<string> $ids */
    private function receipt(User $user, int $amount, string $purpose, array $ids = [], bool $creditExcess = false): IncomingReceipt
    {
        $asset = Asset::factory()->create(['user_id' => $user->id, 'size' => 1000]);
        Storage::disk('gcs')->put($asset->path, 'proof-'.Str::uuid());

        return app(IncomingReceiptService::class)->submit($user, $this->community, ['claimed_amount' => $amount, 'proof_asset_id' => $asset->id, 'bank_reference' => 'RECEIPT-'.Str::uuid(), 'purpose' => $purpose, 'invoice_ids' => $ids, 'credit_excess' => $creditExcess, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/communities/'.$this->community->id.'/billing'.$suffix;
    }
}
