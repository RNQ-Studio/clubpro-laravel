<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('primary_bank_accounts')) {
            Schema::create('primary_bank_accounts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
                $table->text('details');
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('member_transfers')) {
            Schema::create('member_transfers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->foreignId('sender_identity_id')->constrained('member_identities')->restrictOnDelete();
                $table->foreignId('recipient_identity_id')->constrained('member_identities')->restrictOnDelete();
                $table->foreignId('community_id')->constrained()->restrictOnDelete();
                $table->foreignId('recipient_community_id')->constrained('communities')->restrictOnDelete();
                $table->bigInteger('amount');
                $table->string('idempotency_key', 150);
                $table->string('payload_hash', 64);
                $table->uuid('journal_id')->nullable();
                $table->jsonb('snapshot');
                $table->timestamps();
                $table->unique(['user_id', 'idempotency_key']);
            });
        }
        if (! Schema::hasTable('payouts')) {
            Schema::create('payouts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->foreignId('member_identity_id')->constrained()->restrictOnDelete();
                $table->foreignId('community_id')->constrained()->restrictOnDelete();
                $table->text('bank_snapshot');
                $table->string('member_name');
                $table->bigInteger('amount');
                $table->bigInteger('verified_amount')->default(0);
                $table->string('status')->default('requested');
                $table->unsignedInteger('version')->default(1);
                $table->string('idempotency_key', 150);
                $table->string('payload_hash', 64);
                $table->timestamps();
                $table->unique(['user_id', 'idempotency_key']);
            });
        }
        if (! Schema::hasTable('payout_parts')) {
            Schema::create('payout_parts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('payout_id')->constrained()->restrictOnDelete();
                $table->foreignId('community_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('custodian_key');
                $table->bigInteger('amount');
                $table->bigInteger('verified_amount')->default(0);
                $table->string('status')->default('requested');
                $table->unsignedInteger('version')->default(1);
                $table->foreignUuid('reservation_id')->constrained('ledger_reservations')->restrictOnDelete();
                $table->jsonb('allocations');
                $table->timestamps();
                $table->index(['community_id', 'status']);
            });
        }
        if (! Schema::hasTable('payout_fragments')) {
            Schema::create('payout_fragments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('payout_part_id')->constrained()->restrictOnDelete();
                $table->foreignUuid('proof_asset_id')->unique()->constrained('assets')->restrictOnDelete();
                $table->string('proof_digest', 64)->unique();
                $table->string('reference_digest', 64)->unique();
                $table->string('bank_reference', 150);
                $table->bigInteger('amount');
                $table->string('status')->default('pending');
                $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestampTz('verified_at')->nullable();
                $table->string('note', 500);
                $table->uuid('journal_id')->nullable();
                $table->string('idempotency_key', 150);
                $table->string('payload_hash', 64);
                $table->timestamps();
                $table->unique(['submitted_by', 'idempotency_key']);
            });
        }
        if (! Schema::hasTable('payout_events')) {
            Schema::create('payout_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('payout_part_id')->constrained()->restrictOnDelete();
                $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $table->string('actor_name');
                $table->string('status');
                $table->string('note', 500);
                $table->string('operation');
                $table->string('idempotency_key', 150);
                $table->string('payload_hash', 64);
                $table->timestampTz('created_at');
                $table->unique(['actor_id', 'operation', 'idempotency_key']);
            });
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION clubpro_movement_history_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Money movement history cannot be deleted'; END IF;
    IF TG_TABLE_NAME = 'payout_events' THEN RAISE EXCEPTION 'Payout audit is immutable'; END IF;
    IF TG_TABLE_NAME = 'member_transfers' AND
      (to_jsonb(NEW) - ARRAY['journal_id','updated_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['journal_id','updated_at']) THEN
      RAISE EXCEPTION 'Transfer snapshot is immutable'; END IF;
    IF TG_TABLE_NAME = 'member_transfers' THEN
      IF OLD.journal_id IS NOT NULL AND to_jsonb(NEW) IS DISTINCT FROM to_jsonb(OLD) THEN RAISE EXCEPTION 'Final transfer is immutable'; END IF;
    END IF;
    IF TG_TABLE_NAME IN ('payouts', 'payout_parts') THEN
      IF NEW.verified_amount < OLD.verified_amount OR NEW.version < OLD.version THEN RAISE EXCEPTION 'Payout counters cannot go backwards'; END IF;
      IF OLD.status IN ('succeeded', 'failed', 'cancelled') AND to_jsonb(NEW) IS DISTINCT FROM to_jsonb(OLD) THEN RAISE EXCEPTION 'Final payout is immutable'; END IF;
      IF NEW.status = 'succeeded' AND NEW.verified_amount <> NEW.amount THEN RAISE EXCEPTION 'Success requires full verification'; END IF;
    END IF;
    IF TG_TABLE_NAME = 'payouts' AND
      (to_jsonb(NEW) - ARRAY['status','verified_amount','version','updated_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','verified_amount','version','updated_at']) THEN
      RAISE EXCEPTION 'Payout destination and request snapshot are immutable'; END IF;
    IF TG_TABLE_NAME = 'payout_parts' THEN
      IF (to_jsonb(NEW) - ARRAY['status','verified_amount','version','allocations','updated_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','verified_amount','version','allocations','updated_at']) THEN
        RAISE EXCEPTION 'Payout source snapshot is immutable'; END IF;
      IF (SELECT jsonb_agg(x - 'remaining') FROM jsonb_array_elements(NEW.allocations) x) IS DISTINCT FROM
         (SELECT jsonb_agg(x - 'remaining') FROM jsonb_array_elements(OLD.allocations) x) THEN
        RAISE EXCEPTION 'Payout lot source snapshot is immutable'; END IF;
      IF EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.allocations) WITH ORDINALITY AS n(x,i) JOIN jsonb_array_elements(OLD.allocations) WITH ORDINALITY AS o(x,i) ON n.i=o.i WHERE (n.x->>'remaining')::bigint > (o.x->>'remaining')::bigint) THEN RAISE EXCEPTION 'Payout lot remaining cannot increase'; END IF;
      IF EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.allocations) x WHERE (x->>'remaining')::bigint < 0 OR (x->>'remaining')::bigint > (x->>'amount')::bigint) THEN
        RAISE EXCEPTION 'Invalid payout lot remaining'; END IF;
    END IF;
    IF TG_TABLE_NAME = 'payout_fragments' THEN
      IF OLD.status IN ('verified', 'rejected') AND to_jsonb(NEW) IS DISTINCT FROM to_jsonb(OLD) THEN RAISE EXCEPTION 'Verified payout proof is immutable'; END IF;
      IF (to_jsonb(NEW) - ARRAY['amount','bank_reference','reference_digest','note','status','verified_by','verified_at','journal_id','updated_at']) IS DISTINCT FROM
         (to_jsonb(OLD) - ARRAY['amount','bank_reference','reference_digest','note','status','verified_by','verified_at','journal_id','updated_at']) THEN
        RAISE EXCEPTION 'Payout proof identity is immutable'; END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS payout_history ON payouts;
