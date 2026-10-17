<?php

namespace App\Filament\Resources\AppReleases;

use App\Filament\Resources\AppReleases\Pages\ListAppReleases;
use App\Filament\Resources\AppReleases\Tables\AppReleasesTable;
use App\Models\AppRelease;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Daftar APK yang sedang didistribusikan (satu per app+channel). Upload hanya
 * lewat API ber-chunk (skrip rilis), jadi resource ini tanpa form create/edit.
 */
class AppReleaseResource extends Resource
{
    protected static ?string $model = AppRelease::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'App Management';

    protected static ?string $navigationLabel = 'APK Releases';

    protected static ?string $modelLabel = 'APK Release';

    protected static ?string $pluralModelLabel = 'APK Releases';

    public static function table(Table $table): Table
    {
        return AppReleasesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppReleases::route('/'),
        ];
    }
}
