<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_identity_id
 * @property int $guardian_user_id
 * @property int $community_member_id
 * @property-read CommunityMember $member
 * @property Carbon $verified_at
 * @property Carbon|null $revoked_at
 * @property-read MemberIdentity $identity
 */
class GuardianGrant extends Model
{
    protected $fillable = ['member_identity_id', 'guardian_user_id', 'community_member_id', 'verified_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<CommunityMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(CommunityMember::class, 'community_member_id');
    }

    /** @return BelongsTo<MemberIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(MemberIdentity::class, 'member_identity_id');
    }
}
