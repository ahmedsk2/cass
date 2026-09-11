<?php

declare(strict_types=1);

/*
 * The reviewer panel's own views, and the two lines of /invite/{token} where a
 * reviewer invitation reads differently from a team invitation.
 */

return [

    'invite' => [
        'headline' => ':organization has invited you to review abstracts for :conference.',
        'accept' => 'Accept and start reviewing',
    ],

    // The organizer-facing half of the blind-review rule: this key labels the
    // toggle CustomFieldsRelationManager gains in Task 1 Step 10, and Task 6
    // reads the column it sets.
    'review' => [
        'hide_from_reviewers' => 'Hide this answer from reviewers',
        'hide_from_reviewers_help' => 'Turn this on for anything that identifies an author - institution, department, funding source. In a blind conference the answer is not shown on the review page.',
    ],

];
