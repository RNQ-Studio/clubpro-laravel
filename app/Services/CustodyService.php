<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Community;
use App\Models\LedgerAccount;
use App\Models\LedgerClearing;
use App\Models\LedgerJournal;
use App\Models\LedgerReservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Manual cash primitives used by later payouts. No bank integration or user refund endpoint. */
class CustodyService
{
    public function __construct(private readonly LedgerService $ledger, private readonly IncomingReceiptService $receipts) {}

    public function payHeld(User $actor, LedgerReservation $reservation, string $handlerCustodian, Asset $proof, int $amount, string $bankReference, string $key): LedgerJournal
    {
        $this->ledger->assertAmount($amount);
        $this->authorizeCustodian($actor, $handlerCustodian);
        $digest = $this->prepareProof($actor, $proof);
        $reference = $this->reference($bankReference);

        return DB::transaction(function () use ($actor, $reservation, $handlerCustodian, $proof, $amount, $reference, $key, $digest): LedgerJournal {
            $this->ledger->lockOperation('manual-cash:'.$actor->id.':'.$key);
            $reservation = LedgerReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $held = LedgerAccount::query()->findOrFail($reservation->reserved_account_id);
            abort_unless($held->bucket === 'held', 422, 'Hanya dana pencairan tertahan yang dapat difinalkan.');
            $metadata = ['reference' => $reference, 'reservation_id' => $reservation->id, 'handler_custodian' => $handlerCustodian, 'proof_digest' => $digest, 'amount' => $amount];
            $previous = LedgerJournal::query()->where('actor_id', $actor->id)->where('operation', 'manual_payout')->where('idempotency_key', $key)->first();
            if ($previous !== null) {
                abort_unless($previous->metadata === $metadata, 409, 'Kunci transaksi sudah dipakai untuk data berbeda.');

                return $previous;
            }
            abort_if($amount > $reservation->remaining, 409, 'Pencairan melebihi dana tertahan.');
            $cash = $this->ledger->cashAccount($handlerCustodian);
            // Manual cash operations are rare. Serialize all existing accounts in ascending
            // order before selecting FIFO lots, so concurrent reserve/use cannot alter origins.
            LedgerAccount::query()->orderBy('id')->lockForUpdate()->get();
            $postings = [['account_id' => $held->id, 'amount' => -$amount], ['account_id' => $cash->id, 'amount' => $amount]];
            $lotParts = [];
            $clearings = [];
            $remaining = $amount;
            foreach (DB::table('ledger_lot_holdings')->join('incoming_receipts', 'incoming_receipts.id', '=', 'ledger_lot_holdings.receipt_id')->where('account_id', $held->id)->where('amount', '>', 0)->orderBy('ledger_lot_holdings.id')->get(['ledger_lot_holdings.id', 'amount', 'custodian_key']) as $lot) {
                $part = min($remaining, (int) $lot->amount);
                $lotParts[] = ['id' => $lot->id, 'amount' => $part];
                if ($lot->custodian_key !== $handlerCustodian) {
                    $clearings[$lot->custodian_key] = ($clearings[$lot->custodian_key] ?? 0) + $part;
                }
                $remaining -= $part;
                if ($remaining === 0) {
                    break;
                }
            }
            abort_unless($remaining === 0, 409, 'Sumber custody pencairan tidak cocok.');
            foreach ($clearings as $source => $part) {
                $postings[] = ['account_id' => $this->ledger->clearingAccount($source, $handlerCustodian, 'payable')->id, 'amount' => $part];
                $postings[] = ['account_id' => $this->ledger->clearingAccount($source, $handlerCustodian, 'receivable')->id, 'amount' => -$part];
            }
            // Lock the entire posting set in ascending order before checking liquidity.
            LedgerAccount::query()->whereKey(array_column($postings, 'account_id'))->orderBy('id')->lockForUpdate()->get();
            abort_if($cash->fresh()->balance + $amount > 0, 422, 'Kas kolektor belum mencukupi; dana tetap tertahan.');
            $journal = $this->ledger->post($actor, $key, 'manual_payout', $postings, $reservation->community_id === null ? null : Community::query()->findOrFail($reservation->community_id), $metadata, function (LedgerJournal $journal) use ($reservation, $clearings, $handlerCustodian, $amount, $lotParts, $reference): void {
                foreach ($lotParts as $part) {
                    DB::table('ledger_lot_holdings')->where('id', $part['id'])->decrement('amount', $part['amount']);
                }
                foreach ($clearings as $source => $part) {
                    LedgerClearing::query()->create(['source_custodian' => $source, 'handler_custodian' => $handlerCustodian, 'amount' => $part, 'status' => 'open', 'source_journal_id' => $journal->id, 'source_reference' => $reference.':'.$source]);
                }
                $reservation->update(['remaining' => $reservation->remaining - $amount, 'status' => $reservation->remaining === $amount ? 'finalized' : 'reserved']);
            });
            $this->recordProof($actor, $proof, $digest, $reference, $journal);

            return $journal;
        }, 3);
    }

