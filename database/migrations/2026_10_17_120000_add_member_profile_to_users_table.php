<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data diri anggota club. Semua kolom nullable: anggota boleh menyimpan
 * sebagian dan melengkapinya nanti dari halaman profil.
 */
return new class extends Migration
{
    /** @return array<string, callable(Blueprint): mixed> */
    private function columns(): array
    {
        return [
            'gender' => fn (Blueprint $t) => $t->string('gender', 10)->nullable(),
            'birth_place' => fn (Blueprint $t) => $t->string('birth_place', 100)->nullable(),
            'birth_date' => fn (Blueprint $t) => $t->date('birth_date')->nullable(),
            'address' => fn (Blueprint $t) => $t->text('address')->nullable(),
            'archery_started_at' => fn (Blueprint $t) => $t->date('archery_started_at')->nullable(),
            'bow_type' => fn (Blueprint $t) => $t->string('bow_type', 20)->nullable(),
            'blood_type' => fn (Blueprint $t) => $t->string('blood_type', 3)->nullable(),
            'emergency_contact_name' => fn (Blueprint $t) => $t->string('emergency_contact_name', 100)->nullable(),
            'emergency_contact_phone' => fn (Blueprint $t) => $t->string('emergency_contact_phone', 30)->nullable(),
        ];
    }

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach ($this->columns() as $name => $define) {
                if (! Schema::hasColumn('users', $name)) {
                    $define($table);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $existing = array_values(array_filter(
                array_keys($this->columns()),
                fn (string $name): bool => Schema::hasColumn('users', $name),
            ));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
