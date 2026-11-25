<?php

namespace Tests\Feature\Api;

use App\Imports\UsersImport;
use App\Models\User;
use App\Services\Auth\Google\GoogleIdentity;
use App\Services\Auth\Google\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AccountEmailIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Notification::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        $client = app(ClientRepository::class)->createPasswordGrantClient('Email identity test', 'users', true);
        config(['passport.password_client.id' => $client->id, 'passport.password_client.secret' => $client->plainSecret]);
    }

    public function test_registration_normalizes_email_and_rejects_case_variant_legacy_account(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Anggota', 'email' => 'ANGGOTA@anaklangit.manahpro.web.id', 'password' => 'secret-password', 'password_confirmation' => 'secret-password'])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'anggota@anaklangit.manahpro.web.id']);
        $legacy = User::factory()->create();
        DB::table('users')->where('id', $legacy->id)->update(['email' => 'LAMA@anaklangit.manahpro.web.id']);
        $this->postJson('/api/v1/auth/register', ['name' => 'Duplikat', 'email' => 'lama@anaklangit.manahpro.web.id', 'password' => 'secret-password', 'password_confirmation' => 'secret-password'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseHas('users', ['id' => $legacy->id, 'email' => 'LAMA@anaklangit.manahpro.web.id']);
    }

    public function test_profile_email_case_variant_is_unique_and_new_email_loses_old_verification(): void
    {
        $other = User::factory()->create();
        DB::table('users')->where('id', $other->id)->update(['email' => 'MILIK@anaklangit.manahpro.web.id']);
        $user = User::factory()->create();
        Passport::actingAs($user);
        $this->putJson('/api/v1/auth/me', ['email' => 'milik@anaklangit.manahpro.web.id'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->putJson('/api/v1/auth/me', ['email' => 'BARU@anaklangit.manahpro.web.id'])->assertOk()->assertJsonPath('data.email', 'baru@anaklangit.manahpro.web.id')->assertJsonPath('data.email_verified_at', null);
    }

    public function test_ambiguous_legacy_email_cannot_choose_account_for_pin_google_or_import(): void
    {
        [$first, $second] = $this->ambiguous();
        $first->forceFill(['pin_hash' => Hash::make('731942')])->save();
        $this->postJson('/api/v1/auth/pin/login', ['identifier' => $first->email, 'pin' => '731942'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/pin/recovery', ['identifier' => $first->email])->assertOk();
        Notification::assertNothingSent();
        $this->fakeGoogle(new GoogleIdentity('new-subject', $first->email));
        $this->postJson('/api/v1/auth/google', ['id_token' => 'verified'])->assertUnauthorized();
        $import = new UsersImport;
        $import->model(['name' => 'Tidak boleh tertimpa', 'email' => $first->email]);
        $this->assertSame(1, $import->getSummary()['skipped']);
        $this->assertNotSame('Tidak boleh tertimpa', $first->refresh()->name);
        $this->assertNotSame('Tidak boleh tertimpa', $second->refresh()->name);
        $this->assertSame(2, User::query()->count());
    }

    public function test_legacy_exact_password_login_remains_available_and_new_accounts_accept_email_case(): void
    {
        [$first, $second] = $this->ambiguous();
        foreach ([$first, $second] as $user) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        }
        $user = User::factory()->create(['email' => 'modern@anaklangit.manahpro.web.id']);
        $this->postJson('/api/v1/auth/login', ['email' => strtoupper($user->email), 'password' => 'password'])->assertOk();
    }

    public function test_google_subject_does_not_verify_an_unrelated_changed_profile_email(): void
    {
        $user = User::factory()->create(['email' => 'changed@anaklangit.manahpro.web.id']);
        $user->forceFill(['google_id' => 'bound-subject', 'email_verified_at' => null])->save();
        $this->fakeGoogle(new GoogleIdentity('bound-subject', 'original@anaklangit.manahpro.web.id'));
        $this->postJson('/api/v1/auth/google', ['id_token' => 'verified'])->assertOk()->assertJsonPath('data.email_verified', false);
        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_case_variant_import_updates_exactly_one_legacy_account_without_duplicate(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['email' => 'IMPORT@anaklangit.manahpro.web.id']);
        $import = new UsersImport;
        $import->model(['name' => 'Nama diperbarui', 'email' => 'import@anaklangit.manahpro.web.id']);
        $this->assertSame(1, $import->getSummary()['imported']);
        $this->assertSame('Nama diperbarui', $user->refresh()->name);
        $this->assertSame(1, User::query()->count());
    }

    /** @return array{User, User} */
    private function ambiguous(): array
    {
        $first = User::factory()->create(['email' => 'legacy@anaklangit.manahpro.web.id']);
        $second = User::factory()->create();
        DB::table('users')->where('id', $second->id)->update(['email' => 'LEGACY@anaklangit.manahpro.web.id']);

        return [$first, $second->refresh()];
    }

    private function fakeGoogle(GoogleIdentity $identity): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, new class($identity) implements GoogleIdTokenVerifier
        {
            public function __construct(private readonly GoogleIdentity $identity) {}

            public function verify(string $idToken): GoogleIdentity
            {
                return $this->identity;
            }
        });
    }
}
