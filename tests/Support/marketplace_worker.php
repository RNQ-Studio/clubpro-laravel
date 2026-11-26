<?php

use App\Models\MarketplaceProduct;
use App\Models\User;
use App\Services\MarketplaceService;
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
    throw new RuntimeException('Marketplace worker requires isolated PostgreSQL test database.');
}
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['storage_root'], 'filesystems.disks.public.root' => $input['public_root']]);
Storage::forgetDisk('local');
Storage::forgetDisk('public');
DB::statement("SET application_name TO '".$input['application_name']."'");
file_put_contents($input['directory'].'/'.$input['worker'].'.ready', 'ready');
$deadline = microtime(true) + 15;
while (! is_file($input['directory'].'/start')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Marketplace worker barrier timed out.');
    }
    usleep(10000);
}
try {
    $actor = User::query()->findOrFail($input['actor_id']);
    $data = $input['data'];
    if (! ($input['preserve_key'] ?? false)) {
        $data['idempotency_key'] = $input['key'];
    }
    $record = match ($input['operation']) {
        'create' => app(MarketplaceService::class)->save($actor, $data),
        'update' => app(MarketplaceService::class)->save($actor, $data, MarketplaceProduct::query()->findOrFail($input['product_id'])),
        'checkout' => app(MarketplaceService::class)->checkout($actor, $data),
        'payout' => app(MoneyMovementService::class)->createPayout($actor, $data),
        'transfer' => app(MoneyMovementService::class)->transfer($actor, $data),
        default => throw new RuntimeException('Unknown marketplace operation'),
    };
    $result = ['status' => 200, 'id' => $record->id];
} catch (ValidationException) {
    $result = ['status' => 422, 'error' => 'validation'];
} catch (HttpExceptionInterface $error) {
    $result = ['status' => $error->getStatusCode(), 'error' => $error->getMessage()];
} catch (Throwable $error) {
    $result = ['status' => 500, 'error' => get_class($error).':'.$error->getMessage()];
}
file_put_contents($input['directory'].'/'.$input['worker'].'.result', json_encode($result, JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_THROW_ON_ERROR);
