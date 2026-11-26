<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property int $seller_identity_id
 * @property int $community_id
 * @property string $title
 * @property string $body
 * @property string|null $photo_asset_id
 * @property string|null $target_product_id
 * @property bool $is_active
 * @property int $version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MemberAdvertisement extends Model
{
    use HasUuids;

    protected $table = 'member_advertisements';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }
}
