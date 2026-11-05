<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Community;
use App\Models\User;
use App\Support\Enums\CommunityStatus;
use App\Support\Enums\CommunityType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Community>
 */
class CommunityFactory extends Factory
{
    protected $model = Community::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => CommunityStatus::Pending,
            'name' => fake()->unique()->company(),
            'short_name' => fake()->lexify('????'),
            'type' => CommunityType::Panahan,
            'description' => fake()->paragraph(),
            'address' => fake()->address(),
            'province_code' => '32',
            'province_name' => 'Jawa Barat',
            'city_code' => '32.73',
            'city_name' => 'Kota Bandung',
            'contact_name' => fake()->name(),
            'contact_phone' => '081234567890',
            'logo_asset_id' => Asset::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => CommunityStatus::Approved]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => CommunityStatus::Rejected]);
    }
}
