<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Resources\Submissions;

use App\Filament\Reviewer\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Reviewer\Resources\Submissions\Pages\ReviewSubmission;
use App\Filament\Reviewer\Resources\Submissions\Tables\QueueTable;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\User;
use App\Support\Reviews\ReviewerScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The reviewer's queue (spec 5.4 step 3).
 *
 * `$isScopedToTenant = false` because the reviewer panel has no tenancy at all;
 * what replaces it is ReviewerScope in getEloquentQuery(), which
 * resolveRecordRouteBinding() also uses
 * (HasRoutes::getRecordRouteBindingEloquentQuery()), so the list, the review
 * page and every generated URL are covered by one override. SubmissionPolicy is
 * the second, independent gate.
 *
 * @extends \Filament\Resources\Resource<Submission>
 */
class SubmissionResource extends Resource
{
    protected static ?string $model = Submission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $recordTitleAttribute = 'title';

    protected static bool $isScopedToTenant = false;

    // No $recordRouteKeyName: Submission::getRouteKeyName() is the ULID and
    // Filament builds URLs from getRouteKey(), so the two agree (fact 28).

    public static function getNavigationLabel(): string
    {
        return __('reviewer.queue.title');
    }

    public static function getModelLabel(): string
    {
        return __('reviewer.queue.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reviewer.queue.model_plural');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isActiveReviewer();
    }

    public static function table(Table $table): Table
    {
        return QueueTable::configure($table);
    }

    /** @return Builder<Submission> */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        // whereRaw(false) rather than an empty result by luck: outside an
        // authenticated reviewer this resource has no meaning, and returning
        // every submission on the platform because auth() happened to be empty
        // is the failure mode this line exists to prevent.
        //
        // The guard is above the `with()` below, not beside the return, because
        // the constrained eager load needs `$user->getKey()`.
        if (! $user instanceof User) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        $query = parent::getEloquentQuery()->with([
            'conference.organization',
            'track',
            // Constrained to this reviewer: the column reads one row and the
            // page must not load every reviewer's review of every abstract.
            // Relation, not HasMany: `with()` declares its constraint closure
            // as `Closure(Relation<*, *, *>): mixed`, and a narrower parameter
            // type there is a contravariance error Larastan catches.
            'reviews' => fn (Relation $reviews) => $reviews->where('reviewer_user_id', $user->getKey()),
        ]);

        return ReviewerScope::constrain($query, $user);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubmissions::route('/'),
            'review' => ReviewSubmission::route('/{record}/review'),
        ];
    }

    /**
     * The dashboard's link into one conference's queue. The query string has to
     * be the table filter's own shape - Filament binds `$tableFilters` as
     * `#[Url(as: 'filters')]` and a SelectFilter's state is `['value' => …]` -
     * the same rule Plan 3 discovered for the organizer's list.
     */
    public static function urlForConference(Conference $conference): string
    {
        return static::getUrl('index', [
            'filters' => ['conference_id' => ['value' => $conference->getKey()]],
        ], panel: 'reviewer');
    }
}
