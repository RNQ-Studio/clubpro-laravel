<?php

namespace App\Models;

use App\Support\Enums\AssetStatus;
use App\Support\Enums\StorageType;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property int|null $user_id
 * @property StorageType $storage_type
 * @property string $path
 * @property string|null $url
 * @property string $original_filename
 * @property string|null $extension
 * @property string $mime_type
 * @property int $size
 * @property string|null $checksum
 * @property string|null $category
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $retain_until
 * @property bool $is_protected
 * @property AssetStatus $status
 * @property Carbon|null $soft_deleted_at
 * @property Carbon|null $scheduled_hard_delete_at
 * @property Carbon|null $hard_deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'storage_type',
        'path',
        'url',
        'original_filename',
        'extension',
        'mime_type',
        'size',
        'checksum',
        'category',
        'metadata',
        'retain_until',
        'is_protected',
    ];

    protected function casts(): array
    {
        return [
            'storage_type' => StorageType::class,
            'status' => AssetStatus::class,
            'metadata' => 'array',
            'size' => 'integer',
            'is_protected' => 'boolean',
            'retain_until' => 'datetime',
            'soft_deleted_at' => 'datetime',
            'scheduled_hard_delete_at' => 'datetime',
            'hard_deleted_at' => 'datetime',
        ];
    }

    // ── Relations ──────────────────────────────────────────────────────

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ── Query Scopes ───────────────────────────────────────────────────

    /**
     * File yang sudah melewati masa retensi & masih aktif — kandidat soft delete.
     *
     * @param  Builder<Asset>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $query->where('status', AssetStatus::Active)
            ->whereNotNull('retain_until')
            ->where('retain_until', '<', now());
    }

    /**
     * File yang sudah di-soft-delete dan jadwal hard delete-nya telah tiba.
     *
     * @param  Builder<Asset>  $query
     */
    public function scopePendingHardDelete(Builder $query): void
    {
        $query->where('status', AssetStatus::SoftDeleted)
            ->whereNotNull('scheduled_hard_delete_at')
            ->where('scheduled_hard_delete_at', '<=', now());
    }

    /** @param Builder<Asset> $query */
    public function scopeOnGCS(Builder $query): void
    {
        $query->where('storage_type', StorageType::Gcs);
    }

    /**
     * Filter berdasarkan kategori bisnis ATAU prefix mime type.
     * Contoh: byType('image') → cocok dengan 'image/jpeg', 'image/png'.
     * Contoh: byType('bukti_transfer') → cocok dengan kolom category.
     *
     * @param  Builder<Asset>  $query
     */
    public function scopeByType(Builder $query, string $type): void
    {
        // Grouping wajib agar orWhere tidak "bocor" keluar scope bila ada where lain di luar.
        $query->where(function (Builder $q) use ($type): void {
            $q->where('category', $type)
                ->orWhere('mime_type', 'like', $type.'/%')
                ->orWhere('mime_type', $type);
        });
    }

    /**
     * File permanen — retain_until IS NULL, tidak pernah dihapus otomatis.
     *
     * @param  Builder<Asset>  $query
     */
    public function scopePermanent(Builder $query): void
    {
        $query->whereNull('retain_until');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    public function isLocal(): bool
    {
        return $this->storage_type === StorageType::Local;
    }

    public function isGCS(): bool
    {
        return $this->storage_type === StorageType::Gcs;
    }

    /**
     * Berkas lokal yang boleh disajikan lewat route `assets.file`. Hanya gambar raster:
     * SVG bisa memuat skrip yang berjalan di domain back-office.
     */
    public function isServableImage(): bool
    {
        return $this->isLocal()
            && $this->status === AssetStatus::Active
            && in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    }

    /**
     * URL publik file. Pakai url yang tersimpan bila ada (mis. CDN/GCS signed URL),
     * jika tidak, bangun dari disk yang sesuai via Storage facade.
     */
    public function getPublicUrl(): ?string
    {
        if ($this->status === AssetStatus::HardDeleted) {
            return null;
        }

        if (filled($this->url)) {
            return $this->url;
        }

        if ($this->isServableImage()) {
            return route('assets.file', $this);
        }

        return Storage::disk($this->storage_type->disk())->url($this->path);
    }

    /**
     * Tandai file sebagai soft-deleted dan jadwalkan hard delete.
     * Tidak menyentuh file fisik — hanya transisi state di database.
     * Return false bila file dilindungi (is_protected = true).
     */
    public function markAsSoftDeleted(int $hardDeleteAfterDays = 30): bool
    {
        if ($this->is_protected) {
            return false;
        }

        $this->status = AssetStatus::SoftDeleted;
        $this->soft_deleted_at = now();
        $this->scheduled_hard_delete_at = now()->addDays($hardDeleteAfterDays);

        return $this->save();
    }

    /**
     * Masa retensi sudah terlewati dan file masih aktif.
     * File dengan retain_until NULL dianggap permanen → selalu false.
     */
    public function isExpired(): bool
    {
        return $this->status === AssetStatus::Active
            && $this->retain_until !== null
            && $this->retain_until->isPast();
    }

    public function isProtected(): bool
    {
        return $this->is_protected;
    }
}
