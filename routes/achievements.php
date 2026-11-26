<?php

use App\Http\Controllers\Api\V1\AchievementController;
use App\Http\Controllers\Api\V1\AdvertisementController;
use Illuminate\Support\Facades\Route;

Route::get('admin/achievement-rules', [AchievementController::class, 'rules']);
Route::put('admin/achievement-rules', [AchievementController::class, 'configure']);
Route::get('communities/{community}/achievement-members', [AchievementController::class, 'members'])->whereNumber('community');
Route::get('achievements/me', [AchievementController::class, 'own']);
Route::get('communities/{community}/achievements', [AchievementController::class, 'records'])->whereNumber('community');
Route::post('communities/{community}/achievements', [AchievementController::class, 'store'])->whereNumber('community');
Route::post('communities/{community}/achievements/{achievement}/correct', [AchievementController::class, 'correct'])->whereNumber('community')->whereUuid('achievement');
Route::get('advertisements', [AdvertisementController::class, 'own']);
Route::post('advertisements', [AdvertisementController::class, 'store']);
Route::get('advertisements/{advertisement}', [AdvertisementController::class, 'show'])->whereUuid('advertisement');
Route::put('advertisements/{advertisement}', [AdvertisementController::class, 'update'])->whereUuid('advertisement');
Route::delete('advertisements/{advertisement}', [AdvertisementController::class, 'destroy'])->whereUuid('advertisement');
