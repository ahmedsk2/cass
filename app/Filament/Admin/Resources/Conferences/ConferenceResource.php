<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Conferences;

use App\Filament\Admin\Resources\Conferences\Pages\ListConferences;
use App\Filament\Admin\Resources\Conferences\Pages\ViewConference;
use App\Filament\Admin\Resources\Conferences\RelationManagers\ReviewersRelationManager;
use App\Filament\Admin\Resources\Conferences\Tables\ConferencesTable;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\User;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** @extends \Filament\Resources\Resource<Conference> */
class ConferenceResource extends Resource
{
    protected static ?string $model = Conference::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Read-only on purpose: the platform admin looks and restores, the
     * organizer edits. No `create` or `edit` page is registered, and no
     * $recordRouteKeyName is set - the model's ULID route key is what the
     * generated URLs carry, which also keeps this correct when two
     * organizations happen to use the same conference slug.
     */
    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->is_platform_admin;
    }

    public static function table(Table $table): Table
    {
        return ConferencesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Conference')->columns(2)->components([
                // The record itself, not its id: the admin OrganizationResource
                // binds by the model's own route key (the slug), so a bare id
                // here would be looked up in the slug column (fact 16).
                TextEntry::make('organization.name')->label('Organization')
                    ->url(fn (Conference $record): ?string => $record->organization instanceof Organization
                        ? OrganizationResource::getUrl('view', ['record' => $record->organization], panel: 'admin')
                        : null),
                TextEntry::make('name'),
                TextEntry::make('status')->badge(),
                TextEntry::make('ulid')->label('Public id'),
                TextEntry::make('slug')->label('Web address')
                    ->url(fn (Conference $record): string => $record->publicUrl())
                    ->openUrlInNewTab()
                    ->formatStateUsing(fn (Conference $record): string => $record->publicUrl()),
                TextEntry::make('published_at')->dateTime('j M Y, H:i')->placeholder('Not published'),
                TextEntry::make('deleted_at')->label('Deleted')->dateTime('j M Y, H:i')->placeholder('-'),
            ]),

            Section::make('Dates')->columns(3)->components([
                TextEntry::make('starts_at')->date('j M Y')->placeholder('Not set'),
                TextEntry::make('ends_at')->date('j M Y')->placeholder('Not set'),
                TextEntry::make('timezone'),
                TextEntry::make('submission_opens_at')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)->placeholder('Not set'),
                TextEntry::make('submission_deadline')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)->placeholder('Not set'),
                TextEntry::make('review_deadline')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)->placeholder('Not set'),
            ]),
        ]);
    }

    /**
     * The admin panel has no tenancy, so nothing scopes this. The soft-delete
     * scope is dropped so a trashed conference can still be opened and
     * restored; TrashedFilter hides them from the default listing.
     *
     * @return Builder<Conference>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with('organization');
    }

    /**
     * The reviewer pool, read-only by class. Spec section 4's admin row is
     * "See all", and until now nothing outside the organizer panel - which is
     * membership-gated - could show who reviews an edition.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            ReviewersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConferences::route('/'),
            'view' => ViewConference::route('/{record}'),
        ];
    }

    /**
     * Spec section 4's "See all organizations and conferences" in the form
     * somebody actually uses it: a support email names an edition, and the
     * search bar has to find it. Both attributes are indexed columns.
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
        /** @var Conference $record */
        return [
            __('admin.search.organization') => (string) $record->organization?->name,
            __('admin.search.status') => $record->status->getLabel(),
        ];
    }

    /**
     * The global search runs one query per result for the details above, so
     * the relation it prints is eager-loaded here rather than lazily per row.
     *
     * @return Builder<Conference>
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('organization');
    }
}
