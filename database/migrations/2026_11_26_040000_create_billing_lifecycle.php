<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('communities', 'dues_period_months')) {
            Schema::table('communities', function (Blueprint $table): void {
                $table->unsignedSmallInteger('dues_period_months')->default(1);
                $table->date('billing_cutover_date')->nullable();
            });
            DB::table('communities')->update(['billing_cutover_date' => now('Asia/Jakarta')->toDateString()]);
        }
        if (! Schema::hasColumn('community_members', 'billing_scheme')) {
            Schema::table('community_members', function (Blueprint $table): void {
                $table->string('billing_scheme', 24)->default('legacy_calendar');
            });
        }
        if (! Schema::hasTable('billing_subjects')) {
            Schema::create('billing_subjects', function (Blueprint $table): void {
                $table->id();
                $table->string('subject_key')->unique();
                $table->foreignId('merged_into_subject_id')->nullable()->constrained('billing_subjects')->restrictOnDelete();
                $table->foreignId('community_id')->constrained()->restrictOnDelete();
                $table->foreignId('member_identity_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('member_id')->nullable()->constrained('community_members')->restrictOnDelete();
                $table->string('kind', 12);
                $table->string('scheme', 24);
                $table->date('trial_start')->nullable();
                $table->date('trial_end')->nullable();
                $table->date('anchor_date');
                $table->unsignedSmallInteger('due_day')->nullable();
                $table->unsignedInteger('next_offset')->default(0);
                $table->date('inactive_at')->nullable();
                $table->date('paused_at')->nullable();
                $table->date('retained_until')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->foreignId('archived_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('billing_invoices')) {
            Schema::create('billing_invoices', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('subject_id')->constrained('billing_subjects')->restrictOnDelete();
                $table->foreignId('community_id')->constrained()->restrictOnDelete();
                $table->foreignId('member_identity_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('kind', 12);
                $table->string('source_key')->unique();
                $table->date('period_start');
                $table->date('period_end');
                $table->bigInteger('amount');
                $table->bigInteger('paid_amount')->default(0);
                $table->string('status', 20)->default('open');
                $table->json('snapshot');
                $table->string('legacy_month', 7)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
                $table->index(['subject_id', 'period_start']);
            });
        }
        if (! Schema::hasTable('billing_allocations')) {
            Schema::create('billing_allocations', function (Blueprint $table): void {
                $table->id();
                $table->uuid('invoice_id');
                $table->foreign('invoice_id')->references('id')->on('billing_invoices')->restrictOnDelete();
                $table->string('source_reference');
                $table->bigInteger('amount');
                $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('created_at');
                $table->unique(['invoice_id', 'source_reference']);
            });
        }
        if (! Schema::hasTable('billing_notices')) {
            Schema::create('billing_notices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('subject_id')->constrained('billing_subjects')->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->string('deduplication_key')->unique();
                $table->string('type', 30);
                $table->string('message', 500);
                $table->timestamp('created_at');
            });
        }
        if (! Schema::hasTable('billing_decisions')) {
            Schema::create('billing_decisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('subject_id')->constrained('billing_subjects')->restrictOnDelete();
                $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $table->string('action', 20);
                $table->string('reason', 500);
                $table->timestamp('created_at');
            });
        }
        // Keuangan legacy tetap dipertahankan meskipun komunitas/akun diarsipkan.
        foreach (['dues_payments', 'dues_months'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['community_id']);
                $table->dropForeign(['user_id']);
                $table->foreign('community_id')->references('id')->on('communities')->restrictOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Roll forward: trial grants, invoices, allocations and audit must never be erased.
    }
};
