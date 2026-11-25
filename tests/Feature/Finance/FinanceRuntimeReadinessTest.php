<?php

namespace Tests\Feature\Finance;

use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinanceRuntimeReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_confirms_postgresql_and_completed_financial_schema(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->getJson('/api/v1/finance/options')->assertOk()
            ->assertJsonPath('data.database_engine', 'pgsql')
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.billing_version', 2)
            ->assertJsonPath('data.period_transition_version', 2)
            ->assertJsonPath('data.integer_amounts', true)
            ->assertJsonPath('data.payment_gateway', false);
    }

    public function test_readiness_is_false_until_the_legacy_period_data_fix_has_completed(): void
    {
        DB::table('migrations')->where('migration', '2026_11_26_040002_transition_legacy_billing_periods')->delete();
        $this->getJson('/api/v1/finance/options')->assertOk()->assertJsonPath('data.billing_version', 2)->assertJsonPath('data.period_transition_version', 0)->assertJsonPath('data.ready', false);
    }

    public function test_unsupported_database_is_never_reported_finance_ready(): void
    {
        DB::partialMock()->shouldReceive('getDriverName')->once()->andReturn('sqlite');
        $this->getJson('/api/v1/finance/options')->assertOk()
            ->assertJsonPath('data.database_engine', 'sqlite')
            ->assertJsonPath('data.ready', false);
    }

    public function test_unsupported_database_cannot_silently_skip_financial_locking(): void
    {
        $database = DB::partialMock();
        $database->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $database->shouldNotReceive('select');
        try {
            app(LedgerService::class)->lockOperation('unsupported-database-fixture');
            $this->fail('Unsupported database must stop financial mutations.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertStringContainsString('Transaksi belum diproses', $exception->getMessage());
        }
    }
}
