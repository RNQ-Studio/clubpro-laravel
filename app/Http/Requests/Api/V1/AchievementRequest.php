<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AchievementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'configure' => ['expected_version' => ['required', 'integer', 'min:1'], 'points' => ['required', 'array:training_attendance,training_purchase,participation,champion'], 'points.training_attendance' => ['required', 'integer', 'min:0', 'max:1000000'], 'points.training_purchase' => ['required', 'integer', 'min:0', 'max:1000000'], 'points.participation' => ['required', 'integer', 'min:0', 'max:1000000'], 'points.champion' => ['required', 'integer', 'min:0', 'max:1000000'], 'age_boundaries' => ['required', 'array', 'size:5'], 'age_boundaries.*' => ['required', 'integer', 'min:1', 'max:150'], 'reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\S/']],
            'correct' => ['expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\S/']],
            default => ['member_id' => ['required', 'integer', 'min:1'], 'type' => ['required', Rule::in(['participation', 'champion'])], 'title' => ['required', 'string', 'max:150', 'regex:/\S/'], 'reference' => ['required', 'string', 'min:3', 'max:500', 'regex:/\S/'], 'occurred_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:1970-01-01', 'before_or_equal:today'], 'idempotency_key' => ['required', 'uuid']],
        };
    }
}
