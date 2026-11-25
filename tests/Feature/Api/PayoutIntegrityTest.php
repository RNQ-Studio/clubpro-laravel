<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\LedgerAccount;
use App\Models\LedgerJournal;
use App\Models\MemberIdentity;
use App\Models\PayoutEvent;
use App\Models\PayoutPart;
use App\Models\PrimaryBankAccount;
use App\Models\User;
use App\Services\CustodyService;
use App\Services\IncomingReceiptService;
use App\Services\SensitiveAssetService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\PayoutFixture;
use Tests\TestCase;

/** Independent regression coverage for atomic failure and source-specific custody. */
class PayoutIntegrityTest extends TestCase
{
    use PayoutFixture, RefreshDatabase;

    public function test_completed_application_retry_is_resolved_before_a_changed_pin_can_misclassify_it_as_unsent(): void
    {
        $this->fixture();
        $transferData = $this->transferData(10000);
        $transfer = $this->movement->transfer($this->sender, $transferData);
        $payoutData = $this->payoutData(70000);
        $payout = $this->movement->createPayout($this->sender, $payoutData);
        $wrongPin = str_pad((string) (((int) $this->fixturePin + 1) % 1000000), 6, '0', STR_PAD_LEFT);
        $before = $this->moneyState();
        $this->assertSame($transfer->id, $this->movement->transfer($this->sender, [...$transferData, 'pin' => $wrongPin])->id);
        $this->assertSame($payout->id, $this->movement->createPayout($this->sender, [...$payoutData, 'pin' => $wrongPin])->id);
        $this->assertSame($before, $this->moneyState());
        try {
            $this->movement->transfer($this->sender, [...$transferData, 'pin' => $wrongPin, 'idempotency_key' => (string) Str::uuid()]);
            $this->fail('A new transfer still requires a valid transaction PIN.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pin', $exception->errors());
        }
        $this->assertSame($before, $this->moneyState());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_final_money_movement_history_and_destinations_reject_direct_sql_rewrite(): void
    {
        $this->fixture();
        $payout = $this->payout();
        $part = $this->part($payout);
        $fragment = $this->fragment($part, 70000);
        $this->movement->verifyFragment($this->founder, $part->fresh(), $fragment, ['expected_version' => $part->fresh()->version, 'idempotency_key' => (string) Str::uuid()]);
        $event = PayoutEvent::query()->where('payout_part_id', $part->id)->firstOrFail();
        $transfer = $this->movement->transfer($this->sender, $this->transferData(10000));
        $this->rejectHistoryWrite(fn () => DB::table('payouts')->where('id', $payout->id)->update(['bank_snapshot' => '{}']));
        $this->rejectHistoryWrite(fn () => DB::table('payouts')->where('id', $payout->id)->update(['amount' => 1]));
        $this->rejectHistoryWrite(fn () => DB::table('payouts')->where('id', $payout->id)->update(['status' => 'requested']));
        $this->rejectHistoryWrite(fn () => DB::table('payout_parts')->where('id', $part->id)->update(['custodian_key' => 'platform:unconfigured']));
        $this->rejectHistoryWrite(fn () => DB::table('payout_parts')->where('id', $part->id)->update(['verified_amount' => 0]));
        $this->rejectHistoryWrite(fn () => DB::table('payout_fragments')->where('id', $fragment->id)->update(['amount' => 1]));
        $this->rejectHistoryWrite(fn () => DB::table('payout_events')->where('id', $event->id)->update(['note' => 'Diubah langsung']));
        $this->rejectHistoryWrite(fn () => DB::table('payout_events')->where('id', $event->id)->delete());
        $this->rejectHistoryWrite(fn () => DB::table('member_transfers')->where('id', $transfer->id)->update(['amount' => 1]));
        $this->assertSame('succeeded', $payout->fresh()->status);
        $this->assertSame(70000, $part->fresh()->verified_amount);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    private function rejectHistoryWrite(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('Direct SQL changed immutable payout or transfer history.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('P0001', $exception->getMessage());
        }
    }

    public function test_revoked_staff_cannot_reissue_private_payout_proof_url_even_if_they_uploaded_it(): void
    {
        $this->fixture();
        $payout = $this->payout();
        $part = $this->part($payout);
        $fragment = $this->fragment($part, 70000, $this->admin);
        $proof = Asset::query()->findOrFail($fragment->proof_asset_id);
        $service = app(SensitiveAssetService::class);
        $this->assertNotNull($service->viewUrl($proof, $this->admin));
        $this->assertNotNull($service->viewUrl($proof, $this->sender));
        $this->assertNull($service->viewUrl($proof, $this->recipient));
        CommunityMember::query()->where('community_id', $this->community->id)->where('user_id', $this->admin->id)->update(['role' => 'member']);
        $this->assertFalse($this->movement->canManage($this->admin, $part));
        $this->assertNull($service->viewUrl($proof, $this->admin));
        $this->assertNotNull($service->viewUrl($proof, $this->sender));
        $this->assertNull($proof->getPublicUrl());
    }

    public function test_private_recipient_search_does_not_reveal_other_community_memberships(): void
    {
        $this->fixture();
        MemberIdentity::forUser($this->recipient)->update(['is_public' => false]);
        $elsewhere = Community::factory()->approved()->create();
        CommunityMember::factory()->for($elsewhere)->for($this->recipient)->create();
        Passport::actingAs($this->sender);
        $response = $this->getJson('/api/v1/transfers/recipients?community_id='.$this->community->id.'&q=Penerima')->assertOk();
        $this->assertSame([$this->community->id], array_column($response->json('data.items'), 'community_id'));
        $this->assertStringNotContainsString($elsewhere->name, $response->getContent());
    }

    public function test_platform_custody_uses_platform_handler_even_when_original_receipt_has_community_context(): void
    {
        $this->fixture();
        config(['finance.platform_bank_name' => 'Bank Platform Fixture', 'finance.platform_account_number' => '000'.random_int(1000000, 9999999), 'finance.platform_account_name' => 'Kas Platform Fixture']);
        $operator = User::factory()->create(['name' => 'Operator Platform Fixture']);
        $operator->assignRole('super-admin');
        $this->founder->forceFill(['pin_hash' => Hash::make($this->fixturePin)])->save();
        $this->movement->savePrimary($this->founder, $this->bankData());
        $proof = $this->proof($this->founder);
        $receipts = app(IncomingReceiptService::class);
        $receipt = $receipts->submit($this->founder, $this->community, ['purpose' => 'platform', 'claimed_amount' => 80000, 'proof_asset_id' => $proof->id, 'bank_reference' => 'PLATFORM-CLAIM-'.Str::uuid(), 'idempotency_key' => (string) Str::uuid()]);
        // A real platform overpayment can credit the payer's wallet. The trusted
        // allocation keeps the fixture independent of unrelated invoice dates.
        $receipts->verify($operator, $receipt, 80000, 'PLATFORM-FUNDED-'.Str::uuid(), (string) Str::uuid(), [['account_id' => $this->ledger->walletAccount(MemberIdentity::forUser($this->founder))->id, 'amount' => 80000]]);
        $payout = $this->movement->createPayout($this->founder, $this->payoutData(80000));
        $part = $this->part($payout);
        $this->assertStringStartsWith('platform:', $part->custodian_key);
        $this->assertNull($part->community_id, 'Platform receipt context is not its payout handler.');
        $this->assertTrue($this->movement->canManage($operator, $part));
        $this->assertFalse($this->movement->canManage($this->founder, $part));
        $fragment = $this->fragment($part, 80000, $operator);
        $this->movement->verifyFragment($operator, $part->fresh(), $fragment, ['expected_version' => $part->fresh()->version, 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame('succeeded', $payout->fresh()->status);
        $this->assertSame(0, $this->ledger->wallet($this->founder)['held']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_audit_and_verification_timestamps_preserve_the_instant_in_non_utc_database_session(): void
    {
        $previousZone = DB::selectOne('SHOW timezone')->TimeZone;
        DB::statement("SET TIME ZONE 'Asia/Jakarta'");
        $instant = CarbonImmutable::parse('2026-11-26T10:23:45+00:00');
        $this->travelTo($instant);
        try {
            $this->fixture();
            $payout = $this->payout();
            $part = $this->part($payout);
            $fragment = $this->fragment($part, 70000);
            $this->movement->verifyFragment($this->founder, $part->fresh(), $fragment, ['expected_version' => $part->fresh()->version, 'idempotency_key' => (string) Str::uuid()]);
            foreach (PayoutEvent::query()->where('payout_part_id', $part->id)->get() as $event) {
                $rawEpoch = (int) DB::selectOne('SELECT extract(epoch FROM created_at) AS epoch FROM payout_events WHERE id = ?', [$event->id])->epoch;
                $this->assertSame($instant->getTimestamp(), $rawEpoch, 'The payout audit instant changed across timezone serialization.');
                $this->assertSame($instant->getTimestamp(), $event->fresh()->created_at->getTimestamp());
            }
            $rawEpoch = (int) DB::selectOne('SELECT extract(epoch FROM verified_at) AS epoch FROM payout_fragments WHERE id = ?', [$fragment->id])->epoch;
            $this->assertSame($instant->getTimestamp(), $rawEpoch);
            $this->assertSame($instant->getTimestamp(), $fragment->fresh()->verified_at->getTimestamp());
        } finally {
            DB::select("SELECT set_config('TimeZone', ?, false)", [$previousZone]);
            $this->travelBack();
        }
    }

    public function test_own_cancellation_retry_releases_once_and_is_still_authorized_after_final_status(): void
    {
        $this->fixture();
        $payout = $this->payout();
        $part = $this->part($payout);
        $data = ['expected_version' => $part->version, 'confirmed_unsent' => true, 'note' => 'Dibatalkan pemilik sebelum pemrosesan fixture.', 'idempotency_key' => (string) Str::uuid()];
        $first = $this->movement->transition($this->sender, $part, 'cancel', $data);
        $this->assertSame('cancelled', $first->status);
        $before = $this->moneyState();
        $again = $this->movement->transition($this->sender, $part->fresh(), 'cancel', $data);
        $this->assertSame($first->id, $again->id);
        $this->assertSame($before, $this->moneyState());
        $this->assertSame(100000, $this->ledger->wallet($this->sender)['available']);
        $this->assertSame(0, $this->ledger->wallet($this->sender)['held']);
        $this->assertSame(3, LedgerJournal::query()->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_primary_bank_is_encrypted_and_edit_cannot_redirect_existing_payout_snapshot(): void
    {
        $this->fixture();
        $payout = $this->payout();
        $snapshot = $payout->fresh()->bank_snapshot;
        $rawPrimary = (string) DB::table('primary_bank_accounts')->where('user_id', $this->sender->id)->value('details');
        $rawSnapshot = (string) DB::table('payouts')->where('id', $payout->id)->value('bank_snapshot');
        $this->assertStringNotContainsString($this->fixtureBankNumber, $rawPrimary);
        $this->assertStringNotContainsString($this->fixtureBankNumber, $rawSnapshot);
        $this->assertSame('Pihak Ketiga Fixture', $snapshot['account_name']);
        $this->movement->savePrimary($this->sender, [...$this->bankData(1), 'account_number' => '000'.random_int(1000000, 9999999), 'account_name' => 'Pihak Ketiga Baru Fixture']);
        $this->assertSame(2, PrimaryBankAccount::query()->where('user_id', $this->sender->id)->firstOrFail()->version);
        $this->assertSame($snapshot, $payout->fresh()->bank_snapshot);
        $fragment = $this->fragment($this->part($payout), 70000);
        $part = $this->part($payout);
        $this->movement->verifyFragment($this->founder, $part, $fragment, ['expected_version' => $part->version, 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame('succeeded', $payout->fresh()->status);
        $this->assertSame($snapshot, $payout->fresh()->bank_snapshot);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_trusted_payout_primitives_replay_without_consuming_sources_or_recording_proof_twice(): void
    {
        $this->fixture();
        $payout = $this->payout();
        $part = $this->part($payout);
        $fragment = $this->fragment($part, 70000);
        $this->movement->verifyFragment($this->founder, $part->fresh(), $fragment, ['expected_version' => $part->fresh()->version, 'idempotency_key' => (string) Str::uuid()]);
        $journal = LedgerJournal::query()->findOrFail($fragment->fresh()->journal_id);
        $before = $this->moneyState();
        $again = app(CustodyService::class)->payPart($this->founder, $part->fresh(), $fragment->fresh(), $journal->idempotency_key);
        $this->assertSame($journal->id, $again->id);
        $this->assertSame($before, $this->moneyState());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_database_fault_after_journal_and_balance_writes_rolls_back_all_payout_finalization(): void
    {
        $this->fixture();
        $payout = $this->payout();
        $part = $this->part($payout);
        $fragment = $this->fragment($part, 70000);
        $held = $this->ledger->walletAccount(MemberIdentity::forUser($this->sender), 'held');
        $before = $this->moneyState();
        $function = 'test_payout_fail_lot_'.str_replace('-', '', (string) Str::uuid());
        $trigger = 'test_payout_fail_lot';
        DB::unprepared('CREATE FUNCTION '.$function.'() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF OLD.account_id = '.$held->id.' AND NEW.amount < OLD.amount THEN RAISE EXCEPTION \'payout lot fixture failure\'; END IF; RETURN NEW; END $$');
        DB::unprepared('CREATE TRIGGER '.$trigger.' BEFORE UPDATE ON ledger_lot_holdings FOR EACH ROW EXECUTE FUNCTION '.$function.'()');
        $data = ['expected_version' => $part->fresh()->version, 'idempotency_key' => (string) Str::uuid()];
        try {
            $this->movement->verifyFragment($this->founder, $part->fresh(), $fragment, $data);
            $this->fail('The injected payout lot failure did not occur.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('payout lot fixture failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER '.$trigger.' ON ledger_lot_holdings');
            DB::unprepared('DROP FUNCTION '.$function.'()');
        }
        $this->assertSame($before, $this->moneyState());
        $this->assertSame('pending', $fragment->fresh()->status);
        $this->assertSame(70000, $this->ledger->wallet($this->sender)['held']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);

        $result = $this->movement->verifyFragment($this->founder, $part->fresh(), $fragment->fresh(), $data);
        $this->assertSame('succeeded', $result->status);
        $this->assertSame(0, $this->ledger->wallet($this->sender)['held']);
        $this->assertSame(30000, $this->ledger->wallet($this->sender)['available']);
        $this->assertSame(3, LedgerJournal::query()->count());
        $again = $this->movement->verifyFragment($this->founder, $part->fresh(), $fragment->fresh(), $data);
        $this->assertSame($result->id, $again->id);
        $this->assertSame(3, LedgerJournal::query()->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_two_source_payouts_finish_out_of_order_without_consuming_other_requests_lots(): void
    {
        $this->fixture(100003);
        $otherFounder = User::factory()->create();
        $otherCommunity = Community::factory()->approved()->for($otherFounder, 'owner')->create([
            'dues_bank_name' => 'Bank Fixture Lain', 'dues_account_number' => '000'.random_int(1000000, 9999999), 'dues_account_name' => 'Kas Fixture Lain',
        ]);
        CommunityMember::factory()->for($otherCommunity)->for($this->sender)->create();
        $this->fund($this->sender, $otherCommunity, $otherFounder, 100007);
        $first = $this->payout(150005);
        $second = $this->payout(40000);
        $firstParts = PayoutPart::query()->where('payout_id', $first->id)->get();
        $secondPart = $this->part($second);
        $this->assertCount(2, $firstParts);
        $this->assertSame(190005, $this->ledger->wallet($this->sender)['held']);
        $this->assertSame(10005, $this->ledger->wallet($this->sender)['available']);

        // The second request consumes B while the first request still owns A+B.
        $this->assertSame($otherCommunity->id, $secondPart->community_id);
        $fragment = $this->fragment($secondPart, 40000, $otherFounder);
        $this->movement->verifyFragment($otherFounder, $secondPart->fresh(), $fragment, ['expected_version' => $secondPart->fresh()->version, 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame('succeeded', $second->fresh()->status);
        $this->assertSame(150005, $this->ledger->wallet($this->sender)['held']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);

        foreach ($firstParts->sortByDesc('community_id') as $part) {
            $reviewer = $part->community_id === $this->community->id ? $this->founder : $otherFounder;
            $fragment = $this->fragment($part, $part->amount, $reviewer);
            $this->movement->verifyFragment($reviewer, $part->fresh(), $fragment, ['expected_version' => $part->fresh()->version, 'idempotency_key' => (string) Str::uuid()]);
            $this->assertTrue($this->ledger->reconcile()['balanced']);
        }
        $this->assertSame('succeeded', $first->fresh()->status);
        $this->assertSame(150005, $first->fresh()->verified_amount);
        $this->assertSame(0, $this->ledger->wallet($this->sender)['held']);
        $this->assertSame(10005, $this->ledger->wallet($this->sender)['available']);
        $this->assertSame(-10005, (int) LedgerAccount::query()->where('kind', 'custody')->sum('balance'));
        $this->assertSame(0, (int) DB::table('ledger_clearings')->sum('amount'));
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_partial_verified_amount_keeps_remaining_hold_and_never_allows_final_refund(): void
    {
        $this->fixture();
        $payout = $this->payout();
        $part = $this->part($payout);
        $fragment = $this->fragment($part, 30000);
        $this->assertSame(70000, $this->ledger->wallet($this->sender)['held'], 'Proof upload alone cannot finalize money.');
        $this->movement->verifyFragment($this->founder, $part->fresh(), $fragment, ['expected_version' => $part->fresh()->version, 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame('uncertain', $payout->fresh()->status);
        $this->assertSame(30000, $payout->fresh()->verified_amount);
        $this->assertSame(40000, $this->ledger->wallet($this->sender)['held']);
        $this->assertSame(30000, $this->ledger->wallet($this->sender)['available']);

        foreach (['fail', 'cancel'] as $operation) {
            try {
                $this->movement->transition($this->founder, $part->fresh(), $operation, ['expected_version' => $part->fresh()->version, 'confirmed_unsent' => true, 'note' => 'Percobaan pelepasan sesudah parsial.', 'idempotency_key' => (string) Str::uuid()]);
                $this->fail('A verified partial payout must not be released or refunded.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        }
        $this->assertSame(40000, $this->ledger->wallet($this->sender)['held']);
        $remaining = $this->fragment($part, 40000);
        $this->movement->verifyFragment($this->founder, $part->fresh(), $remaining, ['expected_version' => $part->fresh()->version, 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame('succeeded', $payout->fresh()->status);
        $this->assertSame(70000, $payout->fresh()->verified_amount);
        $this->assertSame(0, $this->ledger->wallet($this->sender)['held']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }
}