CREATE TRIGGER payout_history BEFORE UPDATE OR DELETE ON payouts FOR EACH ROW EXECUTE FUNCTION clubpro_movement_history_guard();
DROP TRIGGER IF EXISTS payout_part_history ON payout_parts;
CREATE TRIGGER payout_part_history BEFORE UPDATE OR DELETE ON payout_parts FOR EACH ROW EXECUTE FUNCTION clubpro_movement_history_guard();
DROP TRIGGER IF EXISTS payout_fragment_history ON payout_fragments;
CREATE TRIGGER payout_fragment_history BEFORE UPDATE OR DELETE ON payout_fragments FOR EACH ROW EXECUTE FUNCTION clubpro_movement_history_guard();
DROP TRIGGER IF EXISTS payout_event_history ON payout_events;
CREATE TRIGGER payout_event_history BEFORE UPDATE OR DELETE ON payout_events FOR EACH ROW EXECUTE FUNCTION clubpro_movement_history_guard();
DROP TRIGGER IF EXISTS member_transfer_history ON member_transfers;
CREATE TRIGGER member_transfer_history BEFORE UPDATE OR DELETE ON member_transfers FOR EACH ROW EXECUTE FUNCTION clubpro_movement_history_guard();
SQL);
            foreach (['payouts', 'payout_parts'] as $table) {
                if (! DB::select('SELECT 1 FROM pg_constraint WHERE conname = ?', [$table.'_valid_money'])) {
                    DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT '.$table.'_valid_money CHECK (amount > 0 AND verified_amount >= 0 AND verified_amount <= amount AND version >= 1 AND status IN (\'requested\',\'processing\',\'uncertain\',\'succeeded\',\'failed\',\'cancelled\'))');
                }
            }
        }

    }

    public function down(): void
    {
        // Financial history is deliberately retained. Roll forward to repair.
    }
};
