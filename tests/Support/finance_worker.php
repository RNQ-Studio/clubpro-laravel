<?php

use App\Models\Community;
use App\Models\IncomingReceipt;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\IncomingReceiptService;
use App\Services\LedgerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// A separate OS process and PostgreSQL connection, used only by concurrency regression tests.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_contains((string) config('database.connections.pgsql.database'), 'test')) {
    throw new RuntimeException('Concurrency worker requires an isolated test database.');
}
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['storage_root']]);
Storage::forgetDisk('local');
DB::statement("SET application_name TO '".$input['application_name']."'");
file_put_contents($input['directory'].'/'.$input['worker'].'.ready', 'ready');
$deadline = microtime(true) + 15;
while (! is_file($input['directory'].'/start')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Worker barrier timed out.');
    }
    usleep(10000);
}
try {
    $actor = User::query()->findOrFail($input['actor_id']);
    if ($input['operation'] === 'approve') {
        $receipt = app(IncomingReceiptService::class)->verify($actor, IncomingReceipt::query()->findOrFail($input['receipt_id']), 100000, 'RACE-RECEIPT', $input['key']);
        $result = ['status' => 200, 'journal_id' => $receipt->journal_id];
    } else {
        $journal = app(LedgerService::class)->transfer($actor, $input['key'], 'concurrency_debit', LedgerAccount::query()->findOrFail($input['from_id']), LedgerAccount::query()->findOrFail($input['to_id']), 70000, Community::query()->findOrFail($input['community_id']));
        $result = ['status' => 200, 'journal_id' => $journal->id];
    }
} catch (ValidationException $exception) {
    $result = ['status' => 422, 'error' => 'validation'];
} catch (HttpExceptionInterface $exception) {
    $result = ['status' => $exception->getStatusCode(), 'error' => $exception->getMessage()];
} catch (Throwable $exception) {
    $result = ['status' => 500, 'error' => get_class($exception).': '.$exception->getMessage()];
}
file_put_contents($input['directory'].'/'.$input['worker'].'.result', json_encode($result, JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_THROW_ON_ERROR);
