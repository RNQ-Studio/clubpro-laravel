<?php

namespace App\Services;

use App\Models\AppRelease;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

/**
 * Distribusi APK lewat upload ber-chunk.
 *
 * Alur: startUpload → appendChunk (berulang, bisa dilanjutkan) → completeUpload.
 * Potongan ditampung di disk 'local' server; setelah lengkap dan checksum cocok,
 * file dipindah ke disk rilis dan baris app_releases untuk app+channel diperbarui.
 * File rilis sebelumnya lalu dihapus, sehingga storage hanya memuat APK terbaru.
 *
 * Chunk dipakai (bukan satu multipart besar) karena batas body nginx/PHP di
 * server produksi tidak bisa diandalkan untuk APK puluhan MB.
 */
class AppReleaseService
{
    private const SESSION_FILE = 'session.json';

    private const DATA_FILE = 'data.part';

    private const LOCK_FILE = '.lock';

    public function latest(string $app, string $channel): ?AppRelease
    {
        return AppRelease::query()
            ->where('app', $app)
            ->where('channel', $channel)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data  Data tervalidasi dari StartAppReleaseUploadRequest.
     * @return array<string, mixed>
     */
    public function startUpload(array $data, User $user): array
    {
        $this->pruneStaleUploads();

        // Gagal cepat sebelum klien mengirim puluhan MB; dicek ulang saat complete.
        $this->assertReplaceable($this->latest($data['app'], $data['channel']), $data);

        $id = (string) Str::uuid();
        $now = now();
        $session = [
            'id' => $id,
            'user_id' => $user->getKey(),
            'app' => $data['app'],
            'channel' => $data['channel'],
            'version_name' => $data['version_name'],
            'version_code' => (int) $data['version_code'],
            'file_size' => (int) $data['file_size'],
            'file_sha256' => strtolower($data['file_sha256']),
            'signing_sha256' => isset($data['signing_sha256']) ? strtolower($data['signing_sha256']) : null,
            'git_sha' => $data['git_sha'] ?? null,
            'release_notes' => $data['release_notes'] ?? null,
            'allow_signing_change' => (bool) ($data['allow_signing_change'] ?? false),
            'created_at' => $now->toIso8601String(),
            'expires_at' => $now->copy()->addMinutes($this->ttlMinutes())->toIso8601String(),
        ];

        $disk = $this->uploadDisk();
        $dir = $this->sessionDirectory($id);
        $disk->makeDirectory($dir);
        $disk->put($dir.'/'.self::DATA_FILE, '');
        $disk->put($dir.'/'.self::SESSION_FILE, (string) json_encode($session, JSON_PRETTY_PRINT));

        return $this->sessionStatus($session, 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadStatus(string $uploadId, User $user): array
    {
        $session = $this->loadSession($uploadId, $user);

        return $this->sessionStatus($session, $this->receivedBytes($uploadId));
    }

    /**
     * Tambahkan satu chunk pada posisi $offset. Idempoten: chunk yang sudah
     * diterima (retry setelah timeout) diterima ulang tanpa menulis apa pun.
     *
     * @return array<string, mixed>
     */
    public function appendChunk(string $uploadId, User $user, int $offset, string $bytes): array
    {
        $session = $this->loadSession($uploadId, $user);
        $length = strlen($bytes);

        if ($length === 0) {
            throw new BadRequestHttpException('Empty chunk.');
        }

        if ($length > $this->maxChunkBytes()) {
            throw new BadRequestHttpException('Chunk larger than '.$this->maxChunkBytes().' bytes.');
        }

        return $this->withSessionLock($uploadId, function () use ($uploadId, $session, $offset, $bytes, $length): array {
            $partPath = $this->sessionPath($uploadId, self::DATA_FILE);
            $received = $this->receivedBytes($uploadId);

            $alreadyReceived = $offset < $received && $offset + $length <= $received;

            if (! $alreadyReceived) {
                if ($offset !== $received) {
                    throw new ConflictHttpException("Offset mismatch: server has {$received} bytes, chunk starts at {$offset}.");
                }

                if ($received + $length > $session['file_size']) {
                    throw new UnprocessableEntityHttpException('Chunk exceeds the declared file_size.');
                }

                if (file_put_contents($partPath, $bytes, FILE_APPEND) !== $length) {
                    throw new RuntimeException('Failed to write upload chunk.');
                }

                $received += $length;
            }

            return $this->sessionStatus($session, $received);
        });
    }

    public function completeUpload(string $uploadId, User $user): AppRelease
    {
        $session = $this->loadSession($uploadId, $user);

        return $this->withSessionLock($uploadId, function () use ($uploadId, $session, $user): AppRelease {
            $partPath = $this->sessionPath($uploadId, self::DATA_FILE);
            $received = $this->receivedBytes($uploadId);

            if ($received !== $session['file_size']) {
                throw new UnprocessableEntityHttpException("Upload incomplete: {$received} of {$session['file_size']} bytes received.");
            }

            if (hash_file('sha256', $partPath) !== $session['file_sha256']) {
                // Data rusak tidak bisa diperbaiki dengan melanjutkan — klien harus mulai ulang.
                $this->deleteSession($uploadId);

                throw new UnprocessableEntityHttpException('Checksum mismatch: uploaded data does not match file_sha256. Start a new upload.');
            }

            $handle = fopen($partPath, 'rb');
            $magic = $handle === false ? '' : (string) fread($handle, 4);
            if ($handle !== false) {
                fclose($handle);
            }
            if ($magic !== "PK\x03\x04") {
                $this->deleteSession($uploadId);

                throw new UnprocessableEntityHttpException('File is not a valid APK (ZIP) archive.');
            }

            $release = Cache::lock("app-release:{$session['app']}:{$session['channel']}", 300)
                ->block(30, fn (): AppRelease => $this->publish($session, $partPath, $user));

            $this->deleteSession($uploadId);

            return $release;
        });
    }

    public function abortUpload(string $uploadId, User $user): void
    {
        $this->loadSession($uploadId, $user);
        $this->deleteSession($uploadId);
    }

    /**
     * Hapus sesi upload yang sudah kedaluwarsa (klien mati di tengah jalan).
     */
    public function pruneStaleUploads(): int
    {
        $disk = $this->uploadDisk();
        $root = (string) config('app_release.upload_directory');
        $pruned = 0;

        foreach ($disk->directories($root) as $dir) {
            $raw = $disk->get($dir.'/'.self::SESSION_FILE);
            $session = is_string($raw) ? json_decode($raw, true) : null;

            $expired = is_array($session) && isset($session['expires_at'])
                ? Carbon::parse($session['expires_at'])->isPast()
                : (int) @filemtime($disk->path($dir)) < now()->subMinutes($this->ttlMinutes())->getTimestamp();

            if ($expired) {
                $disk->deleteDirectory($dir);
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * Ukuran chunk terbesar yang diterima: batas konfigurasi, dipangkas oleh
     * post_max_size PHP (dikurangi ruang untuk header).
     */
    public function maxChunkBytes(): int
    {
        $cap = (int) config('app_release.chunk_max_kb') * 1024;
        $postMax = self::iniBytes((string) ini_get('post_max_size'));

        if ($postMax > 0) {
            $cap = min($cap, $postMax - 64 * 1024);
        }

        return max(64 * 1024, $cap);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function publish(array $session, string $partPath, User $user): AppRelease
    {
        $current = $this->latest($session['app'], $session['channel']);
        $this->assertReplaceable($current, $session);

        $diskName = (string) config('app_release.disk');
        $disk = Storage::disk($diskName);
        $directory = sprintf('%s/%s/%s', config('app_release.directory'), $session['app'], $session['channel']);
        $path = $directory.'/'.Str::uuid().'.apk';

        // Nama file di storage unik per rilis supaya link unduhan tidak pernah
        // menyajikan APK baru dengan metadata lama di tengah pergantian.
        if ($disk->putFileAs($directory, new File($partPath), basename($path)) === false) {
            throw new RuntimeException('Failed to store the APK.');
        }

        try {
            /** @var AppRelease $release */
            $release = DB::transaction(fn (): AppRelease => AppRelease::query()->updateOrCreate(
                ['app' => $session['app'], 'channel' => $session['channel']],
                [
                    'version_name' => $session['version_name'],
                    'version_code' => $session['version_code'],
                    'file_disk' => $diskName,
                    'file_path' => $path,
                    'file_size' => $session['file_size'],
                    'file_sha256' => $session['file_sha256'],
                    'signing_sha256' => $session['signing_sha256'],
                    'git_sha' => $session['git_sha'],
                    'release_notes' => $session['release_notes'],
                    'uploaded_by' => $user->getKey(),
                    'uploaded_at' => now(),
                ],
            ));
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        // APK lama (dan sisa apa pun di folder ini) dihapus: hanya rilis terbaru yang disimpan.
        foreach ($disk->files($directory) as $file) {
            if ($file !== $path) {
                $disk->delete($file);
            }
        }
        if ($current !== null && $current->file_disk !== $diskName) {
            Storage::disk($current->file_disk)->delete($current->file_path);
        }

        return $release;
    }

    /**
     * @param  array<string, mixed>  $incoming
     */
    private function assertReplaceable(?AppRelease $current, array $incoming): void
    {
        if ($current === null) {
            return;
        }

        if ((int) $incoming['version_code'] <= $current->version_code) {
            throw new ConflictHttpException(sprintf(
                'version_code must be greater than the current %s/%s release (%d).',
                $current->app,
                $current->channel,
                $current->version_code,
            ));
        }

        $signing = isset($incoming['signing_sha256']) ? strtolower((string) $incoming['signing_sha256']) : null;
        $allowChange = (bool) ($incoming['allow_signing_change'] ?? false);

        // Sertifikat berbeda = penguji tidak bisa memperbarui APK yang terpasang
        // (INSTALL_FAILED_UPDATE_INCOMPATIBLE); harus disengaja.
        if ($signing !== null && $current->signing_sha256 !== null && $signing !== $current->signing_sha256 && ! $allowChange) {
            throw new ConflictHttpException('APK signing certificate differs from the current release; installed copies could not be updated in place. Set allow_signing_change to proceed.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSession(string $uploadId, User $user): array
    {
        if (! Str::isUuid($uploadId)) {
            throw new NotFoundHttpException('Upload session not found.');
        }

        $raw = $this->uploadDisk()->get($this->sessionDirectory($uploadId).'/'.self::SESSION_FILE);
        $session = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($session) || (int) $session['user_id'] !== (int) $user->getKey()) {
            throw new NotFoundHttpException('Upload session not found.');
        }

        if (Carbon::parse($session['expires_at'])->isPast()) {
            $this->deleteSession($uploadId);

            throw new NotFoundHttpException('Upload session expired.');
        }

        return $session;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withSessionLock(string $uploadId, callable $callback): mixed
    {
        $handle = fopen($this->sessionPath($uploadId, self::LOCK_FILE), 'c');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock the upload session.');
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function sessionStatus(array $session, int $received): array
    {
        return [
            'upload_id' => $session['id'],
            'app' => $session['app'],
            'channel' => $session['channel'],
            'version_name' => $session['version_name'],
            'version_code' => $session['version_code'],
            'file_size' => $session['file_size'],
            'received_bytes' => $received,
            'max_chunk_bytes' => $this->maxChunkBytes(),
            'expires_at' => $session['expires_at'],
        ];
    }

    private function receivedBytes(string $uploadId): int
    {
        clearstatcache(true, $this->sessionPath($uploadId, self::DATA_FILE));

        return (int) @filesize($this->sessionPath($uploadId, self::DATA_FILE));
    }

    private function deleteSession(string $uploadId): void
    {
        $this->uploadDisk()->deleteDirectory($this->sessionDirectory($uploadId));
    }

    private function sessionDirectory(string $uploadId): string
    {
        return config('app_release.upload_directory').'/'.$uploadId;
    }

    private function sessionPath(string $uploadId, string $file): string
    {
        return $this->uploadDisk()->path($this->sessionDirectory($uploadId).'/'.$file);
    }

    private function uploadDisk(): FilesystemAdapter
    {
        // Selalu disk lokal server: chunk ditambahkan langsung ke berkas (append + flock).
        /** @var FilesystemAdapter */
        return Storage::disk('local');
    }

    private function ttlMinutes(): int
    {
        return (int) config('app_release.upload_ttl_minutes', 360);
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
