<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class MarketplaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $method = $this->route()?->getActionMethod();
        if (in_array($method, ['store', 'update'], true)) {
            return ['community_id' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:150', 'regex:/\S/'], 'description' => ['required', 'string', 'max:5000', 'regex:/\S/'], 'photo_asset_id' => ['required', 'uuid'], 'price' => ['required', 'integer', 'min:1', 'max:'.(int) config('finance.max_transaction')], 'stock' => ['required', 'integer', 'min:0', 'max:1000000'], 'is_active' => ['required', 'boolean'], ...($method === 'update' ? ['expected_version' => ['required', 'integer', 'min:1']] : [])];
        }
        $rules = ['product_id' => ['required', 'uuid'], 'quantity' => ['required', 'integer', 'min:1', 'max:1000000']];

        return $method === 'quote' ? $rules : [...$rules, 'expected_version' => ['required', 'integer', 'min:1'], 'expected_unit_price' => ['required', 'integer', 'min:1'], 'pin' => ['required', 'digits:6'], 'idempotency_key' => ['required', 'uuid'], 'acknowledged' => ['required', 'accepted']];
    }
}
