<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $source_key
 * @property int $community_id
 * @property int $member_identity_id
 * @property string $type
 * @property string $source_id
 * @property int $points
 * @property int $rule_version
 * @property int|null $actor_id
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
class AchievementEntry extends Model
{
    use HasUuids;

    protected $table = 'achievement_entries';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['points' => 'integer', 'rule_version' => 'integer', 'occurred_at' => 'datetime'];
    }
}
