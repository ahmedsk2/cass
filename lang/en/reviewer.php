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

    'queue' => [
        'title' => 'Abstracts to review',
        'model' => 'abstract',
        'model_plural' => 'abstracts',
        'empty_heading' => 'Nothing waiting',
        'empty_body' => 'When the organizers open a conference for review, the abstracts you can review appear here.',
        'columns' => [
            'reference' => 'Reference',
            'title' => 'Title',
            'conference' => 'Conference',
            'track' => 'Track',
            'preference' => 'Preference',
            'files' => 'Files',
        ],
        'actions' => [
            'review' => 'Open',
        ],
    ],

    // One group, two audiences. `hide_from_reviewers*` is the organizer-facing
    // half of the blind-review rule - it labels the toggle
    // CustomFieldsRelationManager gained in Task 1 Step 10 - and everything
    // below it is the reviewer's own review page, which reads the column that
    // toggle sets. A second `review` key would silently replace this one, so
    // they share it.
    'review' => [
        'hide_from_reviewers' => 'Hide this answer from reviewers',
        'hide_from_reviewers_help' => 'Turn this on for anything that identifies an author - institution, department, funding source. In a blind conference the answer is not shown on the review page.',
        'abstract' => 'Abstract',
        'authors' => 'Authors',
        'blind_notice' => 'This conference is reviewed blind, so the authors and their affiliations are hidden from you. File names are hidden for the same reason.',
        'extra' => 'Additional answers',
        'files' => 'Files',
        'track' => 'Track',
        'preference' => 'Presentation preference',
        'phone' => 'Contact phone',
        'words' => ':count words',
        'yes' => 'Yes',
        'no' => 'No',
        'back' => 'Back to the queue',
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
        'open_queue' => 'Open the abstracts',
    ],

    // Three keys the Reviewers page (Task 4) and the review page (Task 7) also
    // reach for. They land here with the first page that needs them; those
    // tasks add their own keys to the same three groups.
    'actions' => [
        'back' => 'Back to the conference',
    ],

    'notices' => [
        'refused' => 'Nothing changed',
    ],

    'errors' => [
        'not_yours' => 'This abstract is not in your queue.',
    ],

    'assign' => [
        'page_link' => 'Assignments',
        'title' => 'Assignments',
        'subheading' => ':covered of :submissions abstracts have all :target reviewers.',
        'coverage' => 'Coverage',
        'coverage_target' => 'Target: :count reviewers per abstract',
        'coverage_covered' => 'Fully covered: :count of :total',
        'coverage_under' => 'Still short of reviewers: :count',
        'coverage_unassigned' => 'With no reviewer at all: :count',
        'none' => 'Nobody yet',
        'empty_heading' => 'No abstracts to assign',
        'empty_body' => 'Abstracts appear here once authors have submitted them.',
        'columns' => [
            'reference' => 'Reference',
            'title' => 'Title',
            'track' => 'Track',
            'count' => 'Reviewers',
            'reviewers' => 'Assigned to',
        ],
        'filters' => [
            'under_target' => 'Still short of reviewers',
        ],
        'fields' => [
            'reviewers' => 'Reviewers',
            'reviewers_help' => 'This conference aims for :target reviewers per abstract. Only reviewers who have accepted their invitation are listed.',
        ],
        'actions' => [
            'assign' => 'Assign',
            'assign_heading' => 'Who reviews :reference?',
        ],
        'notices' => [
            'saved' => 'Assignments saved',
        ],
        'errors' => [
            'open_pool' => 'This conference is in open pool mode, where every reviewer already sees every abstract. Switch it to assigned review first.',
            'not_reviewable' => 'Only a submitted abstract can be assigned.',
            'not_a_reviewer' => 'One of those people is not an active reviewer of this conference.',
        ],
    ],

];
