<?php

namespace Tests\Feature\Api;

use App\Http\Requests\Api\V1\ManualMemberRequest;
use App\Http\Requests\Api\V1\UpdateDependentRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Models\Community;
use App\Models\GuardianGrant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BirthDateWibTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame('laravel_starter_test', DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string,array{string}> */
    public static function calendarInstants(): array
    {
        return [
            'midnight_WIB_previous_UTC_day' => ['2026-12-03 00:00:00'],
            'year_boundary_before_UTC_day_rollover' => ['2027-01-01 06:59:59'],
            'UTC_day_rollover' => ['2026-12-03 07:00:00'],
            'leap_day_end' => ['2028-02-29 23:59:59'],
        ];
    }

    #[DataProvider('calendarInstants')]
    public function test_all_demographic_requests_share_wib_today_and_configured_age_bound(string $instant): void
    {
        Carbon::setTestNow(Carbon::parse($instant, 'Asia/Jakarta'));
        foreach ([18, 120] as $maxAge) {
            config(['community.max_age_years' => $maxAge]);
            $today = now('Asia/Jakarta')->startOfDay();
            $minimum = $today->copy()->subYearsNoOverflow($maxAge);
            foreach ([new UpdateProfileRequest, new UpdateDependentRequest, new ManualMemberRequest] as $request) {
                $rules = ['birth_date' => $request->rules()['birth_date']];
                foreach ([$today->toDateString(), $today->copy()->subDay()->toDateString(), $minimum->toDateString(), null] as $accepted) {
                    $validator = Validator::make(['birth_date' => $accepted], $rules);
                    $this->assertTrue($validator->passes(), $request::class.' rejected valid WIB birth date '.($accepted ?? 'null').': '.json_encode($validator->errors()->all()));
                }
                foreach ([$today->copy()->addDay()->toDateString(), $minimum->copy()->subDay()->toDateString()] as $rejected) {
                    $this->assertFalse(Validator::make(['birth_date' => $rejected], $rules)->passes(), $request::class.' accepted out-of-range WIB birth date '.$rejected);
                }
            }
        }
    }

    /** @return array<string,array{string}> */
    public static function demographicSurfaces(): array
    {
        return ['own_profile' => ['profile'], 'manual_member' => ['manual'], 'guardian_dependent' => ['dependent']];
    }

    #[DataProvider('demographicSurfaces')]
    public function test_newborn_birth_date_persists_through_each_authorized_http_entry_at_wib_midnight(string $surface): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-03 00:00:00', 'Asia/Jakarta'));
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create();
        Passport::actingAs($actor);
        if ($surface === 'profile') {
            $this->putJson('/api/v1/auth/me', ['birth_date' => '2026-12-03'])->assertOk()->assertJsonPath('data.birth_date', '2026-12-03');
            $this->assertSame('2026-12-03', $actor->fresh()->birth_date?->toDateString());
            $this->putJson('/api/v1/auth/me', ['birth_date' => '2026-12-04'])->assertUnprocessable();

            return;
        }
        $community = Community::factory()->approved()->for($actor, 'owner')->create();
        $url = '/api/v1/communities/'.$community->id.'/manual-members';
        $data = ['name' => 'Bayi Fixture WIB', 'birth_date' => $surface === 'manual' ? '2026-12-03' : '2026-12-02', 'idempotency_key' => (string) Str::uuid()];
        $created = $this->postJson($url, $data)->assertOk()->assertJsonPath('data.birth_date', $data['birth_date'])->json('data');
        if ($surface === 'manual') {
            $this->assertDatabaseHas('member_identities', ['id' => $created['member_identity_id'], 'birth_date' => '2026-12-03']);
            $this->putJson($url.'/'.$created['id'], ['birth_date' => '2026-12-04'])->assertUnprocessable();

            return;
        }
        $guardian = User::factory()->create();
        GuardianGrant::query()->create(['member_identity_id' => $created['member_identity_id'], 'guardian_user_id' => $guardian->id, 'community_member_id' => $created['id'], 'verified_at' => now()]);
        Passport::actingAs($guardian);
        $url = '/api/v1/dependents/'.$created['member_identity_id'];
        $this->putJson($url, ['birth_date' => '2026-12-03'])->assertOk()->assertJsonPath('data.birth_date', '2026-12-03');
        $this->assertDatabaseHas('member_identities', ['id' => $created['member_identity_id'], 'birth_date' => '2026-12-03']);
        $this->putJson($url, ['birth_date' => '2026-12-04'])->assertUnprocessable();
        Passport::actingAs(User::factory()->create());
        $this->putJson($url, ['birth_date' => '2026-12-03'])->assertForbidden();
    }
}
