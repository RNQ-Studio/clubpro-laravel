<?php

namespace App\Http\Requests\Api\V1;

use App\Services\CommunityService;
use App\Services\IndonesiaRegionService;
use App\Support\Enums\CommunityType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Nomor ditulis bebas di app ("0812-3456 7890") → simpan angka saja.
        if (is_string($this->input('contact_phone'))) {
            $this->merge(['contact_phone' => preg_replace('/[\s\-.()]/', '', $this->input('contact_phone'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $regions = app(IndonesiaRegionService::class);

        return [
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'short_name' => ['required', 'string', 'min:2', 'max:30'],
            'type' => ['required', Rule::enum(CommunityType::class)],
            'type_other' => ['nullable', 'required_if:type,'.CommunityType::Lainnya->value, 'string', 'max:50'],
            'description' => ['required', 'string', 'min:30', 'max:2000'],
            'address' => ['required', 'string', 'min:10', 'max:500'],
            'province_code' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($regions): void {
                if ($regions->province(is_string($value) ? $value : null) === null) {
                    $fail('Provinsi tidak dikenal.');
                }
            }],
            'city_code' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($regions): void {
                if ($regions->city($this->string('province_code')->toString(), is_string($value) ? $value : null) === null) {
                    $fail('Kota/kabupaten tidak sesuai dengan provinsinya.');
                }
            }],
            'contact_name' => ['required', 'string', 'min:3', 'max:100'],
            'contact_phone' => ['required', 'string', 'regex:/^\+?[0-9]{8,15}$/'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'logo_asset_id' => ['required', 'uuid'],
            'photo_asset_ids' => ['required', 'array', 'min:1', 'max:'.CommunityService::MAX_PHOTOS],
            'photo_asset_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'required_if' => ':attribute wajib diisi.',
            'min' => ':attribute minimal :min karakter.',
            'max' => ':attribute maksimal :max karakter.',
            'email' => 'Format :attribute tidak valid.',
            'uuid' => 'Foto tidak valid. Unggah ulang fotonya.',
            'contact_phone.regex' => 'Nomor kontak tidak valid.',
            'photo_asset_ids.min' => 'Lampirkan minimal 1 foto.',
            'photo_asset_ids.max' => 'Lampiran maksimal :max foto.',
            'type.enum' => 'Jenis komunitas tidak dikenal.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'Nama komunitas',
            'short_name' => 'Nama pendek',
            'type' => 'Jenis komunitas',
            'type_other' => 'Jenis komunitas lainnya',
            'description' => 'Deskripsi',
            'address' => 'Alamat lengkap',
            'province_code' => 'Provinsi',
            'city_code' => 'Kota/kabupaten',
            'contact_name' => 'Nama kontak',
            'contact_phone' => 'Nomor kontak',
            'contact_email' => 'Email kontak',
            'logo_asset_id' => 'Foto profil komunitas',
            'photo_asset_ids' => 'Lampiran foto',
        ];
    }
}
