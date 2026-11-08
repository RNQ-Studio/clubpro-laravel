<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wilayah data diri anggota: domisili dan wilayah sesuai NIK (KTP), masing-masing
 * provinsi + kabupaten/kota (kode Kemendagri dan namanya, seperti komunitas).
 * Semua nullable: data diri boleh diisi bertahap.
 */
return new class extends Migration
{
    /** @return array<string, callable(Blueprint): mixed> */
    private function columns(): array
    {
        return [
            'domicile_province_code' => fn (Blueprint $t) => $t->string('domicile_province_code', 2)->nullable(),
            'domicile_province_name' => fn (Blueprint $t) => $t->string('domicile_province_name', 100)->nullable(),
            'domicile_city_code' => fn (Blueprint $t) => $t->string('domicile_city_code', 5)->nullable(),
            'domicile_city_name' => fn (Blueprint $t) => $t->string('domicile_city_name', 100)->nullable(),
            'nik_province_code' => fn (Blueprint $t) => $t->string('nik_province_code', 2)->nullable(),
            'nik_province_name' => fn (Blueprint $t) => $t->string('nik_province_name', 100)->nullable(),
            'nik_city_code' => fn (Blueprint $t) => $t->string('nik_city_code', 5)->nullable(),
            'nik_city_name' => fn (Blueprint $t) => $t->string('nik_city_name', 100)->nullable(),
            'nik_same_as_domicile' => fn (Blueprint $t) => $t->boolean('nik_same_as_domicile')->default(false),
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
