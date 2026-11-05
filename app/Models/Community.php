<?php

namespace App\Models;

use App\Support\Enums\CommunityStatus;
use App\Support\Enums\CommunityType;
use Database\Factories\CommunityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Komunitas yang diajukan anggota. Hanya yang sudah disetujui tampil di daftar umum.
 *
 * @property int $id
 * @property int|null $user_id
 * @property CommunityStatus $status
 * @property string $name
 * @property string $short_name
 * @property CommunityType $type
 * @property string|null $type_other
 * @property string $description
 * @property string $address
 * @property string $province_code
 * @property string $province_name
 * @property string $city_code
 * @property string $city_name
 * @property string $contact_name
 * @property string $contact_phone
 * @property string|null $contact_email
 * @property string $logo_asset_id
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Asset|null $logo
 * @property-read User|null $owner
 * @property-read User|null $reviewer
 * @property-read Collection<int, Asset> $photos
 */
class Community extends Model
{
    /** @use HasFactory<CommunityFactory> */
    use HasFactory, LogsActivity;

    // `status` sengaja tidak fillable: pengaju tidak boleh menentukan statusnya sendiri.
    protected $fillable = [
        'name',
        'short_name',
        'type',
        'type_other',
        'description',
        'address',
        'province_code',
        'province_name',
        'city_code',
        'city_name',
        'contact_name',
        'contact_phone',
        'contact_email',
        'logo_asset_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CommunityStatus::class,
            'type' => CommunityType::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<Asset, $this> */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'logo_asset_id');
    }

    /** @return BelongsToMany<Asset, $this> */
    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'community_photos')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /** @param  Builder<Community>  $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', CommunityStatus::Approved);
    }

    /** Label jenis untuk ditampilkan; "Lainnya" memakai isian pengaju. */
    public function typeLabel(): string
    {
        return $this->type === CommunityType::Lainnya && filled($this->type_other)
            ? (string) $this->type_other
            : $this->type->label();
    }
}
