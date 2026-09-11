<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewStatus;
use App\Exceptions\ReviewNotAcceptable;
use App\Models\Review;
use App\Models\User;

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
    /** @return list<string> empty when the review may be reopened */
    public function blockers(Review $review): array
    {
        $reasons = [];

        if ($review->status !== ReviewStatus::Submitted) {
            $reasons[] = __('reviewer.errors.not_submitted');
        }

        $conference = $review->submission?->conference;

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

        activity()
            ->performedOn($review)
            ->causedBy($reviewer)
            ->log('review.reopened');

        return $review->refresh();
    }
}
