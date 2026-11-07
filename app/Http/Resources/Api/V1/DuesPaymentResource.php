<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DuesPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DuesPayment
 */
class DuesPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar_url' => $this->user->avatarUrl(),
            ],
            'months' => $this->months,
            'months_count' => $this->months_count,
            'monthly_amount' => $this->monthly_amount,
            'entry_fee' => $this->entry_fee,
            'total_amount' => $this->total_amount,
            'proof_url' => $this->proof?->getPublicUrl(),
            'note' => $this->note,
            'status' => $this->status->value,
            'review_note' => $this->review_note,
            'reviewed_by' => $this->reviewer?->name,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
