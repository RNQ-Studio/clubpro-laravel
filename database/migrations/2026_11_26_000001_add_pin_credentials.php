<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'pin_hash')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('pin_hash')->nullable();
            });
        }
        if (! Schema::hasTable('pin_recoveries')) {
            Schema::create('pin_recoveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('code_hash');
                $table->string('email_hash', 64);
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pin_recoveries');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('pin_hash'));
    }
};
