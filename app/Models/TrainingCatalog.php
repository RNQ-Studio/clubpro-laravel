<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $community_id
 * @property bool $enabled
 * @property int $version
 * @property array<int,array<string,mixed>> $packages
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TrainingCatalog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'version' => 'integer', 'packages' => 'array'];
    }
}
