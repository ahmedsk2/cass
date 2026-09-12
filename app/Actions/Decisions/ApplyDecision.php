<?php

declare(strict_types=1);

namespace App\Actions\Decisions;

use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\SubmissionStatus;
use App\Exceptions\DecisionNotAcceptable;
use App\Models\Submission;
use App\Models\SubmissionDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Spec 5.6: "Applied per row or in bulk. Each decision records who and when and
 * appends to `SubmissionDecision`."
 *
 * Same shape as PublishConference and SubmitAbstract: `blockers()` is a pure
 * read the panel calls to decide whether to show an action and what to say when
 * it cannot, `handle()` re-checks and throws, because the panel is a
 * convenience and a hand-made Livewire call is not.
 *
 * **The change-after-send rule**, which is spec 5.6's "so decisions can be
 * prepared quietly first" read to the end:
 *
 * - `decision_notified_at` is null: change it as often as you like. Every
 *   change appends a history row, and nothing has left the building.
 * - `decision_notified_at` is set: a plain call is REFUSED. The author is
 *   holding an email that says something else, and flipping the column would
 *   leave the application and that inbox disagreeing with nothing on screen
 *   saying so.
 * - `$changeAfterSend` is the explicit way through, behind its own action and
 *   its own modal. It appends a row, writes the new decision, and puts
 *   `decision_notified_at` back to null so the next send queues a new letter.
 *   The superseded history row keeps its own letter columns, which is the
 *   audit.
 *
 * **Unchanged is not refused.** Applying the decision a row already has does
 * nothing at all - no row, no timestamp, no activity entry. A bulk decision
 * over a filtered list hits this constantly, and treating it as an error is how
 * an organizer learns to ignore the report.
 */
class ApplyDecision
{
    /**
     * @return list<string> empty when the decision may be applied
     */
    public function blockers(
        Submission $submission,
        Decision $decision,
        bool $changeAfterSend = false,
        ?string $note = null,
    ): array {
        $reasons = [];

        $conference = $submission->conference;

        if ($conference === null) {
            // Conference soft deletes and the foreign key only restricts a
            // *hard* delete, so this relation resolves to null while the row
            // survives - the case Submission::isOpenToAuthor() already guards.
            return [__('decisions.errors.no_conference')];
        }

        if (! in_array($conference->status, [ConferenceStatus::Reviewing, ConferenceStatus::Decided], true)) {
            $reasons[] = __('decisions.errors.wrong_conference_status', [
                'status' => mb_strtolower($conference->status->getLabel()),
            ]);
        }

        if ($submission->status === SubmissionStatus::Draft) {
            $reasons[] = __('decisions.errors.draft');
        }

        if ($submission->status === SubmissionStatus::Withdrawn) {
            $reasons[] = __('decisions.errors.withdrawn');
        }

        // ANY write to a row whose author has already been written to needs the
        // explicit change-and-resend path - including one that only edits the
        // committee note. handle() clears decision_notified_at unconditionally,
        // so a note-only edit would put the row back in the send queue and the
        // next "Send decision emails" would queue a SECOND letter, rotating the
        // author's status token again, with no modal and no warning. Guarding
        // on `decision !== $decision` misses exactly that case, and the bulk
        // action is the live path: decideSelected() has no per-row visible()
        // the way decide() does.
        //
        // A call that changes NOTHING is still not an error: re-applying the
        // same decision with the same note over a filtered list is the normal
        // post-send case, and refusing it is how an organizer learns to ignore
        // the report. isUnchanged() is the same predicate handle() uses to
        // return early, so the two cannot disagree.
        if ($submission->decision_notified_at !== null
            && ! $changeAfterSend
            && ! $this->isUnchanged($submission, $decision, $note)) {
            $reasons[] = __('decisions.errors.already_notified');
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Returns the refreshed submission. A call that changes nothing returns it
     * untouched, which is what makes the bulk report's `unchanged` count
     * meaningful.
     */
    public function handle(
        Submission $submission,
        Decision $decision,
        User $actor,
        ?string $note = null,
        bool $changeAfterSend = false,
    ): Submission {
        $reasons = $this->blockers($submission, $decision, $changeAfterSend, $note);

        if ($reasons !== []) {
            throw new DecisionNotAcceptable($reasons);
        }

        if ($this->isUnchanged($submission, $decision, $note)) {
            return $submission;
        }

        $previous = $submission->decision;

        DB::transaction(function () use ($submission, $decision, $actor, $note): void {
            $row = new SubmissionDecision;
            $row->forceFill([
                'submission_id' => $submission->getKey(),
                'decision' => $decision,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'note' => $note,
            ])->save();

            $submission->forceFill([
                'decision' => $decision,
                // Written together, always: two views of one fact, and a row
                // whose decision is accepted_oral while its status is still
                // under_review is a row every screen disagrees about.
                'status' => $decision->submissionStatus(),
                // Null whenever the decision changes, whether or not it had
                // been sent: an unsent row stays unsent, and a sent row goes
                // back into the send queue so the author hears the new answer.
                'decision_notified_at' => null,
            ])->save();
        });

        activity()
            ->performedOn($submission)
            ->causedBy($actor)
            ->withProperties([
                'decision' => $decision->value,
                'previous' => $previous?->value,
                // Never the note: it is the committee's own reasoning about a
                // named person's work, and the activity log is readable by the
                // platform admin. The note lives on the decision row, behind
                // SubmissionDecisionPolicy.
            ])
            ->log('submission.decided');

        return $submission->refresh();
    }

    /**
     * "Already this decision" means the decision AND the note agree: an
     * organizer who reopens the modal only to add a sentence of reasoning has
     * changed something, and that something belongs in the history.
     */
    private function isUnchanged(Submission $submission, Decision $decision, ?string $note): bool
    {
        if ($submission->decision !== $decision) {
            return false;
        }

        $current = $submission->currentDecision();

        return $current !== null && (string) $current->note === (string) $note;
    }
}
