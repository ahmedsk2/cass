<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Actions\Submissions\ExportRankingCsv;
use App\Actions\Submissions\ExportRankingXlsx;
use App\Enums\Decision;
use App\Enums\SubmissionStatus;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Tables\DecisionActions;
use App\Filament\Organizer\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\Track;
use App\Support\Scoring\RankedSubmissions;
use App\Support\Scoring\RankingRows;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spec 5.6's ranking table, one conference at a time.
 *
 * **A resource page, not a second resource.** The ranking's every number is per
 * conference (its "fewer than N reviews" default is this conference's
 * `reviewers_per_submission`; its summary strip's denominator is this
 * conference's abstracts), SubmissionResource is already the panel-wide list,
 * and a second Resource over Submission would need a second copy of that
 * resource's `$isScopedToTenant = false` override and its HasOneThrough
 * warning. A page inherits ConferenceResource's real tenancy through
 * resolveRecord() and then scopes by conference_id, which needs no override.
 *
 * **Unlike ConferenceEmailTemplates, the table is query-backed.** `Table::query()`
 * rather than `Table::records()`, so sorting, filtering, searching, pagination
 * and bulk selection are all SQL (Filament takes the array branch only when
 * `! $table->hasQuery()`, vendor/filament/tables/src/Concerns/HasRecords.php:95).
 * That is what meets spec section 10's budget: every column here is a column on
 * `submissions`, so the page never touches `reviews`, and
 * tests/Feature/Organizer/ConferenceRankingPerformanceTest.php asserts exactly
 * that.
 */
class ConferenceRanking extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ConferenceResource::class;

    protected string $view = 'filament.organizer.pages.conference-ranking';

    /** Memoised by maySend(); see that method for why it has to be. */
    private ?bool $maySend = null;

    public function mount(int|string $record): void
    {
        // resolveRecord() runs through ConferenceResource::getEloquentQuery(),
        // which carries the panel's tenancy global scope, so another
        // organization's conference is already a 404 before the policy is
        // consulted. The policy check is defence in depth, exactly as on
        // ConferenceEmailTemplates and ConferenceShortLink.
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 404);
    }

    public function getTitle(): string
    {
        return __('decisions.ranking.title');
    }

    public function getSubheading(): ?string
    {
        return __('decisions.ranking.subheading');
    }

    public function getConference(): Conference
    {
        /** @var Conference $conference */
        $conference = $this->getRecord();

        return $conference;
    }

    /**
     * The summary strip, computed once per render and handed to the view.
     *
     * @return array{total: int, reviewed: int, unreviewed: int, mean_score: float|null, decided: int, undecided: int, by_decision: array<string, int>}
     */
    public function summary(): array
    {
        return $this->getConference()->rankingSummary();
    }

    public function table(Table $table): Table
    {
        $conference = $this->getConference();

        // The configured default, FOLDED INTO the fixed ladder rather than
        // inserted into it. Filament renders $pageOptions verbatim
        // (vendor/filament/support/resources/views/components/pagination/index.blade.php
        // foreaches it with no dedupe), so an operator who sets
        // CASS_RANKING_PAGE_SIZE=100 - one of the three numbers already on
        // screen, and the likeliest value to pick - would otherwise get "100"
        // twice in the dropdown. array_unique() keeps first-occurrence order,
        // so sort() afterwards is what stops a 250 or a 500 leaving the ladder
        // out of order. max(1, ...) is the house rule for every read of a
        // count knob, and because the result is folded in rather than appended,
        // even CASS_RANKING_PAGE_SIZE=0 leaves defaultPaginationPageOption()
        // naming an option that is really in the list. 'all' stays out of the
        // sort: it is not a number, and array_unique over mixed types is not
        // worth the argument.
        $pageSize = max(1, (int) config('cass.decisions.page_size'));
        $pageOptions = array_unique([25, $pageSize, 100, 250]);
        sort($pageOptions);

        return $table
            ->query(fn (): Builder => RankedSubmissions::query($conference)->with('track'))
            // Best first. NULL sorts last descending on both drivers, so an
            // unreviewed abstract sinks to the bottom rather than heading the
            // list (verified in Task 11's MySQL run).
            ->defaultSort('score', 'desc')
            ->paginated([...$pageOptions, 'all'])
            ->defaultPaginationPageOption($pageSize)
            // The row goes to Plan 3's submission view, which is the one place
            // an organizer reads an abstract. Not a modal: the view page has the
            // files, the authors and the withdraw action already.
            ->recordUrl(fn (Submission $record): string => SubmissionResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('decisions.ranking.columns.reference'))
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('title')
                    ->label(__('decisions.ranking.columns.title'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(80),
                TextColumn::make('track.name')
                    ->label(__('decisions.ranking.columns.track'))
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('presentation_preference')
                    ->label(__('decisions.ranking.columns.preference'))
                    ->badge()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('score')
                    ->label(__('decisions.ranking.columns.score'))
                    ->numeric(2)
                    ->sortable()
                    ->weight('semibold')
                    // An em dash, not a zero: nobody has scored this yet, and a
                    // 0.00 in this column is a real and very different answer.
                    ->placeholder('—'),
                TextColumn::make('score_spread')
                    ->label(__('decisions.ranking.columns.spread'))
                    ->numeric(2)
                    ->sortable()
                    ->tooltip(__('decisions.ranking.columns.spread_help'))
                    ->placeholder('—'),
                TextColumn::make('review_count')
                    ->label(__('decisions.ranking.columns.reviews'))
                    ->badge()
                    ->sortable()
                    ->color(fn (Submission $record): string => $record->review_count
                        >= max(1, (int) $this->getConference()->reviewers_per_submission) ? 'success' : 'warning'),
                TextColumn::make('status')
                    ->label(__('decisions.ranking.columns.status'))
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('decision')
                    ->label(__('decisions.ranking.columns.decision'))
                    ->badge()
                    ->sortable()
                    ->placeholder(__('decisions.ranking.not_decided')),
                TextColumn::make('decision_notified_at')
                    ->label(__('decisions.ranking.columns.notified'))
                    ->dateTime('j M Y, H:i')
                    ->timezone($conference->timezone)
                    ->description($conference->timezone)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder(__('decisions.ranking.not_sent')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('decisions.ranking.columns.status'))
                    // Only the statuses this page can show, derived from the one
                    // definition rather than repeated. `SubmissionStatus::class`
                    // would offer draft and withdrawn, which RankedSubmissions
                    // has already excluded from the base query - an option that
                    // can only ever produce the "Nothing to rank yet" empty
                    // state reads as a bug, not as a rule.
                    ->options(fn (): array => collect(RankedSubmissions::statuses())
                        ->mapWithKeys(fn (string $value): array => [
                            $value => SubmissionStatus::from($value)->getLabel(),
                        ])
                        ->all())
                    ->multiple(),
                SelectFilter::make('track_id')
                    ->label(__('decisions.ranking.columns.track'))
                    // This conference's tracks only. `->relationship()` would
                    // query every track on the platform, because this page is
                    // not a tenant-scoped resource.
                    ->options(fn (): array => Track::query()
                        ->where('conference_id', $conference->getKey())
                        ->orderBy('sort')
                        ->pluck('name', 'id')
                        ->all()),
                SelectFilter::make('decision')
                    ->label(__('decisions.ranking.columns.decision'))
                    ->options(self::decisionFilterOptions())
                    // A custom query rather than the built-in equality: "not
                    // decided" is a NULL, and a SelectFilter cannot express one
                    // through its options alone.
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match (true) {
                            $value === null, $value === '' => $query,
                            $value === 'none' => $query->whereNull('decision'),
                            default => $query->where('decision', $value),
                        };
                    }),
                Filter::make('under_reviewed')
                    ->label(__('decisions.ranking.filters.under_reviewed'))
                    ->schema([
                        TextInput::make('count')
                            ->label(__('decisions.ranking.filters.under_reviewed_count'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(99)
                            // NOT ->default(). A filter schema's defaults are
                            // hydrated on boot
                            // ($this->getTableFiltersForm()->fill($this->tableFilters),
                            // vendor/filament/tables/src/Concerns/InteractsWithTable.php:81
                            // -> HasState::fill(null), schemas/src/Concerns/HasState.php:324-341)
                            // and, because filters are deferred by default, the
                            // next lines copy them straight back into
                            // $tableFilters (:83-84). A custom-schema Filter has
                            // no `isActive` key for apply() to skip on
                            // (Filters/Concerns/InteractsWithTableQuery.php:19-40),
                            // so a default here would silently reduce the WHOLE
                            // ranking to `review_count < 2` on first load - and
                            // the in-order listing test, assertCountTableRecords(3),
                            // the search and summary cases, the 500-row budget
                            // and both export tests would all be looking at a
                            // filtered table. The conference's own target is a
                            // hint instead.
                            ->placeholder((string) max(1, (int) $conference->reviewers_per_submission))
                            ->helperText(__('decisions.ranking.filters.under_reviewed_help', [
                                'count' => max(1, (int) $conference->reviewers_per_submission),
                            ])),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $count = $data['count'] ?? null;

                        return blank($count) ? $query : $query->where('review_count', '<', (int) $count);
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $count = $data['count'] ?? null;

                        return blank($count)
                            ? null
                            : __('decisions.ranking.filters.under_reviewed_indicator', ['count' => (int) $count]);
                    }),
            ])
            ->headerActions([
                // TABLE header actions. `table()` is an instance method on the
                // page, so `$this->exportAction(...)` resolves, and Filament
                // evaluates the closures without rebinding `$this`
                // (vendor/filament/support/src/Concerns/EvaluatesClosures.php:35),
                // so `$this->getFilteredTableQuery()` inside the action still
                // runs on the page.
                //
                // The send sits FIRST, where an organizer looks for it, and it
                // is a TABLE header action rather than a page one because
                // callTableAction() and assertTableAction*() resolve only
                // against Table::$flatActions.
                DecisionActions::sendDecisionEmails($conference, $this->maySend()),
                $this->exportAction(
                    'exportCsv',
                    __('decisions.ranking.export_csv'),
                    Heroicon::OutlinedArrowDownTray,
                    fn (Builder $query, Conference $conference): StreamedResponse => app(ExportRankingCsv::class)
                        ->handle($query, $conference, RankingRows::fileName($conference, 'csv')),
                ),
                $this->exportAction(
                    'exportXlsx',
                    __('decisions.ranking.export_xlsx'),
                    Heroicon::OutlinedTableCells,
                    fn (Builder $query, Conference $conference): StreamedResponse => app(ExportRankingXlsx::class)
                        ->handle($query, $conference, RankingRows::fileName($conference, 'xlsx')),
                ),
            ])
            ->recordActions(DecisionActions::rowActions($conference, $this->mayDecide(), $this->maySend()))
            // The bulk decide's own bound, the same idea as SendDecisionEmails'
            // send_chunk and for the same reason: decideSelected() walks the
            // whole selection asking the Gate per row and then running
            // ApplyDecision's read and transaction - about eleven queries a row
            // - and "select all" on a 500-row conference is several thousand of
            // them inside one php-fpm request (docker/php.ini's 60 seconds).
            // Filament turns this into a LIMIT on the selection query
            // (Tables\Concerns\HasBulkActions::getSelectedTableRecordsQuery),
            // so a larger selection is decided in batches rather than refused.
            ->maxSelectableRecords(max(1, (int) config('cass.decisions.decide_chunk')))
            ->toolbarActions([
                DecisionActions::decideSelected(),
            ])
            ->emptyStateHeading(__('decisions.ranking.empty_heading'))
            ->emptyStateDescription(__('decisions.ranking.empty_body'));
    }

    /**
     * One authorization answer for the whole table, asked once per render.
     *
     * `decide` depends only on the conference's organization
     * (SubmissionPolicy::decide() asks for membership directly), and
     * User::roleIn() is a query, so a Gate call inside a row action's visible()
     * would be one query per rendered row: 500 rows is 500 queries and it
     * breaks the query-count ceiling in ConferenceRankingPerformanceTest. A
     * probe carrying the loaded conference asks the real policy without a row,
     * so the answer is the policy's, not a re-implementation of it.
     */
    protected function mayDecide(): bool
    {
        $probe = new Submission;
        $probe->setRelation('conference', $this->getConference());

        return Gate::allows('decide', $probe);
    }

    /**
     * The send/resend authorization answer, asked once per request and kept on
     * the component, for the reason mayDecide() explains and one more: unlike
     * mayDecide(), which table() calls once, this answer is also read by a
     * header action whose `visible()` Filament evaluates several times per
     * render - and ConferencePolicy::canManage() goes through User::roleIn(),
     * which is a query on every call. Asking the Gate from inside that closure
     * put the 500-row page at exactly the query ceiling
     * ConferenceRankingPerformanceTest bounds.
     *
     * A private instance property, never a static: Livewire builds a fresh
     * component per request, so this is a per-request memo, while a static
     * would answer a later test - or a later tenant - with the first actor's
     * answer.
     */
    protected function maySend(): bool
    {
        return $this->maySend ??= Gate::allows('sendDecisions', $this->getConference());
    }

    /**
     * The four decisions plus "not decided". Built here rather than inline so
     * the same list can be reused by Task 6's decide action without the two
     * drifting.
     *
     * @return array<string, string>
     */
    public static function decisionFilterOptions(): array
    {
        $options = ['none' => __('decisions.ranking.not_decided')];

        foreach (Decision::inReportOrder() as $decision) {
            $options[$decision->value] = $decision->getLabel();
        }

        return $options;
    }

    /**
     * The two exports differ only in a name, an icon and a writer, and both
     * need the same four things: the export ability, the table's own filtered
     * query, a null guard on it, and the conference. One builder rather than
     * two near-identical closures, so a fix to the guard is a fix to both.
     *
     * @param  Closure(Builder<Submission>, Conference): StreamedResponse  $writer
     */
    private function exportAction(string $name, string $label, Heroicon $icon, Closure $writer): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color('gray')
            ->visible(fn (): bool => Gate::allows('export', Submission::class))
            ->action(function () use ($writer): ?StreamedResponse {
                Gate::authorize('export', Submission::class);

                // The table's own query: already scoped to this conference and
                // already carrying whatever filters and search the organizer is
                // looking at, so the file matches the screen. Filament types it
                // `?Builder` with no generic (Tables\Contracts\HasTable), which
                // Larastan level 6 will not hand to a `Builder<Submission>`
                // parameter - hence the annotation and the null guard.
                //
                // getFilteredSortedTableQuery(), NOT getFilteredTableQuery():
                // the latter applies filters, search and eager loads and stops
                // there (Tables\Concerns\HasRecords::getFilteredTableQuery),
                // sorting being added by this one - so the export used to come
                // out in insertion order while the page was sorted best-first,
                // and "exactly the rows on screen" was true of the rows and
                // false of their order.
                /** @var Builder<Submission>|null $query */
                $query = $this->getFilteredSortedTableQuery();

                if ($query === null) {
                    Notification::make()->danger()->title(__('decisions.ranking.nothing_to_export'))->send();

                    return null;
                }

                return $writer($query, $this->getConference());
            });
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToConference')
                ->label(__('decisions.ranking.back'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(fn (): string => ConferenceResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }
}
