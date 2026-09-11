<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\OrganizerPanelProvider;
use App\Providers\Filament\ReviewerPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    OrganizerPanelProvider::class,
    ReviewerPanelProvider::class,
];
