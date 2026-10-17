<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AvatarRequest;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\GoogleLoginRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RefreshTokenRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Asset;
use App\Models\User;
use App\Services\AssetDeletionService;
use App\Services\AssetUploadService;
use App\Services\Auth\AuthService;
use App\Services\Auth\Google\GoogleAuthService;
use App\Services\FileUploadService;
use App\Services\MemberProfileService;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly FileUploadService $fileUploadService,
        private readonly AssetUploadService $assetUploadService,
        private readonly AssetDeletionService $assetDeletionService,
    ) {}

    /**
     * @unauthenticated
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
        ]);

        event(new Registered($user));

        $tokens = $this->authService->login(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->only(['device_id', 'platform', 'os_version', 'app_version', 'device_name', 'push_token']),
        );

        $tokens['email_verified'] = false;

        return ApiResponse::success($tokens, 'Registration successful', 201);
    }

    /**
     * @unauthenticated
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $tokens = $this->authService->login(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->only(['device_id', 'platform', 'os_version', 'app_version', 'device_name', 'push_token']),
        );

        $user = User::query()->where('email', $request->string('email')->toString())->first();
        $tokens['email_verified'] = $user?->hasVerifiedEmail() ?? false;

        return ApiResponse::success($tokens, 'Login successful');
    }

    /**
     * Login / daftar dengan akun Google. Akun baru dibuat otomatis dari email
     * Google yang terverifikasi; `is_new_user` memberi tahu app untuk langsung
     * menampilkan form data diri.
     *
     * @unauthenticated
     */
    public function google(GoogleLoginRequest $request, GoogleAuthService $googleAuth): JsonResponse
    {
        $tokens = $googleAuth->login(
            $request->string('id_token')->toString(),
            $request->only(['device_id', 'platform', 'os_version', 'app_version', 'device_name', 'push_token']),
        );

        return ApiResponse::success([...$tokens, 'email_verified' => true], 'Login successful');
    }

    /**
     * @unauthenticated
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $tokens = $this->authService->refresh(
            $request->string('refresh_token')->toString(),
        );

        return ApiResponse::success($tokens, 'Token refreshed');
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(new UserResource($user), 'OK');
    }

    /**
     * Simpan sebagian data diri; isian yang tidak dikirim tidak berubah.
     */
    public function updateProfile(UpdateProfileRequest $request, MemberProfileService $profiles): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(
            new UserResource($profiles->update($user, $request->validated())),
            'Profile updated',
        );
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->string('current_password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update([
            'password' => $request->string('password')->toString(),
        ]);

        return ApiResponse::success(null, 'Password changed');
    }

    public function uploadAvatar(AvatarRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $oldAvatar = $user->avatar;

        $asset = $this->assetUploadService->upload(
            file: $request->file('avatar'),
            type: 'avatar',
            userId: $user->getKey(),
        );

        $user->update(['avatar' => $asset->id]);

        // Clean up old avatar if exists
        if ($oldAvatar !== null && $oldAvatar !== '') {
            if (Str::isUuid($oldAvatar)) {
                $oldAsset = Asset::find($oldAvatar);
                if ($oldAsset) {
                    $this->assetDeletionService->hardDelete($oldAsset);
                }
            } else {
                $this->fileUploadService->delete($oldAvatar);
            }
        }

        return ApiResponse::success(new UserResource($user->refresh()), 'Avatar updated');
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $deviceId = $request->string('device_id')->toString() ?: null;
        $this->authService->logout($user, $deviceId);

        return ApiResponse::success(null, 'Logged out');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authService->logoutAllDevices($user);

        return ApiResponse::success(null, 'Logged out from all devices');
    }
}
