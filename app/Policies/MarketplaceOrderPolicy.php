<?php

namespace App\Policies;

use App\Models\MarketplaceOrder;
use App\Models\MemberIdentity;
use App\Models\User;

class MarketplaceOrderPolicy
{
    public function view(User $user, MarketplaceOrder $order): bool
    {
        return $user->is_active && in_array(MemberIdentity::forUser($user)->id, [$order->buyer_identity_id, $order->seller_identity_id], true);
    }
}
