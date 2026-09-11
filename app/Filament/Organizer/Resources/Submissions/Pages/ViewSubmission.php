<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Submissions\Pages;

use App\Filament\Organizer\Resources\Submissions\SubmissionResource;
use App\Filament\Organizer\Resources\Submissions\Tables\SubmissionActions;
use Filament\Resources\Pages\ViewRecord;

class ViewSubmission extends ViewRecord
{
    protected static string $resource = SubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SubmissionActions::backToList(),
            ...SubmissionActions::all(),
        ];
    }
}
