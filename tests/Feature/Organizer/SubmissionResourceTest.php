<?php

declare(strict_types=1);

use App\Actions\Submissions\ExportSubmissionsCsv;
use App\Actions\Submissions\IssueSubmissionToken;
use App\Enums\OrganizationRole;
use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use App\Filament\Organizer\Resources\Conferences\Pages\ViewConference;
use App\Filament\Organizer\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Organizer\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Organizer\Resources\Submissions\SubmissionResource;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\Track;
use App\Models\User;
use App\Policies\SubmissionFilePolicy;
use App\Policies\SubmissionPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->published()->create([
        'name' => 'Alpha Annual Meeting',
        'reference_prefix' => 'AAM26',
    ]);
    $this->submission = Submission::factory()
        ->for($this->conference)
        ->submitted()
        ->withCorrespondingAuthor('sara@example.org', 'Dr Sara Al-Harbi')
        ->create(['title' => 'Early mobilisation after cardiac surgery']);
    $this->submission->forceFill(['reference' => 'AAM26-017'])->save();
});

it('lists only the submissions of the current tenant', function () {
    $theirs = withoutTenant(fn () => Submission::factory()->submitted()->create(['title' => 'Somebody else entirely']));

    livewire(ListSubmissions::class)
        ->assertCanSeeTableRecords([$this->submission])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('shows the columns an organizer triages on', function () {
    Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);
    $this->submission->forceFill(['track_id' => $this->conference->tracks()->first()?->id])->save();

    livewire(ListSubmissions::class)
        ->assertCanRenderTableColumn('reference')
        ->assertCanRenderTableColumn('title')
        ->assertCanRenderTableColumn('status')
        ->assertCanRenderTableColumn('submitted_at')
        ->assertSee('AAM26-017')
        ->assertSee('Dr Sara Al-Harbi')
        ->assertSee('Neurocritical care');
});

it('filters by conference, status and track', function () {
    $other = Conference::factory()->for($this->organization)->published()->create(['name' => 'Alpha Winter School']);
    $otherSubmission = Submission::factory()->for($other)->submitted()->create(['title' => 'Winter abstract']);
    $draft = Submission::factory()->for($this->conference)->create(['title' => 'Still a draft']);

    livewire(ListSubmissions::class)
        ->filterTable('conference_id', $this->conference->id)
        ->assertCanSeeTableRecords([$this->submission, $draft])
        ->assertCanNotSeeTableRecords([$otherSubmission]);

    livewire(ListSubmissions::class)
        ->filterTable('status', SubmissionStatus::Draft->value)
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$this->submission]);

    // The third filter the name promises. A track belongs to a conference, so
    // the option list is every track of every conference the tenant owns -
    // which is exactly what SubmissionsTable's track_id filter builds.
    $track = Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);
    $tracked = Submission::factory()->for($this->conference)->submitted()->create([
        'title' => 'Tracked abstract',
        'track_id' => $track->id,
    ]);

    livewire(ListSubmissions::class)
        ->filterTable('track_id', $track->id)
        ->assertCanSeeTableRecords([$tracked])
        ->assertCanNotSeeTableRecords([$this->submission, $draft, $otherSubmission]);
});

it('searches on the title, the reference and an author email', function () {
    $other = Submission::factory()->for($this->conference)->submitted()->withCorrespondingAuthor('omar@example.org')->create(['title' => 'Something unrelated']);
    $other->forceFill(['reference' => 'AAM26-099'])->save();

    foreach (['Early mobilisation', 'AAM26-017', 'sara@example.org'] as $term) {
        livewire(ListSubmissions::class)
            ->searchTable($term)
            ->assertCanSeeTableRecords([$this->submission])
            ->assertCanNotSeeTableRecords([$other]);
    }
});

it('opens a read-only view with the abstract, authors, answers and files', function () {
    $sha = hash('sha256', 'x');
    Storage::disk('local')->put(substr($sha, 0, 2).'/f.pdf', '%PDF-1.4 test');
    SubmissionFile::factory()->for($this->submission)->create([
        'original_name' => 'abstract.pdf', 'path' => substr($sha, 0, 2).'/f.pdf', 'sha256' => $sha,
    ]);
    $this->submission->forceFill([
        'custom_field_values' => ['ethics_approval_number' => 'IRB-2026-14'],
        'presentation_preference' => PresentationPreference::Poster,
    ])->save();

    livewire(ViewSubmission::class, ['record' => $this->submission->getRouteKey()])
        ->assertOk()
        ->assertSee('AAM26-017')
        ->assertSee('Early mobilisation after cardiac surgery')
        ->assertSee('Dr Sara Al-Harbi')
        ->assertSee('sara@example.org')
        ->assertSee('IRB-2026-14')
        ->assertSee('abstract.pdf')
        ->assertSee(PresentationPreference::Poster->getLabel());
});

