<?php

namespace Tests\Feature\Api;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\User;
use App\Notifications\PinRecoveryNotification;
use App\Support\Enums\CommunityMemberRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PinAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Notification::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        $client = app(ClientRepository::class)->createPasswordGrantClient('PIN test', 'users', true);
        config(['passport.password_client.id' => $client->id, 'passport.password_client.secret' => $client->plainSecret]);
    }

    public function test_all_roles_use_identifier_and_same_pin_without_global_uniqueness(): void
    {
        foreach (CommunityMemberRole::cases() as $role) {
            $user = $this->user($role->value);
            $community = Community::factory()->approved()->create();
            CommunityMember::factory()->for($community)->for($user)->create(['role' => $role]);
            $this->postJson('/api/v1/auth/pin/login', ['identifier' => strtoupper($user->email), 'pin' => '731942'])
                ->assertOk()->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'expires_in']]);
            $this->assertStringNotContainsString('pin_hash', json_encode($user->toArray()));
        }
        $this->assertStringNotContainsString('731942', Activity::query()->get()->toJson());
    }

    public function test_pin_failures_are_uniform_and_limited_to_five_for_fifteen_minutes(): void
    {
        $user = $this->user();
        $known = $this->postJson('/api/v1/auth/pin/login', ['identifier' => $user->email, 'pin' => '000000'])->assertUnauthorized()->json();
        $unknown = $this->postJson('/api/v1/auth/pin/login', ['identifier' => 'absent@anaklangit.manahpro.web.id', 'pin' => '000000'])->assertUnauthorized()->json();
        $this->assertSame($known, $unknown);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/pin/login', ['identifier' => $user->email, 'pin' => '000000'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/pin/login', ['identifier' => $user->email, 'pin' => '731942'])->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(16)->minutes();
        $this->postJson('/api/v1/auth/pin/login', ['identifier' => $user->email, 'pin' => '731942'])->assertOk();
    }

    public function test_pin_requires_verified_identifier_and_active_account(): void
    {
        $user = $this->user();
        $user->forceFill(['email_verified_at' => null, 'phone' => '081122334455', 'phone_verified_at' => null])->save();
        foreach ([$user->email, $user->phone] as $identifier) {
            $this->postJson('/api/v1/auth/pin/login', ['identifier' => $identifier, 'pin' => '731942'])->assertUnauthorized();
        }
        $user->forceFill(['phone_verified_at' => now()])->save();
        $this->postJson('/api/v1/auth/pin/login', ['identifier' => $user->phone, 'pin' => '731942'])->assertOk();
        $user->update(['is_active' => false]);
        $this->postJson('/api/v1/auth/pin/login', ['identifier' => $user->phone, 'pin' => '731942'])->assertUnauthorized();
    }

    public function test_enroll_change_and_numeric_validation_preserve_old_login(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);
        $url = '/api/v1/auth/pin';
        $this->putJson($url, ['pin' => '12345x', 'pin_confirmation' => '12345x', 'current_password' => 'password'])->assertUnprocessable();
        $this->putJson($url, ['pin' => '731942', 'pin_confirmation' => '731942'])->assertUnprocessable();
        $this->putJson($url, ['pin' => '731942', 'pin_confirmation' => '731942', 'current_password' => 'password'])->assertOk();
        $this->assertTrue(Hash::check('731942', $user->refresh()->pin_hash));
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.pin_enabled', true)->assertJsonMissingPath('data.pin_hash');
        $this->putJson($url, ['pin' => '592317', 'pin_confirmation' => '592317', 'current_pin' => '000000'])->assertUnprocessable();
        $this->putJson($url, ['pin' => '592317', 'pin_confirmation' => '592317', 'current_pin' => '731942'])->assertOk();
        $this->assertTrue(Hash::check('592317', $user->refresh()->pin_hash));
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_recovery_is_generic_single_use_expires_and_revokes_sessions(): void
    {
        $user = $this->user();
        $this->postJson('/api/v1/auth/pin/login', ['identifier' => $user->email, 'pin' => '731942'])->assertOk();
        $known = $this->postJson('/api/v1/auth/pin/recovery', ['identifier' => $user->email])->assertOk()->json();
        $unknown = $this->postJson('/api/v1/auth/pin/recovery', ['identifier' => 'absent@anaklangit.manahpro.web.id'])->assertOk()->json();
        $this->assertSame($known, $unknown);
        $code = Notification::sent($user, PinRecoveryNotification::class)->last()->code;
        $body = ['identifier' => $user->email, 'code' => $code, 'pin' => '592317', 'pin_confirmation' => '592317'];
        $this->postJson('/api/v1/auth/pin/reset', $body)->assertOk();
        $this->postJson('/api/v1/auth/pin/reset', $body)->assertUnprocessable();
        $this->assertDatabaseMissing('oauth_access_tokens', ['user_id' => $user->id, 'revoked' => false]);
        $this->postJson('/api/v1/auth/pin/login', ['identifier' => $user->email, 'pin' => '592317'])->assertOk();
        $this->postJson('/api/v1/auth/pin/recovery', ['identifier' => $user->email])->assertOk();
        $code = Notification::sent($user, PinRecoveryNotification::class)->last()->code;
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/auth/pin/reset', [...$body, 'code' => $code])->assertUnprocessable();
    }

    public function test_five_wrong_recovery_attempts_burn_the_code(): void
    {
        $user = $this->user();
        $this->postJson('/api/v1/auth/pin/recovery', ['identifier' => $user->email])->assertOk();
        $code = Notification::sent($user, PinRecoveryNotification::class)->last()->code;
        $body = ['identifier' => $user->email, 'code' => '000000', 'pin' => '592317', 'pin_confirmation' => '592317'];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/pin/reset', $body)->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/pin/reset', [...$body, 'code' => $code])->assertUnprocessable();
        $this->assertSame(5, DB::table('pin_recoveries')->where('user_id', $user->id)->value('attempts'));
    }

    public function test_email_and_phone_share_account_throttle_and_global_ip_limit(): void
    {
        $user = $this->user();
        $user->forceFill(['phone' => '081122334455', 'phone_verified_at' => now()])->save();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/pin/login', ['identifier' => $i % 2 === 0 ? $user->email : $user->phone, 'pin' => '000000'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/pin/login', ['identifier' => $user->phone, 'pin' => '731942'])->assertStatus(429);
        Cache::flush();
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/v1/auth/pin/login', ['identifier' => 'absent'.$i.'@anaklangit.manahpro.web.id', 'pin' => '000000'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/pin/login', ['identifier' => $user->email, 'pin' => '731942'])->assertStatus(429);
    }

    public function test_production_recovery_never_falls_back_to_log_mailer(): void
    {
        $this->app->instance('env', 'production');
        config(['mail.default' => 'failover', 'mail.mailers.failover.transport' => 'failover', 'mail.mailers.failover.mailers' => ['smtp', 'log']]);
        $this->postJson('/api/v1/auth/pin/recovery', ['identifier' => 'absent@anaklangit.manahpro.web.id'])->assertStatus(503);
        Notification::assertNothingSent();
    }

    private function user(string $prefix = 'pin'): User
    {
        $user = User::factory()->create(['email' => $prefix.'@anaklangit.manahpro.web.id']);
        $user->forceFill(['pin_hash' => Hash::make('731942')])->save();

        return $user;
    }
}
