<?php

declare(strict_types=1);

use App\Actions\Decisions\ApplyDecision;
use App\Actions\Submissions\IssueSubmissionToken;
use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\OrganizationStatus;
use App\Enums\SubmissionStatus;
use App\Livewire\Public\SubmissionForm;
use App\Livewire\Public\SubmissionStatus as StatusPage;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionDecision;
use App\Models\SubmissionFile;
use App\Models\Track;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;

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

it('404s rather than 500s when the conference or organization is deleted', function (string $target) {
    get('/s/'.$this->token)->assertOk();

    // Both models soft delete and the organizer panel exposes DeleteAction, so
    // either belongsTo can resolve to null while the submission row survives -
    // submissions.conference_id only restricts a *hard* delete.
    // Submission::isOpenToAuthor() already guards this with ?->; without the
    // same guard in mount() every author status link behind a deleted
    // conference is "Call to a member function isPubliclyVisible() on null".
    $this->{$target}->delete();

    get('/s/'.$this->token)->assertNotFound();
})->with(['conference', 'organization']);

it('locks the edit flag against a crafted payload', function () {
    // Every other server-decided boolean in this codebase is #[Locked] for the
    // same reason - isPreview, windowWasOpen, humanVerified. Without it, one
    // `editing=true` in an update payload renders the nested edit form for an
    // abstract startEditing() would refuse, and startEditing()'s
    // isOpenToAuthor() gate is the only thing guarding it.
    $this->submission->forceFill([
        'status' => SubmissionStatus::Withdrawn,
        'withdrawn_at' => now(),
    ])->save();

    $page = livewire(StatusPage::class, ['token' => $this->token])
        ->assertDontSeeLivewire(SubmissionForm::class);

    expect(fn () => $page->set('editing', true))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('reports a refusal instead of a 500 when the abstract is withdrawn mid-edit', function () {
    // UpdateSubmission::handle() throws SubmissionNotAcceptable once the
    // abstract is no longer open to the author, and Livewire rethrows anything
    // that is not a ValidationException - which on this public, unauthenticated
    // page is a 500 over the author's typing. An organizer withdrawing while
    // the form is open is all it takes.
    $draftForm = livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
        'submission' => $this->submission,
        'token' => $this->token,
    ]);
    $submitForm = livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
        'submission' => $this->submission,
        'token' => $this->token,
    ]);

    $this->submission->forceFill([
        'status' => SubmissionStatus::Withdrawn,
        'withdrawn_at' => now(),
    ])->save();

    // "An abstract that is withdrawn can no longer be changed." carries the
    // word "abstract", so reportBlockers() puts it on the abstract field.
    $draftForm->set('title', 'Edited after the withdrawal')->call('saveDraft')->assertHasErrors(['abstract']);
    $submitForm->call('submit')->assertHasErrors(['abstract']);

    expect($this->submission->refresh())
        ->title->toBe('Early mobilisation after cardiac surgery')
        ->status->toBe(SubmissionStatus::Withdrawn);
});

it('shows the withdrawal date in conference time without rewriting the model', function () {
    $this->conference->forceFill(['timezone' => 'Asia/Riyadh'])->save();
    $this->submission->forceFill([
        'status' => SubmissionStatus::Withdrawn,
        'withdrawn_at' => Carbon::parse('2026-10-01 21:30:00', 'UTC'),
    ])->save();

    $component = livewire(StatusPage::class, ['token' => $this->token]);

    // Riyadh is UTC+3, so the reader sees the first of October at half past
    // midnight the next day.
    $component->assertSee('2 October 2026, 00:30');

    // Illuminate\Support\Carbon is mutable and Eloquent caches the cast
    // attribute, so `->setTimezone()` on it rewrites the instance the whole
    // request then carries - the reason Conference::deadlineInConferenceTimezone()
    // copies first.
    expect($component->instance()->submission->withdrawn_at?->getTimezone()->getName())->toBe('UTC');
});

it('redirects after a withdrawal so the banner is shown once', function () {
    // session()->flash() without a redirect paints the banner in this render
    // *and* again on the next request, so a plain reload of /s/{token} repeats
    // "Your abstract has been withdrawn" over an abstract that was withdrawn
    // minutes ago. Every other flash on this page is followed by a redirect.
    livewire(StatusPage::class, ['token' => $this->token])
        ->call('withdraw')
        ->assertHasNoErrors()
        // The redirect is the whole of it: with one, the banner belongs to the
        // page the author lands on and is consumed there. Without one it is
        // painted by this render *and* left in the session for the next
        // request, so a reload an hour later repeats it.
        ->assertRedirect(route('submission.status', ['token' => $this->token]));

    expect($this->submission->refresh()->status)->toBe(SubmissionStatus::Withdrawn);
});

