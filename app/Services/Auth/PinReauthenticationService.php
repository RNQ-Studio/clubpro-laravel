<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/** Bukti reautentikasi Google tidak dapat dipakai token lain atau akun lain. */
class PinReauthenticationService
{
    public function rememberGoogle(User $user, #[\SensitiveParameter] string $accessToken): void
    {
        Cache::put($this->key($user, $accessToken), true, now()->addMinutes(10));
    }

    public function hasGoogle(User $user, #[\SensitiveParameter] ?string $accessToken): bool
    {
        return $accessToken !== null && $accessToken !== '' && Cache::get($this->key($user, $accessToken)) === true;
    }

    public function consumeGoogle(User $user, #[\SensitiveParameter] ?string $accessToken): bool
    {
        if ($accessToken === null || $accessToken === '') {
            return false;
        }
        $key = $this->key($user, $accessToken);

        return Cache::lock($key.':lock', 10)->block(3, fn (): bool => Cache::pull($key) === true);
    }

    public function forgetGoogle(User $user, #[\SensitiveParameter] ?string $accessToken): void
    {
        if ($accessToken !== null && $accessToken !== '') {
            Cache::forget($this->key($user, $accessToken));
        }
    }

    private function key(User $user, #[\SensitiveParameter] string $accessToken): string
    {
        return 'pin:google-reauth:'.hash('sha256', $user->getKey().'|'.$accessToken);
    }
}
