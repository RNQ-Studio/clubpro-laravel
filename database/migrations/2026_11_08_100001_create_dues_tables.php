<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran iuran (bukti transfer, diverifikasi creator/admin) dan status iuran per
 * anggota per bulan. Bulan tanpa baris = belum lunas. Keduanya mengacu ke pengguna, bukan
 * baris keanggotaan, agar riwayat tetap ada saat anggota keluar lalu bergabung lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dues_payments')) {
            Schema::create('dues_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('community_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('months_count');
                // Bulan yang dibayar ("Y-m"), disalin agar tetap terbaca sesudah ditolak.
                $table->json('months');
                $table->unsignedInteger('monthly_amount');
                $table->unsignedInteger('entry_fee')->default(0);
                $table->unsignedInteger('total_amount');
                $table->uuid('proof_asset_id')->nullable();
                $table->string('note', 500)->nullable();
                $table->string('status', 10)->default('pending');
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note', 500)->nullable();
                $table->timestamps();

                $table->foreign('proof_asset_id')->references('id')->on('assets')->nullOnDelete();
                $table->index(['community_id', 'status']);
                $table->index(['community_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('dues_months')) {
            Schema::create('dues_months', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('community_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // Hari pertama bulannya.
                $table->date('month');
                $table->string('status', 10);
                $table->foreignId('dues_payment_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['community_id', 'user_id', 'month']);
                $table->index('dues_payment_id');
            });
        }

        if (! Schema::hasColumn('community_members', 'entry_fee_paid_at')) {
            Schema::table('community_members', function (Blueprint $table): void {
                $table->timestamp('entry_fee_paid_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('community_members', 'entry_fee_paid_at')) {
            Schema::table('community_members', fn (Blueprint $table) => $table->dropColumn('entry_fee_paid_at'));
        }
        Schema::dropIfExists('dues_months');
        Schema::dropIfExists('dues_payments');
    }
};
