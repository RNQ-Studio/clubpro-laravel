<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\PinRecoveryNotification;
use App\Services\SensitiveMailGuard;
use App\Services\UserActivityService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class PinService
{
    public function __construct(private readonly AuthService $auth, private readonly UserActivityService $activity) {}

    /** @param array<string, mixed> $deviceInfo
     * @return array<string, mixed>
     */
    public function login(string $identifier, #[\SensitiveParameter] string $pin, string $ip, array $deviceInfo): array
    {
        $user = $this->findVerified($identifier);
        $key = $this->limit($user === null ? $identifier : 'user:'.$user->getKey(), $ip);
        $dummyHash = Cache::rememberForever('pin:dummy:'.hash('sha256', serialize(config('hashing'))), fn (): string => Hash::make(Str::random(32)));
        $hash = $user->pin_hash ?? $dummyHash;
        if (! Hash::check($pin, $hash) || $user === null || ! $user->is_active || $user->pin_hash === null) {
            RateLimiter::hit($key, 900);
            throw new AuthenticationException('Identitas atau PIN tidak sesuai.');
        }
        RateLimiter::clear($key);
        if (Hash::needsRehash($user->pin_hash)) {
            $user->forceFill(['pin_hash' => Hash::make($pin)])->save();
        }
        $tokens = $this->auth->issueTokenForUser($user);
        $this->activity->recordLogin($user, $deviceInfo);

        return [...$tokens, 'email_verified' => $user->hasVerifiedEmail()];
    }

    public function change(User $user, #[\SensitiveParameter] string $pin, #[\SensitiveParameter] ?string $currentPin, #[\SensitiveParameter] ?string $password, string $ip): void
    {
        $key = $this->limit('change:'.$user->getKey(), $ip);
        $verified = $user->email_verified_at !== null || $user->phone_verified_at !== null;
        $valid = $user->pin_hash !== null
            ? Hash::check($currentPin ?? '', $user->pin_hash)
            : ($verified && Hash::check($password ?? '', $user->password));
        if (! $valid) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['current_pin' => ['Verifikasi tidak sesuai. Gunakan pemulihan PIN bila diperlukan.']]);
        }
        RateLimiter::clear($key);
        $user->forceFill(['pin_hash' => Hash::make($pin)])->save();
        DB::table('pin_recoveries')->where('user_id', $user->getKey())->update(['used_at' => now()]);
        $this->audit($user, 'pin_changed');
    }

    public function recover(string $identifier): void
    {
        app(SensitiveMailGuard::class)->assertAvailable();
        $user = $this->findVerified($identifier);
        if ($user === null || ! $user->is_active || $user->email_verified_at === null) {
            return;
        }
        $code = (string) random_int(100000, 999999);
        DB::table('pin_recoveries')->updateOrInsert(['user_id' => $user->getKey()], [
            'code_hash' => Hash::make($code), 'email_hash' => hash('sha256', strtolower($user->email)), 'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'used_at' => null,
        ]);
        try {
            $user->notify(new PinRecoveryNotification($code));
        } catch (\Throwable) {
            // Respons tetap seragam; jangan log exception transport yang bisa memuat kode.
            Log::warning('PIN recovery delivery failed.');
        }
    }

    public function reset(string $identifier, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $pin): bool
    {
        $user = $this->findVerified($identifier);
        if ($user === null || ! $user->is_active || $user->email_verified_at === null) {
            return false;
        }

        return DB::transaction(function () use ($user, $code, $pin): bool {
            $recovery = DB::table('pin_recoveries')->where('user_id', $user->getKey())->lockForUpdate()->first();
            if ($recovery === null || $recovery->used_at !== null || ! hash_equals($recovery->email_hash, hash('sha256', strtolower($user->email))) || $recovery->attempts >= 5 || now()->gte($recovery->expires_at)) {
                return false;
            }
            DB::table('pin_recoveries')->where('id', $recovery->id)->increment('attempts');
            if (! Hash::check($code, $recovery->code_hash)) {
                return false;
            }
            $user->forceFill(['pin_hash' => Hash::make($pin)])->save();
            DB::table('pin_recoveries')->where('id', $recovery->id)->update(['used_at' => now()]);
            $this->auth->logoutAllDevices($user);
            $this->audit($user, 'pin_reset');

            return true;
        });
    }

    private function findVerified(string $identifier): ?User
    {
        $identifier = trim($identifier);

        $users = User::query()->where(function ($query) use ($identifier): void {
            $query->whereRaw('lower(email) = ?', [mb_strtolower($identifier)])->orWhere('phone', $identifier);
        })->limit(2)->get();
        if ($users->count() !== 1) {
            return null;
        }
        $user = $users->first();
        $verified = mb_strtolower($user->email) === mb_strtolower($identifier)
            ? $user->email_verified_at !== null
            : $user->phone_verified_at !== null;

        return $verified ? $user : null;
    }

    private function limit(string $identifier, string $ip): string
    {
        $global = 'pin:ip:'.hash('sha256', $ip);
        $key = 'pin:account:'.hash('sha256', mb_strtolower(trim($identifier)).'|'.$ip);
        if (RateLimiter::tooManyAttempts($global, 60) || RateLimiter::tooManyAttempts($key, 5)) {
            throw new TooManyRequestsHttpException(max(RateLimiter::availableIn($global), RateLimiter::availableIn($key)), 'Terlalu banyak percobaan. Coba lagi nanti.');
        }
        RateLimiter::hit($global, 900);

        return $key;
    }

    private function audit(User $user, string $event): void
    {
        activity('auth')->causedBy($user)->performedOn($user)->event($event)->log($event);
    }
}
