<?php

declare(strict_types=1);

use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\SubmissionFile;
use App\Models\Track;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores a submission with a ulid, draft status and the casts the panel reads', function () {
    $submission = Submission::factory()->create([
        'title' => 'Early mobilisation after cardiac surgery',
        'presentation_preference' => PresentationPreference::Poster,
        'custom_field_values' => ['funding_source' => 'None'],
    ]);

    // Read it back: the factory's in-memory attributes satisfy every assertion
    // below even with the casts removed, so only a round trip exercises them.
    $submission->refresh();

    expect($submission->ulid)->toHaveLength(26)
        ->and($submission->status)->toBe(SubmissionStatus::Draft)
        ->and($submission->presentation_preference)->toBe(PresentationPreference::Poster)
        ->and($submission->custom_field_values)->toBe(['funding_source' => 'None'])
        ->and($submission->word_count)->toBeInt()
        ->and($submission->reference)->toBeNull()
        ->and($submission->submitted_at)->toBeNull()
        ->and(strlen((string) $submission->access_token_hash))->toBe(64);
});

it('refuses to mass assign the fields only the actions may write', function (string $attribute, mixed $value) {
    // Model::preventSilentlyDiscardingAttributes() is on outside production, so
    // a form field that reaches fill() by accident throws here instead of being
    // silently dropped. These nine are the whole guarded surface.
    expect(fn () => (new Submission)->fill([$attribute => $value]))
        ->toThrow(MassAssignmentException::class);
})->with([
    ['status', 'submitted'],
    ['ulid', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['reference', 'GPCC26-001'],
    ['access_token_hash', str_repeat('a', 64)],
    ['submitted_at', '2026-09-11 10:00:00'],
    ['withdrawn_at', '2026-09-11 10:00:00'],
    ['last_edited_at', '2026-09-11 10:00:00'],
    ['word_count', 250],
    ['conference_id', 1],
]);

it('orders authors and files by sort and finds the corresponding author', function () {
    $submission = Submission::factory()->create();

    SubmissionAuthor::factory()->for($submission)->create(['sort' => 2, 'name' => 'Second', 'email' => 'second@example.org']);
    SubmissionAuthor::factory()->for($submission)->corresponding()->create(['sort' => 1, 'name' => 'First', 'email' => 'first@example.org']);
    SubmissionFile::factory()->for($submission)->create(['sort' => 2, 'original_name' => 'appendix.pdf']);
    SubmissionFile::factory()->for($submission)->create(['sort' => 1, 'original_name' => 'abstract.pdf']);

    expect($submission->authors->pluck('name')->all())->toBe(['First', 'Second'])
        ->and($submission->files->pluck('original_name')->all())->toBe(['abstract.pdf', 'appendix.pdf'])
        ->and($submission->correspondingAuthor()?->email)->toBe('first@example.org');
});

it('keeps a track optional and detaches it rather than deleting the submission', function () {
    $conference = Conference::factory()->create();
    $track = Track::factory()->for($conference)->create();
    $submission = Submission::factory()->for($conference)->create(['track_id' => $track->id]);

    expect($submission->track?->is($track))->toBeTrue();

    $track->delete();

    expect($submission->refresh()->track_id)->toBeNull()
        ->and(Submission::query()->whereKey($submission->getKey())->exists())->toBeTrue();
});

it('derives the reference prefix when a conference is created and leaves an explicit one alone', function () {
    $derived = Conference::factory()->create([
        'name' => 'Gulf Pediatric Critical Care',
        'starts_at' => '2026-11-03',
    ]);
    $chosen = Conference::factory()->create(['reference_prefix' => 'KSAU30']);

    expect($derived->refresh()->reference_prefix)->toBe('GPCC26')
        ->and($derived->submission_counter)->toBe(0)
        ->and($chosen->refresh()->reference_prefix)->toBe('KSAU30');
});

it('counts submissions by status for the conference view', function () {
    $conference = Conference::factory()->create();
    Submission::factory()->count(2)->for($conference)->create();
    Submission::factory()->count(3)->for($conference)->submitted()->create();
    Submission::factory()->for($conference)->withdrawn()->create();

    expect($conference->submissions()->count())->toBe(6)
        ->and($conference->submissionCounts())->toBe([
            'total' => 6,
            'draft' => 2,
            'submitted' => 3,
            'withdrawn' => 1,
        ]);
});

it('stores a per-conference email template override and an email log row', function () {
    $conference = Conference::factory()->create();
    $template = EmailTemplate::factory()->for($conference)->create(['key' => 'submission_received']);
    $log = EmailLog::factory()->for($conference)->create(['to_email' => 'author@example.org']);

    expect($conference->emailTemplates()->count())->toBe(1)
        ->and($template->conference->is($conference))->toBeTrue()
        ->and($log->refresh()->status->value)->toBe('queued')
        ->and($log->ulid)->toHaveLength(26);
});
