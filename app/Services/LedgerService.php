<?php

namespace App\Services;

use App\Models\Community;
use App\Models\IncomingReceipt;
use App\Models\LedgerAccount;
use App\Models\LedgerJournal;
use App\Models\LedgerReservation;
use App\Models\MemberIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Integer IDR double-entry ledger. Account balances are a reconciled cache of immutable postings. */
class LedgerService
{
    public const MAX_SAFE_INTEGER = 9007199254740991;

    public function walletAccount(MemberIdentity $identity, string $bucket = 'available'): LedgerAccount
    {
        abort_unless(in_array($bucket, ['available', 'reserved', 'held'], true), 422, 'Jenis saldo tidak valid.');
        abort_if($identity->merged_into_id !== null, 409, 'Gunakan identitas anggota utama.');

        return $this->account('identity:'.$identity->id.':'.$bucket, 'identity', $bucket, $identity->id);
    }

    public function communityAccount(Community $community): LedgerAccount
    {
        return $this->account('community:'.$community->id.':available', 'community', 'available', null, $community->id);
    }

    public function platformAccount(): LedgerAccount
    {
        return $this->account('platform:revenue', 'platform', 'revenue');
    }

    public function cashAccount(string $custodian): LedgerAccount
    {
        return $this->account('custody:'.$custodian, 'custody', 'cash', null, null, true);
    }

    public function clearingAccount(string $source, string $handler, string $bucket): LedgerAccount
    {
        abort_unless(in_array($bucket, ['payable', 'receivable'], true), 422);

        return $this->account('clearing:'.$source.':'.$handler.':'.$bucket, 'clearing', $bucket, null, null, $bucket === 'receivable');
    }

    /** @return array{identity_id:int,currency:string,available:int,reserved:int,held:int,total:int} */
    public function wallet(User $user): array
    {
        $identity = MemberIdentity::forUser($user);
        $balances = [];
        foreach (['available', 'reserved', 'held'] as $bucket) {
            $this->walletAccount($identity, $bucket);
        }

        $balances = LedgerAccount::query()->where('member_identity_id', $identity->id)->where('kind', 'identity')->whereIn('bucket', ['available', 'reserved', 'held'])->pluck('balance', 'bucket')->all();

        return ['identity_id' => $identity->id, 'currency' => 'IDR', ...$balances, 'total' => array_sum($balances)];
    }

    /** @param array<string,mixed> $metadata */
    public function transfer(User $actor, string $key, string $operation, LedgerAccount $from, LedgerAccount $to, int $amount, ?Community $community = null, array $metadata = []): LedgerJournal
    {
        $this->assertAmount($amount);
        abort_if($from->id === $to->id || $from->allow_negative || $to->allow_negative, 422, 'Rekening sumber atau tujuan tidak valid.');

        return DB::transaction(function () use ($actor, $key, $operation, $from, $to, $amount, $community, $metadata): LedgerJournal {
            return $this->post($actor, $key, $operation, [
                ['account_id' => $from->id, 'amount' => -$amount],
                ['account_id' => $to->id, 'amount' => $amount],
            ], $community, $metadata, function () use ($from, $to, $amount): void {
                $this->moveLots($from->id, $to->id, $amount);
            });
        }, 3);
    }

