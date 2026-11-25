<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $community_id
 * @property string $name
 * @property string $address
 * @property bool $active
 * @property int $version
 * @property list<int> $manager_member_ids
 * @property list<array<string,mixed>> $splits
 * @property int $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TrainingLocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer', 'manager_member_ids' => 'array', 'splits' => 'array'];
    }
}
