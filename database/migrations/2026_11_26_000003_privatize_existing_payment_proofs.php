<?php

use App\Models\Asset;
use App\Services\SensitiveAssetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE assets DROP CONSTRAINT IF EXISTS assets_storage_type_check');
            DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_storage_type_check CHECK (storage_type IN ('local', 'gcs', 'private'))");
        } else {
            Schema::table('assets', function (Blueprint $table): void {
                $table->enum('storage_type', ['local', 'gcs', 'private'])->default('local')->change();
            });
        }
        Asset::withTrashed()->where('storage_type', '!=', 'private')
            ->where(function (Builder $query): void {
                $query->whereIn('category', ['dues-proof', 'bukti-transfer', 'bukti_transfer', 'topup-proof', 'payout-proof'])
                    ->orWhereIn('id', fn ($proofs) => $proofs->select('proof_asset_id')->from('dues_payments')->whereNotNull('proof_asset_id'));
            })->eachById(function (Asset $asset): void {
                app(SensitiveAssetService::class)->privatize($asset);
            });
    }

    public function down(): void
    {
        // Privacy cannot be rolled back by making proof bytes public again.
    }
};
