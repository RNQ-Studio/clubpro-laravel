<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Banner;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePromotionSettingsRequest extends FormRequest
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
            'interval_hours' => ['required', 'integer', 'min:1', 'max:'.Banner::MAX_INTERVAL_HOURS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'interval_hours.required' => 'Isi jeda tampil popup.',
            'interval_hours.integer' => 'Jeda tampil harus berupa jumlah jam.',
            'interval_hours.min' => 'Jeda tampil paling sedikit :min jam.',
            'interval_hours.max' => 'Jeda tampil paling lama :max jam (30 hari).',
        ];
    }
}
