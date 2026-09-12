<?php

declare(strict_types=1);

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Actions\Decisions\ApplyDecision;
use App\Actions\Reviews\ReopenReview;
use App\Actions\Reviews\SaveReviewDraft;
use App\Actions\Reviews\SubmitReview;
use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\ReviewQuestionType;
use App\Enums\ReviewStatus;
use App\Enums\SubmissionStatus;
use App\Exceptions\ReviewNotAcceptable;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\User;
use App\Support\Reviews\ReviewerScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->conference = Conference::factory()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
    ]);
    $this->form = app(CreateDefaultReviewForm::class)->handle($this->conference);
    // Nine Likert questions from the default template; trim to two so the
    // tests below say what they mean.
    $this->form->questions()->orderBy('sort')->skip(2)->take(99)->get()->each->delete();
    [$this->first, $this->second] = $this->form->questions()->orderBy('sort')->get()->all();

    $this->submission = Submission::factory()->for($this->conference)->submitted()->create();
    $this->reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->reviewer->id]);
});

/** @return array<string, mixed> */
function fullAnswers(object $test): array
{
    return [$test->first->ulid => 4, $test->second->ulid => 5];
}

it('saves a draft with no validation at all', function () {
    // A draft is a scratchpad: half an answer must survive a coffee break.
    $review = app(SaveReviewDraft::class)->handle($this->submission, $this->reviewer, [
        $this->first->ulid => 3,
    ]);

    expect($review->status)->toBe(ReviewStatus::Draft)
        ->and($review->submitted_at)->toBeNull()
        ->and($review->review_form_id)->toBe($this->form->id)
        ->and($review->answers)->toHaveCount(1)
        ->and($this->submission->fresh()?->status)->toBe(SubmissionStatus::Submitted)
        ->and($this->form->fresh()?->isLocked())->toBeFalse();
});

it('reuses the same review row and replaces the answer instead of appending', function () {
    $first = app(SaveReviewDraft::class)->handle($this->submission, $this->reviewer, [$this->first->ulid => 3]);
    $second = app(SaveReviewDraft::class)->handle($this->submission, $this->reviewer, [$this->first->ulid => 5]);

    expect($second->is($first))->toBeTrue()
        ->and(Review::query()->count())->toBe(1)
        ->and(ReviewAnswer::query()->count())->toBe(1)
        ->and($second->answers()->first()?->value_int)->toBe(5);
});

it('drops an answer to a question from another form', function () {
    $foreign = ReviewQuestion::factory()->create();

    $review = app(SaveReviewDraft::class)->handle($this->submission, $this->reviewer, [
        $this->first->ulid => 4,
        $foreign->ulid => 1,
    ]);

    // A cross-form foreign key would satisfy every constraint and mean nothing.
    expect($review->answers()->pluck('review_question_id')->all())->toBe([$this->first->id]);
});

it('names every missing and out-of-range answer instead of throwing on the first', function () {
    $blockers = app(SubmitReview::class)->blockers($this->submission, $this->reviewer, [
        $this->first->ulid => 9,
    ]);

    expect($blockers)->toHaveCount(2)
        ->and(implode(' ', $blockers))->toContain((string) $this->first->prompt)
        ->and(implode(' ', $blockers))->toContain((string) $this->second->prompt);
});

it('submits, stamps the time, locks the form and moves the abstract to under review', function () {
    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    expect($review->status)->toBe(ReviewStatus::Submitted)
        ->and($review->submitted_at)->not->toBeNull()
        ->and($review->answers)->toHaveCount(2)
        // Spec section 3: the first submitted review locks the form.
        ->and($this->form->fresh()?->isLocked())->toBeTrue()
        ->and($this->submission->fresh()?->status)->toBe(SubmissionStatus::UnderReview)
        ->and(Activity::query()->where('description', 'review.submitted')->count())->toBe(1);
});

it('locks the form once, whichever reviewer is second', function () {
    $other = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $other->id]);

    app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));
    $lockedAt = $this->form->fresh()?->locked_at;

    Carbon::setTestNow(now()->addHour());
    app(SubmitReview::class)->handle($this->submission, $other, fullAnswers($this));
    Carbon::setTestNow();

    expect($this->form->fresh()?->locked_at?->toDateTimeString())->toBe($lockedAt?->toDateTimeString());
});

it('leaves an abstract that is already under review exactly where it is', function () {
    // Renamed to what it actually asserts. `forceFill` to the enum the row
    // already holds leaves the model clean, so save() issues no UPDATE either
    // way and this passes with or without SubmitReview's `=== Submitted` guard.
    // The withdrawn and decided halves are the case below.
    $this->submission->forceFill(['status' => SubmissionStatus::UnderReview])->save();

    app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    expect($this->submission->fresh()?->status)->toBe(SubmissionStatus::UnderReview);
});

