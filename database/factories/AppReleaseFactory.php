<?php

namespace Database\Factories;

use App\Models\AppRelease;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppRelease>
 */
class AppReleaseFactory extends Factory
{
    protected $model = AppRelease::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app' => 'main',
            'channel' => 'dev',
            'version_name' => '1.0.0',
            'version_code' => fake()->numberBetween(1, 500),
            'file_disk' => 'local',
            'file_path' => 'app-releases/main/dev/'.Str::uuid().'.apk',
            'file_size' => fake()->numberBetween(1_000_000, 80_000_000),
            'file_sha256' => hash('sha256', Str::random(32)),
            'signing_sha256' => hash('sha256', 'signing-cert'),
            'git_sha' => substr(sha1(Str::random(16)), 0, 12),
            'release_notes' => fake()->sentence(),
            'uploaded_at' => now(),
        ];
    }
}
