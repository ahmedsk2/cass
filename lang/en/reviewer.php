<?php

declare(strict_types=1);

/*
 * The reviewer panel's own views, and the reviewer half of /invite/{token}.
 */

return [

    'invite' => [
        'headline' => ':organization has invited you to review abstracts for :conference.',
    ],

    // The organizer-facing half of the blind-review rule: this key labels the
    // toggle CustomFieldsRelationManager gains in Step 10, and Task 6 reads the
    // column it sets.
    'review' => [
        'hide_from_reviewers' => 'Hide this answer from reviewers',
        'hide_from_reviewers_help' => 'Turn this on for anything that identifies an author - institution, department, funding source. In a blind conference the answer is not shown on the review page.',
    ],

    'switch' => [
        'to_reviewer' => 'Switch to reviewing',
        'to_organizer' => 'Switch to organizing',
    ],

    // Two of these sentences deliberately do NOT promise an email at the moment
    // reviewing opens. Spec 5.4 step 5 defines reviewer mail as reminders at 7,
    // 3 and 1 days before the review deadline and once after it, and spec 5.9
    // fixes the reviewer key list at three - so there is no "reviewing has
    // started" send, and adding one would fire a *reminder* with a deadline
    // weeks away, outside ReminderSchedule and outside the reviewer_reminders
    // idempotency key. The copy says what the application actually does.
    'dashboard' => [
        'title' => 'Your reviewing',
        'empty_heading' => 'Nothing to review yet',
        'empty_body' => 'When a conference you review opens its abstracts for review, it appears here.',
        'deadline' => 'Reviews are due by :date (:timezone).',
        'no_deadline' => 'The organizers have not set a review deadline yet.',
        'not_started' => 'Reviewing has not started for this conference yet. Check back after the submission deadline; we email reminders as the review deadline approaches.',
    ],

];
