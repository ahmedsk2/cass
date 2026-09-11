<?php

declare(strict_types=1);

use App\Actions\Submissions\IssueSubmissionToken;
use App\Enums\OrganizationStatus;
use App\Enums\SubmissionStatus;
use App\Livewire\Public\SubmissionForm;
use App\Livewire\Public\SubmissionStatus as StatusPage;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\Track;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');

    // The two colours are explicit for the same reason SubmissionFormTest sets
    // them: `primary_color` and `accent_color` are database defaults, and a
    // just-created model instance does not carry a default it never read back.
    // Every test below that hands *this* instance to SubmissionForm would
    // otherwise die in OrganizationTheme::for(); the ones that go through HTTP
    // are fine, because there the organization is loaded from the row.
    $this->organization = Organization::factory()->approved()->create([
        'name' => 'Gulf Pediatric Society',
        'primary_color' => '#0F4C8A',
        'accent_color' => '#B45309',
    ]);
    $this->conference = Conference::factory()->for($this->organization)->published()->create([
        'name' => 'Gulf Pediatric Critical Care 2026',
        'word_limit' => 250,
        'presentation_types' => ['oral', 'poster'],
    ]);
    $this->submission = Submission::factory()
        ->for($this->conference)
        ->submitted()
        ->withCorrespondingAuthor('sara@example.org', 'Dr Sara Al-Harbi')
        ->create(['title' => 'Early mobilisation after cardiac surgery']);
    $this->submission->forceFill(['reference' => 'GPCC26-017'])->save();

    $this->token = app(IssueSubmissionToken::class)->handle($this->submission);
});

it('shows the reference, status, title, authors and deadline', function () {
    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee('GPCC26-017')
        ->assertSee('Early mobilisation after cardiac surgery')
        ->assertSee('Dr Sara Al-Harbi')
        ->assertSee('sara@example.org')
        ->assertSee(SubmissionStatus::Submitted->getLabel())
        ->assertSee($this->conference->deadlineInConferenceTimezone()?->format('j F Y, H:i'))
        ->assertSee('Gulf Pediatric Society')
        // The page carries author names, affiliations and addresses behind a
        // URL anyone can paste into a browser. Search engines must not index it.
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});

it('lists files behind fresh signed links and serves them', function () {
    $sha = hash('sha256', 'x');
    Storage::disk('local')->put(substr($sha, 0, 2).'/f.pdf', '%PDF-1.4 test');
    $file = SubmissionFile::factory()->for($this->submission)->create([
        'original_name' => 'abstract.pdf',
        'path' => substr($sha, 0, 2).'/f.pdf',
        'sha256' => $sha,
    ]);

    $response = get('/s/'.$this->token)->assertOk()->assertSee('abstract.pdf');

    // The link on the page is a signed URL that actually works, not a route
    // the browser will 403 on.
    preg_match('#(/files/'.$file->ulid.'\?[^"\']+)#', $response->getContent() ?: '', $matches);

    expect($matches[1] ?? null)->not->toBeNull();

    get(html_entity_decode((string) $matches[1]))->assertOk();
});

it('404s an unknown token and anything that is not one', function () {
    get('/s/'.str_repeat('a', 64))->assertNotFound();
    // Route constraint: exactly 64 of [A-Za-z0-9].
    get('/s/short')->assertNotFound();
    get('/s/'.str_repeat('a', 63).'-')->assertNotFound();
    get('/s/'.str_repeat('a', 65))->assertNotFound();
});

it('does not let one submission token open another', function () {
    $other = Submission::factory()->for($this->conference)->submitted()->withCorrespondingAuthor('other@example.org')->create([
        'title' => 'Somebody else entirely',
    ]);
    app(IssueSubmissionToken::class)->handle($other);

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee('Early mobilisation after cardiac surgery')
        ->assertDontSee('Somebody else entirely');
});

