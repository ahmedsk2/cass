<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\ConferenceStatus;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Enums\SubmissionStatus;
use App\Exceptions\ConferenceNotPublishable;
use App\Models\Conference;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The `Closed -> Reviewing` half of the lifecycle ConferenceStatus has declared
 * since Plan 2. Plan 5 moves `Reviewing -> Decided`.
 *
 * Same shape as PublishConference, including the exception type: an organizer
 * meeting a transition they cannot make gets sentences, not a code, and the
 * panel action prints them.
 */
class StartReviewing
{
    /** @return list<string> empty when reviewing may start */
    public function blockers(Conference $conference): array
    {
        $reasons = [];

        if ($conference->status !== ConferenceStatus::Closed) {
            // Not canTransitionTo(): the graph also allows Closed -> Open, and
            // this action is the Closed -> Reviewing step and nothing else.
            $reasons[] = __('reviewer.start.errors.wrong_status', [
                'status' => mb_strtolower($conference->status->getLabel()),
            ]);
        }

        if ($conference->review_deadline === null) {
            $reasons[] = __('reviewer.start.errors.no_deadline');
        }

        if ($conference->reviewers()->where('status', ReviewerStatus::Active->value)->doesntExist()) {
            $reasons[] = __('reviewer.start.errors.no_reviewers');
        }

        $reviewable = $conference->submissions()
            ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value]);

        if ((clone $reviewable)->doesntExist()) {
            $reasons[] = __('reviewer.start.errors.no_submissions');
        }

        if (($conference->reviewForm()->first()?->questions()->count() ?? 0) === 0) {
            $reasons[] = __('reviewer.start.errors.no_questions');
        }

        if ($conference->review_mode === ReviewMode::Assigned) {
            $unassigned = (clone $reviewable)
                ->whereDoesntHave('reviewAssignments', fn (Builder $query): Builder => $query)
                ->count();

            if ($unassigned > 0) {
                // An unassigned abstract in assigned mode is invisible to
                // everybody, which is the worst possible failure here.
                $reasons[] = __('reviewer.start.errors.unassigned', ['count' => $unassigned]);
            }
        }

        return array_values(array_unique($reasons));
    }

    public function handle(Conference $conference, User $actor): Conference
    {
        $reasons = $this->blockers($conference);

        if ($reasons !== []) {
            throw new ConferenceNotPublishable($reasons);
        }

        $conference->forceFill(['status' => ConferenceStatus::Reviewing])->save();

        activity()->performedOn($conference)->causedBy($actor)->log('conference.reviewing');

        return $conference->refresh();
    }
}
