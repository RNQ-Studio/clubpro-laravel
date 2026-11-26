<?php

use App\Http\Controllers\Api\V1\MarketplaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('marketplace')->group(function (): void {
    Route::get('products', [MarketplaceController::class, 'own']);
    Route::get('products/{product}', [MarketplaceController::class, 'ownProduct'])->whereUuid('product');
    Route::post('products', [MarketplaceController::class, 'store'])->middleware('throttle:30,1');
    Route::put('products/{product}', [MarketplaceController::class, 'update'])->whereUuid('product')->middleware('throttle:30,1');
    Route::post('quote', [MarketplaceController::class, 'quote'])->middleware('throttle:60,1');
    Route::get('orders', [MarketplaceController::class, 'orders']);
    Route::post('orders', [MarketplaceController::class, 'checkout'])->middleware('throttle:20,1');
    Route::get('orders/by-key/{key}', [MarketplaceController::class, 'byKey'])->whereUuid('key');
    Route::get('orders/{order}', [MarketplaceController::class, 'order'])->whereUuid('order');
});
