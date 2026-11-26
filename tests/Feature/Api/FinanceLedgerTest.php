<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\DuesPayment;
use App\Models\IncomingReceipt;
use App\Models\LedgerClearing;
use App\Models\LedgerJournal;
use App\Models\MemberIdentity;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CustodyService;
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
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinanceLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $founder;

    private User $member;

    private Community $community;

    private LedgerService $ledger;

    private IncomingReceiptService $receipts;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('gcs');
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(RolePermissionSeeder::class);
        $this->founder = User::factory()->create();
        $this->community = Community::factory()->approved()->for($this->founder, 'owner')->create([
            'dues_bank_name' => 'Bank Fixture', 'dues_account_number' => '00000001', 'dues_account_name' => 'Komunitas Fixture',
        ]);
        $this->member = User::factory()->create();
        CommunityMember::factory()->create(['community_id' => $this->community->id, 'user_id' => $this->member->id]);
        $this->ledger = app(LedgerService::class);
        $this->receipts = app(IncomingReceiptService::class);
    }

    public function test_pending_topup_has_no_money_and_admin_verifies_once_with_audited_amount(): void
    {
        Passport::actingAs($this->member);
        $body = $this->body(100000);
        $id = $this->postJson($this->url(), $body)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.available', 0);
        $this->postJson($this->url(), $body)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson($this->url(), [...$body, 'claimed_amount' => 100001])->assertConflict();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.instructions.account_name', 'Komunitas Fixture')->assertJsonPath('data.can_manage', false);
        Passport::actingAs($this->founder);
        $verify = ['verified_amount' => 90000, 'bank_reference' => 'actual-bank-1', 'idempotency_key' => (string) Str::uuid()];
        $response = $this->postJson($this->url().'/'.$id.'/verify', $verify)->assertOk()->assertJsonPath('data.verified_amount', 90000);
        $this->assertNotNull($response->json('data.proof_url'));
        $this->postJson($this->url().'/'.$id.'/verify', $verify)->assertOk();
        $this->postJson($this->url().'/'.$id.'/verify', [...$verify, 'verified_amount' => 91000])->assertConflict();
        $this->assertSame(90000, $this->ledger->wallet($this->member)['available']);
        $this->assertSame(1, LedgerJournal::query()->count());
        $this->assertSame($this->founder->id, IncomingReceipt::query()->findOrFail($id)->reviewed_by);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
        $proof = Asset::query()->findOrFail($body['proof_asset_id']);
        $this->assertTrue($proof->is_protected);
        $this->assertNull($proof->retain_until);
        $this->assertSame(StorageType::Private, $proof->storage_type);
        $this->assertNull($proof->getPublicUrl());
    }

    public function test_role_scope_direct_requests_and_private_receipts_are_enforced(): void
    {
        $receipt = $this->submit(50000);
        $outsider = User::factory()->create();
        $other = Community::factory()->approved()->for($outsider, 'owner')->create();
        Passport::actingAs($outsider);
        $verify = ['verified_amount' => 50000, 'bank_reference' => 'x', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson($this->url().'/'.$receipt->id.'/verify', $verify)->assertForbidden();
        $this->getJson($this->url().'/'.$receipt->id)->assertForbidden();
        $this->getJson('/api/v1/communities/'.$other->id.'/receipts/'.$receipt->id)->assertNotFound();
        $officer = User::factory()->create();
        CommunityMember::factory()->create(['community_id' => $this->community->id, 'user_id' => $officer->id, 'role' => 'officer']);
        Passport::actingAs($officer);
        $this->postJson($this->url().'/'.$receipt->id.'/verify', $verify)->assertForbidden();
        $this->assertSame(0, $this->ledger->wallet($this->member)['available']);
    }

    public function test_same_file_bytes_cannot_credit_twice_even_with_another_asset_or_account(): void
    {
        $proof = $this->proof($this->member, 'same-bank-proof-bytes');
        $receipt = $this->receipts->submit($this->member, $this->community, $this->body(50000, $proof));
        $this->receipts->verify($this->founder, $receipt, 50000, 'received1', (string) Str::uuid());
        $secondProof = $this->proof($this->founder, 'same-bank-proof-bytes');
        Passport::actingAs($this->founder);
        $this->postJson($this->url(), $this->body(50000, $secondProof))->assertConflict();
        $this->assertSame(1, IncomingReceipt::query()->count());
        $this->assertSame(50000, $this->ledger->wallet($this->member)['available']);
    }

    public function test_bank_reference_unique_per_custodian_and_reject_does_not_mutate_money(): void
    {
        $first = $this->submit(50000);
        $this->receipts->verify($this->founder, $first, 50000, 'bank transaction 7', (string) Str::uuid());
        $second = $this->submit(80000);
        Passport::actingAs($this->founder);
        $this->postJson($this->url().'/'.$second->id.'/verify', ['verified_amount' => 80000, 'bank_reference' => ' BANK   TRANSACTION 7 ', 'idempotency_key' => (string) Str::uuid()])->assertConflict();
        $this->postJson($this->url().'/'.$second->id.'/reject', ['note' => 'Transfer tidak cocok'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson($this->url().'/'.$second->id.'/reject', ['note' => 'Transfer tidak cocok'])->assertOk();
        $this->assertSame(50000, $this->ledger->wallet($this->member)['available']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_admin_enters_amount_with_owned_proof_for_member_and_history_is_private(): void
    {
        Passport::actingAs($this->founder);
        $body = [...$this->body(100000, $this->proof($this->founder)), 'beneficiary_user_id' => $this->member->id];
        $id = $this->postJson($this->url(), $body)->assertCreated()->assertJsonPath('data.user.id', $this->member->id)->json('data.id');
        $this->postJson($this->url().'/'.$id.'/verify', ['verified_amount' => 100000, 'bank_reference' => 'ADMIN-CASH-1', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        Passport::actingAs($this->member);
        $history = $this->getJson('/api/v1/wallet/transactions?direction=in')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.amount', 100000);
        $journal = $history->json('data.items.0.id');
        $this->getJson('/api/v1/wallet/transactions/'.$journal)->assertOk()->assertJsonPath('data.postings.0.amount', 100000)->assertJsonMissingPath('data.metadata.allocations');
        $this->getJson('/api/v1/wallet/receipts')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/wallet/receipts?status=pending')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/wallet/receipts?status=verified')->assertOk()->assertJsonPath('data.total', 1);
        Passport::actingAs(User::factory()->create());
        $this->getJson('/api/v1/wallet/transactions/'.$journal)->assertNotFound();
    }

    public function test_global_identity_wallet_lots_and_atomic_internal_transfer(): void
    {
        $receipt = $this->submit(100000);
        $this->receipts->verify($this->founder, $receipt, 100000, 'TRANSFER-FUND', (string) Str::uuid());
        $receiver = User::factory()->create();
        $from = $this->ledger->walletAccount(MemberIdentity::forUser($this->member));
        $to = $this->ledger->walletAccount(MemberIdentity::forUser($receiver));
        $key = (string) Str::uuid();
        $journal = $this->ledger->transfer($this->member, $key, 'fixture_transfer', $from, $to, 60000, $this->community, ['reference' => 'fixture-1']);
        $again = $this->ledger->transfer($this->member, $key, 'fixture_transfer', $from, $to, 60000, $this->community, ['reference' => 'fixture-1']);
        $this->assertSame($journal->id, $again->id);
        $this->assertSame(40000, $this->ledger->wallet($this->member)['available']);
        $this->assertSame(60000, $this->ledger->wallet($receiver)['available']);
        $this->assertSame(1, DB::table('ledger_lot_holdings')->where('account_id', $to->id)->where('receipt_id', $receipt->id)->count());
        $this->assertSame(-100000, $this->ledger->cashAccount($this->receipts->custodianKey($this->community))->balance);
        $this->assertSame(0, DB::table('ledger_clearings')->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_insufficient_debit_rolls_back_recipient_and_idempotency_payload_is_bound(): void
    {
        $receipt = $this->submit(40000);
        $this->receipts->verify($this->founder, $receipt, 40000, 'FAIL-FUND', (string) Str::uuid());
        $from = $this->ledger->walletAccount(MemberIdentity::forUser($this->member));
        $to = $this->ledger->communityAccount($this->community);
        $key = (string) Str::uuid();
        $this->ledger->transfer($this->member, $key, 'fixture_pay', $from, $to, 30000, $this->community);
        try {
            $this->ledger->transfer($this->member, $key, 'fixture_pay', $from, $to, 20000, $this->community);
            $this->fail('Changed payload must conflict');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        try {
            $this->ledger->transfer($this->member, (string) Str::uuid(), 'fixture_pay', $from, $to, 20000, $this->community);
            $this->fail('Insufficient debit must fail');
        } catch (ValidationException) {
            $this->assertSame(10000, $from->fresh()->balance);
            $this->assertSame(30000, $to->fresh()->balance);
            $this->assertSame(2, LedgerJournal::query()->count());
            $this->assertTrue($this->ledger->reconcile()['balanced']);
        }
    }

    public function test_available_reserve_hold_separate_and_release_finalization_are_once(): void
    {
        $receipt = $this->submit(100000);
        $this->receipts->verify($this->founder, $receipt, 100000, 'HOLD-FUND', (string) Str::uuid());
        $identity = MemberIdentity::forUser($this->member);
        $reservation = $this->ledger->reserve($this->member, $identity, 70000, 'reserved', 'package-fixture', (string) Str::uuid(), $this->community);
        $expected = ['identity_id' => $identity->id, 'currency' => 'IDR', 'available' => 30000, 'reserved' => 70000, 'held' => 0, 'total' => 100000];
        $wallet = $this->ledger->wallet($this->member);
        // SQL row order is unspecified; compare the complete map with exact types and rupiah values.
        ksort($expected);
        ksort($wallet);
        $this->assertSame($expected, $wallet);
        $key = (string) Str::uuid();
        $recipient = $this->ledger->communityAccount($this->community);
        $this->ledger->finalize($this->member, $reservation, $recipient, 20000, $key, 'Fixture sesi');
        $this->ledger->finalize($this->member, $reservation, $recipient, 20000, $key, 'Fixture sesi');
        $releaseKey = (string) Str::uuid();
        $this->ledger->release($this->member, $reservation, $releaseKey, 'Kegagalan teknis fixture');
        $this->ledger->release($this->member, $reservation, $releaseKey, 'Kegagalan teknis fixture');
        $this->assertSame(80000, $this->ledger->wallet($this->member)['available']);
        $this->assertSame(0, $this->ledger->wallet($this->member)['reserved']);
        $this->assertSame(20000, $recipient->fresh()->balance);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_failed_callback_does_not_leave_any_postings_or_partial_credits(): void
    {
        $receipt = $this->submit(50000);
        $this->receipts->verify($this->founder, $receipt, 50000, 'ROLLBACK-FUND', (string) Str::uuid());
        $from = $this->ledger->walletAccount(MemberIdentity::forUser($this->member));
        $to = $this->ledger->communityAccount($this->community);
        try {
            DB::transaction(function () use ($from, $to): void {
                $this->ledger->transfer($this->member, (string) Str::uuid(), 'fixture_crash', $from, $to, 50000, $this->community);
                throw new \RuntimeException('Fixture before commit');
            });
            $this->fail('Expected simulated crash');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fixture before commit', $exception->getMessage());
        }
        $this->assertSame(50000, $from->fresh()->balance);
        $this->assertSame(0, $to->fresh()->balance);
        $this->assertSame(1, LedgerJournal::query()->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_invalid_amounts_and_evidence_are_rejected_without_credit(): void
    {
        Passport::actingAs($this->member);
        $body = $this->body(1);
        foreach ([0, -1, 1.25, 1000000001] as $amount) {
            $this->postJson($this->url(), [...$body, 'claimed_amount' => $amount])->assertUnprocessable();
        }
        $missing = Asset::factory()->create(['user_id' => $this->member->id, 'storage_type' => StorageType::Private]);
        $this->postJson($this->url(), $this->body(50000, $missing))->assertUnprocessable();
        $this->assertSame(0, LedgerJournal::query()->count());
    }

    public function test_http_direct_member_and_platform_receipts_preserve_purpose_settle_invoices_and_allocate_excess_once(): void
    {
        $this->travelTo(Carbon::parse('2026-01-24 10:00:00', 'Asia/Jakarta'));
        $this->community->forceFill(['created_at' => now(), 'dues_amount' => 50000, 'entry_fee' => 10000, 'dues_start_date' => '2026-01-01'])->save();
        $member = $this->community->membershipOf($this->member);
        app(BillingService::class)->initializeJoin($member);
        app(BillingService::class)->platformSubject($this->community, true);
        $this->travelTo(Carbon::parse('2026-01-31 10:00:00', 'Asia/Jakarta'));
        Passport::actingAs($this->member);
        $billingUrl = '/api/v1/communities/'.$this->community->id.'/billing';
        $invoices = $this->getJson($billingUrl)->assertOk()->json('data.invoices');
        $body = [...$this->body(70000), 'purpose' => 'member', 'invoice_ids' => array_column($invoices, 'id'), 'target_member_id' => $member->id, 'credit_excess' => true];
        $id = $this->postJson($this->url(), $body)->assertCreated()->assertJsonPath('data.purpose', 'member')->assertJsonPath('data.credit_excess', true)->assertJsonPath('data.target_member_id', $member->id)->json('data.id');
        Passport::actingAs($this->founder);
        $verification = ['verified_amount' => 70000, 'bank_reference' => 'HTTP-MEMBER', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson($this->url().'/'.$id.'/verify', $verification)->assertOk();
        $this->postJson($this->url().'/'.$id.'/verify', $verification)->assertOk();
        $this->assertSame(60000, $this->ledger->communityAccount($this->community)->balance);
        $this->assertSame(10000, $this->ledger->wallet($this->member)['available']);
        $this->assertSame($this->founder->id, DB::table('billing_allocations')->value('actor_id'));
        Passport::actingAs($this->member);
        $this->getJson($billingUrl)->assertJsonPath('data.entitlement.active', true)->assertJsonPath('data.entitlement.arrears_amount', 0);
        Passport::actingAs($this->founder);
        $platformInvoices = $this->getJson($billingUrl.'?scope=platform')->assertOk()->json('data.invoices');
        $platformBody = [...$this->body(100000, $this->proof($this->founder)), 'purpose' => 'platform', 'invoice_ids' => array_column($platformInvoices, 'id')];
        $platformId = $this->postJson($this->url(), $platformBody)->assertCreated()->assertJsonPath('data.purpose', 'platform')->assertJsonPath('data.can_review', false)->json('data.id');
        $platformVerification = ['verified_amount' => 100000, 'bank_reference' => 'HTTP-PLATFORM', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson($this->url().'/'.$platformId.'/verify', $platformVerification)->assertForbidden();
        $platformAdmin = User::factory()->create();
        $platformAdmin->assignRole('super-admin');
        Passport::actingAs($platformAdmin);
        $this->postJson($this->url().'/'.$platformId.'/verify', $platformVerification)->assertOk();
        $this->getJson('/api/v1/wallet?scope=platform')->assertOk()->assertJsonPath('data.available', 100000);
        $this->getJson('/api/v1/wallet/transactions?scope=platform')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.amount', 100000);
        Passport::actingAs($this->founder);
        $this->getJson('/api/v1/wallet/transactions?scope=community&community_id='.$this->community->id)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.amount', 60000);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
        $this->travelBack();
    }

    public function test_cross_custodian_manual_payout_and_settlement_have_four_balanced_postings_and_no_extra_liability(): void
    {
        $sourceReceipt = $this->submit(100000);
        $this->receipts->verify($this->founder, $sourceReceipt, 100000, 'CUSTODY-A-FUND', (string) Str::uuid());
        $handlerFounder = User::factory()->create();
        $handler = Community::factory()->approved()->for($handlerFounder, 'owner')->create();
        $handlerReceipt = $this->receipts->submit($handlerFounder, $handler, $this->body(100000, $this->proof($handlerFounder)));
        $this->receipts->verify($handlerFounder, $handlerReceipt, 100000, 'CUSTODY-B-FUND', (string) Str::uuid());
        $seller = User::factory()->create();
        $sellerIdentity = MemberIdentity::forUser($seller);
        $this->ledger->transfer($this->member, (string) Str::uuid(), 'fixture_cross_community', $this->ledger->walletAccount(MemberIdentity::forUser($this->member)), $this->ledger->walletAccount($sellerIdentity), 60000, $handler);
        $this->assertSame(0, LedgerClearing::query()->count());
        $hold = $this->ledger->reserve($seller, $sellerIdentity, 60000, 'held', 'cash-fixture', (string) Str::uuid(), $handler);
        $this->assertSame(0, $this->ledger->wallet($seller)['available']);
        $this->assertSame(60000, $this->ledger->wallet($seller)['held']);
        $proof = $this->proof($handlerFounder);
        $key = (string) Str::uuid();
        $cash = app(CustodyService::class);
        $journal = $cash->payHeld($handlerFounder, $hold, $this->receipts->custodianKey($handler), $proof, 60000, 'MANUAL-CASH-1', $key);
        $this->assertSame($journal->id, $cash->payHeld($handlerFounder, $hold, $this->receipts->custodianKey($handler), $proof, 60000, 'MANUAL-CASH-1', $key)->id);
        $this->assertSame(4, DB::table('ledger_entries')->where('journal_id', $journal->id)->count());
        $this->assertSame(40000, $this->ledger->wallet($this->member)['available']);
        $this->assertSame(0, $this->ledger->wallet($seller)['held']);
        $this->assertSame(-40000, $this->ledger->cashAccount($this->receipts->custodianKey($handler))->balance);
        $clearing = LedgerClearing::query()->sole();
        $this->assertSame(60000, $clearing->amount);
        $this->assertTrue($this->ledger->reconcile()['balanced'], json_encode($this->ledger->reconcile()));
        $settlementProof = $this->proof($this->founder);
        $settled = $cash->settle($this->founder, $clearing, $settlementProof, 'MANUAL-SETTLE-1', (string) Str::uuid());
        $this->assertSame(4, DB::table('ledger_entries')->where('journal_id', $settled->id)->count());
        $this->assertSame(-40000, $this->ledger->cashAccount($this->receipts->custodianKey($this->community))->balance);
        $this->assertSame(-100000, $this->ledger->cashAccount($this->receipts->custodianKey($handler))->balance);
        $this->assertSame('settled', $clearing->fresh()->status);
        $newHold = $this->ledger->reserve($this->member, MemberIdentity::forUser($this->member), 40000, 'held', 'duplicate-proof-cash', (string) Str::uuid());
        $journalCount = LedgerJournal::query()->count();
        try {
            $cash->payHeld($handlerFounder, $newHold, $this->receipts->custodianKey($handler), $proof, 40000, 'DUPLICATE-OUTGOING', (string) Str::uuid());
            $this->fail('Outgoing proof reuse must fail');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(40000, $newHold->fresh()->remaining);
        $this->assertSame($journalCount, LedgerJournal::query()->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_two_custodian_lots_follow_global_wallet_and_insufficient_cash_keeps_hold_untouched(): void
    {
        $first = $this->submit(50000);
        $this->receipts->verify($this->founder, $first, 50000, 'TWO-A', (string) Str::uuid());
        $otherFounder = User::factory()->create();
        $other = Community::factory()->approved()->for($otherFounder, 'owner')->create();
        CommunityMember::factory()->create(['community_id' => $other->id, 'user_id' => $this->member->id]);
        $second = $this->receipts->submit($this->member, $other, $this->body(50000));
        $this->receipts->verify($otherFounder, $second, 50000, 'TWO-B', (string) Str::uuid());
        $receiver = User::factory()->create();
        $to = $this->ledger->walletAccount(MemberIdentity::forUser($receiver));
        $this->ledger->transfer($this->member, (string) Str::uuid(), 'fixture_two_lots', $this->ledger->walletAccount(MemberIdentity::forUser($this->member)), $to, 80000);
        $this->assertSame(2, DB::table('ledger_lot_holdings')->where('account_id', $to->id)->where('amount', '>', 0)->count());
        $hold = $this->ledger->reserve($receiver, MemberIdentity::forUser($receiver), 80000, 'held', 'two-custodian-hold', (string) Str::uuid());
        $before = LedgerJournal::query()->count();
        try {
            app(CustodyService::class)->payHeld($this->founder, $hold, $first->custodian_key, $this->proof($this->founder), 80000, 'NO-CASH', (string) Str::uuid());
            $this->fail('Cannot payout more physical cash than recorded');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame($before, LedgerJournal::query()->count());
        $this->assertSame(80000, $hold->fresh()->remaining);
        $releaseKey = (string) Str::uuid();
        $this->ledger->release($receiver, $hold, $releaseKey, 'Belum ditransfer; fixture gagal');
        $this->ledger->release($receiver, $hold, $releaseKey, 'Belum ditransfer; fixture gagal');
        $this->assertSame(80000, $this->ledger->wallet($receiver)['available']);
        $this->assertSame(0, $this->ledger->wallet($receiver)['held']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_failed_duplicate_receipt_does_not_roll_back_private_asset_location_after_bytes_move(): void
    {
        $first = $this->proof($this->member, 'duplicate-after-move');
        $this->receipts->submit($this->member, $this->community, $this->body(50000, $first));
        $second = Asset::factory()->create(['user_id' => $this->member->id, 'storage_type' => StorageType::Local]);
        Storage::disk('public')->put($second->path, 'duplicate-after-move');
        $oldPath = $second->path;
        Passport::actingAs($this->member);
        $this->postJson($this->url(), $this->body(50000, $second))->assertConflict();
        $second->refresh();
        $this->assertSame(StorageType::Private, $second->storage_type);
        $this->assertTrue($second->is_protected);
        Storage::disk('local')->assertExists($second->path);
        Storage::disk('public')->assertMissing($oldPath);
        $this->assertSame(0, LedgerJournal::query()->count());
    }

    public function test_identity_claim_merges_available_lots_and_rejects_orphaning_active_reservations(): void
    {
        $receipt = $this->submit(100000);
        $this->receipts->verify($this->founder, $receipt, 100000, 'MERGE-FUND', (string) Str::uuid());
        $source = MemberIdentity::forUser($this->member);
        $target = MemberIdentity::forUser(User::factory()->create());
        $hold = $this->ledger->reserve($this->member, $source, 20000, 'held', 'merge-hold-fixture', (string) Str::uuid());
        try {
            $this->ledger->mergeIdentity($source, $target, $this->founder);
            $this->fail('Claim must not orphan held funds');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->ledger->release($this->member, $hold, (string) Str::uuid(), 'Belum ditransfer');
        $this->ledger->mergeIdentity($source, $target, $this->founder);
        $this->ledger->mergeIdentity($source, $target, $this->founder);
        $this->assertSame(0, $this->ledger->walletAccount($source)->balance);
        $this->assertSame(100000, $this->ledger->walletAccount($target)->balance);
        $this->assertSame(1, LedgerJournal::query()->where('operation', 'identity_merge')->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_receiving_account_changes_create_distinct_custody_buckets_and_preserve_snapshot(): void
    {
        $first = $this->submit(50000);
        $this->receipts->verify($this->founder, $first, 50000, 'SAME-REFERENCE-DIFFERENT-ACCOUNT', (string) Str::uuid());
        $this->community->forceFill(['dues_account_number' => '00000002', 'dues_account_name' => 'Rekening Baru Fixture'])->save();
        $second = $this->submit(30000);
        $this->receipts->verify($this->founder, $second, 30000, 'SAME-REFERENCE-DIFFERENT-ACCOUNT', (string) Str::uuid());
        $this->assertNotSame($first->custodian_key, $second->custodian_key);
        $this->assertSame('00000001', $first->fresh()->collector_snapshot['account_number']);
        $this->assertSame('00000002', $second->fresh()->collector_snapshot['account_number']);
        $this->assertSame(-50000, $this->ledger->cashAccount($first->custodian_key)->balance);
        $this->assertSame(-30000, $this->ledger->cashAccount($second->custodian_key)->balance);
        $this->assertSame(80000, $this->ledger->wallet($this->member)['available']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_technical_transfer_correction_is_compensating_audited_lot_safe_and_once_without_refund_endpoint(): void
    {
        $receipt = $this->submit(100000);
        $this->receipts->verify($this->founder, $receipt, 100000, 'CORRECTION-FUND', (string) Str::uuid());
        $receiver = MemberIdentity::forUser(User::factory()->create());
        $source = $this->ledger->walletAccount(MemberIdentity::forUser($this->member));
        $target = $this->ledger->walletAccount($receiver);
        $original = $this->ledger->transfer($this->member, (string) Str::uuid(), 'technical_duplicate_transfer', $source, $target, 50000, $this->community, ['reference' => 'incident-fixture']);
        $this->founder->assignRole('super-admin');
        $key = (string) Str::uuid();
        $corrected = $this->ledger->correctTransfer($this->founder, $original, $key, 'Duplikasi teknis fixture; bukan refund pembelian');
        $again = $this->ledger->correctTransfer($this->founder, $original, $key, 'Duplikasi teknis fixture; bukan refund pembelian');
        $this->assertSame($corrected->id, $again->id);
        $this->assertSame($original->id, $corrected->metadata['original_journal_id']);
        $this->assertSame($this->founder->id, $corrected->actor_id);
        $this->assertNotNull($corrected->created_at);
        $this->assertSame(100000, $source->fresh()->balance);
        $this->assertSame(0, $target->fresh()->balance);
        $this->assertSame(3, LedgerJournal::query()->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
        try {
            $this->ledger->correctTransfer($this->founder, $original, (string) Str::uuid(), 'Koreksi kedua tidak sah');
            $this->fail('The same source cannot be corrected twice');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        try {
            $this->ledger->correctTransfer($this->founder, LedgerJournal::query()->findOrFail($receipt->fresh()->journal_id), (string) Str::uuid(), 'Tidak boleh mencetak kas');
            $this->fail('Cash receipts cannot be reversed with liability-only repair');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_imported_approved_receipt_is_in_verified_filter_without_recredit_or_new_review(): void
    {
        $proof = $this->proof($this->member);
        $payment = DuesPayment::query()->forceCreate(['community_id' => $this->community->id, 'user_id' => $this->member->id, 'months_count' => 1, 'months' => ['2026-01'], 'monthly_amount' => 50000, 'entry_fee' => 0, 'total_amount' => 50000, 'proof_asset_id' => $proof->id, 'status' => 'approved', 'reviewed_by' => $this->founder->id, 'reviewed_at' => now()]);
        $receipt = $this->receipts->registerLegacyProof($payment, true);
        Passport::actingAs($this->member);
        $this->getJson('/api/v1/wallet/receipts?status=verified')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.status', 'legacy_verified')->assertJsonPath('data.items.0.verified_amount', 50000);
        $this->getJson('/api/v1/wallet/receipts?status=pending')->assertOk()->assertJsonPath('data.total', 0);
        Passport::actingAs($this->founder);
        $this->getJson($this->url().'?status=verified')->assertOk()->assertJsonPath('data.total', 1);
        $this->postJson($this->url().'/'.$receipt->id.'/verify', ['verified_amount' => 50000, 'bank_reference' => 'LEGACY-RETRY', 'idempotency_key' => (string) Str::uuid()])->assertConflict();
        $this->assertSame(0, LedgerJournal::query()->count());
        $this->assertSame(0, $this->ledger->wallet($this->member)['available']);
        $this->assertSame(50000, $payment->fresh()->monthly_amount);
    }

    public function test_aggregate_wallet_cannot_exceed_exact_web_integer_limit_after_funds_are_reserved(): void
    {
        config(['finance.max_transaction' => LedgerService::MAX_SAFE_INTEGER]);
        $amount = LedgerService::MAX_SAFE_INTEGER - 100;
        $receipt = $this->submit($amount);
        $this->receipts->verify($this->founder, $receipt, $amount, 'SAFE-AGGREGATE-BASE', (string) Str::uuid());
        $this->ledger->reserve($this->member, MemberIdentity::forUser($this->member), 200, 'reserved', 'exact-wallet-ceiling', (string) Str::uuid());
        $next = $this->submit(200);
        try {
            $this->receipts->verify($this->founder, $next, 200, 'SAFE-AGGREGATE-OVER', (string) Str::uuid());
            $this->fail('Aggregate wallet must stay within the exact web integer range');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $wallet = $this->ledger->wallet($this->member);
        $this->assertSame(LedgerService::MAX_SAFE_INTEGER - 300, $wallet['available']);
        $this->assertSame(200, $wallet['reserved']);
        $this->assertSame(LedgerService::MAX_SAFE_INTEGER - 100, $wallet['total']);
        $this->assertSame('pending', $next->fresh()->status);
        $this->assertSame(2, LedgerJournal::query()->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    private function submit(int $amount): IncomingReceipt
    {
        return $this->receipts->submit($this->member, $this->community, $this->body($amount));
    }

    /** @return array<string,mixed> */
    private function body(int $amount, ?Asset $proof = null): array
    {
        return ['claimed_amount' => $amount, 'proof_asset_id' => ($proof ?? $this->proof($this->member))->id, 'bank_reference' => 'CLAIM-'.Str::uuid(), 'idempotency_key' => (string) Str::uuid()];
    }

    private function proof(User $owner, ?string $bytes = null): Asset
    {
        $asset = Asset::factory()->create(['user_id' => $owner->id, 'storage_type' => StorageType::Private, 'category' => 'topup-proof']);
        Storage::disk('local')->put($asset->path, $bytes ?? 'fixture:'.Str::uuid());

        return $asset;
    }

    private function url(): string
    {
        return '/api/v1/communities/'.$this->community->id.'/receipts';
    }
}
