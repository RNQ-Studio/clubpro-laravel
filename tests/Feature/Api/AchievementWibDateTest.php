<?php

namespace Tests\Feature\Api;

use App\Models\AchievementEntry;
use App\Models\AchievementRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MarketplaceFixture;
use Tests\TestCase;

class AchievementWibDateTest extends TestCase
{
    use MarketplaceFixture, RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $this->assertStringContainsString('test', (string) config('database.connections.pgsql.database'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(Carbon::parse('2026-12-31 00:00:00', 'Asia/Jakarta'));
        $this->fixture();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** @return array<string,array{string}> */
    public static function wibBoundaries(): array
    {
        return [
            'WIB midnight while UTC previous date' => ['2026-12-31 00:00:00'],
            'last second of UTC date lag' => ['2026-12-31 06:59:59'],
            'UTC midnight' => ['2026-12-31 07:00:00'],
            'last second WIB year' => ['2026-12-31 23:59:59'],
        ];
    }

    #[DataProvider('wibBoundaries')]
    public function test_current_wib_date_is_accepted_tomorrow_rejected_and_historical_rule_remains_original(string $instant): void
    {
        $this->travelTo(Carbon::parse($instant, 'Asia/Jakarta'));
        $central = User::factory()->create();
        $central->assignRole('super-admin');
        Passport::actingAs($central);
        $this->putJson('/api/v1/admin/achievement-rules', ['expected_version' => 1, 'points' => ['training_purchase' => 2, 'training_attendance' => 3, 'participation' => 4, 'champion' => 5], 'age_boundaries' => [12, 16, 19, 36, 51], 'reason' => 'Regresi kalender WIB prospektif'])->assertOk()->assertJsonPath('data.version', 2);
        Passport::actingAs($this->founder);
        $url = '/api/v1/communities/'.$this->community->id.'/achievements';
        $body = ['member_id' => $this->community->membershipOf($this->sender)->id, 'type' => 'participation', 'title' => 'Kegiatan Kalender WIB', 'reference' => 'WIB-TODAY', 'occurred_at' => now('Asia/Jakarta')->toDateString(), 'idempotency_key' => (string) Str::uuid()];
        $current = $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.points', 4)->json('data.id');
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.id', $current);
        $historical = $this->postJson($url, [...$body, 'reference' => 'WIB-YESTERDAY', 'occurred_at' => now('Asia/Jakarta')->subDay()->toDateString(), 'idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.points', 1)->json('data.id');
        $before = AchievementEntry::query()->count();
        $this->postJson($url, [...$body, 'reference' => 'WIB-TOMORROW', 'occurred_at' => now('Asia/Jakarta')->addDay()->toDateString(), 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
        $this->assertSame($before, AchievementEntry::query()->count());
        $this->assertSame(2, AchievementEntry::query()->where('source_id', $current)->value('rule_version'));
        $this->assertSame(1, AchievementEntry::query()->where('source_id', $historical)->value('rule_version'));
        $this->getJson('/api/v1/public/rankings?scope=members&period=all&community_id='.$this->community->id.'&as_of='.now('Asia/Jakarta')->toDateString())->assertOk()->assertJsonPath('data.items.0.points', 5);
        $this->getJson('/api/v1/public/rankings?period=all&as_of='.now('Asia/Jakarta')->addDay()->toDateString())->assertUnprocessable()->assertJsonValidationErrors('as_of');
        $this->assertTrue(AchievementRecord::query()->findOrFail($current)->occurred_at->equalTo(now()));
        $this->assertSame('2026-12-30 00:00:00', AchievementRecord::query()->findOrFail($historical)->occurred_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'));
        $this->assertSame(100000, $this->ledger->wallet($this->sender)['available']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
        $this->assertSame('pgsql', DB::getDriverName());
    }
}
