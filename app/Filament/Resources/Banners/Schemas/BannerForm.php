<?php

namespace App\Filament\Resources\Banners\Schemas;

use App\Filament\Forms\AssetImageUpload;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BannerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Konten')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('title')
                        ->label('Judul')
                        ->required()
                        ->maxLength(120),
                    TextInput::make('subtitle')
                        ->label('Keterangan')
                        ->maxLength(255),
                    AssetImageUpload::make('image', 'banner')
                        ->label('Gambar')
                        ->helperText('Rasio 16:9 disarankan (mis. 1280×720). Tanpa gambar, app menampilkan judul di atas latar warna.'),
                    TextInput::make('link_url')
                        ->label('Tautan (opsional)')
                        ->url()
                        ->maxLength(2048)
                        ->helperText('Dibuka saat banner diketuk.'),
                ]),
            Section::make('Penayangan')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true),
                    TextInput::make('sort_order')
                        ->label('Urutan')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->helperText('Angka kecil tampil lebih dulu.'),
                    DateTimePicker::make('starts_at')
                        ->label('Mulai tayang')
                        ->native(false),
                    DateTimePicker::make('ends_at')
                        ->label('Selesai tayang')
                        ->native(false)
                        ->afterOrEqual('starts_at'),
                ]),
        ]);
    }
}
