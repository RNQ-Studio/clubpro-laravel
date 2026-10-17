<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AppRelease;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartAppReleaseUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AppRelease::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'app' => ['required', 'string', Rule::in(array_keys((array) config('app_release.apps')))],
            'channel' => ['required', 'string', Rule::in((array) config('app_release.channels'))],
            'version_name' => ['required', 'string', 'max:50', 'regex:/^\d+(\.\d+){1,3}(-[0-9A-Za-z.\-]+)?$/'],
            // Batas atas versionCode Android.
            'version_code' => ['required', 'integer', 'min:1', 'max:2100000000'],
            'file_size' => ['required', 'integer', 'min:1', 'max:'.((int) config('app_release.max_size_mb') * 1024 * 1024)],
            'file_sha256' => ['required', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
            'signing_sha256' => ['nullable', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
            'git_sha' => ['nullable', 'string', 'regex:/^[a-f0-9]{7,40}(-dirty)?$/'],
            'release_notes' => ['nullable', 'string', 'max:2000'],
            'allow_signing_change' => ['sometimes', 'boolean'],
        ];
    }
}
