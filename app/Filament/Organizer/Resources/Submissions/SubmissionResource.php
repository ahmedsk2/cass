<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Submissions;

use App\Filament\Organizer\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Organizer\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Organizer\Resources\Submissions\Schemas\SubmissionInfolist;
use App\Filament\Organizer\Resources\Submissions\Tables\SubmissionsTable;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** @extends \Filament\Resources\Resource<Submission> */
class SubmissionResource extends Resource
{
    protected static ?string $model = Submission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 2;

    /**
     * **Read the comment before changing this.**
     *
     * Filament's tenancy is a global scope *and* two model observers
     * (Resource/Concerns/BelongsToTenant). The `created` observer ends in
     * `$relationship->save($tenant)` for any ownership relation that is not
     * BelongsTo, BelongsToThrough or BelongsToMany. `Submission` has no
     * `organization_id`; it reaches the tenant through `conference`, so its
     * `organization()` is a HasOneThrough - which would scope queries correctly
     * (the scope's default branch is a whereHas) and then fatal on creation,
     * because HasOneThrough has no save(). That fires on every submission
     * created while this panel is booted with a tenant, which is every panel
     * test.
     *
     * So scoping is done by hand, once, in getEloquentQuery() below - which is
     * also what resolveRecordRouteBinding() uses
     * (HasRoutes::getRecordRouteBindingEloquentQuery()), so the list, the view
     * page and every generated record URL are all covered. SubmissionPolicy is
     * the second, independent gate.
     */
    protected static bool $isScopedToTenant = false;

    // No $recordRouteKeyName: the model's route key is the ULID and Filament
    // builds URLs from getRouteKey(), so the two agree (Plan 2 fact 16).

    public static function infolist(Schema $schema): Schema
    {
        return SubmissionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubmissionsTable::configure($table);
    }

    /**
     * @return Builder<Submission>
     */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        $query = parent::getEloquentQuery()->with(['conference', 'track', 'authors']);

        // whereRaw(false) rather than an empty result by luck: outside a tenant
        // this resource has no meaning, and returning every submission on the
        // platform because getTenant() happened to be null is the failure mode
        // this whole comment exists to prevent.
        if (! $tenant instanceof Organization) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'conference',
            fn (Builder $conference): Builder => $conference->where('organization_id', $tenant->getKey()),
        );
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