it('links a file through a signed url that actually works', function () {
    $sha = hash('sha256', 'y');
    Storage::disk('local')->put(substr($sha, 0, 2).'/g.pdf', '%PDF-1.4 test');
    $file = SubmissionFile::factory()->for($this->submission)->create([
        'original_name' => 'appendix.pdf', 'path' => substr($sha, 0, 2).'/g.pdf', 'sha256' => $sha,
    ]);

    $html = livewire(ViewSubmission::class, ['record' => $this->submission->getRouteKey()])->assertOk()->html();

    preg_match('#(/files/'.$file->ulid.'\?[^"\']+)#', $html, $matches);

    expect($matches[1] ?? null)->not->toBeNull();

    $this->get(html_entity_decode((string) $matches[1]))->assertOk();
});

it('withdraws on the organizer side and records who did it', function () {
    livewire(ViewSubmission::class, ['record' => $this->submission->getRouteKey()])
        ->callAction('withdraw')
        ->assertHasNoActionErrors();

    expect($this->submission->refresh()->status)->toBe(SubmissionStatus::Withdrawn);

    $activity = Activity::query()->where('description', 'submission.withdrawn')->firstOrFail();

    expect($activity->causer_id)->toBe($this->user->id)
        ->and($activity->properties['by'] ?? null)->toBe('organizer');
});

it('resends a status link with a new token and kills the old one', function () {
    $old = app(IssueSubmissionToken::class)->handle($this->submission);

    livewire(ViewSubmission::class, ['record' => $this->submission->getRouteKey()])
        ->callAction('resendLink')
        ->assertHasNoActionErrors();

    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('sara@example.org')
        && $mail->templateKey === 'submission_received');

    expect(Submission::findByPlainToken($old))->toBeNull();
});

