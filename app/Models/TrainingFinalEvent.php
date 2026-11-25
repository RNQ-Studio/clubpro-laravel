<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $community_id
 * @property int $member_identity_id
 * @property string $type
 * @property string $source_id
 * @property array<string,mixed> $payload
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TrainingFinalEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
