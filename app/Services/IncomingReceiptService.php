<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\DuesPayment;
use App\Models\IncomingReceipt;
use App\Models\MemberIdentity;
use App\Models\User;
use App\Support\Enums\DuesPaymentStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** One global incoming proof registry prevents reusing the same money for topup and invoice payment. */
class IncomingReceiptService
{
    public function __construct(private readonly LedgerService $ledger, private readonly ImageAssetGuard $images, private readonly SensitiveAssetService $privateAssets) {}

    /** @param array<string,mixed> $data */
    public function submit(User $payer, ?Community $community, array $data): IncomingReceipt
    {
        $purpose = (string) ($data['purpose'] ?? 'topup');
        abort_unless(in_array($purpose, ['topup', 'member', 'platform'], true), 422, 'Jenis pembayaran tidak valid.');
        abort_unless(is_int($data['claimed_amount'] ?? null), 422, 'Nominal harus rupiah bulat.');
        $this->ledger->assertAmount($data['claimed_amount']);
        $key = (string) ($data['idempotency_key'] ?? '');
        abort_unless(strlen($key) >= 1 && strlen($key) <= 150, 422, 'Kunci transaksi wajib diisi.');
        $beneficiaryId = (int) ($data['beneficiary_user_id'] ?? $payer->id);
        $reference = $this->normalizeReference((string) ($data['bank_reference'] ?? ''));
        $payload = [...$data, 'purpose' => $purpose, 'community_id' => $community?->id, 'beneficiary_user_id' => $beneficiaryId, 'bank_reference' => $reference];
        $hash = $this->ledger->payloadHash($payload);
        $existing = IncomingReceipt::query()->where('submitted_by', $payer->id)->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Kunci transaksi sudah dipakai untuk data berbeda.');

            return $existing;
        }
        $this->authorizeSubmission($payer, $community, $purpose, $beneficiaryId, $data);
        // Commit the private file location before the financial transaction. A failed
        // allocation must not roll back metadata after filesystem bytes have moved.
        $this->images->assertUsable($payer, [(string) $data['proof_asset_id']], 'proof_asset_id');
        $privateProof = Asset::query()->findOrFail($data['proof_asset_id']);
        $this->proofDigest($privateProof);
        $this->privateAssets->privatize($privateProof);
        $privateProof->forceFill(['retain_until' => null, 'is_protected' => true])->save();

