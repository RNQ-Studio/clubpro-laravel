<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserDevice;
use App\Services\UserActivityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Passport\RefreshToken;

class AuthService
{
    public function __construct(private readonly UserActivityService $activity) {}

    /**
     * @param  array{device_id?: string, platform?: string, os_version?: string|null, app_version?: string|null, device_name?: string|null, push_token?: string|null, ip_address?: string|null, user_agent?: string|null}  $deviceInfo
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     *
     * @throws AuthenticationException|AuthorizationException
     */
    public function login(string $email, string $password, array $deviceInfo = []): array
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null && ! $user->is_active) {
            throw new AuthorizationException('Your account is inactive.');
        }

        $tokens = $this->issueToken([
            'grant_type' => 'password',
            'username' => $email,
            'password' => $password,
        ]);

        // Reload user after token issuance to ensure it exists
        if ($user !== null) {
            $this->activity->recordLogin($user, $deviceInfo);
        }

        return $tokens;
    }

    /**
     * Issue a refreshable Password Grant token for a user after OTP verification
     * by utilizing a secure single-use login token.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     *
     * @throws AuthenticationException
     */
    public function issueTokenForUser(User $user): array
    {
        $tempToken = 'otp_token_'.Str::random(40);

        // Store in cache for 30 seconds
        cache()->put('otp_login_token_'.$user->getKey(), $tempToken, 30);

        return $this->issueToken([
            'grant_type' => 'password',
            'username' => $user->email,
            'password' => $tempToken,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     *
     * @throws AuthenticationException
     */
    public function refresh(string $refreshToken): array
    {
        return $this->issueToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    public function logout(User $user, ?string $deviceId = null): void
    {
        $accessToken = $user->token();

        if (! $accessToken instanceof AccessToken) {
            return;
        }

        $tokenId = $accessToken->toArray()['oauth_access_token_id'] ?? null;

        if ($tokenId !== null) {
            RefreshToken::query()
                ->where('access_token_id', $tokenId)
                ->update(['revoked' => true]);
        }

        $accessToken->revoke();

        // Nullify push token so device stops receiving notifications
        if ($deviceId !== null) {
            UserDevice::query()
                ->where('user_id', $user->getKey())
                ->where('device_id', $deviceId)
                ->update(['push_token' => null]);
        }
    }

    /**
     * Revoke all access tokens, refresh tokens, and nullify all push tokens for the user.
     */
    public function logoutAllDevices(User $user): void
    {
        DB::transaction(function () use ($user) {
            $tokenIds = $user->tokens()->pluck('id');

            if ($tokenIds->isNotEmpty()) {
                RefreshToken::query()
                    ->whereIn('access_token_id', $tokenIds)
                    ->update(['revoked' => true]);

                $user->tokens()->update(['revoked' => true]);
            }

            UserDevice::query()
                ->where('user_id', $user->getKey())
                ->update(['push_token' => null]);
        });
    }

    /**
     * @param  array<string, string>  $params
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     *
     * @throws AuthenticationException
     */
    private function issueToken(array $params): array
    {
        $params = [
            'client_id' => (string) config('passport.password_client.id'),
            'client_secret' => (string) config('passport.password_client.secret'),
            'scope' => '',
            ...$params,
        ];

        $request = Request::create('/oauth/token', 'POST', $params);
        $response = app()->handle($request);

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getContent(), true) ?: [];

        if ($response->getStatusCode() !== 200) {
            $errorType = $data['error'] ?? null;
            $errorMsg = $data['message'] ?? $data['error_description'] ?? $errorType ?? 'Unknown error';

            if (config('app.debug') && in_array($errorType, ['unsupported_grant_type', 'invalid_client'], true)) {
                throw new \RuntimeException("Passport configuration error: {$errorMsg}. Did you run: php artisan passport:client --password?");
            }

            throw new AuthenticationException('Invalid credentials.');
        }

        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => (string) $data['refresh_token'],
            'token_type' => (string) $data['token_type'],
            'expires_in' => (int) $data['expires_in'],
        ];
    }
}
