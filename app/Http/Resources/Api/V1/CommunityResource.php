<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Community;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin Community
 */
class CommunityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'type' => $this->type->value,
            'type_label' => $this->typeLabel(),
            'status' => $this->status->value,
            'logo_url' => $this->logo?->getPublicUrl(),
            'city_name' => $this->city_name,
            'province_name' => $this->province_name,
            'description' => Str::limit($this->description, 160),
            'created_at' => $this->created_at?->toIso8601String(),
            'members_count' => $this->whenCounted('members'),
            // `members` hanya dimuat untuk keanggotaan pengguna yang login.
            'my_role' => $this->when($this->relationLoaded('members'), fn (): ?string => $this->members->first()?->role->value),
            'my_status' => $this->when($this->relationLoaded('members'), fn (): ?string => $this->members->first()?->status->value),
        ];
    }
}
