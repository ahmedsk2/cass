<?php

declare(strict_types=1);

use App\Actions\Submissions\SaveSubmissionDraft;
use App\Actions\Submissions\SendSubmissionStatusLink;
use App\Actions\Submissions\SubmitAbstract;
use App\Enums\CustomFieldType;
use App\Enums\EmailTemplateKey;
use App\Enums\OrganizationRole;
use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use App\Exceptions\SubmissionNotAcceptable;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Notifications\NewSubmissionNotice;
use App\Support\Submissions\SubmissionLink;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Gulf Pediatric Society']);
    $this->conference = Conference::factory()->for($this->organization)->published()->create([
        'name' => 'Gulf Pediatric Critical Care 2026',
        'word_limit' => 10,
        'reference_prefix' => 'GPCC26',
        'presentation_types' => ['oral', 'poster'],
    ]);
});

/** A saved draft that is ready to submit, plus the plaintext token. */
function readyDraft(Conference $conference, array $overrides = []): SubmissionLink
{
    return app(SaveSubmissionDraft::class)->handle($conference, array_replace([
        'title' => 'Early mobilisation after cardiac surgery',
        'abstract' => 'Background methods results and a short conclusion here now',
        'track_id' => null,
        'presentation_preference' => PresentationPreference::Oral->value,
        'contact_phone' => '+966500000000',
        'custom_field_values' => null,
        'authors' => [
            ['name' => 'Dr Sara Al-Harbi', 'email' => 'sara@example.org', 'affiliation' => 'KFSH', 'is_presenter' => true, 'is_corresponding' => true],
        ],
    ], $overrides));
}

it('assigns a reference, stamps submitted_at and emails the corresponding author', function () {
    Mail::fake();
    Notification::fake();

    $link = readyDraft($this->conference);

    $submission = app(SubmitAbstract::class)->handle($link->submission, agreed: true, plainToken: $link->token);

    expect($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($submission->reference)->toBe('GPCC26-001')
        ->and($submission->submitted_at)->not->toBeNull();

    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('sara@example.org')
        && $mail->templateKey === EmailTemplateKey::SubmissionReceived->value
        // The same link the draft email carried: the author's bookmark keeps
        // working across the submit.
        && str_contains($mail->body, $link->token));

    $log = EmailLog::query()->firstOrFail();
    expect($log->template_key)->toBe('submission_received')
        ->and($log->submission_id)->toBe($submission->id)
        ->and($log->conference_id)->toBe($this->conference->id);
});

it('numbers submissions in order within a conference and separately across conferences', function () {
    Mail::fake();
    Notification::fake();

    $other = Conference::factory()->for($this->organization)->published()->create(['reference_prefix' => 'KSAU30', 'word_limit' => 10]);

    $a = readyDraft($this->conference);
    $b = readyDraft($this->conference);
    $c = readyDraft($other);

    expect(app(SubmitAbstract::class)->handle($a->submission, true, $a->token)->reference)->toBe('GPCC26-001')
        ->and(app(SubmitAbstract::class)->handle($b->submission, true, $b->token)->reference)->toBe('GPCC26-002')
        ->and(app(SubmitAbstract::class)->handle($c->submission, true, $c->token)->reference)->toBe('KSAU30-001');
});

it('notifies only the members who opted in', function () {
    Mail::fake();
    Notification::fake();

    $wants = User::factory()->create();
    $doesNot = User::factory()->create();
    $this->organization->addMember($wants, OrganizationRole::Owner);
    $this->organization->addMember($doesNot, OrganizationRole::Member);
    $this->organization->members()->updateExistingPivot($doesNot->id, ['notify_on_submission' => false]);

    $link = readyDraft($this->conference);
    app(SubmitAbstract::class)->handle($link->submission, true, $link->token);

    Notification::assertSentTo($wants, NewSubmissionNotice::class);
    Notification::assertNotSentTo($doesNot, NewSubmissionNotice::class);
});

