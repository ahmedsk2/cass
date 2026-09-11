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
    ],

    'flash' => [
        'draft_saved' => 'Your draft is saved. We have emailed you a link so you can come back to it.',
        'draft_updated' => 'Your draft is saved.',
        'submitted' => 'Your abstract is submitted. Its reference is :reference.',
    ],

];
