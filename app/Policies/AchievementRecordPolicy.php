<?php

namespace App\Policies;

use App\Models\Community;
use App\Models\User;
use App\Services\AchievementService;

class AchievementRecordPolicy
{
    public function create(User $user, Community $community): bool
    {
        return app(AchievementService::class)->canManage($user, $community);
    }

    public function correct(User $user, Community $community): bool
    {
        return app(AchievementService::class)->canManage($user, $community, true);
    }
}
