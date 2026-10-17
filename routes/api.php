<?php

use App\Http\Controllers\Api\UserExcelController;
use App\Http\Controllers\Api\V1\AppController;
use App\Http\Controllers\Api\V1\AppReleaseController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OtpController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\TagController;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    /*
    |--------------------------------------------------------------------------
    | Excel Import & Export Routes (Tanpa Middleware Auth)
    |--------------------------------------------------------------------------
    | Jika Anda ingin memproteksi endpoint ini dengan middleware auth Passport di kemudian hari,
    | Anda bisa menambahkan middleware: ->middleware('auth:api')
    */
    Route::get('users/export', [UserExcelController::class, 'export']);
    Route::post('users/import', [UserExcelController::class, 'import']);

    Route::get('health', HealthController::class);

    // Unauthenticated app info endpoints (no maintenance check — needed to show maintenance message)
    Route::prefix('app')->group(function (): void {
        Route::get('version', [AppController::class, 'version'])->middleware('throttle:60,1');
        Route::get('config', [AppController::class, 'config'])->middleware('throttle:60,1');
        Route::get('releases/latest', [AppReleaseController::class, 'latest'])->middleware('throttle:60,1');
    });

    // Distribusi APK (rilis development) — upload ber-chunk oleh skrip rilis.
    Route::prefix('app/releases/uploads')->middleware('auth:api')->group(function (): void {
        Route::post('/', [AppReleaseController::class, 'startUpload'])->middleware('throttle:30,1');
        Route::get('{upload}', [AppReleaseController::class, 'showUpload'])->middleware('throttle:120,1');
        // Chunk bisa kecil (≤1 MB bila nginx membatasi body), jadi batasnya longgar.
        Route::post('{upload}/chunks', [AppReleaseController::class, 'uploadChunk'])->middleware('throttle:1200,1');
        Route::post('{upload}/complete', [AppReleaseController::class, 'completeUpload'])->middleware('throttle:30,1');
        Route::delete('{upload}', [AppReleaseController::class, 'abortUpload'])->middleware('throttle:30,1');
    });

    // OTP endpoints (unauthenticated, heavily throttled)
    Route::prefix('auth/otp')->middleware(['throttle:10,1', 'check.maintenance'])->group(function (): void {
        Route::post('send', [OtpController::class, 'send']);
        Route::post('verify', [OtpController::class, 'verify']);
    });

    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->middleware(['throttle:6,1', 'check.maintenance']);
        Route::post('login', [AuthController::class, 'login'])->middleware(['throttle:6,1', 'check.maintenance']);
        Route::post('refresh', [AuthController::class, 'refresh'])->middleware(['throttle:6,1', 'check.maintenance']);
        Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink'])
            ->middleware(['throttle:6,1', 'check.maintenance']);
        Route::post('reset-password', [PasswordResetController::class, 'reset'])
            ->middleware(['throttle:6,1', 'check.maintenance']);
        Route::get('password/reset/{token}', function (string $token) {
            return ApiResponse::success(['token' => $token], 'Password reset token received.');
        })->name('password.reset')->middleware(['check.maintenance']);

        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->name('verification.verify')
            ->middleware(['check.maintenance']);

        Route::middleware(['auth:api', 'check.maintenance'])->group(function (): void {
            Route::post('email/send-verification', [EmailVerificationController::class, 'sendVerification'])
                ->middleware('throttle:6,1');
            Route::post('email/verify', [EmailVerificationController::class, 'verify']);

            Route::get('me', [AuthController::class, 'me']);
            Route::put('me', [AuthController::class, 'updateProfile']);
            Route::post('avatar', [AuthController::class, 'uploadAvatar']);
            Route::post('change-password', [AuthController::class, 'changePassword']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('logout-all', [AuthController::class, 'logoutAll']);
            Route::post('phone', [OtpController::class, 'updatePhone']);
            Route::post('phone/verify', [OtpController::class, 'verifyPhone']);
        });
    });

    Route::apiResource('quotes', QuoteController::class);

    Route::middleware(['auth:api', 'check.maintenance'])->group(function (): void {
        Route::post('assets/upload', [AssetController::class, 'upload'])->middleware('throttle:30,1');

        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('articles', ArticleController::class);
        Route::apiResource('tags', TagController::class);

        Route::prefix('notifications')->group(function (): void {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('read-all', [NotificationController::class, 'markAllRead']);
            Route::post('{notification}/read', [NotificationController::class, 'markRead']);
        });
    });
});
