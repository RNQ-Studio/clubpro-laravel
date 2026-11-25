<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class BillingPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['expected_amount' => ['sometimes', 'integer', 'min:1', 'max:1000000000'], 'target_member_id' => ['sometimes', 'nullable', 'integer'], 'scope' => ['required', 'in:member,platform'], 'invoice_ids' => ['sometimes', 'array', 'max:1200'], 'invoice_ids.*' => ['required', 'uuid', 'distinct'], 'idempotency_key' => ['required', 'uuid']];
    }
}