    public function settle(User $actor, LedgerClearing $clearing, Asset $proof, string $bankReference, string $key): LedgerJournal
    {
        $this->authorizeCustodian($actor, $clearing->source_custodian);
        $digest = $this->prepareProof($actor, $proof);
        $reference = $this->reference($bankReference);

        return DB::transaction(function () use ($actor, $clearing, $proof, $reference, $key, $digest): LedgerJournal {
            $clearing = LedgerClearing::query()->whereKey($clearing->id)->lockForUpdate()->firstOrFail();
            if ($clearing->status === 'settled') {
                abort_unless($clearing->settlement_reference === $reference && $clearing->settlement_proof_digest === $digest, 409, 'Clearing sudah diselesaikan dengan data berbeda.');

                return LedgerJournal::query()->findOrFail($clearing->settlement_journal_id);
            }
            $source = $this->ledger->cashAccount($clearing->source_custodian);
            $handler = $this->ledger->cashAccount($clearing->handler_custodian);
            $payable = $this->ledger->clearingAccount($clearing->source_custodian, $clearing->handler_custodian, 'payable');
            $receivable = $this->ledger->clearingAccount($clearing->source_custodian, $clearing->handler_custodian, 'receivable');
            LedgerAccount::query()->whereKey([$source->id, $handler->id, $payable->id, $receivable->id])->orderBy('id')->lockForUpdate()->get();
            abort_if($source->fresh()->balance + $clearing->amount > 0, 422, 'Kas sumber belum mencukupi untuk settlement.');
            $journal = $this->ledger->post($actor, $key, 'manual_clearing_settlement', [
                ['account_id' => $source->id, 'amount' => $clearing->amount], ['account_id' => $handler->id, 'amount' => -$clearing->amount],
                ['account_id' => $payable->id, 'amount' => -$clearing->amount], ['account_id' => $receivable->id, 'amount' => $clearing->amount],
            ], null, ['reference' => $reference, 'clearing_id' => $clearing->id, 'proof_digest' => $digest, 'amount' => $clearing->amount]);
            $this->recordProof($actor, $proof, $digest, $reference, $journal);
            $clearing->update(['status' => 'settled', 'settlement_journal_id' => $journal->id, 'settlement_reference' => $reference, 'settlement_proof_digest' => $digest, 'settled_by' => $actor->id, 'settled_at' => now()]);

            return $journal;
        }, 3);
    }

    private function authorizeCustodian(User $actor, string $custodian): void
    {
        if (preg_match('/^platform(?::[a-f0-9]{64}|:unconfigured|:legacy-unknown)?$/', $custodian) === 1) {
            $this->receipts->assertReviewer($actor, null, 'platform');

            return;
        }
        abort_unless(preg_match('/^community:([0-9]+)(?::[a-f0-9]{64}|:unconfigured|:legacy-unknown)?$/', $custodian, $matches) === 1, 422, 'Kolektor tidak valid.');
        $this->receipts->assertReviewer($actor, Community::query()->findOrFail((int) $matches[1]), 'topup');
    }

    private function prepareProof(User $actor, Asset $proof): string
    {
        abort_unless($proof->user_id === $actor->id && $proof->status->value === 'active', 403, 'Bukti harus unggahan petugas sendiri.');
        $digest = $this->receipts->proofDigest($proof);
        app(SensitiveAssetService::class)->privatize($proof);
        $proof->update(['is_protected' => true, 'retain_until' => null]);

        return $digest;
    }

    private function reference(string $reference): string
    {
        $reference = mb_strtoupper(trim($reference));
        abort_unless(strlen($reference) > 0 && strlen($reference) <= 150, 422, 'Referensi kas wajib diisi.');

        return $reference;
    }

    private function recordProof(User $actor, Asset $proof, string $digest, string $reference, LedgerJournal $journal): void
    {
        $this->ledger->lockOperation('cash-proof:'.$digest);
        $this->ledger->lockOperation('cash-reference:'.$reference);
        abort_if(DB::table('ledger_cash_events')->where('namespace', 'outgoing')->where(function ($query) use ($digest, $reference): void {
            $query->where('proof_digest', $digest)->orWhere('bank_reference', $reference);
        })->exists(), 409, 'Bukti atau referensi kas keluar sudah digunakan.');
        DB::table('ledger_cash_events')->insert(['id' => (string) Str::uuid(), 'namespace' => 'outgoing', 'bank_reference' => $reference, 'proof_asset_id' => $proof->id, 'proof_digest' => $digest, 'journal_id' => $journal->id, 'actor_id' => $actor->id, 'created_at' => now()]);
    }
}
