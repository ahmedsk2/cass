<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Reviewer\Pages\Dashboard;
use App\Support\Panels\InitialsAvatarProvider;
use App\Support\Panels\PanelSwitch;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Spec section 6: `/review/...`, its own login, the same `users` table.
 *
 * **No tenancy.** A reviewer belongs to conferences, not to organizations, and
 * may review for several organizations at once; a tenant in the URL would mean
 * choosing one of them to look at their own queue. What replaces it is
 * App\Support\Reviews\ReviewerScope (Task 6), which is the single definition of
 * what this panel may read, plus a policy on every model.
 *
 * `discoverResources` points at a directory Task 6 creates. That is safe:
 * Panel::discoverComponents() returns early when the directory does not exist
 * (fact 39), so this provider works on its own for the length of this task.
 */
class ReviewerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('reviewer')
            ->path('review')
            ->login()
            ->passwordReset()
            ->emailVerification()
            ->profile()
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ])
            ->brandName('CASS Review')
            ->brandLogo(fn () => view('brand.logo'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('favicon.ico'))
            // Initials as a data: URI, not a fetch to ui-avatars.com - which
            // img-src does not allow and which would send every panel user's
            // name to a third party on every page load.
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                'primary' => Color::hex('#176BB8'),
            ])
            ->discoverResources(in: app_path('Filament/Reviewer/Resources'), for: 'App\Filament\Reviewer\Resources')
            ->discoverPages(in: app_path('Filament/Reviewer/Pages'), for: 'App\Filament\Reviewer\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Reviewer/Widgets'), for: 'App\Filament\Reviewer\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            // Spec section 6: a user with both roles sees a switch link in the
            // panel header. Filament normalises a MenuItem into an Action
            // anyway (fact 9), so the Action is built directly and keeps a
            // stable name the tests can address.
            ->userMenuItems([
                PanelSwitch::toOrganizer(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
