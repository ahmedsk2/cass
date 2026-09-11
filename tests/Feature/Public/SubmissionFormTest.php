<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\CustomFieldType;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use App\Livewire\Public\SubmissionForm;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Notifications\NewSubmissionNotice;
use App\Support\Turnstile;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Mail::fake();
    Notification::fake();

    $this->organization = Organization::factory()->approved()->create([
        'name' => 'Gulf Pediatric Society',
        'primary_color' => '#0F4C8A',
        'accent_color' => '#B45309',
    ]);
    $this->conference = Conference::factory()->for($this->organization)->published()->create([
        'name' => 'Gulf Pediatric Critical Care 2026',
        'reference_prefix' => 'GPCC26',
        'word_limit' => 250,
        'presentation_types' => ['oral', 'poster'],
        'terms' => 'Presenting authors must register for the conference.',
    ]);
});

function submitUrl(Conference $conference): string
{
    return "/c/{$conference->organization->slug}/{$conference->slug}/submit";
}

/** The full form state a valid submission needs. */
function fillForm(Testable $component): Testable
{
    return $component
        ->set('title', 'Early mobilisation after cardiac surgery')
        ->set('abstract', 'Background. Methods. Results. Conclusion.')
        ->set('presentation_preference', PresentationPreference::Oral->value)
        ->set('contact_phone', '+966500000000')
        ->set('authors.0.name', 'Dr Sara Al-Harbi')
        ->set('authors.0.email', 'sara@example.org')
        ->set('authors.0.affiliation', 'King Fahad Specialist Hospital')
        ->set('authors.0.is_presenter', true)
        ->set('agreed', true);
}

it('renders the branded form with the conference rules on it', function () {
    Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);

    get(submitUrl($this->conference))
        ->assertOk()
        ->assertSee('Gulf Pediatric Critical Care 2026')
        ->assertSee('Gulf Pediatric Society')
        ->assertSee('Neurocritical care')
        ->assertSee('250')
        ->assertSee('Presenting authors must register for the conference.')
        ->assertSee('--org-primary:#0F4C8A', escape: false)
        ->assertSeeLivewire(SubmissionForm::class);
});

it('offers only the presentation types the conference chose', function () {
    get(submitUrl($this->conference))
        ->assertOk()
        ->assertSee(PresentationPreference::Oral->getLabel())
        ->assertSee(PresentationPreference::Poster->getLabel())
        ->assertDontSee(PresentationPreference::Either->getLabel());
});

it('hides the track field entirely when the conference has no tracks', function () {
    get(submitUrl($this->conference))->assertOk()->assertDontSee('Track');

    Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);

    get(submitUrl($this->conference))->assertOk()->assertSee('Track');
});

it('starts with one author row, prefilled as corresponding', function () {
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->assertCount('authors', 1)
        ->assertSet('authors.0.is_corresponding', true);
});

it('adds and removes author rows and never removes the last one', function () {
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->call('addAuthor')
        ->call('addAuthor')
        ->assertCount('authors', 3)
        ->call('removeAuthor', 1)
        ->assertCount('authors', 2)
        ->call('removeAuthor', 0)
        ->assertCount('authors', 1)
        ->call('removeAuthor', 0)
        ->assertCount('authors', 1);
});

it('moves the corresponding flag rather than allowing two', function () {
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->call('addAuthor')
        ->call('makeCorresponding', 1)
        ->assertSet('authors.0.is_corresponding', false)
        ->assertSet('authors.1.is_corresponding', true);
});

it('keeps a corresponding author after the corresponding row is removed', function () {
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->call('addAuthor')
        ->call('makeCorresponding', 1)
        ->call('removeAuthor', 1)
        ->assertSet('authors.0.is_corresponding', true);
});

it('saves a draft, emails the author and redirects to the status page', function () {
    $component = fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))->call('saveDraft')->assertHasNoErrors()->assertRedirectContains('/s/');

    $submission = Submission::query()->firstOrFail();

    expect($submission->status)->toBe(SubmissionStatus::Draft)
        ->and($submission->reference)->toBeNull();

    $token = null;

    Mail::assertQueued(TemplatedMail::class, function (TemplatedMail $mail) use (&$token): bool {
        preg_match('#/s/([A-Za-z0-9]{64})#', $mail->body, $matches);
        $token = $matches[1] ?? null;

        return $mail->hasTo('sara@example.org') && $mail->templateKey === 'submission_draft_saved';
    });

    // The link in the email and the page the author lands on are the same URL,
    // so a bookmark from the email works and the draft is not orphaned behind a
    // token nobody holds.
    expect($token)->not->toBeNull()
        ->and(Submission::findByPlainToken((string) $token)?->is($submission))->toBeTrue();

    $component->assertRedirectContains((string) $token);
});

