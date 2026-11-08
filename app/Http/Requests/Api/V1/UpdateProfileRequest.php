<?php

namespace App\Http\Requests\Api\V1;

use App\Services\IndonesiaRegionService;
use App\Services\MemberProfileService;
use App\Support\Enums\Gender;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pembaruan sebagian data diri: hanya isian yang dikirim yang diubah, dan
 * nilai kosong/null mengosongkan isian itu (kecuali nama & email).
 *
 * Wilayah (domisili, sesuai NIK) dikirim berpasangan: kode provinsi + kode
 * kabupaten/kota milik provinsi itu, atau keduanya null untuk mengosongkan.
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
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => preg_replace('/[\s\-.()]/', '', $this->input('phone'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->getKey();

        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'gender' => ['sometimes', 'nullable', Rule::enum(Gender::class)],
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^\+?[0-9]{8,15}$/',
                Rule::unique('users', 'phone')->ignore($userId),
            ],
            'nik_same_as_domicile' => ['sometimes', 'boolean'],
        ];

        foreach (MemberProfileService::REGION_PREFIXES as $prefix) {
            $rules += $this->regionRules($prefix);
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    private function regionRules(string $prefix): array
    {
        $regions = app(IndonesiaRegionService::class);
        $province = "{$prefix}_province_code";
        $city = "{$prefix}_city_code";

        return [
            $province => ['nullable', 'string', "required_with:{$city}", function (string $attribute, mixed $value, Closure $fail) use ($regions): void {
                if ($regions->province(is_string($value) ? $value : null) === null) {
                    $fail('Provinsi tidak dikenal.');
                }
            }],
            $city => ['nullable', 'string', "required_with:{$province}", function (string $attribute, mixed $value, Closure $fail) use ($regions, $province): void {
                if ($regions->city($this->string($province)->toString(), is_string($value) ? $value : null) === null) {
                    $fail('Kota/kabupaten tidak sesuai dengan provinsinya.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Nomor WhatsApp tidak valid (8–15 angka).',
            'phone.unique' => 'Nomor WhatsApp ini sudah dipakai akun lain.',
            'domicile_province_code.required_with' => 'Pilih provinsi domisili.',
            'domicile_city_code.required_with' => 'Pilih kota/kabupaten domisili.',
            'nik_province_code.required_with' => 'Pilih provinsi sesuai NIK.',
            'nik_city_code.required_with' => 'Pilih kota/kabupaten sesuai NIK.',
        ];
    }
}
