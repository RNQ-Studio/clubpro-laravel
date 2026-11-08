<?php

namespace App\Models;

use App\Notifications\VerifyEmailNotification;
use App\Services\FileUploadService;
use App\Support\Enums\BloodType;
use App\Support\Enums\Gender;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $avatar
 * @property string|null $google_id
 * @property string|null $google_avatar_url
 * @property Gender|null $gender
 * @property string|null $birth_place
 * @property Carbon|null $birth_date
 * @property string|null $address
 * @property BloodType|null $blood_type
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property string|null $domicile_province_code
 * @property string|null $domicile_province_name
 * @property string|null $domicile_city_code
 * @property string|null $domicile_city_name
 * @property string|null $nik_province_code
 * @property string|null $nik_province_name
 * @property string|null $nik_city_code
 * @property string|null $nik_city_name
 * @property bool $nik_same_as_domicile
 * @property bool $is_active
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'email', 'password', 'is_active', 'avatar', 'phone', 'phone_verified_at',
    // Data diri anggota
    'gender', 'birth_place', 'birth_date', 'address', 'blood_type',
    'emergency_contact_name', 'emergency_contact_phone',
    'domicile_province_code', 'domicile_province_name', 'domicile_city_code', 'domicile_city_name',
    'nik_province_code', 'nik_province_name', 'nik_city_code', 'nik_city_name', 'nik_same_as_domicile',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail, OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Send the email verification notification.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    protected string $guard_name = 'web';

    /** Roles allowed to access the back-office panel. */
    public const PANEL_ROLES = ['super-admin', 'admin', 'staff'];

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->hasAnyRole(self::PANEL_ROLES);
    }

    /** @return HasMany<UserDevice, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    /** @return HasOne<UserDevice, $this> */
    public function latestDevice(): HasOne
    {
        return $this->hasOne(UserDevice::class)->latestOfMany('last_active_at');
    }

    /** @return HasMany<Notification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function avatarAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'avatar');
    }

    /** Foto unggahan; belum unggah → foto akun Google bila ada. */
    public function avatarUrl(): ?string
    {
        $url = null;
        if ($this->avatar !== null) {
            $url = Str::isUuid($this->avatar)
                ? $this->avatarAsset?->getPublicUrl()
                : app(FileUploadService::class)->url($this->avatar);
        }

        return $url ?? $this->google_avatar_url;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'gender' => Gender::class,
            'birth_date' => 'date',
            'blood_type' => BloodType::class,
            'nik_same_as_domicile' => 'boolean',
        ];
    }

    /**
     * Find the user instance for the given username.
     */
    public function findForPassport(string $username): ?User
    {
        return $this->where('email', $username)
            ->orWhere('phone', $username)
            ->first();
    }

    /**
     * Validate the password for the Passport password grant.
     */
    public function validateForPassportPasswordGrant(#[\SensitiveParameter] string $password): bool
    {
        $cacheKey = 'otp_login_token_'.$this->getKey();
        $cachedToken = cache($cacheKey);

        if ($cachedToken !== null && hash_equals($cachedToken, $password)) {
            cache()->forget($cacheKey);

            return true;
        }

        return Hash::check($password, $this->password);
    }
}
