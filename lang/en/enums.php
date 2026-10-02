<?php

declare(strict_types=1);

/*
 * The label of every enum case a person reads - in a badge, a filter, a select
 * or a spreadsheet cell. One group per enum, named after the class in
 * snake_case, keyed by the case's stored value, so the key is predictable
 * from the database row and tests/Feature/LanguageCoverageTest.php can derive
 * it. Spec section 10: Arabic is a copy of this file.
 *
 * Decision is NOT here. Its label is `{{decision}}`'s value in every decision
 * letter, so it has to follow the letter's language rather than the panel's;
 * it moves with the bilingual templates of spec section 14 (backlog).
 */

return [

    'conference_status' => [
        'draft' => 'Draft',
        'open' => 'Open for submissions',
        'closed' => 'Submissions closed',
        'reviewing' => 'Under review',
        'decided' => 'Decisions sent',
        'archived' => 'Archived',
    ],

    'custom_field_type' => [
        'text' => 'Single line of text',
        'textarea' => 'Paragraph',
        'select' => 'Choose one from a list',
        'checkbox' => 'Yes / no checkbox',
        'number' => 'Number',
    ],

    'email_log_status' => [
        'queued' => 'Queued',
        'sent' => 'Sent',
        'failed' => 'Failed',
    ],

    'email_template_key' => [
        'submission_received' => 'Abstract received',
        'submission_draft_saved' => 'Draft saved',
        'reviewer_invitation' => 'Reviewer invitation',
        'reviewer_reminder' => 'Reviewer reminder',
        'reviewer_overdue' => 'Reviewer overdue',
        'decision_accepted_oral' => 'Decision: accepted for oral presentation',
        'decision_accepted_poster' => 'Decision: accepted for poster',
        'decision_waitlisted' => 'Decision: waitlisted',
        'decision_rejected' => 'Decision: not accepted',
        'organization_approved' => 'Organization approved',
        'organization_rejected' => 'Organization rejected',
        'organization_suspended' => 'Organization suspended',
    ],

    'invitation_status' => [
        'pending' => 'Invited',
        'accepted' => 'Accepted',
        'expired' => 'Expired',
        'revoked' => 'Withdrawn',
    ],

    'organization_role' => [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'member' => 'Member',
    ],

    'organization_status' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'suspended' => 'Suspended',
    ],

    'organization_type' => [
        'society' => 'Scientific society or association',
        'hospital' => 'Hospital or health cluster',
        'university' => 'University or college',
        'company' => 'Company or agency',
        'other' => 'Other',
    ],

    'poster_size' => [
        'a4' => 'A4 poster (210 x 297 mm)',
        'a3' => 'A3 poster (297 x 420 mm)',
    ],

    'presentation_preference' => [
        'oral' => 'Oral presentation',
        'poster' => 'Poster',
        'either' => 'Either is fine',
    ],

    'reminder_threshold' => [
        'days_7' => '7 days before the deadline',
        'days_3' => '3 days before the deadline',
        'days_1' => '1 day before the deadline',
        'overdue' => 'After the deadline',
    ],

    'review_mode' => [
        'open_pool' => 'Open pool - every reviewer sees every abstract',
        'assigned' => 'Assigned - each abstract goes to named reviewers',
    ],

    'review_question_type' => [
        'likert' => 'Rating scale',
        'text' => 'Free text comment',
        'boolean' => 'Yes / no',
        'select' => 'Choose one from a list',
    ],

    'review_status' => [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
    ],

    'reviewer_status' => [
        'active' => 'Active',
        'removed' => 'Removed',
    ],

    'submission_status' => [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'withdrawn' => 'Withdrawn',
        'under_review' => 'Under review',
        'accepted' => 'Accepted',
        'rejected' => 'Not accepted',
        'waitlisted' => 'Waitlisted',
    ],

];
