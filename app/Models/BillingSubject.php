<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $merged_into_subject_id
 * @property string $subject_key
 * @property int $community_id
 * @property int|null $member_identity_id
 * @property int|null $member_id
 * @property string $kind
 * @property string $scheme
 * @property Carbon|null $trial_start
 * @property Carbon|null $trial_end
 * @property Carbon $anchor_date
 * @property int|null $due_day
 * @property int $next_offset
 * @property Carbon|null $paused_at
 * @property Carbon|null $inactive_at
 * @property Carbon|null $retained_until
 * @property Carbon|null $archived_at
 * @property int|null $archived_by
 */
class BillingSubject extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['trial_start' => 'date', 'trial_end' => 'date', 'anchor_date' => 'date', 'next_offset' => 'integer', 'due_day' => 'integer', 'inactive_at' => 'date', 'paused_at' => 'date', 'retained_until' => 'date', 'archived_at' => 'datetime'];
    }
}
