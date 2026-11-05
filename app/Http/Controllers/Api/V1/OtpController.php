<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\OtpService;
use App\Services\UserActivityService;
use App\Support\ApiResponse;
use App\Support\Enums\OtpPurpose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OtpController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly AuthService $authService,
        private readonly UserActivityService $activity,
    ) {}

    /**
     * @unauthenticated
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'purpose' => ['required', 'string', Rule::enum(OtpPurpose::class)],
        ]);

        try {
            $this->otpService->generate(
                $data['phone'],
                OtpPurpose::from($data['purpose']),
                $request->ip(),
            );
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 429, code: 'TOO_MANY_REQUESTS');
        }

        return ApiResponse::success(null, 'OTP sent successfully');
    }

    /**
     * @unauthenticated
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'size:6'],
            'purpose' => ['required', 'string', Rule::enum(OtpPurpose::class)],
        ]);

        $purpose = OtpPurpose::from($data['purpose']);
        $valid = $this->otpService->verify($data['phone'], $data['code'], $purpose);

        if (! $valid) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired OTP code.'],
            ]);
        }

        if ($purpose === OtpPurpose::Login) {
            $user = User::query()->where('phone', $data['phone'])->first();

            if ($user === null) {
                return ApiResponse::error('No account found for this phone number.', 404, code: 'NOT_FOUND');
            }

            if (! $user->is_active) {
                return ApiResponse::error('Your account is inactive.', 403, code: 'FORBIDDEN');
            }

            $tokens = $this->authService->issueTokenForUser($user);
            $this->activity->recordLogin($user, UserActivityService::clientInfo($request));

            return ApiResponse::success($tokens, 'Login successful');
        }

        return ApiResponse::success(null, 'OTP verified successfully');
    }

    public function updatePhone(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($user->getKey())],
        ]);

        $user->update(['phone' => $data['phone'], 'phone_verified_at' => null]);

        try {
            $this->otpService->generate($data['phone'], OtpPurpose::VerifyPhone, $request->ip());
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 429, code: 'TOO_MANY_REQUESTS');
        }

        return ApiResponse::success(new UserResource($user->refresh()), 'Phone updated. OTP sent for verification.');
    }

    public function verifyPhone(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        if ($user->phone === null) {
            return ApiResponse::error('No phone number set on this account.', 422);
        }

        $valid = $this->otpService->verify($user->phone, $data['code'], OtpPurpose::VerifyPhone);

        if (! $valid) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired OTP code.'],
            ]);
        }

        $user->update(['phone_verified_at' => now()]);

        return ApiResponse::success(new UserResource($user->refresh()), 'Phone verified successfully');
    }
}
