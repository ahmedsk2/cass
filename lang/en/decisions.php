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

];
