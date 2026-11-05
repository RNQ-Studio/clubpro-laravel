<?php

namespace App\Policies;

use App\Models\Community;
use App\Models\User;
use App\Support\Enums\CommunityStatus;
use Illuminate\Auth\Access\Response;

class CommunityPolicy
{
    /** Pengajuan yang belum disetujui hanya terlihat oleh pengajunya. */
    public function view(User $user, Community $community): Response
    {
        return $community->status === CommunityStatus::Approved || $community->user_id === $user->getKey()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
