<?php

use App\Http\Controllers\Api\V1\BillingController;
use Illuminate\Support\Facades\Route;

Route::prefix('communities/{community}/billing')->whereNumber('community')->group(function (): void {
    Route::get('/', [BillingController::class, 'show']);
    Route::post('renew', [BillingController::class, 'renew'])->middleware('throttle:30,1');
    Route::post('pay', [BillingController::class, 'pay'])->middleware('throttle:30,1');
    Route::patch('settings', [BillingController::class, 'settings']);
    Route::get('inactive', [BillingController::class, 'inactive']);
    Route::post('lifecycle', [BillingController::class, 'lifecycle']);
});
