<?php

namespace Tests\Feature\Api;

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
use Tests\TestCase;

class PinReauthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Notification::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        $client = app(ClientRepository::class)->createPasswordGrantClient('PIN reauthentication test', 'users', true);
        config(['passport.password_client.id' => $client->id, 'passport.password_client.secret' => $client->plainSecret]);
    }

    public function test_password_resets_existing_pin_without_mail_and_preserves_only_current_session(): void
    {
        $user = $this->user();
        $other = $this->passwordLogin($user);
        $current = $this->passwordLogin($user);
        $this->app->instance('env', 'production');
        config(['mail.default' => 'log']);
        $this->postJson('/api/v1/auth/pin/recovery', ['identifier' => $user->email])->assertStatus(503);
        $this->authenticate($current);
        $this->putJson('/api/v1/auth/pin', ['pin' => '592317', 'pin_confirmation' => '592317', 'verification_method' => 'password', 'current_password' => 'password'])->assertOk();
        $this->assertTrue(Hash::check('592317', $user->refresh()->pin_hash));
        $this->authenticate($current);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.pin_google_reauthenticated', false);
        $this->authenticate($other);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame(1, DB::table('oauth_access_tokens')->where('user_id', $user->id)->where('revoked', false)->count());
        Notification::assertNothingSent();
    }

    public function test_recent_verified_google_proof_is_bound_to_bearer_single_use_and_does_not_require_mail(): void
    {
        $user = $this->user();
        $ordinary = $this->passwordLogin($user);
        $google = $this->googleLogin($user);
        $this->authenticate($ordinary);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.pin_google_reauthenticated', false);
        $this->putJson('/api/v1/auth/pin', $this->googleBody())->assertUnprocessable();
        $this->authenticate($google);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.pin_google_reauthenticated', true);
        $this->putJson('/api/v1/auth/pin', $this->googleBody())->assertOk();
        $this->authenticate($google);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.pin_google_reauthenticated', false);
        $this->putJson('/api/v1/auth/pin', $this->googleBody())->assertUnprocessable();
        $this->authenticate($ordinary);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertTrue(Hash::check('592317', $user->refresh()->pin_hash));
    }

    public function test_google_proof_expires_after_ten_minutes_and_normal_pin_login_does_not_grant_it(): void
    {
        $user = $this->user();
        $google = $this->googleLogin($user);
        $this->travel(11)->minutes();
        $this->authenticate($google);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.pin_google_reauthenticated', false);
        $this->putJson('/api/v1/auth/pin', $this->googleBody())->assertUnprocessable();
        $pin = $this->postJson('/api/v1/auth/pin/login', ['identifier' => $user->email, 'pin' => '731942'])->assertOk()->json('data.access_token');
        $this->authenticate($pin);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.pin_google_reauthenticated', false);
        $this->putJson('/api/v1/auth/pin', $this->googleBody())->assertUnprocessable();
    }

    public function test_wrong_current_pin_never_falls_back_to_recent_google_proof(): void
    {
        $user = $this->user();
        $google = $this->googleLogin($user);
        $this->authenticate($google);
        $this->putJson('/api/v1/auth/pin', ['pin' => '592317', 'pin_confirmation' => '592317', 'current_pin' => '000000'])->assertUnprocessable();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.pin_google_reauthenticated', true);
        $this->assertTrue(Hash::check('731942', $user->refresh()->pin_hash));
        $this->putJson('/api/v1/auth/pin', $this->googleBody())->assertOk();
    }

    public function test_google_proof_is_not_transferable_to_another_user_or_refreshed_token(): void
    {
        $user = $this->user();
        $google = $this->googleLogin($user, returnTokens: true);
        $other = $this->user('other');
        $this->authenticate($this->passwordLogin($other));
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.pin_google_reauthenticated', false);
        $this->putJson('/api/v1/auth/pin', $this->googleBody())->assertUnprocessable();
        $refreshed = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $google['refresh_token']])->assertOk()->json('data.access_token');
        $this->authenticate($refreshed);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.pin_google_reauthenticated', false);
        $this->putJson('/api/v1/auth/pin', $this->googleBody())->assertUnprocessable();
    }

    public function test_password_or_google_recovery_invalidates_outstanding_email_recovery(): void
    {
        $user = $this->user();
        DB::table('pin_recoveries')->insert(['user_id' => $user->id, 'code_hash' => Hash::make('731942'), 'email_hash' => hash('sha256', $user->email), 'expires_at' => now()->addMinutes(10), 'attempts' => 0]);
        $this->authenticate($this->passwordLogin($user));
        $this->putJson('/api/v1/auth/pin', ['pin' => '592317', 'pin_confirmation' => '592317', 'current_password' => 'password'])->assertOk();
        $this->assertNotNull(DB::table('pin_recoveries')->where('user_id', $user->id)->value('used_at'));
        $this->assertTrue(Hash::check('592317', $user->refresh()->pin_hash));
    }

    public function test_public_pin_options_expose_capabilities_without_transport_or_identity(): void
    {
        $this->app->instance('env', 'production');
        config(['mail.default' => 'log']);
        $this->getJson('/api/v1/auth/pin/options')->assertOk()->assertExactJson(['success' => true, 'message' => 'OK', 'data' => [
            'pin_length' => 6, 'email_recovery_available' => false, 'account_recovery_available' => true, 'in_app_invitations' => true,
        ]]);
    }

    private function user(string $prefix = 'reauth'): User
    {
        $user = User::factory()->create(['email' => $prefix.'@anaklangit.manahpro.web.id']);
        $user->forceFill(['pin_hash' => Hash::make('731942')])->save();

        return $user;
    }

    private function passwordLogin(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->json('data.access_token');
    }

    /** @return string|array<string, mixed> */
    private function googleLogin(User $user, bool $returnTokens = false): string|array
    {
        $identity = new GoogleIdentity('google-'.$user->id, $user->email);
        $this->app->instance(GoogleIdTokenVerifier::class, new class($identity) implements GoogleIdTokenVerifier
        {
            public function __construct(private readonly GoogleIdentity $identity) {}

            public function verify(string $idToken): GoogleIdentity
            {
                return $this->identity;
            }
        });
        $tokens = $this->postJson('/api/v1/auth/google', ['id_token' => 'verified-id-token'])->assertOk()->json('data');

        return $returnTokens ? $tokens : $tokens['access_token'];
    }

    private function authenticate(string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($token);
    }

    /** @return array<string, string> */
    private function googleBody(): array
    {
        return ['pin' => '592317', 'pin_confirmation' => '592317', 'verification_method' => 'google'];
    }
}