it('does not drag a withdrawn or decided abstract back to under review', function () {
    // ReviewerScope::constrain() restricts the queue to `submitted` and
    // `under_review`, so neither of these ever reaches the status write at all -
    // they are refused one layer earlier, which is the stronger guarantee and
    // the one no test had made.
    foreach ([SubmissionStatus::Withdrawn, SubmissionStatus::Accepted] as $status) {
        $this->submission->forceFill(['status' => $status])->save();

        expect(app(SubmitReview::class)->blockers($this->submission->fresh(), $this->reviewer, fullAnswers($this)))
            ->toBe([__('reviewer.errors.not_yours')], $status->value)
            ->and(fn () => app(SubmitReview::class)->handle($this->submission->fresh(), $this->reviewer, fullAnswers($this)))
            ->toThrow(ReviewNotAcceptable::class)
            ->and($this->submission->fresh()?->status)->toBe($status);
    }

    expect(Review::query()->count())->toBe(0);
});

it('refuses every review write on an abstract the committee has already decided', function () {
    // The window ReviewerScope's decided arm opens, and the one its own comment
    // got wrong. The arm re-admits an accepted/rejected/waitlisted row for the
    // reviewer who reviewed it - a DRAFT counts - so they can read back what
    // they wrote; and ApplyDecision writes that status while the conference is
    // still `reviewing`, where acceptsReviewWrites() is true. So nothing but
    // this guard stops a stale draft being submitted onto a row whose author is
    // already holding a letter, with SubmitReview's ComputeSubmissionScore hook
    // rewriting the score, the spread and the count the committee decided on.
    app(SaveReviewDraft::class)->handle($this->submission, $this->reviewer, [$this->first->ulid => 2]);

    app(ApplyDecision::class)->handle($this->submission, Decision::Rejected, User::factory()->create());
    $this->submission->forceFill(['decision_notified_at' => now()])->save();

    $decided = $this->submission->fresh() ?? $this->submission;

    expect(ReviewerScope::allows($this->reviewer, $decided))->toBeTrue()
        ->and($decided->conference->acceptsReviewWrites())->toBeTrue()
        ->and(app(SubmitReview::class)->blockers($decided, $this->reviewer, fullAnswers($this)))
        ->toBe([__('reviewer.errors.decided')])
        ->and(fn () => app(SubmitReview::class)->handle($decided, $this->reviewer, fullAnswers($this)))
        ->toThrow(ReviewNotAcceptable::class, __('reviewer.errors.decided'))
        ->and(fn () => app(SaveReviewDraft::class)->handle($decided, $this->reviewer, [$this->first->ulid => 1]))
        ->toThrow(ReviewNotAcceptable::class, __('reviewer.errors.decided'));

    $after = $this->submission->fresh();

    // The three denormalised columns the ranking sorts on are exactly where
    // ApplyDecision left them, and the draft answer is untouched.
    expect($after?->score)->toBeNull()
        ->and($after?->score_spread)->toBeNull()
        ->and($after?->review_count)->toBe(0)
        ->and($after?->status)->toBe(SubmissionStatus::Rejected)
        ->and($after?->decision_notified_at)->not->toBeNull()
        ->and(Review::query()->firstOrFail()->answers()->first()?->value_int)->toBe(2);
});

it('refuses a reopen on an abstract the committee has already decided', function () {
    // The same window from the other side: this reviewer's review is SUBMITTED,
    // so ReviewerScope admits it whatever the arm's status filter says, and
    // ReopenReview recomputes the score of a decided abstract on its way out.
    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    app(ApplyDecision::class)->handle(
        $this->submission->fresh() ?? $this->submission,
        Decision::AcceptedOral,
        User::factory()->create(),
    );

    $scored = $this->submission->fresh()?->score;

    expect(app(ReopenReview::class)->blockers($review->fresh() ?? $review))
        ->toContain(__('reviewer.errors.decided'))
        ->and(fn () => app(ReopenReview::class)->handle($review->fresh() ?? $review, $this->reviewer))
        ->toThrow(ReviewNotAcceptable::class)
        ->and($review->fresh()?->status)->toBe(ReviewStatus::Submitted)
        ->and($this->submission->fresh()?->score)->toBe($scored);
});

