<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class BillingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dues_amount' => ['required', 'integer', 'min:0', 'max:100000000'],
            'entry_fee' => ['required', 'integer', 'min:0', 'max:100000000'],
            'period_months' => ['required', 'integer', 'min:1', 'max:12'],
            'dues_bank_name' => ['required', 'string', 'max:60'],
            'dues_account_number' => ['required', 'string', 'regex:/^[0-9]{5,30}$/'],
            'dues_account_name' => ['required', 'string', 'max:100'],
        ];
    }
}
