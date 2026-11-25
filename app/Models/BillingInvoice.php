<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $subject_id
 * @property int $community_id
 * @property int|null $member_identity_id
 * @property string $kind
 * @property string $source_key
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $amount
 * @property int $paid_amount
 * @property string $status
 * @property array<string, mixed> $snapshot
 * @property string|null $legacy_month
 * @property Carbon|null $paid_at
 */
class BillingInvoice extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            if ($invoice->isDirty(['subject_id', 'community_id', 'member_identity_id', 'kind', 'source_key', 'period_start', 'period_end', 'amount', 'snapshot', 'legacy_month'])) {
                throw new \LogicException('Snapshot tagihan tidak dapat diubah; gunakan jurnal koreksi yang diaudit.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Histori tagihan tidak dapat dihapus.'));
    }

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'amount' => 'integer', 'paid_amount' => 'integer', 'snapshot' => 'array', 'paid_at' => 'datetime'];
    }
}
