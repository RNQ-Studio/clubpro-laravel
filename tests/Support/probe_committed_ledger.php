<?php

/** Independent process probe: an already committed journal cannot receive new entries.
 * Run only with APP_ENV=testing DB_DATABASE=<isolated_test_database> DB_URL=.
 * Does not reset a database; fixture rows stay in the disposable test database.
 */

use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = config('database.connections.'.config('database.default').'.database');
if (! $app->environment('testing') || ! is_string($database) || ! str_ends_with($database, '_test') || DB::getDriverName() !== 'pgsql') {
    throw new RuntimeException('Refusing financial probe outside an isolated PostgreSQL test database.');
}
$ledger = app(LedgerService::class);
$actor = User::factory()->create();
$reference = (string) Str::uuid();
$source = $ledger->clearingAccount('integrity-probe:'.$reference, 'test-handler', 'receivable');
$recipient = $ledger->clearingAccount('integrity-probe:'.$reference, 'test-handler', 'payable');
$journal = $ledger->post($actor, $reference, 'integrity_probe', [
    ['account_id' => $source->id, 'amount' => -100],
    ['account_id' => $recipient->id, 'amount' => 100],
]);
if (DB::transactionLevel() !== 0) {
    throw new RuntimeException('Probe must observe a fully committed journal.');
}
try {
    DB::transaction(function () use ($journal, $source): void {
        DB::table('ledger_entries')->insert(['journal_id' => $journal->id, 'account_id' => $source->id, 'amount' => -1]);
    });
} catch (QueryException $error) {
    if (! str_contains($error->getMessage(), 'cannot receive additional entries')) {
        throw $error;
    }
    if (DB::table('ledger_entries')->where('journal_id', $journal->id)->count() !== 2 || (int) DB::table('ledger_entries')->where('journal_id', $journal->id)->sum('amount') !== 0) {
        throw new RuntimeException('Rejected append left a posting behind.');
    }
    echo json_encode(['probe' => 'committed_journal_append', 'result' => 'PASS', 'postings' => 2, 'sum' => (int) DB::table('ledger_entries')->where('journal_id', $journal->id)->sum('amount')], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}
throw new RuntimeException('Database accepted an append to a previously committed journal.');
