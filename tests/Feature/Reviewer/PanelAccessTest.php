<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ReviewerStatus;
use App\Filament\Reviewer\Pages\Dashboard;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\User;
use App\Support\Panels\PanelSwitch;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->conference = Conference::factory()->for($this->organization)->closed()->create([
        'name' => 'Alpha Annual Meeting',
        'review_deadline' => now()->addMonth(),
    ]);
    $this->reviewer = User::factory()->create(['name' => 'Dr Omar Khan']);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->reviewer->id]);
});

it('registers a third panel at /review with its own login', function () {
    get('/review')->assertRedirect('/review/login');
    get('/review/login')->assertOk();

    expect(Filament::getPanel('reviewer')->getId())->toBe('reviewer')
        ->and(Filament::getPanel('reviewer')->getPath())->toBe('review')
        ->and(Filament::getPanel('reviewer')->hasTenancy())->toBeFalse()
        ->and(route('filament.reviewer.auth.login'))->toEndWith('/review/login');
});

it('lets an active reviewer in', function () {
    actingAs($this->reviewer)->get('/review')->assertOk()->assertSee('Alpha Annual Meeting');
});

it('keeps everybody who is not an active reviewer out', function () {
    $stranger = User::factory()->create();
    $organizer = User::factory()->create();
    $this->organization->addMember($organizer, OrganizationRole::Owner);

    actingAs($stranger)->get('/review')->assertForbidden();
    // An organizer is not automatically a reviewer: spec section 4 gives them
    // "Invite reviewers, assign, decide" and not "Submit reviews".
    actingAs($organizer)->get('/review')->assertForbidden();
});

it('keeps a removed reviewer out', function () {
    ConferenceReviewer::query()->where('user_id', $this->reviewer->id)->firstOrFail()
        ->forceFill(['status' => ReviewerStatus::Removed, 'removed_at' => now()])->save();

    actingAs($this->reviewer->fresh())->get('/review')->assertForbidden();

    expect($this->reviewer->fresh()?->isActiveReviewer())->toBeFalse();
});

it('sends an unverified reviewer to the prompt rather than a bare 403', function () {
    $unverified = User::factory()->unverified()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $unverified->id]);

    actingAs($unverified)->get('/review')->assertRedirect('/review/email-verification/prompt');
});

it('lists the conferences this reviewer reviews and nobody else\'s', function () {
    $otherConference = Conference::factory()->closed()->create(['name' => 'Somebody Else Meeting']);
    ConferenceReviewer::factory()->for($otherConference)->create();

    actingAs($this->reviewer);
    bootReviewerPanel();

    livewire(Dashboard::class)
        ->assertOk()
        ->assertSee('Alpha Annual Meeting')
        ->assertSee('Alpha Society')
        ->assertDontSee('Somebody Else Meeting');
});

it('shows the review deadline in the conference timezone', function () {
    $this->conference->forceFill([
        'timezone' => 'Asia/Riyadh',
        'review_deadline' => Carbon\Carbon::parse('2026-11-03 20:59:00', 'UTC'),
    ])->save();

    actingAs($this->reviewer);
    bootReviewerPanel();

    // 20:59 UTC is 23:59 in Riyadh. Printing the UTC hour next to the words
    // "Asia/Riyadh" is the bug this asserts against (spec section 10).
    livewire(Dashboard::class)->assertSee('3 November 2026, 23:59');
});

it('offers the organizer a link to the reviewer panel only when they review something', function () {
    $both = User::factory()->create();
    $this->organization->addMember($both, OrganizationRole::Owner);

    actingAs($both);
    bootOrganizerPanel($this->organization);

    expect(PanelSwitch::toReviewer()->isVisible())->toBeFalse();

    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $both->id]);

    actingAs($both->fresh());
    bootOrganizerPanel($this->organization);

    $action = PanelSwitch::toReviewer();

    expect($action->isVisible())->toBeTrue()
        ->and($action->getUrl())->toEndWith('/review');
});

it('offers the reviewer a link back to their organization only when they belong to one', function () {
    actingAs($this->reviewer);
    bootReviewerPanel();

    expect(PanelSwitch::toOrganizer()->isVisible())->toBeFalse();

    $this->organization->addMember($this->reviewer, OrganizationRole::Member);

    actingAs($this->reviewer->fresh());
    bootReviewerPanel();

    $action = PanelSwitch::toOrganizer();

    expect($action->isVisible())->toBeTrue()
        ->and($action->getUrl())->toContain('/org/'.$this->organization->slug);
});

it('renders both switch links in the panels that own them', function () {
    $this->organization->addMember($this->reviewer, OrganizationRole::Owner);

    actingAs($this->reviewer->fresh())
        ->get('/review')
        ->assertOk()
        ->assertSee(__('reviewer.switch.to_organizer'));

    actingAs($this->reviewer->fresh())
        ->get('/org/'.$this->organization->slug)
        ->assertOk()
        ->assertSee(__('reviewer.switch.to_reviewer'));
});
