<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class PinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $action = $this->route()?->getActionMethod();
        if ($action === 'recovery') {
            return ['identifier' => ['required', 'string', 'max:255']];
        }
        $rules = ['pin' => ['required', 'string', 'regex:/^[0-9]{6}$/D']];
        if ($action === 'login' || $action === 'reset') {
            $rules['identifier'] = ['required', 'string', 'max:255'];
        }
        if ($action === 'reset') {
            $rules['code'] = ['required', 'string', 'regex:/^[0-9]{6}$/D'];
        }
        if ($action !== 'login') {
            $rules['pin'][] = 'confirmed';
            $rules['current_pin'] = ['nullable', 'string', 'max:100'];
            $rules['current_password'] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }
}