it('needs only a title and a corresponding address to save a draft', function () {
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->call('saveDraft')
        ->assertHasErrors(['title', 'authors.0.email']);

    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->set('title', 'A work in progress')
        ->set('authors.0.email', 'sara@example.org')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(Submission::query()->count())->toBe(1);
});

it('submits, assigns a reference and redirects with a success flash', function () {
    // The notice goes to members whose organization_members.notify_on_submission
    // is true (the column's default). OrganizationFactory creates none, and
    // NotificationFake::assertSentTo() throws Exception('No notifiable given.')
    // on an empty collection - so without this the assertion below would error
    // rather than prove anything.
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Owner);

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))->call('submit')->assertHasNoErrors()->assertRedirectContains('/s/');

    $submission = Submission::query()->firstOrFail();

    expect($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($submission->reference)->toBe('GPCC26-001')
        ->and(session('status'))->toContain('GPCC26-001');

    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->templateKey === 'submission_received');
    Notification::assertSentTo($member, NewSubmissionNotice::class);
});

it('requires an address on the row that is actually ticked as corresponding', function () {
    // Row zero has an address; the ticked row does not. SaveSubmissionDraft
    // promotes the first *ticked* row, so validating authors.0.email only would
    // save an abstract whose corresponding address is the empty string - and
    // nobody could ever be told it exists.
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->set('title', 'A work in progress')
        ->set('authors.0.email', 'sara@example.org')
        ->call('addAuthor')
        ->set('authors.1.name', 'Dr Omar Khan')
        ->call('makeCorresponding', 1)
        ->call('saveDraft')
        ->assertHasErrors(['authors.1.email']);

    expect(Submission::query()->count())->toBe(0);
});

it('bounds on the draft path every length the columns bound', function () {
    // MySQL in strict mode answers an over-long value with SQLSTATE 22001 - a
    // 500 on a public, unauthenticated endpoint - while SQLite silently accepts
    // it. Every column narrower than the text the author can paste is bounded
    // here, on the path that validates least.
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->set('title', 'A work in progress')
        ->set('abstract', str_repeat('a', 20_001))
        ->set('contact_phone', str_repeat('9', 41))
        ->set('authors.0.name', str_repeat('n', 181))
        ->set('authors.0.email', str_repeat('e', 250).'@example.org')
        ->set('authors.0.affiliation', str_repeat('f', 256))
        ->call('saveDraft')
        ->assertHasErrors([
            'abstract',
            'contact_phone',
            'authors.0.name',
            'authors.0.email',
            'authors.0.affiliation',
        ]);

    expect(Submission::query()->count())->toBe(0);
});

it('stores an abstract of the longest length it accepts', function () {
    // 20000 characters of four bytes each is 80000 bytes - past the 65535 MySQL
    // counts for TEXT, which is why submissions.abstract is mediumText. SQLite
    // passes this either way; CI's MySQL job is where the column is proved.
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->set('title', 'A work in progress')
        ->set('abstract', str_repeat('🙂', 20_000))
        ->set('authors.0.email', 'sara@example.org')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(mb_strlen((string) Submission::query()->firstOrFail()->abstract))->toBe(20_000);
});

it('bounds the abstract by characters as well as by words', function () {
    // The word rule counts one 100 KB token as one word, so it is not a bound
    // on what reaches submissions.abstract at all.
    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('abstract', str_repeat('a', 20_001))
        ->call('submit')
        ->assertHasErrors(['abstract']);

    expect(Submission::query()->count())->toBe(0);
});

