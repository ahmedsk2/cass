<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Spec 5.6: "Decisions: `accepted_oral`, `accepted_poster`, `waitlisted`,
 * `rejected`."
 *
 * Two mappings live here and nowhere else, because a copy of either is how a
 * row comes to have a decision its status disagrees with, or a decision whose
 * email nothing can render:
 *
 * - `submissionStatus()` - what `submissions.status` becomes. Both accepted
 *   cases map to the single `Accepted` status: the *status* is the author's
 *   answer ("you are in the programme") and the *decision* is the format, and
 *   collapsing them would need two more SubmissionStatus cases that every
 *   badge, filter and label in Plans 1-4 would have to learn.
 * - `templateKey()` - which of the four spec 5.9 decision templates is sent.
 *
 * Neither match() has a default arm, so a fifth case added later is an
 * UnhandledMatchError in tests/Unit/DecisionModelTest.php rather than a silent
 * wrong answer in an author's inbox.
 */
enum Decision: string implements HasColor, HasLabel
{
    case AcceptedOral = 'accepted_oral';
    case AcceptedPoster = 'accepted_poster';
    case Waitlisted = 'waitlisted';
    case Rejected = 'rejected';

    /**
     * The label is what `{{decision}}` expands to in the decision email, so it
     * is a sentence fragment an author reads ("has been **accepted for oral
     * presentation**"), not a panel word. It is deliberately NOT translated
     * through __() here, for the same reason every other enum in this codebase
     * is not: the language sweep of spec section 10 is a single backlog item
     * covering all of them, and half-translating one enum is worse than
     * translating none.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::AcceptedOral => 'Accepted for oral presentation',
            self::AcceptedPoster => 'Accepted for poster presentation',
            self::Waitlisted => 'Waitlisted',
            self::Rejected => 'Not accepted',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AcceptedOral => 'success',
            self::AcceptedPoster => 'success',
            self::Waitlisted => 'warning',
            self::Rejected => 'danger',
        };
    }

    public function submissionStatus(): SubmissionStatus
    {
        return match ($this) {
            self::AcceptedOral, self::AcceptedPoster => SubmissionStatus::Accepted,
            self::Waitlisted => SubmissionStatus::Waitlisted,
            self::Rejected => SubmissionStatus::Rejected,
        };
    }

    public function templateKey(): EmailTemplateKey
    {
        return match ($this) {
            self::AcceptedOral => EmailTemplateKey::DecisionAcceptedOral,
            self::AcceptedPoster => EmailTemplateKey::DecisionAcceptedPoster,
            self::Waitlisted => EmailTemplateKey::DecisionWaitlisted,
            self::Rejected => EmailTemplateKey::DecisionRejected,
        };
    }

    public function isAccepted(): bool
    {
        return in_array($this, [self::AcceptedOral, self::AcceptedPoster], true);
    }

    /**
     * The four cases in the order the summary strip and the send-emails modal
     * print them: the good news first, then the waiting list, then the
     * refusals. `cases()` already returns them in this order; this method
     * exists so a future reordering of the enum cannot silently reorder the
     * organizer's screen.
     *
     * @return list<self>
     */
    public static function inReportOrder(): array
    {
        return [self::AcceptedOral, self::AcceptedPoster, self::Waitlisted, self::Rejected];
    }
}
