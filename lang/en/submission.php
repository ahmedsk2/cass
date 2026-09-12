<?php

declare(strict_types=1);

/*
 * Every string on the public submission form and (from Task 8) the author
 * status page. Spec section 10: locale `en` only in v1, with all strings here
 * so Arabic is a copy of this file and not a branch in a Blade view.
 */

return [

    'page' => [
        'eyebrow' => 'Submit an abstract',
    ],

    'window' => [
        'upcoming' => 'Submissions open on :date (:timezone)',
        'closed' => 'Submissions are closed',
        'not_configured' => 'Submission dates have not been announced yet',
        'back' => 'Back to the conference page',
    ],

    'preview' => [
        'badge' => 'Preview.',
        'body' => 'This page is not visible to the public. Only members of :organization can see it.',
    ],

    /*
     * The deadline countdown. Whole phrases rather than words, because
     * resources/js/countdown.js cannot call __() and because English's
     * `days === 1 ? '' : 's'` has no Arabic analogue - Arabic has six plural
     * forms, and a translator needs a sentence to work with.
     *
     * `passed` is NEW COPY, not an extraction: today the badge is simply
     * removed when the deadline passes and there is no message at all.
     */
    'countdown' => [
        'days' => ':days days, :hours hours left',
        'days_one' => '1 day, :hours hours left',
        'hours' => ':hours hours, :minutes minutes left',
        'hours_one' => '1 hour, :minutes minutes left',
        'minutes' => ':minutes minutes left',
        'minutes_one' => '1 minute left',
        'passed' => 'The deadline has passed',
    ],

    'sections' => [
        'abstract' => 'Your abstract',
        'authors' => 'Authors',
        'contact' => 'Contact',
        'custom_fields' => 'Additional questions',
        'files' => 'Files',
        'agreement' => 'Terms',
    ],

    'fields' => [
        'title' => 'Title',
        'abstract' => 'Abstract',
        'words' => 'words',
        'track' => 'Track',
        'track_none' => 'No particular track',
        'presentation_preference' => 'Presentation preference',
        'contact_phone' => 'Contact phone number',
        'contact_phone_help' => 'Used only if the organizers need to reach you quickly about this abstract.',
        'choose' => 'Choose one…',
        'agreed' => 'I have read and accept the terms above, and I confirm every author listed has agreed to this submission.',
        'honeypot' => 'Leave this field empty',
    ],

    'files' => [
        'label' => 'Attach files',
        'limits' => 'Up to :count file(s), :types, :size MB each.',
        'remove' => 'Remove',
        'confirm_delete' => 'Remove this file from your abstract?',
        'uploading' => 'Uploading…',
    ],

    'authors' => [
        'add' => 'Add an author',
        'remove' => 'Remove',
        'help' => 'List every author in the order they should appear. Mark exactly one as the corresponding author — that is the address we will write to.',
        'name' => 'Name',
        'email' => 'Email',
        'affiliation' => 'Affiliation',
        'is_presenter' => 'Will present',
        'is_corresponding' => 'Corresponding author',
        'name_of' => 'name of author :position',
        'email_of' => 'email of author :position',
        'affiliation_of' => 'affiliation of author :position',
    ],

    'buttons' => [
        'submit' => 'Submit abstract',
        'save_draft' => 'Save draft',
        'working' => 'Working…',
        'draft_help' => 'A draft is not considered until you submit it. We will email you a link so you can come back to it.',
    ],

    'errors' => [
        'word_limit' => 'The abstract is :words words. The limit is :limit words.',
        'window_closed' => 'Submissions for this conference are closed. Copy your text somewhere safe before leaving this page.',
        'preview_readonly' => 'This is a preview of a page that is not public yet, so nothing can be submitted from it. Publish the conference first.',
        'too_fast' => 'That was quicker than a person can fill this in. Please take a moment and try again.',
        'too_many' => 'Too many attempts from this connection. Please try again in :seconds seconds.',
        'turnstile' => 'Please complete the "I am human" check and try again.',
    ],

    'flash' => [
        'draft_saved' => 'Your draft is saved. We have emailed you a link so you can come back to it.',
        'draft_updated' => 'Your draft is saved.',
        'submitted' => 'Your abstract is submitted. Its reference is :reference.',
    ],

    'status' => [
        'reference' => 'Reference',
        'state' => 'Status',
        'deadline' => 'Submission deadline',
        'edit' => 'Edit this abstract',
        'cancel_edit' => 'Cancel and go back',
        'withdraw' => 'Withdraw this abstract',
        'confirm_withdraw' => 'Withdraw this abstract? The organizers will be able to see that it was withdrawn, and you cannot undo this yourself.',
        'withdrawn_flash' => 'Your abstract has been withdrawn.',
        'withdrawn_notice' => 'This abstract was withdrawn on :date and will not be reviewed.',
        'draft_warning' => 'This is a draft. It has not been submitted, and a draft is not reviewed. Open it and press "Submit abstract" before the deadline.',
        'decision_pending' => 'The organizers are handling this abstract. They will email you when there is a decision, and the letter will appear here.',
        'decision' => [
            'heading' => 'The organizers\' decision',
            'sent_on' => 'Sent to you on :date.',
        ],
        'until_deadline' => 'You can edit or withdraw until the submission deadline.',
        'keep_link' => 'Keep this link. It is the only way back to this abstract, and anyone who has it can edit it — please do not forward it.',
    ],

];
