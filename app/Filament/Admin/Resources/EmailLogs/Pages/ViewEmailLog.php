<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmailLogs\Pages;

use App\Filament\Admin\Resources\EmailLogs\EmailLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEmailLog extends ViewRecord
{
    protected static string $resource = EmailLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
