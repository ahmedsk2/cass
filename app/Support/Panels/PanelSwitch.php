<?php

declare(strict_types=1);

namespace App\Support\Panels;

use App\Filament\Organizer\Pages\Dashboard as OrganizerDashboard;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Action;
use Filament\FilamentManager;
use Filament\Support\Icons\Heroicon;

/**
 * Spec section 6: "A user with both organizer and reviewer roles sees a switch
 * link in the panel header."
 *
 * One definition of each direction, handed to each panel's userMenuItems(), so
 * the two links cannot drift. Both hide themselves when the target URL is null
 * (fact 10) rather than rendering a link that goes nowhere.
 */
final class PanelSwitch
{
    /** Shown in the organizer panel, to somebody who also reviews. */
    public static function toReviewer(): Action
    {
        return Action::make('switch-to-reviewer')
            ->label(__('reviewer.switch.to_reviewer'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->url(fn (): ?string => self::reviewerUrl())
            ->visible(fn (): bool => (self::user()?->isActiveReviewer() ?? false)
                && self::reviewerUrl() !== null);
    }

    /** Shown in the reviewer panel, to somebody who also organizes. */
    public static function toOrganizer(): Action
    {
        return Action::make('switch-to-organizer')
            ->label(__('reviewer.switch.to_organizer'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->url(fn (): ?string => self::organizerUrl())
            ->visible(fn (): bool => self::organizerUrl() !== null);
    }

    private static function reviewerUrl(): ?string
    {
        // isStrict: false so this is null rather than an exception in a request
        // where the reviewer panel is somehow not registered.
        //
        // Through the manager rather than the Filament facade, for the reason
        // ReviewerInvitation::landingUrl() spells out: the facade's own
        // `@method static Panel getPanel(...)` docblock
        // (vendor/filament/filament/src/Facades/Filament.php:93) drops the
        // `| null` the real signature carries (FilamentManager.php:372), so
        // Larastan reads the nullsafe call this line exists for as dead code.
        $panel = app(FilamentManager::class)->getPanel('reviewer', isStrict: false);

        return $panel?->getUrl();
    }

    /**
     * Built from the organizer Dashboard page rather than from
     * Panel::getUrl(): the page URL takes the tenant explicitly and is never
     * null, while Panel::getUrl() resolves the tenant from the current user and
     * returns null when there is not one (fact 10).
     */
    private static function organizerUrl(): ?string
    {
        $organization = self::user()?->organizations()->orderBy('name')->first();

        if (! $organization instanceof Organization) {
            return null;
        }

        return OrganizerDashboard::getUrl(panel: 'organizer', tenant: $organization);
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
