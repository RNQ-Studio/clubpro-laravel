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
        $profiles = app(MemberProfileService::class);

        return [
            ...parent::toArray($request),
            'phone' => $this->phone,
            'login_method' => $this->google_id !== null ? 'google' : 'password',
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'gender' => $this->gender?->value,
            ...$profiles->regionAttributes($this->resource),
            'profile_completion' => $profiles->completion($this->resource),
            'devices' => AdminDeviceResource::collection($this->whenLoaded('devices')),
        ];
    }
}
