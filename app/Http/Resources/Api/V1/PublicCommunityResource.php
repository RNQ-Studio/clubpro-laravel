<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Community;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Community */
class PublicCommunityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'short_name' => $this->short_name,
            'type' => $this->type->value, 'type_label' => $this->typeLabel(),
            'description' => $this->description, 'city_name' => $this->city_name,
            'province_name' => $this->province_name, 'logo_url' => $this->logo?->getPublicUrl(),
            'members_count' => $this->members_count ?? 0,
            'photo_urls' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($photo) => $photo->getPublicUrl())->filter()->values()),
        ];
    }
}
