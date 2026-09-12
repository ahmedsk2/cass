<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Reviews\Pages;

use App\Filament\Admin\Resources\Reviews\ReviewResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewReview extends ViewRecord
{
    protected static string $resource = ReviewResource::class;

    /**
     * Empty on purpose, and asserted by the test. The platform admin reads a
     * review and never rewrites one - reopening belongs to the reviewer and to
     * the organizer who asks for it.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
