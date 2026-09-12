<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Tables;

use App\Actions\Decisions\ApplyDecision;
use App\Actions\Decisions\ApplyDecisions;
use App\Enums\Decision;
use App\Exceptions\DecisionNotAcceptable;
use App\Models\Submission;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * One definition of each decision action, the same pattern Plan 2's
 * ConferenceStatusActions and Plan 3's SubmissionActions established: the rules
 * must not drift between the row, the bulk selection and the submission view.
 */
class DecisionActions
{
    /**
     * The four choices plus a note, shared by the decide and change modals so
     * the second cannot quietly grow a fifth option.
     *
     * @return list<Radio|Textarea>
     */
    private static function schema(): array
    {
        return [
            Radio::make('decision')
                ->label(__('decisions.actions.decision'))
                ->options(self::options())
                ->required()
                // Not a Select: four options, each of which an organizer has to
                // read, and a radio group shows all four at once rather than
                // making "rejected" a scroll away from "accepted".
                ->inline(false),
            Textarea::make('note')
                ->label(__('decisions.actions.note'))
                ->helperText(__('decisions.actions.note_help'))
                ->rows(3)
                ->maxLength(2000),
        ];
    }

    /** @return array<string, string> */
    private static function options(): array
    {
        $options = [];

        foreach (Decision::inReportOrder() as $decision) {
            $options[$decision->value] = $decision->getLabel();
        }

        return $options;
    }

