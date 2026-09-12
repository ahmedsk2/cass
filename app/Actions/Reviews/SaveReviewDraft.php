<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Enums\ReviewQuestionType;
use App\Enums\ReviewStatus;
use App\Exceptions\ReviewNotAcceptable;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\User;
use App\Support\Reviews\ReviewerScope;
use Illuminate\Support\Facades\DB;

/**
 * Spec 5.4 step 4: "Reviews can be saved as draft and submitted."
 *
 * A draft is a scratchpad. Nothing here validates an answer's *content* - a
 * reviewer who has answered three of nine questions and closes the laptop must
 * find those three there tomorrow. What is checked is the two things a draft
 * still cannot be: written by somebody outside the pool, and written against a
 * question from another conference's form.
 */
class SaveReviewDraft
{
    public function __construct(private readonly CreateDefaultReviewForm $createDefaultReviewForm) {}

    /**
     * @param  array<string, mixed>  $answers  keyed by review_questions.ulid
     */
    public function handle(Submission $submission, User $reviewer, array $answers): Review
    {
        if (! ReviewerScope::allows($reviewer, $submission)) {
            throw ReviewNotAcceptable::because(__('reviewer.errors.not_yours'));
        }

        // ReviewerScope answers a READING question, and it admits Decided
        // (Conference::isOpenToReviewers) so that a reviewer can look back at
        // what they said. Writing is narrower: once the committee has decided,
        // a new answer would change the evidence the decision was made on.
        if (! $submission->conference->acceptsReviewWrites()) {
            throw ReviewNotAcceptable::because(__('reviewer.errors.review_closed'));
        }

        // Narrower than the conference status, and the case that one misses:
        // ApplyDecision writes the decision while the conference is still
        // `reviewing`, and ReviewerScope keeps the decided row readable for the
        // reviewer who reviewed it. See Submission::isDecided().
        if ($submission->isDecided()) {
            throw ReviewNotAcceptable::because(__('reviewer.errors.decided'));
        }

        // A submitted review is not a scratchpad any more. SubmitReview refuses
        // `already_submitted` and ReopenReview refuses once the deadline has
        // passed, so without this the ONLY thing stopping a reviewer rewriting
        // the answers of a submitted, deadline-frozen review is the page's
        // `visible(fn () => ! $this->isReadOnly())` - and this class's siblings
        // all say the page is a convenience and a hand-made call is not.
        //
        // Safe for SubmitReview::handle(), which calls this while the row is
        // still Draft and flips the status afterwards, and for the reopen path,
        // which puts it back to Draft first.
        $existing = Review::query()
            ->where('submission_id', $submission->getKey())
            ->where('reviewer_user_id', $reviewer->getKey())
            ->first();

        if ($existing?->status === ReviewStatus::Submitted) {
            throw ReviewNotAcceptable::because(__('reviewer.errors.already_submitted'));
        }

        $form = $this->form($submission);

        return DB::transaction(function () use ($submission, $reviewer, $answers, $form): Review {
            $review = $this->review($submission, $reviewer, $form);

            $this->writeAnswers($review, $form, $answers);

            return $review->refresh();
        });
    }

    /**
     * The review row, created on first save. A lookup on the unique pair
     * rather than a `create`, so a double-clicked Save cannot hit the unique
     * key.
     *
     * Not `firstOrNew([...])`: Review is `$guarded = ['*']` (every column here
     * is a transition, not a form field), and `firstOrNew` mass-assigns its
     * lookup attributes into the new instance - which under
     * `Model::preventSilentlyDiscardingAttributes()` throws
     * MassAssignmentException instead of silently dropping them. The explicit
     * lookup plus `forceFill` is the same two queries and the house pattern for
     * every other write in this codebase.
     */
    public function review(Submission $submission, User $reviewer, ReviewForm $form): Review
    {
        $review = Review::query()
            ->where('submission_id', $submission->getKey())
            ->where('reviewer_user_id', $reviewer->getKey())
            ->first();

        if ($review instanceof Review) {
            return $review;
        }

        $review = new Review;

        $review->forceFill([
            'submission_id' => $submission->getKey(),
            'reviewer_user_id' => $reviewer->getKey(),
            'review_form_id' => $form->getKey(),
            'status' => ReviewStatus::Draft,
        ])->save();

        return $review;
    }

