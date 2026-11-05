<?php

namespace App\Support\Enums;

enum CommunityMemberRole: string
{
    case Creator = 'creator';
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Creator => 'Creator',
            self::Admin => 'Admin',
            self::Member => 'Anggota',
        };
    }

    /** Boleh mengelola komunitas. */
    public function canManage(): bool
    {
        return $this !== self::Member;
    }
}