        try {
            return DB::transaction(function () use ($payer, $community, $data, $purpose, $key, $beneficiaryId, $reference, $hash): IncomingReceipt {
                $this->ledger->lockOperation('receipt:'.$payer->id.':'.$key);
                $existing = IncomingReceipt::query()->where('submitted_by', $payer->id)->where('idempotency_key', $key)->first();
                if ($existing !== null) {
                    abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Kunci transaksi sudah dipakai untuk data berbeda.');

                    return $existing;
                }
                if ($community !== null) {
                    $community = Community::query()->lockForUpdate()->findOrFail($community->id);
                }
                $targetIdentity = $this->authorizeSubmission($payer, $community, $purpose, $beneficiaryId, $data);
                $beneficiary = User::query()->findOrFail($beneficiaryId);
                $this->images->assertUsable($payer, [(string) $data['proof_asset_id']], 'proof_asset_id');
                $proof = Asset::query()->lockForUpdate()->findOrFail($data['proof_asset_id']);
                $digest = $this->proofDigest($proof);
                $this->ledger->lockOperation('proof:'.$digest);
                abort_if(IncomingReceipt::query()->where('proof_digest', $digest)->orWhere('proof_asset_id', $proof->id)->exists(), 409, 'Bukti ini sudah terdaftar. Lihat pengajuan sebelumnya.');
                // Also detect pre-cutover rows created during an older client transaction.
                abort_if(DuesPayment::query()->where('proof_asset_id', $proof->id)->exists(), 409, 'Bukti sudah dipakai untuk iuran.');
                $receipt = IncomingReceipt::query()->create([
                    'user_id' => $beneficiaryId, 'submitted_by' => $payer->id, 'member_identity_id' => $targetIdentity ?? MemberIdentity::forUser($beneficiary)->id,
                    'community_id' => $community?->id, 'purpose' => $purpose, 'status' => 'pending',
                    'idempotency_key' => $key, 'payload_hash' => $hash,
                    'claimed_amount' => $data['claimed_amount'], 'proof_asset_id' => $proof->id, 'proof_digest' => $digest,
                    'custodian_key' => $this->custodianKey($community, $purpose), 'collector_snapshot' => $this->collectorSnapshot($community, $purpose),
                    'bank_reference' => $reference, 'invoice_ids' => array_values($data['invoice_ids'] ?? []), 'credit_excess' => (bool) ($data['credit_excess'] ?? false), 'target_member_id' => $data['target_member_id'] ?? null, 'note' => $data['note'] ?? null,
                ]);
                $this->audit($payer, $receipt, 'receipt_submitted');

                return $receipt;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Bukti atau referensi pembayaran sudah digunakan.');
        }
    }

    /** @param list<array{account_id:int,amount:int,reference?:string}>|null $allocations Trusted domain-only allocations. */
    public function verify(User $actor, IncomingReceipt $receipt, int $verifiedAmount, string $bankReference, string $key, ?array $allocations = null, ?string $note = null): IncomingReceipt
    {
        $this->ledger->assertAmount($verifiedAmount);
        $reference = $this->normalizeReference($bankReference);
        try {
            return DB::transaction(function () use ($actor, $receipt, $verifiedAmount, $reference, $key, $allocations, $note): IncomingReceipt {
                $receipt = IncomingReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
                $community = $receipt->community_id === null ? null : Community::query()->lockForUpdate()->findOrFail($receipt->community_id);
                $this->assertReviewer($actor, $community, $receipt->purpose);
                if ($receipt->status === 'verified') {
                    abort_unless($receipt->verified_amount === $verifiedAmount && $receipt->verified_reference === $reference && $receipt->review_note === $note, 409, 'Pembayaran sudah diverifikasi dengan data berbeda.');

                    return $receipt;
                }
                abort_unless($receipt->status === 'pending', 409, 'Pengajuan ini tidak menunggu verifikasi.');
                $proof = Asset::query()->findOrFail($receipt->proof_asset_id);
                abort_unless(hash_equals($receipt->proof_digest, $this->proofDigest($proof)), 422, 'Berkas bukti berubah sejak pengajuan. Ajukan bukti yang benar.');
                $this->ledger->lockOperation('bank-reference:'.$receipt->custodian_key.':'.$reference);
                abort_if(IncomingReceipt::query()->where('custodian_key', $receipt->custodian_key)->where('verified_reference', $reference)->where('id', '<>', $receipt->id)->exists(), 409, 'Referensi bank sudah diverifikasi.');
                $receipt->forceFill(['verified_amount' => $verifiedAmount, 'verified_reference' => $reference, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
                if ($allocations === null) {
                    if ($receipt->purpose === 'topup') {
                        $allocations = [['account_id' => $this->ledger->walletAccount(MemberIdentity::query()->findOrFail($receipt->member_identity_id))->id, 'amount' => $verifiedAmount]];
                    } else {
                        $allocations = app(BillingService::class)->allocateReceipt($receipt, $verifiedAmount, $receipt->invoice_ids);
                    }
                }
                $journal = $this->ledger->creditReceipt($actor, $receipt, $allocations, $key);
                $receipt->forceFill(['status' => 'verified', 'journal_id' => $journal->id, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
                $this->audit($actor, $receipt, 'receipt_verified');

                return $receipt;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Bukti atau referensi pembayaran sudah diverifikasi.');
        }
    }

    public function reject(User $actor, IncomingReceipt $receipt, string $note): IncomingReceipt
    {
        abort_if(trim($note) === '', 422, 'Alasan penolakan wajib diisi.');

        return DB::transaction(function () use ($actor, $receipt, $note): IncomingReceipt {
            $receipt = IncomingReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $this->assertReviewer($actor, $receipt->community_id === null ? null : Community::query()->lockForUpdate()->findOrFail($receipt->community_id), $receipt->purpose);
            if ($receipt->status === 'rejected') {
                abort_unless($receipt->review_note === $note, 409, 'Penolakan sudah dicatat dengan alasan berbeda.');

                return $receipt;
            }
            abort_unless($receipt->status === 'pending', 409, 'Pengajuan ini tidak menunggu verifikasi.');
            $receipt->forceFill(['status' => 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            $this->audit($actor, $receipt, 'receipt_rejected');

            return $receipt;
        }, 3);
    }

    public function assertReviewer(User $actor, ?Community $community, string $purpose): void
    {
        if ($purpose === 'platform') {
            abort_unless($actor->hasRole('super-admin'), 403, 'Hanya pengelola platform yang dapat memverifikasi langganan.');

            return;
        }
        abort_unless($community !== null && ($actor->hasRole('super-admin') || CommunityMember::query()->where('community_id', $community->id)->where('user_id', $actor->id)->where('status', 'active')->whereIn('role', ['creator', 'admin'])->exists()), 403, 'Hanya admin komunitas terkait yang dapat memverifikasi.');
    }

    public function canReview(User $actor, ?Community $community, string $purpose = 'topup'): bool
    {
        if ($purpose === 'platform') {
            return $actor->hasRole('super-admin');
        }

        return $community !== null && ($actor->hasRole('super-admin') || CommunityMember::query()->where('community_id', $community->id)->where('user_id', $actor->id)->where('status', 'active')->whereIn('role', ['creator', 'admin'])->exists());
    }

    public function proofDigest(Asset $proof, bool $legacy = false): string
    {
        $disk = Storage::disk($proof->storage_type->disk());
        if ($disk->exists($proof->path)) {
            $stream = $disk->readStream($proof->path);
            abort_unless(is_resource($stream), 422, 'Bukti tidak dapat dibaca.');
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            fclose($stream);

            return hash_final($context);
        }
        abort_unless($legacy, 422, 'Berkas bukti tidak ditemukan. Unggah ulang.');

        return preg_match('/^[a-f0-9]{64}$/i', (string) $proof->checksum) ? strtolower((string) $proof->checksum) : hash('sha256', 'legacy-missing:'.$proof->id);
    }

    /** Reserve a historical proof without inventing a cash opening balance. Safe to repeat. */
    public function registerLegacyProof(DuesPayment $payment, bool $import = false, bool $historical = true): IncomingReceipt
    {
        $existing = IncomingReceipt::query()->where('legacy_dues_payment_id', $payment->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        abort_if($payment->proof_asset_id === null, 422, 'Bukti pembayaran lama tidak tersedia. Riwayat tetap tersimpan.');
        $proof = Asset::withTrashed()->find($payment->proof_asset_id);
        abort_if($proof === null, 422, 'Bukti pembayaran lama tidak tersedia. Riwayat tetap tersimpan.');
        $digest = $this->proofDigest($proof, $historical);
        $proof->forceFill(['is_protected' => true, 'retain_until' => null])->save();
        $byProof = IncomingReceipt::query()->where('proof_digest', $digest)->orWhere('proof_asset_id', $proof->id)->first();
        if ($byProof !== null && $import) {
            return $byProof; // Retain all original historical rows, reserve one global proof.
        }
        abort_if($byProof !== null, 409, 'Bukti iuran sudah dialokasikan pada transaksi lain.');
        $payer = User::query()->findOrFail($payment->user_id);
        $status = match ($payment->status) {
            DuesPaymentStatus::Approved => 'legacy_verified', DuesPaymentStatus::Rejected => 'legacy_rejected', default => 'legacy_pending',
        };

        return IncomingReceipt::query()->create([
            'user_id' => $payment->user_id, 'submitted_by' => $payment->user_id, 'member_identity_id' => MemberIdentity::forUser($payer)->id,
            'community_id' => $payment->community_id, 'purpose' => 'legacy_dues', 'status' => $status,
            'idempotency_key' => 'legacy-dues:'.$payment->id, 'payload_hash' => hash('sha256', 'legacy-dues:'.$payment->id),
            'claimed_amount' => $payment->total_amount, 'verified_amount' => $status === 'legacy_verified' ? $payment->total_amount : null,
            'proof_asset_id' => $proof->id, 'proof_digest' => $digest, 'custodian_key' => $historical ? 'community:'.$payment->community_id.':legacy-unknown' : $this->custodianKey($payment->community), 'collector_snapshot' => $historical ? ['configured' => false, 'legacy_unknown' => true, 'bank_name' => null, 'account_number' => null, 'account_name' => null] : $this->collectorSnapshot($payment->community),
            'bank_reference' => 'LEGACY-DUES-'.$payment->id, 'verified_reference' => $status === 'legacy_verified' ? 'LEGACY-DUES-'.$payment->id : null,
            'invoice_ids' => [], 'note' => 'Histori iuran sebelum ledger; tidak membentuk saldo baru.', 'reviewed_by' => $payment->reviewed_by,
            'reviewed_at' => $payment->reviewed_at, 'legacy_dues_payment_id' => $payment->id,
        ]);
    }

    /** Immutable receiving-account snapshot; private to the payer and authorized reviewer. @return array<string,mixed> */
    public function collectorSnapshot(?Community $community, string $purpose = 'topup'): array
    {
        $bank = $purpose === 'platform' ? config('finance.platform_bank_name') : $community?->dues_bank_name;
        $number = $purpose === 'platform' ? config('finance.platform_account_number') : $community?->dues_account_number;
        $name = $purpose === 'platform' ? config('finance.platform_account_name') : $community?->dues_account_name;

        return ['configured' => filled($bank) && filled($number) && filled($name), 'bank_name' => $bank, 'account_number' => $number, 'account_name' => $name];
    }

    public function custodianKey(?Community $community, string $purpose = 'topup'): string
    {
        $snapshot = $this->collectorSnapshot($community, $purpose);
        $prefix = $purpose === 'platform' ? 'platform' : 'community:'.$community?->id;
        if (! $snapshot['configured']) {
            return $prefix.':unconfigured';
        }
        $bank = mb_strtoupper(trim((string) $snapshot['bank_name']));
        $number = preg_replace('/\s+/', '', (string) $snapshot['account_number']);

        return $prefix.':'.hash('sha256', $bank.':'.$number);
    }

    /** @param array<string,mixed> $data */
    private function authorizeSubmission(User $payer, ?Community $community, string $purpose, int $beneficiaryId, array $data): ?int
    {
        if ($beneficiaryId !== $payer->id) {
            abort_unless($community !== null && $purpose === 'topup', 403);
            $this->assertReviewer($payer, $community, 'topup');
        }
        if ($purpose === 'member' && isset($data['target_member_id'])) {
            abort_unless($community !== null && $beneficiaryId === $payer->id, 403);

            return app(BillingService::class)->subjectFor($payer, $community, 'member', (int) $data['target_member_id'])->member_identity_id;
        }
        if ($purpose === 'platform') {
            abort_unless($community !== null && $community->user_id === $beneficiaryId, 403, 'Langganan hanya untuk pemilik komunitas.');
        } else {
            abort_unless($community !== null && CommunityMember::query()->where('community_id', $community->id)->where('user_id', $beneficiaryId)->whereIn('status', ['active', 'pending'])->exists(), 403, 'Keanggotaan komunitas tidak ditemukan.');
        }

        return null;
    }

    private function normalizeReference(string $reference): string
    {
        $reference = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $reference) ?? ''));
        abort_unless(strlen($reference) >= 1 && strlen($reference) <= 150, 422, 'Referensi transfer wajib diisi maksimal 150 karakter.');

        return $reference;
    }

    private function audit(User $actor, IncomingReceipt $receipt, string $event): void
    {
        activity('finance')->causedBy($actor)->performedOn($receipt->community_id === null ? $actor : Community::query()->findOrFail($receipt->community_id))->event($event)->withProperties([
            'receipt_id' => $receipt->id, 'user_id' => $receipt->user_id, 'community_id' => $receipt->community_id,
            'claimed_amount' => $receipt->claimed_amount, 'verified_amount' => $receipt->verified_amount,
        ])->log($event);
    }
}
