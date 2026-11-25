<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('member_identities')) {
            Schema::create('member_identities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
                $table->foreignId('merged_into_id')->nullable()->constrained('member_identities')->restrictOnDelete();
                $table->string('name');
                $table->date('birth_date')->nullable();
                $table->string('gender', 10)->nullable();
                $table->boolean('is_public')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('community_members', 'member_identity_id')) {
            Schema::table('community_members', function (Blueprint $table): void {
                $table->foreignId('user_id')->nullable()->change();
                $table->foreignId('member_identity_id')->nullable()->constrained('member_identities')->restrictOnDelete();
                $table->foreignId('merged_into_member_id')->nullable()->constrained('community_members')->restrictOnDelete();
                $table->string('external_reference', 100)->nullable();
                $table->uuid('creation_key')->nullable();
                $table->string('creation_hash', 64)->nullable();
                $table->string('account_email')->nullable();
                $table->unique(['community_id', 'member_identity_id']);
                $table->unique(['community_id', 'external_reference']);
                $table->unique(['community_id', 'creation_key']);
            });
        }
        DB::table('users')->orderBy('id')->chunkById(500, function ($users): void {
            foreach ($users as $user) {
                DB::table('member_identities')->insertOrIgnore([
                    'user_id' => $user->id, 'name' => $user->name,
                    'birth_date' => $user->birth_date, 'gender' => $user->gender,
                    'is_public' => $user->birth_date === null || Carbon::parse($user->birth_date)->age >= 18, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('community_members')->where('user_id', $user->id)->whereNull('member_identity_id')
                    ->update(['member_identity_id' => DB::table('member_identities')->where('user_id', $user->id)->value('id')]);
            }
        });
        if (! Schema::hasTable('guardian_grants')) {
            Schema::create('guardian_grants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('member_identity_id')->constrained()->restrictOnDelete();
                $table->foreignId('guardian_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('community_member_id')->constrained()->restrictOnDelete();
                $table->timestamp('verified_at');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->unique(['member_identity_id', 'guardian_user_id']);
            });
        }
        if (! Schema::hasTable('member_invitations')) {
            Schema::create('member_invitations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('member_identity_id')->constrained()->restrictOnDelete();
                $table->foreignId('community_member_id')->constrained()->restrictOnDelete();
                $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
                $table->string('kind', 10);
                $table->string('email');
                $table->string('token_hash', 64);
                $table->timestamp('expires_at');
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->index(['email', 'claimed_at']);
            });
        }
    }

    public function down(): void
    {
        // Identity/KTA/audit links must survive rollback; roll forward instead.
    }
};
