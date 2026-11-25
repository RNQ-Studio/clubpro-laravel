<?php

namespace Tests\Feature\Api;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\GuardianGrant;
use App\Models\User;
use App\Support\Enums\CommunityMemberRole;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CommunityAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_five_role_matrix_for_content_member_management_and_billing(): void
    {
        foreach (CommunityMemberRole::cases() as $role) {
            $community = Community::factory()->approved()->create();
            $actor = User::factory()->create();
            CommunityMember::factory()->for($community)->for($actor)->create(['role' => $role]);
            $target = CommunityMember::factory()->for($community)->create();
            Passport::actingAs($actor);
            $prefix = "/api/v1/communities/{$community->id}";
            $canContent = in_array($role, [CommunityMemberRole::Creator, CommunityMemberRole::Admin, CommunityMemberRole::Officer], true);
            $canMembers = in_array($role, [CommunityMemberRole::Creator, CommunityMemberRole::Admin], true);
            $this->putJson($prefix, ['description' => 'Keterangan komunitas diperbarui'])->assertStatus($canContent ? 200 : 403);
            $this->putJson("{$prefix}/members/{$target->id}/role", ['role' => 'coach'])->assertStatus($canMembers ? 200 : 403);
            $this->getJson("{$prefix}/dues/payments")->assertStatus($canMembers ? 200 : 403);
            $this->getJson($prefix)->assertOk()
                ->assertJsonPath('data.can_manage', $canContent)
                ->assertJsonPath('data.can_manage_members', $canMembers)
                ->assertJsonPath('data.can_configure_dues', $role === CommunityMemberRole::Creator);
        }
    }

    public function test_admin_cannot_approve_pending_admin_or_change_other_admin_or_founder(): void
    {
        $community = Community::factory()->approved()->create();
        $admin = CommunityMember::factory()->admin()->for($community)->create();
        $target = CommunityMember::factory()->admin()->pending()->for($community)->create();
        Passport::actingAs($admin->user);
        $this->postJson("/api/v1/communities/{$community->id}/members/{$target->id}/approve")->assertUnprocessable();
        $this->putJson("/api/v1/communities/{$community->id}/members/{$target->id}/role", ['role' => 'coach'])->assertUnprocessable();
        $this->assertFalse($target->refresh()->isActive());
    }

    public function test_platform_admin_is_not_community_admin_and_cross_community_mutations_fail(): void
    {
        $community = Community::factory()->approved()->create();
        $actor = User::factory()->create();
        $actor->assignRole('admin');
        $other = Community::factory()->approved()->create();
        CommunityMember::factory()->admin()->for($other)->for($actor)->create();
        Passport::actingAs($actor);
        $this->putJson("/api/v1/communities/{$community->id}", ['name' => 'Diambil alih'])->assertForbidden();
        $this->getJson("/api/v1/communities/{$community->id}/members")->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_public_directory_search_detail_allowlists_and_manual_child_privacy(): void
    {
        $community = Community::factory()->approved()->create(['name' => 'Klub Panah Publik']);
        $adult = User::factory()->create(['name' => 'Pemanah Dewasa', 'birth_date' => '1990-01-01']);
        $membership = CommunityMember::factory()->for($community)->for($adult)->create(['role' => 'coach']);
        $child = User::factory()->create(['name' => 'Pemanah Anak', 'birth_date' => now()->subYears(10)->toDateString()]);
        $childMember = CommunityMember::factory()->for($community)->for($child)->create();
        Community::factory()->create(['name' => 'Klub Rahasia']);
        $result = $this->getJson('/api/v1/public/communities?search=Panah')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(['id', 'name', 'short_name', 'type', 'type_label', 'description', 'city_name', 'province_name', 'logo_url', 'members_count'], array_keys($result->json('data.0')));
        $this->getJson("/api/v1/public/communities/{$community->id}")->assertOk()->assertJsonMissingPath('data.contact_email')->assertJsonMissingPath('data.dues_account_number');
        $this->getJson('/api/v1/public/members?search=Pemanah')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Pemanah Dewasa')
            ->assertJsonPath('data.0.community.id', $community->id)->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.birth_date');
        $this->getJson('/api/v1/public/members?role=coach')->assertOk()->assertJsonPath('data.0.membership_id', $membership->id);
        GuardianGrant::query()->create(['member_identity_id' => $childMember->member_identity_id, 'guardian_user_id' => User::factory()->create()->id, 'community_member_id' => $childMember->id, 'verified_at' => now()]);
        $childMember->identity->update(['is_public' => true]);
        $this->getJson('/api/v1/public/members?search=Pemanah')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/public/members?per_page=101')->assertUnprocessable();
        $this->postJson('/api/v1/public/communities', ['name' => 'Palsu'])->assertStatus(405);
        $this->putJson("/api/v1/communities/{$community->id}", ['name' => 'Palsu'])->assertUnauthorized();
    }

    public function test_merged_member_alias_cannot_be_reactivated_or_assigned_a_role(): void
    {
        $owner = User::factory()->create();
        $community = Community::factory()->approved()->for($owner, 'owner')->create();
        $canonical = CommunityMember::factory()->for($community)->create();
        $alias = CommunityMember::factory()->for($community)->create();
        $alias->forceFill(['user_id' => null, 'status' => 'archived', 'merged_into_member_id' => $canonical->id])->save();
        Passport::actingAs($owner);
        $this->postJson("/api/v1/communities/{$community->id}/members/{$alias->id}/approve")->assertUnprocessable()->assertJsonValidationErrors('member');
        $this->putJson("/api/v1/communities/{$community->id}/members/{$alias->id}/role", ['role' => 'coach'])->assertUnprocessable()->assertJsonValidationErrors('member');
        $this->assertDatabaseHas('community_members', ['id' => $alias->id, 'status' => 'archived', 'merged_into_member_id' => $canonical->id]);
    }

    public function test_leave_and_rejoin_preserves_stable_membership_identity_and_join_date(): void
    {
        $community = Community::factory()->approved()->create();
        $member = CommunityMember::factory()->for($community)->create(['joined_at' => '2025-01-01']);
        $cardNumber = $member->cardNumber();
        $identityId = $member->member_identity_id;
        Passport::actingAs($member->user);
        $this->postJson("/api/v1/communities/{$community->id}/leave")->assertOk();
        $this->assertDatabaseHas('community_members', ['id' => $member->id, 'status' => 'archived']);
        $this->postJson("/api/v1/communities/{$community->id}/join")->assertOk()->assertJsonPath('data.id', $member->id)->assertJsonPath('data.status', 'pending');
        $this->assertSame($identityId, $member->refresh()->member_identity_id);
        $this->assertSame($cardNumber, $member->cardNumber());
        $this->assertSame('2025-01-01', $member->joined_at->toDateString());
    }
}
