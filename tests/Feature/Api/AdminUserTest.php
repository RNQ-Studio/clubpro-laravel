<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserDevice;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['name' => 'Pemilik', 'email' => 'pemilik@gmail.com']);
        $this->admin->assignRole('super-admin');
    }

    /** @return array<string, array{string, string}> */
    public static function endpoints(): array
    {
        return [
            'daftar' => ['GET', '/api/v1/admin/users'],
            'detail' => ['GET', '/api/v1/admin/users/{id}'],
            'super admin' => ['PUT', '/api/v1/admin/users/{id}/super-admin'],
            'status' => ['PUT', '/api/v1/admin/users/{id}/status'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_tanpa_login_ditolak(string $method, string $path): void
    {
        $this->json($method, $this->path($path))->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_anggota_biasa_ditolak(string $method, string $path): void
    {
        Passport::actingAs(User::factory()->create());

        $this->json($method, $this->path($path), ['enabled' => true, 'is_active' => true])->assertForbidden();
    }

    #[DataProvider('endpoints')]
    public function test_role_admin_dan_staff_ditolak(string $method, string $path): void
    {
        foreach (['admin', 'staff'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            Passport::actingAs($user);

            $this->json($method, $this->path($path), ['enabled' => true, 'is_active' => true])->assertForbidden();
        }
    }

    public function test_anggota_biasa_tidak_bisa_mengangkat_dirinya_sendiri(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->putJson("/api/v1/admin/users/{$user->id}/super-admin", ['enabled' => true])->assertForbidden();

        $this->assertFalse($user->refresh()->hasRole('super-admin'));
    }

    public function test_peran_yang_dicabut_langsung_berlaku(): void
    {
        $other = User::factory()->create();
        $other->assignRole('super-admin');
        Passport::actingAs($other);
        $this->getJson('/api/v1/admin/users')->assertOk();

        $other->removeRole('super-admin');
        Passport::actingAs($other->refresh());

        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_daftar_memuat_ringkasan_paginasi_dan_perangkat_terakhir(): void
    {
        $user = User::factory()->create(['last_seen_at' => now()->subDay(), 'last_login_at' => now()->subDays(2)]);
        User::factory()->create(['is_active' => false, 'last_seen_at' => now()->subDays(30)]);
        $this->device($user, ['device_name' => 'Lama', 'last_active_at' => now()->subDays(5)]);
        $this->device($user, ['device_name' => 'Chrome 130 · macOS', 'location' => 'Bandung, West Java, Indonesia', 'last_active_at' => now()->subDay()]);
        Passport::actingAs($this->admin);

        $response = $this->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.summary.total', 3)
            ->assertJsonPath('meta.summary.super_admins', 1)
            ->assertJsonPath('meta.summary.inactive', 1);

        // Akses pemilik sendiri ikut tercatat oleh request ini, sesudah ringkasan dihitung.
        $this->assertSame(1, $response->json('meta.summary.active_last_7_days'));

        $row = collect($response->json('data'))->firstWhere('id', $user->id);
        $this->assertSame('Chrome 130 · macOS', $row['latest_device']['device_name']);
        $this->assertSame('Bandung, West Java, Indonesia', $row['latest_device']['location']);
        $this->assertFalse($row['is_super_admin']);
        $this->assertArrayNotHasKey('push_token', $row['latest_device']);
        $this->assertArrayNotHasKey('password', $row);
    }

    public function test_urutan_paling_baru_akses_di_atas_yang_belum_tercatat_di_bawah(): void
    {
        $belum = User::factory()->create();
        $lama = User::factory()->create(['last_seen_at' => now()->subDays(3)]);
        $baru = User::factory()->create(['last_seen_at' => now()->subHour()]);
        $this->admin->forceFill(['last_seen_at' => now()->subDays(9)])->save();
        Passport::actingAs($this->admin);

        $ids = collect($this->getJson('/api/v1/admin/users')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$baru->id, $lama->id, $this->admin->id, $belum->id], $ids);
    }

    public function test_cari_nama_email_telepon_tanpa_peka_huruf(): void
    {
        User::factory()->create(['name' => 'Rina Ayu', 'email' => 'rina@gmail.com']);
        User::factory()->create(['name' => 'Budi', 'email' => 'budi@gmail.com', 'phone' => '081234567890']);
        Passport::actingAs($this->admin);

        foreach (['RINA' => 'Rina Ayu', 'rina@gm' => 'Rina Ayu', '0812345' => 'Budi'] as $search => $name) {
            $this->getJson('/api/v1/admin/users?search='.urlencode($search))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.name', $name);
        }
    }

    public function test_tanda_persen_di_pencarian_bukan_wildcard(): void
    {
        User::factory()->create(['name' => 'Rina Ayu']);
        Passport::actingAs($this->admin);

        $this->getJson('/api/v1/admin/users?search='.urlencode('%'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_saring_super_admin_dan_status(): void
    {
        User::factory()->create(['is_active' => false]);
        User::factory()->create();
        Passport::actingAs($this->admin);

        $this->getJson('/api/v1/admin/users?role=super-admin')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->admin->id);
        $this->getJson('/api/v1/admin/users?status=inactive')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/users?status=active')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/users?status=hapus')->assertUnprocessable();
    }

    public function test_avatar_path_lama_tidak_merusak_daftar(): void
    {
        // assets.id bertipe uuid: eager load path biasa akan gagal di PostgreSQL.
        User::factory()->create(['avatar' => 'avatars/lama.jpg']);
        Passport::actingAs($this->admin);

        $this->getJson('/api/v1/admin/users')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_jumlah_query_daftar_tidak_bertambah_per_pengguna(): void
    {
        Passport::actingAs($this->admin);
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/admin/users')->assertOk();

            return count(DB::getQueryLog());
        };

        $this->device(User::factory()->create());
        $count();
        $sedikit = $count();

        User::factory()->count(5)->create()->each(fn (User $user) => $this->device($user));

        $this->assertSame($sedikit, $count());
    }

    public function test_detail_memuat_semua_data_dan_semua_perangkat(): void
    {
        $user = User::factory()->create([
            'phone' => '081234567890',
            'domicile_province_code' => '32',
            'domicile_province_name' => 'Jawa Barat',
            'domicile_city_code' => '32.73',
            'domicile_city_name' => 'Kota Bandung',
            'nik_same_as_domicile' => true,
            'address' => 'Jl. Merdeka 10',
            'last_login_at' => now()->subDay(),
            'last_seen_at' => now()->subHour(),
        ]);
        $user->forceFill(['google_id' => 'sub-1', 'google_avatar_url' => 'https://lh3.googleusercontent.com/a/rina'])->save();
        $this->device($user, ['last_active_at' => now()->subDays(3)]);
        $this->device($user, ['ip_address' => '36.86.63.180', 'last_active_at' => now()->subHour()]);
        Passport::actingAs($this->admin);

        $this->getJson("/api/v1/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.phone', '081234567890')
            ->assertJsonPath('data.avatar_url', 'https://lh3.googleusercontent.com/a/rina')
            ->assertJsonPath('data.login_method', 'google')
            ->assertJsonPath('data.domicile_city_name', 'Kota Bandung')
            ->assertJsonPath('data.domicile_province_name', 'Jawa Barat')
            ->assertJsonPath('data.nik_same_as_domicile', true)
            ->assertJsonMissingPath('data.address')
            ->assertJsonMissingPath('data.emergency_contact_name')
            ->assertJsonCount(2, 'data.devices')
            ->assertJsonPath('data.devices.0.ip_address', '36.86.63.180')
            ->assertJsonStructure(['data' => [
                'last_seen_at', 'last_login_at', 'created_at', 'email_verified_at', 'profile_completion', 'roles',
            ]]);
    }

    public function test_pengguna_tidak_ada_404(): void
    {
        Passport::actingAs($this->admin);

        $this->getJson('/api/v1/admin/users/999999')->assertNotFound();
        $this->getJson('/api/v1/admin/users/bukan-angka')->assertNotFound();
    }

    public function test_memberi_super_admin_tercatat_dan_langsung_berlaku(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/users/{$user->id}/super-admin", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.is_super_admin', true);

        $log = Activity::query()->where('event', 'super_admin_granted')->sole();
        $this->assertTrue($this->admin->is($log->causer));
        $this->assertTrue($user->is($log->subject));

        Passport::actingAs($user->refresh());
        $this->getJson('/api/v1/admin/users')->assertOk();
    }

    public function test_memberi_dua_kali_tidak_mencatat_ganda(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/users/{$user->id}/super-admin", ['enabled' => true])->assertOk();
        $this->putJson("/api/v1/admin/users/{$user->id}/super-admin", ['enabled' => true])->assertOk();

        $this->assertSame(1, Activity::query()->where('event', 'super_admin_granted')->count());
    }

    public function test_akun_email_belum_terverifikasi_tidak_bisa_diberi_super_admin(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/users/{$user->id}/super-admin", ['enabled' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['super_admin']);

        $this->assertFalse($user->refresh()->hasRole('super-admin'));
    }

    public function test_akun_nonaktif_tidak_bisa_diberi_super_admin(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/users/{$user->id}/super-admin", ['enabled' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['super_admin']);
    }

    public function test_mencabut_super_admin_orang_lain(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/users/{$user->id}/super-admin", ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.is_super_admin', false);

        $this->assertSame(1, Activity::query()->where('event', 'super_admin_revoked')->count());
    }

    public function test_tidak_bisa_mencabut_akses_sendiri(): void
    {
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/users/{$this->admin->id}/super-admin", ['enabled' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['super_admin']);

        $this->assertTrue($this->admin->refresh()->hasRole('super-admin'));
    }

    public function test_menonaktifkan_akun_memutus_semua_sesinya(): void
    {
        $client = app(ClientRepository::class)->createPasswordGrantClient('Test Password Grant', 'users', true);
        $user = User::factory()->create();
        DB::table('oauth_access_tokens')->insert([
            'id' => 'token-rina', 'user_id' => $user->id, 'client_id' => $client->id,
            'revoked' => false, 'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addHour(),
        ]);
        DB::table('oauth_refresh_tokens')->insert([
            'id' => 'refresh-rina', 'access_token_id' => 'token-rina', 'revoked' => false, 'expires_at' => now()->addDay(),
        ]);
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/users/{$user->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertTrue(Token::query()->findOrFail('token-rina')->revoked);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['id' => 'refresh-rina', 'revoked' => true]);
        $this->assertSame(1, Activity::query()->where('event', 'user_deactivated')->count());
    }

    public function test_mengaktifkan_kembali_akun(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/users/{$user->id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_tidak_bisa_menonaktifkan_diri_sendiri(): void
    {
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/users/{$this->admin->id}/status", ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);

        $this->assertTrue($this->admin->refresh()->is_active);
    }

    public function test_isian_wajib_divalidasi(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/users/{$user->id}/super-admin", [])->assertUnprocessable();
        $this->putJson("/api/v1/admin/users/{$user->id}/status", ['is_active' => 'mungkin'])->assertUnprocessable();
    }

    private function path(string $template): string
    {
        return str_replace('{id}', (string) $this->admin->id, $template);
    }

    /** @param  array<string, mixed>  $attributes */
    private function device(User $user, array $attributes = []): UserDevice
    {
        return UserDevice::query()->create([
            'user_id' => $user->getKey(),
            'device_id' => fake()->uuid(),
            'platform' => 'web',
            'last_active_at' => now(),
            ...$attributes,
        ]);
    }
}
