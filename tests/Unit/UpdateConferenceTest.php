<?php

declare(strict_types=1);

use App\Actions\Conferences\UpdateConference;
use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewMode;
use App\Exceptions\ConferenceUpdateRefused;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Submission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->owner = User::factory()->create();
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
    $this->make = fn (ConferenceStatus $status): Conference => Conference::factory()->for($this->organization)
        ->create(['status' => $status, 'review_mode' => ReviewMode::OpenPool, 'blind_review' => true, 'review_deadline' => Carbon::parse('2026-11-03 20:59:00', 'UTC')]);
    $this->change = ['review_mode' => ReviewMode::Assigned->value, 'blind_review' => false];
    $this->refusals = [__('organizer.update.errors.review_mode_locked'), __('organizer.update.errors.blind_review_locked')];
});

it('refuses a change to either review setting from reviewing onward, and not before', function (ConferenceStatus $status, bool $locked) {
    $conference = ($this->make)($status);

    expect(UpdateConference::reviewSettingsLocked($conference))->toBe($locked)
        ->and(app(UpdateConference::class)->blockers($conference, $this->change, $this->owner))->toBe($locked ? $this->refusals : [])
        // The stored values, as the disabled fields send them back, are never a change.
        ->and(app(UpdateConference::class)->blockers($conference, ['review_mode' => ReviewMode::OpenPool, 'blind_review' => true], $this->owner))->toBe([]);
})->with([
    'draft' => [ConferenceStatus::Draft, false], 'open' => [ConferenceStatus::Open, false], 'closed' => [ConferenceStatus::Closed, false],
    'reviewing' => [ConferenceStatus::Reviewing, true], 'decided' => [ConferenceStatus::Decided, true], 'archived' => [ConferenceStatus::Archived, true],
]);

it('saves an open conference\'s settings and names the actor as causer of a deadline edit', function () {
    $conference = ($this->make)(ConferenceStatus::Open);

    // Nobody is signed in: the causer comes from the action, not the auth guard.
    app(UpdateConference::class)->handle($conference, [...$this->change, 'review_deadline' => '2026-11-10 20:59:00'], $this->owner);
    $entry = $conference->activitiesAsSubject()->where('event', 'updated')->sole();

    expect($conference->fresh()?->review_mode)->toBe(ReviewMode::Assigned)
        ->and($entry->causer_id)->toBe($this->owner->id)
        ->and(Carbon::parse($entry->attribute_changes['old']['review_deadline'])->equalTo(Carbon::parse('2026-11-03 20:59:00', 'UTC')))->toBeTrue()
        ->and(Carbon::parse($entry->attribute_changes['attributes']['review_deadline'])->equalTo(Carbon::parse('2026-11-10 20:59:00', 'UTC')))->toBeTrue();
});

it('refuses an edit by a member of another organization', function () {
    $conference = ($this->make)(ConferenceStatus::Draft);
    $stranger = User::factory()->create();
    Organization::factory()->approved()->create()->addMember($stranger, OrganizationRole::Owner);

    expect(fn () => app(UpdateConference::class)->handle($conference, ['name' => 'Taken over'], $stranger))
        ->toThrow(ConferenceUpdateRefused::class, __('organizer.update.errors.not_allowed'))
        ->and($conference->fresh()?->name)->not->toBe('Taken over');
});

it('locks on a review of this conference, trashed abstracts included, and never on a review of another conference', function () {
    $conference = ($this->make)(ConferenceStatus::Closed);
    $sibling = ($this->make)(ConferenceStatus::Closed);
    Review::factory()->for(Submission::factory()->for($sibling)->submitted())->create();

    expect(UpdateConference::reviewSettingsLocked($conference))->toBeFalse()
        ->and(UpdateConference::reviewSettingsLocked($sibling))->toBeTrue();

    $trashed = Submission::factory()->for($conference)->submitted()->create();
    Review::factory()->for($trashed)->create();
    $trashed->delete();

    expect(UpdateConference::reviewSettingsLocked($conference))->toBeTrue()
        ->and(app(UpdateConference::class)->blockers($conference, $this->change, $this->owner))->toBe($this->refusals);
});
