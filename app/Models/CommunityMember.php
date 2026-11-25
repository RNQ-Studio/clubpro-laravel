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
 * @property int|null $user_id
 * @property int|null $member_identity_id
 * @property int|null $merged_into_member_id
 * @property string $billing_scheme
 * @property string|null $external_reference
 * @property string|null $creation_key
 * @property string|null $creation_hash
 * @property string|null $account_email
 * @property-read MemberIdentity|null $identity
 * @property CommunityMemberRole $role
 * @property CommunityMemberStatus $status
 * @property Carbon|null $joined_at
 * @property Carbon|null $entry_fee_paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Community $community
 * @property-read User|null $user
 */
class CommunityMember extends Model
{
    /** @use HasFactory<CommunityMemberFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'role', 'status', 'joined_at', 'member_identity_id', 'merged_into_member_id', 'external_reference', 'creation_key', 'creation_hash', 'account_email'];

    protected static function booted(): void
    {
        static::creating(function (self $member): void {
            if (! $member->isDirty('billing_scheme')) {
                $member->billing_scheme = 'anniversary';
            }
            if ($member->member_identity_id === null && $member->user_id !== null) {
                $member->member_identity_id = MemberIdentity::forUser(User::query()->findOrFail($member->user_id))->id;
            }
        });
    }

    /** @return BelongsTo<MemberIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(MemberIdentity::class, 'member_identity_id');
    }

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
    /** Nomor pada kartu anggota, mis. `PN-000042`: nama pendek komunitas + id keanggotaan. */
    public function cardNumber(): string
    {
        $prefix = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $this->community->short_name));

        return sprintf('%s-%06d', $prefix !== '' ? $prefix : 'CEO', $this->id);
    }

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
