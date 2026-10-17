<?php

namespace App\Services\Auth\Google;

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Login anggota lewat Google: verifikasi ID token, temukan/buat user, lalu
 * terbitkan token Passport yang sama dengan login biasa (access + refresh).
 */
class GoogleAuthService
{
    public function __construct(
        private readonly GoogleIdTokenVerifier $verifier,
        private readonly AuthService $authService,
    ) {}

    /**
     * @param  array{device_id?: string, platform?: string, os_version?: string|null, app_version?: string|null, device_name?: string|null, push_token?: string|null}  $deviceInfo
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, is_new_user: bool}
     *
     * @throws AuthenticationException|AuthorizationException
     */
    public function login(string $idToken, array $deviceInfo = []): array
    {
        $identity = $this->verifier->verify($idToken);

        [$user, $isNew] = $this->resolveUser($identity);

        if (! $user->is_active) {
            throw new AuthorizationException('Your account is inactive.');
        }

        $tokens = $this->authService->issueTokenForUser($user);
        $this->authService->recordDevice($user, $deviceInfo);

        return [...$tokens, 'is_new_user' => $isNew];
    }

    /**
     * @return array{0: User, 1: bool}
     */
    private function resolveUser(GoogleIdentity $identity): array
    {
        $email = Str::lower($identity->email);
        $user = User::query()->where('google_id', $identity->subject)->first()
            ?? User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user !== null) {
            // Akun email yang sudah ada ditautkan ke Google pada login pertama;
            // foto Google hanya diperbarui sebagai cadangan, bukan menimpa unggahan.
            $user->forceFill([
                'google_id' => $user->google_id ?? $identity->subject,
                'google_avatar_url' => $identity->pictureUrl ?? $user->google_avatar_url,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            return [$user, false];
        }

        try {
            $user = DB::transaction(fn (): User => User::query()->forceCreate([
                'name' => $identity->name ?: Str::before($email, '@'),
                'email' => $email,
                // Akun Google tidak memakai password; isi acak agar login password tertutup.
                'password' => Str::password(40),
                'google_id' => $identity->subject,
                'google_avatar_url' => $identity->pictureUrl,
                'email_verified_at' => now(),
                'is_active' => true,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Login ganda bersamaan untuk akun baru: pakai baris yang menang.
            $user = User::query()->where('google_id', $identity->subject)->firstOrFail();

            return [$user, false];
        }

        return [$user, true];
    }
}
