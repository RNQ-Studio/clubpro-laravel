<?php

namespace App\Policies;

use App\Models\Community;
use App\Models\User;
use App\Support\Enums\CommunityMemberStatus;
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

    /** Creator dan admin komunitas; super admin lewat Gate::before. */
    public function manage(User $user, Community $community): bool
    {
        return $community->isManagedBy($user);
    }

    /** Iuran: melihat dan membayar iuran sendiri, hanya anggota aktif. */
    public function dues(User $user, Community $community): bool
    {
        return $community->membershipOf($user)?->status === CommunityMemberStatus::Active;
    }

    /** Daftar anggota hanya untuk sesama anggota. */
    public function viewMembers(User $user, Community $community): bool
    {
        return $community->membershipOf($user)?->status === CommunityMemberStatus::Active;
    }
}
