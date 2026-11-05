<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Enums\CommunityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCommunitiesRequest extends FormRequest
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
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'nullable', Rule::enum(CommunityType::class)],
            'province_code' => ['sometimes', 'nullable', 'string', 'max:2'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
