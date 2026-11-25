<?php

use App\Http\Controllers\Api\V1\MoneyMovementController;
use Illuminate\Support\Facades\Route;

Route::get('bank-accounts/primary', [MoneyMovementController::class, 'primary']);
Route::put('bank-accounts/primary', [MoneyMovementController::class, 'savePrimary'])->middleware('throttle:20,1');
Route::get('transfers/recipients', [MoneyMovementController::class, 'recipients'])->middleware('throttle:60,1');
Route::get('transfers', [MoneyMovementController::class, 'transfers']);
Route::post('transfers', [MoneyMovementController::class, 'transfer'])->middleware('throttle:20,1');
Route::get('transfers/{memberTransfer}', [MoneyMovementController::class, 'transferDetail'])->whereUuid('memberTransfer');
Route::get('payout-reconciliation', [MoneyMovementController::class, 'platformReconciliation']);
Route::get('payouts', [MoneyMovementController::class, 'payouts']);
Route::post('payouts', [MoneyMovementController::class, 'createPayout'])->middleware('throttle:20,1');
Route::get('payouts/{payout}', [MoneyMovementController::class, 'payout'])->whereUuid('payout');
Route::get('communities/{community}/payout-reconciliation', [MoneyMovementController::class, 'reconciliation'])->whereNumber('community');
Route::prefix('payout-parts/{part}')->whereUuid('part')->middleware('throttle:30,1')->group(function (): void {
    Route::post('process', [MoneyMovementController::class, 'process']);
    Route::post('uncertain', [MoneyMovementController::class, 'uncertain']);
    Route::post('fail', [MoneyMovementController::class, 'fail']);
    Route::post('cancel', [MoneyMovementController::class, 'cancel']);
    Route::post('proofs', [MoneyMovementController::class, 'submitProof']);
    Route::put('fragments/{fragment}', [MoneyMovementController::class, 'correctFragment'])->whereUuid('fragment');
    Route::post('fragments/{fragment}/verify', [MoneyMovementController::class, 'verifyFragment'])->whereUuid('fragment');
});
