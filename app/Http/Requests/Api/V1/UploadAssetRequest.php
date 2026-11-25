<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        // Normalisasi default boolean agar 'is_protected' tidak wajib dikirim klien.
        $this->merge([
            'is_protected' => $this->boolean('is_protected'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                Rule::when($this->input('type') === 'payout-proof', ['image', 'dimensions:min_width=1,min_height=1,max_width=16000,max_height=16000']),
                $this->input('type') === 'payout-proof' ? 'max:5120' : 'max:51200', // 50 MB dalam kilobyte
                $this->input('type') === 'payout-proof' ? 'mimes:jpeg,jpg,png,webp' : 'mimes:jpeg,jpg,png,webp,gif,svg,mp4,mov,avi,webm,mp3,wav,ogg,m4a,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv',
            ],
            'type' => ['required', 'string', 'max:255'], // kategori logis file → folder GCS & kolom category
            'retain_until' => ['nullable', 'date'], // tidak diisi → NULL → file permanen
            'is_protected' => ['boolean'],
        ];
    }
}
