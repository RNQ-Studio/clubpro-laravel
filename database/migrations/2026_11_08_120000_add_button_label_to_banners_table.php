<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('banners', 'button_label')) {
            return;
        }

        Schema::table('banners', function (Blueprint $table) {
            // Tombol di bawah gambar popup Paid Promotion; aksinya dari `link_url`.
            $table->string('button_label', 40)->nullable()->after('link_url');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('banners', 'button_label')) {
            Schema::table('banners', function (Blueprint $table) {
                $table->dropColumn('button_label');
            });
        }
    }
};
