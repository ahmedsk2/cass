<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Actions\Reviews\AssignReviewers;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Enums\SubmissionStatus;
use App\Exceptions\ReviewNotAcceptable;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Spec 5.5, the manual half.
 *
 * Unlike the Members and Reviewers pages, this table is **Eloquent-backed**:
 * it lists submissions, which are real rows and of which spec section 10
 * budgets for 500, so `Table::query()` gives sorting, searching, pagination and
 * a filter for free and the reviewer set is a loaded relation.
 */
class ConferenceAssignments extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ConferenceResource::class;

    protected string $view = 'filament.organizer.resources.conferences.pages.assignments';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 404);

        // Assignment has no meaning in open pool, and a bookmarked URL must not
        // survive a mode change.
        abort_unless($this->getConference()->review_mode === ReviewMode::Assigned, 404);
    }

    public function getTitle(): string
    {
        return __('reviewer.assign.title');
    }

    public function getSubheading(): ?string
    {
        $coverage = $this->getConference()->assignmentCoverage();

        return __('reviewer.assign.subheading', [
            'target' => $coverage['target'],
            'covered' => $coverage['covered'],
            'submissions' => $coverage['submissions'],
        ]);
    }

    public function getConference(): Conference
    {
        /** @var Conference $conference */
        $conference = $this->getRecord();

        return $conference;
    }

    /** @return array{target: int, submissions: int, covered: int, under: int, unassigned: int} */
    public function getCoverage(): array
    {
        return $this->getConference()->assignmentCoverage();
    }

    public function table(Table $table): Table
    {
        return $table
            // ->getQuery() on the end, because every builder call forwarded
            // through a relation comes back as the *relation*
            // (Relation::__call returns $this when the decorated call returned
            // the query), so the chain below is a HasMany and not the Builder
            // this closure promises. getQuery() hands back the underlying
            // Eloquent builder with the relation's own `conference_id = ?`
            // constraint already on it - added by HasMany::addConstraints() in
            // the constructor - so nothing is lost by unwrapping it.
            ->query(fn (): Builder => $this->getConference()->submissions()
                ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])
                ->with(['track', 'reviewAssignments.reviewer'])
                ->withCount('reviewAssignments')
                ->getQuery())
            ->defaultSort('reference')
            ->columns([
                TextColumn::make('reference')->label(__('reviewer.assign.columns.reference'))
                    ->fontFamily('mono')->searchable()->sortable()->placeholder('-'),
                TextColumn::make('title')->label(__('reviewer.assign.columns.title'))
                    ->searchable()->sortable()->wrap()->limit(70),
                TextColumn::make('track.name')->label(__('reviewer.assign.columns.track'))->placeholder('-')->toggleable(),
                TextColumn::make('review_assignments_count')->label(__('reviewer.assign.columns.count'))
                    ->badge()
                    ->sortable()
                    ->color(fn (Submission $record): string => $record->review_assignments_count >= $this->getCoverage()['target']
                        ? 'success'
                        : 'warning'),
                TextColumn::make('reviewers')->label(__('reviewer.assign.columns.reviewers'))
                    ->listWithLineBreaks()
                    // Not a relation column: the names come from two hops
                    // (assignment -> user) and the relation is already loaded.
                    // No nullsafe and no fallback: review_assignments.
                    // reviewer_user_id is NOT NULL and cascades on delete, so
                    // the reviewer is there whenever the row is.
                    ->state(fn (Submission $record): array => $record->reviewAssignments
                        ->map(fn (ReviewAssignment $assignment): string => $assignment->reviewer->name)
                        ->all())
                    ->placeholder(__('reviewer.assign.none')),
            ])
            ->filters([
                Filter::make('under_target')
                    ->label(__('reviewer.assign.filters.under_target'))
                    // `has(..., '<', N)` compiles to
                    // `(select count(*) from review_assignments where
                    //   review_assignments.submission_id = submissions.id) < N`
                    // in the WHERE clause
                    // (QueriesRelationships::addWhereCountQuery; N === 1 becomes
                    // `where not exists`, which is the same rule).
                    //
                    // NOT `having()` over the withCount() alias: the query has
                    // no GROUP BY, and SQLite refuses a HAVING clause on a
                    // non-aggregate query outright - "SQLSTATE[HY000]: General
                    // error: 1 HAVING clause on a non-aggregate query" -
                    // whatever the expression is, `havingRaw` with a correlated
                    // sub-select included. MySQL happens to allow it, which is
                    // exactly the driver split this project must not ship. It
                    // would also have to survive the paginator's own count()
                    // query, which a WHERE predicate does and a HAVING does not.
                    ->query(fn (Builder $query): Builder => $query->has(
                        'reviewAssignments',
                        '<',
                        $this->getCoverage()['target'],
                    )),
            ])
            ->recordActions([
                $this->assignAction(),
            ])
            ->emptyStateHeading(__('reviewer.assign.empty_heading'))
            ->emptyStateDescription(__('reviewer.assign.empty_body'));
    }

    private function assignAction(): Action
    {
        return Action::make('assign')
            ->label(__('reviewer.assign.actions.assign'))
            ->icon(Heroicon::OutlinedUserGroup)
            ->visible(fn (): bool => Gate::allows('create', ReviewAssignment::class))
            ->modalHeading(fn (Submission $record): string => __('reviewer.assign.actions.assign_heading', [
                'reference' => (string) ($record->reference ?? $record->title),
            ]))
            ->fillForm(fn (Submission $record): array => [
                'reviewers' => $record->reviewAssignments->pluck('reviewer_user_id')->map('intval')->all(),
            ])
            ->schema([
                Select::make('reviewers')
                    ->label(__('reviewer.assign.fields.reviewers'))
                    ->multiple()
                    ->searchable()
                    // A plain option array built from this conference's active
                    // reviewers. `->relationship()` would reach every user on
                    // the platform, because there is no tenant scope on users.
                    ->options(fn (): array => $this->reviewerOptions())
                    ->helperText(__('reviewer.assign.fields.reviewers_help', [
                        'target' => $this->getCoverage()['target'],
                    ])),
            ])
            ->action(function (Submission $record, array $data, AssignReviewers $assign): void {
                Gate::authorize('create', ReviewAssignment::class);

                /** @var list<int> $ids */
                $ids = array_values(array_map('intval', (array) ($data['reviewers'] ?? [])));

                try {
                    $assign->handle($record, $ids, $this->actor());
                } catch (ReviewNotAcceptable $exception) {
                    Notification::make()->danger()
                        ->title(__('reviewer.notices.refused'))
                        ->body(e($exception->getMessage()))
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->success()->title(__('reviewer.assign.notices.saved'))->send();
            });
    }

    /**
     * The closure parameter is typed for the same reason as every other
     * closure in app/: Larastan level 6 runs MissingClosureParameterTypehintRule
     * and "Anonymous function has parameter $reviewer with no type specified"
     * fails Step 9's gate. `ConferenceReviewer` is also what makes
     * `$reviewer->user` resolve to `User` rather than `mixed` here.
     *
     * conference_reviewers.user_id is NOT NULL and cascades on delete, so there
     * is no nullsafe walk and no "-" fallback here either: a row without its
     * user cannot exist.
     *
     * @return array<int, string>
     */
    private function reviewerOptions(): array
    {
        /** @var array<int, string> $options */
        $options = $this->getConference()->reviewers()
            ->where('status', ReviewerStatus::Active->value)
            ->with('user')
            ->get()
            ->mapWithKeys(fn (ConferenceReviewer $reviewer): array => [
                (int) $reviewer->user_id => $reviewer->user->name,
            ])
            ->all();

        asort($options);

        return $options;
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToConference')
                ->label(__('reviewer.actions.back'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(fn (): string => ConferenceResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
