<?php

namespace App\Models;

use App\Services\AssetDeletionService;
use App\Services\FileUploadService;
use Database\Factories\BannerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Banner slider di Beranda app anggota.
 *
 * @property int $id
 * @property string $title
 * @property string|null $subtitle
 * @property string|null $image
 * @property string|null $link_url
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $image_url
 * @property-read Asset|null $imageAsset
 */
class Banner extends Model
{
    /** @use HasFactory<BannerFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'title',
        'subtitle',
        'image',
        'link_url',
        'sort_order',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Banner dihapus → gambar asset-nya ikut dihapus dari storage.
        static::deleted(function (Banner $banner): void {
            if (filled($banner->image) && Str::isUuid($banner->image)) {
                $asset = Asset::find($banner->image);
                if ($asset !== null) {
                    app(AssetDeletionService::class)->hardDelete($asset);
                }
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Asset, $this> */
    public function imageAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'image');
    }

    /**
     * Banner aktif yang sedang dalam jadwal tayang, urut sesuai sort_order.
     *
     * @param  Builder<Banner>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $now = now();

        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function getImageUrlAttribute(): ?string
    {
        if (blank($this->image)) {
            return null;
        }

        return Str::isUuid($this->image)
            ? $this->imageAsset?->getPublicUrl()
            : app(FileUploadService::class)->url($this->image);
    }
}
