<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDevice;
use App\Services\Geo\IpLocationService;
use App\Support\Enums\DevicePlatform;
use App\Support\UserAgentInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Catat kapan dan dari perangkat apa pengguna login/mengakses API, untuk Admin Panel.
 * Pencatatan bersifat sampingan: kegagalannya dilaporkan, tidak pernah menggagalkan
 * login atau request pengguna.
 */
class UserActivityService
{
    /** Selang pencatatan akses, agar tidak setiap request menulis ke database. */
    public const ACCESS_INTERVAL_MINUTES = 5;

    public function __construct(private readonly IpLocationService $locations) {}

    /**
     * IP & User-Agent asli pemanggil. Ambil dari request di controller:
     * sesudah token diterbitkan, `request()` menunjuk request internal /oauth/token.
     *
     * @return array{ip_address: string|null, user_agent: string|null}
     */
    public static function clientInfo(Request $request): array
    {
        $userAgent = $request->userAgent();

        return [
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
        ];
    }

    /**
     * @param  array{device_id?: string, platform?: string, os_version?: string|null, app_version?: string|null, device_name?: string|null, push_token?: string|null, ip_address?: string|null, user_agent?: string|null}  $deviceInfo
     */
    public function recordLogin(User $user, array $deviceInfo): void
    {
        rescue(function () use ($user, $deviceInfo): void {
            $this->stamp($user, ['last_login_at' => now(), 'last_seen_at' => now()]);
            $this->saveDevice($user, $deviceInfo, ['push_token' => $deviceInfo['push_token'] ?? null]);
        });
    }

    public function recordAccess(User $user, Request $request): void
    {
        if ($user->last_seen_at?->isAfter(now()->subMinutes(self::ACCESS_INTERVAL_MINUTES))) {
            return;
        }

        rescue(function () use ($user, $request): void {
            $this->stamp($user, ['last_seen_at' => now()]);
            $this->saveDevice($user, self::clientInfo($request));
        });
    }

    /** @param  array<string, mixed>  $columns */
    private function stamp(User $user, array $columns): void
    {
        // Tanpa event & updated_at: aktivitas bukan perubahan data anggota.
        $user->timestamps = false;
        $user->forceFill($columns)->saveQuietly();
        $user->timestamps = true;
    }

    /**
     * Browser dikenali dari User-Agent-nya; app native melapor lewat device_id + platform.
     *
     * @param  array{device_id?: string, platform?: string, os_version?: string|null, app_version?: string|null, device_name?: string|null, ip_address?: string|null, user_agent?: string|null}  $info
     * @param  array<string, mixed>  $extra
     */
    private function saveDevice(User $user, array $info, array $extra = []): void
    {
        $agent = UserAgentInfo::parse($info['user_agent'] ?? null);
        $deviceId = $info['device_id'] ?? $agent?->fingerprint();
        $platform = $info['platform'] ?? ($agent === null ? null : DevicePlatform::Web->value);

        if ($deviceId === null || $platform === null) {
            return;
        }

        $key = ['user_id' => $user->getKey(), 'device_id' => $deviceId];
        $known = UserDevice::query()->where($key)->first();
        $ip = $info['ip_address'] ?? $known?->ip_address;

        UserDevice::query()->updateOrCreate($key, [
            'platform' => $platform,
            'device_type' => $agent->deviceType ?? $known?->device_type,
            'os_version' => $info['os_version'] ?? $agent?->osLabel(),
            'app_version' => $info['app_version'] ?? null,
            'device_name' => $info['device_name'] ?? $agent?->label(),
            'ip_address' => $ip,
            'user_agent' => $info['user_agent'] ?? $known?->user_agent,
            // Lokasi disimpan per IP; cari ulang hanya bila IP berubah atau belum dapat.
            'location' => $known?->ip_address === $ip && $known?->location !== null
                ? $known->location
                : $this->locations->locate($ip),
            'last_active_at' => now(),
            ...$extra,
        ]);
    }
}
