<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $business_key
 * @property int $community_id
 * @property int $member_id
 * @property int $member_identity_id
 * @property string $type
 * @property string $title
 * @property string $reference
 * @property string $status
 * @property int $version
 * @property int $created_by
 * @property string $id
 * @property string $business_keyempotency_key
 * @property string $payload_hash
 * @property int|null $corrected_by
 * @property string|null $correction_reason
 * @property Carbon|null $corrected_at
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AchievementRecord extends Model
{
    use HasUuids;

    protected $table = 'achievement_records';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['version' => 'integer', 'occurred_at' => 'datetime', 'corrected_at' => 'datetime'];
    }
}
