<?php

use App\Models\Community;
use App\Services\BillingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('billing_subjects')) {
            return;
        }
        Community::query()->where('dues_period_months', '>', 1)->orderBy('id')->chunkById(100, function ($communities): void {
            foreach ($communities as $community) {
                app(BillingService::class)->transitionLegacyPeriods($community);
            }
        });
    }

    public function down(): void
    {
        // Price periods and issued snapshots must never be rewritten on rollback. Fix forward.
    }
};
