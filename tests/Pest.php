<?php

declare(strict_types=1);

use App\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Put the request into the organizer panel for one tenant, the way the panel's
 * middleware does in a real request.
 */
function bootOrganizerPanel(Organization $organization): void
{
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();
}

/**
 * Filament registers a `creating` observer on every tenant-scoped model that
 * associates the current tenant, so a fixture belonging to another
 * organization would silently be re-parented while the panel is booted. Wrap
 * cross-tenant fixtures in this.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function withoutTenant(Closure $callback): mixed
{
    $tenant = Filament::getTenant();
    Filament::setTenant(null, isQuiet: true);

    try {
        return $callback();
    } finally {
        Filament::setTenant($tenant, isQuiet: true);
    }
}
