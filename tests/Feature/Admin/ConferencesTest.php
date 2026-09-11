<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Admin\Resources\Conferences\ConferenceResource as AdminConferenceResource;
use App\Filament\Admin\Resources\Conferences\Pages\ListConferences as AdminListConferences;
use App\Models\Conference;
use App\Models\Organization;
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
