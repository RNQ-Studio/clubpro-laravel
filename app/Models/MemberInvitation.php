<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_identity_id
 * @property int $community_member_id
 * @property int $invited_by
 * @property string $kind
 * @property string $email
 * @property string $delivery_method
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $revoked_at
 * @property-read MemberIdentity $identity
 * @property-read CommunityMember $member
 * @property-read User $issuer
 */
class MemberInvitation extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'claimed_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<MemberIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(MemberIdentity::class, 'member_identity_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return BelongsTo<CommunityMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(CommunityMember::class, 'community_member_id');
    }
}
