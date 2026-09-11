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

    'page' => [
        'title' => 'Reviewers',
        'subheading' => 'Who reviews the abstracts sent to this conference, and who has been invited but has not accepted yet.',
    ],

    'columns' => [
        'name' => 'Reviewer',
        'affiliation' => 'Affiliation',
        'state' => 'Status',
        'since' => 'Reviewing since',
    ],

    'state' => [
        'invited' => 'Invited',
        'expired' => 'Invitation expired',
    ],

    'fields' => [
        'name' => 'Name',
        'email' => 'Email address',
        'affiliation' => 'Affiliation',
        'affiliation_help' => 'Optional. Used to keep a reviewer away from abstracts from their own institution.',
        'list' => 'One reviewer per line',
        'list_help' => 'Either "Dr Omar Khan <omar@example.org>" or just the address. A comma, a semicolon or a tab between a name and an address works too.',
    ],

    'actions' => [
        'page_link' => 'Reviewers',
        'back' => 'Back to the conference',
        'invite' => 'Invite a reviewer',
        'invite_heading' => 'Invite a reviewer',
        'invite_list' => 'Invite a list',
        'invite_list_heading' => 'Invite several reviewers at once',
        'invite_list_description' => 'Paste one reviewer per line. Every line is reported back: invited, already reviewing, or not understood.',
        'remove' => 'Remove',
        'remove_heading' => 'Remove :name from this conference?',
        'remove_description' => 'They lose access to these abstracts straight away, and any abstract assigned to them becomes unassigned. Reviews they have already written are kept.',
        'reinvite' => 'Invite again',
        'reinvite_heading' => 'Invite this reviewer again?',
        'resend' => 'Resend',
        'resend_heading' => 'Send the invitation again?',
        'resend_description' => 'A new link is emailed. **Any link they already have stops working.**',
        'revoke' => 'Withdraw',
        'revoke_heading' => 'Withdraw this invitation?',
        'revoke_description' => 'The link stops working. If they follow it they are told the invitation was withdrawn.',
    ],

    'notices' => [
        'invited' => 'Invitation sent',
        'invited_body' => 'A link is on its way to :email.',
        'list_done' => ':count invitation(s) sent',
        'removed' => 'Reviewer removed',
        'resent' => 'Invitation sent again',
        'revoked' => 'Invitation withdrawn',
        'refused' => 'Nothing changed',
        'draft_saved' => 'Draft saved',
        'submitted' => 'Review submitted',
        'reopened' => 'Review reopened',
    ],

    'list' => [
        'bad_line' => 'Line :line could not be read: ":text"',
        'duplicate' => ':email appears more than once; it was invited once.',
        'too_many' => 'Only the first :max reviewers on the list were invited. Paste the rest as a second list.',
    ],

    'errors' => [
        'not_allowed' => 'Only a member of this organization can invite reviewers.',
        'bad_email' => 'That does not look like an email address.',
        'already_reviewing' => ':email is already reviewing this conference.',
        'send_limit' => 'Too many invitations have been sent from this account in the last hour. Try again later.',
        'not_yours' => 'This abstract is not in your queue.',
        'already_submitted' => 'You have already submitted this review. Reopen it if you want to change it.',
        'not_submitted' => 'This review has not been submitted, so there is nothing to reopen.',
        'review_closed' => 'This conference is no longer open for reviewing.',
        'deadline_passed' => 'The review deadline has passed.',
        'answer' => 'Please answer ":prompt". :detail',
        'detail_scale' => 'Choose a number between :min and :max.',
        'detail_choice' => 'Choose one of: :choices.',
        'detail_boolean' => 'Choose yes or no.',
        'detail_text' => 'Write something, or ask the organizers to make this question optional.',
    ],

    'mail' => [
        'no_deadline' => 'a date the organizers will confirm',
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
            'state' => 'Your review',
        ],
        'state' => [
            'not_started' => 'Not started',
            'draft' => 'Draft',
            'submitted' => 'Submitted',
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
        'your_review' => 'Your review',
        'save_draft' => 'Save draft',
        'submit' => 'Submit review',
        'submit_heading' => 'Submit this review?',
        'submit_description' => 'The organizers can see it straight away. You can reopen it and change it until the review deadline.',
        'reopen' => 'Reopen',
        'reopen_heading' => 'Reopen this review so you can change it?',
        'submitted_notice' => 'You have submitted this review. Reopen it if you want to change anything.',
        'deadline_passed' => 'The review deadline has passed, so a submitted review can no longer be changed.',
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
        'shortfall' => ':label has :have of :target reviewers; there was nobody left without a conflict.',
        'preview_empty' => 'Every abstract already has the reviewers it needs, so there is nothing to assign.',
        'preview_summary' => 'This will add :count assignments across :submissions abstracts.',
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
            'auto' => 'Auto-assign',
            'auto_heading' => 'Assign reviewers automatically',
            'auto_description' => 'Each abstract is given the reviewers with the fewest assignments so far, skipping anyone who shares an author\'s institution or email domain. Assignments you made by hand are kept.',
            'auto_confirm' => 'Save these assignments',
        ],
        'notices' => [
            'saved' => 'Assignments saved',
            'auto_done' => ':count assignments made',
        ],
        'errors' => [
            'open_pool' => 'This conference is in open pool mode, where every reviewer already sees every abstract. Switch it to assigned review first.',
            'not_reviewable' => 'Only a submitted abstract can be assigned.',
            'not_a_reviewer' => 'One of those people is not an active reviewer of this conference.',
        ],
    ],

    'remind' => [
        'action' => 'Remind reviewers',
        'heading' => 'Email everyone who is behind?',
        'description' => 'Only reviewers with abstracts they have not reviewed yet are emailed. You can do this again after 12 hours.',
        'sent' => 'Reminder sent to :count reviewer(s)',
        'errors' => [
            'not_reviewing' => 'This conference is not open for reviewing.',
            'too_soon' => 'A reminder was sent recently. You can send another after :hours hours.',
            'nobody_behind' => 'Every reviewer has finished, so there is nobody to remind.',
        ],
    ],

];
