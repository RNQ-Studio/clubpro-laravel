<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Services\FileUploadService;
use App\Services\MemberProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

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
        $avatarUrl = null;
        if ($this->avatar !== null) {
            if (Str::isUuid($this->avatar)) {
                $avatarUrl = $this->avatarAsset?->getPublicUrl();
            } else {
                $avatarUrl = app(FileUploadService::class)->url($this->avatar);
            }
        }
        // Belum unggah foto sendiri → pakai foto akun Google bila ada.
        $avatarUrl ??= $this->google_avatar_url;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $avatarUrl,
            'gender' => $this->gender?->value,
            'birth_place' => $this->birth_place,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'address' => $this->address,
            'archery_started_at' => $this->archery_started_at?->format('Y-m-d'),
            'bow_type' => $this->bow_type?->value,
            'blood_type' => $this->blood_type?->value,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'profile_completion' => app(MemberProfileService::class)->completion($this->resource),
            'is_active' => $this->is_active,
            'roles' => $this->getRoleNames()->values(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
