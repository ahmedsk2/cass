<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditConference extends EditRecord
{
    protected static string $resource = ConferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
