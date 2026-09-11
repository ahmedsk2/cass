<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Pages\Tenancy;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use App\Support\Branding\Contrast;
use Closure;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

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
}
