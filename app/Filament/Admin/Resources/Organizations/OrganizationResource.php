<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Organizations;

use App\Filament\Admin\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Admin\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Admin\Resources\Organizations\Schemas\OrganizationInfolist;
use App\Filament\Admin\Resources\Organizations\Tables\OrganizationsTable;
use App\Models\Organization;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * The organizer-facing route key is the slug, but the admin panel links to
     * organizations by id (see the approval notification), so route binding
     * here must resolve by id regardless of the model's own route key name.
     */
    protected static ?string $recordRouteKeyName = 'id';

    /**
     * Defense in depth: the panel's own auth middleware already blocks
     * non-admins at the HTTP layer, but Filament also runs this check when a
     * resource page's Livewire component is mounted directly (bypassing
     * routing entirely, as in a test), so it must not default to allow just
     * because no model policy is registered for Organization.
     */
    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->is_platform_admin;
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrganizationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrganizationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'view' => ViewOrganization::route('/{record}'),
        ];
    }
}
