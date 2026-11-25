<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property int $member_identity_id
 * @property int $community_id
 * @property array<string,mixed> $bank_snapshot
 * @property string $member_name
 * @property int $amount
 * @property int $verified_amount
 * @property string $status
 * @property int $version
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Payout extends Model
{
    use HasUuids;

    protected $hidden = ['bank_snapshot'];

    protected $fillable = ['user_id', 'member_identity_id', 'community_id', 'bank_snapshot', 'member_name', 'amount', 'verified_amount', 'status', 'version', 'idempotency_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['bank_snapshot' => 'encrypted:array', 'amount' => 'integer', 'verified_amount' => 'integer', 'version' => 'integer'];
    }
}
