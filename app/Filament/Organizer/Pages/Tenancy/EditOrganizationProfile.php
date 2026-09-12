<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Pages\Tenancy;

use App\Actions\Organizations\ClaimCustomDomain;
use App\Actions\Organizations\ReleaseCustomDomain;
use App\Actions\Organizations\VerifyCustomDomain;
use App\Enums\OrganizationType;
use App\Exceptions\CustomDomainRefused;
use App\Models\Organization;
use App\Models\User;
use App\Support\Branding\Contrast;
use App\Support\Domains\DomainName;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class EditOrganizationProfile extends EditTenantProfile
{
    public static function canView(Model $tenant): bool
    {
        $user = auth()->user();

        return $user instanceof User && $tenant instanceof Organization
            && ($user->roleIn($tenant)?->canManageOrganization() ?? false);
    }

    public static function getLabel(): string
    {
        return 'Organization profile';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Details')->columns(2)->components([
                TextInput::make('name')->required()->minLength(3)->maxLength(120),
                TextInput::make('slug')->disabled()->dehydrated(false)
                    ->helperText('Used in your public URLs. Contact us if it needs to change.'),
                Select::make('type')->options(OrganizationType::class)->required(),
                Select::make('country')->options(config('cass.countries'))->searchable()->required(),
                TextInput::make('website')->url()->maxLength(255),
                TextInput::make('contact_email')->email()->maxLength(255)
                    ->helperText('How we reach you about your conferences. Use a shared inbox, not a personal address.'),
                Toggle::make('publish_contact_email')
                    ->label('Show this address on public conference pages')
                    ->helperText('Off by default. Anyone - including a scraper - can read an address printed on a public page.'),
            ]),
            Section::make('Branding')->columns(2)->components([
                FileUpload::make('logo_path')->label('Logo')->disk('branding')->directory('logos')
                    ->image()->imagePreviewHeight('80')->maxSize(2048)
                    ->acceptedFileTypes(['image/png', 'image/jpeg'])
                    ->helperText('PNG or JPEG with transparent background works best. Max 2 MB.')
                    ->columnSpanFull(),
                ColorPicker::make('primary_color')->required()->regex('/^#[0-9A-Fa-f]{6}$/')
                    ->rule(static::contrastRule())
                    ->helperText('Buttons and headings on your public pages. Must be readable on white.'),
                ColorPicker::make('accent_color')->required()->regex('/^#[0-9A-Fa-f]{6}$/')
                    ->rule(static::contrastRule())
                    ->helperText('Links and highlights.'),
            ]),
            Section::make(__('domain.section.heading'))
                ->description(__('domain.section.description', ['platform' => DomainName::platformHost()]))
                ->columns(1)
                ->components([
                    // Read-only: every write goes through one of the three
                    // actions below, because the parent's save() fills the
                    // tenant from the form state and none of the three columns
                    // is fillable (Task 2 decision 1).
                    // An infolist TextEntry, NOT Filament\Schemas\Components\Text:
                    // that component has badge() and color() but no label() - it
                    // does not use HasLabel, so ->label() falls through
                    // Macroable::__call and throws BadMethodCallException, which
                    // would make /org/{slug}/profile a 500 for every organizer.
                    // An entry is never dehydrated, so the parent save() still
                    // sees no `custom_domain_status` key.
                    TextEntry::make('custom_domain_status')
                        ->label(__('domain.fields.status'))
                        ->state(fn (): string => static::stateLabel())
                        ->badge()
                        ->color(fn (): string => static::stateColor()),
                    View::make('filament.organizer.partials.custom-domain-records')
                        ->viewData(['organization' => static::tenant()])
                        ->visible(fn (): bool => static::tenant()?->custom_domain !== null),
                    Actions::make([
                        static::claimAction(),
                        static::verifyAction(),
                        static::releaseAction(),
                    ])->key('custom_domain_actions'),
                ]),
        ]);
    }

    protected static function contrastRule(): Closure
    {
        // Filament evaluates a closure passed to `rule()` by injecting named
        // dependencies it recognises (e.g. $get, $record) before using the
        // return value as the actual Laravel validation rule. A raw
        // `function (string $attribute, mixed $value, Closure $fail)`
        // closure has an unresolvable `$attribute` parameter from Filament's
        // point of view, so it must be nested inside a zero-argument
        // closure that Filament can evaluate for free, which then returns
        // the real Illuminate-style rule closure for the validator to call.
        return static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) && ! Contrast::passesAA($value, '#FFFFFF')) {
                $fail('This colour is too light to read on a white background. Choose a darker shade.');
            }
        };
    }

    /** The tenant this page is editing, typed once so every closure below reads it the same way. */
    protected static function tenant(): ?Organization
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization ? $tenant : null;
    }

    protected static function stateLabel(): string
    {
        $organization = static::tenant();

        if ($organization?->custom_domain === null) {
            return __('domain.state.none');
        }

        return $organization->hasVerifiedCustomDomain()
            ? __('domain.state.verified').' — '.$organization->custom_domain
            : __('domain.state.pending').' — '.$organization->custom_domain;
    }

    protected static function stateColor(): string
    {
        $organization = static::tenant();

        return match (true) {
            $organization?->custom_domain === null => 'gray',
            $organization->hasVerifiedCustomDomain() => 'success',
            default => 'warning',
        };
    }

    protected static function canManage(): bool
    {
        $user = auth()->user();
        $organization = static::tenant();

        // The page's canView() already answers this, but an action is a
        // Livewire endpoint a client can call by name, and "the page would
        // have 404ed" is not an authorization check on that call.
        return $user instanceof User
            && $organization instanceof Organization
            && ($user->roleIn($organization)?->canManageOrganization() ?? false);
    }

    /**
     * The error-bag key one field of the *mounted action's* modal is watching.
     *
     * Filament re-throws a ValidationException raised inside an action body
     * unchanged (Actions\Concerns\InteractsWithActions::callMountedAction:384),
     * and the modal's fields live under the mounted action schema's state path
     * - `mountedActions.0.data` for a top-level action. A message keyed plain
     * `domain` therefore reaches no field at all and the modal shows nothing.
     * Read off the live schema rather than hardcoded, because the nesting index
     * moves the moment an action is opened from inside another one.
     */
    protected static function actionFieldKey(self $livewire, string $field): string
    {
        $schemaName = $livewire->getMountedActionSchemaName();
        $statePath = $schemaName === null ? null : $livewire->getSchema($schemaName)?->getStatePath();

        return filled($statePath) ? $statePath.'.'.$field : $field;
    }

    protected static function claimAction(): Action
    {
        return Action::make('claimCustomDomain')
            ->label(__('domain.actions.claim'))
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->visible(fn (): bool => static::canManage())
            ->schema([
                TextInput::make('domain')
                    ->label(__('domain.fields.domain'))
                    ->helperText(__('domain.fields.domain_help'))
                    ->required()
                    ->maxLength(DomainName::MAX_LENGTH)
                    ->rule(static::domainRule()),
            ])
            ->fillForm(fn (): array => ['domain' => static::tenant()?->custom_domain])
            ->action(function (array $data, self $livewire): void {
                $organization = static::tenant();
                $user = auth()->user();

                if (! $organization instanceof Organization || ! $user instanceof User) {
                    return;
                }

                try {
                    app(ClaimCustomDomain::class)->handle($organization, (string) $data['domain'], $user);
                } catch (CustomDomainRefused $exception) {
                    // The uniqueness check lives in the action, because the
                    // action is also what the console and any later caller
                    // use. Surfacing it on the field rather than as a
                    // notification is what keeps the modal open with the value
                    // still in it.
                    throw ValidationException::withMessages([
                        static::actionFieldKey($livewire, 'domain') => $exception->getMessage(),
                    ]);
                }

                Notification::make()->success()->title(__('domain.notices.claimed'))->send();
            });
    }

    protected static function verifyAction(): Action
    {
        return Action::make('verifyCustomDomain')
            ->label(__('domain.actions.verify'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('primary')
            ->visible(fn (): bool => static::canManage() && static::tenant()?->custom_domain !== null)
            ->action(function (): void {
                $organization = static::tenant();
                $user = auth()->user();

                if (! $organization instanceof Organization || ! $user instanceof User) {
                    return;
                }

                // One outbound DNS lookup per click on a four-worker pool, and
                // the natural behaviour of somebody waiting for propagation is
                // to click every two seconds. Keyed on the actor, the shape
                // InviteMember uses.
                $key = 'domain-verify:'.$user->getAuthIdentifier();
                $limit = max(1, (int) config('cass.domains.verify_rate_limit'));

                if (RateLimiter::tooManyAttempts($key, $limit)) {
                    Notification::make()->warning()
                        ->title(__('domain.errors.lookup_failed'))
                        ->body(__('domain.errors.throttled', ['seconds' => RateLimiter::availableIn($key)]))
                        ->send();

                    return;
                }

                RateLimiter::hit($key, 60);

                try {
                    app(VerifyCustomDomain::class)->handle($organization, $user);
                } catch (CustomDomainRefused $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title(__('domain.notices.verified_title'))
                    ->body(__('domain.notices.verified_body', [
                        'domain' => (string) $organization->custom_domain,
                    ]))
                    ->persistent()
                    ->send();
            });
    }

    protected static function releaseAction(): Action
    {
        return Action::make('releaseCustomDomain')
            ->label(__('domain.actions.release'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (): bool => static::canManage() && static::tenant()?->custom_domain !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => __('domain.actions.release_confirm_heading', [
                'domain' => (string) static::tenant()?->custom_domain,
            ]))
            ->modalDescription(fn (): string => __('domain.actions.release_confirm_body', [
                'domain' => (string) static::tenant()?->custom_domain,
                'platform' => DomainName::platformHost(),
            ]))
            ->action(function (): void {
                $organization = static::tenant();
                $user = auth()->user();

                if (! $organization instanceof Organization || ! $user instanceof User) {
                    return;
                }

                $domain = (string) $organization->custom_domain;

                app(ReleaseCustomDomain::class)->handle($organization, $user);

                Notification::make()->success()->title(__('domain.notices.released', ['domain' => $domain]))->send();
            });
    }

    /**
     * The double-closure shape this file already uses for contrastRule(), and
     * for the same reason: Filament evaluates a Closure rule as a callback, so
     * an unwrapped `function (string $attribute, …)` throws
     * BindingResolutionException on its first parameter. The outer closure
     * takes nothing and returns the real Illuminate rule.
     */
    protected static function domainRule(): Closure
    {
        return static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $problem = DomainName::problem($value);

            if ($problem !== null) {
                $fail(__($problem));
            }
        };
    }
}
