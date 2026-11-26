<?php

namespace Tests;

use Database\Seeders\AchievementRuleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Restore migration-owned reference data after DatabaseTruncation as well as migrate:fresh.
    protected $seeder = AchievementRuleSeeder::class;
}
