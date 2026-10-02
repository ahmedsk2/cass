<?php

declare(strict_types=1);

namespace App\Actions\Reviewers;

use App\Exceptions\MemberChangeRefused;
use App\Models\ConferenceReviewer;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * A reviewer corrects their own `conference_reviewers.affiliation` - the value
 * an organizer typed when inviting them, copied on accept by
 * ReviewerInvitation::grantTo(), and the only reviewer-side input to spec 5.5's
 * affiliation conflict rule (AutoAssignReviewers::conflicts()).
 *
 * Per conference, because that is where the column lives: two organizations
 * may know the same person by two different institutions, and each conflict
 * check reads its own row.
 *
 * Existing assignments are left exactly as they are. AutoAssignReviewers never
 * removes an assignment, and AssignReviewers never checks conflicts at all, so
 * an affiliation that now matches an author's on an abstract already assigned
 * changes nothing until the organizer acts - which is today's behaviour for a
 * conflict the organizer missed, and stays so until the owner decides
 * otherwise.
 */
class UpdateReviewerAffiliation
{
    /**
     * `conference_reviewers.affiliation` is a VARCHAR(255), and the organizer's
     * invite form caps the same field at 255 (ConferenceReviewers' `invite`
     * action). One limit for one column, whoever types it.
     */
    public const MAX_LENGTH = 255;

    /** @return list<string> empty when the change may be made */
    public function blockers(ConferenceReviewer $reviewer, ?string $affiliation, User $actor): array
    {
        $reasons = [];

        // The policy is the rule - the actor's own row, while they are active
        // - and it is asked here rather than only in the page, so a second
        // caller cannot forget it. When it says no, say which half failed: a
        // reviewer removed while the modal was open deserves a true sentence.
        if (Gate::forUser($actor)->denies('updateAffiliation', $reviewer)) {
            $reasons[] = $reviewer->user_id === $actor->getKey()
                ? __('reviewer.errors.affiliation_removed')
                : __('reviewer.errors.affiliation_not_yours');
        }

        if (mb_strlen((string) self::normalise($affiliation)) > self::MAX_LENGTH) {
            $reasons[] = __('reviewer.errors.affiliation_too_long', ['max' => self::MAX_LENGTH]);
        }

        return $reasons;
    }

    public function handle(ConferenceReviewer $reviewer, ?string $affiliation, User $actor): ConferenceReviewer
    {
        $reasons = $this->blockers($reviewer, $affiliation, $actor);

        if ($reasons !== []) {
            throw new MemberChangeRefused($reasons);
        }

        $from = $reviewer->affiliation;
        $to = self::normalise($affiliation);

        // A save that changes nothing writes nothing and logs nothing, so the
        // activity log answers "when did this reviewer's institution change"
        // rather than "when did they press Save".
        if ($from === $to) {
            return $reviewer;
        }

        $reviewer->forceFill(['affiliation' => $to])->save();

        activity()
            ->performedOn($reviewer)
            ->causedBy($actor)
            ->withProperties([
                'conference_id' => $reviewer->conference_id,
                'from' => $from,
                'to' => $to,
            ])
            ->log('reviewer.affiliation_changed');

        return $reviewer;
    }

    /**
     * Trimmed, and empty means "none". Nothing cleverer: the conflict rule
     * already folds case, punctuation and runs of whitespace when it compares
     * (AutoAssignReviewers::normalise()), and the organizer reads this value
     * back on the Reviewers page exactly as the reviewer typed it.
     */
    public static function normalise(?string $affiliation): ?string
    {
        $value = trim((string) $affiliation);

        return $value === '' ? null : $value;
    }
}
