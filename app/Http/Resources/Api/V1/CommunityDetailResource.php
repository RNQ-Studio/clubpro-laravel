<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Asset;
use App\Models\Community;
use App\Models\User;
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
        /** @var User|null $viewer */
        $viewer = $request->user();
        $canManage = $viewer?->can('manage', $this->resource) ?? false;

        return [
            ...parent::toArray($request),
            'can_manage' => $canManage,
            'my_status' => $this->when($this->relationLoaded('members'), fn (): ?string => $this->members->first()?->status->value),
            'upcoming_activities_count' => $this->whenCounted('upcoming_activities'),
            'pending_members_count' => $this->when($canManage, fn () => $this->pending_members_count),
            'photos' => $this->photos->map(fn (Asset $photo): array => ['id' => $photo->id, 'url' => $photo->getPublicUrl()])->values(),
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
