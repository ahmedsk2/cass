<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\OrganizationRole;
use App\Filament\Admin\Resources\Conferences\ConferenceResource as AdminConferenceResource;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Filament\Admin\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\SubmissionDecision;
use App\Models\SubmissionFile;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->platformAdmin()->create();
    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->conference = Conference::factory()->for($this->organization)->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
    ]);
    $this->submission = Submission::factory()->for($this->conference)->submitted()->create([
        'title' => 'Early mobilisation after cardiac surgery',
    ]);
});

it('lists every organization submission, with the conference and the organization', function () {
    $theirs = Submission::factory()->submitted()->create(['title' => 'Another society abstract']);

    // The point of the screen: an admin sees across tenants, which nobody in
    // the organizer panel can (canAccessPanel('organizer') requires
    // membership) and which spec section 4 puts in the admin column.
    livewire(ListSubmissions::class)
        ->assertCanSeeTableRecords([$this->submission, $theirs])
        ->assertCanRenderTableColumn('reference')
        ->assertCanRenderTableColumn('title')
        ->assertCanRenderTableColumn('conference.name')
        ->assertCanRenderTableColumn('conference.organization.name')
        ->assertSee('Alpha Society');
});

it('filters by organization, by conference status and by decision', function () {
    $decided = Submission::factory()->for($this->conference)->submitted()->create();
    $decided->forceFill(['decision' => Decision::AcceptedOral, 'decision_notified_at' => now()])->save();

    livewire(ListSubmissions::class)
        ->filterTable('decision', Decision::AcceptedOral->value)
        ->assertCanSeeTableRecords([$decided])
        ->assertCanNotSeeTableRecords([$this->submission]);
});

it('opens one submission with its authors, its files and its decision history', function () {
    SubmissionAuthor::factory()->for($this->submission)->create([
        'name' => 'Sara Al-Harbi',
        'email' => 'sara@example.org',
        'is_presenter' => true,
    ]);
    SubmissionFile::factory()->for($this->submission)->create(['original_name' => 'abstract.pdf']);
    SubmissionDecision::factory()->for($this->submission)->create([
        'decision' => Decision::Waitlisted,
        'decided_at' => now()->subDay(),
    ]);
    $this->submission->forceFill(['decision' => Decision::Waitlisted])->save();

    get(SubmissionResource::getUrl('view', ['record' => $this->submission], panel: 'admin'))
        ->assertOk()
        ->assertSee('Early mobilisation after cardiac surgery')
        ->assertSee('Sara Al-Harbi')
        ->assertSee('abstract.pdf')
        ->assertSee(Decision::Waitlisted->getLabel())
        // Spec section 8: files are reachable only through a signed, expiring
        // route. The link is minted per render, behind the policy, exactly as
        // the organizer infolist mints it.
        ->assertSee('/files/', escape: false)
        ->assertSee('signature=', escape: false);
});

it('gives the platform admin nothing that writes', function () {
    $table = livewire(ListSubmissions::class)->instance()->getTable();

    // array_merge, not `+`: `+` on two numerically-indexed arrays silently
    // drops every bulk-action name whose index already exists in the first.
    $names = array_merge(
        array_keys($table->getFlatActions()),
        array_keys($table->getFlatBulkActions()),
    );

    // Filament treats a MISSING policy method as ALLOW, and SubmissionPolicy's
    // before() answers true for a platform admin without the ability ever
    // being called - so the refusal has to be the resource, not the policy.
    expect($names)->toBe(['view'])
        ->and(array_keys(SubmissionResource::getPages()))->toBe(['index', 'view']);

    // getHeaderActions() is protected (Pages/Concerns/InteractsWithHeaderActions.php:55);
    // getCachedHeaderActions() (:47) is the public accessor, populated on mount
    // by cacheInteractsWithHeaderActions().
    expect(livewire(ListSubmissions::class)->instance()->getCachedHeaderActions())->toBe([])
        ->and(livewire(ViewSubmission::class, ['record' => $this->submission->getRouteKey()])->instance()->getCachedHeaderActions())->toBe([]);
});

it('is forbidden to an organization owner who can see the same rows in their own panel', function () {
    $owner = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);

    actingAs($owner)->get(SubmissionResource::getUrl('index', panel: 'admin'))->assertForbidden();
    actingAs($owner)->get(SubmissionResource::getUrl('view', ['record' => $this->submission], panel: 'admin'))->assertForbidden();
});

it('links a submission to its conference and its organization, and both open', function () {
    $conferenceUrl = AdminConferenceResource::getUrl('view', ['record' => $this->conference], panel: 'admin');
    $organizationUrl = OrganizationResource::getUrl('view', ['record' => $this->organization], panel: 'admin');

    get(SubmissionResource::getUrl('view', ['record' => $this->submission], panel: 'admin'))
        ->assertOk()
        ->assertSee($conferenceUrl, escape: false)
        ->assertSee($organizationUrl, escape: false);

    get($conferenceUrl)->assertOk();
    get($organizationUrl)->assertOk();
});

it('finds an organization and a conference by name in global search, and never an abstract', function () {
    expect(OrganizationResource::getGloballySearchableAttributes())->toContain('name')
        ->and(AdminConferenceResource::getGloballySearchableAttributes())->toContain('name')
        // An abstract title is author-supplied text and a global search box
        // indexes it across every tenant at once. The resource's own filtered
        // table is one click further.
        ->and(SubmissionResource::canGloballySearch())->toBeFalse();

    $results = OrganizationResource::getGlobalSearchResults('Alpha');

    expect($results)->not->toBeEmpty();
});
