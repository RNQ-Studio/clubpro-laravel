<?php

namespace App\Models;

use App\Support\Enums\CommunityMemberRole;
use App\Support\Enums\CommunityMemberStatus;
use Database\Factories\CommunityMemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $community_id
 * @property int $user_id
 * @property CommunityMemberRole $role
 * @property CommunityMemberStatus $status
 * @property Carbon|null $joined_at
 * @property Carbon|null $entry_fee_paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Community $community
 * @property-read User $user
 */
class CommunityMember extends Model
{
    /** @use HasFactory<CommunityMemberFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'role', 'status', 'joined_at'];

    protected function casts(): array
    {
        return [
            'role' => CommunityMemberRole::class,
            'status' => CommunityMemberStatus::class,
            'joined_at' => 'datetime',
            'entry_fee_paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Community, $this> */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<CommunityMember>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', CommunityMemberStatus::Active);
    }

    /** @param  Builder<CommunityMember>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', CommunityMemberStatus::Pending);
    }

    public function isActive(): bool
    {
        return $this->status === CommunityMemberStatus::Active;
    }
}
