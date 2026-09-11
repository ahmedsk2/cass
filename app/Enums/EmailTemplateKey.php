<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The eleven keys of spec 5.9, and which of the ten placeholders each one may
 * use. The placeholder list is what the editor prints as a legend, what the
 * preview fills with sample values, and what the unit test checks a default
 * body never exceeds - an unknown placeholder is left literal at render time
 * rather than blanked, so a typo shows up in the preview instead of in an
 * author's inbox.
 */
enum EmailTemplateKey: string implements HasLabel
{
    case SubmissionReceived = 'submission_received';
    case SubmissionDraftSaved = 'submission_draft_saved';
    case ReviewerInvitation = 'reviewer_invitation';
    case ReviewerReminder = 'reviewer_reminder';
    case ReviewerOverdue = 'reviewer_overdue';
    case DecisionAcceptedOral = 'decision_accepted_oral';
    case DecisionAcceptedPoster = 'decision_accepted_poster';
    case DecisionWaitlisted = 'decision_waitlisted';
    case DecisionRejected = 'decision_rejected';
    case OrganizationApproved = 'organization_approved';
    case OrganizationRejected = 'organization_rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::SubmissionReceived => 'Abstract received',
            self::SubmissionDraftSaved => 'Draft saved',
            self::ReviewerInvitation => 'Reviewer invitation',
            self::ReviewerReminder => 'Reviewer reminder',
            self::ReviewerOverdue => 'Reviewer overdue',
            self::DecisionAcceptedOral => 'Decision: accepted for oral presentation',
            self::DecisionAcceptedPoster => 'Decision: accepted for poster',
            self::DecisionWaitlisted => 'Decision: waitlisted',
            self::DecisionRejected => 'Decision: not accepted',
            self::OrganizationApproved => 'Organization approved',
            self::OrganizationRejected => 'Organization rejected',
        };
    }

    /**
     * The two organization keys are platform-wide: they are sent once, to an
     * organization owner, by Plan 1's OrganizationApproved / OrganizationRejected
     * notifications, at a moment when no conference exists. They appear in the
     * per-conference editor (so the list of template keys an organizer sees
     * matches the spec) but cannot be overridden there, because a
     * conference-scoped override of them would never be read. Moving those two
     * notifications onto this pipeline is Plan 6.
     */
    public function isConferenceScoped(): bool
    {
        return ! in_array($this, [self::OrganizationApproved, self::OrganizationRejected], true);
    }

    /** @return list<string> */
    public function placeholders(): array
    {
        return match ($this) {
            self::SubmissionReceived => ['author_name', 'title', 'reference', 'conference', 'organization', 'deadline', 'status_link'],
            self::SubmissionDraftSaved => ['author_name', 'title', 'conference', 'organization', 'deadline', 'status_link'],
            self::ReviewerInvitation,
            self::ReviewerReminder,
            self::ReviewerOverdue => ['reviewer_name', 'conference', 'organization', 'deadline', 'review_link'],
            self::DecisionAcceptedOral,
            self::DecisionAcceptedPoster,
            self::DecisionWaitlisted,
            self::DecisionRejected => ['author_name', 'title', 'reference', 'conference', 'organization', 'decision', 'status_link'],
            self::OrganizationApproved,
            self::OrganizationRejected => ['organization', 'status_link'],
        };
    }

    /**
     * The placeholders a SUBJECT may use: every one except the two that expand
     * to a link. A rendered subject is stored verbatim in `email_logs.subject`,
     * listed and shown in the admin panel, and carried in a clear-text SMTP
     * header - so `{{status_link}}`, which expands to the author's bearer
     * credential, must not be substitutable into one. No platform default puts
     * a link in a subject, so this narrows nothing that exists today; it stops
     * an organizer typing one into the editor.
     *
     * @return list<string>
     */
    public function subjectPlaceholders(): array
    {
        return array_values(array_diff($this->placeholders(), ['status_link', 'review_link']));
    }

    /**
     * Sample values for the editor's live preview, so an organizer sees the
     * finished sentence rather than a wall of braces.
     *
     * @return array<string, string>
     */
    public function sampleValues(): array
    {
        $samples = [
            'author_name' => 'Dr Sara Al-Harbi',
            'reviewer_name' => 'Dr Omar Khan',
            'title' => 'Early mobilisation after cardiac surgery',
            'reference' => 'GPCC26-017',
            'conference' => 'Gulf Pediatric Critical Care 2026',
            'organization' => 'Gulf Pediatric Society',
            'deadline' => '3 November 2026, 23:59 (Asia/Riyadh)',
            'status_link' => 'https://cass.towardpcc.com/s/0123456789abcdef',
            'review_link' => 'https://cass.towardpcc.com/review',
            'decision' => 'Accepted for oral presentation',
        ];

        return array_intersect_key($samples, array_flip($this->placeholders()));
    }
}
