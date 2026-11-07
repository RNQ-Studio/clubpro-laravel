<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RejectDuesPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Tulis alasan penolakan agar anggota tahu yang harus diperbaiki.',
            'note.min' => 'Alasan penolakan minimal 5 karakter.',
            'note.max' => 'Alasan penolakan maksimal 500 karakter.',
        ];
    }
}
