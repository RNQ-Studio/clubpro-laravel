<?php

namespace Tests\Feature\Finance;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** Exercise the real route middleware rather than calling the entitlement resolver alone. */
class EntitlementRecoveryAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-12-10 12:00:00', 'Asia/Jakarta'));
    }

    public function test_expired_member_can_read_card_top_up_and_pay_but_cannot_read_internal_roster(): void
    {
        $community = $this->community();
        $user = User::factory()->create();
        CommunityMember::factory()->for($community)->for($user)->create(['joined_at' => now()->subMonths(2)]);
        Passport::actingAs($user);
        $base = '/api/v1/communities/'.$community->id;

        $this->getJson($base.'/members')->assertStatus(402)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED')->assertJsonPath('meta.scope', 'member');
        $this->getJson($base)->assertOk();
        $this->getJson($base.'/card')->assertOk()->assertJsonPath('data.validity.status', 'expired');
        $this->getJson($base.'/billing?scope=member')->assertOk()->assertJsonPath('data.entitlement.active', false);
        $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.available', 0);
        $this->getJson($base.'/receipts')->assertOk();
        $this->postJson($base.'/topups', [])->assertUnprocessable();
        $this->postJson($base.'/billing/pay', ['scope' => 'member', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson($base.'/members')->assertStatus(402);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_expiration_does_not_block_another_valid_membership_or_allow_route_spoofing(): void
    {
        $expired = $this->community();
        $valid = $this->community();
        $user = User::factory()->create();
        CommunityMember::factory()->for($expired)->for($user)->create(['joined_at' => now()->subMonths(2)]);
        CommunityMember::factory()->for($valid)->for($user)->create();
        Passport::actingAs($user);

        $this->getJson('/api/v1/communities/'.$expired->id.'/members?community_id='.$valid->id)->assertStatus(402);
        $summary = $this->getJson('/api/v1/communities/'.$valid->id.'/billing?scope=member');
        $this->assertTrue((bool) $summary->json('data.entitlement.active'), (string) $summary->getContent());
        $response = $this->getJson('/api/v1/communities/'.$valid->id.'/members');
        $this->assertSame(200, $response->status(), (string) $response->getContent());
        $this->getJson('/api/v1/communities/joined')->assertOk();
    }

    public function test_expired_platform_keeps_founder_payment_and_verification_reachable(): void
    {
        $community = $this->community(['created_at' => now()->subMonths(2)]);
        $owner = $community->owner;
        $this->assertNotNull($owner);
        Passport::actingAs($owner);
        $base = '/api/v1/communities/'.$community->id;

        $this->putJson($base, [])->assertStatus(402)->assertJsonPath('meta.scope', 'platform');
        $this->getJson($base.'/billing?scope=platform')->assertOk()->assertJsonPath('data.entitlement.active', false);
        $this->getJson($base.'/dues')->assertOk();
        $this->getJson('/api/v1/wallet')->assertOk();
        $this->postJson($base.'/topups', [])->assertUnprocessable();
        $this->getJson($base.'/receipts')->assertOk();
    }

    public function test_guests_cannot_use_wallet_or_payment_endpoints(): void
    {
        $community = $this->community();
        $this->getJson('/api/v1/wallet')->assertUnauthorized();
        $this->getJson('/api/v1/communities/'.$community->id.'/billing')->assertUnauthorized();
        $this->postJson('/api/v1/communities/'.$community->id.'/topups', [])->assertUnauthorized();
    }

    public function test_internal_member_profile_deep_link_requires_a_valid_shared_community(): void
    {
        $expired = $this->community();
        $valid = $this->community();
        $viewer = User::factory()->create();
        $target = User::factory()->create();
        CommunityMember::factory()->for($expired)->for($viewer)->create(['joined_at' => now()->subMonths(2)]);
        CommunityMember::factory()->for($expired)->for($target)->create();
        Passport::actingAs($viewer);
        $this->getJson('/api/v1/members/'.$target->id)->assertNotFound();
        $this->getJson('/api/v1/members/'.$viewer->id)->assertOk();
        CommunityMember::factory()->for($valid)->for($viewer)->create();
        CommunityMember::factory()->for($valid)->for($target)->create();
        $this->getJson('/api/v1/members/'.$target->id)->assertOk()->assertJsonCount(1, 'data.communities')->assertJsonPath('data.communities.0.id', $valid->id);
    }

    /** @param array<string, mixed> $attributes */
    private function community(array $attributes = []): Community
    {
        return Community::factory()->approved()->create([
            'dues_amount' => 50000, 'entry_fee' => 10000,
            'dues_start_date' => '2026-01-01', ...$attributes,
        ]);
    }
}