it('selects exactly the members who opted in', function () {
    // The same query the pipeline case in tests/Feature/Mail/EmailLogPipelineTest.php
    // writes out by hand. This is the one that pins the shared definition, so
    // that file stays a test of the pipeline rather than of this method.
    $wants = User::factory()->create();
    $doesNot = User::factory()->create();
    $this->organization->addMember($wants, OrganizationRole::Owner);
    $this->organization->addMember($doesNot, OrganizationRole::Member);
    $this->organization->members()->updateExistingPivot($doesNot->id, ['notify_on_submission' => false]);

    expect(SubmitAbstract::notifiableMembers($this->conference)->pluck('id')->all())->toBe([$wants->id]);
});

it('reissues the token when the caller has no plaintext', function () {
    Mail::fake();
    Notification::fake();

    $link = readyDraft($this->conference);
    $oldHash = $link->submission->access_token_hash;

    app(SubmitAbstract::class)->handle($link->submission, agreed: true, plainToken: null);

    expect($link->submission->refresh()->access_token_hash)->not->toBe($oldHash)
        ->and(Submission::findByPlainToken((string) $link->token))->toBeNull();
});

it('lists every blocker in spec 5.3 rather than stopping at the first', function (array $mutate, string $expected) {
    $link = readyDraft($this->conference, $mutate['data'] ?? []);

    if (isset($mutate['then'])) {
        $mutate['then']($link->submission, $this->conference);
    }

    $blockers = app(SubmitAbstract::class)->blockers($link->submission->refresh(), $mutate['agreed'] ?? true);

    expect(implode(' | ', $blockers))->toContain($expected);
})->with([
    'over the word limit' => [[
        'data' => ['abstract' => 'one two three four five six seven eight nine ten eleven'],
    ], '10 words'],
    'no title' => [['data' => ['title' => '   ']], 'title'],
    'empty abstract' => [['data' => ['abstract' => '  ']], 'abstract'],
    'no authors' => [[
        'then' => fn (Submission $s) => $s->authors()->delete(),
    ], 'at least one author'],
    'presentation type the conference does not offer' => [[
        'data' => ['presentation_preference' => PresentationPreference::Either->value],
    ], 'presentation'],
    'presentation type that is not a known option at all' => [[
        // Saving this must not be a ValueError out of the enum cast: the draft
        // drops it like a foreign track and the blocker reports it here.
        'data' => ['presentation_preference' => 'keynote'],
    ], 'presentation'],
    'agreement not ticked' => [['agreed' => false], 'terms'],
    'deadline passed' => [[
        'then' => fn (Submission $s, Conference $c) => Carbon::setTestNow($c->submission_deadline->copy()->addMinute()),
    ], 'closed'],
]);

afterEach(fn () => Carbon::setTestNow());

it('refuses a required custom field that is missing, and accepts it once given', function () {
    Mail::fake();
    Notification::fake();

    CustomField::factory()->for($this->conference)->create([
        'label' => 'Ethics approval number',
        'type' => CustomFieldType::Text,
        'required' => true,
    ]);

    $link = readyDraft($this->conference);

    expect(implode(' ', app(SubmitAbstract::class)->blockers($link->submission, true)))
        ->toContain('Ethics approval number');

    $link->submission->forceFill(['custom_field_values' => ['ethics_approval_number' => 'IRB-2026-14']])->save();

    expect(app(SubmitAbstract::class)->blockers($link->submission->refresh(), true))->toBe([]);
});

it('refuses a select answer that is not one of the offered options', function () {
    CustomField::factory()->for($this->conference)->create([
        'label' => 'Study design',
        'type' => CustomFieldType::Select,
        'options' => ['Randomised', 'Observational'],
        'required' => true,
    ]);

    $link = readyDraft($this->conference);
    $link->submission->forceFill(['custom_field_values' => ['study_design' => 'Made up']])->save();

    expect(implode(' ', app(SubmitAbstract::class)->blockers($link->submission->refresh(), true)))
        ->toContain('Study design');
});

