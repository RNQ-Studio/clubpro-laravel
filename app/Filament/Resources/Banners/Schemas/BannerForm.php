<?php

namespace App\Filament\Resources\Banners\Schemas;

use App\Filament\Forms\AssetImageUpload;
use App\Rules\BannerLink;
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
                        ->label('Judul (opsional)')
                        ->maxLength(120)
                        ->dehydrateStateUsing(fn (?string $state): string => $state ?? '')
                        ->helperText('Kosongkan bila fotonya sudah bertulisan.'),
                    TextInput::make('subtitle')
                        ->label('Keterangan')
                        ->maxLength(255),
                    AssetImageUpload::make('image', 'banner')
                        ->label('Gambar')
                        ->helperText('Rasio 2:1 disarankan (mis. 1600×800). Tanpa gambar, app menampilkan judul di atas latar warna.'),
                    TextInput::make('link_url')
                        ->label('Tautan (opsional)')
                        ->rule(new BannerLink)
                        ->maxLength(2048)
                        ->helperText('Dibuka saat banner diketuk: tautan https, atau halaman app seperti /communities.'),
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
