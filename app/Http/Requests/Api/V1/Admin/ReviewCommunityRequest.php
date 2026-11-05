<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\Enums\CommunityStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewCommunityRequest extends FormRequest
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
            'status' => ['required', Rule::in([CommunityStatus::Approved->value, CommunityStatus::Rejected->value])],
            'expected_status' => ['required', Rule::enum(CommunityStatus::class)],
            'note' => ['nullable', 'required_if:status,'.CommunityStatus::Rejected->value, 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Keputusan tidak dikenal.',
            'note.required_if' => 'Tulis alasan penolakan agar pengaju tahu yang perlu diperbaiki.',
            'note.min' => 'Alasan penolakan minimal :min karakter.',
            'note.max' => 'Alasan penolakan maksimal :max karakter.',
        ];
    }
}
