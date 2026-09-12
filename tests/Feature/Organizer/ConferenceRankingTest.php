<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceRanking;
use App\Filament\Organizer\Resources\Conferences\Pages\ViewConference;
use App\Filament\Organizer\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Support\Scoring\RankedSubmissions;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->closed()->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
        'reviewers_per_submission' => 2,
        'review_deadline' => now()->addMonth(),
    ]);

    $this->track = Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);

    $this->best = Submission::factory()->for($this->conference)->scored(91.0, 2.0, 3)
        ->create(['title' => 'Early mobilisation after cardiac surgery', 'track_id' => $this->track->id]);
    $this->best->forceFill(['reference' => 'AAM26-001'])->save();

    $this->middle = Submission::factory()->for($this->conference)->scored(55.5, 20.0, 2)
        ->create(['title' => 'A middling abstract']);
    $this->middle->forceFill(['reference' => 'AAM26-002'])->save();

    $this->unreviewed = Submission::factory()->for($this->conference)->submitted()
        ->create(['title' => 'Nobody has read this yet']);
    $this->unreviewed->forceFill(['reference' => 'AAM26-003'])->save();
});

it('lists the abstracts under consideration, best first', function () {
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->assertCanSeeTableRecords([$this->best, $this->middle, $this->unreviewed], inOrder: true)
        ->assertCanRenderTableColumn('reference')
        ->assertCanRenderTableColumn('title')
        ->assertCanRenderTableColumn('score')
        ->assertCanRenderTableColumn('score_spread')
        ->assertCanRenderTableColumn('review_count')
        ->assertCanRenderTableColumn('decision')
        ->assertSee('AAM26-001')
        ->assertSee('Neurocritical care');
});

it('leaves out drafts and withdrawals', function () {
    $draft = Submission::factory()->for($this->conference)->create(['title' => 'Never submitted']);
    $withdrawn = Submission::factory()->for($this->conference)->withdrawn()->create(['title' => 'Taken back']);

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->assertCanNotSeeTableRecords([$draft, $withdrawn])
        ->assertCountTableRecords(3);

    // The status filter derives its options from this same list, so the two
    // cannot drift into offering an option that can only ever render an empty
    // table (Step 4). Asserted at the source, where the one definition lives.
    expect(RankedSubmissions::statuses())->not->toContain(SubmissionStatus::Draft->value)
        ->and(RankedSubmissions::statuses())->not->toContain(SubmissionStatus::Withdrawn->value)
        ->and(RankedSubmissions::statuses())->toContain(SubmissionStatus::Submitted->value);
});

