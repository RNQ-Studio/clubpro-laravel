<?php

use App\Models\DuesPayment;
use App\Services\IncomingReceiptService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DuesPayment::query()->whereNotNull('proof_asset_id')->whereExists(fn ($query) => $query->selectRaw('1')->from('assets')->whereColumn('assets.id', 'dues_payments.proof_asset_id'))->orderBy('id')->each(function (DuesPayment $payment): void {
            app(IncomingReceiptService::class)->registerLegacyProof($payment, true);
        });
    }

    public function down(): void
    {
        // Receipt references remain reserved to prevent duplicate financial allocation.
    }
};
