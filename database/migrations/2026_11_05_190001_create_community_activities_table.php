<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('community_activities')) {
            return;
        }

        Schema::create('community_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 100);
            $table->text('description')->nullable();
            $table->string('location', 200)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['community_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_activities');
    }
};
