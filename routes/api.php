<?php

use App\Http\Controllers\Api\UserExcelController;
use App\Http\Controllers\Api\V1\Admin\BannerController as AdminBannerController;
use App\Http\Controllers\Api\V1\Admin\CommunityController as AdminCommunityController;
use App\Http\Controllers\Api\V1\Admin\SummaryController as AdminSummaryController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\AppController;
use App\Http\Controllers\Api\V1\AppReleaseController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BannerController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CommunityActivityController;
use App\Http\Controllers\Api\V1\CommunityController;
use App\Http\Controllers\Api\V1\CommunityDuesController;
use App\Http\Controllers\Api\V1\CommunityMemberController;
use App\Http\Controllers\Api\V1\CommunityPhotoController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MemberProfileController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OtpController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\RegionController;
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
        Route::post('google', [AuthController::class, 'google'])->middleware(['throttle:10,1', 'check.maintenance']);
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

        Route::middleware(['auth:api', 'check.maintenance', 'track.activity'])->group(function (): void {
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

    // Popup Paid Promotion di Beranda (publik, dikelola di Admin Panel dan back-office).
    Route::get('banners', [BannerController::class, 'index'])->middleware('throttle:60,1');

    // Pilihan provinsi & kabupaten/kota.
    Route::get('regions/indonesia', [RegionController::class, 'indonesia'])->middleware('throttle:60,1');

    // Berkas gambar di storage server; lewat API agar CORS berlaku untuk app web.
    Route::get('assets/{asset}/file', [AssetController::class, 'file'])
        ->whereUuid('asset')
        ->name('assets.file')
        ->middleware('throttle:600,1');

    Route::middleware(['auth:api', 'check.maintenance', 'track.activity'])->group(function (): void {
        Route::post('assets/upload', [AssetController::class, 'upload'])->middleware('throttle:30,1');

        Route::get('community-types', [CommunityController::class, 'types']);
        Route::get('communities', [CommunityController::class, 'index']);
        Route::get('communities/mine', [CommunityController::class, 'mine']);
        Route::get('communities/{community}', [CommunityController::class, 'show'])->whereNumber('community');
        Route::post('communities', [CommunityController::class, 'store'])->middleware('throttle:10,1');
        Route::get('communities/joined', [CommunityController::class, 'joined']);

        Route::prefix('communities/{community}')->where(['community' => '[0-9]+'])->scopeBindings()->group(function (): void {
            Route::post('join', [CommunityMemberController::class, 'join'])->middleware('throttle:30,1');
            Route::post('leave', [CommunityMemberController::class, 'leave']);

            Route::get('members', [CommunityMemberController::class, 'index']);
            Route::post('members/{member}/approve', [CommunityMemberController::class, 'approve'])->whereNumber('member');
            Route::put('members/{member}/role', [CommunityMemberController::class, 'updateRole'])->whereNumber('member');
            Route::delete('members/{member}', [CommunityMemberController::class, 'destroy'])->whereNumber('member');

            Route::post('photos', [CommunityPhotoController::class, 'store'])->middleware('throttle:30,1');
            Route::delete('photos/{asset}', [CommunityPhotoController::class, 'destroy'])->whereUuid('asset')->withoutScopedBindings();

            Route::get('activities', [CommunityActivityController::class, 'index']);
            Route::post('activities', [CommunityActivityController::class, 'store']);
            Route::put('activities/{activity}', [CommunityActivityController::class, 'update'])->whereNumber('activity');
            Route::delete('activities/{activity}', [CommunityActivityController::class, 'destroy'])->whereNumber('activity');

            Route::prefix('dues')->group(function (): void {
                Route::get('/', [CommunityDuesController::class, 'show']);
                Route::put('settings', [CommunityDuesController::class, 'updateSettings']);
                Route::post('payments', [CommunityDuesController::class, 'storePayment'])->middleware('throttle:20,1');
                Route::get('payments', [CommunityDuesController::class, 'payments']);
                Route::post('payments/{duesPayment}/approve', [CommunityDuesController::class, 'approve'])->whereNumber('duesPayment');
                Route::post('payments/{duesPayment}/reject', [CommunityDuesController::class, 'reject'])->whereNumber('duesPayment');
                Route::get('members', [CommunityDuesController::class, 'members']);
                Route::get('members/{user}', [CommunityDuesController::class, 'member'])->whereNumber('user')->withoutScopedBindings();
                Route::put('members/{user}/months/{month}', [CommunityDuesController::class, 'setMonth'])
                    ->whereNumber('user')
                    ->where('month', '[0-9]{4}-(0[1-9]|1[0-2])')
                    ->withoutScopedBindings();
                Route::put('members/{user}/entry-fee', [CommunityDuesController::class, 'setEntryFee'])->whereNumber('user')->withoutScopedBindings();
            });
        });

        Route::get('members/{user}', [MemberProfileController::class, 'show'])->whereNumber('user');

        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('articles', ArticleController::class);
        Route::apiResource('tags', TagController::class);

        Route::prefix('notifications')->group(function (): void {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('read-all', [NotificationController::class, 'markAllRead']);
            Route::post('{notification}/read', [NotificationController::class, 'markRead']);
        });

        // Admin Panel app web: khusus super admin.
        Route::prefix('admin')->middleware(['can:admin-panel', 'throttle:120,1'])->group(function (): void {
            Route::get('summary', AdminSummaryController::class);

            Route::get('users', [AdminUserController::class, 'index']);
            Route::get('users/{user}', [AdminUserController::class, 'show'])->whereNumber('user');
            Route::put('users/{user}/super-admin', [AdminUserController::class, 'updateSuperAdmin'])->whereNumber('user');
            Route::put('users/{user}/status', [AdminUserController::class, 'updateStatus'])->whereNumber('user');

            Route::get('communities', [AdminCommunityController::class, 'index']);
            Route::get('communities/{community}', [AdminCommunityController::class, 'show'])->whereNumber('community');
            Route::put('communities/{community}/review', [AdminCommunityController::class, 'review'])->whereNumber('community');

            Route::get('banners', [AdminBannerController::class, 'index']);
            Route::post('banners', [AdminBannerController::class, 'store']);
            Route::put('banners/order', [AdminBannerController::class, 'reorder']);
            Route::put('banners/settings', [AdminBannerController::class, 'settings']);
            Route::put('banners/{banner}', [AdminBannerController::class, 'update'])->whereNumber('banner');
            Route::delete('banners/{banner}', [AdminBannerController::class, 'destroy'])->whereNumber('banner');
        });
    });
});
