<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Enums\BloodType;
use App\Support\Enums\BowType;
use App\Support\Enums\Gender;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'archery_started_at' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:today'],
            'bow_type' => ['sometimes', 'nullable', Rule::enum(BowType::class)],
            'blood_type' => ['sometimes', 'nullable', Rule::enum(BloodType::class)],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'emergency_contact_phone' => ['sometimes', ...$phone],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $started = $this->input('archery_started_at');
                if (! is_string($started) || $validator->errors()->hasAny(['archery_started_at', 'birth_date'])) {
                    return;
                }

                // Bandingkan dengan tanggal lahir yang dikirim, atau yang sudah tersimpan.
                $birth = $this->has('birth_date')
                    ? $this->input('birth_date')
                    : $this->user()?->birth_date?->format('Y-m-d');

                if (is_string($birth) && Carbon::parse($started)->lt(Carbon::parse($birth))) {
                    $validator->errors()->add(
                        'archery_started_at',
                        'The archery start date cannot be before the birth date.',
                    );
                }
            },
        ];
    }
}
