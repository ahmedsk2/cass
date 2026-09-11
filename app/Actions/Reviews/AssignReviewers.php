<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Exceptions\ReviewNotAcceptable;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Spec 5.5: the manual half. Sets the whole reviewer set of one submission, so
 * the caller says what the answer should be rather than what to change - which
 * is what makes the picker a multi-select and makes saving the same set twice
 * write nothing.
 */
class AssignReviewers
{
    /**
     * @param  list<int>  $reviewerUserIds
     * @return list<string> empty when the set may be saved
     */
    public function blockers(Submission $submission, array $reviewerUserIds): array
    {
        $reasons = [];
        $conference = $submission->conference;

        if ($conference === null) {
            return [__('reviewer.errors.not_yours')];
        }

        if ($conference->review_mode !== ReviewMode::Assigned) {
            // In open pool every active reviewer already sees every abstract,
            // so an assignment row would change nothing and mislead the
            // coverage summary.
            $reasons[] = __('reviewer.assign.errors.open_pool');
        }

        if (! $submission->isReviewable()) {
            $reasons[] = __('reviewer.assign.errors.not_reviewable');
        }

        $eligible = $conference->reviewers()
            ->where('status', ReviewerStatus::Active->value)
            ->pluck('user_id')
            ->all();

        foreach ($reviewerUserIds as $id) {
            if (! in_array($id, $eligible, true)) {
                $reasons[] = __('reviewer.assign.errors.not_a_reviewer');
                break;
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  list<int>  $reviewerUserIds
     * @return array{added: int, removed: int}
     */
    public function handle(Submission $submission, array $reviewerUserIds, User $actor): array
    {
        $reasons = $this->blockers($submission, $reviewerUserIds);

        if ($reasons !== []) {
            throw new ReviewNotAcceptable($reasons);
        }

        $wanted = array_values(array_unique(array_map('intval', $reviewerUserIds)));

        return DB::transaction(function () use ($submission, $wanted, $actor): array {
            $current = $submission->reviewAssignments()->pluck('reviewer_user_id')->map('intval')->all();

            $toAdd = array_values(array_diff($wanted, $current));
            $toRemove = array_values(array_diff($current, $wanted));

            foreach ($toAdd as $id) {
                $assignment = new ReviewAssignment;
                $assignment->forceFill([
                    'submission_id' => $submission->getKey(),
                    'reviewer_user_id' => $id,
                    'assigned_by' => $actor->getKey(),
                    'assigned_at' => now(),
                ])->save();
            }

            if ($toRemove !== []) {
                // The reviews are deliberately untouched: a submitted review is
                // evidence that outlives its assignment, and a draft simply
                // leaves the queue until the same person is assigned again.
                $submission->reviewAssignments()->whereIn('reviewer_user_id', $toRemove)->delete();
            }

            if ($toAdd !== [] || $toRemove !== []) {
                // Spec section 9 names assignment changes as an audited event.
                activity()
                    ->performedOn($submission)
                    ->causedBy($actor)
                    ->withProperties(['added' => $toAdd, 'removed' => $toRemove])
                    ->log('review.assignments_changed');
            }

            return ['added' => count($toAdd), 'removed' => count($toRemove)];
        });
    }
}
