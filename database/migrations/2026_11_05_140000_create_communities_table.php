<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Komunitas yang diajukan anggota. Wilayah disimpan sebagai kode + nama tanpa FK:
 * `regions:seed --fresh` memakai TRUNCATE CASCADE dan datanya tidak dipakai di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('communities')) {
            Schema::create('communities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('status', 20)->default('pending');
                $table->string('name', 100);
                $table->string('short_name', 30);
                $table->string('type', 30);
                $table->string('type_other', 50)->nullable();
                $table->text('description');
                $table->string('address', 500);
                $table->string('province_code', 2);
                $table->string('province_name', 100);
                $table->string('city_code', 5);
                $table->string('city_name', 100);
                $table->string('contact_name', 100);
                $table->string('contact_phone', 20);
                $table->string('contact_email', 150)->nullable();
                $table->foreignUuid('logo_asset_id')->unique()->constrained('assets');
                $table->timestamps();

                $table->index(['status', 'name']);
                $table->index('user_id');
                $table->index('type');
                $table->index('province_code');
            });
        }

        if (! Schema::hasTable('community_photos')) {
            Schema::create('community_photos', function (Blueprint $table): void {
                $table->foreignId('community_id')->constrained()->cascadeOnDelete();
                // Unik: satu foto hanya milik satu komunitas, juga saat kirim ganda bersamaan.
                $table->foreignUuid('asset_id')->unique()->constrained('assets');
                $table->unsignedSmallInteger('sort_order')->default(0);

                $table->primary(['community_id', 'asset_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('community_photos');
        Schema::dropIfExists('communities');
    }
};
