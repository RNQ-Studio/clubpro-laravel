<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data aktivitas untuk Admin Panel: kapan pengguna terakhir login/akses, dan
 * dari mana perangkatnya mengakses. Semua nullable; data lama memang tidak ada.
 */
return new class extends Migration
{
    /** @return array<string, array<string, callable(Blueprint): mixed>> */
    private function columns(): array
    {
        return [
            'users' => [
                'last_login_at' => fn (Blueprint $t) => $t->timestamp('last_login_at')->nullable(),
                'last_seen_at' => fn (Blueprint $t) => $t->timestamp('last_seen_at')->nullable()->index(),
            ],
            'user_devices' => [
                'device_type' => fn (Blueprint $t) => $t->string('device_type', 10)->nullable(),
                'ip_address' => fn (Blueprint $t) => $t->string('ip_address', 45)->nullable(),
                'user_agent' => fn (Blueprint $t) => $t->string('user_agent', 512)->nullable(),
                'location' => fn (Blueprint $t) => $t->string('location')->nullable(),
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->columns() as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns): void {
                foreach ($columns as $name => $define) {
                    if (! Schema::hasColumn($tableName, $name)) {
                        $define($table);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->columns() as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns): void {
                $existing = array_values(array_filter(
                    array_keys($columns),
                    fn (string $name): bool => Schema::hasColumn($tableName, $name),
                ));
                if ($existing !== []) {
                    $table->dropColumn($existing);
                }
            });
        }
    }
};