it('saves an edit when the status page did not hand the form a token', function () {
    // `token` is nullable on the component, and route('submission.status') with
    // a null parameter is a UrlGenerationException - a 500 over an edit that
    // was already written. submit() guards exactly this case; saveDraft() did
    // not.
    livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
        'submission' => $this->submission,
        'token' => null,
    ])
        ->set('title', 'A revised title')
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->assertRedirect(route('conference.show', [$this->organization, $this->conference]));

    expect($this->submission->refresh()->title)->toBe('A revised title');
});

// --- The decision letter (Plan 5) ---------------------------------------

it('shows nothing about a decision until the letter has been sent', function () {
    $this->submission->forceFill([
        'status' => SubmissionStatus::Accepted,
        'decision' => Decision::AcceptedOral,
        'decision_notified_at' => null,
    ])->save();

    SubmissionDecision::factory()->for($this->submission)->create([
        'decision' => Decision::AcceptedOral,
        'letter_subject' => 'Prepared but not sent',
        'letter_markdown' => 'This letter has not been sent yet.',
        'notified_at' => null,
    ]);

    // Spec 5.6's "so decisions can be prepared quietly first" reaches all the
    // way to the author's page: a decision that has not been emailed is not
    // visible to the person it is about.
    get('/s/'.$this->token)
        ->assertOk()
        ->assertDontSee('This letter has not been sent yet.')
        ->assertSee(__('submission.status.decision_pending'))
        // ...and that includes the State chip. ApplyDecision writes `status`
        // and `decision` in the same transaction, so a page that prints
        // `status` ungated hands the author the answer days before the letter
        // and contradicts the "we are handling it" block right under it.
        ->assertDontSee(SubmissionStatus::Accepted->getLabel())
        ->assertSee(SubmissionStatus::UnderReview->getLabel());
});

it('does not leak a decision through the state chip before the letter goes out', function () {
    // The real path, not a hand-written UPDATE: ApplyDecision is what an
    // organizer's "Decide" button calls, and it writes `accepted`/`rejected`/
    // `waitlisted` days before anybody clicks "Send decision emails".
    $this->conference->forceFill(['status' => ConferenceStatus::Reviewing])->save();
    $this->submission->forceFill(['status' => SubmissionStatus::UnderReview])->save();

    app(ApplyDecision::class)->handle(
        $this->submission->fresh() ?? $this->submission,
        Decision::Rejected,
        User::factory()->create(),
    );

    $decided = $this->submission->fresh();

    expect($decided?->status)->toBe(SubmissionStatus::Rejected)
        ->and($decided?->decision_notified_at)->toBeNull();

    get('/s/'.$this->token)
        ->assertOk()
        // "Not accepted" is the label of SubmissionStatus::Rejected, and it is
        // the one word this page must not print until the letter has been sent.
        ->assertDontSee(SubmissionStatus::Rejected->getLabel())
        ->assertSee(SubmissionStatus::UnderReview->getLabel())
        ->assertSee(__('submission.status.decision_pending'));

    // Once the letter is out, the chip tells the truth again.
    $decided?->forceFill(['decision_notified_at' => now()])->save();

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee(SubmissionStatus::Rejected->getLabel());
});

it('shows the letter that was sent, rendered', function () {
    $this->submission->forceFill([
        'status' => SubmissionStatus::Accepted,
        'decision' => Decision::AcceptedOral,
        'decision_notified_at' => now(),
    ])->save();

    SubmissionDecision::factory()->for($this->submission)->notified(
        "Dear Dr Sara Al-Harbi,\n\nWe are pleased to tell you that your abstract has been **accepted for oral presentation**.\n\n- **Reference:** AAM26-017",
    )->create(['decision' => Decision::AcceptedOral]);

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee(__('submission.status.decision.heading'))
        ->assertSee('We are pleased to tell you')
        // Rendered, not printed as source: the Markdown became HTML.
        ->assertSee('<strong>accepted for oral presentation</strong>', escape: false)
        ->assertDontSee('**accepted for oral presentation**')
        // ...and the neutral placeholder is gone.
        ->assertDontSee(__('submission.status.decision_pending'));
});