it('stops working once the link has been reissued', function () {
    get('/s/'.$this->token)->assertOk();

    $fresh = app(IssueSubmissionToken::class)->handle($this->submission);

    get('/s/'.$this->token)->assertNotFound();
    get('/s/'.$fresh)->assertOk();
});

it('blocks the twenty-first request from one address in a minute', function () {
    foreach (range(1, 20) as $i) {
        get('/s/'.$this->token)->assertOk();
    }

    get('/s/'.$this->token)->assertStatus(429);
});

it('opens the form prefilled when the author chooses to edit', function () {
    Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);

    livewire(StatusPage::class, ['token' => $this->token])
        ->assertDontSeeLivewire(SubmissionForm::class)
        ->call('startEditing')
        ->assertSeeLivewire(SubmissionForm::class)
        ->assertSee('Early mobilisation after cardiac surgery')
        ->call('cancelEditing')
        ->assertDontSeeLivewire(SubmissionForm::class);
});

it('saves an edit through the nested form and keeps the status and reference', function () {
    // No ->set('openedAt', …): it is #[Locked], and phpunit.xml pins
    // CASS_SUBMISSION_MIN_SECONDS=0 so the gate is off for the suite anyway.
    livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
        'submission' => $this->submission,
        'token' => $this->token,
    ])
        ->assertSet('title', 'Early mobilisation after cardiac surgery')
        ->set('title', 'Early mobilisation after cardiac surgery: a pilot')
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->assertRedirectContains('/s/'.$this->token);

    expect($this->submission->refresh())
        ->title->toBe('Early mobilisation after cardiac surgery: a pilot')
        ->status->toBe(SubmissionStatus::Submitted)
        ->reference->toBe('GPCC26-017');
});

it('attaches and removes a file from the status page edit form', function () {
    // saveDraft() has two branches and the files section is on both forms, so
    // an attachment made here must not be dropped behind a success flash.
    livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
        'submission' => $this->submission,
        'token' => $this->token,
    ])
        // createWithContent() rather than `new UploadedFile($path, ...)`, for
        // the reason SubmissionFormTest's uploadedFixturePdf() records:
        // Livewire's Testable::upload() reads `$file->name`, a public property
        // only Illuminate\Http\Testing\File declares, so a plain UploadedFile
        // raises "Undefined property" before the component is ever reached.
        // The bytes are the real fixture's either way, which is what
        // StoreSubmissionFile's content sniff is here to read.
        ->set('uploads', [UploadedFile::fake()->createWithContent(
            'abstract.pdf',
            (string) file_get_contents(base_path('tests/Fixtures/abstract.pdf')),
        )])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $file = $this->submission->refresh()->files()->firstOrFail();

    expect($file->original_name)->toBe('abstract.pdf');
    Storage::disk('local')->assertExists((string) $file->path);

    livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
        'submission' => $this->submission,
        'token' => $this->token,
    ])->call('deleteFile', $file->ulid);

    expect($this->submission->refresh()->files()->count())->toBe(0);
    Storage::disk('local')->assertMissing((string) $file->path);
});

it('submits a draft from the status page and keeps the author on the same link', function () {
    // Spec 5.3's whole reason for the draft email: save, come back, submit.
    $draft = Submission::factory()
        ->for($this->conference)
        ->withCorrespondingAuthor('draft@example.org', 'Dr Omar Khan')
        ->create(['title' => 'A work in progress', 'abstract' => 'Background methods results conclusion.']);
    $token = app(IssueSubmissionToken::class)->handle($draft);

    livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
        'submission' => $draft,
        'token' => $token,
    ])
        // fillFromSubmission() derives `agreed` from submitted_at, so a draft
        // starts unticked and the author has to agree on the way out.
        ->assertSet('agreed', false)
        ->set('presentation_preference', 'oral')
        ->set('agreed', true)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirectContains('/s/'.$token);

    expect($draft->refresh()->status)->toBe(SubmissionStatus::Submitted)
        ->and($draft->reference)->not->toBeNull()
        // SubmitAbstract was handed the existing token, so the link the author
        // bookmarked from the draft email still opens the abstract.
        ->and(Submission::findByPlainToken($token)?->is($draft))->toBeTrue();
});

