<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewMode;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Pages\CreateConference;
use App\Filament\Organizer\Resources\Conferences\Pages\EditConference;
use App\Filament\Organizer\Resources\Conferences\Pages\ListConferences;
use App\Models\Conference;
use App\Models\Organization;
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

it('lists only the conferences of the current tenant', function () {
    $mine = Conference::factory()->for($this->organization)->create(['name' => 'Alpha Annual Meeting']);
    $theirs = withoutTenant(fn () => Conference::factory()->create(['name' => 'Beta Annual Meeting']));

    livewire(ListConferences::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('creates a conference under the tenant with the default review form', function () {
    livewire(CreateConference::class)
        ->fillForm([
            'name' => 'Alpha Pediatric Congress',
            'short_description' => 'A three-day congress in Riyadh.',
            'starts_at' => now()->addMonths(7)->toDateString(),
            'ends_at' => now()->addMonths(7)->addDays(2)->toDateString(),
            'timezone' => 'Asia/Riyadh',
            'venue' => 'Riyadh Front',
            'city' => 'Riyadh',
            'country' => 'SA',
            'submission_opens_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'submission_deadline' => now()->addMonths(2)->format('Y-m-d H:i:s'),
            'review_deadline' => now()->addMonths(3)->format('Y-m-d H:i:s'),
            'review_mode' => ReviewMode::OpenPool->value,
            'blind_review' => true,
            'word_limit' => 450,
            'max_files' => 2,
            'allowed_file_types' => ['pdf'],
            'presentation_types' => ['oral', 'poster'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $conference = Conference::query()->where('name', 'Alpha Pediatric Congress')->firstOrFail();

    expect($conference->organization_id)->toBe($this->organization->id)
        ->and($conference->slug)->toBe('alpha-pediatric-congress')
        ->and($conference->status)->toBe(ConferenceStatus::Draft)
        ->and($conference->word_limit)->toBe(450)
        ->and($conference->reviewForm?->questions()->count())->toBe(9);
});

it('requires a name and rejects a deadline before the opening date', function () {
    livewire(CreateConference::class)
        ->fillForm([
            'name' => '',
            'submission_opens_at' => now()->addMonths(2)->format('Y-m-d H:i:s'),
            'submission_deadline' => now()->addMonth()->format('Y-m-d H:i:s'),
        ])
        ->call('create')
        ->assertHasFormErrors(['name', 'submission_deadline']);
});

it('takes an optional web address and refuses one already used', function () {
    livewire(CreateConference::class)
        ->fillForm(['name' => 'Gulf Pediatric Critical Care 2026', 'slug' => 'gpcc26'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Conference::query()->where('name', 'Gulf Pediatric Critical Care 2026')->firstOrFail()->slug)
        ->toBe('gpcc26');

    // The second half of the name: the same web address cannot be taken twice
    // within one organization.
    livewire(CreateConference::class)
        ->fillForm(['name' => 'Gulf Pediatric Critical Care 2027', 'slug' => 'gpcc26'])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('refuses a web address a soft-deleted conference still holds', function () {
    Conference::factory()->for($this->organization)->create(['name' => 'Winter School'])->delete();

    livewire(CreateConference::class)
        ->fillForm(['name' => 'Winter School 2027', 'slug' => 'winter-school'])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('asks for reviewers per submission only in assigned mode', function () {
    livewire(CreateConference::class)
        ->fillForm(['review_mode' => ReviewMode::OpenPool->value])
        ->assertFormFieldIsHidden('reviewers_per_submission')
        ->fillForm(['review_mode' => ReviewMode::Assigned->value])
        ->assertFormFieldIsVisible('reviewers_per_submission');
});

it('stores reviewers per submission for an assigned-mode conference', function () {
    // A hidden field is not dehydrated, so an always-false visible() closure
    // (comparing $get('review_mode') with ->value instead of the enum case)
    // would silently drop this value rather than fail loudly.
    livewire(CreateConference::class)
        ->fillForm([
            'name' => 'Assigned Review Meeting',
            'review_mode' => ReviewMode::Assigned->value,
            'reviewers_per_submission' => 3,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Conference::query()->where('name', 'Assigned Review Meeting')->firstOrFail()->reviewers_per_submission)
        ->toBe(3);
});

it('edits a conference and keeps the slug fixed', function () {
    $conference = Conference::factory()->for($this->organization)->create(['name' => 'Alpha Annual Meeting']);

    livewire(EditConference::class, ['record' => $conference->getRouteKey()])
        ->fillForm([
            'name' => 'Alpha Annual Scientific Meeting',
            'venue' => 'King Saud University',
            'word_limit' => 600,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $conference->refresh();
    expect($conference->name)->toBe('Alpha Annual Scientific Meeting')
        ->and($conference->venue)->toBe('King Saud University')
        ->and($conference->word_limit)->toBe(600)
        ->and($conference->slug)->toBe('alpha-annual-meeting');
});

it('hides the conference resource from a user with no membership', function () {
    $stranger = User::factory()->create();
    Organization::factory()->approved()->create()->addMember($stranger, OrganizationRole::Owner);

    actingAs($stranger)->get(ConferenceResource::getUrl('index', tenant: $this->organization))
        ->assertNotFound();
});

it('refuses to open a conference belonging to another organization', function () {
    $theirs = withoutTenant(fn () => Conference::factory()->create());

    get(ConferenceResource::getUrl('edit', ['record' => $theirs->getRouteKey()], tenant: $this->organization))
        ->assertNotFound();
});

it('opens the pages behind the URLs Filament generates', function () {
    // Filament builds every record URL by handing the model to route(), which
    // uses getRouteKey(), and resolves it with the resource's own key name. If
    // those two disagree, the redirect after create, the table's row actions
    // and every breadcrumb 404 while tests that pass an explicit key pass.
    $conference = Conference::factory()->for($this->organization)->create();

    foreach (['edit'] as $page) {
        get(ConferenceResource::getUrl($page, ['record' => $conference]))->assertOk();
    }
});

it('lets a plain member create and edit conferences', function () {
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    actingAs($member);

    livewire(CreateConference::class)
        ->fillForm(['name' => 'Member Created Meeting'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Conference::query()->where('name', 'Member Created Meeting')->exists())->toBeTrue();
});

it('offers delete only to owners and admins', function () {
    $conference = Conference::factory()->for($this->organization)->create();
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);

    expect($this->user->can('delete', $conference))->toBeTrue();
    expect($member->can('delete', $conference))->toBeFalse();
    expect($member->can('update', $conference))->toBeTrue();
});

it('offers bulk delete to owners but not to plain members', function () {
    // Filament authorizes DeleteBulkAction with deleteAny() and treats a
    // missing policy method as "allow", so without deleteAny() a plain member
    // sees "Delete selected" and can soft-delete every conference of the
    // organization while `can('delete', $conference)` still answers false.
    $conference = Conference::factory()->for($this->organization)->create();
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);

    actingAs($member);
    livewire(ListConferences::class)->assertTableBulkActionHidden('delete');
    expect($member->can('deleteAny', Conference::class))->toBeFalse();

    actingAs($this->user);
    livewire(ListConferences::class)
        ->assertTableBulkActionVisible('delete')
        ->callTableBulkAction('delete', [$conference]);

    expect($conference->fresh()?->trashed())->toBeTrue();
});
