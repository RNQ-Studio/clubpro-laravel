<?php

namespace App\Models;

use App\Support\Enums\AppConfigType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property AppConfigType $type
 * @property string|null $description
 */
class AppConfig extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = ['key', 'value', 'type', 'description'];

    protected function casts(): array
    {
        return [
            'type' => AppConfigType::class,
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        // Yang di-cache array skalar, bukan model: cache Laravel 13 tidak meng-unserialize
        // objek (cache.serializable_classes = false), sehingga model yang di-cache kembali
        // sebagai __PHP_Incomplete_Class dan setiap request ber-check.maintenance error 500.
        /** @var array{exists: bool, value?: mixed} $cached */
        $cached = Cache::remember(self::cacheKey($key), now()->addHour(), function () use ($key): array {
            $config = static::query()->where('key', $key)->first();

            return $config === null ? ['exists' => false] : ['exists' => true, 'value' => $config->castValue()];
        });

        if (! $cached['exists']) {
            return $default;
        }

        return $cached['value'] ?? null;
    }

    public static function set(string $key, mixed $value): void
    {
        /** @var AppConfig $config */
        $config = static::query()->firstOrNew(['key' => $key]);
        $config->value = is_array($value) ? json_encode($value) : (string) $value;
        $config->save();

        Cache::forget(self::cacheKey($key));
    }

    public static function allPublic(): array
    {
        return Cache::remember('app_config:all', now()->addHour(), function (): array {
            return static::query()->get()
                ->mapWithKeys(fn (AppConfig $c): array => [$c->key => $c->castValue()])
                ->toArray();
        });
    }

    public static function bustCache(?string $key = null): void
    {
        if ($key !== null) {
            Cache::forget(self::cacheKey($key));
        }
        Cache::forget('app_config:all');
    }

    /** Kunci v2: entri lama berisi model ter-serialize yang tidak bisa dibaca lagi. */
    private static function cacheKey(string $key): string
    {
        return "app_config:v2:{$key}";
    }

    private function castValue(): mixed
    {
        return match ($this->type) {
            AppConfigType::Boolean => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            AppConfigType::Integer => (int) $this->value,
            AppConfigType::Json => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }
}
