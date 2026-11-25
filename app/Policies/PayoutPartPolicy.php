<?php

namespace App\Policies;

use App\Models\PayoutPart;
use App\Models\User;
use App\Services\MoneyMovementService;

class PayoutPartPolicy
{
    public function manage(User $user, PayoutPart $part): bool
    {
        return app(MoneyMovementService::class)->canManage($user, $part);
    }
}
