<?php

namespace App\Models;

use App\Support\Enums\Gender;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $merged_into_id
 * @property string $name
 * @property Carbon|null $birth_date
 * @property Gender|null $gender
 * @property bool $is_public
 * @property int|null $created_by
 * @property-read User|null $user
 * @property-read Collection<int, CommunityMember> $memberships
 * @property-read Collection<int, GuardianGrant> $guardianGrants
 */
class MemberIdentity extends Model
{
    protected $fillable = ['user_id', 'name', 'birth_date', 'gender', 'is_public', 'created_by', 'merged_into_id'];

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'gender' => Gender::class, 'is_public' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CommunityMember, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(CommunityMember::class);
    }

    /** @return HasMany<GuardianGrant, $this> */
    public function guardianGrants(): HasMany
    {
        return $this->hasMany(GuardianGrant::class);
    }

    public static function forUser(User $user): self
    {
        return self::query()->firstOrCreate(['user_id' => $user->getKey()], [
            'name' => $user->name, 'birth_date' => $user->birth_date,
            'gender' => $user->gender, 'is_public' => $user->birth_date === null || $user->birth_date->age >= 18,
        ]);
    }
}
