<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $community_id
 * @property int $session_id
 * @property int $member_id
 * @property int $member_identity_id
 * @property string $status
 * @property string|null $purchase_id
 * @property int $allocation
 * @property array<string,mixed>|null $snapshot
 * @property string|null $journal_id
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property string|null $confirmation_key
 * @property int|null $checked_in_by
 * @property string|null $checkin_key
 * @property string|null $corrected_by_name
 * @property int|null $corrected_by
 * @property Carbon|null $corrected_at
 * @property string|null $correction_reason
 * @property string|null $correction_key
 * @property string|null $correction_journal_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TrainingAttendance extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['allocation' => 'integer', 'snapshot' => 'array', 'confirmed_at' => 'datetime', 'corrected_at' => 'datetime'];
    }
}