it('exports the visible rows as csv and neutralises a spreadsheet formula', function () {
    // An author-supplied title beginning with `=` is executed by Excel when the
    // file is opened. It has to arrive as text.
    $this->submission->forceFill(['title' => '=HYPERLINK("https://evil.example","click")'])->save();
    withoutTenant(fn () => Submission::factory()->submitted()->create(['title' => 'Another tenant']));

    // callTableAction, not callAction: `export` is a table *header* action, and
    // the table helpers are what add the table context Filament resolves the
    // action name in (vendor/filament/tables/src/Testing/TestsActions.php).
    //
    // Livewire turns the StreamedResponse the action returns into a `download`
    // effect, running the stream callback and base64-encoding what it wrote
    // (SupportFileDownloads::call()) - so the assertion below is on the real
    // bytes a browser would have saved, not on a response object.
    $component = livewire(ListSubmissions::class)
        ->callTableAction('export')
        ->assertFileDownloaded();

    $csv = base64_decode((string) data_get($component->effects, 'download.content'), true);

    expect($csv)->toContain('AAM26-017')
        ->toContain('sara@example.org')
        ->toContain("'=HYPERLINK")
        ->not->toContain('Another tenant')
        // The BOM, so Excel opens UTF-8 without mangling an Arabic affiliation.
        ->and(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF");
});

// --- Cross-tenant -------------------------------------------------------

it('404s a submission from another organization', function () {
    $theirs = withoutTenant(fn () => Submission::factory()->submitted()->create());

    // Through the route, not livewire(): Filament's resolveRecord() throws
    // ModelNotFoundException, and Livewire's test harness disables exception
    // handling for everything except HttpException and AuthorizationException
    // (RequestBroker), so a livewire() call would error instead of asserting.
    // Only a real request turns it into the 404 a stranger actually sees -
    // which is the form Plan 2's ConferenceResourceTest already uses.
    get(SubmissionResource::getUrl('view', ['record' => $theirs->getRouteKey()], tenant: $this->organization))
        ->assertNotFound();
});

it('answers for its own organization file and refuses another one', function () {
    // SubmissionFilePolicy is the only thing between an organizer and a signed
    // URL for another tenant's uploaded PDF, and it is the gate the view page's
    // Gate::allows('view', $record) calls before minting one.
    $sha = hash('sha256', 'mine');
    $mine = SubmissionFile::factory()->for($this->submission)->create([
        'original_name' => 'mine.pdf',
        'path' => substr($sha, 0, 2).'/mine.pdf',
        'sha256' => $sha,
    ]);

    $theirFile = withoutTenant(function () {
        $theirs = Submission::factory()->submitted()->create();
        $sha = hash('sha256', 'theirs');

        return SubmissionFile::factory()->for($theirs)->create([
            'original_name' => 'theirs.pdf',
            'path' => substr($sha, 0, 2).'/theirs.pdf',
            'sha256' => $sha,
        ]);
    });

    $policy = app(SubmissionFilePolicy::class);

    // The positive half is what makes the negative half mean something: a
    // policy that answered false to everything would pass the negatives and
    // break every download.
    expect($policy->view($this->user, $mine))->toBeTrue()
        ->and($policy->view($this->user, $theirFile))->toBeFalse()
        // Files are attached and removed by the author, never by an organizer.
        ->and($policy->create($this->user))->toBeFalse()
        ->and($policy->update($this->user, $mine))->toBeFalse()
        ->and($policy->delete($this->user, $mine))->toBeFalse()
        ->and($policy->deleteAny($this->user))->toBeFalse();
});

it('refuses the actions on another organization submission', function () {
    $theirs = withoutTenant(fn () => Submission::factory()->submitted()->withCorrespondingAuthor('victim@example.org')->create());

    expect(fn () => app(SubmissionPolicy::class)->view($this->user, $theirs))->not->toThrow(Throwable::class);

    expect(app(SubmissionPolicy::class)->view($this->user, $theirs))->toBeFalse()
        ->and(app(SubmissionPolicy::class)->withdraw($this->user, $theirs))->toBeFalse()
        ->and(app(SubmissionPolicy::class)->resendLink($this->user, $theirs))->toBeFalse();

    Mail::assertNothingQueued();
});

it('hides the resource entirely from someone who is not a member', function () {
    $outsider = User::factory()->create();
    withoutTenant(fn () => Organization::factory()->approved()->create()->addMember($outsider, OrganizationRole::Owner));

    actingAs($outsider);

    expect(SubmissionResource::canViewAny())->toBeFalse();
});

it('lets a plain member read submissions but never edit or delete one', function () {
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    actingAs($member);

    livewire(ListSubmissions::class)->assertCanSeeTableRecords([$this->submission]);

    $policy = app(SubmissionPolicy::class);

    expect($policy->view($member, $this->submission))->toBeTrue()
        ->and($policy->update($member, $this->submission))->toBeFalse()
        ->and($policy->delete($member, $this->submission))->toBeFalse()
        ->and($policy->deleteAny($member))->toBeFalse()
        ->and($policy->forceDeleteAny($member))->toBeFalse()
        ->and($policy->restoreAny($member))->toBeFalse();
});

// --- Counts on the conference page --------------------------------------

it('shows the four submission counts and a link on the conference view', function () {
    Submission::factory()->count(2)->for($this->conference)->create();
    Submission::factory()->for($this->conference)->withdrawn()->create();

    livewire(ViewConference::class, [
        'record' => $this->conference->getRouteKey(),
    ])
        ->assertOk()
        ->assertSee('Submissions')
        // Every number beside its own label, in the order the section lays them
        // out. A bare assertSee('4') was vacuous: every Filament page inlines
        // heroicons whose viewBox attribute contains a 4, so all four entries
        // were effectively unasserted and a wrong count would have passed.
        ->assertSeeTextInOrder(['Total', '4', 'Drafts', '2', 'Submitted', '1', 'Withdrawn', '1'])
        // Through the helper, so the test breaks if the query-string shape
        // drifts away from the one ListRecords actually binds.
        ->assertSee(SubmissionResource::urlForConference($this->conference), escape: false);

    // And the query behind them, so a broken grouping is a failure here rather
    // than a rendering puzzle.
    expect($this->conference->submissionCounts())
        ->toBe(['total' => 4, 'draft' => 2, 'submitted' => 1, 'withdrawn' => 1]);
});

it('names the export file after the moment it was taken', function () {
    Carbon::setTestNow('2026-09-11 08:30:00');

    livewire(ListSubmissions::class)
        ->callTableAction('export')
        ->assertFileDownloaded('submissions-2026-09-11-083000.csv');

    Carbon::setTestNow();
});

it('exports a row whose conference is gone instead of dying mid-stream', function () {
    // SubmissionResource::getEloquentQuery() uses whereHas('conference'), which
    // excludes a soft-deleted one - so this action is safe only by the grace of
    // its one caller. A future caller handing it a plain Submission::query()
    // got a 500 after the response had already started streaming, which is a
    // truncated download and no error page.
    $orphan = Submission::factory()->for($this->conference)->submitted()->create(['title' => 'Orphaned abstract']);
    $this->conference->delete();

    $response = app(ExportSubmissionsCsv::class)
        ->handle(Submission::query()->whereKey($orphan->getKey()), 'submissions.csv');

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toContain('Orphaned abstract');
});