    /**
     * The plain decision, for a row whose author has not been told anything
     * yet. Hidden - not disabled - once the letter has gone, with
     * changeDecision() taking its place, so the two are never both on offer.
     *
     * `$mayDecide` is passed IN rather than asked per row. `decide` depends
     * only on the conference's organization, and User::roleIn() runs a query on
     * every call (app/Models/User.php:95-100), so a Gate::allows() inside a row
     * action's visible() is one query per RENDERED row - 500 rows is 500
     * queries and it breaks the query-count ceiling in
     * ConferenceRankingPerformanceTest. The page asks once and hands the answer
     * down (ConferenceRanking::mayDecide()). A per-request boolean, never a
     * static cache: with RefreshDatabase every test restarts ids at 1, so a
     * cached answer for conference 1 would silently answer a later test with a
     * different actor.
     */
    public static function decide(bool $mayDecide): Action
    {
        return Action::make('decide')
            ->label(__('decisions.actions.decide'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('primary')
            ->modalHeading(__('decisions.actions.decide_heading'))
            ->modalDescription(__('decisions.actions.decide_description'))
            ->modalSubmitActionLabel(__('decisions.actions.decide_submit'))
            ->schema(self::schema())
            ->fillForm(fn (Submission $record): array => [
                'decision' => $record->decision?->value,
                'note' => $record->currentDecision()?->note,
            ])
            ->visible(fn (Submission $record): bool => $record->decision_notified_at === null && $mayDecide)
            // The per-row Gate has not gone anywhere - it has moved to the
            // write path, where it runs once per click rather than once per
            // rendered row (self::apply() calls Gate::authorize()).
            ->action(function (Submission $record, array $data, ApplyDecision $apply): void {
                self::apply($record, $data, $apply, changeAfterSend: false);
            });
    }

    /**
     * Spec 5.6's "so decisions can be prepared quietly first", read to the end:
     * once the letter is out, changing the decision is a second email to a
     * person who is holding the first one, and it says so before it does it.
     *
     * Takes `$mayDecide` for the same reason decide() does.
     */
    public static function changeDecision(bool $mayDecide): Action
    {
        return Action::make('changeDecision')
            ->label(__('decisions.actions.change'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('decisions.actions.change_heading'))
            ->modalDescription(__('decisions.actions.change_description'))
            ->modalSubmitActionLabel(__('decisions.actions.change_submit'))
            ->schema(self::schema())
            ->fillForm(fn (Submission $record): array => [
                'decision' => $record->decision?->value,
                'note' => $record->currentDecision()?->note,
            ])
            ->visible(fn (Submission $record): bool => $record->decision_notified_at !== null && $mayDecide)
            ->action(function (Submission $record, array $data, ApplyDecision $apply): void {
                self::apply($record, $data, $apply, changeAfterSend: true);
            });
    }

    public static function decideSelected(): BulkAction
    {
        return BulkAction::make('decideSelected')
            ->label(__('decisions.actions.decide_selected'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('primary')
            ->modalHeading(__('decisions.actions.bulk_heading'))
            ->modalDescription(__('decisions.actions.bulk_description'))
            ->modalSubmitActionLabel(__('decisions.actions.decide_submit'))
            ->schema(self::schema())
            ->deselectRecordsAfterCompletion()
            ->action(function (BulkAction $action, array $data, ApplyDecisions $apply): void {
                /** @var User $actor */
                $actor = auth()->user();

                $decision = Decision::from((string) $data['decision']);
                $note = filled($data['note'] ?? null) ? (string) $data['note'] : null;

                // Authorization per row, through the same Gate the row action's
                // write path uses. It stays per row HERE, unlike the row
                // actions' visible(), because this loop runs once per click
                // over a selection rather than once per rendered row - the
                // query-count budget is untouched by it. Not
                // authorizeIndividualRecords(): the report below has to
                // distinguish "not yours" from "already emailed" from
                // "withdrawn", and Filament's helper counts rather than
                // explains (fact 9).
                $allowed = [];

                /** @var Submission $record */
                foreach ($action->getSelectedRecords() as $record) {
                    if (Gate::allows('decide', $record)) {
                        $allowed[] = $record;
                    }
                }

                $report = $apply->handle($allowed, $decision, $actor, $note);

                // Built in steps rather than one chain: Notification has no
                // color() in 5.8.1 (only status(), danger(), info(), success(),
                // warning() - vendor/filament/notifications/src/Concerns/
                // HasStatus.php:11-38) and persistent() takes NO argument
                // (Concerns/HasDuration.php:30), so `->persistent($condition)`
                // is a Larastan level 6 error even though PHP would tolerate
                // the extra argument.
                $notification = Notification::make()
                    ->title(__('decisions.bulk.title'))
                    ->body(ApplyDecisions::summarise($report));

                if ($report['refused'] === []) {
                    $notification->success();
                } else {
                    // A toast that vanishes takes the list of references with
                    // it, and those references are the whole point of the
                    // report.
                    $notification->warning()->persistent();
                }

                $notification->send();
            });
    }

    /**
     * The one write path for both single-row modals, so the refusal wording and
     * the success wording cannot drift.
     *
     * @param  array<string, mixed>  $data
     */
    private static function apply(Submission $record, array $data, ApplyDecision $apply, bool $changeAfterSend): void
    {
        Gate::authorize('decide', $record);

        /** @var User $actor */
        $actor = auth()->user();

        $decision = Decision::from((string) $data['decision']);
        $note = filled($data['note'] ?? null) ? (string) $data['note'] : null;

        try {
            $apply->handle($record, $decision, $actor, $note, $changeAfterSend);
        } catch (DecisionNotAcceptable $exception) {
            // Filament renders a notification body as sanitised HTML whose
            // shared config keeps `style` and `class` (Plan 2 fact 15). These
            // sentences are ours, but escaping them costs nothing and means a
            // later sentence that interpolates a title is already safe.
            Notification::make()
                ->danger()
                ->title(__('decisions.actions.refused'))
                ->body(e($exception->getMessage()))
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('decisions.actions.applied', ['decision' => $decision->getLabel()]))
            ->send();
    }

    /**
     * The record actions of the ranking table, in the order an organizer meets
     * them. The two are mutually exclusive by `visible()`, so only one decision
     * control is ever on a row.
     *
     * The authorization answers are arguments, computed once per page render:
     * see decide()'s docblock. Task 7 adds a second one for the resend.
     *
     * @return list<Action>
     */
    public static function rowActions(bool $mayDecide): array
    {
        return [static::decide($mayDecide), static::changeDecision($mayDecide)];
    }
}
