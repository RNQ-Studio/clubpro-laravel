<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Rules\BannerLink;
use Illuminate\Foundation\Http\FormRequest;

class SaveBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Teks opsional: foto yang sudah bertulisan tidak perlu ditimpa teks. Kolomnya tetap
     * wajib dikirim agar field yang terlewat tidak terhapus diam-diam.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['present', 'nullable', 'string', 'max:120'],
            'subtitle' => ['present', 'nullable', 'string', 'max:255'],
            'link_url' => ['present', 'nullable', 'string', 'max:2048', new BannerLink],
            'image_asset_id' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image_asset_id.required' => 'Lampirkan foto banner.',
            'image_asset_id.uuid' => 'Foto tidak valid. Unggah ulang fotonya.',
            'title.max' => 'Judul maksimal :max karakter.',
            'subtitle.max' => 'Keterangan maksimal :max karakter.',
            'link_url.max' => 'Tautan maksimal :max karakter.',
        ];
    }
}