it('reports a refusal only the action can make, on the field it belongs to', function () {
    // Every form rule passes - this abstract was filled in through this very
    // form and is already submitted. The only thing left that refuses is
    // SubmitAbstract::blockers()'s "This abstract has already been submitted.",
    // and reportBlockers() maps that sentence onto the abstract field (it
    // contains the word "abstract", not "title"). Without reportBlockers the
    // button would silently do nothing, and no form rule can produce this case.
    livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
        'submission' => $this->submission,
        'token' => $this->token,
    ])->call('submit')->assertHasErrors(['abstract']);

    expect($this->submission->refresh()->status)->toBe(SubmissionStatus::Submitted)
        ->and($this->submission->reference)->toBe('GPCC26-017')
        // No second reference was burnt.
        ->and($this->conference->refresh()->submission_counter)->toBe(0);
});

it('offers neither edit nor withdraw once the window has closed', function () {
    Carbon::setTestNow($this->conference->submission_deadline->copy()->addMinute());

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee('GPCC26-017')
        ->assertDontSee(__('submission.status.edit'))
        ->assertDontSee(__('submission.status.withdraw'));

    livewire(StatusPage::class, ['token' => $this->token])
        ->call('startEditing')
        ->assertDontSeeLivewire(SubmissionForm::class);

    Carbon::setTestNow();
});

it('withdraws on request and refuses to do it twice', function () {
    livewire(StatusPage::class, ['token' => $this->token])
        ->call('withdraw')
        ->assertHasNoErrors();

    expect($this->submission->refresh()->status)->toBe(SubmissionStatus::Withdrawn)
        ->and($this->submission->withdrawn_at)->not->toBeNull()
        // The number stays: an organizer may already have printed it.
        ->and($this->submission->reference)->toBe('GPCC26-017');

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee(SubmissionStatus::Withdrawn->getLabel())
        ->assertDontSee(__('submission.status.withdraw'));

    livewire(StatusPage::class, ['token' => $this->token])
        ->call('withdraw')
        ->assertHasErrors();
});

it('refuses to withdraw after the deadline', function () {
    Carbon::setTestNow($this->conference->submission_deadline->copy()->addMinute());

    livewire(StatusPage::class, ['token' => $this->token])->call('withdraw')->assertHasErrors();

    expect($this->submission->refresh()->status)->toBe(SubmissionStatus::Submitted);

    Carbon::setTestNow();
});

it('shows a draft as unfinished and pushes the author to submit it', function () {
    $draft = Submission::factory()->for($this->conference)->withCorrespondingAuthor('draft@example.org')->create();
    $token = app(IssueSubmissionToken::class)->handle($draft);

    get('/s/'.$token)
        ->assertOk()
        ->assertSee(SubmissionStatus::Draft->getLabel())
        ->assertSee(__('submission.status.draft_warning'))
        ->assertSee(__('submission.status.edit'));
});

it('renders a neutral block for a status plan 3 does not drive yet', function (SubmissionStatus $status) {
    $this->submission->forceFill(['status' => $status])->save();

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee($status->getLabel())
        ->assertSee(__('submission.status.decision_pending'))
        // Plan 5 replaces this block with the decision letter. Until it does,
        // the page must not offer an edit or a withdrawal it cannot honour.
        ->assertDontSee(__('submission.status.edit'))
        ->assertDontSee(__('submission.status.withdraw'));
})->with([
    SubmissionStatus::UnderReview,
    SubmissionStatus::Accepted,
    SubmissionStatus::Rejected,
    SubmissionStatus::Waitlisted,
]);

it('404s when the conference is no longer public', function () {
    get('/s/'.$this->token)->assertOk();

    $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

    // Consistent with the conference page and the short link: a suspended
    // organization goes offline entirely, and the author's own link is part of
    // "entirely".
    get('/s/'.$this->token)->assertNotFound();
});
