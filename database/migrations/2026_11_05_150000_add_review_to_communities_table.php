<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil verifikasi komunitas: siapa, kapan, dan alasan penolakannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table): void {
            if (! Schema::hasColumn('communities', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('communities', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable();
            }
            if (! Schema::hasColumn('communities', 'review_note')) {
                $table->string('review_note', 500)->nullable();
            }
        });

        Schema::table('communities', function (Blueprint $table): void {
            if (! Schema::hasIndex('communities', ['status', 'created_at'])) {
                $table->index(['status', 'created_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('communities', function (Blueprint $table): void {
            if (Schema::hasIndex('communities', ['status', 'created_at'])) {
                $table->dropIndex(['status', 'created_at']);
            }
            if (Schema::hasColumn('communities', 'reviewed_by')) {
                $table->dropConstrainedForeignId('reviewed_by');
            }
            foreach (['reviewed_at', 'review_note'] as $column) {
                if (Schema::hasColumn('communities', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