it('caps the author list on both buttons', function () {
    // `authors` is a public, unlocked property: Livewire fills it wholesale from
    // the request, so without a cap one unauthenticated call makes
    // SaveSubmissionDraft::syncAuthors() insert tens of thousands of rows inside
    // a single transaction.
    $rows = array_fill(0, 51, [
        'name' => 'Dr Sara Al-Harbi',
        'email' => 'sara@example.org',
        'affiliation' => 'King Fahad Specialist Hospital',
        'is_presenter' => false,
        'is_corresponding' => false,
    ]);
    $rows[0]['is_corresponding'] = true;

    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->set('title', 'A work in progress')
        ->set('authors', $rows)
        ->call('saveDraft')
        ->assertHasErrors(['authors']);

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('authors', $rows)
        ->call('submit')
        ->assertHasErrors(['authors']);

    expect(Submission::query()->count())->toBe(0);
});

it('renders the submission form with a bounded number of queries', function () {
    // Spec section 10 gives this page the same 300 ms server budget as the
    // conference page, and it is the only page on that budget that is Livewire
    // rather than plain Blade. Bound the query count so an N+1 over tracks or
    // custom fields fails here rather than on the host.
    Track::factory()->count(5)->for($this->conference)->create();
    CustomField::factory()->count(5)->for($this->conference)->create();
    $url = submitUrl($this->conference);

    DB::enableQueryLog();
    get($url)->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // organization + conference bindings, tracks, custom fields, with headroom.
    // customFields() and tracks() are memoised per request for this reason.
    expect($queries)->toBeLessThanOrEqual(6);
});

it('reports the word limit as a field error rather than a page crash', function () {
    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('abstract', implode(' ', array_fill(0, 251, 'word')))
        ->call('submit')
        ->assertHasErrors(['abstract']);

    expect(Submission::query()->count())->toBe(0);
});

it('refuses a presentation preference the conference does not offer', function () {
    // The client-side rules reject it: presentationOptions() is built from the
    // conference's own list, so `either` is not a value this form will accept.
    // (The mapping from an action sentence back to a field - reportBlockers() -
    // is proved in tests/Feature/Public/SubmissionStatusPageTest.php, with the
    // one refusal no form rule can make.)
    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('presentation_preference', PresentationPreference::Either->value)
        ->call('submit')
        ->assertHasErrors(['presentation_preference']);
});

it('requires the agreement', function () {
    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('agreed', false)
        ->call('submit')
        ->assertHasErrors(['agreed']);
});

it('renders every custom field type and stores the answers under their keys', function () {
    CustomField::factory()->for($this->conference)->create(['label' => 'Ethics approval number', 'type' => CustomFieldType::Text, 'required' => true]);
    CustomField::factory()->for($this->conference)->create(['label' => 'Study design', 'type' => CustomFieldType::Select, 'options' => ['Randomised', 'Observational'], 'required' => true]);
    CustomField::factory()->for($this->conference)->create(['label' => 'Number of centres', 'type' => CustomFieldType::Number, 'required' => false]);
    CustomField::factory()->for($this->conference)->create(['label' => 'Previously presented', 'type' => CustomFieldType::Checkbox, 'required' => false]);
    CustomField::factory()->for($this->conference)->create(['label' => 'Funding statement', 'type' => CustomFieldType::Textarea, 'required' => false]);

    get(submitUrl($this->conference))
        ->assertOk()
        ->assertSee('Ethics approval number')
        ->assertSee('Study design')
        ->assertSee('Randomised')
        ->assertSee('Number of centres')
        ->assertSee('Previously presented')
        ->assertSee('Funding statement');

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('custom.ethics_approval_number', 'IRB-2026-14')
        ->set('custom.study_design', 'Randomised')
        ->set('custom.number_of_centres', '3')
        ->set('custom.previously_presented', true)
        ->call('submit')
        ->assertHasNoErrors();

    // Compared by key, not by position: MySQL's JSON type stores object members
    // in its own order (by key length, then bytewise), so the four answers come
    // back from CI's database in an order SQLite never produces.
    $values = (array) Submission::query()->firstOrFail()->custom_field_values;
    ksort($values);

    expect($values)->toBe([
        'ethics_approval_number' => 'IRB-2026-14',
        'number_of_centres' => '3',
        'previously_presented' => true,
        'study_design' => 'Randomised',
    ]);
});

