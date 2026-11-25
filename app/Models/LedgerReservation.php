<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $actor_id
 * @property string $source_reference
 * @property int $available_account_id
 * @property int $reserved_account_id
 * @property int|null $community_id
 * @property int $amount
 * @property int $remaining
 * @property string $status
 */
class LedgerReservation extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'actor_id', 'source_reference', 'available_account_id', 'reserved_account_id', 'community_id', 'amount', 'remaining', 'status'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'remaining' => 'integer'];
    }
}
