<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CommunityMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Anggota beserta kalender iurannya (`dues`, diisi DuesService) untuk pengelola.
 *
 * @mixin CommunityMember
 */
class MemberDuesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'name' => $this->user->name,
            'avatar_url' => $this->user->avatarUrl(),
            'role' => $this->role->value,
            'joined_at' => $this->joined_at?->toIso8601String(),
            ...$this->getAttribute('dues'),
        ];
    }
}
