<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Asset;
use App\Services\AssetDeletionService;
use App\Services\AssetUploadService;
use App\Services\FileUploadService;
use App\Support\Enums\BloodType;
use App\Support\Enums\Gender;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('avatar')
                    ->image()
                    ->imageEditor()
                    ->maxSize(2048)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->nullable()
                    ->fetchFileInformation(false)
                    ->getUploadedFileUsing(function ($file) {
                        if (! $file) {
                            return null;
                        }
                        if (Str::isUuid($file)) {
                            $asset = Asset::find($file);
                            if ($asset) {
                                return [
                                    'name' => $asset->original_filename,
                                    'size' => $asset->size,
                                    'type' => $asset->mime_type,
                                    'url' => $asset->getPublicUrl(),
                                ];
                            }
                        }
                        // Legacy fallback
                        $disk = Storage::disk(config('filesystems.default', 'public'));
                        if ($disk->exists($file)) {
                            return [
                                'name' => basename($file),
                                'size' => $disk->size($file),
                                'type' => $disk->mimeType($file),
                                'url' => $disk->url($file),
                            ];
                        }

                        return null;
                    })
                    ->saveUploadedFileUsing(function (TemporaryUploadedFile $file, $record) {
                        $asset = app(AssetUploadService::class)->upload(
                            file: $file,
                            type: 'avatar',
                            userId: $record?->getKey(),
                        );

                        if ($record && $record->avatar) {
                            $oldAvatar = $record->avatar;
                            if (Str::isUuid($oldAvatar)) {
                                $oldAsset = Asset::find($oldAvatar);
                                if ($oldAsset) {
                                    app(AssetDeletionService::class)->hardDelete($oldAsset);
                                }
                            } else {
                                app(FileUploadService::class)->delete($oldAvatar);
                            }
                        }

                        return $asset->id;
                    })
                    ->deleteUploadedFileUsing(function ($state) {
                        if (! $state) {
                            return;
                        }
                        if (Str::isUuid($state)) {
                            $asset = Asset::find($state);
                            if ($asset) {
                                app(AssetDeletionService::class)->hardDelete($asset);
                            }
                        } else {
                            app(FileUploadService::class)->delete($state);
                        }
                    }),

                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('Leave blank to keep the current password.')
                    ->maxLength(255),
                Select::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable(),
                Toggle::make('is_active')
                    ->default(true),

                // Diisi anggota dari app (halaman Profil); admin bisa mengoreksi di sini.
                Section::make('Data Anggota')
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Select::make('gender')
                            ->label('Jenis kelamin')
                            ->options(collect(Gender::cases())->mapWithKeys(fn (Gender $g): array => [$g->value => $g->label()])),
                        TextInput::make('phone')
                            ->label('Nomor HP / WhatsApp')
                            ->tel()
                            ->unique(ignoreRecord: true)
                            ->maxLength(20),
                        TextInput::make('birth_place')
                            ->label('Tempat lahir')
                            ->maxLength(100),
                        DatePicker::make('birth_date')
                            ->label('Tanggal lahir')
                            ->native(false)
                            ->maxDate(now()),
                        Textarea::make('address')
                            ->label('Alamat')
                            ->rows(2)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                        Select::make('blood_type')
                            ->label('Golongan darah')
                            ->options(collect(BloodType::cases())->mapWithKeys(fn (BloodType $b): array => [$b->value => $b->value])),
                        TextInput::make('emergency_contact_name')
                            ->label('Nama kontak darurat')
                            ->maxLength(100),
                        TextInput::make('emergency_contact_phone')
                            ->label('Nomor kontak darurat')
                            ->tel()
                            ->maxLength(30),
                    ]),
            ]);
    }
}
