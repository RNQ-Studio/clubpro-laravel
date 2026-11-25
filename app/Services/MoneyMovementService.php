<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\LedgerAccount;
use App\Models\LedgerReservation;
use App\Models\MemberIdentity;
use App\Models\MemberTransfer;
use App\Models\Payout;
use App\Models\PayoutEvent;
use App\Models\PayoutFragment;
use App\Models\PayoutPart;
use App\Models\PrimaryBankAccount;
use App\Models\User;
use App\Support\Enums\CommunityStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** S05 consumer of the single identity wallet and original custody lots. */
class MoneyMovementService
{
    public function __construct(private readonly LedgerService $ledger, private readonly BillingService $billing, private readonly CustodyService $custody, private readonly IncomingReceiptService $receipts, private readonly SensitiveAssetService $privateAssets, private readonly ImageAssetGuard $images) {}

    /** @param array<string,mixed> $data */
    public function savePrimary(User $actor, #[\SensitiveParameter] array $data): PrimaryBankAccount
    {
        return DB::transaction(function () use ($actor, $data): PrimaryBankAccount {
            $this->ledger->lockOperation('primary-bank:'.$actor->id);
            $account = PrimaryBankAccount::query()->where('user_id', $actor->id)->lockForUpdate()->first();
            abort_unless(($account->version ?? 0) === $data['expected_version'], 409, 'Rekening telah berubah. Muat ulang.');
            abort_unless(($data['acknowledged'] ?? false) === true, 422, 'Konfirmasi tanggung jawab data rekening.');
            $details = array_intersect_key($data, array_flip(['bank_code', 'bank_name', 'account_number', 'account_name']));
            abort_unless(preg_match('/^[0-9]{5,34}$/', $details['account_number']) === 1, 422, 'Nomor rekening harus 5–34 digit.');
            $account ??= new PrimaryBankAccount(['user_id' => $actor->id]);
            $account->fill(['details' => $details, 'version' => ($account->version ?? 0) + 1])->save();
            activity('finance')->causedBy($actor)->performedOn($actor)->event('primary_bank_updated')->withProperties(['version' => $account->version, 'masked_number' => $this->mask($details['account_number'])])->log('Rekening utama diperbarui.');

            return $account;
        }, 3);
    }

    /** @return array<string,mixed> */
    public function primaryData(User $actor): array
    {
        $account = PrimaryBankAccount::query()->where('user_id', $actor->id)->first();

        return ['account' => $account === null ? null : ['id' => $account->id, ...$account->details, 'masked_number' => $this->mask((string) $account->details['account_number']), 'is_primary' => true, 'version' => $account->version], 'responsibility_notice' => 'Rekening boleh atas nama pihak lain. Kebenaran tujuan dan data rekening menjadi tanggung jawab anggota.'];
    }

    public function mask(string $number): string
    {
        return str_repeat('*', max(1, strlen($number) - 4)).substr($number, -4);
    }

    public function requireMember(User $actor, Community $community, bool $active = false): CommunityMember
    {
        $member = $community->membershipOf($actor);
        abort_unless($actor->is_active && $member !== null && $member->merged_into_member_id === null && $member->member_identity_id !== null, 403, 'Keanggotaan komunitas tidak ditemukan.');
        if ($active) {
            abort_unless($community->status === CommunityStatus::Approved && $member->status->value === 'active' && $this->billing->entitlement($this->billing->platformSubject($community))['active'] && $this->billing->entitlement($this->billing->memberSubject($member))['active'], 402, 'Keanggotaan atau komunitas tidak aktif. Buka pembayaran.');
        }

        return $member;
    }

    public function canManage(User $actor, PayoutPart $part): bool
    {
        if ($part->community_id === null) {
            return $actor->hasRole('super-admin');
        }
        $community = Community::query()->find($part->community_id);
        $member = $community?->membershipOf($actor);

        return $actor->is_active && $member !== null && $member->status->value === 'active' && $member->merged_into_member_id === null && in_array($member->role->value, ['creator', 'admin'], true);
    }

    public function assertManage(User $actor, PayoutPart $part): void
    {
        Gate::forUser($actor)->authorize('manage', $part);
        abort_unless($this->canManage($actor, $part), 403, 'Hanya pendiri/admin penanggung jawab sumber dana dapat memproses pencairan.');
    }

    public function verifyPin(User $actor, #[\SensitiveParameter] string $pin): void
    {
        $key = 'money-pin:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Terlalu banyak percobaan PIN. Coba kembali 15 menit lagi.');
        if (! $actor->is_active || $actor->pin_hash === null || ! Hash::check($pin, $actor->pin_hash)) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['pin' => ['PIN transaksi tidak sesuai atau belum diatur.']]);
        }
        RateLimiter::clear($key);
    }

    /** @param array<string,mixed> $data */
    public function transfer(User $actor, #[\SensitiveParameter] array $data): MemberTransfer
    {
        $pin = (string) $data['pin'];
        unset($data['pin']);
        $this->assertIntegerAmount($data['amount']);
        $key = (string) $data['idempotency_key'];
        $hash = $this->ledger->payloadHash($data);

        return DB::transaction(function () use ($actor, $data, $key, $hash, $pin): MemberTransfer {
            $this->ledger->lockOperation('member-transfer:'.$actor->id.':'.$key);
            $existing = MemberTransfer::query()->where('user_id', $actor->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $this->samePayload($existing->payload_hash, $hash);

                return $existing;
            }
            $this->verifyPin($actor, $pin);
            $community = Community::query()->findOrFail($data['community_id']);
            $this->requireMember($actor, $community, true);
            $sender = MemberIdentity::forUser($actor);
            $recipient = MemberIdentity::query()->with('user')->whereNull('merged_into_id')->findOrFail($data['recipient_identity_id']);
            abort_if($recipient->id === $sender->id || $recipient->user === null, 422, 'Pilih anggota penerima lain dengan akun aktif.');
            $recipientCommunity = Community::query()->findOrFail($data['recipient_community_id']);
            $this->requireMember($recipient->user, $recipientCommunity, true);
            abort_unless($recipient->is_public || $recipientCommunity->id === $community->id, 403, 'Penerima lintas komunitas tidak tersedia dalam direktori publik.');
            $transfer = MemberTransfer::query()->create(['user_id' => $actor->id, 'sender_identity_id' => $sender->id, 'recipient_identity_id' => $recipient->id, 'community_id' => $community->id, 'recipient_community_id' => $recipientCommunity->id, 'amount' => $data['amount'], 'idempotency_key' => $key, 'payload_hash' => $hash, 'snapshot' => ['sender' => ['identity_id' => $sender->id, 'name' => $sender->name], 'recipient' => ['identity_id' => $recipient->id, 'name' => $recipient->name], 'recipient_community_name' => $recipientCommunity->name]]);
            $journal = $this->ledger->transfer($actor, 'transfer:'.$transfer->id, 'member_transfer', $this->ledger->walletAccount($sender), $this->ledger->walletAccount($recipient), $data['amount'], $community, ['reference' => $transfer->id]);
            $transfer->update(['journal_id' => $journal->id]);

            return $transfer;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function createPayout(User $actor, #[\SensitiveParameter] array $data): Payout
    {
        $pin = (string) $data['pin'];
        unset($data['pin']);
        $this->assertIntegerAmount($data['amount']);
        $key = (string) $data['idempotency_key'];
        $hash = $this->ledger->payloadHash($data);

        return DB::transaction(function () use ($actor, $data, $key, $hash, $pin): Payout {
            $this->ledger->lockOperation('payout-create:'.$actor->id.':'.$key);
            $existing = Payout::query()->where('user_id', $actor->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $this->samePayload($existing->payload_hash, $hash);

                return $existing;
            }
            $this->verifyPin($actor, $pin);
            $community = Community::query()->findOrFail($data['community_id']);
            $this->requireMember($actor, $community); // Expired users retain access to their own funds.
            $bank = PrimaryBankAccount::query()->where('user_id', $actor->id)->lockForUpdate()->first();
            abort_unless($bank !== null && $bank->version === $data['bank_account_version'] && ($data['acknowledged'] ?? false) === true, 409, 'Rekening berubah atau belum dikonfirmasi. Muat ulang tujuan.');
            $identity = MemberIdentity::forUser($actor);
            $available = $this->ledger->walletAccount($identity);
            $this->ledger->walletAccount($identity, 'reserved');
            $this->ledger->walletAccount($identity, 'held');
            LedgerAccount::query()->where('member_identity_id', $identity->id)->orderBy('id')->lockForUpdate()->get();
            $sources = [];
            $remaining = $data['amount'];
            foreach (DB::table('ledger_lot_holdings')->join('incoming_receipts', 'incoming_receipts.id', '=', 'ledger_lot_holdings.receipt_id')->where('account_id', $available->id)->where('amount', '>', 0)->orderBy('ledger_lot_holdings.id')->get(['ledger_lot_holdings.receipt_id', 'ledger_lot_holdings.amount', 'incoming_receipts.custodian_key', 'incoming_receipts.community_id']) as $lot) {
                $amount = min($remaining, (int) $lot->amount);
                $source = $lot->custodian_key;
                $sources[$source] ??= ['community_id' => str_starts_with($source, 'platform') ? null : $lot->community_id, 'amount' => 0, 'allocations' => []];
                $sources[$source]['amount'] += $amount;
                $sources[$source]['allocations'][] = ['receipt_id' => $lot->receipt_id, 'amount' => $amount, 'remaining' => $amount];
                $remaining -= $amount;
                if ($remaining === 0) {
                    break;
                }
            }
            abort_unless($remaining === 0, 422, 'Saldo tersedia tidak cukup. Dana paket dan pencairan lain tidak dapat digunakan.');
            $payout = Payout::query()->create(['user_id' => $actor->id, 'member_identity_id' => $identity->id, 'community_id' => $community->id, 'bank_snapshot' => $bank->details, 'member_name' => $identity->name, 'amount' => $data['amount'], 'verified_amount' => 0, 'status' => 'requested', 'version' => 1, 'idempotency_key' => $key, 'payload_hash' => $hash]);
            $reservation = $this->ledger->reserve($actor, $identity, $data['amount'], 'held', 'payout:'.$payout->id, 'payout:'.$payout->id, $community);
            foreach ($sources as $custodian => $source) {
                $part = PayoutPart::query()->create(['payout_id' => $payout->id, 'community_id' => $source['community_id'], 'custodian_key' => $custodian, 'amount' => $source['amount'], 'verified_amount' => 0, 'status' => 'requested', 'version' => 1, 'reservation_id' => $reservation->id, 'allocations' => $source['allocations']]);
                $this->event($actor, $part, 'requested', 'Permintaan dibuat; dana ditahan.', 'create:'.$part->id, $key, $hash);
            }

            return $payout;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function transition(User $actor, PayoutPart $part, string $action, array $data): Payout
    {
        return DB::transaction(function () use ($actor, $part, $action, $data): Payout {
            $payout = Payout::query()->lockForUpdate()->findOrFail($part->payout_id);
            $part = PayoutPart::query()->lockForUpdate()->findOrFail($part->id);
            $ownerCancel = $action === 'cancel' && $actor->id === $payout->user_id;
            if (! $ownerCancel) {
                $this->assertManage($actor, $part);
            }
            $hash = $this->ledger->payloadHash($data);
            $key = $data['idempotency_key'];
            if ($this->replay($actor, $action, $key, $hash, $part)) {
                return $payout;
            }
            $this->version($part, $data);
            abort_if($ownerCancel && $part->status !== 'requested', 403, 'Permintaan sudah diproses oleh petugas.');
            abort_unless(in_array($part->status, ['requested', 'processing', 'uncertain'], true), 409, 'Pencairan sudah final.');
            $status = match ($action) {
                'process' => 'processing', 'uncertain' => 'uncertain', 'fail' => 'failed', 'cancel' => 'cancelled', default => abort(422)
            };
            if (in_array($action, ['fail', 'cancel'], true)) {
                abort_unless(($data['confirmed_unsent'] ?? false) === true && $part->verified_amount === 0 && ! PayoutFragment::query()->where('payout_part_id', $part->id)->where('status', 'verified')->exists(), 409, 'Bukti atau pembayaran sudah tercatat. Rekonsiliasi hasil terlebih dahulu; dana tidak dilepas.');
                PayoutFragment::query()->where('payout_part_id', $part->id)->where('status', 'pending')->get()->each(fn (PayoutFragment $fragment) => $fragment->update(['status' => 'rejected']));
                $this->custody->releasePart($actor, $part, 'release:'.$part->id, $data['note']);
            } elseif ($action === 'process') {
                abort_unless($part->status === 'requested', 409, 'Permintaan sudah diproses. Jangan mengulangi transfer bank.');
            }
            $part->update(['status' => $status, 'version' => $part->version + 1]);
            $this->event($actor, $part, $status, $data['note'], $action, $key, $hash);

            return $this->refreshParent($payout);
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function submitProof(User $actor, PayoutPart $part, #[\SensitiveParameter] array $data): Payout
    {
        $this->assertManage($actor, $part);
        $this->assertIntegerAmount($data['amount']);
        $key = $data['idempotency_key'];
        $hash = $this->ledger->payloadHash($data);
        $existing = PayoutFragment::query()->where('submitted_by', $actor->id)->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            $this->samePayload($existing->payload_hash, $hash);
            abort_unless($existing->payout_part_id === $part->id, 409);

            return Payout::query()->findOrFail($part->payout_id);
        }
        $this->images->assertUsable($actor, [$data['proof_asset_id']], 'proof_asset_id');
        $proof = Asset::query()->findOrFail($data['proof_asset_id']);
        $digest = $this->receipts->proofDigest($proof);
        $this->privateAssets->privatize($proof);
        $proof->update(['is_protected' => true, 'retain_until' => null]);

        return DB::transaction(function () use ($actor, $part, $data, $hash, $key, $digest): Payout {
            $this->ledger->lockOperation('payout-proof:'.$actor->id.':'.$key);
            $payout = Payout::query()->lockForUpdate()->findOrFail($part->payout_id);
            $part = PayoutPart::query()->lockForUpdate()->findOrFail($part->id);
            $this->assertManage($actor, $part);
            $existing = PayoutFragment::query()->where('submitted_by', $actor->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $this->samePayload($existing->payload_hash, $hash);

                return $payout;
            }
            $this->version($part, $data);
            abort_unless(in_array($part->status, ['processing', 'uncertain'], true), 409, 'Mulai proses pencairan sebelum mencatat bukti.');
            $bank = $payout->bank_snapshot;
            abort_unless(hash_equals((string) $bank['account_number'], $data['account_number']) && mb_strtolower(trim($bank['account_name'])) === mb_strtolower(trim($data['account_name'])), 422, 'Tujuan transfer tidak sesuai snapshot permintaan.');
            $pending = (int) PayoutFragment::query()->where('payout_part_id', $part->id)->where('status', 'pending')->sum('amount');
            abort_if($part->verified_amount + $pending + $data['amount'] > $part->amount, 422, 'Nominal melebihi sisa permintaan.');
            $reference = mb_strtoupper(trim($data['bank_reference']));
            $this->ledger->lockOperation('cash-proof:'.$digest);
            $this->ledger->lockOperation('cash-reference:'.$reference);
            abort_if(PayoutFragment::query()->where('proof_digest', $digest)->orWhere('reference_digest', hash('sha256', $reference))->exists() || DB::table('ledger_cash_events')->where('namespace', 'outgoing')->where(fn ($q) => $q->where('proof_digest', $digest)->orWhere('bank_reference', $reference))->exists(), 409, 'Bukti atau referensi kas keluar sudah digunakan.');
            PayoutFragment::query()->create(['payout_part_id' => $part->id, 'proof_asset_id' => $data['proof_asset_id'], 'proof_digest' => $digest, 'reference_digest' => hash('sha256', $reference), 'bank_reference' => $reference, 'amount' => $data['amount'], 'status' => 'pending', 'submitted_by' => $actor->id, 'note' => $data['note'], 'idempotency_key' => $key, 'payload_hash' => $hash]);
            $part->update(['status' => 'uncertain', 'version' => $part->version + 1]);
            $this->event($actor, $part, 'uncertain', 'Bukti dicatat; belum diverifikasi. '.$data['note'], 'proof', $key, $hash);

            return $this->refreshParent($payout);
        }, 3);
    }

    /** Correct manual input before verification; verified financial history is immutable.
     * @param  array<string,mixed>  $data
     */
    public function correctFragment(User $actor, PayoutPart $part, PayoutFragment $fragment, #[\SensitiveParameter] array $data): Payout
    {
        $this->assertIntegerAmount($data['amount']);

        return DB::transaction(function () use ($actor, $part, $fragment, $data): Payout {
            $payout = Payout::query()->lockForUpdate()->findOrFail($part->payout_id);
            $part = PayoutPart::query()->lockForUpdate()->findOrFail($part->id);
            $this->assertManage($actor, $part);
            $fragment = PayoutFragment::query()->where('payout_part_id', $part->id)->lockForUpdate()->findOrFail($fragment->id);
            $hash = $this->ledger->payloadHash([...$data, 'fragment_id' => $fragment->id]);
            $key = $data['idempotency_key'];
            if ($this->replay($actor, 'correct', $key, $hash, $part)) {
                return $payout;
            }
            $this->version($part, $data);
            abort_unless($fragment->status === 'pending' && $part->status === 'uncertain', 409, 'Hanya input bukti yang belum diverifikasi dapat diperbaiki.');
            $bank = $payout->bank_snapshot;
            abort_unless(hash_equals($bank['account_number'], $data['account_number']) && mb_strtolower(trim($bank['account_name'])) === mb_strtolower(trim($data['account_name'])), 422, 'Tujuan transfer tidak sesuai snapshot.');
            $otherPending = (int) PayoutFragment::query()->where('payout_part_id', $part->id)->where('status', 'pending')->where('id', '<>', $fragment->id)->sum('amount');
            abort_if($part->verified_amount + $otherPending + $data['amount'] > $part->amount, 422, 'Nominal melebihi sisa.');
            $reference = mb_strtoupper(trim($data['bank_reference']));
            $referenceDigest = hash('sha256', $reference);
            $this->ledger->lockOperation('cash-reference:'.$reference);
            abort_if(PayoutFragment::query()->where('reference_digest', $referenceDigest)->where('id', '<>', $fragment->id)->exists() || DB::table('ledger_cash_events')->where('namespace', 'outgoing')->where('bank_reference', $reference)->exists(), 409, 'Referensi kas keluar sudah dipakai.');
            $before = $fragment->amount;
            $fragment->update(['amount' => $data['amount'], 'bank_reference' => $reference, 'reference_digest' => $referenceDigest, 'note' => $data['note']]);
            $part->update(['version' => $part->version + 1]);
            $this->event($actor, $part, 'uncertain', 'Koreksi input nominal '.$before.' menjadi '.$data['amount'].'. '.$data['note'], 'correct', $key, $hash);

            return $this->refreshParent($payout);
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function verifyFragment(User $actor, PayoutPart $part, PayoutFragment $fragment, array $data): Payout
    {
        return DB::transaction(function () use ($actor, $part, $fragment, $data): Payout {
            $payout = Payout::query()->lockForUpdate()->findOrFail($part->payout_id);
            $part = PayoutPart::query()->lockForUpdate()->findOrFail($part->id);
            $this->assertManage($actor, $part);
            $fragment = PayoutFragment::query()->where('payout_part_id', $part->id)->lockForUpdate()->findOrFail($fragment->id);
            $hash = $this->ledger->payloadHash([...$data, 'fragment_id' => $fragment->id]);
            $key = $data['idempotency_key'];
            if ($this->replay($actor, 'verify', $key, $hash, $part)) {
                return $payout;
            }
            $this->version($part, $data);
            abort_unless($fragment->status === 'pending' && in_array($part->status, ['processing', 'uncertain'], true), 409, 'Bukti sudah diverifikasi atau pencairan final.');
            abort_if($part->verified_amount + $fragment->amount > $part->amount, 422, 'Nominal melebihi sisa.');
            $journal = $this->custody->payPart($actor, $part, $fragment, 'fragment:'.$fragment->id);
            $fragment->update(['status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()->format('Y-m-d H:i:s.uP'), 'journal_id' => $journal->id]);
            $verified = $part->verified_amount + $fragment->amount;
            $part->update(['verified_amount' => $verified, 'status' => $verified === $part->amount ? 'succeeded' : 'uncertain', 'version' => $part->version + 1]);
            $this->event($actor, $part, $part->status, 'Nominal bukti diverifikasi manual tepat sekali.', 'verify', $key, $hash);

            return $this->refreshParent($payout);
        }, 3);
    }

    /** A consistent private reconciliation, including the actual ledger and shared reservations.
     * @return array<string,mixed>
     */
    public function reconciliation(?int $communityId): array
    {
        return DB::transaction(function () use ($communityId): array {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $parts = PayoutPart::query()->where('community_id', $communityId)->get();
            $amount = (int) $parts->sum('amount');
            $verified = (int) $parts->sum('verified_amount');
            $released = (int) $parts->whereIn('status', ['failed', 'cancelled'])->sum('amount');
            $held = (int) $parts->whereNotIn('status', ['failed', 'cancelled', 'succeeded'])->sum(fn (PayoutPart $part): int => $part->amount - $part->verified_amount);
            $errors = [];
            foreach ($parts as $part) {
                $expected = in_array($part->status, ['failed', 'cancelled'], true) ? 0 : $part->amount - $part->verified_amount;
                if (array_sum(array_column($part->allocations, 'remaining')) !== $expected || (int) PayoutFragment::query()->where('payout_part_id', $part->id)->where('status', 'verified')->sum('amount') !== $part->verified_amount) {
                    $errors[] = $part->id;
                }
                $reservation = LedgerReservation::query()->findOrFail($part->reservation_id);
                $siblings = PayoutPart::query()->where('reservation_id', $reservation->id)->get();
                if ((int) $siblings->sum(fn (PayoutPart $sibling): int => array_sum(array_column($sibling->allocations, 'remaining'))) !== $reservation->remaining) {
                    $errors[] = $part->id;
                }
                $heldAccount = LedgerAccount::query()->findOrFail($reservation->reserved_account_id);
                if ((int) LedgerReservation::query()->where('reserved_account_id', $heldAccount->id)->sum('remaining') !== $heldAccount->balance) {
                    $errors[] = $part->id;
                }
            }
            $ledger = $this->ledger->reconcile();

            return ['parts_amount' => $amount, 'verified_amount' => $verified, 'held_amount' => $held, 'released_amount' => $released, 'balanced' => $amount === $verified + $held + $released && $errors === [] && $ledger['balanced'], 'ledger_balanced' => $ledger['balanced'], 'source_errors' => array_values(array_unique($errors)), 'parts_count' => $parts->count()];
        });
    }

    public function activeMember(CommunityMember $member): bool
    {
        return $member->community->status === CommunityStatus::Approved && $member->status->value === 'active' && $this->billing->entitlement($this->billing->platformSubject($member->community))['active'] && $this->billing->entitlement($this->billing->memberSubject($member))['active'];
    }

    private function refreshParent(Payout $payout): Payout
    {
        $parts = PayoutPart::query()->where('payout_id', $payout->id)->get();
        $verified = (int) $parts->sum('verified_amount');
        $statuses = $parts->pluck('status');
        $status = $verified === $payout->amount ? 'succeeded' : ($statuses->contains('uncertain') || $verified > 0 ? 'uncertain' : ($statuses->contains('processing') ? 'processing' : ($statuses->every(fn ($s) => in_array($s, ['failed', 'cancelled'], true)) ? ($statuses->contains('failed') ? 'failed' : 'cancelled') : 'requested')));
        $payout->update(['verified_amount' => $verified, 'status' => $status, 'version' => $payout->version + 1]);

        return $payout;
    }

    /** @param array<string,mixed> $data */
    private function version(PayoutPart $part, array $data): void
    {
        abort_unless($part->version === $data['expected_version'], 409, 'Status berubah. Muat ulang sebelum melanjutkan.');
    }

    private function samePayload(string $before, string $after): void
    {
        abort_unless(hash_equals($before, $after), 409, 'Kunci transaksi sudah dipakai untuk data berbeda.');
    }

    private function replay(User $actor, string $operation, string $key, string $hash, PayoutPart $part): bool
    {
        $this->ledger->lockOperation('payout-event:'.$actor->id.':'.$operation.':'.$key);
        $event = PayoutEvent::query()->where('actor_id', $actor->id)->where('operation', $operation)->where('idempotency_key', $key)->first();
        if ($event === null) {
            return false;
        }
        $this->samePayload($event->payload_hash, $hash);
        abort_unless($event->payout_part_id === $part->id, 409);

        return true;
    }

    private function event(User $actor, PayoutPart $part, string $status, string $note, string $operation, string $key, string $hash): void
    {
        PayoutEvent::query()->create(['payout_part_id' => $part->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name, 'status' => $status, 'note' => $note, 'operation' => $operation, 'idempotency_key' => $key, 'payload_hash' => $hash, 'created_at' => now()->format('Y-m-d H:i:s.uP')]);
        activity('finance')->causedBy($actor)->performedOn(User::query()->findOrFail(Payout::query()->findOrFail($part->payout_id)->user_id))->event('payout_'.$operation)->withProperties(['payout_id' => $part->payout_id, 'part_id' => $part->id, 'status' => $status, 'version' => $part->version])->log('Status pencairan diperbarui.');
    }

    private function assertIntegerAmount(mixed $amount): void
    {
        abort_unless(is_int($amount), 422, 'Nominal harus rupiah bulat.');
        $this->ledger->assertAmount($amount);
    }
}
