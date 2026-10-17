<?php

namespace App\Filament\Forms;

use App\Models\Asset;
use App\Services\AssetDeletionService;
use App\Services\AssetUploadService;
use App\Services\FileUploadService;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * FileUpload gambar yang disimpan lewat sistem asset terpusat (kolom berisi UUID
 * asset), dengan pembersihan asset lama saat diganti/dihapus. Pola yang sama
 * dengan avatar di UserForm.
 */
final class AssetImageUpload
{
    public static function make(string $field, string $assetType, int $maxSizeKb = 4096): FileUpload
    {
        return FileUpload::make($field)
            ->image()
            ->imageEditor()
            ->maxSize($maxSizeKb)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->nullable()
            ->fetchFileInformation(false)
            ->getUploadedFileUsing(function (?string $file): ?array {
                if (! $file) {
                    return null;
                }
                if (Str::isUuid($file)) {
                    $asset = Asset::find($file);

                    return $asset ? [
                        'name' => $asset->original_filename,
                        'size' => $asset->size,
                        'type' => $asset->mime_type,
                        'url' => $asset->getPublicUrl(),
                    ] : null;
                }
                $disk = Storage::disk(config('filesystems.default', 'public'));

                return $disk->exists($file) ? [
                    'name' => basename($file),
                    'size' => $disk->size($file),
                    'type' => $disk->mimeType($file),
                    'url' => $disk->url($file),
                ] : null;
            })
            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file, ?Model $record) use ($field, $assetType): string {
                $asset = app(AssetUploadService::class)->upload(
                    file: $file,
                    type: $assetType,
                    userId: auth()->id(),
                );

                $old = $record?->getAttribute($field);
                if (is_string($old) && $old !== '') {
                    self::deleteStored($old);
                }

                return (string) $asset->id;
            })
            ->deleteUploadedFileUsing(function (?string $state): void {
                if ($state) {
                    self::deleteStored($state);
                }
            });
    }

    private static function deleteStored(string $stored): void
    {
        if (Str::isUuid($stored)) {
            $asset = Asset::find($stored);
            if ($asset) {
                app(AssetDeletionService::class)->hardDelete($asset);
            }

            return;
        }

        app(FileUploadService::class)->delete($stored);
    }
}
