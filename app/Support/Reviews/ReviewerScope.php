<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\ConferenceStatus;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * **The** definition of what a reviewer may see (spec section 4, "assigned or
 * pool only"; spec 5.4 step 3).
 *
 * Four things ask this question - the queue resource, the review page's record
 * binding, SubmissionFilePolicy, and Task 10's reminder command, which has to
 * know who still has outstanding work - and four copies of a whereHas is how
 * one rule becomes four rules. Everything here is a query the database can run;
 * nothing loads a collection to filter it in PHP, because a conference with 500
 * abstracts is the size spec section 10 budgets for.
 *
 * The rule:
 *   1. the conference is `reviewing` or `decided`;
 *   2. the reviewer has an *active* conference_reviewers row for it;
 *   3. the submission is `submitted` or `under_review` - or it has been decided
 *      (`accepted`, `rejected`, `waitlisted`) and THIS reviewer has a review of
 *      it, so a reviewer can still read back what they wrote;
 *   4. and either the conference is open_pool, or a review_assignments row
 *      names this reviewer.
 *
 * Filament's tenancy global scope on Conference is inert here: it returns early
 * unless the *current panel* is the one that registered it and a tenant is set
 * (fact 40), and the reviewer panel has no tenancy at all.
 */
final class ReviewerScope
{
    /**
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public static function constrain(Builder $query, User $reviewer, ?Conference $conference = null): Builder
    {
        $query
            ->where(function (Builder $status) use ($reviewer): void {
                $status
                    ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])
                    // Plan 5 writes accepted/rejected/waitlisted onto a decided
                    // abstract, while the conference is still `reviewing`.
                    // Without this arm the abstract - and the reviewer's own
                    // submitted review of it - leaves every read path the moment
                    // the committee decides, and by the time MarkDecided can
                    // succeed EVERY abstract has a decision, so the reviewer's
                    // queue is empty and every review page a 404 on the very
                    // screen that says otherwise. Scoped to the reviewer's OWN
                    // reviews, so a decided abstract nobody reviewed stays out
                    // of the pool. Writes are still refused by
                    // Conference::acceptsReviewWrites().
                    ->orWhere(fn (Builder $decided): Builder => $decided
                        ->whereIn('status', [
                            SubmissionStatus::Accepted->value,
                            SubmissionStatus::Rejected->value,
                            SubmissionStatus::Waitlisted->value,
                        ])
                        ->whereHas('reviews', fn (Builder $reviews): Builder => $reviews
                            ->where('reviewer_user_id', $reviewer->getKey())));
            })
            ->whereHas('conference', function (Builder $conferenceQuery) use ($reviewer): void {
                $conferenceQuery
                    ->whereIn('status', [ConferenceStatus::Reviewing->value, ConferenceStatus::Decided->value])
                    ->whereHas('reviewers', fn (Builder $reviewers): Builder => $reviewers
                        ->where('user_id', $reviewer->getKey())
                        ->where('status', ReviewerStatus::Active->value));
            })
            ->where(function (Builder $either) use ($reviewer): void {
                $either
                    ->whereHas('conference', fn (Builder $c): Builder => $c->where('review_mode', ReviewMode::OpenPool->value))
                    ->orWhereHas('reviewAssignments', fn (Builder $a): Builder => $a->where('reviewer_user_id', $reviewer->getKey()));
            });

        if ($conference !== null) {
            $query->where('conference_id', $conference->getKey());
        }

        return $query;
    }

    /** @return Builder<Submission> */
    public static function submissions(User $reviewer, ?Conference $conference = null): Builder
    {
        return self::constrain(Submission::query(), $reviewer, $conference);
    }

    /**
     * One row. A fresh query rather than a read of the in-memory model, because
     * the answer depends on the conference's status and the reviewer's row,
     * neither of which the caller reliably has loaded.
     */
    public static function allows(User $reviewer, Submission $submission): bool
    {
        return self::submissions($reviewer)->whereKey($submission->getKey())->exists();
    }

    /**
     * The conferences this person reviews, in whatever state - the dashboard
     * lists a conference that has not started reviewing yet, with a sentence
     * saying so.
     *
     * @return Builder<Conference>
     */
    public static function conferences(User $reviewer): Builder
    {
        return Conference::query()
            ->whereHas('reviewers', fn (Builder $reviewers): Builder => $reviewers
                ->where('user_id', $reviewer->getKey())
                ->where('status', ReviewerStatus::Active->value));
    }
}