it('refuses to submit without a required custom field', function () {
    CustomField::factory()->for($this->conference)->create(['label' => 'Ethics approval number', 'type' => CustomFieldType::Text, 'required' => true]);

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->call('submit')
        ->assertHasErrors(['custom.ethics_approval_number']);
});

it('offers only this conference tracks and refuses another conference track', function () {
    $mine = Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);
    $theirs = Track::factory()->for(Conference::factory()->published())->create(['name' => 'Somebody else']);

    get(submitUrl($this->conference))->assertOk()->assertSee('Neurocritical care')->assertDontSee('Somebody else');

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('track_id', $theirs->id)
        ->call('submit')
        ->assertHasErrors(['track_id']);

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('track_id', $mine->id)
        ->call('submit')
        ->assertHasNoErrors();
});

// --- Who may reach this page at all -------------------------------------
// Each negative case is paired with a request that must succeed on the same
// URL, so none of them can pass just because the route does not exist yet.

it('404s a draft conference for the public and previews it for a member', function () {
    $conference = Conference::factory()->for($this->organization)->create();

    get(submitUrl($conference))->assertNotFound();

    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);

    actingAs($member)->get(submitUrl($conference))
        ->assertOk()
        ->assertSee('not visible to the public');

    // A preview is read-only. Saving from it would create a real abstract, burn
    // a reference number off conferences.submission_counter and queue branded
    // email for a conference the public cannot see - and /s/{token} would 404,
    // so the author would never find out.
    fillForm(livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $conference]))
        ->call('saveDraft')
        ->assertHasErrors();

    expect(Submission::query()->count())->toBe(0);
});

it('shows a member the form it is previewing, not a closed panel', function () {
    // A draft conference has no window at all (submission_opens_at is null), so
    // gating the form on the window would hide from the member the one thing the
    // preview exists to show.
    $conference = Conference::factory()->for($this->organization)->create();
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);

    actingAs($member)->get(submitUrl($conference))
        ->assertOk()
        ->assertSee('not visible to the public')
        ->assertSee('Save draft')
        ->assertSee('Submission dates have not been announced yet');
});

it('404s an archived conference and one of a suspended organization', function () {
    get(submitUrl($this->conference))->assertOk();

    $this->conference->forceFill(['status' => ConferenceStatus::Archived])->save();
    get(submitUrl($this->conference))->assertNotFound();

    $this->conference->forceFill(['status' => ConferenceStatus::Open])->save();
    $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
    get(submitUrl($this->conference))->assertNotFound();
});

it('shows a closed state instead of the form once the deadline has passed', function () {
    Carbon::setTestNow($this->conference->submission_deadline->copy()->addMinute());

    get(submitUrl($this->conference))
        ->assertOk()
        ->assertSee('Submissions are closed')
        ->assertDontSee('Save draft');

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))->call('submit')->assertHasErrors();

    expect(Submission::query()->count())->toBe(0);

    Carbon::setTestNow();
});

it('keeps the form on the page when the deadline passes mid-session', function () {
    // The author opened the page while the window was open and is still typing.
    // Swapping the form for the closed panel would throw away everything typed
    // and hide the very error windowIsOpen() adds.
    $component = fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]));

    Carbon::setTestNow($this->conference->submission_deadline->copy()->addMinute());

    $component->call('submit')
        ->assertHasErrors(['title'])
        ->assertSee('Submissions are closed')
        ->assertSee('Save draft');

    expect(Submission::query()->count())->toBe(0);

    Carbon::setTestNow();
});

it('shows an upcoming state before the window opens', function () {
    Carbon::setTestNow($this->conference->submission_opens_at->copy()->subDay());

    get(submitUrl($this->conference))
        ->assertOk()
        ->assertSee('Submissions open on')
        ->assertDontSee('Save draft');

    Carbon::setTestNow();
});

it('does not leak a conference through another organization slug', function () {
    $other = Organization::factory()->approved()->create();

    get(submitUrl($this->conference))->assertOk();
    get("/c/{$other->slug}/{$this->conference->slug}/submit")->assertNotFound();
});

// --- Files ---------------------------------------------------------------

it('attaches a pdf to the abstract when it is submitted', function () {
    Storage::fake('local');

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('uploads', [uploadedFixturePdf()])
        ->call('submit')
        ->assertHasNoErrors();

    $submission = Submission::query()->firstOrFail();

    expect($submission->files)->toHaveCount(1)
        ->and($submission->files->first()?->original_name)->toBe('abstract.pdf');

    Storage::disk('local')->assertExists((string) $submission->files->first()?->path);
});

