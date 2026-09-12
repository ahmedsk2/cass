<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\OrganizationRole;
use App\Filament\Admin\Resources\Conferences\ConferenceResource as AdminConferenceResource;
use App\Filament\Admin\Resources\Conferences\Pages\ListConferences as AdminListConferences;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
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
});

it('shows the platform admin every organization conference', function () {
    // Spec section 4 gives the platform admin "see all organizations and
    // conferences"; the organizer panel admits members only, so without this
    // read-only resource nobody outside an organization can look at one.
    $first = Conference::factory()->create(['name' => 'Alpha Annual Meeting']);
    $second = Conference::factory()->create(['name' => 'Beta Annual Meeting']);

    livewire(AdminListConferences::class)
        ->assertCanSeeTableRecords([$first, $second])
        ->assertSee($first->organization->name)
        ->assertSee($second->organization->name);
});

it('opens the right conference when two organizations share a slug', function () {
    $first = Conference::factory()->create(['name' => 'Annual Meeting']);
    $second = Conference::factory()->create(['name' => 'Annual Meeting']);

    expect($first->slug)->toBe($second->slug);

    get(AdminConferenceResource::getUrl('view', ['record' => $second], panel: 'admin'))
        ->assertOk()
        ->assertSee($second->organization->name);
});

it('lets the platform admin restore a soft-deleted conference', function () {
    // The organizer-facing delete modal promises the platform team can restore
    // a conference; this is where that promise is kept.
    $conference = Conference::factory()->create();
    $conference->delete();

    livewire(AdminListConferences::class)
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$conference])
        ->callTableAction('restore', $conference);

    expect($conference->fresh()?->trashed())->toBeFalse();
});

it('refuses the admin conference list to an organization owner', function () {
    $owner = User::factory()->create();
    Organization::factory()->approved()->create()->addMember($owner, OrganizationRole::Owner);

    actingAs($owner)->get(AdminConferenceResource::getUrl('index', panel: 'admin'))
        ->assertForbidden();
});

it('links a conference to an organization page that opens', function () {
    // The two resources have to agree on how an organization is addressed: the
    // link is printed here and resolved by OrganizationResource.
    $conference = Conference::factory()->create();
    $organizationUrl = OrganizationResource::getUrl('view', ['record' => $conference->organization], panel: 'admin');

    get(AdminConferenceResource::getUrl('view', ['record' => $conference], panel: 'admin'))
        ->assertOk()
        ->assertSee($organizationUrl, escape: false);

    get($organizationUrl)->assertOk()->assertSee($conference->organization->name);
});

it('shows read-only decision counts on the admin conference list', function () {
    $conference = Conference::factory()->create(['status' => ConferenceStatus::Reviewing]);
    Submission::factory()->for($conference)->decided(Decision::AcceptedOral, notified: true)->create();
    Submission::factory()->for($conference)->decided(Decision::Rejected)->create();
    Submission::factory()->for($conference)->submitted()->create();

    // `AdminListConferences`, which is the name this file already imports the
    // page under - a bare `ListConferences` here is an unimported global and
    // the case errors before it reaches a column.
    $component = livewire(AdminListConferences::class)
        ->assertCanRenderTableColumn('decided_count')
        ->assertCanRenderTableColumn('notified_count')
        ->assertCanSeeTableRecords([$conference]);

    // The counts as the TABLE computed them, read off the record Filament
    // actually loaded, so a wrong or missing ->counts() constraint fails here.
    // Re-deriving them with a second withCount() in the test would assert the
    // test's own query and pass for a column that renders nothing.
    $row = $component->instance()->getTableRecords()->firstWhere('id', $conference->id);

    expect((int) $row?->decided_count)->toBe(2)
        ->and((int) $row?->notified_count)->toBe(1);
});

it('gives the platform admin no way to decide anything from the conference list', function () {
    $conference = Conference::factory()->create(['status' => ConferenceStatus::Reviewing]);
    Submission::factory()->for($conference)->decided(Decision::AcceptedOral)->create();

    // Spec section 4's platform-admin cells are read-only here, exactly as
    // Plans 3 and 4 left submissions and reviews: the counts are visible and
    // nothing writes. The guard that can actually regress is the table's action
    // list, so that is what is asserted - not `class_exists()` on a class this
    // plan never creates, which is a placeholder assertion that can never be
    // shown failing first and would go red the day Plan 6 adds the read-only
    // admin SubmissionResource the backlog already schedules.
    $table = livewire(AdminListConferences::class)
        ->assertCanSeeTableRecords([$conference])
        ->instance()
        ->getTable();

    // array_merge, not `+`: `+` on two numerically-indexed arrays silently
    // drops every bulk-action name whose index already exists in the first.
    $actions = array_merge(
        array_keys($table->getFlatActions()),
        array_keys($table->getFlatBulkActions()),
    );

    expect($actions)->not->toContain('decide')
        ->not->toContain('changeDecision')
        ->not->toContain('decideSelected')
        ->not->toContain('sendDecisionEmails');
});
