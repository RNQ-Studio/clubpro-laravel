<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Distribusi APK (rilis development)
    |--------------------------------------------------------------------------
    |
    | APK diunggah lewat API ber-chunk (App\Services\AppReleaseService) dan
    | diunduh lewat link stabil /apk/{app}/{channel}. Tiap pasangan app+channel
    | hanya menyimpan SATU file: rilis baru menggantikan file lama, yang lalu
    | dihapus dari storage.
    |
    */

    // Disk tujuan file final. 'local' = storage/app/private di server.
    'disk' => env('APP_RELEASE_DISK', 'local'),

    'directory' => 'app-releases',

    // Potongan upload yang belum selesai disimpan di disk lokal server.
    'upload_directory' => 'app-release-uploads',

    // Sesi upload yang ditinggalkan lebih lama dari ini dibersihkan otomatis.
    'upload_ttl_minutes' => 360,

    'max_size_mb' => (int) env('APP_RELEASE_MAX_SIZE_MB', 300),

    // Batas atas ukuran satu chunk. Server mengiklankan nilai terkecil antara ini
    // dan post_max_size PHP; klien boleh memakai chunk lebih kecil (mis. bila nginx
    // membalas 413).
    'chunk_max_kb' => 8192,

    // Slug app di URL => nama aplikasi pada nama file unduhan.
    'apps' => [
        'main' => 'CEO',
        'variant' => 'ClubPro Variant',
    ],

    'channels' => ['dev', 'staging'],

    // Zona waktu stempel "kapan diunggah" pada nama file unduhan.
    'timezone' => 'Asia/Jakarta',

];
