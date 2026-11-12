<?php

namespace App\Support\Enums;

/** Status masa berlaku kartu anggota menurut iuran bulanan yang lunas. */
enum MemberCardStatus: string
{
    /** Hari ini masih dalam masa berlaku. */
    case Active = 'active';

    /** Masa berlaku lewat: iuran bulan berikutnya menunggak. */
    case Expired = 'expired';

    /** Belum ada bulan iuran yang lunas. */
    case Inactive = 'inactive';

    /** Komunitas belum mengatur iuran: berlaku selama menjadi anggota. */
    case NoDues = 'no_dues';
}
