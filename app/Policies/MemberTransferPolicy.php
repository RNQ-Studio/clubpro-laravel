<?php

namespace App\Policies;

use App\Models\MemberIdentity;
use App\Models\MemberTransfer;
use App\Models\User;

class MemberTransferPolicy
{
    public function view(User $user, MemberTransfer $transfer): bool
    {
        return in_array(MemberIdentity::forUser($user)->id, [$transfer->sender_identity_id, $transfer->recipient_identity_id], true);
    }
}
