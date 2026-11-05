<?php

namespace App\Support\Enums;

enum CommunityType: string
{
    case Panahan = 'panahan';
    case SepakBola = 'sepak_bola';
    case Futsal = 'futsal';
    case BuluTangkis = 'bulu_tangkis';
    case Basket = 'basket';
    case Voli = 'voli';
    case Tenis = 'tenis';
    case Lari = 'lari';
    case Bersepeda = 'bersepeda';
    case BelaDiri = 'bela_diri';
    case PecintaAlam = 'pecinta_alam';
    case Fashion = 'fashion';
    case Fotografi = 'fotografi';
    case Otomotif = 'otomotif';
    case Kuliner = 'kuliner';
    case Musik = 'musik';
    case SeniBudaya = 'seni_budaya';
    case Gaming = 'gaming';
    case Teknologi = 'teknologi';
    case BisnisUmkm = 'bisnis_umkm';
    case Pendidikan = 'pendidikan';
    case Sosial = 'sosial';
    case Keagamaan = 'keagamaan';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Panahan => 'Panahan',
            self::SepakBola => 'Sepak Bola',
            self::Futsal => 'Futsal',
            self::BuluTangkis => 'Bulu Tangkis',
            self::Basket => 'Basket',
            self::Voli => 'Voli',
            self::Tenis => 'Tenis',
            self::Lari => 'Lari',
            self::Bersepeda => 'Bersepeda',
            self::BelaDiri => 'Bela Diri',
            self::PecintaAlam => 'Pecinta Alam',
            self::Fashion => 'Fashion',
            self::Fotografi => 'Fotografi',
            self::Otomotif => 'Otomotif',
            self::Kuliner => 'Kuliner',
            self::Musik => 'Musik',
            self::SeniBudaya => 'Seni & Budaya',
            self::Gaming => 'Gaming & E-Sport',
            self::Teknologi => 'Teknologi',
            self::BisnisUmkm => 'Bisnis & UMKM',
            self::Pendidikan => 'Pendidikan',
            self::Sosial => 'Sosial & Relawan',
            self::Keagamaan => 'Keagamaan',
            self::Lainnya => 'Lainnya',
        };
    }
}
