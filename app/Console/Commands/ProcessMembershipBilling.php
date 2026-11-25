<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

class ProcessMembershipBilling extends Command
{
    protected $signature = 'billing:process';

    protected $description = 'Buat tagihan dan pengingat keanggotaan secara idempoten dengan tanggal WIB';

    public function handle(BillingService $billing): int
    {
        $count = $billing->process();
        $this->info('Tagihan diperbarui; '.$count.' pengingat baru.');

        return self::SUCCESS;
    }
}
