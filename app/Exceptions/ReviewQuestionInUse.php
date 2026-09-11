<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A review question that some reviewer has already answered cannot be removed.
 *
 * Separate from ReviewFormLocked because the two refusals happen at different
 * moments and only one of them is about the form: a *draft* answer is written
 * by SaveReviewDraft, which never locks anything, so the window between the
 * first draft save and the first submit has answer rows on an unlocked form.
 * `review_answers.review_question_id` is `restrictOnDelete`, so without this the
 * organizer's Delete button is a foreign-key violation rather than a sentence.
 */
final class ReviewQuestionInUse extends RuntimeException
{
    public static function make(): self
    {
        return new self('A reviewer has already answered this question, so it can no longer be removed. Editing the prompt is still possible until the first review is submitted.');
    }
}
