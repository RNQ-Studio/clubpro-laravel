<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $seller_identity_id
 * @property int $user_id
 * @property int $community_id
 * @property string $name
 * @property string $description
 * @property string $photo_asset_id
 * @property int $price
 * @property int $stock
 * @property bool $is_active
 * @property int $version
 * @property Carbon $created_at
 * @property-read MemberIdentity $seller
 * @property-read Community $community
 * @property-read Asset|null $photo
 */
class MarketplaceProduct extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'stock' => 'integer', 'is_active' => 'boolean', 'version' => 'integer'];
    }

    /** @return BelongsTo<MemberIdentity, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(MemberIdentity::class, 'seller_identity_id');
    }

    /** @return BelongsTo<Community, $this> */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'photo_asset_id');
    }
}
