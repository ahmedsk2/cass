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

    /** Statuses this plan actually writes; the rest render read-only. */
    public function isDrivenInPlan3(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::Withdrawn], true);
    }
}
