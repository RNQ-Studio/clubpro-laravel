<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\Google\GoogleIdentity;
use App\Services\Auth\Google\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\ClientRepository;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OwnerSuperAdminMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'ramadhanrp.developer@gmail.com';

    public function test_akun_belum_ada_dibuat_sebagai_super_admin(): void
    {
        $this->grant();

        $user = User::query()->where('email', self::EMAIL)->sole();
        $this->assertTrue($user->hasRole('super-admin'));
        $this->assertTrue($user->is_active);
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_peran_dibuat_bila_seeder_belum_pernah_jalan(): void
    {
        $this->assertSame(0, Role::query()->where('name', 'super-admin')->count());

        $this->grant();

        $this->assertSame(1, Role::query()->where('name', 'super-admin')->count());
    }

    public function test_idempoten(): void
    {
        $this->grant();
        $password = User::query()->where('email', self::EMAIL)->sole()->password;

        $this->grant();

        $user = User::query()->where('email', self::EMAIL)->sole();
        $this->assertSame($password, $user->password);
        $this->assertCount(1, $user->roles);
    }

    public function test_akun_terverifikasi_yang_sudah_ada_dipertahankan_apa_adanya(): void
    {
        $user = User::factory()->create(['email' => self::EMAIL, 'name' => 'Ramadhan', 'password' => 'rahasia-lama', 'is_active' => false]);
        $user->assignRole(Role::findOrCreate('staff', 'web'));

        $this->grant();

        $user->refresh();
        $this->assertTrue($user->hasRole('super-admin'));
        $this->assertTrue($user->hasRole('staff'));
        $this->assertTrue($user->is_active);
        $this->assertSame('Ramadhan', $user->name);
        $this->assertTrue(Hash::check('rahasia-lama', $user->password));
    }

    public function test_email_huruf_besar_tetap_ditemukan(): void
    {
        User::factory()->create(['email' => 'RamadhanRP.Developer@gmail.com']);

        $this->grant();

        $this->assertSame(1, User::query()->whereRaw('lower(email) = ?', [self::EMAIL])->count());
        $this->assertTrue(User::query()->whereRaw('lower(email) = ?', [self::EMAIL])->sole()->hasRole('super-admin'));
    }

    public function test_akun_belum_terverifikasi_kehilangan_password_dan_sesi_sebelum_diberi_peran(): void
    {
        // Siapa pun bisa POST /auth/register memakai email ini tanpa membuktikan kepemilikan.
        $client = app(ClientRepository::class)->createPasswordGrantClient('Test Password Grant', 'users', true);
        $penyerobot = User::factory()->create(['email' => self::EMAIL, 'email_verified_at' => null, 'password' => 'password-penyerobot']);
        DB::table('oauth_access_tokens')->insert([
            'id' => 'token-penyerobot', 'user_id' => $penyerobot->id, 'client_id' => $client->id,
            'revoked' => false, 'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addHour(),
        ]);

        $this->grant();

        $penyerobot->refresh();
        $this->assertFalse(Hash::check('password-penyerobot', $penyerobot->password));
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => 'token-penyerobot', 'revoked' => true]);
        $this->assertTrue($penyerobot->hasRole('super-admin'));
    }

    public function test_login_google_pertama_masuk_ke_akun_super_admin(): void
    {
        $client = app(ClientRepository::class)->createPasswordGrantClient('Test Password Grant', 'users', true);
        config([
            'passport.password_client.id' => $client->id,
            'passport.password_client.secret' => $client->plainSecret,
        ]);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->app->instance(GoogleIdTokenVerifier::class, new class implements GoogleIdTokenVerifier
        {
            public function verify(string $idToken): GoogleIdentity
            {
                return new GoogleIdentity('sub-owner', 'ramadhanrp.developer@gmail.com', 'Ramadhan');
            }
        });

        $this->grant();

        $token = $this->postJson('/api/v1/auth/google', ['id_token' => 'valid'])->assertOk()->json('data.access_token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.roles.0', 'super-admin');
        $this->withToken($token)->getJson('/api/v1/admin/users')->assertOk();
    }

    private function grant(): void
    {
        $migration = require database_path('migrations/2026_11_05_100001_grant_super_admin_to_owner.php');
        $migration->grant();
    }
}
