<?php

namespace App\Support\Enums;

/** Jenis busur yang dipakai anggota (divisi umum lomba panahan). */
enum BowType: string
{
    case Recurve = 'recurve';
    case Compound = 'compound';
    case Barebow = 'barebow';
    case Traditional = 'traditional';

    public function label(): string
    {
        return match ($this) {
            self::Recurve => 'Recurve',
            self::Compound => 'Compound',
            self::Barebow => 'Barebow',
            self::Traditional => 'Tradisional / Horsebow',
        };
    }
}
