<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class MoneyMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('idempotency_key') && $this->header('Idempotency-Key') !== null) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $method = $this->route()?->getActionMethod();
        $key = ['idempotency_key' => ['required', 'uuid']];
        $amount = ['required', 'integer', 'min:1', 'max:'.(int) config('finance.max_transaction', 1000000000)];
        $version = ['expected_version' => ['required', 'integer', 'min:1']];
        $destination = ['account_number' => ['required', 'string', 'regex:/^[0-9]{5,34}$/'], 'account_name' => ['required', 'string', 'max:150']];
        $proof = [...$key, ...$version, ...$destination, 'amount' => $amount, 'bank_reference' => ['required', 'string', 'max:150', 'regex:/\S/'], 'note' => ['required', 'string', 'max:500', 'regex:/\S/']];

        return match ($method) {
            'savePrimary' => [...$destination, 'bank_code' => ['required', 'string', 'max:30'], 'bank_name' => ['required', 'string', 'max:100'], 'expected_version' => ['required', 'integer', 'min:0'], 'acknowledged' => ['required', 'accepted']],
            'transfer' => [...$key, 'community_id' => ['required', 'integer', 'min:1'], 'recipient_identity_id' => ['required', 'integer', 'min:1'], 'recipient_community_id' => ['required', 'integer', 'min:1'], 'amount' => $amount, 'pin' => ['required', 'digits:6']],
            'createPayout' => [...$key, 'community_id' => ['required', 'integer', 'min:1'], 'amount' => $amount, 'bank_account_version' => ['required', 'integer', 'min:1'], 'pin' => ['required', 'digits:6'], 'acknowledged' => ['required', 'accepted']],
            'submitProof' => [...$proof, 'proof_asset_id' => ['required', 'uuid']],
            'correctFragment' => $proof,
            'verifyFragment' => [...$key, ...$version],
            default => [...$key, ...$version, 'note' => ['required', 'string', 'max:500', 'regex:/\S/'], 'confirmed_unsent' => ['sometimes', 'accepted']],
        };
    }
}
