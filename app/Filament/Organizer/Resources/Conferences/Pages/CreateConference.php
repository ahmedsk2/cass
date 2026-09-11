<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Actions\Conferences\CreateConference as CreateConferenceAction;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateConference extends CreateRecord
{
    protected static string $resource = ConferenceResource::class;

    /**
     * Delegating to the action keeps the slug derivation and the default
     * review form in one place, shared with the console and the Plan 6
     * legacy import.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var Organization $organization */
        $organization = Filament::getTenant();

        return app(CreateConferenceAction::class)->handle($organization, $data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
