<?php

namespace App\Services\Geo;

use MaxMind\Db\Reader;

/**
 * Perkiraan lokasi dari alamat IP memakai database lokal (`php artisan geoip:update`),
 * sehingga IP anggota tidak dikirim ke pihak ketiga. Akurasinya level kota dan bisa
 * meleset, terutama di jaringan seluler.
 */
class IpLocationService
{
    public function __construct(private readonly string $databasePath) {}

    /**
     * "Bandung, West Java, Indonesia"; null bila IP privat, tidak ada di database,
     * atau database belum diunduh.
     */
    public function locate(?string $ip): ?string
    {
        if ($ip === null || ! is_file($this->databasePath) || ! $this->isPublic($ip)) {
            return null;
        }

        $reader = new Reader($this->databasePath);

        try {
            $record = $reader->get($ip);
        } finally {
            $reader->close();
        }

        if (! is_array($record)) {
            return null;
        }

        $parts = array_unique(array_filter([
            $record['city']['names']['en'] ?? null,
            $record['subdivisions'][0]['names']['en'] ?? null,
            $record['country']['names']['en'] ?? null,
        ]));

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
