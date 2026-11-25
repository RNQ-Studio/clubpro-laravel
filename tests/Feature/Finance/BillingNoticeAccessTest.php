<?php

namespace Tests\Feature\Finance;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

class BillingNoticeAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00', 'Asia/Jakarta'));
    }

    public function test_h_minus_three_reminder_is_visible_only_to_recipient_and_read_is_idempotent(): void
    {
        $community = Community::factory()->approved()->create();
        $member = CommunityMember::factory()->for($community)->create();
        $this->travelTo(CarbonImmutable::parse('2026-12-05 00:00:00', 'Asia/Jakarta'));
        $this->artisan('billing:process')->assertSuccessful();
        $this->artisan('billing:process')->assertSuccessful();
        Passport::actingAs($member->user);
        $response = $this->getJson('/api/v1/billing-notices')->assertOk()->assertJsonPath('data.unread_count', 1)->assertJsonCount(1, 'data.items');
        $id = $response->json('data.items.0.id');
        $response->assertJsonPath('data.items.0.type', 'expires_soon')->assertJsonPath('data.items.0.community_id', $community->id);
        $this->postJson('/api/v1/billing-notices/'.$id.'/read')->assertOk();
        $this->postJson('/api/v1/billing-notices/'.$id.'/read')->assertOk();
        $this->getJson('/api/v1/billing-notices')->assertOk()->assertJsonPath('data.unread_count', 0);
        Passport::actingAs($community->owner);
        $this->postJson('/api/v1/billing-notices/'.$id.'/read')->assertNotFound();
    }

    public function test_admin_receives_three_month_warning_about_another_member_in_own_feed(): void
    {
        $community = Community::factory()->approved()->create(['dues_amount' => 50000]);
        $user = User::factory()->create();
        $member = CommunityMember::factory()->for($community)->for($user)->create(['joined_at' => '2026-09-01 00:00:00']);
        $this->travelTo(CarbonImmutable::parse('2026-12-08 12:00:00', 'Asia/Jakarta'));
        $this->artisan('billing:process')->assertSuccessful();
        $before = DB::table('billing_notices')->count();
        $this->artisan('billing:process')->assertSuccessful();
        $this->assertSame($before, DB::table('billing_notices')->count());
        Passport::actingAs($community->owner);
        $response = $this->getJson('/api/v1/billing-notices')->assertOk();
        $notice = collect($response->json('data.items'))->firstWhere('type', 'inactive_review');
        $this->assertNotNull($notice, 'Admin must see another member review in their own reminder feed.');
        $this->assertSame($member->id, $notice['member_id']);
        $this->assertSame('member', $notice['subject_kind']);
        $this->assertDatabaseHas('community_members', ['id' => $member->id, 'status' => 'active']);
        Passport::actingAs(User::factory()->create());
        $this->getJson('/api/v1/billing-notices')->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson('/api/v1/billing-notices/'.$notice['id'].'/read')->assertNotFound();
    }
}
