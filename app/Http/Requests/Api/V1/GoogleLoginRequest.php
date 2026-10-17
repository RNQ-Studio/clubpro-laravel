<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Enums\DevicePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GoogleLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ID token dari Google Sign-In (audiens = Web client ID / serverClientId).
            'id_token' => ['required', 'string', 'max:4096'],

            // Optional device info — sent by mobile clients
            'device_id' => ['sometimes', 'string', 'max:255'],
            'platform' => ['sometimes', 'string', Rule::enum(DevicePlatform::class)],
            'os_version' => ['sometimes', 'nullable', 'string', 'max:100'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:50'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'push_token' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
