<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Pages;

use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewSubmission extends ViewRecord
{
    protected static string $resource = SubmissionResource::class;

    /**
     * Empty on purpose, and asserted by the test. Filament 5.8.1's ViewRecord
     * declares no default EditAction either - getHeaderActions() falls through
     * to the deprecated getActions(), which returns []
     * (InteractsWithHeaderActions.php:55-68) - so this override removes no
     * framework default. It is the standing refusal against a later edit: the
     * platform admin reads an abstract and never rewrites one.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
