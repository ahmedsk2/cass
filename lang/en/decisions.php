<?php

declare(strict_types=1);

// The ranking page, the decision actions and the decision emails all land in
// this file across Tasks 4-9. This task needs exactly one key: the fallback
// SubmissionDecision::actorName() prints when `decided_by` was nulled by a
// deleted account.
return [

    'history' => [
        'former_member' => 'a former member',
    ],

    'ranking' => [
        'action' => 'Ranking and decisions',
        'title' => 'Ranking and decisions',
        'subheading' => 'Every abstract still under consideration, best score first. Scores are the weighted mean of the submitted reviews; the spread is how far apart the reviewers were.',
        'back' => 'Back to the conference',
        'not_decided' => 'Not decided',
        'not_sent' => 'Not sent',
        'empty_heading' => 'Nothing to rank yet',
        'empty_body' => 'Abstracts appear here once they have been submitted. Scores appear as reviews come in.',
        'export_csv' => 'Export CSV',
        'export_xlsx' => 'Export Excel',
        'nothing_to_export' => 'Nothing to export',
        'columns' => [
            'reference' => 'Reference',
            'title' => 'Title',
            'track' => 'Track',
            'preference' => 'Preference',
            'score' => 'Score',
            'spread' => 'Spread',
            'spread_help' => 'Sample standard deviation of the review scores. A high number means the reviewers disagreed.',
            'reviews' => 'Reviews',
            'status' => 'Status',
            'decision' => 'Decision',
            'notified' => 'Letter sent',
        ],
        'filters' => [
            'under_reviewed' => 'Not enough reviews',
            'under_reviewed_count' => 'Fewer than this many reviews',
            'under_reviewed_help' => 'This conference asks for :count reviews per abstract.',
            'under_reviewed_indicator' => 'Fewer than :count reviews',
        ],
    ],

    'summary' => [
        'heading' => 'Where this conference stands',
        'total' => 'Abstracts',
        'reviewed' => 'Reviewed',
        'unreviewed' => ':count with no reviews yet',
        'mean' => 'Mean score',
        'decided' => 'Decided',
        'undecided' => ':count still open',
    ],

    'actions' => [
        'decision' => 'Decision',
        'note' => 'Note for the committee',
        'note_help' => 'Only organizers see this. The author reads the decision letter, not this note.',
        'decide' => 'Decide',
        'decide_selected' => 'Decide selected',
        'decide_submit' => 'Save decision',
        'decide_heading' => 'Decide this abstract',
        'decide_description' => 'Nothing is emailed now. Decisions go out when you click "Send decision emails".',
        'change' => 'Change decision and resend',
        'change_heading' => 'Change a decision the author has already been told?',
        'change_description' => 'This author has already received a decision letter. Changing the decision queues a second letter with the new answer, and both are kept in the history.',
        'change_submit' => 'Change and queue a new letter',
        'bulk_heading' => 'Decide the selected abstracts',
        'bulk_description' => 'The same decision is applied to every selected abstract that can take it. Nothing is emailed now.',
        'applied' => 'Decision saved: :decision',
        'refused' => 'Not decided',
    ],

    'bulk' => [
        'title' => 'Decisions applied',
        'applied' => 'Decided :count.',
        'unchanged' => ':count already had that decision.',
        'refused' => ':count refused: :rows',
    ],

    'send' => [
        'action' => 'Send decision emails',
        'heading' => 'Send the decision letters?',
        'description' => 'One email goes to the corresponding author of every abstract that has a decision and has not been written to yet. Each letter uses this conference\'s template for that decision. This cannot be undone.',
        'counts' => 'What will be sent',
        'link_warning_label' => 'About the links',
        'link_warning' => 'Each letter carries a fresh private link to the author\'s abstract page, where the letter is also shown. Any older link that author is holding stops working - which is how this application keeps those links secret.',
        // One word, in one place, so an Arabic file changes both the field
        // label and the rule that checks it.
        'confirm_word' => 'SEND',
        'confirm_label' => 'Type :word to confirm',
        'confirm_failed' => 'Type :word exactly to send the letters.',
        'submit' => 'Send the letters',
        'title' => 'Decision letters',
        'sent' => 'Queued :count letter(s).',
        'skipped' => 'Skipped :count: :rows',
        'remaining' => ':count still to send - click again.',
        'resend' => 'Resend the letter',
        'resend_heading' => 'Send this letter again?',
        'resend_description' => 'The author gets the same decision again, rendered from the template as it stands now, and a fresh private link. Any older link they hold stops working.',
        'resent' => 'Letter sent again',
        'resent_body' => 'It is on its way to :email.',
        'nothing_sent' => 'Nothing sent',
    ],

    'infolist' => [
        'heading' => 'Decision',
        'decision' => 'Decision',
        'notified' => 'Letter sent',
        'not_sent' => 'Not sent yet',
        'score' => 'Score',
        'reviews' => 'from :count review(s)',
        'history' => 'History',
    ],

    'errors' => [
        'no_conference' => 'This abstract has no conference any more, so it cannot be decided.',
        'wrong_conference_status' => 'Decisions can only be made while a conference is under review or decided; this one is :status.',
        'draft' => 'This abstract was never submitted, so there is nothing to decide.',
        'withdrawn' => 'This abstract was withdrawn by its author and cannot be decided.',
        'already_notified' => 'This author has already been sent a decision letter. Use "Change decision and resend" if the decision really has changed.',
        'not_decided' => 'This abstract has no decision yet, so there is no letter to send.',
        'no_history' => 'This abstract has a decision with no history behind it. Decide it again before sending a letter.',
        'no_author_email' => 'This abstract has no corresponding author with a usable email address.',
        'conference_not_sending' => 'This conference is :status and is off the public site, so the link in the letter would not open. Letters cannot be sent from it.',
        'already_sending' => 'This letter is already being sent, or the decision changed while the run was under way.',
    ],

    'mark' => [
        'action' => 'Mark decisions final',
        'heading' => 'Mark this conference decided?',
        'description' => 'The conference moves to "Decisions sent". Reviewers can still read their reviews but can no longer write them, and you can still correct a decision and send a new letter.',
        'unsent_warning' => ':count decision letter(s) have not been sent yet. You can send them from the ranking page before or after this.',
        'not_ready' => 'This conference is not ready to be marked decided',
        'done' => 'Decisions are final',
        'errors' => [
            'wrong_status' => 'Only a conference that is under review can be marked decided; this one is :status.',
            'nothing_to_decide' => 'This conference has no abstracts under consideration.',
            'undecided' => ':count abstract(s) still have no decision. Decide them, or the authors are waiting for an email that will never arrive.',
        ],
    ],

    'conference' => [
        'heading' => 'Decisions',
        'open' => 'Ranking and decisions',
        'decided' => 'Decided',
        'notified' => 'Letters sent',
        'undecided' => 'Still open',
        'breakdown' => 'By decision',
        'none' => 'No decisions yet',
    ],

];
