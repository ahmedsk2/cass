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
 * The browser suite runs only under phpunit.browser.xml (tests/Browser is not
 * in phpunit.xml at all), but it needs the same TestCase and the same
 * RefreshDatabase as the rest: the plugin serves the application in-process, so
 * the browser's requests hit this very SQLite :memory: connection.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

/**
 * Put the request into the organizer panel for one tenant, the way the panel's
 * middleware does in a real request.
 */
function bootOrganizerPanel(Organization $organization): void
{
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organization);
    // Not bootCurrentPanel(): FilamentManager guards it with a one-shot
    // $isCurrentPanelBooted flag it never resets
    // (vendor/filament/filament/src/FilamentManager.php:65-73), and
    // setCurrentPanel() does not reset it either (:885-892). So in a test that
    // has already booted ANOTHER panel - which is now possible, because
    // bootReviewerPanel() exists - this call would return immediately and
    // silently skip registering the organizer panel's tenancy global scope and
    // its tenant-associating `creating` observer, the two things
    // withoutTenant() exists to work around. The organizer half of such a test
    // would then assert against unscoped queries and pass for the wrong reason.
    //
    // Re-booting the same panel is safe: registerTenancyModelGlobalScope() is
    // guarded by hasGlobalScope()
    // (Resources/Resource/Concerns/BelongsToTenant.php:139), and the
    // creating/created listeners return early unless this panel is the current
    // one.
    Filament::getPanel('organizer')->boot();
}

/**
 * Put the request into the reviewer panel, the way that panel's middleware does
 * in a real request. There is no tenant: the reviewer panel is not
 * tenant-scoped, and a tenant left over from an earlier bootOrganizerPanel()
 * call in the same test would make Filament's tenancy global scope fire on
 * models this panel reads without one.
 */
function bootReviewerPanel(): void
{
    Filament::setCurrentPanel('reviewer');
    Filament::setTenant(null, isQuiet: true);
    Filament::getPanel('reviewer')->boot();
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
