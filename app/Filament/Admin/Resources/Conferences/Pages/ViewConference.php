<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Conferences\Pages;

use App\Filament\Admin\Resources\Conferences\ConferenceResource;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;

class ViewConference extends ViewRecord
{
    protected static string $resource = ConferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RestoreAction::make(),
        ];
    }
}
