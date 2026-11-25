<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\BillingInvoice;
use App\Models\BillingSubject;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\DuesPayment;
use App\Models\MemberIdentity;
use App\Models\MemberInvitation;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CommunityMemberService;
use App\Services\DuesService;
use App\Services\GuardianService;
use App\Services\IncomingReceiptService;
use App\Services\LedgerService;
use App\Support\Enums\StorageType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class RawLegacyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;

    private User $founder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('local');
        Storage::fake('gcs');
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2026-04-05 12:00:00', 'Asia/Jakarta'));
        $this->founder = User::factory()->create();
        $this->community = Community::factory()->approved()->for($this->founder, 'owner')->create();
        $this->community->forceFill(['dues_amount' => 50000, 'entry_fee' => 10000, 'dues_start_date' => '2025-11-10', 'billing_cutover_date' => '2026-01-24', 'dues_bank_name' => 'Bank Fixture', 'dues_account_number' => '1234567890', 'dues_account_name' => 'Komunitas Fixture'])->save();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_raw_pre_cutover_archived_rejoin_keeps_paid_pending_and_debt_without_new_trial_or_gap_bills(): void
    {
        [$user, $member, $approved, $pending] = $this->archivedAccount();
        $approvedBefore = $approved->refresh()->getRawOriginal();
        $pendingBefore = $pending->refresh()->getRawOriginal();
        $this->assertFalse(BillingSubject::query()->where('member_id', $member->id)->exists());
        $joined = app(CommunityMemberService::class)->join($user, $this->community);
        $billing = app(BillingService::class);
        $subject = $billing->memberSubject($joined);
        $state = $billing->entitlement($subject);
        $this->assertNull($state['trial_start']);
        $this->assertNull($state['trial_end']);
        $this->assertSame('2025-11-01', $joined->joined_at->toDateString());
        $this->assertSame('2026-04-05', $subject->refresh()->anchor_date->toDateString());
        $this->assertSame(90000, $state['arrears_amount']);
        $this->assertSame(['2025-11-11', '2025-12-11', '2026-04-05'], BillingInvoice::query()->where('subject_id', $subject->id)->where('kind', 'member')->orderBy('period_start')->get()->map(fn (BillingInvoice $invoice): string => $invoice->period_start->toDateString())->all());
        $this->assertSame([50000, 40000, 50000], BillingInvoice::query()->where('subject_id', $subject->id)->where('kind', 'member')->orderBy('period_start')->pluck('amount')->all());
        $this->assertSame($approvedBefore, $approved->refresh()->getRawOriginal());
        $this->assertSame($pendingBefore, $pending->refresh()->getRawOriginal());
        $this->assertDatabaseCount('ledger_journals', 0);
        $this->assertDatabaseHas('billing_invoices', ['subject_id' => $subject->id, 'legacy_month' => '2025-11', 'status' => 'paid', 'amount' => 50000]);
        app(DuesService::class)->approve($this->founder, $pending, 40000, 'RAW-LEGACY-DECEMBER');
        $this->assertSame(50000, $billing->entitlement($subject)['arrears_amount']);
        $this->assertDatabaseCount('ledger_journals', 1);
        $this->assertSame('approved', $pending->refresh()->status->value);
        Passport::actingAs($user);
        $this->getJson('/api/v1/communities/'.$this->community->id.'/card')->assertOk()->assertJsonPath('data.entitlement.trial_start', null)->assertJsonPath('data.entitlement.active', false);
    }

    public function test_raw_archived_admin_approval_resumes_before_refresh_without_charging_archive_gap(): void
    {
        [$user, $member] = $this->archivedAccount();
        $restored = app(CommunityMemberService::class)->approve($this->founder, $member);
        $billing = app(BillingService::class);
        $subject = $billing->memberSubject($restored);
        $this->assertNull($subject->trial_start);
        $this->assertSame('2026-04-05', $subject->anchor_date->toDateString());
        $this->assertSame('active', $restored->status->value);
        $this->assertSame(90000, $billing->entitlement($subject)['arrears_amount']);
        $this->assertFalse(BillingInvoice::query()->where('subject_id', $subject->id)->whereBetween('period_start', ['2026-01-01', '2026-04-04'])->exists());
        $this->assertSame('2025-11-01', $restored->joined_at->toDateString());
        $this->assertDatabaseCount('ledger_journals', 0);
    }

    public function test_admin_approval_classifies_raw_pending_legacy_before_join_date_and_preserves_cutover_trial(): void
    {
        $this->travelTo(Carbon::parse('2026-01-25 12:00:00', 'Asia/Jakarta'));
        $user = User::factory()->create();
        $member = CommunityMember::factory()->for($this->community)->for($user)->pending()->create(['billing_scheme' => 'legacy_calendar', 'created_at' => '2025-11-01']);
        $approved = app(CommunityMemberService::class)->approve($this->founder, $member);
        $subject = app(BillingService::class)->memberSubject($approved);
        $this->assertSame('active', $approved->status->value);
        $this->assertSame('anniversary', $approved->billing_scheme);
        $this->assertSame('2026-01-24', $subject->trial_start->toDateString());
        $this->assertSame('2026-01-31', $subject->trial_end->toDateString());
        $this->assertSame('trial', app(BillingService::class)->entitlement($subject)['state']);
        $this->assertSame(0, BillingInvoice::query()->where('subject_id', $subject->id)->count());
        $this->assertDatabaseCount('ledger_journals', 0);
    }

    public function test_canceled_never_active_legacy_does_not_get_old_debt_or_a_fresh_return_date_trial(): void
    {
        $user = User::factory()->create();
        $member = CommunityMember::factory()->for($this->community)->for($user)->create(['status' => 'archived', 'billing_scheme' => 'legacy_calendar', 'joined_at' => null, 'created_at' => '2025-11-01', 'updated_at' => '2025-12-15']);
        $joined = app(CommunityMemberService::class)->join($user, $this->community);
        $subject = app(BillingService::class)->memberSubject($joined);
        $state = app(BillingService::class)->entitlement($subject);
        $this->assertSame('2026-01-24', $state['trial_start']);
        $this->assertSame('2026-01-31', $state['trial_end']);
        $this->assertSame('2026-04-05', $subject->anchor_date->toDateString());
        $this->assertSame(60000, $state['arrears_amount']);
        $this->assertFalse(BillingInvoice::query()->where('subject_id', $subject->id)->whereDate('period_start', '<', '2026-04-05')->exists());
        $this->assertFalse($state['active']);
    }

    public function test_archived_manual_identity_merge_preserves_old_debt_and_pending_moderation_without_gap_bills(): void
    {
        $source = MemberIdentity::query()->create(['name' => 'Identitas Arsip', 'is_public' => false]);
        $sourceArchived = $this->community->members()->create(['member_identity_id' => $source->id, 'role' => 'member', 'status' => 'archived', 'joined_at' => '2025-11-01']);
        $sourceArchived->forceFill(['billing_scheme' => 'legacy_calendar', 'entry_fee_paid_at' => '2025-11-10', 'created_at' => '2025-11-01', 'updated_at' => '2025-12-15'])->save();
        $account = User::factory()->create(['birth_date' => null, 'gender' => null]);
        $canonical = CommunityMember::factory()->for($this->community)->for($account)->pending()->create();
        $other = Community::factory()->approved()->for($this->founder, 'owner')->create();
        $sourceActive = $other->members()->create(['member_identity_id' => $source->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);
        $invitation = MemberInvitation::query()->create(['member_identity_id' => $source->id, 'community_member_id' => $sourceActive->id, 'invited_by' => $this->founder->id, 'kind' => 'account', 'email' => $account->email, 'delivery_method' => 'in_app', 'token_hash' => hash('sha256', 'raw-legacy-merge'), 'expires_at' => now()->addDay()]);
        app(GuardianService::class)->claim($account, $invitation, null, true);
        $this->assertSame('pending', $canonical->refresh()->status->value);
        $this->assertNull($canonical->joined_at);
        $this->assertSame('archived', $sourceArchived->refresh()->status->value);
        $this->assertSame($canonical->id, $sourceArchived->merged_into_member_id);
        $billing = app(BillingService::class);
        $subject = $billing->memberSubject($canonical);
        $state = $billing->entitlement($subject);
        $this->assertNull($subject->trial_start);
        $this->assertSame('2026-04-05', $subject->anchor_date->toDateString());
        $this->assertSame(150000, $state['arrears_amount']);
        $this->assertSame(['2025-11-11', '2025-12-11', '2026-04-05'], BillingInvoice::query()->where('subject_id', $subject->id)->where('kind', 'member')->orderBy('period_start')->get()->map(fn (BillingInvoice $invoice): string => $invoice->period_start->toDateString())->all());
        $this->assertSame('pending', $canonical->refresh()->status->value);
        $this->assertDatabaseCount('ledger_journals', 0);
    }

    public function test_entry_merge_void_keeps_partial_receipt_money_and_invoice_snapshot_unchanged(): void
    {
        $this->community->forceFill(['entry_fee' => 20000])->save();
        $source = MemberIdentity::query()->create(['name' => 'Identitas Sudah Bayar Uang Masuk', 'is_public' => false]);
        $archived = $this->community->members()->create(['member_identity_id' => $source->id, 'role' => 'member', 'status' => 'archived', 'joined_at' => '2025-11-01']);
        $archived->forceFill(['billing_scheme' => 'legacy_calendar', 'entry_fee_paid_at' => '2025-11-10', 'created_at' => '2025-11-01', 'updated_at' => '2025-12-15'])->save();
        $account = User::factory()->create(['birth_date' => null, 'gender' => null]);
        $canonical = CommunityMember::factory()->for($this->community)->for($account)->pending()->create();
        $billing = app(BillingService::class);
        $billing->renew($account, $this->community, 'member');
        $targetSubject = $billing->memberSubject($canonical);
        $entry = BillingInvoice::query()->where('subject_id', $targetSubject->id)->where('kind', 'entry')->firstOrFail();
        $proof = Asset::factory()->create(['user_id' => $account->id, 'storage_type' => StorageType::Private, 'size' => 1000]);
        Storage::disk('local')->put($proof->path, 'partial-entry-'.$proof->id);
        $receipt = app(IncomingReceiptService::class)->submit($account, $this->community, ['purpose' => 'member', 'claimed_amount' => 10000, 'proof_asset_id' => $proof->id, 'bank_reference' => 'PARTIAL-ENTRY-CLAIM', 'idempotency_key' => (string) Str::uuid(), 'invoice_ids' => [$entry->id]]);
        app(IncomingReceiptService::class)->verify($this->founder, $receipt, 10000, 'PARTIAL-ENTRY-REAL', (string) Str::uuid());
        $statusBeforeMerge = $canonical->refresh()->status->value;
        $entrySnapshot = $entry->refresh()->snapshot;
        $journals = DB::table('ledger_journals')->orderBy('id')->get()->toJson();
        $other = Community::factory()->approved()->for($this->founder, 'owner')->create();
        $active = $other->members()->create(['member_identity_id' => $source->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);
        $invite = MemberInvitation::query()->create(['member_identity_id' => $source->id, 'community_member_id' => $active->id, 'invited_by' => $this->founder->id, 'kind' => 'account', 'email' => $account->email, 'delivery_method' => 'in_app', 'token_hash' => hash('sha256', 'partial-entry-merge'), 'expires_at' => now()->addDay()]);
        app(GuardianService::class)->claim($account, $invite, null, true);
        $this->assertSame('void', $entry->refresh()->status);
        $this->assertSame(20000, $entry->amount);
        $this->assertSame(10000, $entry->paid_amount);
        $this->assertSame($entrySnapshot, $entry->snapshot);
        $this->assertSame($journals, DB::table('ledger_journals')->orderBy('id')->get()->toJson());
        $this->assertSame($statusBeforeMerge, $canonical->refresh()->status->value);
        $this->assertSame('2025-11-10', $canonical->entry_fee_paid_at->toDateString());
        $this->assertDatabaseHas('activity_log', ['event' => 'billing_entry_entitlement_merged']);
        $this->assertSame(10000, app(LedgerService::class)->communityAccount($this->community)->balance);
        $this->assertSame('verified', $receipt->refresh()->status);
    }

    public function test_rejoin_before_legacy_due_day_preserves_current_paid_calendar_month_without_extra_prorata(): void
    {
        $this->community->forceFill(['dues_start_date' => '2026-01-31'])->save();
        $this->travelTo(Carbon::parse('2026-01-25 12:00:00', 'Asia/Jakarta'));
        $user = User::factory()->create();
        $member = CommunityMember::factory()->for($this->community)->for($user)->create(['billing_scheme' => 'legacy_calendar', 'status' => 'archived', 'joined_at' => '2025-12-01', 'entry_fee_paid_at' => '2026-01-01', 'created_at' => '2025-12-01', 'updated_at' => '2026-01-20']);
        DuesPayment::query()->forceCreate(['community_id' => $this->community->id, 'user_id' => $user->id, 'months_count' => 1, 'months' => ['2026-01'], 'monthly_amount' => 50000, 'entry_fee' => 0, 'total_amount' => 50000, 'status' => 'approved']);
        $joined = app(CommunityMemberService::class)->join($user, $this->community);
        $billing = app(BillingService::class);
        $subject = $billing->memberSubject($joined);
        $state = $billing->entitlement($subject);
        $this->assertNull($subject->trial_start);
        $this->assertSame('2026-03-01', $subject->anchor_date->toDateString());
        $this->assertSame('anniversary', $subject->scheme);
        $this->assertTrue($state['active']);
        $this->assertSame('2026-03-01', $state['valid_until']);
        $this->assertSame(0, $state['arrears_amount']);
        $this->assertSame(1, BillingInvoice::query()->where('subject_id', $subject->id)->count());
        $this->assertDatabaseHas('billing_invoices', ['subject_id' => $subject->id, 'legacy_month' => '2026-01', 'amount' => 50000, 'status' => 'paid']);
        $this->assertDatabaseCount('ledger_journals', 0);
        Passport::actingAs($user);
        $this->getJson('/api/v1/communities/'.$this->community->id.'/card')->assertOk()->assertJsonPath('data.validity.valid_until', '2026-02-28')->assertJsonPath('data.entitlement.active', true);
    }

    /** @return array{User,CommunityMember,DuesPayment,DuesPayment} */
    private function archivedAccount(): array
    {
        $user = User::factory()->create();
        $member = CommunityMember::factory()->for($this->community)->for($user)->create(['billing_scheme' => 'legacy_calendar', 'status' => 'archived', 'joined_at' => '2025-11-01', 'entry_fee_paid_at' => '2025-11-10', 'created_at' => '2025-11-01', 'updated_at' => '2025-12-15']);
        $approved = DuesPayment::query()->forceCreate(['community_id' => $this->community->id, 'user_id' => $user->id, 'months_count' => 1, 'months' => ['2025-11'], 'monthly_amount' => 50000, 'entry_fee' => 0, 'total_amount' => 50000, 'status' => 'approved', 'reviewed_by' => $this->founder->id, 'reviewed_at' => '2025-11-10', 'created_at' => '2025-11-10']);
        $proof = Asset::factory()->create(['user_id' => $user->id, 'storage_type' => StorageType::Private, 'size' => 1000]);
        Storage::disk('local')->put($proof->path, 'raw-legacy-proof-'.$proof->id);
        $pending = DuesPayment::query()->forceCreate(['community_id' => $this->community->id, 'user_id' => $user->id, 'months_count' => 1, 'months' => ['2025-12'], 'monthly_amount' => 40000, 'entry_fee' => 0, 'total_amount' => 40000, 'proof_asset_id' => $proof->id, 'status' => 'pending', 'created_at' => '2025-12-10']);
        app(IncomingReceiptService::class)->registerLegacyProof($pending);

        return [$user, $member, $approved, $pending];
    }
}
