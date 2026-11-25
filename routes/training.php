<?php

use App\Http\Controllers\Api\V1\TrainingController;
use Illuminate\Support\Facades\Route;

Route::prefix('communities/{community}/training')->whereNumber('community')->group(function (): void {
    Route::get('/', [TrainingController::class, 'overview']);
    Route::put('catalog', [TrainingController::class, 'catalog']);
    Route::post('locations', [TrainingController::class, 'location']);
    Route::put('locations/{trainingLocation}', [TrainingController::class, 'location'])->whereNumber('trainingLocation');
    Route::post('sessions', [TrainingController::class, 'session']);
    Route::post('purchases', [TrainingController::class, 'purchase']);
    Route::post('sessions/{trainingSession}/check-in', [TrainingController::class, 'checkIn'])->whereNumber('trainingSession');
    Route::post('sessions/{trainingSession}/confirm', [TrainingController::class, 'confirm'])->whereNumber('trainingSession');
    Route::post('attendances/{trainingAttendance}/correct', [TrainingController::class, 'correct'])->whereUuid('trainingAttendance');
    Route::get('report', [TrainingController::class, 'report']);
});
