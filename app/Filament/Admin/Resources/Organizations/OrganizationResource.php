<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Organizations;

use App\Filament\Admin\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Admin\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Admin\Resources\Organizations\RelationManagers\InvitationsRelationManager;
use App\Filament\Admin\Resources\Organizations\RelationManagers\MembersRelationManager;
use App\Filament\Admin\Resources\Organizations\Schemas\OrganizationInfolist;
use App\Filament\Admin\Resources\Organizations\Tables\OrganizationsTable;
use App\Models\Organization;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The @extends is the sibling ConferenceResource's convention and is not
 * decoration: Filament\Resources\Resource declares `@template TModel of Model
 * = Model`, so without it every inherited `Builder<TModel>` - including the
 * one getGlobalSearchEloquentQuery() below has to return - resolves to
 * Builder<Model> rather than to this resource's own model.
 *
 * @extends \Filament\Resources\Resource<Organization>
 */
class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * No $recordRouteKeyName override: Filament builds every record URL from
     * $record->getRouteKey() and resolves it with the resource's own key name,
     * so naming a different column here (id, while the model's route key is
     * the slug) makes the list's View action link to /admin/organizations/{slug}
     * and then look that slug up in the id column - a 404 on every row. Leaving
     * it null falls back to Organization::getRouteKeyName(), and the two agree.
     */

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

    /**
     * Spec section 4's platform-admin cell for "Manage organization members",
     * which had no screen: the organizer panel is membership-gated, so an admin
     * who is not a member of an organization could not see who is in it. Both
     * managers are read-only by class.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            InvitationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'view' => ViewOrganization::route('/{record}'),
        ];
    }

    /**
     * Spec section 4's "See all organizations and conferences" in the form
     * somebody actually uses it: a support email arrives naming a society, and
     * the search bar has to find it. Both attributes are indexed columns.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'slug'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Organization $record */
        return [
            __('admin.search.status') => $record->status->getLabel(),
            __('admin.search.conferences') => (string) ($record->conferences_count ?? 0),
        ];
    }

    /**
     * The global search prints a conference count per result, and
     * `$record->conferences()->count()` is one extra aggregate query for every
     * row the search returns - up to the 50-result limit, on every keystroke.
     * withCount() folds it into the search query itself, which is what the
     * sibling ConferenceResource does with its organization relation.
     *
     * @return Builder<Organization>
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->withCount('conferences');
    }
}
