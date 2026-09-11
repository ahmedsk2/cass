<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\SubmissionStatus;
use App\Exceptions\SubmissionNotAcceptable;
use App\Models\Submission;
use App\Models\User;

/**
 * Withdrawal is the author's own exit and the organizer's tool for an author
 * who emailed instead of clicking. Nothing is deleted: the reference and
 * `submitted_at` stay, because an organizer who has already printed a
 * programme needs `GPCC26-017` to keep meaning something.
 *
 * `$actor` is the organizer when the panel calls it, and null when the author
 * does it from their status page (there is no account to attribute it to). The
 * activity-log entry records the difference, which is what a support question
 * six weeks later actually needs - and it is also what decides whether the
 * submission window applies, because the two callers are not the same person.
 */
class WithdrawSubmission
{
    public function handle(Submission $submission, ?User $actor = null): Submission
    {
        if ($submission->status === SubmissionStatus::Withdrawn) {
            throw SubmissionNotAcceptable::because('This abstract has already been withdrawn.');
        }

        // The organizer reaches one status further than the author: an abstract
        // whose first review has landed is `under_review`, and an author who
        // emails "please pull it" then still has to be taken off the programme
        // by somebody. The author's own path keeps stopping at `submitted`,
        // because the text is frozen once reviewers are scoring it. The
        // assignments and reviews are deliberately left alone - ReviewerScope
        // and reviewableSubmissions both exclude a withdrawn abstract already.
        $withdrawable = $actor === null
            ? $submission->status->isOpenToAuthor()
            : $submission->status->isOrganizerWithdrawable();

        if (! $withdrawable) {
            throw SubmissionNotAcceptable::because(
                'An abstract that is '.strtolower($submission->status->getLabel()).' cannot be withdrawn here. Contact the organizers.',
            );
        }

        // The author's own withdrawal closes with the submission window; the
        // organizer's (SubmissionActions::withdraw, which passes an actor)
        // deliberately does not - an author who emails after the deadline still
        // has to be taken off the programme by hand. Note this is the *model's*
        // rule, not the enum's: SubmissionStatus::isOpenToAuthor() above is
        // true for Draft and Submitted whatever the date, which is why a check
        // on the enum alone let a token holder withdraw after the deadline.
        if ($actor === null && ! $submission->conference->acceptsSubmissions()) {
            throw SubmissionNotAcceptable::because(
                'The submission window for this conference has closed. Contact the organizers.',
            );
        }

        $submission->forceFill([
            'status' => SubmissionStatus::Withdrawn,
            'withdrawn_at' => now(),
        ])->save();

        $activity = activity()->performedOn($submission);

        if ($actor !== null) {
            $activity = $activity->causedBy($actor);
        }

        $activity
            ->withProperties(['by' => $actor === null ? 'author' : 'organizer'])
            ->log('submission.withdrawn');

        return $submission->refresh();
    }
}
