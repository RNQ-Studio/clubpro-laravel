<?php

namespace App\Support\Enums;

/**
 * Status iuran satu bulan untuk satu anggota. Hanya [Paid] dan [Pending] yang tersimpan;
 * sisanya dihitung dari setelan iuran dan tanggal hari ini.
 */
enum DuesMonthStatus: string
{
    case Paid = 'paid';

    /** Sedang diajukan dalam pembayaran yang menunggu verifikasi. */
    case Pending = 'pending';

    /** Lewat jatuh tempo dan belum lunas. */
    case Overdue = 'overdue';

    /** Bulan berjalan, belum lewat jatuh tempo. */
    case Due = 'due';

    case Upcoming = 'upcoming';

    /** Di luar masa iuran anggota ini (sebelum mulai iuran atau sebelum bergabung). */
    case None = 'none';
}
