<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keanggotaan komunitas. Pengaju yang sudah ada menjadi creator agar komunitasnya
 * tidak kehilangan pengelola.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('community_members')) {
            Schema::create('community_members', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('community_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('role', 10)->default('member');
                $table->string('status', 10)->default('pending');
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();

                $table->unique(['community_id', 'user_id']);
                $table->index(['community_id', 'status']);
                $table->index(['user_id', 'status']);
            });
        }

        DB::table('communities')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->each(function (object $community): void {
                DB::table('community_members')->insertOrIgnore([
                    'community_id' => $community->id,
                    'user_id' => $community->user_id,
                    'role' => 'creator',
                    'status' => 'active',
                    'joined_at' => $community->created_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_members');
    }
};
