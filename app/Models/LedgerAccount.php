<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $account_key
 * @property string $kind
 * @property string $bucket
 * @property int|null $member_identity_id
 * @property int|null $community_id
 * @property int $balance
 * @property bool $allow_negative
 */
class LedgerAccount extends Model
{
    protected $fillable = ['account_key', 'kind', 'bucket', 'member_identity_id', 'community_id', 'balance', 'allow_negative'];

    protected function casts(): array
    {
        return ['balance' => 'integer', 'allow_negative' => 'boolean'];
    }
}
