<?php

declare(strict_types=1);

namespace App\Actions\Reviewers;

use App\Enums\ReviewerStatus;
use App\Models\ConferenceReviewer;
use App\Models\ReviewAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Spec section 9 names reviewer removals as an audited event.
 *
 * Assignments go, reviews stay. A removed reviewer is not going to review, so
 * an assignment left behind would make Task 8's coverage summary and Task 9's
 * balance both read "this abstract has two reviewers" when it has one. A
 * submitted review is the record of what the committee was told and survives
 * its author's removal; a draft survives because re-inviting the same person is
 * one click and their work should still be there.
 */
class RemoveConferenceReviewer
{
    public function handle(ConferenceReviewer $reviewer, User $actor): ConferenceReviewer
    {
        return DB::transaction(function () use ($reviewer, $actor): ConferenceReviewer {
            $dropped = ReviewAssignment::query()
                ->where('reviewer_user_id', $reviewer->user_id)
                ->whereHas(
                    'submission',
                    fn (Builder $query): Builder => $query->where('conference_id', $reviewer->conference_id),
                )
                ->delete();

            $reviewer->forceFill([
                'status' => ReviewerStatus::Removed,
                'removed_at' => now(),
            ])->save();

            activity()
                ->performedOn($reviewer)
                ->causedBy($actor)
                ->withProperties([
                    'conference_id' => $reviewer->conference_id,
                    'user_id' => $reviewer->user_id,
                    'assignments_removed' => $dropped,
                ])
                ->log('reviewer.removed');

            return $reviewer;
        });
    }
}
