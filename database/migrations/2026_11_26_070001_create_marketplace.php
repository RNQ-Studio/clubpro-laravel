<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_products')) {
            Schema::create('marketplace_products', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('seller_identity_id')->constrained('member_identities')->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->foreignId('community_id')->constrained()->restrictOnDelete();
                $table->string('name', 150);
                $table->text('description');
                $table->foreignUuid('photo_asset_id')->constrained('assets')->restrictOnDelete();
                $table->bigInteger('price');
                $table->integer('stock');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
                $table->index(['seller_identity_id', 'is_active']);
                $table->index(['community_id', 'is_active']);
            });
        }
        if (! Schema::hasTable('marketplace_orders')) {
            Schema::create('marketplace_orders', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('product_id')->constrained('marketplace_products')->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->foreignId('buyer_identity_id')->constrained('member_identities')->restrictOnDelete();
                $table->foreignId('seller_identity_id')->constrained('member_identities')->restrictOnDelete();
                $table->foreignId('community_id')->constrained()->restrictOnDelete();
                $table->foreignUuid('journal_id')->constrained('ledger_journals')->restrictOnDelete();
                $table->integer('quantity');
                $table->bigInteger('unit_price');
                $table->bigInteger('total');
                $table->string('status')->default('succeeded');
                $table->string('idempotency_key', 150);
                $table->string('payload_hash', 64);
                $table->jsonb('snapshot');
                $table->timestampTz('created_at');
                $table->unique(['user_id', 'idempotency_key']);
                $table->index(['buyer_identity_id', 'created_at']);
                $table->index(['seller_identity_id', 'created_at']);
            });
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION clubpro_marketplace_order_guard() RETURNS trigger AS $$
BEGIN RAISE EXCEPTION 'Final marketplace orders are immutable; no refund'; END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS marketplace_order_history ON marketplace_orders;
CREATE TRIGGER marketplace_order_history BEFORE UPDATE OR DELETE ON marketplace_orders FOR EACH ROW EXECUTE FUNCTION clubpro_marketplace_order_guard();
SQL);
            if (! DB::select('SELECT 1 FROM pg_constraint WHERE conname = ?', ['marketplace_product_valid'])) {
                DB::statement('ALTER TABLE marketplace_products ADD CONSTRAINT marketplace_product_valid CHECK (price > 0 AND price <= 9007199254740991 AND stock >= 0 AND version >= 1)');
            }
            if (! DB::select('SELECT 1 FROM pg_constraint WHERE conname = ?', ['marketplace_order_valid'])) {
                DB::statement("ALTER TABLE marketplace_orders ADD CONSTRAINT marketplace_order_valid CHECK (quantity > 0 AND unit_price > 0 AND total = unit_price * quantity AND total <= 9007199254740991 AND buyer_identity_id <> seller_identity_id AND status = 'succeeded')");
            }
        }
    }

    public function down(): void
    {
        // Preserve committed financial history; roll forward to repair.
    }
};
