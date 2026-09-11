<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceShortLink;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\ShortLink;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Member);
    $this->conference = Conference::factory()->for($this->organization)->published()->create();
    ShortLink::forTarget($this->conference);
    $this->conference->refresh();
});

dataset('conference asset routes', [
    'qr svg' => ['conference-assets.qr.svg', []],
    'qr png' => ['conference-assets.qr.png', []],
    'poster a4' => ['conference-assets.poster', ['size' => 'a4']],
    'poster a3' => ['conference-assets.poster', ['size' => 'a3']],
]);

it('sends guests on every asset route to the organizer login', function (string $name, array $extra) {
    get(route($name, ['conference' => $this->conference, ...$extra]))
        ->assertRedirect('/org/login');
})->with('conference asset routes');

it('refuses every asset route to a member of another organization', function (string $name, array $extra) {
    // Each controller action calls authorizeDownload() separately, so testing
    // only the SVG would let a missing Gate call on the PNG or the poster ship.
    $outsider = User::factory()->create();
    Organization::factory()->approved()->create()->addMember($outsider, OrganizationRole::Owner);

    actingAs($outsider)->get(route($name, ['conference' => $this->conference, ...$extra]))
        ->assertForbidden();
})->with('conference asset routes');

it('sends an unverified member to the email verification prompt', function () {
    // The organizer panel runs Filament's EnsureEmailIsVerified on every tenant
    // route; these downloads live outside the panel and repeat it.
    $unverified = User::factory()->unverified()->create();
    $this->organization->addMember($unverified, OrganizationRole::Member);

    actingAs($unverified)->get(route('conference-assets.qr.svg', $this->conference))
        ->assertRedirect('/org/email-verification/prompt');
});

it('rate limits asset downloads per account', function () {
    // Every poster is a synchronous dompdf render on the four-worker php-fpm
    // pool that serves every tenant, so one member must not be able to occupy
    // it. Ten cheap SVG requests spend the budget without rendering a PDF.
    actingAs($this->user);

    foreach (range(1, 10) as $ignored) {
        get(route('conference-assets.qr.svg', $this->conference))->assertOk();
    }

    get(route('conference-assets.poster', ['conference' => $this->conference, 'size' => 'a4']))
        ->assertStatus(429);
});

it('serves the svg, the png and both poster sizes to a member', function () {
    actingAs($this->user);

    get(route('conference-assets.qr.svg', $this->conference))
        ->assertOk()
        ->assertHeader('content-type', 'image/svg+xml');

    get(route('conference-assets.qr.png', $this->conference))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');

    foreach (['a4', 'a3'] as $size) {
        get(route('conference-assets.poster', ['conference' => $this->conference, 'size' => $size]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
});

it('offers the files as downloads named after the conference', function () {
    actingAs($this->user);

    get(route('conference-assets.qr.png', $this->conference))
        ->assertDownload($this->conference->slug.'-qr.png');
});

it('returns 404 before the conference is published and has a short link', function () {
    $draft = Conference::factory()->for($this->organization)->create();

    actingAs($this->user)->get(route('conference-assets.qr.svg', $draft))->assertNotFound();
});

it('rejects a poster size that is not a4 or a3', function () {
    actingAs($this->user);

    get('/conference-assets/'.$this->conference->ulid.'/poster/a4')->assertOk();
    get('/conference-assets/'.$this->conference->ulid.'/poster/a5')->assertNotFound();
});

it('shows total scans and a thirty day breakdown on the sharing page', function () {
    // A bare assertSee('2') matches almost anything on a Filament page (icon
    // path data, dates, Livewire ids), so assert the rendered markup instead.
    $this->travelTo(now()->setTime(12, 0));
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $link = $this->conference->shortLink;
    $link->visits()->create(['visited_at' => now()]);
    $link->visits()->create(['visited_at' => now()->subDays(3)]);
    $link->forceFill(['clicks' => 4817])->save();

    livewire(ConferenceShortLink::class, ['record' => $this->conference->getRouteKey()])
        ->assertSee($link->code)
        ->assertSee('Total scans')
        ->assertSee('4,817')
        ->assertSee('Last 30 days')
        ->assertSeeHtml('title="'.now()->toDateString().': 1"')
        ->assertSeeHtml('title="'.now()->subDays(3)->toDateString().': 1"')
        ->assertSeeHtml('title="'.now()->subDay()->toDateString().': 0"');
});

it('opens the sharing page through the URL Filament generates', function () {
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    get(ConferenceResource::getUrl('short-link', ['record' => $this->conference]))->assertOk();
});

it('does not open the sharing page for another organization conference', function () {
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $theirs = withoutTenant(fn () => Conference::factory()->published()->create());

    $this->get(ConferenceResource::getUrl(
        'short-link', ['record' => $theirs->getRouteKey()], tenant: $this->organization
    ))->assertNotFound();
});
