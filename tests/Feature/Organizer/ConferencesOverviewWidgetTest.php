<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Organizer\Widgets\ConferencesOverview;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\ShortLink;
use App\Models\Track;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);
});

it('lists this tenant conferences with status, deadline and counts', function () {
    // 4817, not 12: a Filament page is full of icon path data and dates, so
    // assertSee('12') passes whatever the scan count is.
    $conference = Conference::factory()->for($this->organization)->published()->create(['name' => 'Alpha Annual Meeting']);
    Track::factory()->for($conference)->create();
    Track::factory()->for($conference)->create();
    ShortLink::forTarget($conference)->forceFill(['clicks' => 4817])->save();

    livewire(ConferencesOverview::class)
        ->assertCanSeeTableRecords([$conference])
        ->assertSee('Alpha Annual Meeting')
        ->assertSee('Open for submissions')
        ->assertSee('4817')
        ->assertSee($conference->deadlineInConferenceTimezone()?->format('j M Y, H:i'));
});

it('never shows another organization conferences', function () {
    $mine = Conference::factory()->for($this->organization)->create();
    $theirs = withoutTenant(fn () => Conference::factory()->create());

    livewire(ConferencesOverview::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('replaces the placeholder card on the dashboard', function () {
    // Widgets are lazy by default, so the table heading is not in the first
    // response - and after Task 7 the sidebar always says "Conferences", which
    // is why that assertion would prove nothing. Assert the component itself.
    get("/org/{$this->organization->slug}")
        ->assertOk()
        ->assertDontSee('Conference management arrives in the next release')
        ->assertSeeLivewire(ConferencesOverview::class);
});

it('keeps the pending banner above the conferences widget', function () {
    // Spec 5.1 lets a pending organization draft a conference (it just cannot
    // publish one), so the widget stays; only the banner is extra.
    $pending = Organization::factory()->create();
    $owner = User::factory()->create();
    $pending->addMember($owner, OrganizationRole::Owner);

    actingAs($owner)->get("/org/{$pending->slug}")
        ->assertOk()
        ->assertSee('awaiting approval')
        ->assertSeeLivewire(ConferencesOverview::class);
});
