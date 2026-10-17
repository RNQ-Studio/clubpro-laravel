<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Auth\Google\GoogleIdentity;
use App\Services\Auth\Google\GoogleIdTokenVerifier;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $client = app(ClientRepository::class)->createPasswordGrantClient('Test Password Grant', 'users', true);
        config([
            'passport.password_client.id' => $client->id,
            'passport.password_client.secret' => $client->plainSecret,
        ]);

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_first_google_login_creates_member_and_returns_tokens(): void
    {
        $this->fakeGoogle(new GoogleIdentity('google-sub-1', 'rina@gmail.com', 'Rina Pemanah', 'https://lh3.googleusercontent.com/a/rina'));

        $response = $this->postJson('/api/v1/auth/google', ['id_token' => 'valid-token'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_new_user', true)
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'token_type', 'expires_in']]);

        $user = User::query()->where('email', 'rina@gmail.com')->sole();
        $this->assertSame('google-sub-1', $user->google_id);
        $this->assertSame('Rina Pemanah', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->is_active);

        // Token yang diterbitkan bisa dipakai, dan foto Google menjadi avatar cadangan.
        $this->withToken($response->json('data.access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'rina@gmail.com')
            ->assertJsonPath('data.avatar_url', 'https://lh3.googleusercontent.com/a/rina');
    }

    public function test_returning_google_user_is_not_new_and_not_duplicated(): void
    {
        $this->fakeGoogle(new GoogleIdentity('google-sub-1', 'rina@gmail.com', 'Rina'));

        $this->postJson('/api/v1/auth/google', ['id_token' => 'valid-token'])->assertOk();
        $this->postJson('/api/v1/auth/google', ['id_token' => 'valid-token'])
            ->assertOk()
            ->assertJsonPath('data.is_new_user', false);

        $this->assertSame(1, User::query()->count());
    }

    public function test_existing_email_account_is_linked_without_changing_its_data(): void
    {
        $existing = User::factory()->create(['email' => 'budi@gmail.com', 'name' => 'Budi Santoso']);
        $this->fakeGoogle(new GoogleIdentity('google-sub-9', 'BUDI@gmail.com', 'Budi G'));

        $this->postJson('/api/v1/auth/google', ['id_token' => 'valid-token'])
            ->assertOk()
            ->assertJsonPath('data.is_new_user', false);

        $existing->refresh();
        $this->assertSame('google-sub-9', $existing->google_id);
        $this->assertSame('Budi Santoso', $existing->name);
        $this->assertSame(1, User::query()->count());
    }

    public function test_inactive_account_is_refused(): void
    {
        User::factory()->create(['email' => 'off@gmail.com', 'is_active' => false]);
        $this->fakeGoogle(new GoogleIdentity('google-sub-2', 'off@gmail.com'));

        $this->postJson('/api/v1/auth/google', ['id_token' => 'valid-token'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is inactive.');
    }

    public function test_invalid_google_token_is_unauthorized(): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, new class implements GoogleIdTokenVerifier
        {
            public function verify(string $idToken): GoogleIdentity
            {
                throw new AuthenticationException('Invalid Google token.');
            }
        });

        $this->postJson('/api/v1/auth/google', ['id_token' => 'forged'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->assertSame(0, User::query()->count());
    }

    public function test_unconfigured_google_sign_in_returns_503(): void
    {
        config(['services.google.client_ids' => []]);
        $this->app->forgetInstance(GoogleIdTokenVerifier::class);

        $this->postJson('/api/v1/auth/google', ['id_token' => 'anything'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Google sign-in is not configured.');
    }

    public function test_id_token_is_required(): void
    {
        $this->postJson('/api/v1/auth/google', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_token']);
    }

    public function test_device_info_is_recorded(): void
    {
        $this->fakeGoogle(new GoogleIdentity('google-sub-3', 'dev@gmail.com'));

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'valid-token',
            'device_id' => 'device-abc',
            'platform' => 'android',
            'app_version' => '1.1.0',
        ])->assertOk();

        $this->assertDatabaseHas('user_devices', ['device_id' => 'device-abc', 'platform' => 'android']);
    }

    private function fakeGoogle(GoogleIdentity $identity): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, new class($identity) implements GoogleIdTokenVerifier
        {
            public function __construct(private readonly GoogleIdentity $identity) {}

            public function verify(string $idToken): GoogleIdentity
            {
                if ($idToken !== 'valid-token') {
                    throw new AuthenticationException('Invalid Google token.');
                }

                return $this->identity;
            }
        });
    }
}
