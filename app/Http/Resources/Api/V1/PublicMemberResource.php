<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CommunityMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CommunityMember */
class PublicMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->member_identity_id, 'membership_id' => $this->id,
            'name' => $this->identity->name ?? $this->user?->name,
            'avatar_url' => $this->user?->avatarUrl(),
            'role' => $this->role->value, 'role_label' => $this->role->label(),
            'community' => ['id' => $this->community->id, 'name' => $this->community->name,
                'short_name' => $this->community->short_name, 'city_name' => $this->community->city_name],
        ];
    }
}
