<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PinRequest;
use App\Models\User;
use App\Services\Auth\PinService;
use App\Services\SensitiveMailGuard;
use App\Services\UserActivityService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class PinController extends Controller
{
    public function __construct(private readonly PinService $pins) {}

    public function options(SensitiveMailGuard $mail): JsonResponse
    {
        return ApiResponse::success(['pin_length' => 6, 'email_recovery_available' => $mail->isAvailable(),
            'account_recovery_available' => true, 'in_app_invitations' => true]);
    }

    public function login(PinRequest $request): JsonResponse
    {
        return ApiResponse::success($this->pins->login((string) $request->string('identifier'), (string) $request->string('pin'), $request->ip() ?? '', [
            ...$request->only(['device_id', 'platform', 'os_version', 'app_version', 'device_name', 'push_token']),
            ...UserActivityService::clientInfo($request),
        ]), 'Login berhasil');
    }

    public function change(PinRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->pins->change($user, (string) $request->string('pin'), $request->input('current_pin'), $request->input('current_password'), $request->ip() ?? '', $request->bearerToken(), $request->input('verification_method'), $request->boolean('use_recent_google_auth'));

        return ApiResponse::success(['pin_enabled' => true], 'PIN disimpan');
    }

    public function recovery(PinRequest $request): JsonResponse
    {
        $this->pins->recover((string) $request->string('identifier'));

        return ApiResponse::success(null, 'Jika akun memenuhi syarat, kode pemulihan dikirim ke email terverifikasi.');
    }

    public function reset(PinRequest $request): JsonResponse
    {
        if (! $this->pins->reset((string) $request->string('identifier'), (string) $request->string('code'), (string) $request->string('pin'))) {
            throw ValidationException::withMessages(['code' => ['Identitas atau kode tidak sesuai, kedaluwarsa, atau sudah dipakai.']]);
        }

        return ApiResponse::success(null, 'PIN diperbarui. Silakan masuk kembali.');
    }
}
