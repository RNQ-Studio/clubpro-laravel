<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** Explicit fresh-store baseline; never repairs or reprices existing achievement history. */
class AchievementRuleSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('achievement_rules') || DB::table('achievement_rules')->exists()) {
            return;
        }
        if (Schema::hasTable('achievement_entries') && DB::table('achievement_entries')->exists()) {
            throw new LogicException('Achievement rule history is missing for existing entries; restore the original history.');
        }
        DB::table('achievement_rules')->insertOrIgnore(['version' => 1, 'points' => json_encode(['training_attendance' => 1, 'training_purchase' => 1, 'participation' => 1, 'champion' => 1]), 'age_boundaries' => json_encode([12, 16, 19, 36, 51]), 'effective_at' => '1970-01-01 00:00:00+00', 'reason' => 'Default operasional AI D05; bukan keputusan client.', 'created_at' => now()]);
    }
}
