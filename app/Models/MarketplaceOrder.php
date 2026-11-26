<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $product_id
 * @property int $user_id
 * @property int $buyer_identity_id
 * @property int $seller_identity_id
 * @property int $community_id
 * @property string $journal_id
 * @property int $quantity
 * @property int $unit_price
 * @property int $total
 * @property string $status
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property array<string,mixed> $snapshot
 * @property Carbon $created_at
 */
class MarketplaceOrder extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'integer', 'total' => 'integer', 'snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
