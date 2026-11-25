<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $payout_part_id
 * @property string $proof_asset_id
 * @property string $proof_digest
 * @property string $reference_digest
 * @property string $bank_reference
 * @property int $amount
 * @property string $status
 * @property int $submitted_by
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property string $note
 * @property string|null $journal_id
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property Carbon $created_at
 */
class PayoutFragment extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['payout_part_id', 'proof_asset_id', 'proof_digest', 'reference_digest', 'bank_reference', 'amount', 'status', 'submitted_by', 'verified_by', 'verified_at', 'note', 'journal_id', 'idempotency_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'verified_at' => 'datetime'];
    }
}
