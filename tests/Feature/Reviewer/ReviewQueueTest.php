<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\CustomFieldType;
use App\Enums\OrganizationRole;
use App\Enums\ReviewMode;
use App\Filament\Organizer\Resources\Submissions\Pages\ListSubmissions as OrganizerList;
use App\Filament\Reviewer\Pages\Dashboard;
use App\Filament\Reviewer\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Reviewer\Resources\Submissions\Pages\ReviewSubmission;
use App\Filament\Reviewer\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->conference = Conference::factory()->for($this->organization)->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
        'review_mode' => ReviewMode::OpenPool,
        'blind_review' => true,
        'review_deadline' => now()->addMonth(),
        'submission_opens_at' => now()->subMonths(2),
        'submission_deadline' => now()->subWeek(),
    ]);

    $this->reviewer = User::factory()->create(['name' => 'Dr Omar Khan']);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->reviewer->id]);

    $this->submission = Submission::factory()->for($this->conference)->submitted()
        ->withCorrespondingAuthor('sara@example.org', 'Dr Sara Al-Harbi')
        ->create(['title' => 'Early mobilisation after cardiac surgery', 'contact_phone' => '+966500000000']);
    $this->submission->forceFill(['reference' => 'AAM26-017'])->save();

    actingAs($this->reviewer);
    bootReviewerPanel();
});

it('lists the pool and offers a way into each abstract', function () {
    $track = Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);
    $this->submission->forceFill(['track_id' => $track->id])->save();

    livewire(ListSubmissions::class)
        ->assertCanSeeTableRecords([$this->submission])
        ->assertCanRenderTableColumn('reference')
        ->assertCanRenderTableColumn('title')
        ->assertSee('AAM26-017')
        ->assertSee('Early mobilisation after cardiac surgery')
        ->assertSee('Neurocritical care')
        ->assertTableActionVisible('review', $this->submission);
});

it('never shows an author name in the queue, blind or not', function () {
    livewire(ListSubmissions::class)
        ->assertDontSee('Dr Sara Al-Harbi')
        ->assertDontSee('sara@example.org');

    // QueueTable has no author column and no author search in EITHER mode, by
    // design - so the two assertions above hold identically with blind_review
    // off, and the blind-only version of this test pinned nothing about
    // blinding. Both modes are asserted, so a later author column gated on
    // hidesAuthorsFrom() cannot leak every non-blind conference's authors with
    // this test still green.
    $this->conference->forceFill(['blind_review' => false])->save();

    livewire(ListSubmissions::class)
        ->assertDontSee('Dr Sara Al-Harbi')
        ->assertDontSee('sara@example.org');
});

it('shows only assignments in an assigned conference', function () {
    $this->conference->forceFill(['review_mode' => ReviewMode::Assigned])->save();
    $mine = Submission::factory()->for($this->conference)->submitted()->create(['title' => 'Mine to review']);
    ReviewAssignment::factory()->for($mine)->create(['reviewer_user_id' => $this->reviewer->id]);

    livewire(ListSubmissions::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$this->submission]);
});

it('does not list another conference abstracts', function () {
    $theirs = Submission::factory()->submitted()->create(['title' => 'Somebody else entirely']);

    livewire(ListSubmissions::class)
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertDontSee('Somebody else entirely');
});

it('opens the abstract with everything a reviewer needs and nothing that identifies the author', function () {
    livewire(ReviewSubmission::class, ['record' => $this->submission->getRouteKey()])
        ->assertOk()
        ->assertSee('AAM26-017')
        ->assertSee('Early mobilisation after cardiac surgery')
        ->assertSee($this->submission->abstract)
        // Blind: the author, the affiliation and the contact number are all
        // out (spec 5.4 step 4).
        ->assertDontSee('Dr Sara Al-Harbi')
        ->assertDontSee('sara@example.org')
        ->assertDontSee('+966500000000')
        ->assertSee(__('reviewer.review.blind_notice'));
});

it('shows the authors when the conference is not blind', function () {
    $this->conference->forceFill(['blind_review' => false])->save();

    livewire(ReviewSubmission::class, ['record' => $this->submission->fresh()->getRouteKey()])
        ->assertSee('Dr Sara Al-Harbi')
        ->assertSee('sara@example.org')
        ->assertDontSee(__('reviewer.review.blind_notice'));
});

it('hides an identifying custom field from a blind reviewer and shows it otherwise', function () {
    // Blinding the authors block is theatre if the organizer's own
    // "Institution" question prints the same answer two sections down. Only the
    // organizer knows which of their questions identify an author, so
    // custom_fields.hide_from_reviewers is how they say so (Task 1).
    CustomField::factory()->for($this->conference)->create([
        'key' => 'institution',
        'label' => 'Institution',
        'type' => CustomFieldType::Text,
        'required' => false,
        'hide_from_reviewers' => true,
        'sort' => 1,
    ]);
    CustomField::factory()->for($this->conference)->create([
        'key' => 'study_design',
        'label' => 'Study design',
        'type' => CustomFieldType::Text,
        'required' => false,
        'hide_from_reviewers' => false,
        'sort' => 2,
    ]);

    $this->submission->forceFill(['custom_field_values' => [
        'institution' => 'King Faisal Specialist Hospital',
        'study_design' => 'Prospective cohort',
    ]])->save();

    livewire(ReviewSubmission::class, ['record' => $this->submission->fresh()->getRouteKey()])
        ->assertDontSee('King Faisal Specialist Hospital')
        ->assertSee('Prospective cohort');

    $this->conference->forceFill(['blind_review' => false])->save();

    // Not blind: the flag says "identifies an author", not "secret", so an
    // open-review conference still prints it.
    livewire(ReviewSubmission::class, ['record' => $this->submission->fresh()->getRouteKey()])
        ->assertSee('King Faisal Specialist Hospital')
        ->assertSee('Prospective cohort');
});

