<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $community_id
 * @property int $location_id
 * @property string $title
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $created_by
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TrainingSession extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
