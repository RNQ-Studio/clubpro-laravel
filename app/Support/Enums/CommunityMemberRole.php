<?php

namespace App\Support\Enums;

enum CommunityMemberRole: string
{
    case Creator = 'creator';
    case Admin = 'admin';
    case Officer = 'officer';
    case Coach = 'coach';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Creator => 'Pendiri', self::Admin => 'Admin', self::Officer => 'Pengurus',
            self::Coach => 'Pelatih', self::Member => 'Anggota',
        };
    }

    public function canManage(): bool
    {
        return in_array($this, [self::Creator, self::Admin, self::Officer], true);
    }

    public function canManageMembers(): bool
    {
        return in_array($this, [self::Creator, self::Admin], true);
    }
}
