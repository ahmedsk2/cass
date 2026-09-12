<?php

declare(strict_types=1);

use App\Contracts\DnsResolver;
use App\Enums\OrganizationRole;
use App\Filament\Organizer\Pages\Tenancy\EditOrganizationProfile;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CustomDomainVerified;
use App\Support\Domains\FakeDnsResolver;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/**
 * The three domain actions live inside the form schema, not on the page, and
 * Filament 5.8 resolves a bare action name only against the Livewire
 * component's own actions (InteractsWithActions::resolveAction). An action
 * embedded in a schema needs that schema component's key as context - the
 * fallback the plan's Step 1 names, confirmed against this installation:
 * `$schema->getAction('claimCustomDomain', 'custom_domain_actions')` finds it
 * and `callAction('claimCustomDomain')` does not.
 */
function domainAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('custom_domain_actions');
}

beforeEach(function () {
    config()->set('app.url', 'https://cass.towardpcc.com');
    config()->set('cass.domains.cname_target', 'cass.towardpcc.com');

    Notification::fake();

    $this->dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $this->dns);

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->owner = User::factory()->create();
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
    actingAs($this->owner);
    bootOrganizerPanel($this->organization);
});

it('shows the section with nothing claimed', function () {
    livewire(EditOrganizationProfile::class)
        ->assertSee(__('domain.section.heading'))
        ->assertSee(__('domain.state.none'))
        ->assertActionExists(domainAction('claimCustomDomain'))
        // Nothing to verify and nothing to remove until a domain is saved:
        // an action that is visible and refuses is a worse screen than one
        // that is not there.
        //
        // assertActionDoesNotExist, not assertActionHidden: Schema::getAction()
        // walks getComponents(withHidden: false), so a hidden action inside a
        // schema does not resolve at all and assertActionHidden raises
        // ActionNotResolvableException instead of asserting. Not resolving is
        // the stronger statement anyway - it is what the mountAction endpoint
        // answers to a client that calls these two by name right now.
        ->assertActionDoesNotExist(domainAction('verifyCustomDomain'))
        ->assertActionDoesNotExist(domainAction('releaseCustomDomain'))
        ->assertDontSee(__('domain.actions.verify'))
        ->assertDontSee(__('domain.actions.release'));
});

it('saves a domain and then prints the two records', function () {
    livewire(EditOrganizationProfile::class)
        ->callAction(domainAction('claimCustomDomain'), ['domain' => ' Abstracts.Example.ORG '])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $this->organization->refresh();

    expect($this->organization->custom_domain)->toBe('abstracts.example.org')
        ->and($this->organization->custom_domain_token)->toHaveLength(64);

    livewire(EditOrganizationProfile::class)
        ->assertSee(__('domain.state.pending'))
        ->assertSee('_cass-verify.abstracts.example.org')
        // The token itself is on the page, on purpose: it is published in
        // public DNS and the organizer has to copy it. Task 1's decision 1.
        ->assertSee((string) $this->organization->custom_domain_token)
        ->assertSee('cass.towardpcc.com')
        ->assertActionExists(domainAction('verifyCustomDomain'))
        ->assertActionExists(domainAction('releaseCustomDomain'));
});

it('refuses a domain that is not a domain, without touching the row', function () {
    livewire(EditOrganizationProfile::class)
        ->callAction(domainAction('claimCustomDomain'), ['domain' => 'localhost'])
        ->assertHasActionErrors(['domain']);

    expect($this->organization->fresh()?->custom_domain)->toBeNull();
});

it('refuses the platform host', function () {
    livewire(EditOrganizationProfile::class)
        ->callAction(domainAction('claimCustomDomain'), ['domain' => 'org.cass.towardpcc.com'])
        ->assertHasActionErrors(['domain']);
});

it('refuses a domain another organization already holds', function () {
    $other = withoutTenant(fn (): Organization => Organization::factory()->approved()->create());
    $other->forceFill(['custom_domain' => 'abstracts.example.org', 'custom_domain_token' => str_repeat('a', 64)])->save();

    livewire(EditOrganizationProfile::class)
        ->callAction(domainAction('claimCustomDomain'), ['domain' => 'abstracts.example.org'])
        ->assertHasActionErrors(['domain']);

    expect($this->organization->fresh()?->custom_domain)->toBeNull();
});

it('verifies, tells the organizer the platform team has been emailed, and logs it', function () {
    livewire(EditOrganizationProfile::class)->callAction(domainAction('claimCustomDomain'), ['domain' => 'abstracts.example.org']);
    $this->organization->refresh();
    $this->dns->set('_cass-verify.abstracts.example.org', [(string) $this->organization->custom_domain_token]);

    $admin = User::factory()->platformAdmin()->create();

    livewire(EditOrganizationProfile::class)
        ->callAction(domainAction('verifyCustomDomain'))
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($this->organization->fresh()?->hasVerifiedCustomDomain())->toBeTrue();

    Notification::assertSentTo($admin, CustomDomainVerified::class);

    livewire(EditOrganizationProfile::class)->assertSee(__('domain.state.verified'));
});

