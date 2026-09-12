<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Conferences\Pages;

use App\Filament\Admin\Resources\Conferences\ConferenceResource;
use App\Filament\Admin\Resources\Conferences\Tables\ConferencesTable;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;

class ViewConference extends ViewRecord
{
    protected static string $resource = ConferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RestoreAction::make(),
            // One object serves the row and this header, the
            // OrganizationsTable::approveAction() idiom, so the confirmation
            // and the count preview cannot drift between the two places an
            // admin can reach the purge from.
            ConferencesTable::purgeAction(),
        ];
    }
}
