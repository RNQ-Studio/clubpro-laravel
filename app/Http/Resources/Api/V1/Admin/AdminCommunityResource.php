<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Http\Resources\Api\V1\CommunityResource;
use App\Models\Community;
use Illuminate\Http\Request;

/**
 * @mixin Community
 */
class AdminCommunityResource extends CommunityResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'submitter' => $this->owner === null ? null : [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
                'email' => $this->owner->email,
            ],
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
