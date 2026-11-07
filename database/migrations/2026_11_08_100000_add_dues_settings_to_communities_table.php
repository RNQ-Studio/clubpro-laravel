<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setelan iuran per komunitas, diatur creator/admin. Kosong = iuran belum diatur.
 * Nominal dalam rupiah utuh.
 */
return new class extends Migration
{
    /** @var array<string, callable(Blueprint): void> */
    private array $columns;

    public function __construct()
    {
        $this->columns = [
            'dues_amount' => fn (Blueprint $table) => $table->unsignedInteger('dues_amount')->nullable(),
            'entry_fee' => fn (Blueprint $table) => $table->unsignedInteger('entry_fee')->default(0),
            'dues_start_date' => fn (Blueprint $table) => $table->date('dues_start_date')->nullable(),
            'dues_bank_name' => fn (Blueprint $table) => $table->string('dues_bank_name', 60)->nullable(),
            'dues_account_number' => fn (Blueprint $table) => $table->string('dues_account_number', 40)->nullable(),
            'dues_account_name' => fn (Blueprint $table) => $table->string('dues_account_name', 100)->nullable(),
        ];
    }

    public function up(): void
    {
        foreach ($this->columns as $name => $define) {
            if (! Schema::hasColumn('communities', $name)) {
                Schema::table('communities', fn (Blueprint $table) => $define($table));
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->columns) as $name) {
            if (Schema::hasColumn('communities', $name)) {
                Schema::table('communities', fn (Blueprint $table) => $table->dropColumn($name));
            }
        }
    }
};
