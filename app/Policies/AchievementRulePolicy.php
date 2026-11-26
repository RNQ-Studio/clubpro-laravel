<?php

namespace App\Policies;

use App\Models\User;

class AchievementRulePolicy
{
    public function manage(User $user): bool
    {
        return $user->is_active && $user->hasRole('super-admin');
    }
}
