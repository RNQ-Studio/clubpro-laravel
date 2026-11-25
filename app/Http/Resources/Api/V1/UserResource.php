<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Services\Auth\PinReauthenticationService;
use App\Services\MemberProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profiles = app(MemberProfileService::class);

        return [
            'id' => $this->id,
            'pin_enabled' => $this->pin_hash !== null,
            'pin_google_reauthenticated' => app(PinReauthenticationService::class)->hasGoogle($this->resource, $request->bearerToken()),
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatarUrl(),
            'gender' => $this->gender?->value,
            'birth_date' => $this->birth_date?->toDateString(),
            ...$profiles->regionAttributes($this->resource),
            'profile_completion' => $profiles->completion($this->resource),
            'is_active' => $this->is_active,
            'roles' => $this->getRoleNames()->values(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
