<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Submissions\Pages;

use App\Filament\Organizer\Resources\Submissions\SubmissionResource;
use Filament\Resources\Pages\ListRecords;

class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;

    // No header actions: nobody creates an abstract from the panel. The export
    // lives in the table header, next to the filters it exports.
}
