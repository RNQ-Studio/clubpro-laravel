<?php

use App\Services\AchievementService;
use Database\Seeders\AchievementRuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('achievement_rules')) {
            Schema::create('achievement_rules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('version')->unique();
                $table->jsonb('points');
                $table->jsonb('age_boundaries');
                $table->timestampTz('effective_at', 6)->index();
                $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('reason', 1000);
                $table->timestampTz('created_at', 6);
            });
            (new AchievementRuleSeeder)->run();
        }
        if (! Schema::hasTable('achievement_records')) {
            Schema::create('achievement_records', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('community_id')->constrained()->restrictOnDelete();
                $table->foreignId('member_id')->constrained('community_members')->restrictOnDelete();
                $table->foreignId('member_identity_id')->constrained()->restrictOnDelete();
                $table->string('business_key', 64)->unique();
                $table->string('type', 30);
                $table->string('title', 150);
                $table->string('reference', 500);
                $table->timestampTz('occurred_at', 6)->index();
                $table->string('status', 20)->default('final');
                $table->unsignedInteger('version')->default(1);
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->uuid('idempotency_key');
                $table->string('payload_hash', 64);
                $table->foreignId('corrected_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->text('correction_reason')->nullable();
                $table->timestampTz('corrected_at', 6)->nullable();
                $table->timestampsTz(6);
                $table->unique(['community_id', 'idempotency_key']);
            });
        }
        if (! Schema::hasTable('achievement_entries')) {
            Schema::create('achievement_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('source_key', 150)->unique();
                $table->foreignId('community_id')->constrained()->restrictOnDelete();
                $table->foreignId('member_identity_id')->constrained()->restrictOnDelete();
                $table->string('type', 50);
                $table->string('source_id', 64);
                $table->integer('points');
                $table->unsignedInteger('rule_version');
                $table->foreign('rule_version')->references('version')->on('achievement_rules')->restrictOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestampTz('occurred_at', 6);
                $table->timestampTz('created_at', 6);
                $table->index(['community_id', 'occurred_at', 'member_identity_id'], 'achievement_ranking_idx');
                $table->index(['member_identity_id', 'occurred_at']);
            });
        }
        if (! Schema::hasTable('member_advertisements')) {
            Schema::create('member_advertisements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->foreignId('seller_identity_id')->constrained('member_identities')->restrictOnDelete();
                $table->foreignId('community_id')->constrained()->restrictOnDelete();
                $table->string('title', 120);
                $table->text('body');
                $table->uuid('photo_asset_id')->nullable();
                $table->foreign('photo_asset_id')->references('id')->on('assets')->restrictOnDelete();
                $table->uuid('target_product_id')->nullable();
                $table->foreign('target_product_id')->references('id')->on('marketplace_products')->restrictOnDelete();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('version')->default(1);
                $table->timestampsTz(6);
                $table->index(['community_id', 'is_active']);
                $table->index(['seller_identity_id', 'is_active']);
            });
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE training_final_events ALTER COLUMN created_at TYPE timestamp(6) with time zone');
            DB::statement("CREATE OR REPLACE FUNCTION clubpro_achievement_immutable() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Achievement history is immutable'; END; $$ LANGUAGE plpgsql");
            foreach (['achievement_entries', 'achievement_rules'] as $table) {
                DB::statement("DROP TRIGGER IF EXISTS achievement_immutable ON {$table}");
                DB::statement("CREATE TRIGGER achievement_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION clubpro_achievement_immutable()");
            }
            DB::statement('ALTER TABLE achievement_rules DROP CONSTRAINT IF EXISTS achievement_rule_positive');
            DB::statement('ALTER TABLE achievement_rules ADD CONSTRAINT achievement_rule_positive CHECK (version > 0)');
        }
        app(AchievementService::class)->syncTraining();
    }

    public function down(): void
    {
        // Preserve immutable achievement history and member advertisements on rollback.
    }
};