    /**
     * Trusted domain API only; never accepts postings/account IDs from an HTTP request.
     * Callback records lot ownership in the same transaction and is skipped on idempotent replay.
     *
     * @param  list<array{account_id:int,amount:int}>  $postings
     * @param  array<string,mixed>  $metadata
     */
    public function post(User $actor, string $key, string $operation, array $postings, ?Community $community = null, array $metadata = [], ?callable $afterPosting = null): LedgerJournal
    {
        abort_unless(strlen($key) >= 1 && strlen($key) <= 150 && strlen($operation) <= 100, 422, 'Kunci transaksi tidak valid.');
        $totals = [];
        foreach ($postings as $posting) {
            $totals[$posting['account_id']] = ($totals[$posting['account_id']] ?? 0) + $posting['amount'];
        }
        $totals = array_filter($totals, fn (int $amount): bool => $amount !== 0);
        ksort($totals);
        abort_unless(count($totals) >= 2 && array_sum($totals) === 0, 422, 'Jurnal harus seimbang.');
        foreach ($totals as $amount) {
            $this->assertAmount(abs($amount));
        }
        $hash = $this->payloadHash(['postings' => $totals, 'community_id' => $community?->id, 'metadata' => $metadata]);

        return DB::transaction(function () use ($actor, $key, $operation, $totals, $community, $metadata, $hash, $afterPosting): LedgerJournal {
            $this->lockOperation($actor->id.':'.$operation.':'.$key);
            $existing = LedgerJournal::query()->where('actor_id', $actor->id)->where('operation', $operation)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Kunci transaksi sudah dipakai untuk data berbeda.');

                return $existing;
            }
            $postingAccounts = LedgerAccount::query()->whereKey(array_keys($totals))->get();
            abort_unless($postingAccounts->count() === count($totals), 422, 'Rekening transaksi tidak ditemukan.');
            $identityIds = $postingAccounts->pluck('member_identity_id')->filter()->unique()->values()->all();
            sort($identityIds);
            foreach ($identityIds as $identityId) {
                foreach (['available', 'reserved', 'held'] as $bucket) {
                    $this->account('identity:'.$identityId.':'.$bucket, 'identity', $bucket, $identityId);
                }
            }
            // Lock all buckets for every affected identity, not just the changed
            // account. Concurrent topups and reservations share the same total ceiling.
            $accounts = LedgerAccount::query()->where(function ($query) use ($totals, $identityIds): void {
                $query->whereIn('id', array_keys($totals))->orWhereIn('member_identity_id', $identityIds);
            })->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($identityIds as $identityId) {
                $identityAccounts = $accounts->where('member_identity_id', $identityId);
                $nextTotal = (int) $identityAccounts->sum('balance');
                foreach ($identityAccounts as $identityAccount) {
                    $nextTotal += $totals[$identityAccount->id] ?? 0;
                }
                abort_if($nextTotal > self::MAX_SAFE_INTEGER, 422, 'Batas total saldo identitas terlampaui.');
            }
            foreach ($totals as $id => $amount) {
                $account = $accounts[$id];
                $next = $account->balance + $amount;
                if (! $account->allow_negative && $next < 0) {
                    throw ValidationException::withMessages(['amount' => ['Saldo tersedia tidak mencukupi. Silakan top up.']]);
                }
                abort_if(abs($next) > self::MAX_SAFE_INTEGER, 422, 'Batas saldo terlampaui.');
            }
            $journal = LedgerJournal::query()->create([
                'id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'operation' => $operation,
                'idempotency_key' => $key, 'payload_hash' => $hash, 'community_id' => $community?->id,
                'reference' => isset($metadata['reference']) ? (string) $metadata['reference'] : null,
                'metadata' => $metadata, 'created_at' => now(),
            ]);
            foreach ($totals as $id => $amount) {
                DB::table('ledger_entries')->insert(['journal_id' => $journal->id, 'account_id' => $id, 'amount' => $amount]);
                LedgerAccount::query()->whereKey($id)->increment('balance', $amount);
            }
            if ($afterPosting !== null) {
                $afterPosting($journal);
            }

            return $journal;
        }, 3);
    }

    /**
     * @param  list<array{account_id:int,amount:int,reference?:string}>  $allocations
     */
    public function creditReceipt(User $actor, IncomingReceipt $receipt, array $allocations, string $key): LedgerJournal
    {
        $amount = (int) $receipt->verified_amount;
        $this->assertAmount($amount);
        abort_unless(array_sum(array_column($allocations, 'amount')) === $amount, 422, 'Alokasi tidak sama dengan uang diterima.');
        $cash = $this->cashAccount($receipt->custodian_key);
        $postings = [['account_id' => $cash->id, 'amount' => -$amount]];
        foreach ($allocations as $allocation) {
            $this->assertAmount($allocation['amount']);
            $account = LedgerAccount::query()->findOrFail($allocation['account_id']);
            abort_if($account->allow_negative || ! in_array($account->kind, ['identity', 'community', 'platform'], true), 422, 'Tujuan penerimaan tidak sah.');
            $postings[] = ['account_id' => $account->id, 'amount' => $allocation['amount']];
        }

        return $this->post($actor, $key, 'incoming_receipt', $postings, $receipt->community_id === null ? null : Community::query()->findOrFail($receipt->community_id), [
            'reference' => $receipt->id, 'receipt_id' => $receipt->id, 'purpose' => $receipt->purpose,
            'verified_amount' => $amount, 'allocations' => $allocations,
        ], function () use ($receipt, $allocations): void {
            foreach ($allocations as $allocation) {
                $this->addLot($receipt->id, $allocation['account_id'], $allocation['amount']);
            }
        });
    }

    public function reserve(User $actor, MemberIdentity $identity, int $amount, string $bucket, string $reference, string $key, ?Community $community = null): LedgerReservation
    {
        abort_unless(in_array($bucket, ['reserved', 'held'], true), 422);
        $this->assertAmount($amount);

        return DB::transaction(function () use ($actor, $identity, $amount, $bucket, $reference, $key, $community): LedgerReservation {
            $this->lockOperation('reservation:'.$reference);
            $existing = LedgerReservation::query()->where('source_reference', $reference)->first();
            $available = $this->walletAccount($identity);
            $reserved = $this->walletAccount($identity, $bucket);
            if ($existing !== null) {
                abort_unless($existing->actor_id === $actor->id && $existing->available_account_id === $available->id && $existing->reserved_account_id === $reserved->id && $existing->amount === $amount && $existing->community_id === $community?->id, 409, 'Referensi cadangan sudah dipakai untuk data berbeda.');

                return $existing;
            }
            $reservation = LedgerReservation::query()->create([
                'actor_id' => $actor->id, 'source_reference' => $reference, 'available_account_id' => $available->id,
                'reserved_account_id' => $reserved->id, 'community_id' => $community?->id,
                'amount' => $amount, 'remaining' => $amount, 'status' => 'reserved',
            ]);
            $this->transfer($actor, $key, 'reserve_'.$bucket, $available, $reserved, $amount, $community, ['reference' => $reference, 'reservation_id' => $reservation->id]);

            return $reservation;
        }, 3);
    }

    /** Releases a failed technical reservation; not a refund of a completed purchase. */
    public function release(User $actor, LedgerReservation $reservation, string $key, string $reason): LedgerJournal
    {
        return $this->finishReservation($actor, $reservation, LedgerAccount::query()->findOrFail($reservation->available_account_id), $reservation->remaining, $key, 'release', $reason);
    }

    public function finalize(User $actor, LedgerReservation $reservation, LedgerAccount $recipient, int $amount, string $key, string $reason): LedgerJournal
    {
        return $this->finishReservation($actor, $reservation, $recipient, $amount, $key, 'finalize', $reason);
    }

    private function finishReservation(User $actor, LedgerReservation $reservation, LedgerAccount $recipient, int $amount, string $key, string $operation, string $reason): LedgerJournal
    {
        return DB::transaction(function () use ($actor, $reservation, $recipient, $amount, $key, $operation, $reason): LedgerJournal {
            $this->lockOperation($actor->id.':'.$operation.':'.$key);
            $reservation = LedgerReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $previous = LedgerJournal::query()->where('actor_id', $actor->id)->where('operation', $operation)->where('idempotency_key', $key)->first();
            if ($previous !== null) {
                abort_unless(($previous->metadata['reservation_id'] ?? null) === $reservation->id && ($previous->metadata['recipient_id'] ?? null) === $recipient->id && ($previous->metadata['reason'] ?? null) === $reason && ($operation === 'release' || ($previous->metadata['amount'] ?? null) === $amount), 409, 'Kunci transaksi sudah dipakai untuk data berbeda.');

                return $previous;
            }
            abort_unless($reservation->actor_id === $actor->id, 403);
            if ($operation === 'release') {
                $amount = $reservation->remaining;
            }
            $this->assertAmount($amount);
            abort_if($amount > $reservation->remaining, 409, 'Cadangan sudah terpakai.');
            abort_if(trim($reason) === '', 422, 'Alasan wajib diisi.');
            $journal = $this->transfer($actor, $key, $operation, LedgerAccount::query()->findOrFail($reservation->reserved_account_id), $recipient, $amount,
                $reservation->community_id === null ? null : Community::query()->findOrFail($reservation->community_id),
                ['reference' => $reservation->source_reference, 'reservation_id' => $reservation->id, 'recipient_id' => $recipient->id, 'amount' => $amount, 'reason' => $reason]);
            $reservation->forceFill(['remaining' => $reservation->remaining - $amount, 'status' => $reservation->remaining === $amount ? ($operation === 'release' ? 'released' : 'finalized') : 'reserved'])->save();

            return $journal;
        }, 3);
    }

    /** @return array{balanced:bool,account_errors:list<int>,lot_errors:list<int>,unbalanced_journals:list<string>,custody_errors:list<string>,total_postings:int} */
    public function reconcile(): array
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(function (): array {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                }

                return $this->reconcile();
            });
        }
        $accountErrors = [];
        $lotErrors = [];
        foreach (LedgerAccount::query()->get() as $account) {
            $sum = (int) DB::table('ledger_entries')->where('account_id', $account->id)->sum('amount');
            if ($sum !== $account->balance || (! $account->allow_negative && $sum < 0)) {
                $accountErrors[] = $account->id;
            }
            if (in_array($account->kind, ['identity', 'community', 'platform'], true) && (int) DB::table('ledger_lot_holdings')->where('account_id', $account->id)->sum('amount') !== $account->balance) {
                $lotErrors[] = $account->id;
            }
        }
        $unbalanced = DB::table('ledger_entries')->select('journal_id')->groupBy('journal_id')->havingRaw('sum(amount) <> 0 OR count(*) < 2')->pluck('journal_id')->all();

        $custodyErrors = [];
        foreach (LedgerAccount::query()->where('kind', 'custody')->get() as $cash) {
            $custodian = substr($cash->account_key, strlen('custody:'));
            $holdings = (int) DB::table('ledger_lot_holdings')->join('incoming_receipts', 'incoming_receipts.id', '=', 'ledger_lot_holdings.receipt_id')->where('custodian_key', $custodian)->sum('ledger_lot_holdings.amount');
            $payables = (int) DB::table('ledger_clearings')->where('source_custodian', $custodian)->where('status', 'open')->sum('amount');
            $receivables = (int) DB::table('ledger_clearings')->where('handler_custodian', $custodian)->where('status', 'open')->sum('amount');
            if ($cash->balance + $holdings + $payables - $receivables !== 0) {
                $custodyErrors[] = $custodian;
            }
        }

        return ['balanced' => $accountErrors === [] && $lotErrors === [] && $unbalanced === [] && $custodyErrors === [], 'custody_errors' => $custodyErrors, 'account_errors' => $accountErrors, 'lot_errors' => $lotErrors, 'unbalanced_journals' => $unbalanced, 'total_postings' => (int) DB::table('ledger_entries')->sum('amount')];
    }

    /** Consolidate available money when a manual identity is claimed; preserve original journals. */
    public function mergeIdentity(MemberIdentity $source, MemberIdentity $target, User $actor): void
    {
        if ($source->id === $target->id) {
            return;
        }
        DB::transaction(function () use ($source, $target, $actor): void {
            $this->lockOperation('identity-money-merge:'.$source->id.':'.$target->id);
            $accounts = LedgerAccount::query()->whereIn('member_identity_id', [$source->id, $target->id])->orderBy('id')->lockForUpdate()->get();
            $sourceAccounts = $accounts->where('member_identity_id', $source->id);
            abort_if($sourceAccounts->whereIn('bucket', ['reserved', 'held'])->sum('balance') > 0 || LedgerReservation::query()->whereIn('available_account_id', $sourceAccounts->pluck('id'))->where('remaining', '>', 0)->exists(), 409, 'Selesaikan dana terikat identitas ini sebelum menghubungkan akun.');
            foreach ($sourceAccounts->where('bucket', 'available') as $account) {
                if ($account->balance > 0) {
                    $this->transfer($actor, 'identity-merge:'.$source->id.':'.$target->id, 'identity_merge', $account, $this->walletAccount($target), $account->balance, null, ['reference' => 'identity:'.$source->id, 'source_identity_id' => $source->id, 'target_identity_id' => $target->id]);
                }
            }
        }, 3);
    }

    /**
     * Trusted technical repair only, never exposed as a purchase refund endpoint.
     * A super-admin attests the incident; original final business payments, reserves
     * and incoming cash cannot be reversed independently of their source documents.
     */
    public function correctTransfer(User $actor, LedgerJournal $original, string $key, string $reason): LedgerJournal
    {
        abort_unless($actor->hasRole('super-admin'), 403);
        abort_if(trim($reason) === '', 422, 'Alasan koreksi teknis wajib diisi.');
        abort_unless(str_starts_with($original->operation, 'technical_') && $original->operation !== 'technical_correction', 422, 'Koreksi ini hanya untuk transfer teknis, bukan pembayaran final.');

        return DB::transaction(function () use ($actor, $original, $key, $reason): LedgerJournal {
            $this->lockOperation('correction:'.$original->id);
            $previous = LedgerJournal::query()->where('operation', 'technical_correction')->where('reference', $original->id)->first();
            if ($previous !== null) {
                abort_unless($previous->actor_id === $actor->id && $previous->idempotency_key === $key && ($previous->metadata['reason'] ?? null) === $reason, 409, 'Transfer teknis sudah dikoreksi.');

                return $previous;
            }
            $entries = DB::table('ledger_entries')->where('journal_id', $original->id)->get();
            abort_unless($entries->count() === 2, 422, 'Jurnal sumber bukan transfer teknis sederhana.');
            $debit = $entries->first(fn (object $entry): bool => $entry->amount < 0);
            $credit = $entries->first(fn (object $entry): bool => $entry->amount > 0);
            abort_unless($debit !== null && $credit !== null && -$debit->amount === $credit->amount, 422, 'Transfer sumber tidak seimbang.');
            $from = LedgerAccount::query()->findOrFail($credit->account_id);
            $to = LedgerAccount::query()->findOrFail($debit->account_id);
            abort_unless($from->bucket === 'available' && $to->bucket === 'available' && ! $from->allow_negative && ! $to->allow_negative, 422, 'Koreksi tidak boleh mencetak kas atau melepaskan dana terikat.');

            return $this->transfer($actor, $key, 'technical_correction', $from, $to, (int) $credit->amount, $original->community_id === null ? null : Community::query()->findOrFail($original->community_id), ['reference' => $original->id, 'original_journal_id' => $original->id, 'reason' => $reason]);
        }, 3);
    }

    public function assertAmount(int $amount): void
    {
        if ($amount < 1 || $amount > min(self::MAX_SAFE_INTEGER, (int) config('finance.max_transaction', 1000000000))) {
            throw ValidationException::withMessages(['amount' => ['Nominal rupiah di luar batas transaksi.']]);
        }
    }

    public function lockOperation(string $key): void
    {
        abort_unless(DB::getDriverName() === 'pgsql', 503, 'Layanan keuangan memerlukan PostgreSQL. Transaksi belum diproses.');
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }

    /** @param array<mixed> $payload */
    public function payloadHash(array $payload): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $normalize($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($normalize($payload), JSON_THROW_ON_ERROR));
    }

    private function account(string $key, string $kind, string $bucket, ?int $identityId = null, ?int $communityId = null, bool $negative = false): LedgerAccount
    {
        return LedgerAccount::query()->firstOrCreate(['account_key' => $key], ['kind' => $kind, 'bucket' => $bucket, 'member_identity_id' => $identityId, 'community_id' => $communityId, 'balance' => 0, 'allow_negative' => $negative]);
    }

    private function moveLots(int $from, int $to, int $amount): void
    {
        $remaining = $amount;
        $lots = DB::table('ledger_lot_holdings')->where('account_id', $from)->where('amount', '>', 0)->orderBy('id')->lockForUpdate()->get();
        foreach ($lots as $lot) {
            $part = min($remaining, (int) $lot->amount);
            DB::table('ledger_lot_holdings')->where('id', $lot->id)->decrement('amount', $part);
            $this->addLot($lot->receipt_id, $to, $part);
            $remaining -= $part;
            if ($remaining === 0) {
                break;
            }
        }
        abort_unless($remaining === 0, 409, 'Sumber dana tidak dapat direkonsiliasi.');
    }

    private function addLot(string $receiptId, int $accountId, int $amount): void
    {
        DB::table('ledger_lot_holdings')->insertOrIgnore(['receipt_id' => $receiptId, 'account_id' => $accountId, 'amount' => 0]);
        DB::table('ledger_lot_holdings')->where('receipt_id', $receiptId)->where('account_id', $accountId)->increment('amount', $amount);
    }
}
