<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'google_id')) {
                // `sub` dari ID token Google; unik per akun Google.
                $table->string('google_id')->nullable()->unique()->after('email');
            }
            if (! Schema::hasColumn('users', 'google_avatar_url')) {
                // Foto akun Google, dipakai bila anggota belum mengunggah foto sendiri.
                $table->string('google_avatar_url', 2048)->nullable()->after('avatar');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'google_avatar_url')) {
                $table->dropColumn('google_avatar_url');
            }
            if (Schema::hasColumn('users', 'google_id')) {
                $table->dropUnique(['google_id']);
                $table->dropColumn('google_id');
            }
        });
    }
};
