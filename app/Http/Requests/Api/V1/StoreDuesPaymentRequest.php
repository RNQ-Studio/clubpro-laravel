<?php

namespace App\Http\Requests\Api\V1;

use App\Services\DuesService;
use Illuminate\Foundation\Http\FormRequest;

class StoreDuesPaymentRequest extends FormRequest
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
            'months_count' => ['required', 'integer', 'min:1', 'max:'.DuesService::MAX_MONTHS_PER_PAYMENT],
            'proof_asset_id' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'months_count.required' => 'Pilih jumlah bulan yang dibayar.',
            'months_count.min' => 'Bayar minimal 1 bulan.',
            'months_count.max' => 'Bayar paling banyak '.DuesService::MAX_MONTHS_PER_PAYMENT.' bulan sekaligus.',
            'months_count.integer' => 'Jumlah bulan tidak valid.',
            'proof_asset_id.required' => 'Unggah bukti transfer.',
            'proof_asset_id.uuid' => 'Bukti transfer tidak valid. Unggah ulang fotonya.',
            'note.max' => 'Catatan maksimal 500 karakter.',
        ];
    }
}