it('refuses a track from another conference at submit time too', function () {
    $link = readyDraft($this->conference);
    $foreign = Track::factory()->for(Conference::factory()->published())->create();
    // Straight into the column, past SaveSubmissionDraft's own filter: the
    // submit gate must not assume the draft path was the only writer.
    $link->submission->forceFill(['track_id' => $foreign->id])->save();

    expect(implode(' ', app(SubmitAbstract::class)->blockers($link->submission->refresh(), true)))
        ->toContain('track');
});

it('throws with every reason and changes nothing when it refuses', function () {
    Mail::fake();

    $link = readyDraft($this->conference, ['title' => '  ']);

    expect(fn () => app(SubmitAbstract::class)->handle($link->submission, agreed: false))
        ->toThrow(SubmissionNotAcceptable::class);

    expect($link->submission->refresh()->status)->toBe(SubmissionStatus::Draft)
        ->and($link->submission->reference)->toBeNull()
        ->and($this->conference->refresh()->submission_counter)->toBe(0);

    Mail::assertNothingQueued();
});

it('refuses to submit an abstract twice', function () {
    Mail::fake();
    Notification::fake();

    $link = readyDraft($this->conference);
    app(SubmitAbstract::class)->handle($link->submission, true, $link->token);

    expect(fn () => app(SubmitAbstract::class)->handle($link->submission->refresh(), true))
        ->toThrow(SubmissionNotAcceptable::class);

    expect($this->conference->refresh()->submission_counter)->toBe(1);
});

it('refuses a submit whose draft another request already took', function () {
    Mail::fake();
    Notification::fake();

    $link = readyDraft($this->conference);

    // The row moves behind the instance the caller is holding - which is what a
    // second request submitting the same draft does. blockers() reads the stale
    // in-memory status and passes, so without a locked re-read inside the
    // transaction both callers allocate a reference, one number is burned, the
    // first confirmation email quotes a reference the row no longer holds, and
    // the members get two notices.
    Submission::query()->whereKey($link->submission->getKey())->update([
        'status' => SubmissionStatus::Submitted->value,
        'reference' => 'GPCC26-001',
        'submitted_at' => now(),
    ]);

    expect($link->submission->status)->toBe(SubmissionStatus::Draft);

    expect(fn () => app(SubmitAbstract::class)->handle($link->submission, true, $link->token))
        ->toThrow(SubmissionNotAcceptable::class);

    expect($link->submission->refresh()->reference)->toBe('GPCC26-001')
        ->and($this->conference->refresh()->submission_counter)->toBe(0);

    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

it('resends a status link with a fresh token and the template that matches the status', function () {
    Mail::fake();

    $link = readyDraft($this->conference);

    $draftLog = app(SendSubmissionStatusLink::class)->handle($link->submission);

    expect($draftLog->template_key)->toBe(EmailTemplateKey::SubmissionDraftSaved->value)
        ->and(Submission::findByPlainToken((string) $link->token))->toBeNull();

    $link->submission->forceFill([
        'status' => SubmissionStatus::Submitted,
        'reference' => 'GPCC26-009',
        'submitted_at' => now(),
    ])->save();

    $submittedLog = app(SendSubmissionStatusLink::class)->handle($link->submission);

    expect($submittedLog->template_key)->toBe(EmailTemplateKey::SubmissionReceived->value)
        ->and($submittedLog->to_email)->toBe('sara@example.org');

    Mail::assertQueuedCount(2);
});

it('refuses to resend when there is nobody to send to', function () {
    $link = readyDraft($this->conference);
    $link->submission->authors()->delete();

    expect(fn () => app(SendSubmissionStatusLink::class)->handle($link->submission->refresh()))
        ->toThrow(SubmissionNotAcceptable::class);
});
