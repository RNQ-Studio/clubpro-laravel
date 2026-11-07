<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDuesSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Nomor rekening disimpan tanpa spasi/strip agar mudah disalin.
        if (is_string($this->input('dues_account_number'))) {
            $this->merge(['dues_account_number' => preg_replace('/[\s.-]+/', '', $this->input('dues_account_number'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dues_amount' => ['required', 'integer', 'min:1000', 'max:100000000'],
            'entry_fee' => ['required', 'integer', 'min:0', 'max:100000000'],
            'dues_start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'dues_bank_name' => ['required', 'string', 'min:2', 'max:60'],
            'dues_account_number' => ['required', 'string', 'regex:/^[0-9]{5,30}$/'],
            'dues_account_name' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'integer' => ':attribute harus berupa angka rupiah.',
            'dues_amount.min' => 'Iuran per bulan minimal Rp1.000.',
            'max' => ':attribute terlalu besar.',
            'entry_fee.min' => 'Uang masuk tidak boleh minus.',
            'min' => ':attribute minimal :min karakter.',
            'date_format' => ':attribute tidak valid.',
            'after_or_equal' => ':attribute tidak valid.',
            'before' => ':attribute tidak valid.',
            'dues_account_number.regex' => 'Nomor rekening hanya angka, 5–30 digit.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'dues_amount' => 'Iuran per bulan',
            'entry_fee' => 'Uang masuk',
            'dues_start_date' => 'Tanggal mulai iuran',
            'dues_bank_name' => 'Nama bank',
            'dues_account_number' => 'Nomor rekening',
            'dues_account_name' => 'Nama pemilik rekening',
        ];
    }
}
