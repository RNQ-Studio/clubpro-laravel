<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class AdvertisementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['community_id' => ['required', 'integer', 'min:1'], 'title' => ['required', 'string', 'max:120', 'regex:/\S/'], 'body' => ['required', 'string', 'max:2000', 'regex:/\S/'], 'photo_asset_id' => ['nullable', 'uuid'], 'target_product_id' => ['nullable', 'uuid'], 'is_active' => ['required', 'boolean'], ...($this->isMethod('PUT') ? ['expected_version' => ['required', 'integer', 'min:1']] : [])];
    }
}
