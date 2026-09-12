<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Organizer\Pages\Dashboard;
use App\Filament\Organizer\Pages\Tenancy\EditOrganizationProfile;
use App\Models\Organization;
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

class OrganizerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('organizer')
            ->path('org')
            ->login()
            ->passwordReset()
            ->emailVerification()
            ->profile()
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ])
            ->tenant(Organization::class, slugAttribute: 'slug')
            ->tenantProfile(EditOrganizationProfile::class)
            ->brandName('CASS')
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
            ->discoverResources(in: app_path('Filament/Organizer/Resources'), for: 'App\Filament\Organizer\Resources')
            ->discoverPages(in: app_path('Filament/Organizer/Pages'), for: 'App\Filament\Organizer\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Organizer/Widgets'), for: 'App\Filament\Organizer\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            // Spec section 6: a user with both roles sees a switch link in the
            // panel header.
            ->userMenuItems([
                PanelSwitch::toReviewer(),
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
