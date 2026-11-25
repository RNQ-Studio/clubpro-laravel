<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class BillingLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['subject' => ['required', 'in:member,platform'], 'member_id' => ['required_if:subject,member', 'nullable', 'integer'], 'action' => ['required', 'in:retain,archive'], 'reason' => ['required', 'string', 'min:3', 'max:500']];
    }
}
