<?php

namespace App\Models;

use App\Support\Enums\DuesPaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pembayaran iuran dengan bukti transfer, menunggu verifikasi creator/admin komunitas.
 *
 * @property int $id
 * @property int $community_id
 * @property int $user_id
 * @property int $months_count
 * @property list<string> $months
 * @property int $monthly_amount
 * @property int $entry_fee
 * @property int $total_amount
 * @property string|null $proof_asset_id
 * @property string|null $note
 * @property DuesPaymentStatus $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Community $community
 * @property-read User $user
 * @property-read User|null $reviewer
 * @property-read Asset|null $proof
 */
class DuesPayment extends Model
{
    protected $fillable = ['months_count', 'months', 'monthly_amount', 'entry_fee', 'total_amount', 'proof_asset_id', 'note'];

    protected function casts(): array
    {
        return [
            'months' => 'array',
            'status' => DuesPaymentStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Community, $this> */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<Asset, $this> */
    public function proof(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'proof_asset_id');
    }
}
