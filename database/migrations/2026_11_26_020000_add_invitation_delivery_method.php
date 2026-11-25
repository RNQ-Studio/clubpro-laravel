<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('member_invitations', 'delivery_method')) {
            Schema::table('member_invitations', function (Blueprint $table): void {
                // Existing invitations retain the emailed-token ownership proof.
                $table->string('delivery_method', 10)->default('email');
            });
        }
    }

    public function down(): void
    {
        // Keep the proof method so rollback never turns consent into a token claim.
    }
};
