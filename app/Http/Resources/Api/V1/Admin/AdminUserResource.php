<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\User;
use App\Services\Admin\UserAdminService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatarUrl(),
            'roles' => $this->getRoleNames()->values(),
            'is_super_admin' => $this->hasRole(UserAdminService::SUPER_ADMIN),
            'is_active' => $this->is_active,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'latest_device' => new AdminDeviceResource($this->whenLoaded('latestDevice')),
        ];
    }
}
