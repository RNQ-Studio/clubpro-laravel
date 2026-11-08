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
 * Paid Promotion: popup slider yang tampil di Beranda app anggota tiap
 * {@see Banner::intervalHours()} jam. Tombol di bawah gambar menjalankan `link_url`.
 *
 * @property int $id
 * @property string $title
 * @property string|null $subtitle
 * @property string|null $image
 * @property string|null $link_url
 * @property string|null $button_label
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

    /** Banner terbanyak yang tampil di slider Beranda. */
    public const MAX_VISIBLE = 10;

    /** Halaman app yang boleh menjadi tujuan banner. */
    public const APP_LINK_PATTERN = '#^/(profile|communities(/new|/[1-9][0-9]*)?)$#';

    /** Chat WhatsApp: nomor berkode negara (tanpa `+`/0 di depan), pesan awal opsional. */
    public const WHATSAPP_LINK_PATTERN = '#^https://wa\.me/[1-9][0-9]{7,14}(\?text=[^&\#\s]*)?$#';

    /** AppConfig: popup tampil lagi di perangkat yang sama sesudah sekian jam. */
    public const INTERVAL_CONFIG_KEY = 'paid_promotion_interval_hours';

    public const DEFAULT_INTERVAL_HOURS = 24;

    public const MAX_INTERVAL_HOURS = 720;

    protected $fillable = [
        'title',
        'subtitle',
        'image',
        'link_url',
        'button_label',
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

    /** @return 'live'|'inactive'|'scheduled'|'ended' Sama dengan syarat scope `visible`. */
    public function displayStatus(): string
    {
        return match (true) {
            ! $this->is_active => 'inactive',
            $this->starts_at?->isFuture() ?? false => 'scheduled',
            $this->ends_at?->isPast() ?? false => 'ended',
            default => 'live',
        };
    }

    /** Id komunitas bila tautan menuju halaman klub tertentu. */
    public static function communityIdFromLink(?string $link): ?int
    {
        return preg_match('#^/communities/([1-9][0-9]*)$#', (string) $link, $match) === 1 ? (int) $match[1] : null;
    }

    /** Jeda tampil popup; isian AppConfig yang rusak memakai bawaan. */
    public static function intervalHours(): int
    {
        $hours = AppConfig::get(self::INTERVAL_CONFIG_KEY);

        return is_int($hours) && $hours >= 1 && $hours <= self::MAX_INTERVAL_HOURS
            ? $hours
            : self::DEFAULT_INTERVAL_HOURS;
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
