<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Submissions;

use App\Filament\Organizer\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Organizer\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Organizer\Resources\Submissions\Schemas\SubmissionInfolist;
use App\Filament\Organizer\Resources\Submissions\Tables\SubmissionsTable;
use App\Models\Conference;
use App\Models\Submission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every abstract of the tenant, under Filament's tenancy like ConferenceResource.
 * The ownership relation is Submission::organization(), a has-one-through the
 * conference, so the panel's global scope is a whereHas on every Submission
 * query in an organizer request: this list and its search, filters and export,
 * the view page and its route binding, global search, and every
 * ConferenceResource page that reads abstracts. SubmissionPolicy is the second,
 * independent gate.
 *
 * @extends \Filament\Resources\Resource<Submission>
 */
class SubmissionResource extends Resource
{
    protected static ?string $model = Submission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 2;

    protected static ?string $tenantOwnershipRelationshipName = 'organization';

    // No $recordRouteKeyName: the model's route key is the ULID and Filament
    // builds URLs from getRouteKey(), so the two agree.

    public static function infolist(Schema $schema): Schema
    {
        return SubmissionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubmissionsTable::configure($table);
    }

    /**
     * Tenancy is the panel's scope, not this method. What stays here is the
     * conference: an abstract whose conference was deleted is neither listed
     * nor opened, because the timezone column and the infolist read the
     * conference, and ExportSubmissionsCsv relies on the same exclusion. Inside
     * the panel this whereHas also carries the Conference model's own tenancy
     * scope, so it is a second filter on the tenant as well.
     *
     * @return Builder<Submission>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('conference')
            ->with(['conference', 'track', 'authors']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubmissions::route('/'),
            'view' => ViewSubmission::route('/{record}'),
        ];
    }

    /**
     * Used by the link from the conference view page. The query string has to
     * be the table filter's own shape: Filament 5's ListRecords binds
     * `public ?array $tableFilters` as `#[Url(as: 'filters')]`, and a single
     * SelectFilter's state is `['value' => ...]`. A bare `?conference_id=`
     * reaches no property at all, is silently dropped, and the organizer lands
     * on every conference's submissions.
     */
    public static function urlForConference(Conference $conference): string
    {
        return static::getUrl('index', [
            'filters' => ['conference_id' => ['value' => $conference->getKey()]],
        ]);
    }
}
