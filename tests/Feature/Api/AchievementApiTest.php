<?php

namespace Tests\Feature\Api;

use App\Models\AchievementEntry;
use App\Models\AchievementRule;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\MemberAdvertisement;
use App\Models\MemberIdentity;
use App\Models\TrainingFinalEvent;
use App\Models\User;
use App\Services\AchievementService;
use Database\Seeders\AchievementRuleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\Support\MarketplaceFixture;
use Tests\TestCase;

class AchievementApiTest extends TestCase
{
    use MarketplaceFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->fixture();
    }

    /** @return array<string,mixed> */
    private function eventData(?CommunityMember $member = null, string $type = 'participation'): array
    {
        $member ??= $this->community->membershipOf($this->sender);

        return ['member_id' => $member->id, 'type' => $type, 'title' => 'Lomba Fixture', 'reference' => 'FIXTURE-'.Str::uuid(), 'occurred_at' => now('Asia/Jakarta')->toDateString(), 'idempotency_key' => (string) Str::uuid()];
    }

    private function base(): string
    {
        return '/api/v1/communities/'.$this->community->id.'/achievements';
    }

    public function test_central_authorization_prospective_rules_and_audit(): void
    {
        Passport::actingAs($this->founder);
        $this->getJson('/api/v1/admin/achievement-rules')->assertForbidden();
        $first = $this->postJson($this->base(), $this->eventData())->assertCreated()->json('data.id');
        $super = User::factory()->create();
        $super->assignRole('super-admin');
        Passport::actingAs($super);
        $this->travel(1)->seconds();
        $this->putJson('/api/v1/admin/achievement-rules', ['expected_version' => 1, 'points' => ['training_attendance' => 3, 'training_purchase' => 5, 'participation' => 7, 'champion' => 10], 'age_boundaries' => [12, 16, 19, 36, 51], 'reason' => 'Aturan fixture prospektif'])->assertOk()->assertJsonPath('data.version', 2)->assertJsonCount(2, 'data.history');
        Passport::actingAs($this->founder);
        $second = $this->postJson($this->base(), $this->eventData())->assertCreated()->json('data.id');
        $this->assertSame(1, AchievementEntry::query()->where('source_id', $first)->value('points'));
        $this->assertSame(7, AchievementEntry::query()->where('source_id', $second)->value('points'));
        $this->assertSame(2, AchievementRule::query()->count());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'achievement', 'event' => 'rules_updated', 'causer_id' => $super->id]);
        $this->assertSame(100000, $this->ledger->wallet($this->sender)['available']);
    }

    public function test_manual_retry_business_dedup_correction_and_invalid_sources(): void
    {
        Passport::actingAs($this->founder);
        $data = $this->eventData();
        $id = $this->postJson($this->base(), $data)->assertCreated()->json('data.id');
        $this->postJson($this->base(), $data)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson($this->base(), [...$data, 'idempotency_key' => (string) Str::uuid()])->assertConflict();
        $this->postJson($this->base(), [...$data, 'title' => 'Changed'])->assertConflict();
        $correct = ['expected_version' => 1, 'reason' => 'Hasil fixture salah ketik'];
        $this->postJson($this->base().'/'.$id.'/correct', $correct)->assertOk()->assertJsonPath('data.points', 0);
        $this->postJson($this->base().'/'.$id.'/correct', $correct)->assertOk();
        $this->assertSame(2, AchievementEntry::query()->count());
        $this->assertSame(0, (int) AchievementEntry::query()->sum('points'));
        $this->postJson($this->base(), [...$this->eventData(), 'type' => 'training_purchase'])->assertUnprocessable();
        $this->postJson($this->base(), [...$this->eventData(), 'occurred_at' => now()->addDay()->toDateString()])->assertUnprocessable();
        $fake = TrainingFinalEvent::query()->create(['community_id' => $this->community->id, 'member_identity_id' => MemberIdentity::forUser($this->sender)->id, 'type' => 'training_purchase_final', 'source_id' => (string) Str::uuid(), 'payload' => [], 'created_at' => now()]);
        app(AchievementService::class)->consumeTraining($fake);
        $this->assertSame(2, AchievementEntry::query()->count());
        $entry = AchievementEntry::query()->where('source_id', $id)->where('points', 1)->firstOrFail();
        $negative = AchievementEntry::query()->where('points', -1)->firstOrFail();
        $this->assertTrue($entry->occurred_at->equalTo($negative->occurred_at));
        $this->assertSame($entry->rule_version, $negative->rule_version);
    }

    public function test_role_matrix_and_scoped_member_picker(): void
    {
        $coach = User::factory()->create();
        CommunityMember::factory()->for($this->community)->for($coach)->create(['role' => 'coach']);
        foreach ([$this->sender, User::factory()->create()] as $denied) {
            Passport::actingAs($denied);
            $this->postJson($this->base(), $this->eventData())->assertForbidden();
            $this->getJson('/api/v1/communities/'.$this->community->id.'/achievement-members')->assertForbidden();
        }
        $super = User::factory()->create();
        $super->assignRole('super-admin');
        Passport::actingAs($super);
        $this->postJson($this->base(), $this->eventData())->assertForbidden();
        Passport::actingAs($coach);
        $this->getJson('/api/v1/communities/'.$this->community->id.'/achievement-members')->assertOk()->assertJsonStructure(['data' => ['items' => [['id', 'name', 'role']]]]);
        $id = $this->postJson($this->base(), $this->eventData())->assertCreated()->json('data.id');
        $this->postJson($this->base().'/'.$id.'/correct', ['expected_version' => 1, 'reason' => 'Koreksi fixture tidak boleh'])->assertForbidden();
        $other = Community::factory()->approved()->create();
        $foreign = CommunityMember::factory()->for($other)->create();
        Passport::actingAs($this->founder);
        $this->postJson($this->base(), $this->eventData($foreign))->assertNotFound();
    }

    public function test_exact_attribution_dense_ties_period_gender_unknown_and_privacy(): void
    {
        Passport::actingAs($this->founder);
        $this->postJson($this->base(), $this->eventData())->assertCreated();
        $this->postJson($this->base(), $this->eventData($this->community->membershipOf($this->recipient)))->assertCreated();
        $other = Community::factory()->approved()->for($this->founder, 'owner')->create();
        $second = CommunityMember::factory()->for($other)->for($this->sender)->create();
        $this->postJson('/api/v1/communities/'.$other->id.'/achievements', $this->eventData($second))->assertCreated();
        $this->getJson('/api/v1/public/rankings?scope=communities')->assertOk()->assertJsonPath('data.items.0.points', 2)->assertJsonPath('data.items.1.points', 1);
        $this->getJson('/api/v1/public/rankings?scope=members&community_id='.$this->community->id)->assertOk()->assertJsonPath('data.items.0.rank', 1)->assertJsonPath('data.items.1.rank', 1)->assertJsonPath('data.items.0.points', 1);
        $identity = MemberIdentity::forUser($this->sender);
        $identity->update(['gender' => null, 'birth_date' => null]);
        $this->getJson('/api/v1/public/rankings?scope=members&gender=unknown&age_group=unknown')->assertOk()->assertJsonPath('data.items.0.points', 2);
        $identity->update(['is_public' => false]);
        $response = $this->getJson('/api/v1/public/rankings?scope=members&community_id='.$this->community->id)->assertOk();
        $this->assertCount(1, $response->json('data.items'));
        $text = $response->getContent();
        foreach (['birth_date', 'email', 'balance', 'bank_account', 'guardian'] as $private) {
            $this->assertStringNotContainsString($private, $text);
        }
        $this->getJson('/api/v1/public/rankings?scope=communities&period=month&month='.now()->subMonth()->format('Y-m'))->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/public/rankings?per_page=101')->assertUnprocessable();
        Passport::actingAs($this->sender);
        $this->getJson('/api/v1/achievements/me')->assertOk()->assertJsonPath('data.total_points', 2);
    }

    public function test_age_boundaries_leap_day_and_canonical_merge_without_rewriting_history(): void
    {
        $service = app(AchievementService::class);
        $rule = $service->rule();
        $identity = MemberIdentity::forUser($this->sender);
        $identity->update(['birth_date' => '2012-02-29']);
        $this->assertSame('u12', $service->ageGroup($identity->fresh(), Carbon::parse('2024-02-28'), $rule));
        $this->assertSame('12_15', $service->ageGroup($identity->fresh(), Carbon::parse('2024-02-29'), $rule));
        foreach ([11 => 'u12', 12 => '12_15', 15 => '12_15', 16 => '16_18', 18 => '16_18', 19 => '19_35', 35 => '19_35', 36 => '36_50', 50 => '36_50', 51 => '51_plus'] as $age => $group) {
            $identity->update(['birth_date' => Carbon::parse('2026-11-30')->subYears($age)->toDateString()]);
            $this->assertSame($group, $service->ageGroup($identity->fresh(), Carbon::parse('2026-11-30'), $rule));
        }
        $identity->update(['birth_date' => null]);
        Passport::actingAs($this->founder);
        $this->postJson($this->base(), $this->eventData())->assertCreated();
        $old = $identity->id;
        $target = MemberIdentity::forUser($this->recipient);
        $identity->update(['merged_into_id' => $target->id]);
        Passport::actingAs($this->recipient);
        $this->getJson('/api/v1/achievements/me')->assertOk()->assertJsonPath('data.total_points', 1);
        $this->getJson('/api/v1/public/rankings?scope=members&community_id='.$this->community->id)->assertOk()->assertJsonPath('data.items.0.identity_id', $target->id)->assertJsonPath('data.items.0.points', 1);
        $this->assertSame($old, AchievementEntry::query()->firstOrFail()->member_identity_id);
    }

    public function test_member_ad_management_display_target_validation_quota_and_ownership(): void
    {
        Passport::actingAs($this->recipient);
        $product = $this->product();
        $body = ['community_id' => $this->community->id, 'title' => 'Iklan Fixture', 'body' => 'Informasi promosi anggota', 'photo_asset_id' => null, 'target_product_id' => $product->id, 'is_active' => true];
        $id = $this->postJson('/api/v1/advertisements', $body)->assertCreated()->json('data.id');
        $this->getJson('/api/v1/public/advertisements')->assertOk()->assertJsonPath('data.items.0.id', $id);
        $this->getJson('/api/v1/advertisements/'.$id)->assertOk();
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/advertisements', $body)->assertCreated();
        }
        $this->postJson('/api/v1/advertisements', $body)->assertUnprocessable();
        $this->putJson('/api/v1/advertisements/'.$id, [...$body, 'expected_version' => 8])->assertConflict();
        $this->deleteJson('/api/v1/advertisements/'.$id, ['expected_version' => 1])->assertOk();
        $this->getJson('/api/v1/advertisements/'.$id)->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson('/api/v1/advertisements', $body)->assertCreated();
        $super = User::factory()->create();
        $super->assignRole('super-admin');
        Passport::actingAs($super);
        $this->getJson('/api/v1/advertisements/'.$id)->assertForbidden();
        $this->putJson('/api/v1/advertisements/'.$id, [...$body, 'expected_version' => 2])->assertForbidden();
        Passport::actingAs($this->sender);
        $this->postJson('/api/v1/advertisements', $body)->assertUnprocessable();
        $this->assertSame(6, MemberAdvertisement::query()->count());
    }

    public function test_explicit_default_seed_preserves_configured_versions_and_history(): void
    {
        $super = User::factory()->create();
        $super->assignRole('super-admin');
        app(AchievementService::class)->configure($super, ['expected_version' => 1, 'points' => ['training_attendance' => 2, 'training_purchase' => 3, 'participation' => 4, 'champion' => 5], 'age_boundaries' => [12, 16, 19, 36, 51], 'reason' => 'Fixture aturan yang dipertahankan']);
        $before = AchievementRule::query()->orderBy('version')->get()->toArray();
        $this->seed(AchievementRuleSeeder::class);
        $this->seed(AchievementRuleSeeder::class);
        $this->assertSame($before, AchievementRule::query()->orderBy('version')->get()->toArray());
        $this->assertSame(2, app(AchievementService::class)->rule()->version);
    }

    public function test_configurable_tie_order_and_sql_immutable_history(): void
    {
        Passport::actingAs($this->founder);
        $this->postJson($this->base(), $this->eventData())->assertCreated();
        $this->postJson($this->base(), $this->eventData($this->community->membershipOf($this->recipient)))->assertCreated();
        MemberIdentity::forUser($this->sender)->update(['name' => 'Zed Fixture']);
        MemberIdentity::forUser($this->recipient)->update(['name' => 'Alpha Fixture']);
        config(['achievements.ranking_tie_order' => 'name', 'achievements.default_period' => 'all']);
        $this->getJson('/api/v1/public/rankings?scope=members&community_id='.$this->community->id)->assertOk()->assertJsonPath('data.period', 'all')->assertJsonPath('data.items.0.name', 'Alpha Fixture')->assertJsonPath('data.items.0.rank', 1)->assertJsonPath('data.items.1.rank', 1);
        foreach (['achievement_entries', 'achievement_rules'] as $table) {
            try {
                DB::transaction(fn () => DB::table($table)->update([$table === 'achievement_entries' ? 'points' : 'reason' => $table === 'achievement_entries' ? 99 : 'rewrite']));
                $this->fail('Immutable table accepted mutation');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
        $this->assertSame(2, (int) AchievementEntry::query()->sum('points'));
    }

    public function test_ad_photo_rejects_private_foreign_fake_and_protects_real_raster(): void
    {
        Passport::actingAs($this->recipient);
        $body = ['community_id' => $this->community->id, 'title' => 'Iklan Foto Fixture', 'body' => 'Promosi foto', 'is_active' => true];
        $this->postJson('/api/v1/advertisements', [...$body, 'photo_asset_id' => $this->proof($this->recipient)->id])->assertUnprocessable();
        $this->postJson('/api/v1/advertisements', [...$body, 'photo_asset_id' => $this->productPhoto($this->sender)->id])->assertUnprocessable();
        $photo = $this->productPhoto();
        $id = $this->postJson('/api/v1/advertisements', [...$body, 'photo_asset_id' => $photo->id])->assertCreated()->json('data.id');
        $photo->refresh()->update(['is_protected' => false]);
        $this->assertTrue($photo->isProtected());
        $this->getJson('/api/v1/public/advertisements')->assertOk()->assertJsonPath('data.items.0.id', $id);
        $this->assertSame(100000, $this->ledger->wallet($this->sender)['available']);
    }
}
