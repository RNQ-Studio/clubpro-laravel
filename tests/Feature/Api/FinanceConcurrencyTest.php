<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\LedgerAccount;
use App\Models\LedgerJournal;
use App\Models\MemberIdentity;
use App\Models\User;
use App\Services\IncomingReceiptService;
use App\Services\LedgerService;
use App\Support\Enums\StorageType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();
        parent::tearDown();
    }

    public function test_two_real_processes_overlap_on_approval_and_debit_locks_without_double_spend(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertStringContainsString('test', (string) config('database.connections.pgsql.database'));
        Storage::fake('local');
        Storage::fake('gcs');
        $this->seed(RolePermissionSeeder::class);
        $founder = User::factory()->create();
        $community = Community::factory()->approved()->for($founder, 'owner')->create();
        $payer = User::factory()->create();
        CommunityMember::factory()->create(['community_id' => $community->id, 'user_id' => $payer->id]);
        $proof = Asset::factory()->create(['user_id' => $payer->id, 'storage_type' => StorageType::Private]);
        Storage::disk('local')->put($proof->path, 'concurrency-fixture:'.Str::uuid());
        $receipt = app(IncomingReceiptService::class)->submit($payer, $community, ['claimed_amount' => 100000, 'proof_asset_id' => $proof->id, 'bank_reference' => 'CLAIM-RACE', 'idempotency_key' => (string) Str::uuid()]);
        DB::beginTransaction();
        DB::table('incoming_receipts')->where('id', $receipt->id)->lockForUpdate()->first();
        $approvals = $this->race(['operation' => 'approve', 'actor_id' => $founder->id, 'receipt_id' => $receipt->id]);
        $this->assertSame([200, 200], array_column($approvals, 'status'));
        $this->assertSame($approvals[0]['journal_id'], $approvals[1]['journal_id']);
        $this->assertSame(1, LedgerJournal::query()->count());
        $this->assertSame(100000, app(LedgerService::class)->wallet($payer)['available']);
        $source = app(LedgerService::class)->walletAccount(MemberIdentity::forUser($payer));
        $receiverOne = app(LedgerService::class)->walletAccount(MemberIdentity::forUser(User::factory()->create()));
        $receiverTwo = app(LedgerService::class)->walletAccount(MemberIdentity::forUser(User::factory()->create()));
        DB::beginTransaction();
        LedgerAccount::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
        $debits = $this->race(['operation' => 'debit', 'actor_id' => $payer->id, 'community_id' => $community->id, 'from_id' => $source->id], [['to_id' => $receiverOne->id], ['to_id' => $receiverTwo->id]]);
        $statuses = array_column($debits, 'status');
        sort($statuses);
        $this->assertSame([200, 422], $statuses, json_encode($debits));
        $this->assertSame(30000, $source->fresh()->balance);
        $this->assertSame(70000, $receiverOne->fresh()->balance + $receiverTwo->fresh()->balance);
        $this->assertSame(2, LedgerJournal::query()->count());
        $this->assertTrue(app(LedgerService::class)->reconcile()['balanced']);
    }

    /** @param array<string,mixed> $shared @param list<array<string,mixed>> $individual @return list<array<string,mixed>> */
    private function race(array $shared, array $individual = [[], []]): array
    {
        $directory = sys_get_temp_dir().'/clubpro-ledger-race-'.Str::uuid();
        mkdir($directory, 0700);
        $applicationPrefix = 'clubpro-race-'.substr(str_replace('-', '', (string) Str::uuid()), 0, 16);
        $processes = [];
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_DATABASE' => (string) config('database.connections.pgsql.database'), 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync']);
        try {
            foreach ([0, 1] as $worker) {
                $input = [...$shared, ...$individual[$worker], 'storage_root' => Storage::disk('local')->path(''), 'worker' => $worker, 'directory' => $directory, 'application_name' => $applicationPrefix.'-'.$worker, 'key' => (string) Str::uuid()];
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/finance_worker.php'), json_encode($input, JSON_THROW_ON_ERROR)], [0 => ['pipe', 'r'], 1 => ['file', $directory.'/'.$worker.'.stdout', 'w'], 2 => ['file', $directory.'/'.$worker.'.stderr', 'w']], $pipes, base_path(), $env);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = $process;
            }
            $this->await(fn (): bool => count(glob($directory.'/*.ready')) === 2);
            touch($directory.'/start');
            // Both independent PostgreSQL backends must actually be waiting on row locks
            // held by the parent. This proves overlapping execution, not sequential requests.
            $this->await(fn (): bool => (int) DB::selectOne('SELECT count(*) AS waiting FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type = ?', [$applicationPrefix.'%', 'Lock'])->waiting === 2);
            $this->assertSame(2, (int) DB::selectOne('SELECT count(*) AS waiting FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type = ?', [$applicationPrefix.'%', 'Lock'])->waiting);
            DB::commit();
            $this->await(fn (): bool => count(glob($directory.'/*.result')) === 2);
            $results = [];
            foreach ([0, 1] as $worker) {
                $results[] = json_decode(file_get_contents($directory.'/'.$worker.'.result'), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
            }
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function await(callable $condition): void
    {
        $deadline = microtime(true) + 15;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                $this->fail('Real-process concurrency barrier timed out.');
            }
            usleep(20000);
        }
    }
}
