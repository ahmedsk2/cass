<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Pages;

use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;

    /**
     * Empty on purpose, and asserted by the test. Filament 5.8.1's ListRecords
     * adds NO header action of its own - getHeaderActions() falls through to
     * the deprecated getActions(), which returns []
     * (InteractsWithHeaderActions.php:55-68) - so this override removes no
     * framework default. It is belt-and-braces against a later edit or a
     * scaffolded CreateAction: a platform admin creating an abstract on an
     * organizer's behalf is not a thing this platform does.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
