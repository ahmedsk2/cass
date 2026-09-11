<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use App\Support\Reviews\ReviewerScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->reviewer = User::factory()->create();
    $this->conference = Conference::factory()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_mode' => ReviewMode::OpenPool,
    ]);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->reviewer->id]);

    $this->submitted = Submission::factory()->for($this->conference)->submitted()->create();
});

it('shows every submitted abstract in an open pool conference', function () {
    $second = Submission::factory()->for($this->conference)->submitted()->create();

    expect(ReviewerScope::submissions($this->reviewer)->pluck('id')->sort()->values()->all())
        ->toBe([$this->submitted->id, $second->id])
        ->and(ReviewerScope::allows($this->reviewer, $second))->toBeTrue();
});

it('counts an under_review abstract and excludes a draft or a withdrawal', function () {
    $underReview = Submission::factory()->for($this->conference)->submitted()->create();
    $underReview->forceFill(['status' => SubmissionStatus::UnderReview])->save();
    $draft = Submission::factory()->for($this->conference)->create();
    $withdrawn = Submission::factory()->for($this->conference)->withdrawn()->create();

    $visible = ReviewerScope::submissions($this->reviewer)->pluck('id')->all();

    expect($visible)->toContain($underReview->id)
        ->not->toContain($draft->id)
        ->not->toContain($withdrawn->id)
        ->and(ReviewerScope::allows($this->reviewer, $draft))->toBeFalse()
        ->and(ReviewerScope::allows($this->reviewer, $withdrawn))->toBeFalse();
});

it('shows nothing until the conference is in review, and shows it again once decided', function () {
    foreach ([ConferenceStatus::Open, ConferenceStatus::Closed, ConferenceStatus::Draft, ConferenceStatus::Archived] as $status) {
        $this->conference->forceFill(['status' => $status])->save();

        expect(ReviewerScope::submissions($this->reviewer)->count())->toBe(0, $status->value);
    }

    foreach ([ConferenceStatus::Reviewing, ConferenceStatus::Decided] as $status) {
        $this->conference->forceFill(['status' => $status])->save();

        expect(ReviewerScope::submissions($this->reviewer)->count())->toBe(1, $status->value);
    }
});

it('shows only the assignments in an assigned conference', function () {
    $this->conference->forceFill(['review_mode' => ReviewMode::Assigned])->save();
    $mine = Submission::factory()->for($this->conference)->submitted()->create();
    ReviewAssignment::factory()->for($mine)->create(['reviewer_user_id' => $this->reviewer->id]);

    expect(ReviewerScope::submissions($this->reviewer)->pluck('id')->all())->toBe([$mine->id])
        ->and(ReviewerScope::allows($this->reviewer, $this->submitted))->toBeFalse()
        ->and(ReviewerScope::allows($this->reviewer, $mine))->toBeTrue();
});

it('shows nothing to a removed reviewer, even in an open pool', function () {
    ConferenceReviewer::query()->where('user_id', $this->reviewer->id)->firstOrFail()
        ->forceFill(['status' => ReviewerStatus::Removed, 'removed_at' => now()])->save();

    expect(ReviewerScope::submissions($this->reviewer)->count())->toBe(0)
        ->and(ReviewerScope::allows($this->reviewer, $this->submitted))->toBeFalse();
});

it('shows nothing from a conference this person does not review', function () {
    $other = Conference::factory()->create(['status' => ConferenceStatus::Reviewing]);
    $theirs = Submission::factory()->for($other)->submitted()->create();

    expect(ReviewerScope::allows($this->reviewer, $theirs))->toBeFalse()
        ->and(ReviewerScope::submissions($this->reviewer)->pluck('id')->all())->not->toContain($theirs->id);
});

it('narrows to one conference when asked', function () {
    $second = Conference::factory()->create(['status' => ConferenceStatus::Reviewing]);
    ConferenceReviewer::factory()->for($second)->create(['user_id' => $this->reviewer->id]);
    Submission::factory()->for($second)->submitted()->create();

    expect(ReviewerScope::submissions($this->reviewer)->count())->toBe(2)
        ->and(ReviewerScope::submissions($this->reviewer, $this->conference)->count())->toBe(1);
});

it('lists the conferences a reviewer has work in', function () {
    $quiet = Conference::factory()->create(['status' => ConferenceStatus::Reviewing]);
    ConferenceReviewer::factory()->for($quiet)->create(['user_id' => $this->reviewer->id]);

    expect(ReviewerScope::conferences($this->reviewer)->pluck('id')->all())
        ->toContain($this->conference->id)
        ->toContain($quiet->id);
});
