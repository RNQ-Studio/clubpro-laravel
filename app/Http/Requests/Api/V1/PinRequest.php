<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
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
            $rules['verification_method'] = ['nullable', Rule::in(['pin', 'password', 'google'])];
            $rules['use_recent_google_auth'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'pin.required' => 'PIN wajib diisi.', 'pin.regex' => 'PIN harus tepat 6 angka.',
            'pin.confirmed' => 'Konfirmasi PIN tidak sama.', 'pin.string' => 'PIN harus berupa 6 angka.',
            'identifier.required' => 'Email atau telepon terverifikasi wajib diisi.',
            'identifier.max' => 'Pengenal akun terlalu panjang.',
            'code.required' => 'Kode pemulihan wajib diisi.', 'code.regex' => 'Kode pemulihan harus tepat 6 angka.',
            'verification_method.in' => 'Metode verifikasi tidak valid.',
        ];
    }
}
