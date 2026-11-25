<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TrainingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $key = ['idempotency_key' => ['required', 'string', 'min:1', 'max:150']];

        return match ($this->route()?->getActionMethod()) {
            'catalog' => ['expected_version' => ['required', 'integer', 'min:0'], 'enabled' => ['required', 'boolean'], 'packages' => ['required', 'array', 'size:3'], 'packages.*.sessions' => ['required', 'integer', Rule::in([7, 14, 21]), 'distinct'], 'packages.*.price' => ['required', 'integer', 'min:1', 'max:1000000000']],
            'location' => ['name' => ['required', 'string', 'max:150'], 'address' => ['nullable', 'string', 'max:500'], 'active' => ['required', 'boolean'], 'expected_version' => [$this->route('trainingLocation') !== null ? 'required' : 'sometimes', 'integer', 'min:1'], 'manager_member_ids' => ['present', 'array', 'max:100'], 'manager_member_ids.*' => ['integer', 'min:1', 'distinct'], 'splits' => ['present', 'array', 'max:100'], 'splits.*.member_id' => ['required', 'integer', 'min:1', 'distinct'], 'splits.*.amount' => ['required', 'integer', 'min:0', 'max:1000000000']],
            'session' => [...$key, 'title' => ['required', 'string', 'max:150'], 'location_id' => ['required', 'integer', 'min:1'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at']],
            'purchase' => [...$key, 'sessions' => ['required', 'integer', Rule::in([7, 14, 21])], 'expected_price' => ['required', 'integer', 'min:1', 'max:1000000000'], 'catalog_version' => ['required', 'integer', 'min:1']],
            'confirm' => [...$key, 'member_id' => ['required', 'integer', 'min:1']],
            'correct' => [...$key, 'reason' => ['required', 'string', 'min:10', 'max:2000']],
            default => $key,
        };
    }
}
