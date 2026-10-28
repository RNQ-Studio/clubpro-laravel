<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rebranding ke CEO (Community E-Commerce Operator): isian khusus panahan
 * dihapus dari data diri anggota. Data di kedua kolom ikut hilang (keputusan
 * pemilik produk); down() hanya mengembalikan kolom kosong.
 */
return new class extends Migration
{
    /** @return array<string, callable(Blueprint): mixed> */
    private function columns(): array
    {
        return [
            'archery_started_at' => fn (Blueprint $t) => $t->date('archery_started_at')->nullable(),
            'bow_type' => fn (Blueprint $t) => $t->string('bow_type', 20)->nullable(),
        ];
    }

    public function up(): void
    {
        $existing = array_values(array_filter(
            array_keys($this->columns()),
            fn (string $name): bool => Schema::hasColumn('users', $name),
        ));

        if ($existing !== []) {
            Schema::table('users', function (Blueprint $table) use ($existing): void {
                $table->dropColumn($existing);
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach ($this->columns() as $name => $define) {
                if (! Schema::hasColumn('users', $name)) {
                    $define($table);
                }
            }
        });
    }
};
