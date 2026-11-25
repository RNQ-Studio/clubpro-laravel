<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $payout_id
 * @property int|null $community_id
 * @property string $custodian_key
 * @property int $amount
 * @property int $verified_amount
 * @property string $status
 * @property int $version
 * @property string $reservation_id
 * @property list<array{receipt_id:string,amount:int,remaining:int}> $allocations
 * @property Carbon $created_at
 */
class PayoutPart extends Model
{
    use HasUuids;

    protected $fillable = ['payout_id', 'community_id', 'custodian_key', 'amount', 'verified_amount', 'status', 'version', 'reservation_id', 'allocations'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'verified_amount' => 'integer', 'version' => 'integer', 'allocations' => 'array'];
    }
}
