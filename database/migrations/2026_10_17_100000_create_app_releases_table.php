<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_releases')) {
            return;
        }

        Schema::create('app_releases', function (Blueprint $table) {
            $table->id();
            $table->string('app', 50);
            $table->string('channel', 20);
            $table->string('version_name', 50);
            $table->unsignedInteger('version_code');
            $table->string('file_disk', 50);
            $table->string('file_path');
            $table->unsignedBigInteger('file_size');
            $table->char('file_sha256', 64);
            $table->char('signing_sha256', 64)->nullable();
            $table->string('git_sha', 50)->nullable();
            $table->text('release_notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamps();

            // Satu baris per app+channel: rilis baru menimpa rilis lama.
            $table->unique(['app', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};
