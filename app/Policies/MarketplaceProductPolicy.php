<?php

namespace App\Policies;

use App\Models\MarketplaceProduct;
use App\Models\MemberIdentity;
use App\Models\User;

class MarketplaceProductPolicy
{
    public function update(User $user, MarketplaceProduct $product): bool
    {
        return $user->is_active && $product->user_id === $user->id && $product->seller_identity_id === MemberIdentity::forUser($user)->id;
    }
}
