<?php

namespace App\Support\Enums;

enum StorageType: string
{
    case Local = 'local';
    case Gcs = 'gcs';
    case Private = 'private';

    /** Nama disk Laravel (config/filesystems.php) untuk tiap storage type. */
    public function disk(): string
    {
        return match ($this) {
            self::Local => 'public',
            self::Gcs => 'gcs',
            self::Private => 'local',
        };
    }
}
