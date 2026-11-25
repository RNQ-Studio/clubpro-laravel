<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\UniqueAccountEmail;
use App\Support\Enums\DevicePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new UniqueAccountEmail],
            'password' => ['required', 'string', 'min:8', 'confirmed'],

            // Optional device info — sent by mobile clients for immediate login setup
            'device_id' => ['sometimes', 'string', 'max:255'],
            'platform' => ['sometimes', 'string', Rule::enum(DevicePlatform::class)],
            'os_version' => ['sometimes', 'nullable', 'string', 'max:100'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:50'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'push_token' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
