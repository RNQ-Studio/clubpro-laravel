<?php

namespace App\Filament\Resources\AppReleases\Tables;

use App\Models\AppRelease;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class AppReleasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('app_name')
                    ->label('Aplikasi')
                    ->state(fn (AppRelease $record): string => $record->app_name),
                TextColumn::make('channel')
                    ->label('Channel')
                    ->badge()
                    ->sortable(),
                TextColumn::make('version_name')
                    ->label('Versi')
                    ->formatStateUsing(fn (AppRelease $record): string => "{$record->version_name} (build {$record->version_code})"),
                TextColumn::make('file_size')
                    ->label('Ukuran')
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state, precision: 1)),
                TextColumn::make('download_url')
                    ->label('Link Unduh')
                    ->state(fn (AppRelease $record): string => self::downloadUrl($record))
                    ->copyable()
                    ->copyMessage('Link disalin')
                    ->limit(40),
                TextColumn::make('uploader.name')
                    ->label('Pengunggah')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('uploaded_at')
                    ->label('Diunggah')
                    ->dateTime('d M Y H:i', timezone: (string) config('app_release.timezone'))
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Unduh')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (AppRelease $record): string => self::downloadUrl($record)),
                // Menghapus baris juga menghapus file APK-nya (AppRelease::booted).
                DeleteAction::make()
                    ->modalDescription('APK akan dihapus dari server dan link unduhannya berhenti bekerja sampai rilis berikutnya.'),
            ])
            ->defaultSort('uploaded_at', 'desc');
    }

    private static function downloadUrl(AppRelease $record): string
    {
        return route('app-releases.download', ['app' => $record->app, 'channel' => $record->channel]);
    }
}
