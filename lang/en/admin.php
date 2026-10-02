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
        'logo' => 'logo file on the public branding disk',
        'audit_note' => 'The audit log is kept: the record of who did what, including this purge, is not erased.',
        'confirm_label' => 'Type :word to confirm',
        'confirm_help' => 'That is the slug from the URL. Typing it is the confirmation.',
        'confirm_mismatch' => 'That is not :word.',
        'submit' => 'Purge permanently',
        'done' => 'Purged :name — :rows rows and :files files removed.',
    ],

    // The three tables Plans 1 and 2 wrote before this file existed. Eight of
    // their columns and all three status filters had no label at all and
    // printed one Filament made up from the column name ("Name", "Status",
    // "Type", "Country", "Subject"); those are keys now too, with the same
    // words. `organizations` is the list and its two review actions.
    'conferences' => [
        'columns' => [
            'name' => 'Name',
            'organization' => 'Organization',
            'status' => 'Status',
            'abstracts' => 'Abstracts',
            'decided' => 'Decided',
            'notified' => 'Letters sent',
            'deadline' => 'Deadline',
            'created' => 'Created',
        ],
        'filters' => [
            'status' => 'Status',
        ],
        'not_set' => 'Not set',
    ],

    'organizations' => [
        'columns' => [
            'name' => 'Name',
            'type' => 'Type',
            'country' => 'Country',
            'owner' => 'Owner',
            'status' => 'Status',
            'registered' => 'Registered',
        ],
        'filters' => [
            'status' => 'Status',
        ],
        'approve' => [
            'action' => 'Approve',
            'heading' => 'Approve this organization?',
            'description' => 'The owner will be emailed and can publish conferences immediately.',
            'done' => ':name approved',
        ],
        'reject' => [
            'action' => 'Reject',
            'reason' => 'Reason sent to the owner',
            'done' => ':name rejected',
        ],
    ],

    'email_log' => [
        'columns' => [
            'queued' => 'Queued',
            'to' => 'To',
            'subject' => 'Subject',
            'status' => 'Status',
            'template' => 'Template',
            'sent_by' => 'Sent by',
            'organization' => 'Organization',
            'sent' => 'Sent',
        ],
        'filters' => [
            'status' => 'Status',
            'organization' => 'Organization',
        ],
        // An email with no organization: a password reset, a registration
        // notice to the platform admin.
        'platform' => 'Platform',
        'empty' => 'Nothing sent yet',
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

    // The organization profile form (App\Filament\Schemas\OrganizationProfileForm).
    // The organizer's tenant profile page renders it as well as the admin's
    // edit page: one form, so one set of strings, here because the admin page
    // is what made it shared.
    'organization' => [
        'profile_title' => 'Organization profile',
        'logo_too_large' => 'This image is :width × :height pixels. A logo can be at most :max megapixels — resize it and upload it again.',
        'form' => [
            'details' => 'Details',
            'name' => 'Name',
            'slug' => 'Slug',
            'slug_help' => 'Used in your public URLs. Contact us if it needs to change.',
            'type' => 'Type',
            'country' => 'Country',
            'website' => 'Website',
            'contact_email' => 'Contact email',
            'contact_email_help' => 'How we reach you about your conferences. Use a shared inbox, not a personal address.',
            'publish_contact_email' => 'Show this address on public conference pages',
            'publish_contact_email_help' => 'Off by default. Anyone - including a scraper - can read an address printed on a public page.',
            'branding' => 'Branding',
            'logo' => 'Logo',
            'logo_help' => 'PNG or JPEG, at most 2 MB and :max megapixels. A transparent background works best.',
            'primary_color' => 'Primary color',
            'primary_color_help' => 'Buttons and headings on your public pages. Must be readable on white.',
            'accent_color' => 'Accent color',
            'accent_color_help' => 'Links and highlights.',
            'contrast' => 'This colour is too light to read on a white background. Choose a darker shade.',
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
        // The admin's Change role and Remove reuse members.actions.* for the
        // buttons and headings; these two descriptions differ because the
        // reader does: a platform admin acts with an owner's authority, and
        // cannot invite anybody back.
        'change_role_description' => 'You act with an owner\'s authority here. An organization always keeps at least one owner, so make somebody else an owner before you change the last one.',
        'remove_description' => 'They lose access to this organization straight away, and every invitation they sent is withdrawn. Their CASS account and anything they created stay as they are, and an owner or admin of the organization can invite them again.',
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
