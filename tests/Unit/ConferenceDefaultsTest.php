<?php

declare(strict_types=1);

use App\Actions\Conferences\CreateConference;
use App\Models\Conference;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a conference without file type or presentation type settings and stores the defaults', function () {
    $organization = Organization::factory()->approved()->create();

    $conference = app(CreateConference::class)->handle($organization, [
        'name' => 'Minimal Conference',
        'timezone' => 'Asia/Riyadh',
        'starts_at' => now()->addMonths(4),
        'ends_at' => now()->addMonths(4)->addDay(),
        'submission_opens_at' => now()->subDay(),
        'submission_deadline' => now()->addMonths(2),
        'review_deadline' => now()->addMonths(3),
    ]);

    $stored = Conference::query()->findOrFail($conference->id);

    expect($stored->allowed_file_types)->toBe(['pdf'])
        ->and($stored->presentation_types)->toBe(['oral', 'poster', 'either'])
        ->and($stored->word_limit)->toBe(500)
        ->and($stored->max_files)->toBe(3);
});

it('keeps explicitly chosen file and presentation types', function () {
    $organization = Organization::factory()->approved()->create();

    $conference = app(CreateConference::class)->handle($organization, [
        'name' => 'Explicit Conference',
        'timezone' => 'Asia/Riyadh',
        'starts_at' => now()->addMonths(4),
        'ends_at' => now()->addMonths(4)->addDay(),
        'submission_opens_at' => now()->subDay(),
        'submission_deadline' => now()->addMonths(2),
        'review_deadline' => now()->addMonths(3),
        'allowed_file_types' => ['pdf', 'docx'],
        'presentation_types' => ['poster'],
    ]);

    expect($conference->fresh()->allowed_file_types)->toBe(['pdf', 'docx'])
        ->and($conference->fresh()->presentation_types)->toBe(['poster']);
});
