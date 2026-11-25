<?php

namespace App\Policies;

use App\Models\Payout;
use App\Models\PayoutPart;
use App\Models\User;
use App\Services\MoneyMovementService;

class PayoutPolicy
{
    public function view(User $user, Payout $payout): bool
    {
        return $payout->user_id === $user->id || PayoutPart::query()->where('payout_id', $payout->id)->get()->contains(fn (PayoutPart $part): bool => app(MoneyMovementService::class)->canManage($user, $part));
    }
}
