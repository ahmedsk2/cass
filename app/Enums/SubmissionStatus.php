<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The whole lifecycle, written once. Plan 3 drives only the first three:
 * Draft, Submitted and Withdrawn. Plan 4 moves Submitted -> UnderReview and
 * Plan 5 moves UnderReview -> Accepted / Rejected / Waitlisted. Declaring them
 * now means those plans add behaviour instead of migrating an enum that is
 * already in a `status` column and in three indexes.
 */
enum SubmissionStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Withdrawn = 'withdrawn';
    case UnderReview = 'under_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Waitlisted = 'waitlisted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Withdrawn => 'Withdrawn',
            self::UnderReview => 'Under review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Not accepted',
            self::Waitlisted => 'Waitlisted',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'info',
            self::Withdrawn => 'danger',
            self::UnderReview => 'warning',
            self::Accepted => 'success',
            self::Rejected => 'danger',
            self::Waitlisted => 'warning',
        };
    }

    /**
     * The author may still edit and withdraw. A withdrawn abstract stays
     * withdrawn, and once reviewing has started the text is frozen because
     * reviewers are already scoring it.
     */
    public function isOpenToAuthor(): bool
    {
        return in_array($this, [self::Draft, self::Submitted], true);
    }

    /**
     * The organizer's reach is one status wider, and the difference is the
     * honest one: the *text* freezes once reviewers are scoring it, but taking
     * an abstract off the programme is exactly what an organizer has to be able
     * to do when the author emails instead of clicking - and the first submitted
     * review writes `under_review`, so without this a single reviewer's Submit
     * would end every route to withdrawal for good.
     *
     * The three decided statuses are in for the same reason one status further
     * on, and it is the case Plan 5's own "a decision can still be changed once
     * the conference is `decided`" argument is built on: an accepted presenter
     * pulls out and the waiting list moves up. Without them the row stays on
     * the ranking, in `decisionCounts()` and in both exports with no route out
     * but a hand-written UPDATE. The history is append-only and survives;
     * `decisionLetter()`'s own Withdrawn guard stops the old letter being shown.
     */
    public function isOrganizerWithdrawable(): bool
    {
        return $this->isOpenToAuthor()
            || in_array($this, [self::UnderReview, self::Accepted, self::Rejected, self::Waitlisted], true);
    }

    /**
     * The author has done their part and the organizers have it: under review,
     * or decided. The public status page shows the decision letter for these
     * once it has been sent, and a neutral "we are handling it" line until then.
     *
     * This was `isDrivenInPlan3()`, which named the plan that did not write
     * these statuses rather than what they mean - and from Plan 5 on it is
     * false for every case it was written to describe.
     */
    public function isWithOrganizers(): bool
    {
        return in_array(
            $this,
            [self::UnderReview, self::Accepted, self::Rejected, self::Waitlisted],
            true,
        );
    }
}
