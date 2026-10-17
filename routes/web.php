<?php

use App\Http\Controllers\AppReleaseDownloadController;
use App\Http\Controllers\PublicArticleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/articles', [PublicArticleController::class, 'index'])->name('public.articles.index');
Route::get('/articles/{slug}', [PublicArticleController::class, 'show'])->name('public.articles.show');

// Link unduhan APK terbaru, mis. /apk/main/dev. Link tetap; isinya diganti tiap rilis.
Route::get('/apk/{app}/{channel?}', AppReleaseDownloadController::class)
    ->where(['app' => '[a-z0-9-]+', 'channel' => '[a-z0-9-]+'])
    ->middleware('throttle:60,1')
    ->name('app-releases.download');
