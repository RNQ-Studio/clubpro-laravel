<?php

namespace App\Services;

use App\Models\Asset;
use App\Support\Enums\AssetStatus;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AssetDeletionService
{
    /**
     * Hapus file dari storage lalu tandai record sebagai hard_deleted (tombstone).
     * Idempoten: bila file sudah tidak ada / record sudah hard_deleted, aman dipanggil ulang.
     * Melempar RuntimeException bila asset dilindungi (legal/compliance hold).
     */
    public function hardDelete(Asset $asset): void
    {
        if ($asset->isProtected()) {
            throw new RuntimeException('Asset dilindungi (is_protected) tidak boleh dihapus.');
        }

        if ($asset->status === AssetStatus::HardDeleted) {
            return;
        }

        $disk = Storage::disk($asset->storage_type->disk());

        // Idempoten: kalau file sudah tidak ada, lanjut tandai saja.
        if ($disk->exists($asset->path)) {
            $disk->delete($asset->path);
        }

        $asset->status = AssetStatus::HardDeleted;
        $asset->hard_deleted_at = now();
        $asset->save();
    }
}
