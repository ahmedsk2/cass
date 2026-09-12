<?php

declare(strict_types=1);

/*
 * The platform-admin panel: the read-only screens of spec section 4 and the
 * hard purge of section 3. Spec section 10: Arabic is a copy of this file.
 */

return [
    'purge' => [
        'action' => 'Purge permanently',
        'conference_heading' => 'Purge :name?',
        'organization_heading' => 'Purge :name and everything in it?',
        'intro' => 'This deletes the rows below and the uploaded files behind them. It cannot be undone and there is no restore — the only copy afterwards is the nightly backup.',
        'nothing' => 'There is nothing left to remove.',
        'counts_heading' => 'What will be deleted',
        'files' => 'uploaded files',
        'audit_note' => 'The audit log is kept: the record of who did what, including this purge, is not erased.',
        'confirm_label' => 'Type :word to confirm',
        'confirm_help' => 'That is the slug from the URL. Typing it is the confirmation.',
        'confirm_mismatch' => 'That is not :word.',
        'submit' => 'Purge permanently',
        'done' => 'Purged :name — :rows rows and :files files removed.',
    ],

    'submissions' => [
        'title' => 'Abstracts',
        'columns' => [
            'reference' => 'Reference',
            'title' => 'Title',
            'conference' => 'Conference',
            'organization' => 'Organization',
            'status' => 'Status',
            'decision' => 'Decision',
            'reviews' => 'Reviews',
            'score' => 'Score',
            'submitted' => 'Submitted',
            'presenter' => 'Presenter',
            'file' => 'File',
            'type' => 'Type',
            'decided_by' => 'Decided by',
        ],
        'filters' => [
            'organization' => 'Organization',
            'conference' => 'Conference',
            'status' => 'Status',
            'decision' => 'Decision',
            'conference_status' => 'Conference status',
        ],
        'sections' => [
            'abstract' => 'Abstract',
            'owner' => 'Where it belongs',
            'authors' => 'Authors',
            'files' => 'Files',
            'decisions' => 'Decisions',
        ],
        'not_notified' => 'Not sent',
        'former_member' => 'A former member',
    ],

    // The only screen in the application that prints a review's content, and
    // the read-only manager of the same rows on an abstract.
    'reviews' => [
        'title' => 'Reviews',
        'open' => 'Open the review',
        'yes' => 'Yes',
        'no' => 'No',
        'no_answer' => 'Not answered',
        'columns' => [
            'reference' => 'Reference',
            'abstract' => 'Abstract',
            'conference' => 'Conference',
            'reviewer' => 'Reviewer',
            'email' => 'Email address',
            'status' => 'Status',
            'score' => 'Score',
            'submitted' => 'Submitted',
        ],
        'filters' => [
            'status' => 'Status',
            'conference' => 'Conference',
            'organization' => 'Organization',
        ],
        'sections' => [
            'review' => 'Review',
            'abstract' => 'The abstract',
            'answers' => 'What the reviewer wrote',
        ],
    ],

    'assignments' => [
        'title' => 'Assigned reviewers',
        'columns' => [
            'reviewer' => 'Reviewer',
            'assigned_by' => 'Assigned by',
            'assigned' => 'Assigned',
        ],
    ],

    'reviewers' => [
        'title' => 'Reviewers',
        'columns' => [
            'name' => 'Reviewer',
            'email' => 'Email address',
            'affiliation' => 'Affiliation',
            'status' => 'Status',
            'accepted' => 'Accepted',
            'removed' => 'Removed',
        ],
    ],

    'members' => [
        'title' => 'Members',
        'columns' => [
            'name' => 'Name',
            'email' => 'Email address',
            'role' => 'Role',
            'notified' => 'Emailed on submission',
            'since' => 'Member since',
        ],
    ],

    'invitations' => [
        'title' => 'Invitations',
        'columns' => [
            'email' => 'Email address',
            'role' => 'Role',
            'invited_by' => 'Invited by',
            'expires' => 'Expires',
            'accepted' => 'Accepted',
            'revoked' => 'Withdrawn',
        ],
    ],

    'search' => [
        'status' => 'Status',
        'conferences' => 'Conferences',
        'organization' => 'Organization',
    ],
];
