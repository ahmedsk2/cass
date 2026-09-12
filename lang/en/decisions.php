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
    ],

];
