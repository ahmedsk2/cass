<?php

declare(strict_types=1);

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewQuestionType;
use App\Enums\ReviewStatus;
use App\Models\Conference;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->conference = Conference::factory()->published()->create();
    $this->form = app(CreateDefaultReviewForm::class)->handle($this->conference);
    $this->submission = Submission::factory()->for($this->conference)->submitted()->create();
    $this->reviewer = User::factory()->create();
});

it('keeps one review per reviewer per submission', function () {
    Review::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]);

    // Spec section 8 lists (submission_id, reviewer_user_id) as unique. SQLite
    // and MySQL both raise here; the action layer never relies on catching it,
    // it is the last line of defence behind SaveReviewDraft's firstOrNew.
    expect(fn () => Review::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]))
        ->toThrow(QueryException::class);
});

it('keeps one assignment per reviewer per submission', function () {
    ReviewAssignment::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]);

    expect(fn () => ReviewAssignment::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]))
        ->toThrow(QueryException::class);
});

it('keeps one answer per question per review', function () {
    $review = Review::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]);
    $question = $this->form->questions()->firstOrFail();

    ReviewAnswer::factory()->for($review)->create(['review_question_id' => $question->id]);

    expect(fn () => ReviewAnswer::factory()->for($review)->create(['review_question_id' => $question->id]))
        ->toThrow(QueryException::class);
});

it('refuses mass assignment on every review table', function () {
    expect(fn () => new Review(['status' => ReviewStatus::Submitted->value]))->toThrow(MassAssignmentException::class)
        ->and(fn () => new ReviewAnswer(['value_int' => 5]))->toThrow(MassAssignmentException::class)
        ->and(fn () => new ReviewAssignment(['submission_id' => 1]))->toThrow(MassAssignmentException::class);
});

it('reads an answer back with the type its question asked for', function () {
    $review = Review::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]);

    $likert = $this->form->questions()->firstOrFail();
    $text = ReviewQuestion::factory()->for($this->form)->freeText()->create(['prompt' => 'Comments', 'sort' => 90]);
    $boolean = ReviewQuestion::factory()->for($this->form)->create([
        'prompt' => 'Anonymised?', 'type' => ReviewQuestionType::Boolean, 'scale_min' => null, 'scale_max' => null, 'sort' => 91,
    ]);
    $select = ReviewQuestion::factory()->for($this->form)->create([
        'prompt' => 'Format', 'type' => ReviewQuestionType::Select, 'scale_min' => null, 'scale_max' => null, 'sort' => 92,
        'options' => [['label' => 'Oral', 'score' => 100], ['label' => 'Poster', 'score' => 50]],
    ]);

    ReviewAnswer::factory()->for($review)->create(['review_question_id' => $likert->id, 'value_int' => 4]);
    ReviewAnswer::factory()->for($review)->create(['review_question_id' => $text->id, 'value_int' => null, 'value_text' => 'Solid work.']);
    ReviewAnswer::factory()->for($review)->create(['review_question_id' => $boolean->id, 'value_int' => null, 'value_bool' => true]);
    ReviewAnswer::factory()->for($review)->create(['review_question_id' => $select->id, 'value_int' => null, 'choice_key' => 'Oral']);

    $answers = $review->refresh()->answers->keyBy('review_question_id');

    expect($answers[$likert->id]->value())->toBe(4)
        ->and($answers[$text->id]->value())->toBe('Solid work.')
        ->and($answers[$boolean->id]->value())->toBeTrue()
        ->and($answers[$select->id]->value())->toBe('Oral')
        // Plan 5 scores from the key. The key IS the label (see the model
        // comment); the lookup is what that plan will call.
        ->and($select->optionScore('Oral'))->toBe(100)
        ->and($select->optionScore('Nonexistent'))->toBeNull()
        ->and($select->optionKeys())->toBe(['Oral', 'Poster']);
});

it('locks a review form once and reports whether it did the locking', function () {
    expect($this->form->isLocked())->toBeFalse()
        ->and($this->form->lockIfUnlocked())->toBeTrue()
        ->and($this->form->refresh()->isLocked())->toBeTrue()
        // Idempotent: the second submitted review must not re-stamp the time.
        ->and($this->form->lockIfUnlocked())->toBeFalse();
});

it('walks from a conference to its reviewers, assignments and reviews', function () {
    $review = Review::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]);
    ReviewAssignment::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]);

    expect($this->submission->reviews)->toHaveCount(1)
        ->and($this->submission->reviewAssignments)->toHaveCount(1)
        ->and($this->reviewer->reviews)->toHaveCount(1)
        ->and($this->reviewer->reviewAssignments)->toHaveCount(1)
        ->and($review->reviewForm->is($this->form))->toBeTrue()
        ->and($this->form->reviews)->toHaveCount(1);
});

it('hides authors from a reviewer only when the conference is blind', function () {
    $member = User::factory()->create();
    $this->conference->organization->addMember($member, OrganizationRole::Owner);

    $this->conference->forceFill(['blind_review' => true])->save();

    expect($this->conference->hidesAuthorsFrom($this->reviewer))->toBeTrue()
        // An organizer is never blinded: spec section 4 gives every
        // organization member "View submissions and files" with no caveat, and
        // somebody has to be able to answer an author's email.
        ->and($this->conference->hidesAuthorsFrom($member))->toBeFalse();

    $this->conference->forceFill(['blind_review' => false])->save();

    expect($this->conference->fresh()?->hidesAuthorsFrom($this->reviewer))->toBeFalse();
});

it('lets a reviewer read a decided conference but not write to it', function () {
    // Two questions, deliberately not one. Reading is open in Reviewing AND
    // Decided - a reviewer may look back at what they said. Writing is open in
    // Reviewing only: a review changed after the committee decided would change
    // the evidence the decision was made on. Task 7's SaveReviewDraft,
    // SubmitReview, ReopenReview and ReviewSubmission::isReadOnly() all ask
    // acceptsReviewWrites(); every read path asks isOpenToReviewers().
    $this->conference->forceFill(['status' => ConferenceStatus::Reviewing])->save();

    expect($this->conference->isOpenToReviewers())->toBeTrue()
        ->and($this->conference->acceptsReviewWrites())->toBeTrue();

    $this->conference->forceFill(['status' => ConferenceStatus::Decided])->save();

    expect($this->conference->isOpenToReviewers())->toBeTrue()
        ->and($this->conference->acceptsReviewWrites())->toBeFalse();

    $this->conference->forceFill(['status' => ConferenceStatus::Closed])->save();

    expect($this->conference->isOpenToReviewers())->toBeFalse()
        ->and($this->conference->acceptsReviewWrites())->toBeFalse();
});
