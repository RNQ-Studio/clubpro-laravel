<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\LedgerJournal;
use App\Models\LedgerReservation;
use App\Models\MemberIdentity;
use App\Models\TrainingAttendance;
use App\Models\TrainingPurchase;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\IncomingReceiptService;
use App\Services\LedgerService;
use App\Services\TrainingService;
use App\Support\Enums\StorageType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Independent OS processes must overlap on actual PostgreSQL locks. */
class TrainingConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private User $founder;

    private User $athlete;

    private Community $community;

    private CommunityMember $member;

    private int $location;

    private TrainingService $training;

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();
        parent::tearDown();
    }

    private function fixture(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertStringContainsString('test', (string) config('database.connections.pgsql.database'));
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('gcs');
        $this->seed(RolePermissionSeeder::class);
        $this->founder = User::factory()->create();
        $this->community = Community::factory()->approved()->for($this->founder, 'owner')->create(['dues_bank_name' => 'Bank Fixture', 'dues_account_number' => '000001', 'dues_account_name' => 'Komunitas Fixture']);
        $this->athlete = User::factory()->create();
        $this->member = CommunityMember::factory()->create(['community_id' => $this->community->id, 'user_id' => $this->athlete->id]);
        $this->training = app(TrainingService::class);
        $this->training->saveCatalog($this->founder, $this->community, ['expected_version' => 0, 'enabled' => true, 'packages' => $this->prices()]);
        $location = $this->training->saveLocation($this->founder, $this->community, ['name' => 'Lapangan Fixture', 'address' => 'Lokasi Uji', 'active' => true, 'manager_member_ids' => [], 'splits' => [['member_id' => $this->community->members()->where('user_id', $this->founder->id)->firstOrFail()->id, 'amount' => 10000]]]);
        $this->location = $location['id'];
        $proof = Asset::factory()->create(['user_id' => $this->athlete->id, 'storage_type' => StorageType::Private]);
        Storage::disk('local')->put($proof->path, 'training-concurrency-fixture:'.Str::uuid());
        $receipt = app(IncomingReceiptService::class)->submit($this->athlete, $this->community, ['claimed_amount' => 500000, 'proof_asset_id' => $proof->id, 'bank_reference' => 'TRAINING-CLAIM', 'idempotency_key' => (string) Str::uuid()]);
        app(IncomingReceiptService::class)->verify($this->founder, $receipt, 500000, 'TRAINING-RACE-FUND', (string) Str::uuid());
    }

    /** @return list<array{sessions:int,price:int}> */
    private function prices(): array
    {
        return [['sessions' => 7, 'price' => 100003], ['sessions' => 14, 'price' => 180001], ['sessions' => 21, 'price' => 240001]];
    }

    private function purchase(): TrainingPurchase
    {
        return $this->training->purchase($this->athlete, $this->community, ['sessions' => 7, 'expected_price' => 100003, 'catalog_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function newSession(): TrainingSession
    {
        $data = $this->training->createSession($this->founder, $this->community, ['title' => 'Sesi Fixture '.Str::uuid(), 'location_id' => $this->location, 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String(), 'idempotency_key' => (string) Str::uuid()]);

        $session = TrainingSession::query()->findOrFail($data['id']);
        $this->assertSame((new \DateTimeImmutable($data['starts_at']))->getTimestamp(), $session->starts_at->getTimestamp(), 'Waktu sesi harus mempertahankan instant setelah reload pada timezone database mana pun.');

        return $session;
    }

    private function confirm(TrainingSession $session): TrainingAttendance
    {
        return $this->training->confirm($this->founder, $this->community, $session, ['member_id' => $this->member->id, 'idempotency_key' => (string) Str::uuid()]);
    }

    public function test_two_sessions_compete_for_last_quota_only_once_and_reconcile_every_rupiah(): void
    {
        $this->fixture();
        $purchase = $this->purchase();
        for ($i = 0; $i < 6; $i++) {
            $this->confirm($this->newSession());
        }
        $one = $this->newSession();
        $two = $this->newSession();
        $this->assertSame(1, $purchase->fresh()->remaining_sessions);
        $before = app(LedgerService::class)->wallet($this->athlete)['available'];
        DB::beginTransaction();
        Community::query()->whereKey($this->community->id)->lockForUpdate()->firstOrFail();
        $results = $this->race(['operation' => 'confirm', 'actor_id' => $this->founder->id, 'community_id' => $this->community->id, 'member_id' => $this->member->id], [['session_id' => $one->id], ['session_id' => $two->id]]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 422], $statuses, json_encode($results));
        $this->assertSame(0, $purchase->fresh()->remaining_sessions);
        $this->assertSame(0, $purchase->fresh()->remaining_amount);
        $this->assertSame(7, TrainingAttendance::query()->where('status', 'confirmed')->count());
        $this->assertSame(100003, (int) TrainingAttendance::query()->where('status', 'confirmed')->sum('allocation'));
        $this->assertSame($before, app(LedgerService::class)->wallet($this->athlete)['available']);
        $this->assertSame(0, app(LedgerService::class)->wallet($this->athlete)['reserved']);
        $this->assertTrue(app(LedgerService::class)->reconcile()['balanced']);
    }

    public function test_same_session_two_different_keys_never_distribute_twice(): void
    {
        $this->fixture();
        $purchase = $this->purchase();
        $session = $this->newSession();
        DB::beginTransaction();
        Community::query()->whereKey($this->community->id)->lockForUpdate()->firstOrFail();
        $results = $this->race(['operation' => 'confirm', 'actor_id' => $this->founder->id, 'community_id' => $this->community->id, 'member_id' => $this->member->id, 'session_id' => $session->id]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses, json_encode($results));
        $this->assertSame(6, $purchase->fresh()->remaining_sessions);
        $this->assertSame(85717, $purchase->fresh()->remaining_amount);
        $this->assertSame(1, TrainingAttendance::query()->count());
        $this->assertTrue(app(LedgerService::class)->reconcile()['balanced']);
    }

    public function test_same_purchase_key_race_reserves_only_once(): void
    {
        $this->fixture();
        $key = (string) Str::uuid();
        DB::beginTransaction();
        Community::query()->whereKey($this->community->id)->lockForUpdate()->firstOrFail();
        $results = $this->race(['operation' => 'purchase', 'actor_id' => $this->athlete->id, 'community_id' => $this->community->id, 'request_key' => $key]);
        $this->assertSame([200, 200], array_column($results, 'status'), json_encode($results));
        $this->assertSame($results[0]['purchase_id'], $results[1]['purchase_id']);
        $this->assertSame(1, TrainingPurchase::query()->count());
        $this->assertSame(399997, app(LedgerService::class)->wallet($this->athlete)['available']);
        $this->assertSame(100003, app(LedgerService::class)->wallet($this->athlete)['reserved']);
        $this->assertSame(2, LedgerJournal::query()->count());
        $this->assertTrue(app(LedgerService::class)->reconcile()['balanced']);
    }

    public function test_concurrent_correction_replay_restores_quota_and_reserve_once_without_refund(): void
    {
        $this->fixture();
        $purchase = $this->purchase();
        $attendance = $this->confirm($this->newSession());
        DB::beginTransaction();
        Community::query()->whereKey($this->community->id)->lockForUpdate()->firstOrFail();
        $results = $this->race(['operation' => 'correct', 'actor_id' => $this->founder->id, 'community_id' => $this->community->id, 'attendance_id' => $attendance->id, 'request_key' => (string) Str::uuid()]);
        $this->assertSame([200, 200], array_column($results, 'status'), json_encode($results));
        $this->assertSame(7, $purchase->fresh()->remaining_sessions);
        $this->assertSame(100003, $purchase->fresh()->remaining_amount);
        $this->assertSame('corrected', $attendance->fresh()->status);
        $this->assertSame(399997, app(LedgerService::class)->wallet($this->athlete)['available']);
        $this->assertSame(100003, app(LedgerService::class)->wallet($this->athlete)['reserved']);
        $this->assertSame(4, LedgerJournal::query()->count());
        $this->assertTrue(app(LedgerService::class)->reconcile()['balanced']);
    }

    public function test_location_setting_change_and_confirmation_take_one_consistent_snapshot(): void
    {
        $this->fixture();
        $this->purchase();
        $session = $this->newSession();
        $founderMember = $this->community->members()->where('user_id', $this->founder->id)->firstOrFail();
        DB::beginTransaction();
        Community::query()->whereKey($this->community->id)->lockForUpdate()->firstOrFail();
        $results = $this->race(['actor_id' => $this->founder->id, 'community_id' => $this->community->id], [
            ['operation' => 'confirm', 'session_id' => $session->id, 'member_id' => $this->member->id],
            ['operation' => 'location_settings', 'location_id' => $this->location, 'recipient_id' => $founderMember->id],
        ]);
        $this->assertSame([200, 200], array_column($results, 'status'), json_encode($results));
        $attendance = TrainingAttendance::query()->firstOrFail();
        $snapshot = $attendance->snapshot;
        $this->assertContains($snapshot['location_version'], [1, 2]);
        $expectedSplit = $snapshot['location_version'] === 1 ? 10000 : 9000;
        $this->assertSame($expectedSplit, $snapshot['splits'][0]['amount']);
        $this->assertSame(14286, array_sum(array_column($snapshot['splits'], 'amount')));
        $this->assertSame(14286 - $expectedSplit, $snapshot['residual']);
        $this->assertSame(14286, $attendance->allocation);
        $this->assertTrue(app(LedgerService::class)->reconcile()['balanced']);
    }

    public function test_unused_package_funds_are_unspendable_for_buyer_and_recipient(): void
    {
        $this->fixture();
        $this->purchase();
        $ledger = app(LedgerService::class);
        foreach ([[$this->athlete, 400000], [$this->founder, 1]] as [$actor, $amount]) {
            try {
                $ledger->transfer($actor, (string) Str::uuid(), 'fixture_spend_before_training', $ledger->walletAccount(MemberIdentity::forUser($actor)), $ledger->communityAccount($this->community), $amount, $this->community);
                $this->fail('Cadangan paket belum digunakan tidak boleh dibelanjakan sebagai saldo tersedia.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('amount', $exception->errors());
            }
        }
        $this->assertSame(399997, $ledger->wallet($this->athlete)['available']);
        $this->assertSame(100003, $ledger->wallet($this->athlete)['reserved']);
        $this->assertSame(0, $ledger->wallet($this->founder)['available']);
        $this->assertSame(2, LedgerJournal::query()->count());
        $this->assertTrue($ledger->reconcile()['balanced']);
    }

    public function test_trusted_ledger_allocation_and_restore_are_replay_safe_independently_of_service_guards(): void
    {
        $this->fixture();
        $purchase = $this->purchase();
        $attendance = $this->confirm($this->newSession());
        $ledger = app(LedgerService::class);
        $reservation = LedgerReservation::query()->findOrFail($purchase->reservation_id);
        $original = LedgerJournal::query()->findOrFail($attendance->journal_id);
        $metadata = array_diff_key($original->metadata, array_flip(['reservation_id', 'allocations']));
        $again = $ledger->allocateReservation($this->founder, $reservation, $original->metadata['allocations'], $original->idempotency_key, $metadata);
        $this->assertSame($original->id, $again->id);
        $this->assertSame(85717, $reservation->fresh()->remaining);
        $attendance = $this->training->correct($this->founder, $this->community, $attendance, ['reason' => 'Koreksi teknis replay fixture', 'idempotency_key' => (string) Str::uuid()]);
        $correction = LedgerJournal::query()->findOrFail($attendance->correction_journal_id);
        $restoreMetadata = array_diff_key($correction->metadata, array_flip(['reservation_id', 'original_journal_id']));
        $restored = $ledger->restoreTrainingAllocation($this->founder, $reservation, $original, $correction->idempotency_key, $restoreMetadata);
        $this->assertSame($correction->id, $restored->id);
        $this->assertSame(100003, $reservation->fresh()->remaining);
        $this->assertSame(4, LedgerJournal::query()->count());
        $this->assertTrue($ledger->reconcile()['balanced']);
    }

    /** @param array<string,mixed> $shared @param list<array<string,mixed>> $individual @return list<array<string,mixed>> */
    private function race(array $shared, array $individual = [[], []]): array
    {
        $directory = sys_get_temp_dir().'/clubpro-training-race-'.Str::uuid();
        mkdir($directory, 0700);
        $applicationPrefix = 'clubpro-race-'.substr(str_replace('-', '', (string) Str::uuid()), 0, 16);
        $processes = [];
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_DATABASE' => (string) config('database.connections.pgsql.database'), 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync']);
        try {
            foreach ([0, 1] as $worker) {
                $input = [...$shared, ...$individual[$worker], 'storage_root' => Storage::disk('local')->path(''), 'worker' => $worker, 'directory' => $directory, 'application_name' => $applicationPrefix.'-'.$worker, 'key' => (string) Str::uuid()];
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/training_worker.php'), json_encode($input, JSON_THROW_ON_ERROR)], [0 => ['pipe', 'r'], 1 => ['file', $directory.'/'.$worker.'.stdout', 'w'], 2 => ['file', $directory.'/'.$worker.'.stderr', 'w']], $pipes, base_path(), $env);
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
