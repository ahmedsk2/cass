<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewMode;
use App\Filament\Organizer\Resources\Conferences\Pages\EditConference;
use App\Filament\Organizer\Resources\Conferences\Pages\ListConferences;
use App\Filament\Organizer\Resources\Conferences\Pages\ViewConference;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Submission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);
    // Whole-minute dates, so a save changes only what a test types.
    $this->make = fn (ConferenceStatus $status): Conference => Conference::factory()->for($this->organization)->create([
        'status' => $status, 'submission_opens_at' => Carbon::parse('2026-09-01 00:00:00', 'UTC'),
        'submission_deadline' => Carbon::parse('2026-10-01 20:59:00', 'UTC'), 'review_deadline' => Carbon::parse('2026-11-03 20:59:00', 'UTC'),
    ]);
    $this->edit = fn (Conference $conference) => livewire(EditConference::class, ['record' => $conference->getRouteKey()]);
});

it('disables both review settings from reviewing onward, and not before', function (ConferenceStatus $status, bool $locked) {
    $page = ($this->edit)(($this->make)($status))->assertSee(__('organizer.review_settings.locked'));

    $locked
        ? $page->assertFormFieldDisabled('review_mode')->assertFormFieldDisabled('blind_review')
        : $page->assertFormFieldEnabled('review_mode')->assertFormFieldEnabled('blind_review');
})->with(['closed' => [ConferenceStatus::Closed, false], 'reviewing' => [ConferenceStatus::Reviewing, true]]);

it('locks a closed conference with a review, asking the database once per request', function () {
    $conference = ($this->make)(ConferenceStatus::Closed);
    Review::factory()->for(Submission::factory()->for($conference)->submitted())->create();
    DB::enableQueryLog();

    $page = ($this->edit)($conference)->assertFormFieldDisabled('review_mode')->assertFormFieldDisabled('blind_review');
    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/["`]reviews["`]/', (string) $query['query']) === 1))->toHaveCount(1);
    $page->fillForm(['review_mode' => ReviewMode::Assigned->value])->call('save')->assertNotified(__('organizer.update.refused'));
    expect($conference->fresh()?->review_mode)->toBe(ReviewMode::OpenPool);
});

it('refuses a forged change to a locked setting and saves nothing from that form', function () {
    $conference = ($this->make)(ConferenceStatus::Reviewing);

    ($this->edit)($conference)->fillForm(['blind_review' => false, 'name' => 'Renamed while refused'])
        ->call('save')->assertNotified(__('organizer.update.refused'));
    expect($conference->fresh()?->blind_review)->toBeTrue()->and($conference->fresh()?->name)->not->toBe('Renamed while refused');
});

it('keeps the deadlines editable while reviewing and logs the edit once, with the organizer as causer', function () {
    $conference = ($this->make)(ConferenceStatus::Reviewing);

    // Typed in the conference's timezone, Asia/Riyadh (UTC+3), and stored in UTC.
    ($this->edit)($conference)->fillForm(['review_deadline' => '2026-11-10 23:59:00'])->call('save')->assertHasNoFormErrors();
    expect($conference->fresh()?->review_deadline?->equalTo(Carbon::parse('2026-11-10 20:59:00', 'UTC')))->toBeTrue()
        ->and($conference->activitiesAsSubject()->where('event', 'updated')->sole()->causer_id)->toBe($this->user->id);
});

it('refuses the same forged change through the view page and the list table', function () {
    $conference = ($this->make)(ConferenceStatus::Reviewing);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->mountAction('edit')->setActionData(['blind_review' => false])->callMountedAction()
        ->assertNotified(__('organizer.update.refused'));
    livewire(ListConferences::class)
        ->mountTableAction('edit', $conference)->setTableActionData(['review_mode' => ReviewMode::Assigned->value])
        ->callMountedTableAction()->assertNotified(__('organizer.update.refused'));

    expect($conference->fresh()?->blind_review)->toBeTrue()
        ->and($conference->fresh()?->review_mode)->toBe(ReviewMode::OpenPool);
});