it('refuses a draft save over a review that has already been submitted', function () {
    // SubmitReview refuses `already_submitted` and ReopenReview refuses past the
    // deadline, but SaveReviewDraft checked only the scope and the conference -
    // so the one thing stopping a reviewer rewriting the answers of a submitted,
    // deadline-frozen review was the page's own visible() closure. The page is a
    // convenience and a hand-made Livewire call is not.
    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));
    $answers = $review->answers()->pluck('value_int', 'review_question_id')->all();

    expect(fn () => app(SaveReviewDraft::class)->handle(
        $this->submission->fresh(), $this->reviewer, [$this->first->ulid => 1],
    ))->toThrow(ReviewNotAcceptable::class, __('reviewer.errors.already_submitted'));

    expect($review->fresh()?->answers()->pluck('value_int', 'review_question_id')->all())->toBe($answers);

    // ...and a reopen puts the scratchpad back, because the refusal is about the
    // status and nothing else.
    app(ReopenReview::class)->handle($review->fresh(), $this->reviewer);

    app(SaveReviewDraft::class)->handle($this->submission->fresh(), $this->reviewer, [$this->first->ulid => 1]);

    expect($review->fresh()?->answers()->where('review_question_id', $this->first->id)->first()?->value_int)->toBe(1);
});

it('refuses to reopen a review once the reviewer has been removed from the conference', function () {
    // ReviewerScope::allows() is what refuses a removed reviewer everywhere
    // else, and ReopenReview never asked it: the record is #[Locked] and
    // mount()'s scoped resolve never runs again, so a Livewire snapshot captured
    // before the removal still retracted a submitted review - and took it out of
    // the committee's evidence.
    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    $this->conference->reviewers()->where('user_id', $this->reviewer->id)->delete();

    expect(ReviewerScope::allows($this->reviewer, $this->submission->fresh()))->toBeFalse()
        ->and(app(ReopenReview::class)->blockers($review->fresh()))->toBe([__('reviewer.errors.not_yours')])
        ->and(fn () => app(ReopenReview::class)->handle($review->fresh(), $this->reviewer))
        ->toThrow(ReviewNotAcceptable::class);

    expect($review->fresh()?->status)->toBe(ReviewStatus::Submitted);
});

it('refuses a second submit and refuses a submission outside the pool', function () {
    app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    expect(fn () => app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this)))
        ->toThrow(ReviewNotAcceptable::class);

    $stranger = User::factory()->create();

    expect(fn () => app(SubmitReview::class)->handle($this->submission, $stranger, fullAnswers($this)))
        ->toThrow(ReviewNotAcceptable::class)
        ->and(fn () => app(SaveReviewDraft::class)->handle($this->submission, $stranger, []))
        ->toThrow(ReviewNotAcceptable::class);
});

it('validates every question type against its own rule', function () {
    $text = ReviewQuestion::factory()->for($this->form)->freeText()->create(['prompt' => 'Comments', 'required' => true, 'sort' => 30]);
    $boolean = ReviewQuestion::factory()->for($this->form)->create([
        'prompt' => 'Anonymised?', 'type' => ReviewQuestionType::Boolean,
        'scale_min' => null, 'scale_max' => null, 'sort' => 31,
    ]);
    $select = ReviewQuestion::factory()->for($this->form)->create([
        'prompt' => 'Format', 'type' => ReviewQuestionType::Select,
        'scale_min' => null, 'scale_max' => null, 'sort' => 32,
        'options' => [['label' => 'Oral', 'score' => 100], ['label' => 'Poster', 'score' => 50]],
    ]);

    $bad = fullAnswers($this) + [$text->ulid => '', $boolean->ulid => null, $select->ulid => 'Keynote'];

    expect(app(SubmitReview::class)->blockers($this->submission, $this->reviewer, $bad))->toHaveCount(3);

    $good = fullAnswers($this) + [$text->ulid => 'Solid work.', $boolean->ulid => true, $select->ulid => 'Oral'];
    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, $good);

    $answers = $review->answers->keyBy('review_question_id');

    expect($answers[$text->id]->value_text)->toBe('Solid work.')
        ->and($answers[$boolean->id]->value_bool)->toBeTrue()
        ->and($answers[$select->id]->choice_key)->toBe('Oral')
        // The typed column, not a string in value_text: Plan 5 averages these.
        ->and($answers[$this->first->id]->value_int)->toBe(4);
});

