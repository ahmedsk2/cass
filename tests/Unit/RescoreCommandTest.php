<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\ReviewStatus;
use App\Models\Conference;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\Submission;
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
});

it('rescores every abstract of one conference by its ulid', function () {
    $first = Submission::factory()->for($this->conference)->submitted()->create();
    $second = Submission::factory()->for($this->conference)->submitted()->create();
    scoredReview($first, $this->form, $this->question, 5);
    scoredReview($second, $this->form, $this->question, 1);

    // Nothing has computed anything yet: the rows were written directly, which
    // is exactly the state the Plan 6 legacy import leaves behind.
    expect($first->fresh()?->score)->toBeNull();

    $this->artisan('cass:rescore', ['conference' => $this->conference->ulid])
        ->expectsOutputToContain('2')
        ->assertSuccessful();

    expect($first->fresh()?->score)->toBe('100.00')
        ->and($second->fresh()?->score)->toBe('0.00');
});

it('accepts the numeric id as well, and refuses anything else', function () {
    Submission::factory()->for($this->conference)->submitted()->create();

    $this->artisan('cass:rescore', ['conference' => (string) $this->conference->id])->assertSuccessful();

    // A failed lookup is exit code 1 and a sentence, not an exception trace in
    // an operator's terminal.
    $this->artisan('cass:rescore', ['conference' => 'not-a-conference'])
        ->expectsOutputToContain('No conference')
        ->assertFailed();
});

it('leaves another conference alone', function () {
    $mine = Submission::factory()->for($this->conference)->submitted()->create();
    scoredReview($mine, $this->form, $this->question, 5);

    $other = Conference::factory()->create(['status' => ConferenceStatus::Reviewing]);
    $otherForm = ReviewForm::factory()->for($other)->create(['is_active' => true]);
    $otherQuestion = ReviewQuestion::factory()->for($otherForm)->create(['scale_min' => 1, 'scale_max' => 5]);
    $theirs = Submission::factory()->for($other)->submitted()->create();
    scoredReview($theirs, $otherForm, $otherQuestion, 5);

    $this->artisan('cass:rescore', ['conference' => $this->conference->ulid])->assertSuccessful();

    expect($mine->fresh()?->score)->toBe('100.00')
        ->and($theirs->fresh()?->score)->toBeNull();
});

it('rescores a draft and a withdrawn abstract too', function () {
    // The command is a repair tool, not a report: it recomputes every row of
    // the conference, so an abstract that is withdrawn today and reinstated by
    // hand tomorrow does not carry a stale number.
    $draft = Submission::factory()->for($this->conference)->create();
    $withdrawn = Submission::factory()->for($this->conference)->withdrawn()->create();
    scoredReview($draft, $this->form, $this->question, 5, ReviewStatus::Submitted);
    scoredReview($withdrawn, $this->form, $this->question, 3, ReviewStatus::Submitted);

    $this->artisan('cass:rescore', ['conference' => $this->conference->ulid])->assertSuccessful();

    expect($draft->fresh()?->score)->toBe('100.00')
        ->and($withdrawn->fresh()?->score)->toBe('50.00');
});
