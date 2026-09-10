<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\ReviewMode;
use App\Enums\SubmissionWindow;
use App\Models\Conference;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a conference with a ulid, a derived slug and draft status', function () {
    $conference = Conference::factory()->create(['name' => 'Gulf Pediatric Critical Care 2026']);

    // Read the row back: the factory's in-memory attributes would satisfy the
    // enum/boolean/integer/array assertions below even with every cast removed,
    // so only a database round trip actually exercises Conference::casts().
    $conference->refresh();

    expect($conference->ulid)->toHaveLength(26)
        ->and($conference->slug)->toBe('gulf-pediatric-critical-care-2026')
        ->and($conference->status)->toBe(ConferenceStatus::Draft)
        ->and($conference->review_mode)->toBe(ReviewMode::OpenPool)
        ->and($conference->timezone)->toBe('Asia/Riyadh')
        ->and($conference->word_limit)->toBe(500)
        ->and($conference->max_files)->toBe(3)
        ->and($conference->allowed_file_types)->toBe(['pdf'])
        ->and($conference->presentation_types)->toBe(['oral', 'poster', 'either'])
        ->and($conference->blind_review)->toBeTrue();
});

it('makes the slug unique inside one organization but reusable across organizations', function () {
    $alpha = Organization::factory()->approved()->create();
    $beta = Organization::factory()->approved()->create();

    $first = Conference::factory()->for($alpha)->create(['name' => 'Annual Meeting']);
    $second = Conference::factory()->for($alpha)->create(['name' => 'Annual Meeting']);
    $other = Conference::factory()->for($beta)->create(['name' => 'Annual Meeting']);

    expect($first->slug)->toBe('annual-meeting')
        ->and($second->slug)->toBe('annual-meeting-2')
        ->and($other->slug)->toBe('annual-meeting');
});

it('keeps a soft-deleted slug reserved so old links never point at a different conference', function () {
    $organization = Organization::factory()->approved()->create();
    Conference::factory()->for($organization)->create(['name' => 'Winter School'])->delete();

    $replacement = Conference::factory()->for($organization)->create(['name' => 'Winter School']);

    expect($replacement->slug)->toBe('winter-school-2');
});

it('belongs to its organization and answers public visibility per status', function () {
    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->create();

    expect($conference->organization->is($organization))->toBeTrue()
        ->and($conference->isPubliclyVisible())->toBeFalse();

    foreach ([ConferenceStatus::Open, ConferenceStatus::Closed, ConferenceStatus::Reviewing, ConferenceStatus::Decided] as $status) {
        $conference->forceFill(['status' => $status])->save();
        expect($conference->isPubliclyVisible())->toBeTrue();
    }

    $conference->forceFill(['status' => ConferenceStatus::Archived])->save();
    expect($conference->isPubliclyVisible())->toBeFalse();
});

it('reports the submission window state from the dates and the status', function () {
    $conference = Conference::factory()->create([
        'submission_opens_at' => null,
        'submission_deadline' => null,
    ]);
    expect($conference->submissionWindow())->toBe(SubmissionWindow::NotConfigured);

    $conference->forceFill([
        'status' => ConferenceStatus::Open,
        'submission_opens_at' => now()->addDays(3),
        'submission_deadline' => now()->addDays(30),
    ])->save();
    expect($conference->submissionWindow())->toBe(SubmissionWindow::Upcoming);

    $conference->forceFill(['submission_opens_at' => now()->subDay()])->save();
    expect($conference->submissionWindow())->toBe(SubmissionWindow::Open)
        ->and($conference->acceptsSubmissions())->toBeTrue();

    $conference->forceFill(['submission_deadline' => now()->subHour()])->save();
    expect($conference->submissionWindow())->toBe(SubmissionWindow::Closed)
        ->and($conference->acceptsSubmissions())->toBeFalse();

    // A conference the organizer closed by hand is closed even inside the window.
    $conference->forceFill([
        'status' => ConferenceStatus::Closed,
        'submission_deadline' => now()->addDays(10),
    ])->save();
    expect($conference->submissionWindow())->toBe(SubmissionWindow::Closed);
});

it('presents deadlines in the conference timezone while storing utc', function () {
    $conference = Conference::factory()->create([
        'timezone' => 'Asia/Riyadh',
        'submission_deadline' => '2026-11-30 21:00:00',
    ]);

    expect($conference->submission_deadline->toDateTimeString())->toBe('2026-11-30 21:00:00')
        ->and($conference->deadlineInConferenceTimezone()?->format('Y-m-d H:i T'))->toBe('2026-12-01 00:00 +03');
});
