<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('training_catalogs')) {
            Schema::create('training_catalogs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('community_id')->unique()->constrained()->restrictOnDelete();
                $table->boolean('enabled')->default(false);
                $table->unsignedInteger('version')->default(0);
                $table->jsonb('packages')->default('[]');
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('training_locations')) {
            Schema::create('training_locations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('community_id')->constrained()->restrictOnDelete();
                $table->string('name', 150);
                $table->string('address', 500)->default('');
                $table->boolean('active')->default(true);
                $table->unsignedInteger('version')->default(1);
                $table->jsonb('manager_member_ids')->default('[]');
                $table->jsonb('splits')->default('[]');
                $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('training_sessions')) {
            Schema::create('training_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('community_id')->constrained()->restrictOnDelete();
                $table->foreignId('location_id')->constrained('training_locations')->restrictOnDelete();
                $table->string('title', 150);
                $table->timestampTz('starts_at');
                $table->timestampTz('ends_at');
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->string('idempotency_key', 150);
                $table->string('payload_hash', 64);
                $table->timestamps();
                $table->unique(['created_by', 'idempotency_key']);
            });
        }
        if (! Schema::hasTable('training_purchases')) {
            Schema::create('training_purchases', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('community_id')->constrained()->restrictOnDelete();
                $table->foreignId('member_id')->constrained('community_members')->restrictOnDelete();
                $table->foreignId('member_identity_id')->constrained()->restrictOnDelete();
                $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
                $table->unsignedSmallInteger('sessions');
                $table->unsignedSmallInteger('remaining_sessions');
                $table->bigInteger('price');
                $table->bigInteger('remaining_amount');
                $table->unsignedInteger('catalog_version');
                $table->jsonb('snapshot');
                $table->foreignUuid('reservation_id')->constrained('ledger_reservations')->restrictOnDelete();
                $table->foreignUuid('journal_id')->constrained('ledger_journals')->restrictOnDelete();
                $table->string('idempotency_key', 150);
                $table->string('payload_hash', 64);
                $table->timestamps();
                $table->unique(['buyer_id', 'idempotency_key']);
                $table->index(['community_id', 'member_identity_id', 'created_at']);
            });
            DB::statement('ALTER TABLE training_purchases ADD CONSTRAINT training_purchase_money CHECK (price > 0 AND remaining_amount >= 0 AND remaining_amount <= price AND sessions IN (7,14,21) AND remaining_sessions >= 0 AND remaining_sessions <= sessions)');
        }
        if (! Schema::hasColumn('training_purchases', 'purchase_order')) {
            DB::statement('ALTER TABLE training_purchases ADD COLUMN purchase_order BIGSERIAL UNIQUE');
        }
        if (! Schema::hasTable('training_attendances')) {
            Schema::create('training_attendances', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('community_id')->constrained()->restrictOnDelete();
                $table->foreignId('session_id')->constrained('training_sessions')->restrictOnDelete();
                $table->foreignId('member_id')->constrained('community_members')->restrictOnDelete();
                $table->foreignId('member_identity_id')->constrained()->restrictOnDelete();
                $table->string('status', 20)->default('pending');
                $table->foreignUuid('purchase_id')->nullable()->constrained('training_purchases')->restrictOnDelete();
                $table->bigInteger('allocation')->default(0);
                $table->jsonb('snapshot')->nullable();
                $table->foreignUuid('journal_id')->nullable()->constrained('ledger_journals')->restrictOnDelete();
                $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestampTz('confirmed_at')->nullable();
                $table->string('confirmation_key', 150)->nullable();
                $table->foreignId('checked_in_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('checkin_key', 150)->nullable();
                $table->foreignId('corrected_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestampTz('corrected_at')->nullable();
                $table->string('corrected_by_name', 255)->nullable();
                $table->text('correction_reason')->nullable();
                $table->string('correction_key', 150)->nullable();
                $table->foreignUuid('correction_journal_id')->nullable()->constrained('ledger_journals')->restrictOnDelete();
                $table->timestamps();
                $table->unique(['session_id', 'member_identity_id']);
            });
        }
        if (! Schema::hasTable('training_final_events')) {
            Schema::create('training_final_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('community_id')->constrained()->restrictOnDelete();
                $table->foreignId('member_identity_id')->constrained()->restrictOnDelete();
                $table->string('type', 50);
                $table->uuid('source_id');
                $table->jsonb('payload');
                $table->timestampTz('created_at');
                $table->unique(['type', 'source_id']);
            });
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION clubpro_training_history_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Training financial history cannot be deleted';
    END IF;
    IF TG_TABLE_NAME = 'training_final_events' THEN
        RAISE EXCEPTION 'Final training events are immutable';
    END IF;
    IF TG_TABLE_NAME = 'training_purchases' AND
        (to_jsonb(NEW) - ARRAY['remaining_sessions','remaining_amount','updated_at']) IS DISTINCT FROM
        (to_jsonb(OLD) - ARRAY['remaining_sessions','remaining_amount','updated_at']) THEN
        RAISE EXCEPTION 'Purchase price and original snapshot are immutable';
    END IF;
    IF TG_TABLE_NAME = 'training_attendances' THEN
    IF NEW.status NOT IN ('pending', 'confirmed', 'corrected') THEN
        RAISE EXCEPTION 'Invalid attendance status';
    END IF;
    IF OLD.status = 'confirmed' AND (NEW.status <> 'corrected' OR NEW.corrected_by IS NULL OR NEW.corrected_at IS NULL OR NEW.correction_journal_id IS NULL OR NEW.correction_reason IS NULL) THEN
        RAISE EXCEPTION 'Confirmed attendance only permits an audited technical correction';
    END IF;
    IF OLD.status <> 'pending' AND
        (to_jsonb(NEW) - ARRAY['status','corrected_by','corrected_by_name','corrected_at','correction_reason','correction_key','correction_journal_id','updated_at']) IS DISTINCT FROM
        (to_jsonb(OLD) - ARRAY['status','corrected_by','corrected_by_name','corrected_at','correction_reason','correction_key','correction_journal_id','updated_at']) THEN
        RAISE EXCEPTION 'Confirmed attendance snapshot is immutable';
    END IF;
    IF OLD.status = 'corrected' AND to_jsonb(NEW) IS DISTINCT FROM to_jsonb(OLD) THEN
        RAISE EXCEPTION 'Attendance technical correction is immutable';
    END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS training_purchase_history ON training_purchases;
CREATE TRIGGER training_purchase_history BEFORE UPDATE OR DELETE ON training_purchases FOR EACH ROW EXECUTE FUNCTION clubpro_training_history_guard();
DROP TRIGGER IF EXISTS training_attendance_history ON training_attendances;
CREATE TRIGGER training_attendance_history BEFORE UPDATE OR DELETE ON training_attendances FOR EACH ROW EXECUTE FUNCTION clubpro_training_history_guard();
DROP TRIGGER IF EXISTS training_event_history ON training_final_events;
CREATE TRIGGER training_event_history BEFORE UPDATE OR DELETE ON training_final_events FOR EACH ROW EXECUTE FUNCTION clubpro_training_history_guard();
SQL);
        }

    }

    public function down(): void
    {
        // Financial history is retained. Roll forward for fixes; never drop paid packages.
    }
};
