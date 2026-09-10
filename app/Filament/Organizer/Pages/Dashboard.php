<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Pages;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.organizer.pages.dashboard';

    public function getOrganization(): Organization
    {
        /** @var Organization $tenant */
        $tenant = Filament::getTenant();

        return $tenant;
    }

    public function isPending(): bool
    {
        return $this->getOrganization()->status === OrganizationStatus::Pending;
    }

    public function isSuspended(): bool
    {
        return $this->getOrganization()->status === OrganizationStatus::Suspended;
    }
}
