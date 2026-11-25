<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('billing_notices', 'read_at')) {
            Schema::table('billing_notices', function (Blueprint $table): void {
                $table->timestamp('read_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Preserve the delivery/read audit during rollback; fix forward.
    }
};