it('404s an abstract outside the pool or the assignments', function () {
    $theirs = Submission::factory()->submitted()->create();

    // Through the route, not livewire(). ReviewSubmission::mount() goes through
    // Filament's InteractsWithRecord::resolveRecord(), which throws
    // ModelNotFoundException for a record the scoped query excludes
    // (vendor/filament/filament/src/Resources/Pages/Concerns/
    // InteractsWithRecord.php:42-44), and Livewire's harness rethrows anything
    // that is not an HttpException or an AuthorizationException
    // (vendor/livewire/livewire/src/Features/SupportTesting/RequestBroker.php:29)
    // - so livewire(...)->assertNotFound() would ERROR instead of asserting.
    get(SubmissionResource::getUrl('review', ['record' => $theirs], panel: 'reviewer'))->assertNotFound();

    $this->conference->forceFill(['review_mode' => ReviewMode::Assigned])->save();

    // In assigned mode, an abstract nobody assigned to this reviewer is a 404
    // rather than a 403: the reviewer has no business knowing it exists.
    get(SubmissionResource::getUrl('review', ['record' => $this->submission->fresh()], panel: 'reviewer'))
        ->assertNotFound();
});

it('blinds the downloaded file name and cannot be un-blinded by editing the url', function () {
    $sha = hash('sha256', 'pdf');
    Storage::disk('local')->put(substr($sha, 0, 2).'/a.pdf', '%PDF-1.4 test');
    $file = SubmissionFile::factory()->for($this->submission)->create([
        'original_name' => 'al-harbi-kfsh-final.pdf',
        'path' => substr($sha, 0, 2).'/a.pdf',
        'sha256' => $sha,
        'mime' => 'application/pdf',
        'sort' => 1,
    ]);

    livewire(ReviewSubmission::class, ['record' => $this->submission->getRouteKey()])
        ->assertSee('attachment-1.pdf')
        ->assertDontSee('al-harbi-kfsh-final.pdf');

    $blind = $file->temporaryUrl(blind: true);

    get($blind)->assertOk()->assertDownload('attachment-1.pdf');

    // Every parameter is inside the HMAC (fact 19), so stripping `blind`
    // invalidates the signature rather than revealing the name.
    get((string) preg_replace('/blind=1&?/', '', $blind))->assertForbidden();

    // The organizer's own link is not blinded.
    expect($file->temporaryUrl())->not->toContain('blind=');
});

it('lets a reviewer download a file in their pool and refuses one outside it', function () {
    $sha = hash('sha256', 'pdf2');
    Storage::disk('local')->put(substr($sha, 0, 2).'/b.pdf', '%PDF-1.4 test');
    $mine = SubmissionFile::factory()->for($this->submission)->create([
        'original_name' => 'abstract.pdf', 'path' => substr($sha, 0, 2).'/b.pdf', 'sha256' => $sha, 'sort' => 1,
    ]);

    $theirSubmission = Submission::factory()->submitted()->create();
    $theirs = SubmissionFile::factory()->for($theirSubmission)->create([
        'original_name' => 'theirs.pdf', 'path' => substr($sha, 0, 2).'/b.pdf', 'sha256' => hash('sha256', 'other'), 'sort' => 1,
    ]);

    // The policy is what decides where a signed URL may be *minted*
    // (SubmissionFilePolicy's own docblock); the route itself is authorized by
    // the signature.
    expect($this->reviewer->can('view', $mine))->toBeTrue()
        ->and($this->reviewer->can('view', $theirs))->toBeFalse();
});

it('blinds nothing for the organizer, including the csv export', function () {
    $organizer = User::factory()->create();
    $this->organization->addMember($organizer, OrganizationRole::Owner);
    actingAs($organizer);
    bootOrganizerPanel($this->organization);

    expect($this->conference->fresh()?->blind_review)->toBeTrue()
        ->and($this->conference->fresh()?->hidesAuthorsFrom($organizer))->toBeFalse();

    $component = livewire(OrganizerList::class)
        ->callTableAction('export')
        ->assertFileDownloaded();

    $csv = base64_decode((string) data_get($component->effects, 'download.content'), true);

    // Blind review is a rule about reviewers. Blinding the organizer's own
    // export would leave nobody able to answer an author's email.
    expect($csv)->toContain('sara@example.org')->toContain('Dr Sara Al-Harbi');
});

it('links each conference on the dashboard into its queue', function () {
    livewire(Dashboard::class)
        ->assertSee(__('reviewer.dashboard.open_queue'));

    get(SubmissionResource::urlForConference($this->conference))->assertOk();
});
