<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Resources\Submissions\Pages;

use App\Filament\Reviewer\Resources\Submissions\SubmissionResource;
use Filament\Resources\Pages\ListRecords;

class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;

    public function getTitle(): string
    {
        return __('reviewer.queue.title');
    }

    // No header actions: a reviewer creates nothing here.
}
