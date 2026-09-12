<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Reviews\Pages;

use App\Filament\Admin\Resources\Reviews\ReviewResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListReviews extends ListRecords
{
    protected static string $resource = ReviewResource::class;

    /**
     * Empty on purpose, and asserted by the test. A platform admin does not
     * write a review on a reviewer's behalf.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
