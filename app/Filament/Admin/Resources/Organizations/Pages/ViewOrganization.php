<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Organizations\Pages;

use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Filament\Admin\Resources\Organizations\Tables\OrganizationsTable;
use Filament\Resources\Pages\ViewRecord;

class ViewOrganization extends ViewRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OrganizationsTable::approveAction(),
            OrganizationsTable::rejectAction(),
            OrganizationsTable::purgeAction(),
        ];
    }
}
