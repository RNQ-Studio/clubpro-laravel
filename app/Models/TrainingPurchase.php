<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $community_id
 * @property int $member_id
 * @property int $member_identity_id
 * @property int $buyer_id
 * @property int $purchase_order
 * @property int $sessions
 * @property int $remaining_sessions
 * @property int $price
 * @property int $remaining_amount
 * @property int $catalog_version
 * @property array<string,mixed> $snapshot
 * @property string $reservation_id
 * @property string $journal_id
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TrainingPurchase extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sessions' => 'integer', 'remaining_sessions' => 'integer', 'price' => 'integer', 'remaining_amount' => 'integer', 'catalog_version' => 'integer', 'snapshot' => 'array'];
    }
}
