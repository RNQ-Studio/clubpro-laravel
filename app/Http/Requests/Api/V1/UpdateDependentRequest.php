<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDependentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.now()->subYears((int) config('community.max_age_years', 120))->toDateString()],
            'gender' => ['sometimes', 'nullable', Rule::enum(Gender::class)],
            'is_public' => ['sometimes', 'boolean'],
        ];
    }
}
