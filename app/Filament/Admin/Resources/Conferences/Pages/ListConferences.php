<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Conferences\Pages;

use App\Filament\Admin\Resources\Conferences\ConferenceResource;
use Filament\Resources\Pages\ListRecords;

class ListConferences extends ListRecords
{
    protected static string $resource = ConferenceResource::class;
}
