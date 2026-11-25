<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCommunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:120'],
            'short_name' => ['sometimes', 'required', 'string', 'max:20'],
            'description' => ['sometimes', 'required', 'string', 'max:5000'],
            'address' => ['sometimes', 'required', 'string', 'max:500'],
            'contact_name' => ['sometimes', 'required', 'string', 'max:120'],
            'contact_phone' => ['sometimes', 'required', 'string', 'max:20'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ];
    }
}
