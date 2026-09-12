<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Reviews;

use App\Filament\Admin\Resources\Reviews\Pages\ListReviews;
use App\Filament\Admin\Resources\Reviews\Pages\ViewReview;
use App\Filament\Admin\Resources\Reviews\Schemas\ReviewInfolist;
use App\Filament\Admin\Resources\Reviews\Tables\ReviewsTable;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewQuestion;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The one screen in this application that prints a review's *content*. Spec
 * section 4's admin row is "See all", and until now nothing anywhere - not the
 * organizer panel, not Plan 5's ranking table, which reads the scores - showed
 * what a reviewer actually wrote.
 *
 * Read-only, and the refusal is HERE rather than in the policy: ReviewPolicy's
 * before() answers true for a platform admin and Laravel returns a non-null
 * before() result WITHOUT calling the ability, so two pages and no header
 * actions are what actually refuse.
 *
 * @extends \Filament\Resources\Resource<Review>
 */
class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    /**
     * A review has no name. Filament falls back to the primary key when this is
     * null, which is what a global search result would print - so the search is
     * off below instead.
     */
    protected static ?string $recordTitleAttribute = null;

    protected static ?int $navigationSort = 40;

    /**
     * A review is prose a reviewer wrote about somebody else's unpublished
     * work. It is reachable from the abstract it belongs to and from this
     * resource's own filters; it is not something a search box offers across
     * every tenant at once.
     */
    protected static bool $isGloballySearchable = false;

    /** Defense in depth: the panel's middleware blocks non-admins at the HTTP layer, but Filament also runs this when a page's Livewire component is mounted directly. */
    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->is_platform_admin;
    }

    /**
     * Four relations on every row of the list and the infolist: the abstract,
     * its conference, its organization and the reviewer. Eager-loaded here so a
     * page of fifty reviews is one query and not two hundred.
     *
     * The answers are loaded in the FORM's order, not their own, so an admin
     * reads the review the way the reviewer filled it in: `review_answers` has
     * no sort column of its own and `review_questions.sort` is the order the
     * reviewer saw. The nested `question` is loaded inside that same closure
     * rather than as a second `answers.question` entry, because
     * Builder::parseWithRelations() re-registers a parent segment as a no-op
     * closure and a later with() call would then drop this ordering silently.
     *
     * @return Builder<Review>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'submission.conference.organization',
            'reviewer',
            'answers' => self::eagerLoadAnswersInFormOrder(...),
        ]);
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [];
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.reviews.title');
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReviewInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReviewsTable::configure($table);
    }

    /**
     * Index and view only. There is no create, no edit and no delete page, and
     * because before() short-circuits ReviewPolicy for a platform admin, this
     * array IS the refusal together with the empty getHeaderActions() on both
     * pages.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListReviews::route('/'),
            'view' => ViewReview::route('/{record}'),
        ];
    }

    /**
     * @param  HasMany<ReviewAnswer, Review>  $query
     */
    private static function eagerLoadAnswersInFormOrder(HasMany $query): void
    {
        $query->with('question')->orderBy(
            ReviewQuestion::query()
                ->select('sort')
                ->whereColumn('review_questions.id', 'review_answers.review_question_id')
        )->orderBy('review_answers.id');
    }
}
