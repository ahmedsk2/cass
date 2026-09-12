<?php

declare(strict_types=1);

use App\Actions\Reviews\ReopenReview;
use App\Actions\Reviews\SubmitReview;
use App\Actions\Submissions\ComputeSubmissionScore;
use App\Enums\ConferenceStatus;
use App\Enums\ReviewStatus;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->conference = Conference::factory()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
    ]);
    $this->form = ReviewForm::factory()->for($this->conference)->create(['is_active' => true]);
    $this->question = ReviewQuestion::factory()->for($this->form)->create([
        'scale_min' => 1, 'scale_max' => 5, 'weight' => '1.00',
    ]);
    $this->submission = Submission::factory()->for($this->conference)->submitted()->create();
});

// `scoredReview()` is NOT declared here. It lives in tests/Pest.php,
// because tests/Unit/RescoreCommandTest.php calls it too and a helper declared
// in one test file is a fatal "Call to undefined function" the moment somebody
// runs or --filters the other file on its own.

it('writes the review score and the four submission columns', function () {
    $first = scoredReview($this->submission, $this->form, $this->question, 5);
    scoredReview($this->submission, $this->form, $this->question, 3);

    // `reviews.updated_at` means "the reviewer touched it", which is why
    // reviews.score is written through ->toBase() and not forceFill()->save()
    // or an Eloquent Builder::update() (both of which call
    // addUpdatedAtColumn()). Nothing pinned that reason, and a whole-conference
    // cass:rescore under either of them would reset the timestamp on every
    // review in the conference - invisibly, with the whole suite green.
    $touched = $first->fresh()?->updated_at;

    expect($touched)->not->toBeNull();

    $this->travel(1)->minutes();

    app(ComputeSubmissionScore::class)->handle($this->submission);

    expect($first->fresh()?->updated_at?->equalTo($touched))->toBeTrue();

    $fresh = $this->submission->fresh();

    // 100 and 50 -> mean 75, sample SD sqrt(((25)^2 + (25)^2)/1) = 35.36
    expect($fresh?->score)->toBe('75.00')
        ->and($fresh?->score_spread)->toBe('35.36')
        ->and($fresh?->review_count)->toBe(2)
        ->and($fresh?->scored_at)->not->toBeNull()
        ->and(Review::query()->pluck('score')->sort()->values()->all())->toBe(['50.00', '100.00']);
});

it('scores a draft review but leaves it out of the aggregate', function () {
    scoredReview($this->submission, $this->form, $this->question, 5);
    $draft = scoredReview($this->submission, $this->form, $this->question, 1, ReviewStatus::Draft);

    app(ComputeSubmissionScore::class)->handle($this->submission);

    $fresh = $this->submission->fresh();

    // The draft's own score is written - it is what those answers are worth -
    // but it is not one of the reviews the committee has been given.
    expect($draft->fresh()?->score)->toBe('0.00')
        ->and($fresh?->score)->toBe('100.00')
        ->and($fresh?->review_count)->toBe(1);
});

it('counts a submitted review that scored nothing, without averaging it in', function () {
    $textOnly = ReviewQuestion::factory()->for($this->form)->freeText()->create();

    scoredReview($this->submission, $this->form, $this->question, 4);

    // A second reviewer who answered only the free-text question.
    $reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $reviewer->id]);
    $review = Review::factory()->create([
        'submission_id' => $this->submission->getKey(),
        'reviewer_user_id' => $reviewer->getKey(),
        'review_form_id' => $this->form->getKey(),
        'status' => ReviewStatus::Submitted,
        'submitted_at' => now(),
    ]);
    $answer = new ReviewAnswer;
    $answer->forceFill([
        'review_id' => $review->getKey(),
        'review_question_id' => $textOnly->getKey(),
        'value_int' => null, 'value_text' => 'Good, but no numbers from me', 'value_bool' => null, 'choice_key' => null,
    ])->save();

    app(ComputeSubmissionScore::class)->handle($this->submission);

    $fresh = $this->submission->fresh();

    // Two people reviewed it; one score exists. Both numbers are true and the
    // ranking prints both.
    expect($fresh?->review_count)->toBe(2)
        ->and($fresh?->score)->toBe('75.00')
        ->and($fresh?->score_spread)->toBeNull()
        ->and($review->fresh()?->score)->toBeNull();
});

it('clears the columns of an abstract whose reviews have gone', function () {
    scoredReview($this->submission, $this->form, $this->question, 5);
    app(ComputeSubmissionScore::class)->handle($this->submission);
    expect($this->submission->fresh()?->score)->toBe('100.00');

    Review::query()->delete();
    app(ComputeSubmissionScore::class)->handle($this->submission);

    // Back to "nobody has reviewed this", not "everybody scored it zero".
    $fresh = $this->submission->fresh();
    expect($fresh?->score)->toBeNull()
        ->and($fresh?->score_spread)->toBeNull()
        ->and($fresh?->review_count)->toBe(0)
        // scored_at still moves: the answer "no reviews" was computed just now,
        // and an organizer asking "is this up to date" needs the timestamp to
        // mean "last computed", not "last non-null".
        ->and($fresh?->scored_at)->not->toBeNull();
});

it('is idempotent', function () {
    scoredReview($this->submission, $this->form, $this->question, 4);
    scoredReview($this->submission, $this->form, $this->question, 2);

    app(ComputeSubmissionScore::class)->handle($this->submission);
    $first = $this->submission->fresh();

    app(ComputeSubmissionScore::class)->handle($this->submission);
    app(ComputeSubmissionScore::class)->handle($this->submission);
    $third = $this->submission->fresh();

    expect($third?->score)->toBe($first?->score)
        ->and($third?->score_spread)->toBe($first?->score_spread)
        ->and($third?->review_count)->toBe($first?->review_count);
});

// --- The hook into Plan 4 -----------------------------------------------

it('is fired by submitting a review', function () {
    // The whole point of the one-line hook: nothing else in the application
    // calls ComputeSubmissionScore on the happy path, so if this line is ever
    // dropped from SubmitReview the ranking silently shows stale zeros.
    $reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $reviewer->id]);

    expect($this->submission->fresh()?->score)->toBeNull();

    app(SubmitReview::class)->handle($this->submission, $reviewer, [$this->question->ulid => 4]);

    expect($this->submission->fresh()?->score)->toBe('75.00')
        ->and($this->submission->fresh()?->review_count)->toBe(1);
});

it('is fired by reopening a review', function () {
    $reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $reviewer->id]);

    $review = app(SubmitReview::class)->handle($this->submission, $reviewer, [$this->question->ulid => 4]);
    expect($this->submission->fresh()?->review_count)->toBe(1);

    app(ReopenReview::class)->handle($review, $reviewer);

    // A reopened review is a review the committee no longer has, so the
    // aggregate drops it - and the answers keep their own score.
    expect($this->submission->fresh()?->review_count)->toBe(0)
        ->and($this->submission->fresh()?->score)->toBeNull()
        ->and($review->fresh()?->score)->toBe('75.00');
});
