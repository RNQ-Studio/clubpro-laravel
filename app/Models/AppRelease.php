<?php

namespace App\Models;

use Database\Factories\AppReleaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * APK terbaru untuk satu pasangan app+channel. Hanya satu baris (dan satu file)
 * per pasangan; rilis baru memperbarui baris ini dan menghapus file lama.
 *
 * @property int $id
 * @property string $app
 * @property string $channel
 * @property string $version_name
 * @property int $version_code
 * @property string $file_disk
 * @property string $file_path
 * @property int $file_size
 * @property string $file_sha256
 * @property string|null $signing_sha256
 * @property string|null $git_sha
 * @property string|null $release_notes
 * @property int|null $uploaded_by
 * @property Carbon $uploaded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $app_name
 * @property-read string $download_filename
 * @property-read User|null $uploader
 */
class AppRelease extends Model
{
    /** @use HasFactory<AppReleaseFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'app',
        'channel',
        'version_name',
        'version_code',
        'file_disk',
        'file_path',
        'file_size',
        'file_sha256',
        'signing_sha256',
        'git_sha',
        'release_notes',
        'uploaded_by',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'version_code' => 'integer',
            'file_size' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Menghapus rilis (mis. dari back-office) ikut menghapus file APK-nya.
        static::deleted(function (AppRelease $release): void {
            Storage::disk($release->file_disk)->delete($release->file_path);
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['version_name', 'version_code', 'file_size', 'file_sha256', 'signing_sha256', 'git_sha', 'uploaded_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getAppNameAttribute(): string
    {
        return (string) (config("app_release.apps.{$this->app}") ?? Str::headline($this->app));
    }

    /**
     * Nama file saat diunduh: nama aplikasi, channel, versi, build, dan waktu unggah.
     * Contoh: ClubPro-Dev-v1.2.0-build15-2026-10-17-1030WIB.apk
     */
    public function getDownloadFilenameAttribute(): string
    {
        $uploadedAt = $this->uploaded_at->copy()->setTimezone(config('app_release.timezone', 'UTC'));

        // 'T' memberi singkatan zona (WIB untuk Asia/Jakarta); zona tanpa singkatan
        // menghasilkan offset seperti "+07" sehingga diberi awalan UTC.
        $zone = $uploadedAt->format('T');
        $zone = preg_match('/^[A-Za-z]+$/', $zone) === 1 ? $zone : 'UTC'.$zone;

        return sprintf(
            '%s-%s-v%s-build%d-%s%s.apk',
            self::filenameSegment($this->app_name),
            self::filenameSegment(Str::ucfirst($this->channel)),
            self::filenameSegment($this->version_name),
            $this->version_code,
            $uploadedAt->format('Y-m-d-Hi'),
            self::filenameSegment($zone),
        );
    }

    private static function filenameSegment(string $value): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9.]+/', '-', $value), '-.');
    }
}
