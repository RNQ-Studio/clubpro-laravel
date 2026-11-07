<?php

namespace App\Models;

use App\Support\Enums\DuesMonthStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Iuran satu bulan yang lunas atau sedang diajukan. Bulan tanpa baris = belum lunas.
 *
 * @property int $id
 * @property int $community_id
 * @property int $user_id
 * @property Carbon $month
 * @property DuesMonthStatus $status
 * @property int|null $dues_payment_id
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DuesPayment|null $payment
 */
class DuesMonth extends Model
{
    protected $fillable = ['user_id', 'month', 'status', 'dues_payment_id', 'updated_by'];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'status' => DuesMonthStatus::class,
        ];
    }

    /** @return BelongsTo<DuesPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(DuesPayment::class, 'dues_payment_id');
    }
}
