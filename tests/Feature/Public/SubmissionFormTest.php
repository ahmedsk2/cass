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
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
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

    expect(Submission::query()->firstOrFail()->custom_field_values)->toBe([
        'ethics_approval_number' => 'IRB-2026-14',
        'study_design' => 'Randomised',
        'number_of_centres' => '3',
        'previously_presented' => true,
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
