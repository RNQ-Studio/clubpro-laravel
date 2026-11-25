<?php

namespace Tests\Feature\Api;

use App\Mail\MemberInvitationMail;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\GuardianGrant;
use App\Models\MemberIdentity;
use App\Models\MemberInvitation;
use App\Models\User;
use App\Support\Enums\CommunityMemberRole;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManualMemberIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $founder;

    private Community $community;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Mail::fake();
        $this->founder = User::factory()->create();
        $this->community = Community::factory()->approved()->for($this->founder, 'owner')->create();
        Passport::actingAs($this->founder);
    }

    public function test_manual_member_is_stable_private_and_retry_safe_without_an_account(): void
    {
        $data = ['name' => 'Anggota Anak', 'birth_date' => '2016-01-15', 'gender' => 'female', 'idempotency_key' => (string) Str::uuid()];
        $member = $this->postJson($this->url('manual-members'), $data)->assertOk()->assertJsonPath('data.user_id', null)->json('data');
        $this->postJson($this->url('manual-members'), $data)->assertOk()->assertJsonPath('data.id', $member['id']);
        $this->postJson($this->url('manual-members'), [...$data, 'name' => 'Berbeda'])->assertUnprocessable();
        $this->assertFalse(MemberIdentity::query()->findOrFail($member['member_identity_id'])->is_public);
        $this->assertSame(2, $this->community->members()->count());
        $this->putJson($this->url('manual-members/'.$member['id']), ['name' => 'Nama Diperbaiki'])->assertOk()->assertJsonPath('data.member_number', $member['member_number']);
        $this->getJson($this->url('manual-members/'.$member['id']))->assertOk()->assertJsonPath('data.birth_date', '2016-01-15');
        $this->getJson($this->url('members?search=Nama'))->assertOk()->assertJsonPath('data.0.name', 'Nama Diperbaiki');
        $this->postJson($this->url('manual-members'), [...$data, 'idempotency_key' => (string) Str::uuid(), 'name' => 'Nama Diperbaiki'])->assertUnprocessable();
    }

    /** @return array<string,array{string,bool}> */
    public static function managerRoles(): array
    {
        return ['admin' => ['admin', true], 'officer' => ['officer', true], 'coach' => ['coach', false], 'member' => ['member', false]];
    }

    #[DataProvider('managerRoles')]
    public function test_manual_creation_obeys_role_matrix(string $role, bool $allowed): void
    {
        $actor = User::factory()->create();
        CommunityMember::factory()->for($this->community)->for($actor)->create(['role' => CommunityMemberRole::from($role)]);
        Passport::actingAs($actor);
        $response = $this->postJson($this->url('manual-members'), ['name' => 'Anggota Baru', 'idempotency_key' => (string) Str::uuid()]);
        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public function test_cross_community_mutations_and_public_creation_are_rejected(): void
    {
        $member = $this->manual();
        $other = Community::factory()->approved()->create();
        Passport::actingAs($other->owner);
        $this->postJson($this->url('manual-members'), ['name' => 'Tidak Boleh', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->putJson("/api/v1/communities/{$other->id}/manual-members/{$member->id}", ['name' => 'Tidak Boleh'])->assertNotFound();
        auth('api')->forgetUser();
        $this->postJson($this->url('manual-members'), ['name' => 'Tidak Boleh'])->assertUnauthorized();
    }

    public function test_duplicate_reference_and_existing_account_email_are_not_auto_merged(): void
    {
        $existing = User::factory()->create();
        CommunityMember::factory()->for($this->community)->for($existing)->create();
        $this->postJson($this->url('manual-members'), ['name' => 'Sudah Ada', 'account_email' => $existing->email, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($this->url('manual-members'), ['name' => 'Satu', 'external_reference' => 'LOCAL-1', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->postJson($this->url('manual-members'), ['name' => 'Dua', 'external_reference' => 'LOCAL-1', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($this->url('manual-members'), ['name' => 'Tanggal Salah', 'birth_date' => now()->addDay()->toDateString(), 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_typing_guardian_email_does_not_grant_access_and_claim_requires_verified_owner(): void
    {
        $member = $this->manual();
        $guardian = User::factory()->unverified()->create();
        [$invite, $token] = $this->invite($member, $guardian);
        $this->assertDatabaseCount('guardian_grants', 0);
        $this->assertNotSame($token, $invite->token_hash);
        Passport::actingAs($guardian);
        $this->getJson('/api/v1/dependents/'.$member->member_identity_id)->assertForbidden();
        $this->postJson('/api/v1/guardian-invitations/'.$invite->id.'/claim', ['token' => $token])->assertUnprocessable();
        $guardian->forceFill(['email_verified_at' => now()])->save();
        Passport::actingAs($guardian->refresh());
        $this->postJson('/api/v1/guardian-invitations/'.$invite->id.'/claim', ['token' => 'salah'])->assertUnprocessable();
        $this->postJson('/api/v1/guardian-invitations/'.$invite->id.'/claim', ['token' => $token])->assertOk();
        $this->postJson('/api/v1/guardian-invitations/'.$invite->id.'/claim', ['token' => $token])->assertUnprocessable();
        $this->assertDatabaseCount('guardian_grants', 1);
        $this->getJson('/api/v1/dependents')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson('/api/v1/dependents/'.$member->member_identity_id, ['name' => 'Nama Dependent', 'is_public' => true])->assertOk();
        $this->getJson('/api/v1/dependents/'.$member->member_identity_id.'/memberships/'.$member->id.'/card')->assertOk()->assertJsonPath('data.name', 'Nama Dependent')->assertJsonPath('data.member_number', $member->cardNumber());
        $grant = GuardianGrant::query()->firstOrFail();
        $this->deleteJson('/api/v1/guardian-grants/'.$grant->id)->assertOk();
        $this->getJson('/api/v1/dependents/'.$member->member_identity_id)->assertForbidden();
    }

    public function test_wrong_email_expiry_and_reissued_invitation_cannot_claim(): void
    {
        $member = $this->manual();
        $guardian = User::factory()->create();
        [$old, $oldToken] = $this->invite($member, $guardian);
        [$new, $newToken] = $this->invite($member, $guardian);
        Passport::actingAs($guardian);
        $this->postJson('/api/v1/guardian-invitations/'.$old->id.'/claim', ['token' => $oldToken])->assertUnprocessable();
        Passport::actingAs(User::factory()->create());
        $this->postJson('/api/v1/guardian-invitations/'.$new->id.'/claim', ['token' => $newToken])->assertUnprocessable();
        Passport::actingAs($guardian);
        $this->travel(25)->hours();
        $this->postJson('/api/v1/guardian-invitations/'.$new->id.'/claim', ['token' => $newToken])->assertUnprocessable();
        $this->assertDatabaseCount('guardian_grants', 0);
    }

    public function test_multiple_guardians_can_link_without_controlling_login_credentials(): void
    {
        $member = $this->manual();
        foreach (range(1, 2) as $_) {
            $guardian = User::factory()->create();
            Passport::actingAs($this->founder);
            [$invite, $token] = $this->invite($member, $guardian);
            Passport::actingAs($guardian);
            $this->postJson('/api/v1/guardian-invitations/'.$invite->id.'/claim', ['token' => $token])->assertOk();
            $this->putJson('/api/v1/dependents/'.$member->member_identity_id, ['user_id' => $guardian->id, 'email' => $guardian->email, 'pin' => '654321'])->assertOk();
        }
        $this->assertDatabaseCount('guardian_grants', 2);
        $this->assertNull($member->refresh()->user_id);
        Passport::actingAs($this->founder);
        $grant = GuardianGrant::query()->firstOrFail();
        $this->getJson($this->url('manual-members/'.$member->id))->assertOk()->assertJsonCount(2, 'data.guardian_grants');
        $this->deleteJson('/api/v1/guardian-grants/'.$grant->id)->assertOk();
        $this->assertNotNull($grant->refresh()->revoked_at);
    }

    public function test_account_claim_uses_email_ownership_and_preserves_member_card_history_across_communities(): void
    {
        $account = User::factory()->create(['birth_date' => null, 'gender' => null]);
        $other = Community::factory()->approved()->create();
        $otherMember = CommunityMember::factory()->for($other)->for($account)->create();
        $canonical = $otherMember->member_identity_id;
        $member = $this->manual(['account_email' => $account->email]);
        $oldIdentity = $member->member_identity_id;
        $number = $member->cardNumber();
        [$invite, $token] = $this->invite($member, $account, 'account');
        $this->assertNull($member->refresh()->user_id);
        Passport::actingAs($account);
        $this->postJson('/api/v1/guardian-invitations/'.$invite->id.'/claim', ['token' => $token])->assertOk();
        $this->assertSame($account->id, $member->refresh()->user_id);
        $this->assertSame($canonical, $member->member_identity_id);
        $this->assertSame($number, $member->cardNumber());
        $this->assertSame($canonical, MemberIdentity::query()->findOrFail($oldIdentity)->merged_into_id);
        $this->assertSame(2, CommunityMember::query()->where('user_id', $account->id)->count());
        $this->getJson('/api/v1/communities/joined')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_account_claim_rejects_conflicting_demographics_and_cannot_hijack_existing_account(): void
    {
        $account = User::factory()->create(['birth_date' => '1990-01-01']);
        MemberIdentity::forUser($account);
        $member = $this->manual(['birth_date' => '2016-01-01']);
        [$invite, $token] = $this->invite($member, $account, 'account');
        Passport::actingAs($account);
        $this->postJson('/api/v1/guardian-invitations/'.$invite->id.'/claim', ['token' => $token])->assertUnprocessable();
        $this->assertNull($member->refresh()->user_id);
        $this->assertSame('1990-01-01', $account->refresh()->birth_date?->toDateString());
    }

    public function test_identity_migration_is_repeatable_and_preserves_existing_membership_and_kta(): void
    {
        $member = $this->community->membershipOf($this->founder);
        $id = $member?->id;
        $number = $member?->cardNumber();
        $migration = require database_path('migrations/2026_11_26_010000_create_member_identities_and_guardians.php');
        $migration->up();
        $migration->up();
        $this->assertSame($id, $member?->refresh()->id);
        $this->assertSame($number, $member?->cardNumber());
        $this->assertSame(1, DB::table('member_identities')->where('user_id', $this->founder->id)->count());
    }

    public function test_email_change_revokes_verified_ownership_and_minor_publication_requires_explicit_consent(): void
    {
        $account = User::factory()->create(['birth_date' => '2016-01-01']);
        $identity = MemberIdentity::forUser($account);
        $this->assertFalse($identity->is_public);
        Passport::actingAs($account);
        $this->putJson('/api/v1/auth/me', ['email' => 'new-owner@anaklangit.manahpro.web.id'])->assertOk();
        $this->assertNull($account->refresh()->email_verified_at);
        $this->getJson('/api/v1/guardian-invitations')->assertForbidden();
    }

    public function test_last_guardian_revocation_removes_publication_consent(): void
    {
        $member = $this->manual(['birth_date' => '2016-01-01']);
        $guardian = User::factory()->create();
        [$invite, $token] = $this->invite($member, $guardian);
        Passport::actingAs($guardian);
        $this->postJson('/api/v1/guardian-invitations/'.$invite->id.'/claim', ['token' => $token])->assertOk();
        $this->assertFalse($member->identity?->fresh()->is_public);
        $this->putJson('/api/v1/dependents/'.$member->member_identity_id, ['is_public' => true])->assertOk();
        $this->assertTrue($member->identity?->fresh()->is_public);
        $grant = GuardianGrant::query()->firstOrFail();
        $this->deleteJson('/api/v1/guardian-grants/'.$grant->id)->assertOk();
        $this->assertFalse($member->identity?->fresh()->is_public);
    }

    public function test_claim_without_existing_identity_reconciles_demographics_without_overwriting_conflicts(): void
    {
        $account = User::factory()->create(['birth_date' => '1990-01-01', 'gender' => null]);
        $member = $this->manual(['birth_date' => '2016-01-01']);
        [$invite, $token] = $this->invite($member, $account, 'account');
        Passport::actingAs($account);
        $this->postJson('/api/v1/guardian-invitations/'.$invite->id.'/claim', ['token' => $token])->assertUnprocessable();
        Passport::actingAs($this->founder);
        $this->putJson($this->url('manual-members/'.$member->id), ['birth_date' => '1990-01-01', 'gender' => 'female'])->assertOk();
        Passport::actingAs($account);
        $this->postJson('/api/v1/guardian-invitations/'.$invite->id.'/claim', ['token' => $token])->assertOk();
        $this->assertSame('female', $account->refresh()->gender?->value);
        $this->assertSame('1990-01-01', $member->identity?->fresh()->birth_date?->toDateString());
    }

    public function test_same_community_claim_preserves_canonical_role_and_archives_alias_without_losing_rows(): void
    {
        $account = User::factory()->create(['birth_date' => null, 'gender' => null]);
        $manual = $this->manual(['account_email' => $account->email]);
        $manual->update(['role' => CommunityMemberRole::Admin]);
        $manualNumber = $manual->cardNumber();
        [$invite, $token] = $this->invite($manual, $account, 'account');
        $existing = CommunityMember::factory()->for($this->community)->for($account)->create(['role' => CommunityMemberRole::Member]);
        Passport::actingAs($account);
        $this->postJson('/api/v1/guardian-invitations/'.$invite->id.'/claim', ['token' => $token])->assertOk();
        $this->assertSame('archived', $manual->refresh()->status->value);
        $this->assertSame($existing->id, $manual->merged_into_member_id);
        $this->assertSame($manualNumber, $manual->cardNumber());
        $this->assertSame(CommunityMemberRole::Member, $existing->refresh()->role);
        $this->assertDatabaseHas('community_members', ['id' => $manual->id]);
        Passport::actingAs($this->founder);
        $this->putJson($this->url('manual-members/'.$manual->id), ['name' => 'Reopen'])->assertUnprocessable();
        $this->postJson($this->url('members/'.$manual->id.'/account-invitations'), ['email' => $account->email])->assertUnprocessable();
        $this->getJson($this->url('members'))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_cannot_bind_or_edit_privileged_manual_identity_and_old_officer_invite_cannot_escalate(): void
    {
        $manual = $this->manual();
        $admin = User::factory()->create();
        CommunityMember::factory()->for($this->community)->for($admin)->create(['role' => CommunityMemberRole::Admin]);
        $manual->update(['role' => CommunityMemberRole::Admin]);
        Passport::actingAs($admin);
        $this->putJson($this->url('manual-members/'.$manual->id), ['name' => 'Hijack'])->assertForbidden();
        $this->postJson($this->url('members/'.$manual->id.'/account-invitations'), ['email' => $admin->email])->assertForbidden();
        $manual->update(['role' => CommunityMemberRole::Member]);
        $officer = User::factory()->create();
        CommunityMember::factory()->for($this->community)->for($officer)->create(['role' => CommunityMemberRole::Officer]);
        Passport::actingAs($officer);
        $recipient = User::factory()->create();
        [$invite, $token] = $this->invite($manual, $recipient, 'account');
        $manual->update(['role' => CommunityMemberRole::Admin]);
        Passport::actingAs($recipient);
        $this->postJson('/api/v1/guardian-invitations/'.$invite->id.'/claim', ['token' => $token])->assertForbidden();
        $this->assertNull($manual->refresh()->user_id);
    }

    public function test_raw_legacy_backfill_preserves_joined_entry_fee_and_kta(): void
    {
        $user = User::factory()->create(['birth_date' => '2014-04-01', 'gender' => 'female']);
        $joined = '2026-01-02 03:04:05';
        $paid = '2026-02-03 04:05:06';
        $id = DB::table('community_members')->insertGetId([
            'community_id' => $this->community->id, 'user_id' => $user->id,
            'role' => 'member', 'status' => 'active', 'joined_at' => $joined,
            'entry_fee_paid_at' => $paid, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $number = CommunityMember::query()->findOrFail($id)->cardNumber();
        $manual = $this->manual();
        $migration = require database_path('migrations/2026_11_26_010000_create_member_identities_and_guardians.php');
        $migration->up();
        $migration->up();
        $member = CommunityMember::query()->findOrFail($id);
        $this->assertSame($number, $member->cardNumber());
        $this->assertSame($joined, $member->joined_at?->format('Y-m-d H:i:s'));
        $this->assertSame($paid, $member->entry_fee_paid_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2014-04-01', $member->identity?->birth_date?->toDateString());
        $this->assertFalse($member->identity?->is_public);
        $this->assertSame(1, MemberIdentity::query()->where('user_id', $user->id)->count());
        $this->assertNull($manual->refresh()->user_id);
    }

    /** @param array<string,mixed> $overrides */
    private function manual(array $overrides = []): CommunityMember
    {
        $data = $this->postJson($this->url('manual-members'), [...['name' => 'Manual Anak', 'idempotency_key' => (string) Str::uuid()], ...$overrides])->assertOk()->json('data');

        return CommunityMember::query()->findOrFail($data['id']);
    }

    /** @return array{MemberInvitation,string} */
    private function invite(CommunityMember $member, User $recipient, string $kind = 'guardian'): array
    {
        $data = $this->postJson($this->url('members/'.$member->id.'/'.$kind.'-invitations'), ['email' => $recipient->email])->assertOk()->assertJsonMissingPath('data.token')->json('data');
        $mail = Mail::sent(MemberInvitationMail::class)->last();
        $this->assertInstanceOf(MemberInvitationMail::class, $mail);

        return [MemberInvitation::query()->findOrFail($data['id']), $mail->claimToken];
    }

    private function url(string $path): string
    {
        return '/api/v1/communities/'.$this->community->id.'/'.$path;
    }
}
