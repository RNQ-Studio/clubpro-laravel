<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserDevice;
use App\Services\Auth\Google\GoogleIdentity;
use App\Services\Auth\Google\GoogleIdTokenVerifier;
use App\Services\Geo\IpLocationService;
use App\Services\OtpService;
use App\Support\Enums\OtpPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class UserActivityTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    private const IP = '36.86.63.180';

    /** @var list<string> */
    private array $lookups = [];

    protected function setUp(): void
    {
        parent::setUp();

        $client = app(ClientRepository::class)->createPasswordGrantClient('Test Password Grant', 'users', true);
        config([
            'passport.password_client.id' => $client->id,
            'passport.password_client.secret' => $client->plainSecret,
        ]);

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->fakeLocations();
    }

    public function test_login_password_mencatat_waktu_ip_asli_dan_perangkat(): void
    {
        $user = User::factory()->create(['email' => 'rina@gmail.com']);

        $this->fromBrowser()->postJson('/api/v1/auth/login', [
            'email' => 'rina@gmail.com',
            'password' => 'password',
        ])->assertOk();

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertNotNull($user->last_seen_at);

        // Bukan IP/UA request internal /oauth/token (127.0.0.1 / "Symfony").
        $device = UserDevice::query()->where('user_id', $user->getKey())->sole();
        $this->assertSame(self::IP, $device->ip_address);
        $this->assertSame(self::CHROME_MAC, $device->user_agent);
        $this->assertSame('web', $device->platform->value);
        $this->assertSame('desktop', $device->device_type);
        $this->assertSame('Chrome 130 · macOS', $device->device_name);
        $this->assertSame('macOS', $device->os_version);
        $this->assertSame('Bandung, West Java, Indonesia', $device->location);
    }

    public function test_login_google_mencatat_aktivitas(): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, new class implements GoogleIdTokenVerifier
        {
            public function verify(string $idToken): GoogleIdentity
            {
                return new GoogleIdentity('sub-1', 'rina@gmail.com', 'Rina');
            }
        });

        $this->fromBrowser()->postJson('/api/v1/auth/google', ['id_token' => 'valid'])->assertOk();

        $user = User::query()->where('email', 'rina@gmail.com')->sole();
        $this->assertNotNull($user->last_login_at);
        $this->assertSame(self::IP, $user->latestDevice?->ip_address);
    }

    public function test_login_otp_mencatat_aktivitas(): void
    {
        $user = User::factory()->create(['phone' => '081234567890']);
        $this->mock(OtpService::class)->shouldReceive('verify')->andReturnTrue();

        $this->fromBrowser()->postJson('/api/v1/auth/otp/verify', [
            'phone' => '081234567890',
            'code' => '123456',
            'purpose' => OtpPurpose::Login->value,
        ])->assertOk();

        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertSame(self::IP, $user->latestDevice?->ip_address);
    }

    public function test_akses_api_mencatat_terakhir_akses_tanpa_mengubah_data_anggota(): void
    {
        $user = User::factory()->create(['updated_at' => now()->subDay()]);
        $updatedAt = $user->refresh()->updated_at;
        Activity::query()->delete();
        Passport::actingAs($user);

        $this->fromBrowser()->getJson('/api/v1/auth/me')->assertOk();

        $user->refresh();
        $this->assertNotNull($user->last_seen_at);
        $this->assertNull($user->last_login_at);
        $this->assertTrue($updatedAt?->equalTo($user->updated_at));
        $this->assertSame(0, Activity::query()->count());
        $this->assertSame(self::IP, $user->latestDevice?->ip_address);
    }

    public function test_akses_dicatat_paling_sering_tiap_lima_menit(): void
    {
        Carbon::setTestNow('2026-11-05 10:00:00');
        $user = User::factory()->create();

        Passport::actingAs($user);
        $this->fromBrowser()->getJson('/api/v1/auth/me')->assertOk();

        Carbon::setTestNow('2026-11-05 10:04:00');
        Passport::actingAs($user->refresh());
        $this->fromBrowser()->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame('10:00:00', $user->refresh()->last_seen_at?->format('H:i:s'));

        Carbon::setTestNow('2026-11-05 10:06:00');
        Passport::actingAs($user->refresh());
        $this->fromBrowser()->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame('10:06:00', $user->refresh()->last_seen_at?->format('H:i:s'));
    }

    public function test_klien_bukan_browser_tetap_tercatat_akses_tanpa_baris_perangkat(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->withHeader('User-Agent', 'Dart/3.5 (dart:io)')->getJson('/api/v1/auth/me')->assertOk();

        $this->assertNotNull($user->refresh()->last_seen_at);
        $this->assertDatabaseCount('user_devices', 0);
    }

    public function test_pembaruan_browser_memperbarui_perangkat_yang_sama(): void
    {
        $user = User::factory()->create(['email' => 'rina@gmail.com']);
        $credentials = ['email' => 'rina@gmail.com', 'password' => 'password'];

        $this->fromBrowser()->postJson('/api/v1/auth/login', $credentials)->assertOk();
        $this->fromBrowser(str_replace('Chrome/130', 'Chrome/131', self::CHROME_MAC))
            ->postJson('/api/v1/auth/login', $credentials)->assertOk();

        $this->assertSame('Chrome 131 · macOS', UserDevice::query()->where('user_id', $user->getKey())->sole()->device_name);
    }

    public function test_lokasi_dicari_ulang_hanya_saat_ip_berubah(): void
    {
        User::factory()->create(['email' => 'rina@gmail.com']);
        $credentials = ['email' => 'rina@gmail.com', 'password' => 'password'];

        $this->fromBrowser()->postJson('/api/v1/auth/login', $credentials)->assertOk();
        $this->fromBrowser()->postJson('/api/v1/auth/login', $credentials)->assertOk();
        $this->assertSame([self::IP], $this->lookups);

        $this->fromBrowser(ip: '114.124.200.1')->postJson('/api/v1/auth/login', $credentials)->assertOk();
        $this->assertSame([self::IP, '114.124.200.1'], $this->lookups);
    }

    public function test_akses_tidak_menghapus_push_token(): void
    {
        $user = User::factory()->create(['email' => 'rina@gmail.com']);
        $this->fromBrowser()->postJson('/api/v1/auth/login', [
            'email' => 'rina@gmail.com',
            'password' => 'password',
            'push_token' => 'fcm-token',
        ])->assertOk();

        $this->travel(10)->minutes();
        Passport::actingAs($user->refresh());
        $this->fromBrowser()->getJson('/api/v1/auth/me')->assertOk();

        $this->assertSame('fcm-token', UserDevice::query()->where('user_id', $user->getKey())->sole()->push_token);
    }

    public function test_kegagalan_pencatatan_tidak_menggagalkan_login_maupun_request(): void
    {
        $this->app->instance(IpLocationService::class, new class('') extends IpLocationService
        {
            public function locate(?string $ip): ?string
            {
                throw new RuntimeException('database lokasi rusak');
            }
        });
        $user = User::factory()->create(['email' => 'rina@gmail.com']);

        $this->fromBrowser()->postJson('/api/v1/auth/login', [
            'email' => 'rina@gmail.com',
            'password' => 'password',
        ])->assertOk();

        $this->travel(10)->minutes();
        Passport::actingAs($user->refresh());
        $this->fromBrowser()->getJson('/api/v1/auth/me')->assertOk();
    }

    private function fromBrowser(string $userAgent = self::CHROME_MAC, string $ip = self::IP): static
    {
        return $this->withHeader('User-Agent', $userAgent)->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function fakeLocations(): void
    {
        $lookups = &$this->lookups;
        $this->app->instance(IpLocationService::class, new class('', $lookups) extends IpLocationService
        {
            /** @param  list<string>  $lookups */
            public function __construct(string $path, private array &$lookups)
            {
                parent::__construct($path);
            }

            public function locate(?string $ip): ?string
            {
                $this->lookups[] = (string) $ip;

                return 'Bandung, West Java, Indonesia';
            }
        });
    }
}