    /**
     * The conference's active form, created on demand for a conference that
     * somehow has none (a factory, the Plan 6 import) exactly as the organizer
     * relation manager does.
     */
    public function form(Submission $submission): ReviewForm
    {
        $existing = $submission->conference->reviewForm()->first();

        return $existing instanceof ReviewForm
            ? $existing
            : $this->createDefaultReviewForm->handle($submission->conference);
    }

    /**
     * Writes one row per question the caller actually mentioned - including a
     * question mentioned with a blank value, which becomes a row whose four
     * value columns are all null.
     *
     * A question the caller did NOT mention gets no row from a draft save. A
     * draft is a scratchpad and its answer set is partial by definition; the
     * complete set is SubmitReview's business, and it gets one by naming every
     * question of the form before it delegates here, so a submitted review
     * carries "answered and empty" rather than "never asked" for the questions
     * the reviewer left alone (Plan 5 counts the two differently).
     *
     * An answer to a question from another form is dropped rather than stored:
     * the foreign key would be satisfied and the row would mean nothing.
     *
     * `updateOrCreate` semantics keyed on the unique pair are what make a second
     * save idempotent.
     *
     * @param  array<string, mixed>  $answers
     */
    private function writeAnswers(Review $review, ReviewForm $form, array $answers): void
    {
        /** @var ReviewQuestion $question */
        foreach ($form->questions()->get() as $question) {
            $ulid = (string) $question->ulid;

            if (! array_key_exists($ulid, $answers)) {
                continue;
            }

            // The same reason `review()` above does not use `firstOrNew`:
            // ReviewAnswer is totally guarded, so a lookup array handed to
            // `firstOrNew` would be mass-assigned into the new row and throw.
            $answer = ReviewAnswer::query()
                ->where('review_id', $review->getKey())
                ->where('review_question_id', $question->getKey())
                ->first() ?? new ReviewAnswer;

            $answer->forceFill([
                'review_id' => $review->getKey(),
                'review_question_id' => $question->getKey(),
                ...self::columnsFor($question, $answers[$ulid]),
            ])->save();
        }
    }

    /**
     * One column per answer type, the other three explicitly null - so changing
     * a draft answer from "Oral" to a blank cannot leave `choice_key` behind.
     *
     * @return array{value_int: int|null, value_text: string|null, value_bool: bool|null, choice_key: string|null}
     */
    public static function columnsFor(ReviewQuestion $question, mixed $value): array
    {
        // A draft is permissive about COMPLETENESS and strict about SHAPE. An
        // array or an object posted for a Text question is not half an answer,
        // it is a forged payload, and `(string) $value` on it raises "Array to
        // string conversion", which HandleExceptions promotes to an
        // ErrorException and a 500. Drop it instead.
        if ($value !== null && ! is_scalar($value)) {
            $value = null;
        }

        $blank = $value === null || $value === '';

        return match ($question->type) {
            ReviewQuestionType::Likert => [
                'value_int' => $blank ? null : (int) $value,
                'value_text' => null, 'value_bool' => null, 'choice_key' => null,
            ],
            ReviewQuestionType::Text => [
                'value_int' => null,
                // Capped at the same 5000 the submit path enforces
                // (ReviewFormSchema::rules()) and the component renders
                // (->maxLength(5000)). `review_answers.value_text` is a
                // mediumText column; nothing else stops a draft save writing
                // megabytes into it once a request reaches this method without
                // going through the form.
                'value_text' => $blank ? null : mb_substr((string) $value, 0, 5000),
                'value_bool' => null, 'choice_key' => null,
            ],
            ReviewQuestionType::Boolean => [
                'value_int' => null, 'value_text' => null,
                'value_bool' => $blank ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'choice_key' => null,
            ],
            ReviewQuestionType::Select => [
                'value_int' => null, 'value_text' => null, 'value_bool' => null,
                'choice_key' => $blank ? null : (string) $value,
            ],
        };
    }
}