it('shows the count, size and type limits the conference set', function () {
    $this->conference->forceFill(['max_files' => 2, 'allowed_file_types' => ['pdf']])->save();

    // The whole sentence, from the language file. assertSee('2') on its own is
    // vacuous - a word count, a date, a Livewire id or a heroicon viewBox
    // carries a 2 on almost any page - so the count placeholder could break
    // without this test noticing.
    get(submitUrl($this->conference))
        ->assertOk()
        ->assertSee(__('submission.files.limits', ['count' => 2, 'types' => 'PDF', 'size' => 10]));
});

it('accepts an upload when the conference lists no file types at all', function () {
    Storage::fake('local');

    // `allowed_file_types` is `?? ['pdf']` on both sides, which catches null and
    // not an empty array. Empty built the rule string `extensions:` with no
    // parameters, which Laravel answers with an InvalidArgumentException - a
    // 500 on the public form the moment an author picks a file. Not reachable
    // through the organizer form today (the CheckboxList is required), and
    // nothing else enforced it.
    $this->conference->forceFill(['max_files' => 2, 'allowed_file_types' => []])->save();

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('uploads', [uploadedFixturePdf()])
        ->call('submit')
        ->assertHasNoErrors();

    expect(Submission::query()->firstOrFail()->files)->toHaveCount(1);
});

it('renders an author row a crafted payload left a key out of', function () {
    // `authors` is public and unlocked, so Livewire fills it wholesale from the
    // request and every rule on it is `nullable`. A row with no
    // `is_corresponding` key therefore reaches the partial, where an undefined
    // index is a warning Laravel promotes to an ErrorException - a 500 on a
    // public, unauthenticated page.
    livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ])
        ->set('authors', [['name' => 'Dr Sara Al-Harbi', 'email' => 'sara@example.org']])
        ->assertOk()
        // The row itself reached the page. The values are not in the markup -
        // wire:model binds them client-side - so the row's own field ids are
        // what proves the partial rendered rather than threw.
        ->assertSee('author-email-0');
});

it('stops writing when the organization is suspended while the form is open', function () {
    // mount() checks the organization once. Every later Livewire request
    // re-checked only the conference window, so a page opened before a
    // suspension could still create abstracts and queue branded email for an
    // organization the platform has taken offline - while /s/{token} 404s, so
    // the author never sees any of it. An archived conference is caught by the
    // window; a suspended organization was not.
    $component = fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]));

    $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

    $component->call('submit')->assertHasErrors('title');

    expect(Submission::query()->count())->toBe(0);

    Mail::assertNothingQueued();
});

it('refuses a renamed image and leaves the abstract as a draft', function () {
    Storage::fake('local');

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('uploads', [uploadedRenamedImage()])
        ->call('submit')
        ->assertHasErrors(['uploads']);

    // The row exists as a draft - the author's typing is not thrown away - but
    // it was not submitted and no reference was burnt.
    $submission = Submission::query()->firstOrFail();

    expect($submission->status)->toBe(SubmissionStatus::Draft)
        ->and($submission->reference)->toBeNull()
        ->and($submission->files)->toHaveCount(0)
        ->and($this->conference->refresh()->submission_counter)->toBe(0);
});

it('refuses more files than the conference allows before touching the database', function () {
    Storage::fake('local');
    $this->conference->forceFill(['max_files' => 1])->save();

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('uploads', [uploadedFixturePdf(), uploadedFixturePdf('second.pdf')])
        ->call('submit')
        ->assertHasErrors(['uploads']);

    expect(Submission::query()->count())->toBe(0);
});

it('drops a pending upload the author changed their mind about', function () {
    Storage::fake('local');

    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->set('uploads', [uploadedFixturePdf(), uploadedFixturePdf('second.pdf')])
        ->assertCount('uploads', 2)
        ->call('removeUpload', 0)
        ->assertCount('uploads', 1);
});

