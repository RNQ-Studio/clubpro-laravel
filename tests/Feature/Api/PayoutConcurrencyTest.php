<?php

namespace Tests\Feature\Api;

use App\Models\LedgerAccount;
use App\Models\LedgerJournal;
use App\Models\MemberIdentity;
use App\Models\MemberTransfer;
use App\Models\Payout;
use App\Models\PayoutFragment;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\PayoutFixture;
use Tests\TestCase;

/** Independent OS processes overlap on real PostgreSQL locks, never sequential mocks. */
class PayoutConcurrencyTest extends TestCase
{
    use DatabaseTruncation, PayoutFixture;

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();
        parent::tearDown();
    }

    public function test_two_payouts_competing_for_the_same_available_money_cannot_over_reserve(): void
    {
        $this->fixture();
        $this->lockSender();
        $results = $this->race(['operation' => 'payout', 'actor_id' => $this->sender->id, 'data' => $this->payoutData()]);
        $this->assertStatuses([200, 422], $results);
        $this->assertSame(1, Payout::query()->count());
        $this->assertSame(30000, $this->ledger->wallet($this->sender)['available']);
        $this->assertSame(70000, $this->ledger->wallet($this->sender)['held']);
        $this->assertSame(2, LedgerJournal::query()->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_payout_and_transfer_competing_for_the_same_money_commit_only_one(): void
    {
        $this->fixture();
        $this->lockSender();
        $results = $this->race(['actor_id' => $this->sender->id], [['operation' => 'payout', 'data' => $this->payoutData()], ['operation' => 'transfer', 'data' => $this->transferData()]]);
        $this->assertStatuses([200, 422], $results);
        $wallet = $this->ledger->wallet($this->sender);
        $received = $this->ledger->wallet($this->recipient)['available'];
        $this->assertSame(30000, $wallet['available']);
        $this->assertSame(70000, $wallet['held'] + $received);
        $this->assertSame(1, Payout::query()->count() + MemberTransfer::query()->count());
        $this->assertSame(2, LedgerJournal::query()->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_identical_payout_application_retry_reserves_once_even_when_requests_overlap(): void
    {
        $this->fixture();
        $key = (string) Str::uuid();
        $this->lockSender();
        $results = $this->race(['operation' => 'payout', 'actor_id' => $this->sender->id, 'data' => $this->payoutData(70000, $key), 'preserve_key' => true]);
        $this->assertStatuses([200, 200], $results);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, Payout::query()->count());
        $this->assertSame(30000, $this->ledger->wallet($this->sender)['available']);
        $this->assertSame(70000, $this->ledger->wallet($this->sender)['held']);
        $this->assertSame(2, LedgerJournal::query()->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_two_staff_verifying_the_same_fragment_finalize_it_once(): void
    {
        $this->fixture();
        $payout = $this->payout();
        $part = $this->part($payout);
        $fragment = $this->fragment($part, 70000);
        $version = $part->fresh()->version;
        DB::beginTransaction();
        Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
        $results = $this->race(['operation' => 'verify', 'part_id' => $part->id, 'fragment_id' => $fragment->id, 'data' => ['expected_version' => $version]], [['actor_id' => $this->founder->id], ['actor_id' => $this->admin->id]]);
        $statuses = array_column($results, 'status');
        $this->assertContains(200, $statuses, json_encode($results));
        foreach ($statuses as $status) {
            $this->assertContains($status, [200, 409], json_encode($results));
        }
        $this->assertSame('succeeded', $payout->fresh()->status);
        $this->assertSame(70000, $payout->fresh()->verified_amount);
        $this->assertSame(1, PayoutFragment::query()->where('status', 'verified')->count());
        $this->assertSame(30000, $this->ledger->wallet($this->sender)['available']);
        $this->assertSame(0, $this->ledger->wallet($this->sender)['held']);
        $this->assertSame(3, LedgerJournal::query()->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_failure_release_and_verification_race_cannot_both_spend_and_restore_money(): void
    {
        $this->fixture();
        $payout = $this->payout();
        $part = $this->part($payout);
        $fragment = $this->fragment($part, 70000);
        $version = $part->fresh()->version;
        DB::beginTransaction();
        Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
        $results = $this->race(['actor_id' => $this->founder->id, 'part_id' => $part->id], [
            ['operation' => 'verify', 'fragment_id' => $fragment->id, 'data' => ['expected_version' => $version]],
            ['operation' => 'fail', 'data' => ['expected_version' => $version, 'confirmed_unsent' => true, 'note' => 'Fixture terkonfirmasi belum terkirim.']],
        ]);
        $this->assertStatuses([200, 409], $results);
        $state = $payout->fresh();
        $wallet = $this->ledger->wallet($this->sender);
        $this->assertContains($state->status, ['succeeded', 'failed']);
        $this->assertSame(0, $wallet['held']);
        $this->assertSame($state->status === 'succeeded' ? 30000 : 100000, $wallet['available']);
        $this->assertSame($state->status === 'succeeded' ? 70000 : 0, $state->verified_amount);
        $this->assertSame(3, LedgerJournal::query()->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    private function lockSender(): void
    {
        DB::beginTransaction();
        LedgerAccount::query()->whereKey($this->ledger->walletAccount(MemberIdentity::forUser($this->sender))->id)->lockForUpdate()->firstOrFail();
    }

    /** @param list<int> $expected @param list<array<string,mixed>> $results */
    private function assertStatuses(array $expected, array $results): void
    {
        $actual = array_column($results, 'status');
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual, json_encode($results));
    }

    /** @param array<string,mixed> $shared @param list<array<string,mixed>> $individual @return list<array<string,mixed>> */
    private function race(array $shared, array $individual = [[], []]): array
    {
        $directory = sys_get_temp_dir().'/clubpro-payout-race-'.Str::uuid();
        mkdir($directory, 0700);
        $applicationPrefix = 'payout-race-'.substr(str_replace('-', '', (string) Str::uuid()), 0, 16);
        $processes = [];
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_DATABASE' => (string) config('database.connections.pgsql.database'), 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync']);
        try {
            foreach ([0, 1] as $worker) {
                $input = [...$shared, ...$individual[$worker], 'storage_root' => Storage::disk('local')->path(''), 'worker' => $worker, 'directory' => $directory, 'application_name' => $applicationPrefix.'-'.$worker, 'key' => (string) Str::uuid()];
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/payout_worker.php'), json_encode($input, JSON_THROW_ON_ERROR)], [0 => ['pipe', 'r'], 1 => ['file', $directory.'/'.$worker.'.stdout', 'w'], 2 => ['file', $directory.'/'.$worker.'.stderr', 'w']], $pipes, base_path(), $env);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = $process;
            }
            $this->await(fn (): bool => count(glob($directory.'/*.ready')) === 2);
            touch($directory.'/start');
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
                $this->fail('Real-process payout concurrency barrier timed out.');
            }
            usleep(20000);
        }
    }
}
