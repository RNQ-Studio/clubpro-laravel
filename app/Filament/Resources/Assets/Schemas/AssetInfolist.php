<?php

namespace App\Filament\Resources\Assets\Schemas;

use App\Models\Asset;
use App\Support\Enums\AssetStatus;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Throwable;

class AssetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Preview')
                ->schema([
                    ImageEntry::make('preview')
                        ->hiddenLabel()
                        ->state(fn (Asset $record): ?string => self::previewUrl($record)),
                ])
                // Hanya tampilkan blok preview bila file adalah gambar.
                ->visible(fn (Asset $record): bool => str_starts_with($record->mime_type, 'image/')),

            Section::make('Informasi File')
                ->columns(2)
                ->schema([
                    TextEntry::make('original_filename')->label('Nama File'),
                    TextEntry::make('extension')->label('Ekstensi')->placeholder('—'),
                    TextEntry::make('mime_type')->label('MIME Type')->badge(),
                    TextEntry::make('size')
                        ->label('Ukuran')
                        ->formatStateUsing(fn (int $state): string => Number::fileSize($state, precision: 2)),
                    TextEntry::make('category')->label('Kategori')->placeholder('—'),
                    TextEntry::make('checksum')->label('Checksum (SHA-256)')->placeholder('—')->copyable(),
                ]),

            Section::make('Storage & Lifecycle')
                ->columns(2)
                ->schema([
                    TextEntry::make('storage_type')->label('Storage')->badge(),
                    TextEntry::make('path')->label('Path')->copyable(),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->color(fn (Asset $record): string => match ($record->status) {
                            AssetStatus::Active => 'success',
                            AssetStatus::SoftDeleted => 'warning',
                            AssetStatus::HardDeleted => 'danger',
                        }),
                    TextEntry::make('is_protected')
                        ->label('Dilindungi')
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Ya' : 'Tidak'),
                    TextEntry::make('retain_until')->label('Retensi Hingga')->dateTime()->placeholder('Permanen'),
                    TextEntry::make('scheduled_hard_delete_at')->label('Jadwal Hapus Permanen')->dateTime()->placeholder('—'),
                ]),

            Section::make('Metadata & Audit')
                ->columns(2)
                ->schema([
                    TextEntry::make('creator.name')->label('Uploader')->placeholder('—'),
                    TextEntry::make('created_at')->label('Diupload')->dateTime(),
                    TextEntry::make('updated_at')->label('Diperbarui')->dateTime(),
                    TextEntry::make('deleted_at')->label('Dihapus (trash)')->dateTime()->placeholder('—'),
                    TextEntry::make('hard_deleted_at')->label('Dihapus Permanen')->dateTime()->placeholder('—'),
                ]),
        ]);
    }

    /** URL preview gambar: GCS pakai signed URL, local pakai public URL. */
    protected static function previewUrl(Asset $record): ?string
    {
        try {
            $disk = Storage::disk($record->storage_type->disk());

            if (! $disk->exists($record->path)) {
                return null;
            }

            if ($record->isSensitive()) {
                return URL::temporarySignedRoute('assets.file', now()->addMinutes(5), ['asset' => $record->id]);
            }

            return $record->isGCS()
                ? $disk->temporaryUrl($record->path, now()->addMinutes(5))
                : $disk->url($record->path);
        } catch (Throwable) {
            return null;
        }
    }
}
