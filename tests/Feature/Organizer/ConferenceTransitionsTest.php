<?php

declare(strict_types=1);

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Pages\CreateConference;
use App\Filament\Organizer\Resources\Conferences\Pages\ListConferences;
use App\Filament\Organizer\Resources\Conferences\Pages\ViewConference;
use App\Filament\Organizer\Resources\Conferences\Tables\ConferenceStatusActions;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\User;
use Filament\Notifications\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->owner = User::factory()->create();
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
    actingAs($this->owner);
    bootOrganizerPanel($this->organization);
});

function readyConference(Organization $organization): Conference
{
    $conference = Conference::factory()->for($organization)->withSubmissionWindow()->create();
    app(CreateDefaultReviewForm::class)->handle($conference);

    return $conference->fresh() ?? $conference;
}

it('publishes a ready conference from the view page and shows the short link', function () {
    $conference = readyConference($this->organization);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->callAction('publish')
        ->assertNotified();

    $conference->refresh();
    expect($conference->status)->toBe(ConferenceStatus::Open)
        ->and($conference->published_at)->not->toBeNull()
        ->and($conference->shortLink)->not->toBeNull();
});

it('opens the pages behind the URLs Filament generates', function () {
    $conference = readyConference($this->organization);

    foreach (['view', 'edit'] as $page) {
        get(ConferenceResource::getUrl($page, ['record' => $conference]))->assertOk();
    }
});

it('refuses to publish and names every blocker', function () {
    $conference = Conference::factory()->for($this->organization)->create();

    // The full notification, not just its title: assertNotified('...') compares
    // the title alone, so dropping ->body() would go unnoticed.
    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->callAction('publish')
        ->assertNotified(
            Notification::make()
                ->danger()
                ->title('This conference is not ready to publish')
                ->body('Set both a submission opening date and a submission deadline. The review form has no questions yet. Add at least one before publishing.')
                ->persistent()
        );

    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Draft);
});

it('escapes the conference name in the publish notification', function () {
    // Filament renders a notification title as sanitised HTML whose shared
    // config keeps `style` on every element, so an unescaped name could paint a
    // full-viewport phishing link over the panel of whoever clicks Publish.
    $conference = readyConference($this->organization);
    $conference->forceFill(['name' => '<a href="https://evil.example" style="position:fixed;inset:0">Sign in</a>'])->save();

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->callAction('publish')
        ->assertNotified(e($conference->name).' is live');
});

it('refuses to publish while the organization is only pending', function () {
    $pending = Organization::factory()->create();
    $owner = User::factory()->create();
    $pending->addMember($owner, OrganizationRole::Owner);

    // Switch tenant *before* building the fixture: Filament's tenancy
    // `creating` observer re-parents any conference created while another
    // tenant is current (fact 5), which would silently make this an approved
    // organization's conference and the assertions meaningless.
    actingAs($owner);
    bootOrganizerPanel($pending);
    $conference = readyConference($pending);

    expect($conference->organization_id)->toBe($pending->id);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->callAction('publish')
        ->assertNotified('This conference is not ready to publish');

    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Draft);
});

it('closes submissions and offers to reopen afterwards', function () {
    $conference = readyConference($this->organization);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->callAction('publish')
        ->assertActionHidden('publish')
        ->assertActionVisible('close')
        ->callAction('close')
        ->assertNotified();

    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Closed);

    livewire(ViewConference::class, ['record' => $conference->fresh()?->getRouteKey()])
        ->assertActionVisible('publish')
        ->assertActionHidden('close');
});

it('drops the publishing checklist once the conference is live', function () {
    // blockers() also reports why a status cannot move to Open, so a live or
    // archived conference has a non-empty list - the red "Publishing checklist"
    // section must not appear on those.
    $conference = readyConference($this->organization);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->assertDontSee('Publishing checklist')
        ->callAction('publish')
        ->assertDontSee('Publishing checklist');

    livewire(ViewConference::class, ['record' => $conference->fresh()?->getRouteKey()])
        ->assertDontSee('Publishing checklist');
});

