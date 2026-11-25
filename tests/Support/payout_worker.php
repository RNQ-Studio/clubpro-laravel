<?php

use App\Models\PayoutFragment;
use App\Models\PayoutPart;
use App\Models\User;
use App\Services\MoneyMovementService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_contains((string) config('database.connections.pgsql.database'), 'test')) {
    throw new RuntimeException('Payout worker requires an isolated test database.');
}
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['storage_root']]);
Storage::forgetDisk('local');
DB::statement("SET application_name TO '".$input['application_name']."'");
file_put_contents($input['directory'].'/'.$input['worker'].'.ready', 'ready');
$deadline = microtime(true) + 15;
while (! is_file($input['directory'].'/start')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Payout worker barrier timed out.');
    }
    usleep(10000);
}
try {
    $actor = User::query()->findOrFail($input['actor_id']);
    $service = app(MoneyMovementService::class);
    $data = $input['data'];
    if (! ($input['preserve_key'] ?? false)) {
        $data['idempotency_key'] = $input['key'];
    }
    if ($input['operation'] === 'payout') {
        $record = $service->createPayout($actor, $data);
    } elseif ($input['operation'] === 'transfer') {
        $record = $service->transfer($actor, $data);
    } elseif ($input['operation'] === 'verify') {
        $record = $service->verifyFragment($actor, PayoutPart::query()->findOrFail($input['part_id']), PayoutFragment::query()->findOrFail($input['fragment_id']), $data);
    } else {
        $record = $service->transition($actor, PayoutPart::query()->findOrFail($input['part_id']), $input['operation'], $data);
    }
    $result = ['status' => 200, 'id' => $record->id];
} catch (ValidationException) {
    $result = ['status' => 422, 'error' => 'validation'];
} catch (HttpExceptionInterface $exception) {
    $result = ['status' => $exception->getStatusCode(), 'error' => $exception->getMessage()];
} catch (Throwable $exception) {
    $result = ['status' => 500, 'error' => get_class($exception)];
}
file_put_contents($input['directory'].'/'.$input['worker'].'.result', json_encode($result, JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_THROW_ON_ERROR);
