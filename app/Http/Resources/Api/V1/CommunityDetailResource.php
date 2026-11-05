<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Asset;
use App\Models\Community;
use Illuminate\Http\Request;

/**
 * @mixin Community
 */
class CommunityDetailResource extends CommunityResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'address' => $this->address,
            'province_code' => $this->province_code,
            'city_code' => $this->city_code,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            // Hanya terisi pada penolakan, yang hanya bisa dibuka pengaju & super admin.
            'review_note' => $this->review_note,
            'photo_urls' => $this->photos->map(fn (Asset $photo): ?string => $photo->getPublicUrl())->filter()->values(),
        ];
    }
}