it('keeps a file it already stored out of the pending list when a later one is refused', function () {
    Storage::fake('local');

    $component = fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]));

    // The good one is stored before the renamed image is sniffed and refused.
    $component
        ->set('uploads', [uploadedFixturePdf(), uploadedRenamedImage()])
        ->call('submit')
        ->assertHasErrors(['uploads']);

    expect(Submission::query()->firstOrFail()->files()->count())->toBe(1);

    // Only the refused file is still pending. Leaving the stored one in the
    // list means the next Submit re-stores it and StoreSubmissionFile answers
    // `duplicate` - an error the author cannot clear without also dropping the
    // file that worked.
    $component->assertCount('uploads', 1);

    // So the obvious next move - drop the file that was refused, press Submit -
    // has to work.
    $component->call('removeUpload', 0)
        ->call('submit')
        ->assertHasNoErrors();

    expect(Submission::query()->count())->toBe(1)
        ->and(Submission::query()->firstOrFail()->files)->toHaveCount(1);
});

it('ignores an uploads payload that is not an uploaded file', function () {
    // `uploads` is public and unlocked, so a crafted Livewire request can put a
    // string in it. updatedUploads() answers that with a ValidationException,
    // which Livewire swallows (SupportValidation::exception stops propagation)
    // and then renders anyway - and the files partial calls
    // getClientOriginalName() on every entry. Anything that is not a temporary
    // upload has to be gone before the view sees it, or this is an
    // unauthenticated 500 on the public submit page.
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->set('uploads', ['not-a-file'])
        ->assertCount('uploads', 0)
        ->assertSee('Attach files');
});

// --- Bot protection ------------------------------------------------------

it('silently swallows a filled honeypot', function () {
    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('website_confirm', 'http://spam.example')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    // It looks like it worked, and nothing was written. Telling a bot which
    // check it failed is telling it which check to remove.
    expect(Submission::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('refuses a form that was filled faster than a human could, and accepts it four seconds later', function () {
    // phpunit.xml pins CASS_SUBMISSION_MIN_SECONDS=0 for the suite, because
    // openedAt is #[Locked] - a test cannot back-date it any more than a
    // browser can, and Livewire answers ->set() on a locked property with
    // CannotUpdateLockedPropertyException. So the one test that owns the gate
    // switches it on itself.
    config()->set('cass.submission_min_seconds', 4);

    // BOTH components are mounted at the same instant, before the clock moves:
    // openedAt is stamped in mount(), so a component created after the jump
    // would be "opened" at the advanced instant and refused all over again -
    // which is why the only honest way to test the patient half is to mount it
    // first and wait.
    $tooFast = fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]));
    $patient = fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]));

    $tooFast->call('submit')->assertHasErrors();

    expect(Submission::query()->count())->toBe(0);

    Carbon::setTestNow(now()->addSeconds(5));

    $patient->call('submit')->assertHasNoErrors();

    expect(Submission::query()->count())->toBe(1);

    Carbon::setTestNow();
});

it('blocks the sixth save or submit from one address in a minute', function () {
    foreach (range(1, 5) as $i) {
        fillForm(livewire(SubmissionForm::class, [
            'organization' => $this->organization,
            'conference' => $this->conference,
        ]))->call('saveDraft')->assertHasNoErrors();
    }

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))->call('submit')->assertHasErrors();

    expect(Submission::query()->count())->toBe(5);
});

it('renders the turnstile widget only when both keys are configured', function () {
    get(submitUrl($this->conference))->assertOk()->assertDontSee('challenges.cloudflare.com', escape: false);

    // The half-configuration a deploy actually produces: the public site key is
    // easy to paste in and the secret is the one that gets forgotten. Rendering
    // the widget here would ask the author to solve a challenge that
    // Turnstile::verify() never checks - isConfigured() is false, so it returns
    // true immediately - while the operator reads the widget as proof that bot
    // protection is on.
    config()->set('cass.turnstile.site_key', 'site-key');

    get(submitUrl($this->conference))->assertOk()->assertDontSee('challenges.cloudflare.com', escape: false);

    config()->set('cass.turnstile.secret_key', 'secret-key');

    get(submitUrl($this->conference))
        ->assertOk()
        ->assertSee('challenges.cloudflare.com', escape: false)
        ->assertSee('site-key');
});

