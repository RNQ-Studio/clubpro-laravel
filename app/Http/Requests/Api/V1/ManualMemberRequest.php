<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManualMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'external_reference'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->string($field)->toString())]);
            }
        }
        if (is_string($this->input('account_email'))) {
            $this->merge(['account_email' => strtolower(trim($this->string('account_email')->toString()))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.now()->subYears((int) config('community.max_age_years', 120))->toDateString()],
            'gender' => ['sometimes', 'nullable', Rule::enum(Gender::class)],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'account_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'idempotency_key' => [$creating ? 'required' : 'sometimes', 'uuid'],
        ];
    }
}
