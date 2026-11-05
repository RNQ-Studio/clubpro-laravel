<?php

namespace Database\Factories;

use App\Models\Community;
use App\Models\CommunityActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityActivity>
 */
class CommunityActivityFactory extends Factory
{
    protected $model = CommunityActivity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'community_id' => Community::factory()->approved(),
            'title' => fake()->sentence(3),
            'starts_at' => now()->addWeek(),
        ];
    }
}
