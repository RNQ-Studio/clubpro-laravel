<?php

namespace App\Http\Requests\Api\V1;

use App\Services\LedgerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncomingReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'claimed_amount' => ['required', 'integer', 'min:1', 'max:'.min(LedgerService::MAX_SAFE_INTEGER, (int) config('finance.max_transaction'))],
            'proof_asset_id' => ['required', 'uuid'],
            'bank_reference' => ['required', 'string', 'max:150'],
            'idempotency_key' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
            'purpose' => ['sometimes', Rule::in(['topup', 'member', 'platform'])],
            'credit_excess' => ['sometimes', 'boolean'],
            'target_member_id' => ['nullable', 'integer', 'min:1'],
            'invoice_ids' => ['sometimes', 'array', 'max:1200'],
            'invoice_ids.*' => ['uuid'],
            'beneficiary_user_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