it('cannot be used to inject html through a letter', function () {
    // The letter is stored as RenderEmailTemplate produced it, which escapes
    // every `<` in the finished body (app/Actions/Mail/RenderEmailTemplate.php:96-99)
    // - so a tag cannot be in a real letter. This pins what happens if one ever
    // is: it is printed, not executed.
    $this->submission->forceFill([
        'status' => SubmissionStatus::Rejected,
        'decision' => Decision::Rejected,
        'decision_notified_at' => now(),
    ])->save();

    SubmissionDecision::factory()->for($this->submission)
        ->notified('&lt;script&gt;alert(1)&lt;/script&gt; and a real sentence.')
        ->create(['decision' => Decision::Rejected]);

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee('and a real sentence.')
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('never shows a letter on a withdrawn abstract', function () {
    $this->submission->forceFill([
        'status' => SubmissionStatus::Waitlisted,
        'decision' => Decision::Waitlisted,
        'decision_notified_at' => now(),
    ])->save();

    SubmissionDecision::factory()->for($this->submission)->notified('You are on the waiting list.')
        ->create(['decision' => Decision::Waitlisted]);

    $this->submission->forceFill([
        'status' => SubmissionStatus::Withdrawn,
        'withdrawn_at' => now(),
    ])->save();

    // The MODEL's own guard, asserted directly. The view tests its Withdrawn
    // arm before the letter arm, so every assertion below passes whatever
    // Submission::decisionLetter() answers - and every other reader of that
    // method, SubmissionStatus::render()'s $letterBody included, is computed
    // before the view runs.
    expect($this->submission->fresh()?->decisionLetter())->toBeNull();

    // An author who withdrew is not waiting for an answer, and a letter under a
    // "Withdrawn" banner reads as a reversal of their own choice.
    get('/s/'.$this->token)
        ->assertOk()
        ->assertDontSee('You are on the waiting list.')
        ->assertSee(__('submission.status.withdrawn_notice', [
            'date' => $this->submission->fresh()?->withdrawn_at?->copy()
                ->setTimezone($this->submission->conference->timezone)->format('j F Y, H:i'),
        ]));
});

it('shows the newest letter after a decision was changed and resent', function () {
    $this->submission->forceFill([
        'status' => SubmissionStatus::Accepted,
        'decision' => Decision::AcceptedPoster,
        'decision_notified_at' => now(),
    ])->save();

    SubmissionDecision::factory()->for($this->submission)->notified('The first answer was oral.')
        ->create(['decision' => Decision::AcceptedOral, 'decided_at' => now()->subDay()]);
    SubmissionDecision::factory()->for($this->submission)->notified('The programme changed; it is a poster.')
        ->create(['decision' => Decision::AcceptedPoster, 'decided_at' => now()]);

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee('The programme changed; it is a poster.')
        ->assertDontSee('The first answer was oral.');
});

it('fills the link in the stored letter from the token in the url', function () {
    // Task 7 stores the letter with `{{status_link}}` left literal, so no
    // database row ever holds a live bearer credential. The page fills it in
    // from the token this reader already has in their own URL.
    $this->submission->forceFill([
        'status' => SubmissionStatus::Accepted,
        'decision' => Decision::AcceptedOral,
        'decision_notified_at' => now(),
    ])->save();

    SubmissionDecision::factory()->for($this->submission)
        ->notified('Your abstract: [open it]({{status_link}}).')
        ->create(['decision' => Decision::AcceptedOral]);

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee($this->submission->statusUrl($this->token), escape: false)
        ->assertDontSee('{{status_link}}');
});

it('still lets the author read their abstract and files under a decision', function () {
    $this->submission->forceFill([
        'status' => SubmissionStatus::Accepted,
        'decision' => Decision::AcceptedOral,
        'decision_notified_at' => now(),
    ])->save();

    SubmissionDecision::factory()->for($this->submission)->notified()->create(['decision' => Decision::AcceptedOral]);

    // A decided abstract is closed to the author (SubmissionStatus::isOpenToAuthor()
    // is Draft and Submitted only), so the edit and withdraw buttons are gone -
    // but the page is not a dead end.
    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee($this->submission->title)
        ->assertDontSee(__('submission.status.edit'))
        ->assertDontSee(__('submission.status.withdraw'));
});
