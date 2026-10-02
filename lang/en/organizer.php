<?php

declare(strict_types=1);

/*
 * The organizer panel's conference lifecycle, which Plan 2 wrote in PHP before
 * the language-file convention existed. Spec section 10: Arabic is a copy of
 * this file.
 *
 * Plan 7 opens it with the publishing checklist - PublishConference::blockers(),
 * shown on the conference page and in the refusal when Publish is clicked. The
 * newer lifecycle actions keep theirs beside their feature
 * (reviewer.start.errors, decisions.mark.errors); this is the same shape for
 * the oldest one. The rest of Plan 2's organizer screens land here when they
 * are swept (backlog).
 */

return [

    'publish' => [
        'errors' => [
            'pending' => 'Your organization is still waiting for platform approval. You can publish as soon as it is approved.',
            'suspended' => 'This organization is suspended, so its conferences cannot be published.',
            'no_window' => 'Set both a submission opening date and a submission deadline.',
            'deadline_past' => 'The submission deadline is in the past. Choose a future date and time.',
            'deadline_order' => 'The submission deadline must come after the submission opening date.',
            'no_questions' => 'The review form has no questions yet. Add at least one before publishing.',
            'archived' => 'An archived conference cannot be published again.',
            // :status is the conference status label, lower-cased by the
            // caller, exactly as reviewer.start.errors.wrong_status takes it.
            'wrong_status' => 'A conference that is :status cannot be opened for submissions.',
        ],
    ],

];
