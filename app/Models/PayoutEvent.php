<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $payout_part_id
 * @property int $actor_id
 * @property string $actor_name
 * @property string $status
 * @property string $note
 * @property string $operation
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property Carbon $created_at
 */
class PayoutEvent extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['payout_part_id', 'actor_id', 'actor_name', 'status', 'note', 'operation', 'idempotency_key', 'payload_hash', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
