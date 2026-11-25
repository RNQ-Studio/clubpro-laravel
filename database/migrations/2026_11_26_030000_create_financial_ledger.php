<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ledger_accounts')) {
            return;
        }
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('account_key')->unique();
            $table->string('kind');
            $table->string('bucket');
            $table->foreignId('member_identity_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('community_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('balance')->default(0);
            $table->boolean('allow_negative')->default(false);
            $table->timestamps();
        });
        Schema::create('ledger_journals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 100);
            $table->string('idempotency_key', 150);
            $table->string('payload_hash', 64);
            $table->foreignId('community_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference')->nullable();
            $table->json('metadata');
            $table->timestampTz('created_at');
            $table->bigInteger('creation_xid')->nullable();
            $table->unique(['actor_id', 'operation', 'idempotency_key'], 'ledger_journal_idempotency');
        });
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('journal_id')->constrained('ledger_journals')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->bigInteger('amount');
            $table->unique(['journal_id', 'account_id']);
        });
        Schema::create('incoming_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('member_identity_id')->constrained()->restrictOnDelete();
            $table->foreignId('community_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('purpose', 40);
            $table->string('status', 40)->default('pending');
            $table->string('idempotency_key', 150);
            $table->string('payload_hash', 64);
            $table->bigInteger('claimed_amount');
            $table->bigInteger('verified_amount')->nullable();
            $table->foreignUuid('proof_asset_id')->constrained('assets')->restrictOnDelete();
            $table->string('proof_digest', 64)->unique();
            $table->string('custodian_key');
            $table->json('collector_snapshot');
            $table->string('bank_reference', 150);
            $table->string('verified_reference', 150)->nullable();
            $table->json('invoice_ids');
            $table->boolean('credit_excess')->default(false);
            $table->foreignId('target_member_id')->nullable()->constrained('community_members')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignUuid('journal_id')->nullable()->constrained('ledger_journals')->restrictOnDelete();
            $table->foreignId('legacy_dues_payment_id')->nullable()->constrained('dues_payments')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['submitted_by', 'idempotency_key']);
            $table->unique(['custodian_key', 'verified_reference'], 'incoming_verified_reference_unique');
        });
        Schema::create('ledger_lot_holdings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('receipt_id')->constrained('incoming_receipts')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->bigInteger('amount');
            $table->unique(['receipt_id', 'account_id']);
        });
        Schema::create('ledger_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('source_reference', 500)->unique();
            $table->foreignId('available_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('reserved_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('community_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->bigInteger('remaining');
            $table->string('status')->default('reserved');
            $table->timestampsTz();
        });
        Schema::create('ledger_clearings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_custodian');
            $table->string('handler_custodian');
            $table->bigInteger('amount');
            $table->string('status')->default('open');
            $table->foreignUuid('source_journal_id')->constrained('ledger_journals')->restrictOnDelete();
            $table->foreignUuid('settlement_journal_id')->nullable()->constrained('ledger_journals')->restrictOnDelete();
            $table->string('source_reference', 500)->unique();
            $table->string('settlement_reference')->nullable()->unique();
            $table->string('settlement_proof_digest', 64)->nullable()->unique();
            $table->foreignId('settled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('ledger_cash_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('namespace')->default('outgoing');
            $table->string('bank_reference', 150);
            $table->foreignUuid('proof_asset_id')->constrained('assets')->restrictOnDelete();
            $table->string('proof_digest', 64);
            $table->foreignUuid('journal_id')->constrained('ledger_journals')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['namespace', 'bank_reference']);
            $table->unique(['namespace', 'proof_digest']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_nonnegative CHECK (allow_negative OR balance >= 0)');
            DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_nonzero CHECK (amount <> 0)');
            DB::statement('ALTER TABLE ledger_lot_holdings ADD CONSTRAINT ledger_lot_nonnegative CHECK (amount >= 0)');
            DB::statement("ALTER TABLE incoming_receipts ADD CONSTRAINT receipt_positive_amount CHECK (purpose = 'legacy_dues' OR (claimed_amount > 0 AND (verified_amount IS NULL OR verified_amount > 0)))");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION clubpro_receipt_collector_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.collector_snapshot::text <> OLD.collector_snapshot::text OR NEW.custodian_key <> OLD.custodian_key THEN RAISE EXCEPTION 'Receipt collector snapshot is immutable'; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER incoming_collector_immutable BEFORE UPDATE ON incoming_receipts FOR EACH ROW EXECUTE FUNCTION clubpro_receipt_collector_immutable();
CREATE OR REPLACE FUNCTION clubpro_ledger_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Posted ledger rows are immutable'; END;
$$;
CREATE OR REPLACE FUNCTION clubpro_ledger_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME = 'ledger_journals' THEN NEW.creation_xid := txid_current(); RETURN NEW; END IF;
    IF NOT EXISTS (SELECT 1 FROM ledger_journals WHERE id = NEW.journal_id AND creation_xid = txid_current()) THEN RAISE EXCEPTION 'Posted ledger cannot receive additional entries'; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER ledger_journal_creation BEFORE INSERT ON ledger_journals FOR EACH ROW EXECUTE FUNCTION clubpro_ledger_insert_guard();
CREATE TRIGGER ledger_entry_creation BEFORE INSERT ON ledger_entries FOR EACH ROW EXECUTE FUNCTION clubpro_ledger_insert_guard();
CREATE TRIGGER ledger_journal_immutable BEFORE UPDATE OR DELETE ON ledger_journals FOR EACH ROW EXECUTE FUNCTION clubpro_ledger_immutable();
CREATE TRIGGER ledger_entry_immutable BEFORE UPDATE OR DELETE ON ledger_entries FOR EACH ROW EXECUTE FUNCTION clubpro_ledger_immutable();
CREATE OR REPLACE FUNCTION clubpro_ledger_balanced() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE journal_uuid uuid; posting_count bigint; posting_sum numeric;
BEGIN
    IF TG_TABLE_NAME = 'ledger_journals' THEN journal_uuid := NEW.id; ELSE journal_uuid := NEW.journal_id; END IF;
    SELECT count(*), coalesce(sum(amount),0) INTO posting_count, posting_sum FROM ledger_entries WHERE journal_id = journal_uuid;
    IF posting_count < 2 OR posting_sum <> 0 THEN RAISE EXCEPTION 'Unbalanced ledger journal %', journal_uuid; END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER ledger_journal_balanced AFTER INSERT ON ledger_journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION clubpro_ledger_balanced();
CREATE CONSTRAINT TRIGGER ledger_entry_balanced AFTER INSERT ON ledger_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION clubpro_ledger_balanced();
SQL);
        }
    }

    public function down(): void
    {
        // Financial history is retained. Roll forward with an additive correction.
    }
};
