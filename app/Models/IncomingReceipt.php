<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property int $submitted_by
 * @property int $member_identity_id
 * @property int|null $community_id
 * @property string $purpose
 * @property string $status
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property int $claimed_amount
 * @property int|null $verified_amount
 * @property string $proof_asset_id
 * @property string $proof_digest
 * @property array<string,mixed> $collector_snapshot
 * @property string $custodian_key
 * @property string $bank_reference
 * @property string|null $verified_reference
 * @property int|null $target_member_id
 * @property bool $credit_excess
 * @property list<string> $invoice_ids
 * @property string|null $note
 * @property string|null $review_note
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $journal_id
 * @property int|null $legacy_dues_payment_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class IncomingReceipt extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'user_id', 'submitted_by', 'member_identity_id', 'community_id', 'purpose', 'status', 'idempotency_key', 'payload_hash', 'claimed_amount', 'verified_amount', 'proof_asset_id', 'proof_digest', 'custodian_key', 'collector_snapshot', 'bank_reference', 'verified_reference', 'invoice_ids', 'credit_excess', 'target_member_id', 'note', 'review_note', 'reviewed_by', 'reviewed_at', 'journal_id', 'legacy_dues_payment_id'];

    protected function casts(): array
    {
        return ['claimed_amount' => 'integer', 'verified_amount' => 'integer', 'invoice_ids' => 'array', 'collector_snapshot' => 'array', 'credit_excess' => 'boolean', 'reviewed_at' => 'datetime'];
    }
}
