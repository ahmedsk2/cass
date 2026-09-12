<?php

declare(strict_types=1);

/*
 * The public conference page and its submit call-to-action - the organization's
 * own page, as an author meets it. Spec section 10: Arabic is a copy of this
 * file, not a branch in a Blade view.
 *
 * The four submission-window states are NOT here. `submission.window.upcoming`,
 * `.closed` and `.not_configured` already say those sentences word for word,
 * and `submission.buttons.submit` is already the button - two keys that say the
 * same thing are two keys a translation can leave disagreeing.
 */

return [

    'call' => 'Call for abstracts',
    'dates' => 'Conference dates',
    'venue' => 'Venue',

    /*
     * Two labels, because the page says "Submission deadline" over a definition
     * list and the call-to-action says "Deadline" in a sentence. `value` carries
     * the parentheses so a translation can move or drop them.
     */
    'deadline' => 'Submission deadline',
    'deadline_label' => 'Deadline',
    'deadline_value' => ':date (:timezone)',

    'terms' => 'Terms',
    'tracks' => 'Tracks',

    'prepare' => [
        'heading' => 'What to prepare',
        'words' => 'Abstract of up to :count words.',
        'files' => 'Up to :max file(s) (:types).',
        'presentation' => 'Presentation preference: :types.',
    ],

    'contact_organizers' => 'Contact the organizers',

    /*
     * The unpublished-conference banner. This reuses nothing:
     * `submission.preview.body` is close, but it carries no :status, and this
     * page prints the status word. Two keys that differ by one placeholder are
     * two keys.
     */
    'preview' => [
        'badge' => 'Preview.',
        'body' => 'This conference is :status and is not visible to the public. Only members of :organization can see this page.',
    ],

];
