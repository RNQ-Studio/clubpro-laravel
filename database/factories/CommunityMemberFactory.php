<?php

namespace Database\Factories;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\User;
use App\Support\Enums\CommunityMemberRole;
use App\Support\Enums\CommunityMemberStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityMember>
 */
class CommunityMemberFactory extends Factory
{
    protected $model = CommunityMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'community_id' => Community::factory()->approved(),
            'user_id' => User::factory(),
            'role' => CommunityMemberRole::Member,
            'status' => CommunityMemberStatus::Active,
            'joined_at' => now(),
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => CommunityMemberRole::Admin]);
    }

    public function pending(): static
    {
        return $this->state(['status' => CommunityMemberStatus::Pending, 'joined_at' => null]);
    }
}
