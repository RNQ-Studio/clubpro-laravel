<?php

namespace App\Console\Commands;

use App\Services\LedgerService;
use Illuminate\Console\Command;

/** Read-only journal/cache/lot/custody audit; does not silently repair financial data. */
class ReconcileLedger extends Command
{
    protected $signature = 'finance:reconcile';

    protected $description = 'Periksa keseimbangan jurnal, saldo, lot custody dan clearing tanpa mengubah data';

    public function handle(LedgerService $ledger): int
    {
        $result = $ledger->reconcile();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['balanced'] ? self::SUCCESS : self::FAILURE;
    }
}
