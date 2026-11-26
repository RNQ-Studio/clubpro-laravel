<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $version
 * @property array<string,int> $points
 * @property list<int> $age_boundaries
 * @property Carbon $effective_at
 * @property int|null $changed_by
 * @property string $reason
 * @property Carbon $created_at
 */
class AchievementRule extends Model
{
    protected $table = 'achievement_rules';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['points' => 'array', 'age_boundaries' => 'array', 'effective_at' => 'datetime', 'version' => 'integer'];
    }
}