// Two tests, not one with two halves: Http::fake() MERGES stub sets and
// PendingRequest::buildStubHandler() takes ->filter()->first(), so a second
// fake() for the same URL never wins over the first.
it('refuses a submission whose turnstile token fails verification', function () {
    config()->set('cass.turnstile.site_key', 'site-key');
    config()->set('cass.turnstile.secret_key', 'secret-key');
    Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => false], 200)]);

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('turnstileToken', 'a-token')
        ->call('submit')
        ->assertHasErrors(['turnstileToken']);

    expect(Submission::query()->count())->toBe(0);
});

it('accepts a submission whose turnstile token verifies', function () {
    config()->set('cass.turnstile.site_key', 'site-key');
    config()->set('cass.turnstile.secret_key', 'secret-key');
    Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => true], 200)]);

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('turnstileToken', 'a-token')
        ->call('submit')
        ->assertHasNoErrors();

    expect(Submission::query()->count())->toBe(1);
});

it('redeems a turnstile token only once, however many times a submit is refused', function () {
    config()->set('cass.turnstile.site_key', 'site-key');
    config()->set('cass.turnstile.secret_key', 'secret-key');

    // One stub, deliberately: what proves the fix is the call COUNT, because a
    // second redemption of the same token answers `timeout-or-duplicate` at
    // Cloudflare and the widget is inside wire:ignore, so nothing would mint a
    // replacement for minutes.
    Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => true], 200)]);

    $component = fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))->set('turnstileToken', 'a-token');

    // Verification runs before $this->validate(), so this refusal has already
    // been past Cloudflare once.
    $component->set('abstract', implode(' ', array_fill(0, 251, 'word')))
        ->call('submit')
        ->assertHasErrors(['abstract']);

    $component->set('abstract', 'Background. Methods. Results. Conclusion.')
        ->call('submit')
        ->assertHasNoErrors();

    Http::assertSentCount(1);

    expect(Submission::query()->count())->toBe(1);
});

it('reuses the same draft when a submit is retried after a rejected file', function () {
    Storage::fake('local');

    $component = fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]));

    $component
        ->set('uploads', [uploadedRenamedImage()])
        ->call('submit')
        ->assertHasErrors(['uploads']);

    // Livewire's _finishUpload() APPENDS to an array property, so the rejected
    // file has to be cleared before the good one is attached.
    $component->set('uploads', [])
        ->set('uploads', [uploadedFixturePdf()])
        ->call('submit')
        ->assertHasNoErrors();

    // One abstract, one token, one file - not two of each. submit() adopts the
    // row it just created into $this->submission, so the retry edits it.
    expect(Submission::query()->count())->toBe(1)
        ->and(Submission::query()->firstOrFail()->files)->toHaveCount(1);
});

it('refuses an oversized temporary upload at the livewire endpoint', function () {
    // config/livewire.php caps temporary_file_upload at max:10240 (KB), the
    // same 10 MB the form enforces. Without that file the package default is
    // max:12288 with no type rule, on a throttle:60,1 endpoint that any
    // anonymous visitor to /submit can reach.
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->set('uploads', [UploadedFile::fake()->create('huge.pdf', 11 * 1024)])
        ->assertHasErrors(['uploads.0']);
});

/**
 * The real 615-byte fixture PDF, wrapped so Livewire's test helper can carry it.
 *
 * createWithContent() rather than `new UploadedFile($path, ...)`: Livewire's
 * Testable::upload() reads `$file->name` (Testable.php:291), a public property
 * only Illuminate\Http\Testing\File declares, so a plain UploadedFile makes
 * every ->set('uploads', ...) raise "Undefined property". The bytes are the
 * fixture's either way, which is what the content sniff is here to read.
 */
function uploadedFixturePdf(string $name = 'abstract.pdf'): UploadedFile
{
    $content = (string) file_get_contents(base_path('tests/Fixtures/abstract.pdf'));

    // A distinct body, so two uploads in one submission are not a duplicate.
    if ($name !== 'abstract.pdf') {
        $content .= '%% '.$name."\n";
    }

    return UploadedFile::fake()->createWithContent($name, $content);
}

/** A real PNG under a .pdf name - the fixture the content sniff must refuse. */
function uploadedRenamedImage(): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'abstract.pdf',
        (string) file_get_contents(base_path('tests/Fixtures/not-really.pdf')),
    );
}