it('keeps another conference out, even inside the same organization', function () {
    $other = Conference::factory()->for($this->organization)->closed()->create(['name' => 'Alpha Winter School']);
    $theirs = Submission::factory()->for($other)->scored(99.0)->create(['title' => 'Winter abstract']);

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('sorts by score, spread, review count and track', function () {
    $second = Track::factory()->for($this->conference)->create(['name' => 'Respiratory']);
    $this->middle->forceFill(['track_id' => $second->id])->save();

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->sortTable('score', 'asc')
        // Ascending puts the unscored abstract first on both drivers: NULL is
        // the smallest value in SQLite and sorts first ascending in MySQL.
        // Task 11 repeats this assertion against MySQL.
        ->assertCanSeeTableRecords([$this->unreviewed, $this->middle, $this->best], inOrder: true)
        ->sortTable('score_spread', 'desc')
        ->assertCanSeeTableRecords([$this->middle, $this->best], inOrder: true)
        ->sortTable('review_count', 'desc')
        ->assertCanSeeTableRecords([$this->best, $this->middle], inOrder: true)
        // Spec 5.6 names track among the sortable columns, and it is the only
        // one of the four Filament answers with a relationship subquery
        // (InteractsWithTableQuery::applySort -> RelationshipOrderer::buildSubquery,
        // vendor/filament/tables/src/Columns/Concerns/InteractsWithTableQuery.php:147-155)
        // rather than an ORDER BY on a column of `submissions` - so a dropped
        // ->sortable() here is the one sort the other three cannot catch.
        // assertCanSeeTableRecords(inOrder:) asserts relative order only, so the
        // track-less third row does not enter this assertion.
        ->sortTable('track.name', 'asc')
        ->assertCanSeeTableRecords([$this->best, $this->middle], inOrder: true);
});

it('filters by status, track, decision and by how few reviews there are', function () {
    $this->middle->forceFill([
        'decision' => Decision::Waitlisted,
        'status' => SubmissionStatus::Waitlisted,
    ])->save();

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->filterTable('status', SubmissionStatus::Waitlisted->value)
        ->assertCanSeeTableRecords([$this->middle])
        ->assertCanNotSeeTableRecords([$this->best]);

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->filterTable('track_id', $this->track->id)
        ->assertCanSeeTableRecords([$this->best])
        ->assertCanNotSeeTableRecords([$this->middle]);

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->filterTable('decision', Decision::Waitlisted->value)
        ->assertCanSeeTableRecords([$this->middle])
        ->assertCanNotSeeTableRecords([$this->best, $this->unreviewed]);

    // The one filter an organizer actually chases stragglers with.
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->filterTable('decision', 'none')
        ->assertCanSeeTableRecords([$this->best, $this->unreviewed])
        ->assertCanNotSeeTableRecords([$this->middle]);

    // A Filter with its own schema: filterTable() passes an array through
    // untouched (vendor/filament/tables/src/Testing/TestsFilters.php:48-52).
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->filterTable('under_reviewed', ['count' => 2])
        ->assertCanSeeTableRecords([$this->unreviewed])
        ->assertCanNotSeeTableRecords([$this->best, $this->middle]);
});

it('searches by reference and by title', function () {
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->searchTable('AAM26-002')
        ->assertCanSeeTableRecords([$this->middle])
        ->assertCanNotSeeTableRecords([$this->best]);

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->searchTable('mobilisation')
        ->assertCanSeeTableRecords([$this->best])
        ->assertCanNotSeeTableRecords([$this->middle]);
});

it('prints a summary strip of what the committee is looking at', function () {
    $this->middle->forceFill(['decision' => Decision::Waitlisted, 'status' => SubmissionStatus::Waitlisted])->save();
    $this->best->forceFill(['decision' => Decision::AcceptedOral, 'status' => SubmissionStatus::Accepted])->save();

    $summary = $this->conference->fresh()?->rankingSummary();

    expect($summary['total'])->toBe(3)
        ->and($summary['reviewed'])->toBe(2)
        ->and($summary['unreviewed'])->toBe(1)
        ->and($summary['decided'])->toBe(2)
        ->and($summary['undecided'])->toBe(1)
        // The mean of the abstracts that have one, not of all three.
        ->and($summary['mean_score'])->toBe(73.25)
        ->and($summary['by_decision'][Decision::AcceptedOral->value])->toBe(1)
        ->and($summary['by_decision'][Decision::AcceptedPoster->value])->toBe(0)
        ->and($summary['by_decision'][Decision::Waitlisted->value])->toBe(1)
        ->and($summary['by_decision'][Decision::Rejected->value])->toBe(0);

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->assertSee(__('decisions.summary.reviewed'))
        ->assertSee(__('decisions.summary.mean'))
        ->assertSee('73.25');
});

it('prints an em dash, not a zero, for a conference nobody has reviewed', function () {
    // Every other ranking fixture has at least one scored row, so mean_score is
    // never null and the summary strip's fallback never renders. A change that
    // returned 0.0 - or a cast that turned a null avg() into 0 - would print
    // "0.00" as the mean score of a conference nobody has read, right beside
    // "Reviewed 0", and nothing would go red.
    $quiet = Conference::factory()->for($this->organization)->closed()->create([
        'name' => 'Quiet Meeting',
        'status' => ConferenceStatus::Reviewing,
        'reviewers_per_submission' => 2,
    ]);
    Submission::factory()->for($quiet)->submitted()->create(['title' => 'Nobody has read this either']);

    $summary = $quiet->fresh()?->rankingSummary();

    expect($summary['total'])->toBe(1)
        ->and($summary['reviewed'])->toBe(0)
        ->and($summary['mean_score'])->toBeNull();

    livewire(ConferenceRanking::class, ['record' => $quiet->getRouteKey()])
        ->assertSee(__('decisions.summary.mean'))
        ->assertSee('—');
});

it('links each row to the submission view and back to the conference', function () {
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->assertSee(SubmissionResource::getUrl('view', ['record' => $this->best]))
        ->assertActionExists('backToConference');
});

it('is reachable from the conference view once reviewing has started', function () {
    livewire(ViewConference::class, ['record' => $this->conference->getRouteKey()])
        ->assertActionVisible('ranking');

    $draft = Conference::factory()->for($this->organization)->create();

    // Before reviewing there is nothing to rank, and an empty ranking table on
    // a conference still collecting abstracts is a screen that answers a
    // question nobody asked.
    livewire(ViewConference::class, ['record' => $draft->getRouteKey()])
        ->assertActionHidden('ranking');
});

// --- Cross-tenant -------------------------------------------------------

it('404s the ranking of another organization conference', function () {
    $theirs = withoutTenant(fn (): Conference => Conference::factory()->create([
        'status' => ConferenceStatus::Reviewing,
    ]));

    // Through the route, not livewire(). Filament's InteractsWithRecord
    // ::resolveRecord() throws ModelNotFoundException when the tenant-scoped
    // query excludes the record (vendor/filament/filament/src/Resources/Pages/
    // Concerns/InteractsWithRecord.php:39-43), and Livewire's test harness
    // rethrows everything except HttpException and AuthorizationException
    // (vendor/livewire/livewire/src/Features/SupportTesting/RequestBroker.php:29),
    // so livewire(...)->assertNotFound() would ERROR rather than assert.
    get(ConferenceResource::getUrl('ranking', ['record' => $theirs]))->assertNotFound();
});

it('refuses the ranking to somebody with no role in this organization', function () {
    $outsider = User::factory()->create();
    $otherOrganization = Organization::factory()->approved()->create();
    $otherOrganization->addMember($outsider, OrganizationRole::Owner);

    actingAs($outsider);
    bootOrganizerPanel($otherOrganization);

    get(ConferenceResource::getUrl('ranking', ['record' => $this->conference], tenant: $this->organization))
        ->assertNotFound();
});
