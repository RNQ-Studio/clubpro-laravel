<?php

use App\Http\Controllers\Api\V1\FinanceController;
use Illuminate\Support\Facades\Route;

Route::get('wallet', [FinanceController::class, 'wallet']);
Route::get('wallet/transactions', [FinanceController::class, 'transactions']);
Route::get('wallet/transactions/{ledgerJournal}', [FinanceController::class, 'transaction'])->whereUuid('ledgerJournal');
Route::get('wallet/receipts', [FinanceController::class, 'ownReceipts']);
Route::get('communities/{community}/wallet', [FinanceController::class, 'communityWallet'])->whereNumber('community');
foreach (['topups', 'receipts'] as $path) {
    Route::prefix('communities/{community}/'.$path)->whereNumber('community')->group(function (): void {
        Route::get('/', [FinanceController::class, 'index']);
        Route::post('/', [FinanceController::class, 'store']);
        Route::get('{incomingReceipt}', [FinanceController::class, 'show'])->whereUuid('incomingReceipt');
        Route::post('{incomingReceipt}/verify', [FinanceController::class, 'verify'])->whereUuid('incomingReceipt');
        Route::post('{incomingReceipt}/reject', [FinanceController::class, 'reject'])->whereUuid('incomingReceipt');
    });
}