it('keeps the organizer on the page and says what is wrong when the record is missing', function () {
    // BEFORE the call, not after: NotificationFake::assertNotSentTo() filters
    // by the notifiable's key, so a platform admin created on the assertion
    // line could never have been sent anything and the assertion could never
    // fail.
    User::factory()->platformAdmin()->create();

    livewire(EditOrganizationProfile::class)->callAction(domainAction('claimCustomDomain'), ['domain' => 'abstracts.example.org']);

    // No record set on the fake at all: the same answer an organizer gets two
    // minutes after adding one, before it has propagated.
    livewire(EditOrganizationProfile::class)
        ->callAction(domainAction('verifyCustomDomain'))
        // The exact sentence, not a bare assertNotified() - which passes for
        // the SUCCESS notification too and would leave the whole failure path
        // pinned by the verified_at null check alone.
        ->assertNotified(FilamentNotification::make()->danger()->title(
            __('domain.errors.no_record', ['name' => '_cass-verify.abstracts.example.org'])
        ));

    expect($this->organization->fresh()?->custom_domain_verified_at)->toBeNull();
    Notification::assertNothingSent();
});

it('tells a throttled organizer they clicked too fast, not that DNS is down', function () {
    config()->set('cass.domains.verify_rate_limit', 1);

    // The throttle notification embeds a countdown, and RateLimiter::availableIn()
    // recomputes it from the wall clock every time it is asked
    // (`timer - currentTime()`). The verify action baked the number into the
    // sentence when it ran; an expectation that called availableIn() again at
    // assertion time asked a clock that had moved on. One tick over a second
    // boundary between the two - ~1 run in 60, and reliably under a loaded CI
    // runner - and the page said "60 seconds" while the test demanded "59".
    // That is what reddened the Dependabot bump and went green on re-run.
    //
    // Stopping the clock removes the race at its source. No unwind needed:
    // Laravel clears the test-now in tearDownTheTestEnvironment(), which runs
    // even when the test fails.
    $this->freezeTime();

    livewire(EditOrganizationProfile::class)->callAction(domainAction('claimCustomDomain'), ['domain' => 'abstracts.example.org']);

    $page = livewire(EditOrganizationProfile::class);
    $page->callAction(domainAction('verifyCustomDomain'));

    // domain.errors.lookup_failed is "We could not reach the DNS servers for
    // that domain just now", which sends an organizer off to edit a record
    // that is already correct.
    $page->callAction(domainAction('verifyCustomDomain'))
        ->assertNotified(FilamentNotification::make()->warning()
            ->title(__('domain.errors.throttled_title'))
            // The literal 60 - the decay verifyAction() hands RateLimiter::hit()
            // - and deliberately not availableIn() again. An expectation built
            // from the same call the component made agrees with whatever the
            // component says, including a wrong number; it could only ever
            // check that the sentence template was used. Naming the number
            // pins the sentence an organizer actually reads.
            ->body(__('domain.errors.throttled', ['seconds' => 60])));
});

it('stops an organizer who clicks verify in a loop', function () {
    config()->set('cass.domains.verify_rate_limit', 2);
    livewire(EditOrganizationProfile::class)->callAction(domainAction('claimCustomDomain'), ['domain' => 'abstracts.example.org']);

    $page = livewire(EditOrganizationProfile::class);

    $page->callAction(domainAction('verifyCustomDomain'));
    $page->callAction(domainAction('verifyCustomDomain'));
    $page->callAction(domainAction('verifyCustomDomain'));

    // Three clicks, two lookups: the third is refused by the limiter before
    // the resolver is asked. Asserting the resolver's own log rather than a
    // notification body, because the notification is a sentence and this is a
    // count.
    expect($this->dns->queriedNames())->toHaveCount(2);
});

it('removes a domain and frees the name', function () {
    livewire(EditOrganizationProfile::class)->callAction(domainAction('claimCustomDomain'), ['domain' => 'abstracts.example.org']);

    livewire(EditOrganizationProfile::class)
        ->callAction(domainAction('releaseCustomDomain'))
        ->assertNotified();

    $this->organization->refresh();

    expect($this->organization->custom_domain)->toBeNull()
        ->and($this->organization->custom_domain_token)->toBeNull()
        ->and($this->organization->custom_domain_verified_at)->toBeNull();
});

it('hides every domain action from a plain member, and the page with it', function () {
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);

    // Filament 5.8 resolves a false canView() on a tenant profile page into a
    // 404, not a 403 - the behaviour tests/Feature/Organizer/OrganizationProfileTest.php
    // already pins.
    actingAs($member)->get('/org/'.$this->organization->slug.'/profile')->assertNotFound();
});

it('is unreachable from another organization', function () {
    $outsider = User::factory()->create();
    $other = withoutTenant(fn (): Organization => Organization::factory()->approved()->create());
    $other->addMember($outsider, OrganizationRole::Owner);

    actingAs($outsider)->get('/org/'.$this->organization->slug.'/profile')->assertNotFound();
});
