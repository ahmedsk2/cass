<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\ConferenceStatus;
use App\Enums\SubmissionStatus;
use App\Exceptions\ConferenceNotPublishable;
use App\Models\Conference;
use App\Models\User;
use App\Support\Scoring\RankedSubmissions;

/**
 * The `Reviewing -> Decided` half of the lifecycle ConferenceStatus has
 * declared since Plan 2, and the last transition Plan 2's backlog was waiting
 * on. `Decided -> Archived` already exists (ArchiveConference).
 *
 * Same shape as PublishConference and Plan 4's StartReviewing, including the
 * exception type: an organizer meeting a transition they cannot make gets
 * sentences, and the panel action prints them rather than throwing.
 *
 * **What `Decided` means downstream, all of it already wired by Plan 4:**
 * Conference::acceptsReviewWrites() is Reviewing only, so SaveReviewDraft,
 * SubmitReview and ReopenReview all refuse; ReviewSubmission::isReadOnly()
 * renders the form disabled; and isOpenToReviewers() still admits Decided which,
 * together with this task's one widened clause in ReviewerScope::constrain(),
 * is what lets a reviewer open a decided abstract they reviewed and read what
 * they wrote. Decisions themselves
 * stay changeable (ApplyDecision allows Reviewing and Decided) because a
 * presenter withdrawing in week three is a real thing that happens after the
 * letters go out.
 *
 * **Unsent letters do not block.** A conference may be marked decided while the
 * queue is still draining: the organizer clicked send, the worker is working,
 * and decision_notified_at is null for the tail. Blocking would tie a status
 * transition to a worker. unsentLetters() exists so the confirmation modal can
 * say how many, which is the honest middle.
 */
class MarkDecided
{
    /** @return list<string> empty when the conference may be marked decided */
    public function blockers(Conference $conference): array
    {
        $reasons = [];

        if ($conference->status !== ConferenceStatus::Reviewing) {
            // Not canTransitionTo(): the graph also allows Reviewing -> Closed
            // and Reviewing -> Archived, and this action is the one step.
            $reasons[] = __('decisions.mark.errors.wrong_status', [
                'status' => mb_strtolower($conference->status->getLabel()),
            ]);
        }

        $ranked = RankedSubmissions::query($conference);

        if ((clone $ranked)->doesntExist()) {
            $reasons[] = __('decisions.mark.errors.nothing_to_decide');
        }

        // The real blocker. An `accepted`, `rejected` or `waitlisted` row is
        // decided by definition - its status came from
        // Decision::submissionStatus() - so the open ones are exactly those
        // still in `submitted` or `under_review`.
        $open = (clone $ranked)
            ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])
            ->whereNull('decision')
            ->count();

        if ($open > 0) {
            $reasons[] = __('decisions.mark.errors.undecided', ['count' => $open]);
        }

        return array_values(array_unique($reasons));
    }

    /** Decided abstracts whose letter has not been queued yet. A warning, not a blocker. */
    public function unsentLetters(Conference $conference): int
    {
        return RankedSubmissions::query($conference)
            ->whereNotNull('decision')
            ->whereNull('decision_notified_at')
            ->count();
    }

    public function handle(Conference $conference, User $actor): Conference
    {
        $reasons = $this->blockers($conference);

        if ($reasons !== []) {
            throw new ConferenceNotPublishable($reasons);
        }

        $conference->forceFill(['status' => ConferenceStatus::Decided])->save();

        activity()
            ->performedOn($conference)
            ->causedBy($actor)
            ->withProperties(['unsent_letters' => $this->unsentLetters($conference)])
            ->log('conference.decided');

        return $conference->refresh();
    }
}