it('accepts an option label that contains a comma and refuses one of its halves', function () {
    // An option key IS the organizer's free-text label
    // (ReviewQuestion::optionKey()), and Laravel parses an `in:` parameter list
    // with str_getcsv - so 'in:'.implode(',', $keys) would split "Oral, in
    // person" into two bogus allowed values, reject the option the reviewer
    // actually chose, and silently admit "Oral" and " in person" into
    // review_answers.choice_key. Rule::in() quotes each value.
    $select = ReviewQuestion::factory()->for($this->form)->create([
        'prompt' => 'Format', 'type' => ReviewQuestionType::Select,
        'scale_min' => null, 'scale_max' => null, 'sort' => 33,
        'options' => [['label' => 'Oral, in person', 'score' => 100], ['label' => 'Poster', 'score' => 50]],
    ]);

    $answers = fullAnswers($this) + [$select->ulid => 'Oral, in person'];

    expect(app(SubmitReview::class)->blockers($this->submission, $this->reviewer, $answers))->toBe([])
        ->and(app(SubmitReview::class)->blockers(
            $this->submission,
            $this->reviewer,
            fullAnswers($this) + [$select->ulid => 'Oral'],
        ))->not->toBe([]);

    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, $answers);

    expect($review->answers->keyBy('review_question_id')[$select->id]->choice_key)->toBe('Oral, in person');
});

it('drops a non-scalar draft answer instead of throwing', function () {
    // A draft is permissive about completeness and strict about shape: an array
    // posted for a question is a forged payload, not half an answer, and
    // `(string) $value` on it is an ErrorException and a 500.
    $review = app(SaveReviewDraft::class)->handle($this->submission, $this->reviewer, [
        $this->first->ulid => ['not' => 'a scalar'],
    ]);

    expect($review->answers()->where('review_question_id', $this->first->id)->first()?->value_int)->toBeNull();
});

it('lets an optional question be left blank', function () {
    $optional = ReviewQuestion::factory()->for($this->form)->freeText()->create([
        'prompt' => 'Anything else?', 'required' => false, 'sort' => 40,
    ]);

    expect(app(SubmitReview::class)->blockers($this->submission, $this->reviewer, fullAnswers($this)))->toBe([]);

    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    // Stored as a row with a null value rather than not stored: Plan 5 counts
    // "answered and empty" differently from "never asked".
    expect($review->answers()->where('review_question_id', $optional->id)->exists())->toBeTrue();
});

it('reopens a submitted review before the deadline and refuses after it', function () {
    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    $reopened = app(ReopenReview::class)->handle($review, $this->reviewer);

    expect($reopened->status)->toBe(ReviewStatus::Draft)
        ->and($reopened->reopened_at)->not->toBeNull()
        // The form stays locked: a review WAS submitted, and unlocking would
        // let the organizer edit a question two reviewers already answered.
        ->and($this->form->fresh()?->isLocked())->toBeTrue()
        // The answers survive the reopen, because reopening is "let me change
        // my mind", not "start again".
        ->and($reopened->answers)->toHaveCount(2);

    app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    Carbon::setTestNow($this->conference->review_deadline->copy()->addMinute());

    expect(app(ReopenReview::class)->blockers($review->fresh()))->not->toBe([])
        ->and(fn () => app(ReopenReview::class)->handle($review->fresh(), $this->reviewer))
        ->toThrow(ReviewNotAcceptable::class);

    Carbon::setTestNow();
});

it('still lets outstanding work be finished after the deadline', function () {
    // This is the rule the `reviewer_overdue` template already promises: "the
    // deadline has passed and some of your reviews are still outstanding.
    // Please complete them as soon as you can." Freezing a draft at the
    // deadline would make that email a lie. Recorded as an owner question in
    // Task 13.
    Carbon::setTestNow($this->conference->review_deadline->copy()->addWeek());

    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    expect($review->status)->toBe(ReviewStatus::Submitted);

    Carbon::setTestNow();
});

it('stops everything once the conference leaves reviewing', function () {
    // Decided is still READABLE - ReviewerScope::allows() admits it, because a
    // reviewer may look back at what they said - so nothing in the scope stops
    // a write here. Conference::acceptsReviewWrites() is what does, and the
    // three write actions each ask it.
    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    $this->conference->forceFill(['status' => ConferenceStatus::Decided])->save();

    expect(ReviewerScope::allows($this->reviewer, $this->submission->fresh()))->toBeTrue()
        ->and(fn () => app(SaveReviewDraft::class)->handle($this->submission->fresh(), $this->reviewer, []))
        ->toThrow(ReviewNotAcceptable::class)
        ->and(app(SubmitReview::class)->blockers($this->submission->fresh(), $this->reviewer, fullAnswers($this)))
        ->not->toBe([])
        ->and(app(ReopenReview::class)->blockers($review->fresh()))->not->toBe([]);
});