it('archives a conference and stops offering any further transition', function () {
    $conference = readyConference($this->organization);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->callAction('archive')
        ->assertNotified();

    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Archived);

    livewire(ViewConference::class, ['record' => $conference->fresh()?->getRouteKey()])
        ->assertActionHidden('publish')
        ->assertActionHidden('close')
        ->assertActionHidden('archive');
});

it('hides archive from a plain member but keeps publish', function () {
    $conference = readyConference($this->organization);
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    actingAs($member);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->assertActionVisible('publish')
        ->assertActionHidden('archive');
});

it('stores a deadline typed in the conference timezone as utc and shows it back locally', function () {
    // Spec section 10: timestamps are stored UTC and every conference has its
    // own timezone. The form converts on save; the view page and the table have
    // to convert back, or a Riyadh deadline reads three hours early next to the
    // "Timezone: Asia/Riyadh" line beside it.
    $local = now('Asia/Riyadh')->addMonths(2)->setTime(0, 0);

    livewire(CreateConference::class)
        ->fillForm([
            'name' => 'Timezone Meeting',
            'timezone' => 'Asia/Riyadh',
            'submission_opens_at' => $local->copy()->subMonth()->format('Y-m-d H:i:s'),
            'submission_deadline' => $local->format('Y-m-d H:i:s'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $conference = Conference::query()->where('name', 'Timezone Meeting')->firstOrFail();

    expect($conference->getRawOriginal('submission_deadline'))
        ->toBe($local->copy()->utc()->format('Y-m-d H:i:s'));

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->assertSee($local->format('j M Y, H:i'));

    livewire(ListConferences::class)->assertSee($local->format('j M Y, H:i'));
});

it('publishes from the list table too', function () {
    $conference = readyConference($this->organization);

    livewire(ListConferences::class)
        ->callTableAction('publish', $conference)
        ->assertNotified();

    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Open);
});

it('shows the publishing checklist on the view page of a draft', function () {
    $conference = Conference::factory()->for($this->organization)->create();

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->assertSee('Publishing checklist')
        ->assertSee('Set both a submission opening date and a submission deadline.')
        ->assertSee('The review form has no questions yet. Add at least one before publishing.');
});

it('does not open the view page for another organization conference', function () {
    $theirs = withoutTenant(fn () => Conference::factory()->create());

    $this->get(ConferenceResource::getUrl(
        'view', ['record' => $theirs->getRouteKey()], tenant: $this->organization
    ))->assertNotFound();
});

it('asks a reopening question when publish is a reopen rather than a go-live', function () {
    // The button relabels itself once published_at is set, so the confirmation
    // it opens must not still promise that "the public page goes live" - the
    // page has been live for weeks and the organizer is only reopening
    // submissions on it.
    $conference = readyConference($this->organization);

    $first = ConferenceStatusActions::publish()->record($conference);

    expect($first->getLabel())->toBe('Publish')
        ->and($first->getModalHeading())->toBe('Publish this conference?')
        ->and((string) $first->getModalDescription())->toContain('The public page goes live');

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])->callAction('publish');
    livewire(ViewConference::class, ['record' => $conference->fresh()?->getRouteKey()])->callAction('close');

    $closed = $conference->fresh() ?? $conference;
    $again = ConferenceStatusActions::publish()->record($closed);

    expect($closed->published_at)->not->toBeNull()
        ->and($again->getLabel())->toBe('Reopen submissions')
        ->and($again->getModalHeading())->toBe('Reopen submissions?')
        ->and((string) $again->getModalDescription())->not->toContain('The public page goes live')
        ->and((string) $again->getModalDescription())->toContain('Authors can submit again');

    livewire(ViewConference::class, ['record' => $closed->getRouteKey()])
        ->assertActionHasLabel('publish', 'Reopen submissions');
});
