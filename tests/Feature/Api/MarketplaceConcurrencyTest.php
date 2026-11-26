<?php

namespace Tests\Feature\Api;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\LedgerAccount;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceProduct;
use App\Models\MemberIdentity;
use App\Models\MemberTransfer;
use App\Models\Payout;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MarketplaceFixture;
use Tests\TestCase;

/** Real independent OS processes; barrier verifies overlapping PostgreSQL lock waits. */
class MarketplaceConcurrencyTest extends TestCase
{
    use DatabaseTruncation, MarketplaceFixture;

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();
        parent::tearDown();
    }

    public function test_two_parallel_creates_at_four_global_active_can_only_reach_five(): void
    {
        $this->fixture();
        for ($i = 0; $i < 4; $i++) {
            $this->product();
        }
        $foreign = Community::factory()->approved()->for($this->founder, 'owner')->create();
        CommunityMember::factory()->for($foreign)->for($this->recipient)->create();
        $a = $this->productData();
        $b = [...$this->productData(), 'community_id' => $foreign->id];
        DB::beginTransaction();
        MemberIdentity::query()->whereKey(MemberIdentity::forUser($this->recipient)->id)->lockForUpdate()->firstOrFail();
        $result = $this->race(['operation' => 'create', 'actor_id' => $this->recipient->id], [['data' => $a], ['data' => $b]]);
        $this->assertStatuses([200, 422], $result);
        $this->assertSame(5, MarketplaceProduct::query()->where('is_active', true)->count());
    }

    public function test_two_parallel_creates_at_five_both_fail_without_sixth(): void
    {
        $this->fixture();
        for ($i = 0; $i < 5; $i++) {
            $this->product();
        }
        $a = $this->productData();
        $b = $this->productData();
        DB::beginTransaction();
        MemberIdentity::query()->whereKey(MemberIdentity::forUser($this->recipient)->id)->lockForUpdate()->firstOrFail();
        $result = $this->race(['operation' => 'create', 'actor_id' => $this->recipient->id], [['data' => $a], ['data' => $b]]);
        $this->assertStatuses([422, 422], $result);
        $this->assertSame(5, MarketplaceProduct::query()->count());
    }

    public function test_two_buyers_racing_for_last_stock_only_one_pays(): void
    {
        $this->fixture();
        $product = $this->product(1, 70000);
        $this->admin->forceFill(['pin_hash' => $this->sender->pin_hash])->save();
        $this->fund($this->admin, $this->community, $this->founder, 100000);
        $a = $this->checkoutData($product);
        $b = $this->checkoutData($product);
        DB::beginTransaction();
        MarketplaceProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
        $result = $this->race(['operation' => 'checkout'], [['actor_id' => $this->sender->id, 'data' => $a], ['actor_id' => $this->admin->id, 'data' => $b]]);
        $this->assertStatuses([200, 422], $result);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(1, MarketplaceOrder::query()->count());
        $this->assertSame(130000, $this->ledger->wallet($this->sender)['available'] + $this->ledger->wallet($this->admin)['available']);
        $this->assertSame(70000, $this->ledger->wallet($this->recipient)['available']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_same_checkout_key_concurrently_returns_same_order_without_double_stock_or_money(): void
    {
        $this->fixture();
        $product = $this->product(2, 70000);
        $data = $this->checkoutData($product);
        DB::beginTransaction();
        MarketplaceProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
        $result = $this->race(['operation' => 'checkout', 'actor_id' => $this->sender->id, 'data' => $data, 'preserve_key' => true]);
        $this->assertStatuses([200, 200], $result);
        $this->assertSame($result[0]['id'], $result[1]['id']);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(1, MarketplaceOrder::query()->count());
        $this->assertSame(30000, $this->ledger->wallet($this->sender)['available']);
        $this->assertSame(70000, $this->ledger->wallet($this->recipient)['available']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_checkout_competing_with_payout_cannot_spend_held_money_twice(): void
    {
        $this->fixture();
        $product = $this->product(2, 70000);
        $this->lockSender();
        $result = $this->race(['actor_id' => $this->sender->id], [['operation' => 'checkout', 'data' => $this->checkoutData($product)], ['operation' => 'payout', 'data' => $this->payoutData(70000)]]);
        $this->assertStatuses([200, 422], $result);
        $this->assertSame(1, MarketplaceOrder::query()->count() + Payout::query()->count());
        $wallet = $this->ledger->wallet($this->sender);
        $this->assertSame(30000, $wallet['available']);
        $this->assertSame(70000, $wallet['held'] + $this->ledger->wallet($this->recipient)['available']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_checkout_competing_with_transfer_cannot_overdraw_available(): void
    {
        $this->fixture();
        $product = $this->product(2, 70000);
        $this->lockSender();
        $result = $this->race(['actor_id' => $this->sender->id], [['operation' => 'checkout', 'data' => $this->checkoutData($product)], ['operation' => 'transfer', 'data' => $this->transferData(70000)]]);
        $this->assertStatuses([200, 422], $result);
        $this->assertSame(1, MarketplaceOrder::query()->count() + MemberTransfer::query()->count());
        $this->assertSame(30000, $this->ledger->wallet($this->sender)['available']);
        $this->assertSame(70000, $this->ledger->wallet($this->recipient)['available']);
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
        $directory = sys_get_temp_dir().'/clubpro-marketplace-race-'.Str::uuid();
        mkdir($directory, 0700);
        $applicationPrefix = 'marketplace-race-'.substr(str_replace('-', '', (string) Str::uuid()), 0, 16);
        $processes = [];
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_DATABASE' => (string) config('database.connections.pgsql.database'), 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync']);
        try {
            foreach ([0, 1] as $worker) {
                $input = [...$shared, ...$individual[$worker], 'storage_root' => Storage::disk('local')->path(''), 'public_root' => Storage::disk('public')->path(''), 'worker' => $worker, 'directory' => $directory, 'application_name' => $applicationPrefix.'-'.$worker, 'key' => (string) Str::uuid()];
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/marketplace_worker.php'), json_encode($input, JSON_THROW_ON_ERROR)], [0 => ['pipe', 'r'], 1 => ['file', $directory.'/'.$worker.'.stdout', 'w'], 2 => ['file', $directory.'/'.$worker.'.stderr', 'w']], $pipes, base_path(), $env);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = $process;
            }
            $this->await(fn (): bool => count(glob($directory.'/*.ready')) === 2);
            touch($directory.'/start');
            try {
                $this->await(fn (): bool => (int) DB::selectOne('SELECT count(*) AS waiting FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type = ?', [$applicationPrefix.'%', 'Lock'])->waiting === 2);
            } catch (\Throwable $error) {
                $diagnostics = [];
                foreach (glob($directory.'/*.result') as $file) {
                    $diagnostics[] = file_get_contents($file);
                }
                foreach (glob($directory.'/*.stderr') as $file) {
                    $diagnostics[] = file_get_contents($file);
                }
                $this->fail('Marketplace race barrier: '.json_encode($diagnostics));
            }
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
