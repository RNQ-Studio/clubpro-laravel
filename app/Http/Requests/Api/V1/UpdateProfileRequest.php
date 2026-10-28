<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Enums\BloodType;
use App\Support\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pembaruan sebagian data diri: hanya isian yang dikirim yang diubah, dan
 * nilai kosong/null mengosongkan isian itu (kecuali nama & email).
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Nomor ditulis bebas di app ("0812-3456 7890") → simpan angka saja.
        foreach (['phone', 'emergency_contact_phone'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => preg_replace('/[\s\-.()]/', '', $this->input($field))]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->getKey();
        $phone = ['nullable', 'string', 'regex:/^\+?[0-9]{8,15}$/'];

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'gender' => ['sometimes', 'nullable', Rule::enum(Gender::class)],
            'birth_place' => ['sometimes', 'nullable', 'string', 'max:100'],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before:today'],
            'phone' => ['sometimes', ...$phone, Rule::unique('users', 'phone')->ignore($userId)],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'blood_type' => ['sometimes', 'nullable', Rule::enum(BloodType::class)],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'emergency_contact_phone' => ['sometimes', ...$phone],
        ];
    }
}
