<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Actions\Conferences\GenerateConferencePoster;
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
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\BasePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Spec section 4's "Manage organization profile, branding, domain", as ONE form
 * for the two places that may do it: the organizer's tenant profile page and
 * the platform admin's edit page. Moved here from EditOrganizationProfile
 * unchanged in behaviour - tests/Feature/Organizer/OrganizationProfileTest.php
 * and CustomDomainTest.php pass unedited, which is the proof.
 *
 * Every closure takes the organization as Filament's injected `$record`, which
 * is the schema's model on both pages (the tenant on one, the edited record on
 * the other), rather than Filament::getTenant(), which is null in the admin
 * panel. Every write is OrganizationPolicy::update(), which already answers
 * both questions: a manager of this organization, or a platform admin.
 */
class OrganizationProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.organization.form.details'))->columns(2)->components([
                TextInput::make('name')->label(__('admin.organization.form.name'))
                    ->required()->minLength(3)->maxLength(120),
                TextInput::make('slug')->label(__('admin.organization.form.slug'))
                    ->disabled()->dehydrated(false)
                    ->helperText(__('admin.organization.form.slug_help')),
                Select::make('type')->label(__('admin.organization.form.type'))
                    ->options(OrganizationType::class)->required(),
                Select::make('country')->label(__('admin.organization.form.country'))
                    ->options(config('cass.countries'))->searchable()->required(),
                TextInput::make('website')->label(__('admin.organization.form.website'))
                    ->url()->maxLength(255),
                TextInput::make('contact_email')->label(__('admin.organization.form.contact_email'))
                    ->email()->maxLength(255)
                    ->helperText(__('admin.organization.form.contact_email_help')),
                Toggle::make('publish_contact_email')
                    ->label(__('admin.organization.form.publish_contact_email'))
                    ->helperText(__('admin.organization.form.publish_contact_email_help')),
            ]),
            Section::make(__('admin.organization.form.branding'))->columns(2)->components([
                FileUpload::make('logo_path')->label(__('admin.organization.form.logo'))
                    ->disk('branding')->directory('logos')
                    ->image()->imagePreviewHeight('80')->maxSize(2048)
                    ->acceptedFileTypes(['image/png', 'image/jpeg'])
                    // A stored path in the request must be this record's own.
                    // Off by default in Filament 5.8.1, and it matters now:
                    // UpdateOrganizationProfile deletes the file a row stops
                    // pointing at, so a path copied from another organization's
                    // public page would otherwise become a way to delete its
                    // logo on the next replacement.
                    ->preventFilePathTampering()
                    ->rule(static::logoPixelRule())
                    ->helperText(__('admin.organization.form.logo_help', [
                        'max' => intdiv(GenerateConferencePoster::MAX_LOGO_PIXELS, 1_000_000),
                    ]))
                    ->columnSpanFull(),
                ColorPicker::make('primary_color')->label(__('admin.organization.form.primary_color'))
                    ->required()->regex('/^#[0-9A-Fa-f]{6}$/')
                    ->rule(static::contrastRule())
                    ->helperText(__('admin.organization.form.primary_color_help')),
                ColorPicker::make('accent_color')->label(__('admin.organization.form.accent_color'))
                    ->required()->regex('/^#[0-9A-Fa-f]{6}$/')
                    ->rule(static::contrastRule())
                    ->helperText(__('admin.organization.form.accent_color_help')),
            ]),
            Section::make(__('domain.section.heading'))
                ->description(__('domain.section.description', ['platform' => DomainName::platformHost()]))
                ->columns(1)
                ->components([
                    // Read-only: every write goes through one of the three
                    // actions below, because the page's save() hands the form
                    // state to UpdateOrganizationProfile and none of the three
                    // columns is fillable (Plan 6 Task 2 decision 1).
                    // An infolist TextEntry, NOT Filament\Schemas\Components\Text:
                    // that component has badge() and color() but no label() - it
                    // does not use HasLabel, so ->label() falls through
                    // Macroable::__call and throws BadMethodCallException, which
                    // would make the page a 500 for everybody. An entry is never
                    // dehydrated, so save() still sees no `custom_domain_status`
                    // key.
                    TextEntry::make('custom_domain_status')
                        ->label(__('domain.fields.status'))
                        ->state(fn (?Organization $record): string => static::stateLabel($record))
                        ->badge()
                        ->color(fn (?Organization $record): string => static::stateColor($record)),
                    View::make('filament.organizer.partials.custom-domain-records')
                        ->viewData(fn (?Organization $record): array => ['organization' => $record])
                        ->visible(fn (?Organization $record): bool => $record?->custom_domain !== null),
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
                $fail(__('admin.organization.form.contrast'));
            }
        };
    }

    /**
     * The 2 MB cap says nothing about pixels: a flat-colour 6000 x 3500 PNG
     * fits inside it, and GenerateConferencePoster::logoDataUri() then drops
     * the logo from every poster without a word. Refused here instead, at the
     * same line, read from the same constant.
     *
     * Filament runs file rules against each TemporaryUploadedFile on its own
     * (BaseFileUpload::getValidationRules()), so `$value` is the upload, not
     * the field's array. dimensions() streams it off whatever disk Livewire
     * stored it on; getimagesize() reads the header and decodes nothing.
     */
    protected static function logoPixelRule(): Closure
    {
        return static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $size = $value instanceof TemporaryUploadedFile
                ? $value->dimensions()
                : @getimagesize((string) $value->getRealPath());

            if (! is_array($size) || $size[0] * $size[1] <= GenerateConferencePoster::MAX_LOGO_PIXELS) {
                return;
            }

            $fail(__('admin.organization.logo_too_large', [
                'width' => number_format($size[0]),
                'height' => number_format($size[1]),
                'max' => intdiv(GenerateConferencePoster::MAX_LOGO_PIXELS, 1_000_000),
            ]));
        };
    }

    protected static function stateLabel(?Organization $organization): string
    {
        if ($organization?->custom_domain === null) {
            return __('domain.state.none');
        }

        return $organization->hasVerifiedCustomDomain()
            ? __('domain.state.verified').' — '.$organization->custom_domain
            : __('domain.state.pending').' — '.$organization->custom_domain;
    }

    protected static function stateColor(?Organization $organization): string
    {
        return match (true) {
            $organization?->custom_domain === null => 'gray',
            $organization->hasVerifiedCustomDomain() => 'success',
            default => 'warning',
        };
    }

    /**
     * Each page's canView()/canEdit() already answers this, but an action is a
     * Livewire endpoint a client can call by name, and "the page would have
     * 404ed" is not an authorization check on that call.
     */
    protected static function canManage(?Organization $organization): bool
    {
        return $organization instanceof Organization && Gate::allows('update', $organization);
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
    protected static function actionFieldKey(BasePage $livewire, string $field): string
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
            ->visible(fn (?Organization $record): bool => static::canManage($record))
            ->schema([
                TextInput::make('domain')
                    ->label(__('domain.fields.domain'))
                    ->helperText(__('domain.fields.domain_help'))
                    ->required()
                    ->maxLength(DomainName::MAX_LENGTH)
                    ->rule(static::domainRule()),
            ])
            ->fillForm(fn (?Organization $record): array => ['domain' => $record?->custom_domain])
            ->action(function (array $data, ?Organization $record, BasePage $livewire): void {
                $user = auth()->user();

                if (! static::canManage($record) || ! $record instanceof Organization || ! $user instanceof User) {
                    return;
                }

                try {
                    app(ClaimCustomDomain::class)->handle($record, (string) $data['domain'], $user);
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
            ->visible(fn (?Organization $record): bool => static::canManage($record) && $record?->custom_domain !== null)
            ->action(function (?Organization $record): void {
                $user = auth()->user();

                if (! static::canManage($record) || ! $record instanceof Organization || ! $user instanceof User) {
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
                        // Not domain.errors.lookup_failed: "we could not reach
                        // the DNS servers" tells an organizer who simply
                        // clicked too fast to go and edit a record that is
                        // already correct.
                        ->title(__('domain.errors.throttled_title'))
                        ->body(__('domain.errors.throttled', ['seconds' => RateLimiter::availableIn($key)]))
                        ->send();

                    return;
                }

                RateLimiter::hit($key, 60);

                try {
                    app(VerifyCustomDomain::class)->handle($record, $user);
                } catch (CustomDomainRefused $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title(__('domain.notices.verified_title'))
                    ->body(__('domain.notices.verified_body', [
                        'domain' => (string) $record->custom_domain,
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
            ->visible(fn (?Organization $record): bool => static::canManage($record) && $record?->custom_domain !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (?Organization $record): string => __('domain.actions.release_confirm_heading', [
                'domain' => (string) $record?->custom_domain,
            ]))
            ->modalDescription(fn (?Organization $record): string => __('domain.actions.release_confirm_body', [
                'domain' => (string) $record?->custom_domain,
                'platform' => DomainName::platformHost(),
            ]))
            ->action(function (?Organization $record): void {
                $user = auth()->user();

                if (! static::canManage($record) || ! $record instanceof Organization || ! $user instanceof User) {
                    return;
                }

                $domain = (string) $record->custom_domain;

                app(ReleaseCustomDomain::class)->handle($record, $user);

                Notification::make()->success()->title(__('domain.notices.released', ['domain' => $domain]))->send();
            });
    }

    /**
     * The double-closure shape this class already uses for contrastRule(), and
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
