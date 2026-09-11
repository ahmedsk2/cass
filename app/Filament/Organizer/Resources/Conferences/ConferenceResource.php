<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences;

use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceShortLink;
use App\Filament\Organizer\Resources\Conferences\Pages\CreateConference;
use App\Filament\Organizer\Resources\Conferences\Pages\EditConference;
use App\Filament\Organizer\Resources\Conferences\Pages\ListConferences;
use App\Filament\Organizer\Resources\Conferences\Pages\ViewConference;
use App\Filament\Organizer\Resources\Conferences\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Organizer\Resources\Conferences\RelationManagers\ReviewQuestionsRelationManager;
use App\Filament\Organizer\Resources\Conferences\RelationManagers\TracksRelationManager;
use App\Filament\Organizer\Resources\Conferences\Schemas\ConferenceForm;
use App\Filament\Organizer\Resources\Conferences\Schemas\ConferenceInfolist;
use App\Filament\Organizer\Resources\Conferences\Tables\ConferencesTable;
use App\Models\Conference;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filament's Resource is generic (`@template TModel of Model = Model`), so
 * without this the inherited getEloquentQuery() is a Builder<Model> and the
 * narrowed override below fails Larastan level 6 with return.type.
 *
 * @extends \Filament\Resources\Resource<Conference>
 */
class ConferenceResource extends Resource
{
    protected static ?string $model = Conference::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'name';

    // No $recordRouteKeyName: Filament builds record URLs from the model's own
    // getRouteKey() (the ULID) and resolves them with this key name, so
    // overriding one without the other 404s every generated link (fact 16).

    /**
     * The default already resolves to `organization`; stating it means a
     * rename of the relation breaks loudly here instead of silently
     * disabling tenant scoping.
     */
    protected static ?string $tenantOwnershipRelationshipName = 'organization';

    public static function form(Schema $schema): Schema
    {
        return ConferenceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ConferenceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConferencesTable::configure($table);
    }

    /**
     * Tenant scoping is a global scope the panel registers, so this only adds
     * the eager loads the table columns need. Do not re-add a tenant filter.
     *
     * @return Builder<Conference>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('shortLink');
    }

    public static function getRelations(): array
    {
        return [
            TracksRelationManager::class,
            CustomFieldsRelationManager::class,
            ReviewQuestionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConferences::route('/'),
            'create' => CreateConference::route('/create'),
            'view' => ViewConference::route('/{record}'),
            'short-link' => ConferenceShortLink::route('/{record}/share'),
            'edit' => EditConference::route('/{record}/edit'),
        ];
    }
}
