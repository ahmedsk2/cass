<?php

declare(strict_types=1);

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Actions\Conferences\StartReviewing;
use App\Enums\ConferenceStatus;
use App\Enums\ReviewMode;
use App\Exceptions\ConferenceNotPublishable;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->conference = Conference::factory()->closed()->create([
        'review_deadline' => now()->addMonth(),
        'reviewers_per_submission' => 2,
    ]);
    app(CreateDefaultReviewForm::class)->handle($this->conference);
});

function readyToReview(Conference $conference): Conference
{
    ConferenceReviewer::factory()->for($conference)->create();
    Submission::factory()->for($conference)->submitted()->create();

    return $conference->fresh() ?? $conference;
}

it('moves a ready conference to reviewing and logs it', function () {
    $conference = readyToReview($this->conference);

    expect(app(StartReviewing::class)->blockers($conference))->toBe([]);

    $result = app(StartReviewing::class)->handle($conference, $this->actor);

    expect($result->status)->toBe(ConferenceStatus::Reviewing)
        ->and(Activity::query()->where('description', 'conference.reviewing')->count())->toBe(1);
});

it('names every blocker at once', function () {
    // No reviewers, no abstracts, no deadline, and the wrong status.
    $conference = Conference::factory()->create(['review_deadline' => null]);

    $blockers = app(StartReviewing::class)->blockers($conference);

    expect($blockers)->toHaveCount(5)
        ->and(fn () => app(StartReviewing::class)->handle($conference, $this->actor))
        ->toThrow(ConferenceNotPublishable::class);
});

it('refuses without an active reviewer', function () {
    ConferenceReviewer::factory()->for($this->conference)->removed()->create();
    Submission::factory()->for($this->conference)->submitted()->create();

    expect(app(StartReviewing::class)->blockers($this->conference->fresh()))->toHaveCount(1);
});

it('refuses without a submitted abstract, and a draft does not count', function () {
    ConferenceReviewer::factory()->for($this->conference)->create();
    Submission::factory()->for($this->conference)->create();

    expect(app(StartReviewing::class)->blockers($this->conference->fresh()))->toHaveCount(1);
});

it('refuses in assigned mode until every abstract has a reviewer', function () {
    $this->conference->forceFill(['review_mode' => ReviewMode::Assigned])->save();
    $reviewer = ConferenceReviewer::factory()->for($this->conference)->create();
    $assigned = Submission::factory()->for($this->conference)->submitted()->create();
    $orphan = Submission::factory()->for($this->conference)->submitted()->create(['title' => 'Nobody is reading this']);

    ReviewAssignment::factory()->for($assigned)->create(['reviewer_user_id' => $reviewer->user_id]);

    $blockers = app(StartReviewing::class)->blockers($this->conference->fresh());

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0])->toContain('1');

    ReviewAssignment::factory()->for($orphan)->create(['reviewer_user_id' => $reviewer->user_id]);

    expect(app(StartReviewing::class)->blockers($this->conference->fresh()))->toBe([]);
});

it('refuses from a status the graph does not allow', function () {
    foreach ([ConferenceStatus::Draft, ConferenceStatus::Open, ConferenceStatus::Decided, ConferenceStatus::Archived] as $status) {
        $conference = readyToReview($this->conference);
        $conference->forceFill(['status' => $status])->save();

        expect(app(StartReviewing::class)->blockers($conference->fresh()))->not->toBe([], $status->value);
    }
});

it('counts the progress an organizer looks at', function () {
    $conference = readyToReview($this->conference);
    $second = Submission::factory()->for($conference)->submitted()->create();
    $reviewer = $conference->reviewers()->firstOrFail();
    $other = ConferenceReviewer::factory()->for($conference)->create();

    Review::factory()->for($conference->submissions()->firstOrFail())->submitted()
        ->create(['reviewer_user_id' => $reviewer->user_id]);
    Review::factory()->for($second)->create(['reviewer_user_id' => $other->user_id]);

    $progress = $conference->fresh()->reviewProgress();

    // Open pool: two abstracts times the conference's own target of 2.
    expect($progress['expected'])->toBe(4)
        ->and($progress['submitted'])->toBe(1)
        ->and($progress['drafts'])->toBe(1)
        ->and($progress['reviewers'])->toHaveCount(2)
        ->and($progress['reviewers'][0]['submitted'] + $progress['reviewers'][1]['submitted'])->toBe(1);
});

it('counts assignments as the expectation in assigned mode', function () {
    $conference = readyToReview($this->conference);
    $conference->forceFill(['review_mode' => ReviewMode::Assigned])->save();
    $reviewer = $conference->reviewers()->firstOrFail();
    ReviewAssignment::factory()->for($conference->submissions()->firstOrFail())
        ->create(['reviewer_user_id' => $reviewer->user_id]);

    expect($conference->fresh()->reviewProgress()['expected'])->toBe(1);
});

it('counts one reviewer own progress for their dashboard', function () {
    $conference = readyToReview($this->conference);
    Submission::factory()->for($conference)->submitted()->create();
    $reviewer = $conference->reviewers()->firstOrFail();
    $user = User::query()->findOrFail($reviewer->user_id);

    Review::factory()->for($conference->submissions()->firstOrFail())->submitted()
        ->create(['reviewer_user_id' => $user->id]);

    $conference->forceFill(['status' => ConferenceStatus::Reviewing])->save();

    // Open pool: a reviewer's own expectation is the whole pool.
    expect($conference->fresh()->reviewProgressFor($user))->toBe(['expected' => 2, 'submitted' => 1]);
});
