<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\User;
use App\Services\MemberProfileService;
use Illuminate\Http\Request;

/**
 * @mixin User
 */
class AdminUserDetailResource extends AdminUserResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'phone' => $this->phone,
            'login_method' => $this->google_id !== null ? 'google' : 'password',
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'gender' => $this->gender?->value,
            'birth_place' => $this->birth_place,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'address' => $this->address,
            'blood_type' => $this->blood_type?->value,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'profile_completion' => app(MemberProfileService::class)->completion($this->resource),
            'devices' => AdminDeviceResource::collection($this->whenLoaded('devices')),
        ];
    }
}
