<?php

namespace App\Filament\Resources\AppReleases\Pages;

use App\Filament\Resources\AppReleases\AppReleaseResource;
use Filament\Resources\Pages\ListRecords;

class ListAppReleases extends ListRecords
{
    protected static string $resource = AppReleaseResource::class;
}
