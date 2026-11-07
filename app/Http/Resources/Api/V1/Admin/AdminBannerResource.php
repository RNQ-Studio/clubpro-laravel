<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\Banner;
use App\Models\Community;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Banner
 */
class AdminBannerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Community|null $community */
        $community = $this->relationLoaded('linkCommunity') ? $this->getRelation('linkCommunity') : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'image_url' => $this->image_url,
            'link_url' => $this->link_url,
            // Null bila tujuannya bukan komunitas, atau komunitasnya sudah terhapus.
            'link_community' => $community === null ? null : [
                'id' => $community->id,
                'name' => $community->name,
            ],
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'status' => $this->displayStatus(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
        ];
    }
}
