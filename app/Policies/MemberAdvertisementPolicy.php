<?php

namespace App\Policies;

use App\Models\MemberAdvertisement;
use App\Models\MemberIdentity;
use App\Models\User;

class MemberAdvertisementPolicy
{
    public function update(User $user, MemberAdvertisement $ad): bool
    {
        return $user->is_active && $ad->user_id === $user->id && $ad->seller_identity_id === MemberIdentity::forUser($user)->id;
    }
}
