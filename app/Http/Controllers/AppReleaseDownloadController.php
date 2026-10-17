<?php

namespace App\Http\Controllers;

use App\Services\AppReleaseService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppReleaseDownloadController extends Controller
{
    /**
     * Link unduhan stabil (/apk/{app}/{channel}) yang selalu menyajikan APK
     * terbaru, dengan nama file berisi nama aplikasi, versi, dan waktu unggah.
     */
    public function __invoke(AppReleaseService $releases, string $app, string $channel = 'dev'): StreamedResponse
    {
        $release = $releases->latest($app, $channel);
        if ($release === null) {
            abort(404);
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($release->file_disk);
        if (! $disk->exists($release->file_path)) {
            abort(404);
        }

        return $disk->download($release->file_path, $release->download_filename, [
            'Content-Type' => 'application/vnd.android.package-archive',
            // Link tetap sama antar rilis; jangan biarkan browser/proxy menyajikan APK lama.
            'Cache-Control' => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
