<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Actions\Submissions\ComputeSubmissionScore;
use App\Enums\ReviewStatus;
use App\Exceptions\ReviewNotAcceptable;
use App\Models\Review;
use App\Models\User;
use App\Support\Reviews\ReviewerScope;

/**
 * Spec 5.4 step 4: "a submitted review can be reopened by the reviewer until
 * the review deadline."
 *
 * The answers are kept: reopening is "let me change my mind", not "start
 * again". The form stays locked - a review *was* submitted, and unlocking would
 * let the organizer edit a question another reviewer has already answered.
 */
class ReopenReview
{
    public function __construct(private readonly ComputeSubmissionScore $computeScore) {}

    /** @return list<string> empty when the review may be reopened */
    public function blockers(Review $review): array
    {
        $reasons = [];

        // The scope first, exactly as SaveReviewDraft and SubmitReview do it.
        // Without it a reviewer the organizer has REMOVED could still retract a
        // submitted review - refused everywhere else, but reachable here by
        // replaying the page's reopen action from a Livewire snapshot captured
        // before the removal: the snapshot is signed but never expires, the
        // record is #[Locked], and so mount()'s scoped resolve never runs again.
        // Retracting a review removes it from the committee's evidence.
        //
        // The relation, not a parameter: blockers() takes only the review, and
        // the owner of a review is a fact on the row, not something a caller
        // gets to assert. ReviewerScope already requires the conference to exist
        // and to be in review, so the null check below it is now type narrowing
        // rather than a rule.
        $submission = $review->submission;
        $owner = $review->reviewer;

        if ($submission === null || $owner === null || ! ReviewerScope::allows($owner, $submission)) {
            return [__('reviewer.errors.not_yours')];
        }

        if ($review->status !== ReviewStatus::Submitted) {
            $reasons[] = __('reviewer.errors.not_submitted');
        }

        $conference = $submission->conference;

        if ($conference === null) {
            return [__('reviewer.errors.not_yours')];
        }

        // acceptsReviewWrites(), not isOpenToReviewers(): the latter admits
        // Decided so a reviewer can still READ their review after the committee
        // decides. Reopening is a write, and a review changed after the decision
        // would change the evidence that decision was made on.
        if (! $conference->acceptsReviewWrites()) {
            $reasons[] = __('reviewer.errors.review_closed');
        }

        if (! $conference->reviewWindowIsOpen()) {
            $reasons[] = __('reviewer.errors.deadline_passed');
        }

        return array_values(array_unique($reasons));
    }

    public function handle(Review $review, User $reviewer): Review
    {
        if ($review->reviewer_user_id !== $reviewer->getKey()) {
            throw ReviewNotAcceptable::because(__('reviewer.errors.not_yours'));
        }

        $reasons = $this->blockers($review);

        if ($reasons !== []) {
            throw new ReviewNotAcceptable($reasons);
        }

        $review->forceFill([
            'status' => ReviewStatus::Draft,
            'submitted_at' => null,
            'reopened_at' => now(),
        ])->save();

        // A reopened review is a review the committee no longer has, so the
        // abstract's mean, spread and count all change. Same one-line hook as
        // SubmitReview, and the same reason it is not inside a transaction.
        $this->computeScore->handle($review->submission);

        activity()
            ->performedOn($review)
            ->causedBy($reviewer)
            ->log('review.reopened');

        return $review->refresh();
    }
}
