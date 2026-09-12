<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Admin\Resources\Conferences\Pages\ListConferences as AdminListConferences;
use App\Filament\Admin\Resources\Organizations\Pages\ListOrganizations;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Policies\ConferencePolicy;
use App\Policies\OrganizationPolicy;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->platformAdmin()->create();
    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();
});

it('shows what a purge would destroy, and refuses the wrong word', function () {
    $conference = Conference::factory()->create(['name' => 'Alpha Annual Meeting', 'slug' => 'annual-meeting']);
    Submission::factory()->for($conference)->submitted()->count(3)->create();

    livewire(AdminListConferences::class)
        ->mountTableAction('purge', $conference)
        // The count is in the modal before anything is typed: a yes/no
        // confirmation for an irreversible cascade over fourteen tables is not
        // a confirmation.
        ->assertMountedActionModalSee('3')
        ->setTableActionData(['confirmation' => 'not-the-slug'])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['confirmation']);

    expect(Conference::whereKey($conference->getKey())->exists())->toBeTrue();
});

it('purges a conference when the slug is typed', function () {
    $conference = Conference::factory()->create(['slug' => 'annual-meeting']);
    Submission::factory()->for($conference)->submitted()->create();

    livewire(AdminListConferences::class)
        ->callTableAction('purge', $conference, ['confirmation' => 'annual-meeting'])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    expect(Conference::withTrashed()->whereKey($conference->getKey())->exists())->toBeFalse();
});

it('purges an organization, and its conferences with it', function () {
    $organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $conference = Conference::factory()->for($organization)->create();

    livewire(ListOrganizations::class)
        // The table filters to Pending by default (OrganizationsTable), and an
        // action can only be mounted against a record the table query returns -
        // the idiom OrganizationApprovalTest already uses to reject an approved
        // organization.
        ->removeTableFilter('status')
        ->callTableAction('purge', $organization, ['confirmation' => (string) $organization->slug])
        ->assertHasNoTableActionErrors();

    expect(Organization::withTrashed()->whereKey($organization->getKey())->exists())->toBeFalse()
        ->and(Conference::withTrashed()->whereKey($conference->getKey())->exists())->toBeFalse();
});

it('does not gate on the demo flag', function () {
    // The backlog is explicit: "a platform-admin purge must NOT gate on that
    // flag, and must find its own confirmation instead". The typed slug is
    // that confirmation.
    $organization = Organization::factory()->approved()->create();

    livewire(ListOrganizations::class)
        ->removeTableFilter('status')
        ->callTableAction('purge', $organization, ['confirmation' => (string) $organization->slug])
        ->assertHasNoTableActionErrors();

    expect(Organization::withTrashed()->whereKey($organization->getKey())->exists())->toBeFalse();
})->skip(fn (): bool => ! Schema::hasColumn('organizations', 'is_demo'), 'The demo branch has not merged.');

it('offers no purge to an organization owner, and no force delete to anybody', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->approved()->create();
    $organization->addMember($owner, OrganizationRole::Owner);
    $conference = Conference::factory()->for($organization)->create();

    $organizationPolicy = app(OrganizationPolicy::class);
    $conferencePolicy = app(ConferencePolicy::class);

    expect($organizationPolicy->purge($this->admin, $organization))->toBeTrue()
        ->and($organizationPolicy->purge($owner, $organization))->toBeFalse()
        // forceDelete is false for EVERYBODY on an organization, platform
        // admin included, so Filament can never surface a ForceDeleteAction
        // that would run into the RESTRICT key on conferences.organization_id.
        ->and($organizationPolicy->forceDelete($this->admin, $organization))->toBeFalse()
        ->and($organizationPolicy->forceDeleteAny($this->admin))->toBeFalse()
        ->and($conferencePolicy->purge($owner, $conference))->toBeFalse();

    actingAs($owner);
    Filament::setCurrentPanel('admin');
    livewire(ListOrganizations::class)->assertForbidden();
});

it('never offers a filament force-delete or a bulk purge on either admin table', function () {
    Conference::factory()->create();
    Organization::factory()->approved()->create();

    foreach ([AdminListConferences::class, ListOrganizations::class] as $page) {
        $table = livewire($page)->instance()->getTable();

        // array_merge, not `+`: on two numerically-indexed arrays `+` silently
        // drops every bulk-action name whose index already exists.
        $names = array_merge(
            array_keys($table->getFlatActions()),
            array_keys($table->getFlatBulkActions()),
        );

        expect($names)->not->toContain('forceDelete')
            ->and($names)->not->toContain('forceDeleteAny')
            // Fourteen tables and no undo is not a thing to apply to a
            // checkbox selection.
            ->and($names)->not->toContain('purgeSelected');
    }
});
