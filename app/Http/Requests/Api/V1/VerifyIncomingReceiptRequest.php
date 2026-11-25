<?php

namespace App\Http\Requests\Api\V1;

use App\Services\LedgerService;
use Illuminate\Foundation\Http\FormRequest;

class VerifyIncomingReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'verified_amount' => ['required', 'integer', 'min:1', 'max:'.min(LedgerService::MAX_SAFE_INTEGER, (int) config('finance.max_transaction'))],
            'bank_reference' => ['required', 'string', 'max:150'],
            'idempotency_key' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
