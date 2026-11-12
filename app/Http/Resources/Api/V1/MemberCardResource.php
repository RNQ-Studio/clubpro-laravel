<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CommunityMember;
use App\Support\Enums\MemberCardStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommunityMember
 */
class MemberCardResource extends JsonResource
{
    /**
     * @param  array{status: MemberCardStatus, valid_until: CarbonImmutable|null, paid_until: CarbonImmutable|null}  $validity
     */
    public function __construct(
        CommunityMember $member,
        private readonly array $validity,
        private readonly bool $pendingPayment,
    ) {
        parent::__construct($member);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'member_number' => $this->cardNumber(),
            'name' => $this->user->name,
            'avatar_url' => $this->user->avatarUrl(),
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'joined_at' => ($this->joined_at ?? $this->created_at)?->toIso8601String(),
            'community' => new CommunityResource($this->community),
            'validity' => [
                'status' => $this->validity['status']->value,
                'valid_until' => $this->validity['valid_until']?->toDateString(),
                'paid_until' => $this->validity['paid_until']?->format('Y-m'),
                'pending_payment' => $this->pendingPayment,
            ],
        ];
    }
}
