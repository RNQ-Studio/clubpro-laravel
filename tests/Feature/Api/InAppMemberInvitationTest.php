<?php

namespace Tests\Feature\Api;

use App\Mail\MemberInvitationMail;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\GuardianGrant;
use App\Models\MemberIdentity;
use App\Models\MemberInvitation;
use App\Models\User;
use App\Services\GuardianService;
use App\Services\ManualMemberService;
use App\Support\Enums\CommunityMemberRole;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InAppMemberInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $founder;

    private Community $community;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance('env', 'production');
        config(['mail.default' => 'log']);
        Mail::fake();
        $this->founder = User::factory()->create(['name' => 'Pendiri Komunitas']);
        $this->community = Community::factory()->approved()->for($this->founder, 'owner')->create();
        Passport::actingAs($this->founder);
    }

    public function test_unavailable_production_mail_creates_private_in_app_invitation_without_sending_or_returning_a_token(): void
    {
        $member = $this->manual();
        $recipient = User::factory()->create();
        $response = $this->postJson($this->url($member, 'guardian'), ['email' => $recipient->email])
            ->assertOk()->assertJsonPath('data.delivery_method', 'in_app')
            ->assertJsonPath('data.invited_by_name', $this->founder->name)
            ->assertJsonPath('data.community_name', $this->community->name)
            ->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.token_hash');
        $this->assertStringContainsString('Undangan tersedia', $response->json('message'));
        Mail::assertNothingSent();
        $invite = MemberInvitation::query()->findOrFail($response->json('data.id'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $invite->token_hash);
        $this->assertDatabaseCount('guardian_grants', 0);
        Passport::actingAs($recipient);
        $this->getJson('/api/v1/guardian-invitations')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.delivery_method', 'in_app')->assertJsonMissingPath('data.0.token_hash');
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function missingConsentCases(): array
    {
        return [
            'missing' => [[]], 'false' => [['accept' => false]], 'string true' => [['accept' => 'true']],
            'numeric true' => [['accept' => 1]], 'token only' => [['token' => 'not-consent']],
        ];
    }

    #[DataProvider('missingConsentCases')]
    public function test_in_app_claim_requires_explicit_boolean_consent(array $payload): void
    {
        $recipient = User::factory()->create();
        $invite = $this->invite($this->manual(), $recipient);
        Passport::actingAs($recipient);
        $this->postJson($this->claimUrl($invite), $payload)->assertUnprocessable();
        $this->assertNull($invite->refresh()->claimed_at);
        $this->assertDatabaseCount('guardian_grants', 0);
    }

    public function test_only_the_verified_matching_email_owner_can_consent_and_claim_is_one_use(): void
    {
        $recipient = User::factory()->unverified()->create();
        $member = $this->manual();
        $invite = $this->invite($member, $recipient);
        Passport::actingAs(User::factory()->create());
        $this->postJson($this->claimUrl($invite), ['accept' => true])->assertUnprocessable();
        Passport::actingAs($recipient);
        $this->getJson('/api/v1/guardian-invitations')->assertForbidden();
        $this->postJson($this->claimUrl($invite), ['accept' => true])->assertUnprocessable();
        $recipient->forceFill(['email_verified_at' => now()])->save();
        Passport::actingAs($recipient->refresh());
        $this->postJson($this->claimUrl($invite), ['accept' => true])->assertOk();
        $this->postJson($this->claimUrl($invite), ['accept' => true])->assertUnprocessable();
        $this->getJson('/api/v1/dependents/'.$member->member_identity_id)->assertOk();
        $this->getJson('/api/v1/guardian-invitations')->assertOk()->assertJsonCount(0, 'data');
        Mail::assertNothingSent();
    }

    public function test_expired_or_replaced_in_app_invitation_cannot_be_accepted(): void
    {
        $member = $this->manual();
        $recipient = User::factory()->create();
        $old = $this->invite($member, $recipient);
        $new = $this->invite($member, $recipient);
        Passport::actingAs($recipient);
        $this->postJson($this->claimUrl($old), ['accept' => true])->assertUnprocessable();
        $this->travel(25)->hours();
        $this->postJson($this->claimUrl($new), ['accept' => true])->assertUnprocessable();
        $this->assertDatabaseCount('guardian_grants', 0);
    }

    public function test_email_invitation_remains_token_protected_when_delivery_becomes_unavailable(): void
    {
        config(['mail.default' => 'smtp']);
        $member = $this->manual();
        $recipient = User::factory()->create();
        $response = $this->postJson($this->url($member, 'guardian'), ['email' => $recipient->email])
            ->assertOk()->assertJsonPath('data.delivery_method', 'email');
        $invite = MemberInvitation::query()->findOrFail($response->json('data.id'));
        $mail = Mail::sent(MemberInvitationMail::class)->last();
        $this->assertInstanceOf(MemberInvitationMail::class, $mail);
        config(['mail.default' => 'log']);
        $migration = require database_path('migrations/2026_11_26_020000_add_invitation_delivery_method.php');
        $migration->up();
        $migration->up();
        $this->assertSame('email', $invite->refresh()->delivery_method);
        Passport::actingAs($recipient);
        $this->postJson($this->claimUrl($invite), ['accept' => true])->assertUnprocessable();
        $this->postJson($this->claimUrl($invite), ['token' => 'wrong', 'accept' => true])->assertUnprocessable();
        $this->postJson($this->claimUrl($invite), ['token' => $mail->claimToken])->assertOk();
    }

    public function test_nested_log_fallback_is_not_used_and_legacy_ambiguous_email_cannot_accept(): void
    {
        config(['mail.default' => 'failover', 'mail.mailers.failover.transport' => 'failover', 'mail.mailers.failover.mailers' => ['smtp', 'log']]);
        $recipient = User::factory()->create(['email' => 'invite-consent@anaklangit.manahpro.web.id']);
        $member = $this->manual();
        $invite = $this->invite($member, $recipient);
        $this->assertSame('in_app', $invite->delivery_method);
        $duplicate = User::factory()->create();
        DB::table('users')->where('id', $duplicate->id)->update(['email' => strtoupper($recipient->email)]);
        Passport::actingAs($recipient);
        $this->postJson($this->claimUrl($invite), ['accept' => true])->assertUnprocessable();
        $this->assertDatabaseCount('guardian_grants', 0);
        Mail::assertNothingSent();
    }

    public function test_in_app_acceptance_rechecks_the_inviting_managers_current_permission(): void
    {
        $member = $this->manual();
        $officer = User::factory()->create();
        $manager = CommunityMember::factory()->for($this->community)->for($officer)->create(['role' => CommunityMemberRole::Officer]);
        $recipient = User::factory()->create();
        Passport::actingAs($officer);
        $invite = $this->invite($member, $recipient);
        $manager->update(['role' => CommunityMemberRole::Member]);
        Passport::actingAs($recipient);
        $this->postJson($this->claimUrl($invite), ['accept' => true])->assertForbidden();
        $this->assertDatabaseCount('guardian_grants', 0);
        $this->assertNull($invite->refresh()->claimed_at);
    }

    /** @return array<string,array{bool}> */
    public static function canonicalCases(): array
    {
        return ['without canonical identity' => [false], 'with canonical identity' => [true]];
    }

    #[DataProvider('canonicalCases')]
    public function test_account_acceptance_preserves_membership_and_revokes_previous_manual_guardians(bool $existingCanonical): void
    {
        $account = User::factory()->create(['birth_date' => null, 'gender' => null]);
        if ($existingCanonical) {
            MemberIdentity::forUser($account);
        }
        $manual = $this->manual();
        $number = $manual->cardNumber();
        $guardian = User::factory()->create();
        $guardianInvite = $this->invite($manual, $guardian);
        Passport::actingAs($guardian);
        $this->postJson($this->claimUrl($guardianInvite), ['accept' => true])->assertOk();
        $grant = GuardianGrant::query()->firstOrFail();
        Passport::actingAs($this->founder);
        $pending = $this->invite($manual, User::factory()->create());
        $accountInvite = $this->invite($manual, $account, 'account');
        Passport::actingAs($account);
        $claim = $this->postJson($this->claimUrl($accountInvite), ['accept' => true])->assertOk()->json('data');
        $this->assertNotNull($grant->refresh()->revoked_at);
        $this->assertNotNull($pending->refresh()->revoked_at);
        $this->assertSame($account->id, $manual->refresh()->user_id);
        $this->assertSame($number, $manual->cardNumber());
        Passport::actingAs($guardian);
        $this->getJson('/api/v1/dependents/'.$claim['id'])->assertForbidden();
        $this->putJson('/api/v1/dependents/'.$claim['id'], ['name' => 'No Inherited Authority'])->assertForbidden();
        Mail::assertNothingSent();
    }

    #[DataProvider('canonicalCases')]
    public function test_stale_manual_member_cannot_edit_or_issue_invites_after_account_claim(bool $existingCanonical): void
    {
        $account = User::factory()->create(['birth_date' => null, 'gender' => null]);
        if ($existingCanonical) {
            MemberIdentity::forUser($account);
        }
        $manual = $this->manual();
        $stale = CommunityMember::query()->with('identity', 'community')->findOrFail($manual->id);
        $invite = $this->invite($manual, $account, 'account');
        Passport::actingAs($account);
        $this->postJson($this->claimUrl($invite), ['accept' => true])->assertOk();
        try {
            app(ManualMemberService::class)->update($this->founder, $stale, ['name' => 'Stale Manager Edit']);
            $this->fail('A stale manual snapshot must not edit the linked identity.');
        } catch (ValidationException) {
            $this->assertSame($account->name, $manual->fresh()->identity?->name);
        }
        $count = MemberInvitation::query()->count();
        try {
            app(GuardianService::class)->invite($this->founder, $stale, 'guardian', $this->founder->email);
            $this->fail('A stale manager snapshot must not issue a guardian invitation for a linked account.');
        } catch (AuthorizationException) {
            $this->assertSame($count, MemberInvitation::query()->count());
        }
        $this->assertSame($account->name, $account->fresh()->name);
    }

    public function test_late_manager_invitation_cannot_grant_authority_after_identity_is_account_linked(): void
    {
        $account = User::factory()->create(['birth_date' => null, 'gender' => null]);
        $manual = $this->manual();
        $recipient = User::factory()->create();
        $pending = $this->invite($manual, $recipient);
        $accountInvite = $this->invite($manual, $account, 'account');
        Passport::actingAs($account);
        $this->postJson($this->claimUrl($accountInvite), ['accept' => true])->assertOk();
        $this->assertNotNull($pending->fresh()->revoked_at);
        // Reproduce a formerly queued manager issuance that persisted after the
        // account claim revoked earlier invitations. Claim must reject it itself.
        DB::table('member_invitations')->where('id', $pending->id)->update(['revoked_at' => null]);
        Passport::actingAs($recipient);
        $this->postJson($this->claimUrl($pending), ['accept' => true])->assertForbidden();
        $this->assertDatabaseCount('guardian_grants', 0);
        $this->assertNull($pending->fresh()->claimed_at);
    }

    public function test_stale_ordinary_role_snapshot_does_not_allow_officer_to_edit_or_bind_a_promoted_admin(): void
    {
        $manual = $this->manual();
        $stale = CommunityMember::query()->with('identity', 'community')->findOrFail($manual->id);
        $officer = User::factory()->create();
        CommunityMember::factory()->for($this->community)->for($officer)->create(['role' => CommunityMemberRole::Officer]);
        $manual->update(['role' => CommunityMemberRole::Admin]);
        try {
            app(ManualMemberService::class)->update($officer, $stale, ['name' => 'Stale Role Edit']);
            $this->fail('A stale target role must not bypass privileged-identity protection.');
        } catch (AuthorizationException) {
            $this->assertNotSame('Stale Role Edit', $manual->fresh()->identity?->name);
        }
        try {
            app(GuardianService::class)->invite($officer, $stale, 'account', $officer->email);
            $this->fail('An officer cannot bind a target promoted to admin.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('member_invitations', 0);
        }
    }

    public function test_stale_manager_grant_snapshot_cannot_revoke_a_new_owner_authorized_guardian(): void
    {
        $account = User::factory()->create(['birth_date' => null, 'gender' => null]);
        $manual = $this->manual();
        $guardian = User::factory()->create();
        $initial = $this->invite($manual, $guardian);
        Passport::actingAs($guardian);
        $this->postJson($this->claimUrl($initial), ['accept' => true])->assertOk();
        $staleGrant = GuardianGrant::query()->with('identity', 'member.community')->firstOrFail();
        Passport::actingAs($this->founder);
        $accountInvite = $this->invite($manual, $account, 'account');
        Passport::actingAs($account);
        $this->postJson($this->claimUrl($accountInvite), ['accept' => true])->assertOk();
        $ownerInvitation = $this->invite($manual->fresh(), $guardian);
        Passport::actingAs($guardian);
        $this->postJson($this->claimUrl($ownerInvitation), ['accept' => true])->assertOk();
        $this->assertNull($staleGrant->fresh()->revoked_at);
        try {
            app(GuardianService::class)->revoke($this->founder, $staleGrant);
            $this->fail('Stale manual ownership must not revoke an account owner delegation.');
        } catch (AuthorizationException) {
            $this->assertNull($staleGrant->fresh()->revoked_at);
        }
    }

    private function manual(): CommunityMember
    {
        $data = $this->postJson('/api/v1/communities/'.$this->community->id.'/manual-members', [
            'name' => 'Anggota Manual', 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->json('data');

        return CommunityMember::query()->findOrFail($data['id']);
    }

    private function invite(CommunityMember $member, User $recipient, string $kind = 'guardian'): MemberInvitation
    {
        $data = $this->postJson($this->url($member, $kind), ['email' => $recipient->email])->assertOk()->json('data');

        return MemberInvitation::query()->findOrFail($data['id']);
    }

    private function url(CommunityMember $member, string $kind): string
    {
        return '/api/v1/communities/'.$this->community->id.'/members/'.$member->id.'/'.$kind.'-invitations';
    }

    private function claimUrl(MemberInvitation $invite): string
    {
        return '/api/v1/guardian-invitations/'.$invite->id.'/claim';
    }
}
