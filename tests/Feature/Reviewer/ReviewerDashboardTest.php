<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Filament\Reviewer\Pages\Dashboard;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Review;
use App\Models\Submission;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->conference = Conference::factory()->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
        'reviewers_per_submission' => 2,
    ]);
    $this->reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->reviewer->id]);

    $this->first = Submission::factory()->for($this->conference)->submitted()->create();
    $this->second = Submission::factory()->for($this->conference)->submitted()->create();

    actingAs($this->reviewer);
    bootReviewerPanel();
});

it('shows nothing done at the start', function () {
    livewire(Dashboard::class)
        ->assertSee(__('reviewer.progress.yours', ['submitted' => 0, 'expected' => 2]));
});

it('counts a submitted review and ignores a draft', function () {
    Review::factory()->for($this->first)->submitted()->create(['reviewer_user_id' => $this->reviewer->id]);
    Review::factory()->for($this->second)->create(['reviewer_user_id' => $this->reviewer->id]);

    livewire(Dashboard::class)
        ->assertSee(__('reviewer.progress.yours', ['submitted' => 1, 'expected' => 2]));
});

it('does not count another reviewer work as yours', function () {
    $other = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $other->id]);
    Review::factory()->for($this->first)->submitted()->create(['reviewer_user_id' => $other->id]);

    livewire(Dashboard::class)
        ->assertSee(__('reviewer.progress.yours', ['submitted' => 0, 'expected' => 2]));
});

it('shows no progress line for a conference that has not started reviewing', function () {
    $this->conference->forceFill(['status' => ConferenceStatus::Closed])->save();

    livewire(Dashboard::class)
        ->assertSee(__('reviewer.dashboard.not_started'))
        ->assertDontSee(__('reviewer.progress.yours', ['submitted' => 0, 'expected' => 2]));
});
