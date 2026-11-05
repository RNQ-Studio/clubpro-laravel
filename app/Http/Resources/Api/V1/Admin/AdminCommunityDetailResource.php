<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Http\Resources\Api\V1\CommunityDetailResource;
use App\Models\Community;
use Illuminate\Http\Request;

/**
 * @mixin Community
 */
class AdminCommunityDetailResource extends CommunityDetailResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'type_other' => $this->type_other,
            'submitter' => $this->owner === null ? null : [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
                'email' => $this->owner->email,
                'avatar_url' => $this->owner->avatarUrl(),
            ],
            'reviewer' => $this->reviewer === null ? null : [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ],
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
