<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;
use RuntimeException;
use Throwable;

class GeoIpUpdateCommand extends Command
{
    protected $signature = 'geoip:update {--force : Unduh ulang walau file bulan ini sudah ada}';

    protected $description = 'Unduh database lokasi IP (DB-IP Lite, terbit bulanan)';

    private const URL = 'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz';

    public function handle(): int
    {
        $target = (string) config('services.geoip.database');

        if (! $this->option('force') && is_file($target) && filemtime($target) >= now()->startOfMonth()->getTimestamp()) {
            $this->info('Database lokasi IP sudah terbaru.');

            return self::SUCCESS;
        }

        File::ensureDirectoryExists(dirname($target));

        // Di awal bulan rilis terbaru bisa belum terbit: pakai bulan sebelumnya.
        foreach ([now(), now()->subMonthNoOverflow()] as $month) {
            if ($this->download($month, $target)) {
                $this->info("Database lokasi IP {$month->format('Y-m')} terpasang.");

                return self::SUCCESS;
            }
        }

        $this->error('Gagal mengunduh database lokasi IP; file lama tidak berubah.');

        return self::FAILURE;
    }

    private function download(Carbon $month, string $target): bool
    {
        $gzip = "{$target}.gz.tmp";
        $mmdb = "{$target}.tmp";

        try {
            $response = Http::timeout(600)->sink($gzip)->get(sprintf(self::URL, $month->format('Y-m')));

            if (! $response->successful()) {
                return false;
            }

            $this->gunzip($gzip, $mmdb);
            (new Reader($mmdb))->close(); // File rusak melempar di sini, sebelum menimpa yang lama.

            return File::move($mmdb, $target);
        } catch (Throwable $e) {
            report($e);

            return false;
        } finally {
            File::delete([$gzip, $mmdb]);
        }
    }

    private function gunzip(string $source, string $destination): void
    {
        $in = gzopen($source, 'rb');
        $out = fopen($destination, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('Tidak bisa membuka file database lokasi IP.');
        }

        try {
            while (! gzeof($in)) {
                fwrite($out, (string) gzread($in, 1 << 20));
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
    }
}
