<?php

use App\Models\Community;
use App\Models\TrainingAttendance;
use App\Models\TrainingLocation;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\TrainingService;
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
    $community = Community::query()->findOrFail($input['community_id']);
    $training = app(TrainingService::class);
    $key = $input['request_key'] ?? $input['key'];
    if ($input['operation'] === 'purchase') {
        $purchase = $training->purchase($actor, $community, ['sessions' => 7, 'expected_price' => 100003, 'catalog_version' => 1, 'idempotency_key' => $key]);
        $result = ['status' => 200, 'purchase_id' => $purchase->id];
    } elseif ($input['operation'] === 'location_settings') {
        $training->saveLocation($actor, $community, ['expected_version' => 1, 'name' => 'Lapangan Fixture Revisi', 'address' => 'Lokasi Uji', 'active' => true, 'manager_member_ids' => [], 'splits' => [['member_id' => $input['recipient_id'], 'amount' => 9000]]], TrainingLocation::query()->findOrFail($input['location_id']));
        $result = ['status' => 200];
    } elseif ($input['operation'] === 'correct') {
        $attendance = $training->correct($actor, $community, TrainingAttendance::query()->findOrFail($input['attendance_id']), ['reason' => 'Koreksi teknis fixture konkurensi', 'idempotency_key' => $key]);
        $result = ['status' => 200, 'attendance_id' => $attendance->id];
    } else {
        $attendance = $training->confirm($actor, $community, TrainingSession::query()->findOrFail($input['session_id']), ['member_id' => $input['member_id'], 'idempotency_key' => $key]);
        $result = ['status' => 200, 'attendance_id' => $attendance->id];
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
