<?php

declare(strict_types=1);

use App\Actions\Conferences\MarkDecided;
use App\Actions\Decisions\ApplyDecision;
use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\SubmissionStatus;
use App\Exceptions\ConferenceNotPublishable;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Review;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->conference = Conference::factory()->closed()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->subDay(),
    ]);
});

it('moves a fully decided conference to decided and logs it', function () {
    Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral, notified: true)->create();
    Submission::factory()->for($this->conference)->decided(Decision::Rejected, notified: true)->create();

    $conference = $this->conference->fresh() ?? $this->conference;

    expect(app(MarkDecided::class)->blockers($conference))->toBe([]);

    $result = app(MarkDecided::class)->handle($conference, $this->actor);

    expect($result->status)->toBe(ConferenceStatus::Decided)
        ->and(Activity::query()->where('description', 'conference.decided')->count())->toBe(1);
});

it('refuses while any abstract is still waiting for an answer', function () {
    Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral)->create();
    $waiting = Submission::factory()->for($this->conference)->submitted()->create();
    $waiting->forceFill(['status' => SubmissionStatus::UnderReview])->save();

    $conference = $this->conference->fresh() ?? $this->conference;
    $blockers = app(MarkDecided::class)->blockers($conference);

    expect($blockers)->not->toBe([])
        ->and(implode(' ', $blockers))->toContain('1')
        ->and(fn () => app(MarkDecided::class)->handle($conference, $this->actor))
        ->toThrow(ConferenceNotPublishable::class);

    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Reviewing);
});

it('ignores drafts and withdrawals when it counts what is still open', function () {
    Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral)->create();
    Submission::factory()->for($this->conference)->create();
    Submission::factory()->for($this->conference)->withdrawn()->create();

    // Neither is under consideration (App\Support\Scoring\RankedSubmissions),
    // so neither can hold the conference open.
    expect(app(MarkDecided::class)->blockers($this->conference->fresh() ?? $this->conference))->toBe([]);
});

it('refuses an empty conference and a conference in the wrong status', function () {
    expect(app(MarkDecided::class)->blockers($this->conference))->not->toBe([]);

    $closed = Conference::factory()->closed()->create();
    Submission::factory()->for($closed)->decided(Decision::AcceptedOral)->create();

    expect(app(MarkDecided::class)->blockers($closed->fresh() ?? $closed))->not->toBe([]);
});

it('allows the move while letters are still queued, and says how many', function () {
    Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral, notified: true)->create();
    Submission::factory()->for($this->conference)->decided(Decision::Rejected)->create();

    $conference = $this->conference->fresh() ?? $this->conference;

    // Not a blocker: the queue draining is not a property of the committee's
    // work, and blocking on it would tie a status transition to a worker.
    expect(app(MarkDecided::class)->blockers($conference))->toBe([])
        ->and(app(MarkDecided::class)->unsentLetters($conference))->toBe(1);
});

it('counts the decisions of a conference, one query, with the empty ones at zero', function () {
    Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral, notified: true)->create();
    Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral)->create();
    Submission::factory()->for($this->conference)->decided(Decision::Rejected)->create();
    Submission::factory()->for($this->conference)->submitted()->create();

    $counts = ($this->conference->fresh() ?? $this->conference)->decisionCounts();

    expect($counts['decided'])->toBe(3)
        ->and($counts['undecided'])->toBe(1)
        ->and($counts['notified'])->toBe(1)
        ->and($counts['by_decision'][Decision::AcceptedOral->value])->toBe(2)
        ->and($counts['by_decision'][Decision::AcceptedPoster->value])->toBe(0)
        ->and($counts['by_decision'][Decision::Rejected->value])->toBe(1);
});

it('keeps a decided abstract inside the review progress printed beside the decisions', function () {
    $this->conference->forceFill(['reviewers_per_submission' => 1])->save();

    $reviewer = ConferenceReviewer::factory()->for($this->conference)->create();
    User::query()->whereKey($reviewer->user_id)->update(['name' => 'Dr Omar Khan']);

    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    Review::factory()->for($submission)->submitted()->create(['reviewer_user_id' => $reviewer->user_id]);

    app(ApplyDecision::class)->handle($submission, Decision::Rejected, $this->actor);

    // ApplyDecision rewrites submissions.status while the conference is still
    // `reviewing`, and review progress is printed on the SAME conference view as
    // the decision counts: an abstract that fell out of the denominator the
    // moment it was decided makes that page read "Submitted 0 / 0" and
    // "Dr Omar Khan - 0 / 0" beside "1 decided". RankedSubmissions is the one
    // definition of what the committee is looking at, decided rows included.
    $progress = ($this->conference->fresh() ?? $this->conference)->reviewProgress();

    expect($progress['expected'])->toBe(1)
        ->and($progress['submitted'])->toBe(1)
        ->and($progress['reviewers'])->toBe([
            ['name' => 'Dr Omar Khan', 'submitted' => 1, 'expected' => 1],
        ]);
});
