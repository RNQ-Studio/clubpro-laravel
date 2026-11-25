<?php

namespace Tests\Feature\Finance;

use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Independent checks of PostgreSQL constraints, bypassing the service deliberately. */
class LedgerDatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_command_succeeds_for_balanced_empty_ledger(): void
    {
        $this->artisan('finance:reconcile')->assertSuccessful();
    }

    public function test_reconciliation_command_fails_when_cached_balance_has_no_journal_source(): void
    {
        LedgerAccount::query()->create([
            'account_key' => 'test:unexplained-cache', 'kind' => 'identity',
            'bucket' => 'available', 'balance' => 1, 'allow_negative' => false,
        ]);
        $this->artisan('finance:reconcile')->assertFailed();
    }

    public function test_database_rejects_negative_spendable_balance(): void
    {
        $this->rejects(function (): void {
            LedgerAccount::query()->create([
                'account_key' => 'test:negative', 'kind' => 'identity',
                'bucket' => 'available', 'balance' => -1, 'allow_negative' => false,
            ]);
        }, 'ledger_nonnegative');
    }

    public function test_database_rejects_unbalanced_journal_at_commit_boundary(): void
    {
        $actor = User::factory()->create();
        $this->rejects(function () use ($actor): void {
            $this->journal($actor, false);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, 'Unbalanced ledger journal');
    }

    public function test_database_rejects_editing_posted_journal(): void
    {
        $id = $this->journal(User::factory()->create());
        $this->rejects(fn () => DB::table('ledger_journals')->where('id', $id)->update(['operation' => 'tamper']), 'immutable');
    }

    public function test_database_rejects_deleting_posted_entry(): void
    {
        $id = $this->journal(User::factory()->create());
        $this->rejects(fn () => DB::table('ledger_entries')->where('journal_id', $id)->delete(), 'immutable');
    }

    private function rejects(callable $operation, string $expected): void
    {
        $this->assertSame('pgsql', DB::getDriverName(), 'Financial invariant evidence requires PostgreSQL.');
        try {
            DB::transaction($operation);
        } catch (QueryException $error) {
            $this->assertStringContainsString($expected, $error->getMessage());

            return;
        }
        $this->fail('Database accepted an invalid financial mutation.');
    }

    private function journal(User $actor, bool $balanced = true): string
    {
        $id = (string) Str::uuid();
        DB::table('ledger_journals')->insert([
            'id' => $id, 'actor_id' => $actor->id, 'operation' => 'integrity_fixture',
            'idempotency_key' => $id, 'payload_hash' => hash('sha256', $id),
            'metadata' => '{}', 'created_at' => now(),
        ]);
        if ($balanced) {
            $cash = LedgerAccount::query()->create([
                'account_key' => 'test:cash:'.$id, 'kind' => 'custody',
                'bucket' => 'cash', 'allow_negative' => true,
            ]);
            $recipient = LedgerAccount::query()->create([
                'account_key' => 'test:recipient:'.$id, 'kind' => 'identity',
                'bucket' => 'available', 'allow_negative' => false,
            ]);
            DB::table('ledger_entries')->insert([
                ['journal_id' => $id, 'account_id' => $cash->id, 'amount' => -100],
                ['journal_id' => $id, 'account_id' => $recipient->id, 'amount' => 100],
            ]);
        }

        return $id;
    }
}
