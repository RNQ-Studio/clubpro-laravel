<?php

namespace App\Policies;

use App\Models\AppRelease;
use App\Models\User;

class AppReleasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('app_releases.viewAny');
    }

    public function view(User $user, AppRelease $appRelease): bool
    {
        return $user->can('app_releases.view');
    }

    /** Mengunggah rilis APK baru (API upload ber-chunk). */
    public function create(User $user): bool
    {
        return $user->can('app_releases.create');
    }

    public function update(User $user, AppRelease $appRelease): bool
    {
        return $user->can('app_releases.update');
    }

    public function delete(User $user, AppRelease $appRelease): bool
    {
        return $user->can('app_releases.delete');
    }
}
