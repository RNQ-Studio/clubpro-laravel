<?php

namespace App\Models;

use App\Support\Enums\DevicePlatform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string $device_id
 * @property DevicePlatform $platform
 * @property string|null $os_version
 * @property string|null $app_version
 * @property string|null $device_name
 * @property string|null $push_token
 * @property string|null $device_type
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $location
 * @property Carbon|null $last_active_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserDevice extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'device_id',
        'platform',
        'os_version',
        'app_version',
        'device_name',
        'push_token',
        'device_type',
        'ip_address',
        'user_agent',
        'location',
        'last_active_at',
    ];

    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'last_active_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<UserDevice> $query */
    public function scopeWithPushToken(Builder $query): void
    {
        $query->whereNotNull('push_token');
    }
}
