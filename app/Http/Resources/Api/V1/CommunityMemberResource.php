<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CommunityMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Hanya data umum: sesama anggota tidak melihat email, nomor HP, atau alamat.
 *
 * @mixin CommunityMember
 */
class CommunityMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->user->name,
            'avatar_url' => $this->user->avatarUrl(),
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'status' => $this->status->value,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'requested_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
