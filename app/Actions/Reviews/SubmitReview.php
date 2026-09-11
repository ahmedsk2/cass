<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewQuestionType;
use App\Enums\ReviewStatus;
use App\Enums\SubmissionStatus;
use App\Exceptions\ReviewNotAcceptable;
use App\Models\Review;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\User;
use App\Support\Reviews\ReviewerScope;
use App\Support\Reviews\ReviewFormSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Spec 5.4 step 4, spec section 3's lock rule, and the
 * `Submitted -> UnderReview` transition.
 *
 * Same shape as PublishConference and SubmitAbstract: `blockers()` is a pure
 * read the page calls to paint errors, `handle()` re-checks and throws, because
 * the page is a convenience and a hand-made Livewire call is not.
 *
 * **What the review deadline stops.** A *submitted* review is read-only once
 * the deadline passes (ReopenReview refuses). A *draft* may still be finished:
 * `reviewer_overdue`, a spec 5.9 key whose platform default Plan 3 already
 * ships, tells a reviewer after the deadline to complete outstanding reviews,
 * and freezing them would make that email a lie. Recorded as an owner question.
 */
class SubmitReview
{
    public function __construct(private readonly SaveReviewDraft $saveDraft) {}

    /**
     * @param  array<string, mixed>  $answers
     * @return list<string> empty when the review may be submitted
     */
    public function blockers(Submission $submission, User $reviewer, array $answers): array
    {
        $reasons = [];

        if (! ReviewerScope::allows($reviewer, $submission)) {
            // Covers every "not yours" case at once: not a reviewer, removed,
            // not assigned, conference not in review, abstract withdrawn.
            return [__('reviewer.errors.not_yours')];
        }

        // ...except the one it deliberately does not cover: ReviewerScope admits
        // Decided, because a reviewer may still READ what they said after the
        // committee decides. Writing stops at Reviewing.
        if (! $submission->conference->acceptsReviewWrites()) {
            $reasons[] = __('reviewer.errors.review_closed');
        }

        $existing = Review::query()
            ->where('submission_id', $submission->getKey())
            ->where('reviewer_user_id', $reviewer->getKey())
            ->first();

        if ($existing !== null && $existing->status === ReviewStatus::Submitted) {
            $reasons[] = __('reviewer.errors.already_submitted');
        }

        foreach ($this->fieldErrors($submission, $answers) as $message) {
            $reasons[] = $message;
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Per-question messages, so the panel can attach each one to its own field
     * instead of printing a banner listing nine prompts.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, string> question ULID => message
     */
    public function fieldErrors(Submission $submission, array $answers): array
    {
        $form = $this->saveDraft->form($submission);
        $rules = ReviewFormSchema::rules($form, required: true);

        $validator = Validator::make(['answers' => $answers], $rules);

        if ($validator->passes()) {
            return [];
        }

        $messages = [];

        /** @var ReviewQuestion $question */
        foreach ($form->questions()->get() as $question) {
            $key = 'answers.'.$question->ulid;

            if (! $validator->errors()->has($key)) {
                continue;
            }

            $messages[(string) $question->ulid] = __('reviewer.errors.answer', [
                'prompt' => (string) $question->prompt,
                'detail' => $this->detail($question),
            ]);
        }

        return $messages;
    }

    private function detail(ReviewQuestion $question): string
    {
        return match ($question->type) {
            ReviewQuestionType::Likert => __('reviewer.errors.detail_scale', [
                'min' => (int) ($question->scale_min ?? 1),
                'max' => (int) ($question->scale_max ?? 5),
            ]),
            ReviewQuestionType::Select => __('reviewer.errors.detail_choice', [
                'choices' => implode(', ', $question->optionKeys()),
            ]),
            ReviewQuestionType::Boolean => __('reviewer.errors.detail_boolean'),
            ReviewQuestionType::Text => __('reviewer.errors.detail_text'),
        };
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function handle(Submission $submission, User $reviewer, array $answers): Review
    {
        $reasons = $this->blockers($submission, $reviewer, $answers);

        if ($reasons !== []) {
            throw new ReviewNotAcceptable($reasons, $this->fieldErrors($submission, $answers));
        }

        $form = $this->saveDraft->form($submission);
        $complete = $this->completeAnswers($form, $answers);

        $review = DB::transaction(function () use ($submission, $reviewer, $complete, $form): Review {
            // The answers are written by the same code a draft save uses, so
            // "submit without saving first" and "save then submit" cannot store
            // different rows.
            $review = $this->saveDraft->handle($submission, $reviewer, $complete);

            $review->forceFill([
                'status' => ReviewStatus::Submitted,
                'submitted_at' => now(),
                'review_form_id' => $form->getKey(),
            ])->save();

            // Spec section 3. A conditional UPDATE, so two reviewers submitting
            // in the same second cannot both stamp a different locked_at.
            $form->lockIfUnlocked();

            // Only from Submitted. An abstract already under review, withdrawn,
            // or decided by Plan 5 is left exactly where it is.
            if ($submission->status === SubmissionStatus::Submitted) {
                $submission->forceFill(['status' => SubmissionStatus::UnderReview])->save();
            }

            return $review;
        });

        activity()
            ->performedOn($review)
            ->causedBy($reviewer)
            ->withProperties(['submission_id' => $submission->getKey()])
            ->log('review.submitted');

        return $review->refresh();
    }

    /**
     * Every question of the form, blanks included.
     *
     * A draft stores only what the reviewer touched, because it is a scratchpad
     * and a partial answer set is what it is for. A *submitted* review is a
     * complete record: a question the reviewer left blank is stored as a row
     * with a null value rather than as no row at all, because Plan 5 counts
     * "answered and empty" differently from "never asked".
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function completeAnswers(ReviewForm $form, array $answers): array
    {
        $complete = [];

        /** @var ReviewQuestion $question */
        foreach ($form->questions()->get() as $question) {
            $ulid = (string) $question->ulid;

            $complete[$ulid] = $answers[$ulid] ?? null;
        }

        return $complete;
    }
}
