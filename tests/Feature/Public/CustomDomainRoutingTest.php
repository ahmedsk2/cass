<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Http\Middleware\RequireCustomDomain;
use App\Models\Conference;
use App\Models\Organization;
use App\Support\Domains\CustomDomains;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

use function Pest\Laravel\get;

beforeEach(function () {
    config()->set('app.url', 'https://cass.towardpcc.com');
    CustomDomains::forget();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->organization->forceFill([
        'custom_domain' => 'abstracts.example.org',
        'custom_domain_token' => str_repeat('a', 64),
        'custom_domain_verified_at' => now(),
    ])->save();

    $this->conference = Conference::factory()->for($this->organization)->create([
        'name' => 'Alpha Annual Meeting',
        'slug' => 'annual-meeting',
        'status' => ConferenceStatus::Open,
        'submission_opens_at' => now()->subWeek(),
        'submission_deadline' => now()->addWeek(),
    ]);

    CustomDomains::forget();
});

/**
 * Every request in this file is made with an explicit Host header, because
 * that is the only thing that distinguishes the two route sets. `get()` with a
 * full URL sets it.
 *
 * https, not http: a verified custom domain is served over TLS (that is what
 * the Coolify/Traefik step in the runbook is for), and the scheme is not
 * cosmetic here. UrlGenerator::formatScheme() takes the scheme from the
 * CURRENT request even when a root has been forced, so an http request would
 * make the platform links this file asserts come out as
 * http://cass.towardpcc.com/... - a difference that says nothing about the
 * middleware under test.
 */
function onDomain(string $path, string $host = 'abstracts.example.org'): TestResponse
{
    return get('https://'.$host.$path);
}

it('serves the conference page at the root of a verified domain', function () {
    onDomain('/annual-meeting')
        ->assertOk()
        ->assertSee('Alpha Annual Meeting')
        ->assertSee('Alpha Society');
});

it('serves the submit page under the conference slug', function () {
    onDomain('/annual-meeting/submit')->assertOk();
});

it('redirects the root to the one public conference', function () {
    onDomain('/')->assertRedirect('https://abstracts.example.org/annual-meeting');
});

it('404s the root when there is nothing, or more than one thing, to redirect to', function () {
    Conference::factory()->for($this->organization)->create([
        'slug' => 'second-meeting',
        'status' => ConferenceStatus::Open,
    ]);

    // Two open conferences: picking one would silently send visitors to the
    // wrong meeting in exactly the week a society runs two calls at once.
    onDomain('/')->assertNotFound();

    Conference::query()->update(['status' => ConferenceStatus::Draft]);

    onDomain('/')->assertNotFound();
});

it('never serves another organization conference from this domain', function () {
    $theirs = Conference::factory()->create(['slug' => 'their-meeting', 'status' => ConferenceStatus::Open]);

    expect($theirs->organization->is($this->organization))->toBeFalse();

    // The route parameter is a plain string and RequireCustomDomain looks it
    // up through $organization->conferences(), so there is no query in the
    // application that could return this row for this host.
    onDomain('/their-meeting')->assertNotFound();
    onDomain('/their-meeting/submit')->assertNotFound();
});

it('404s every platform page on a custom domain', function (string $path) {
    onDomain($path)->assertNotFound();
})->with([
    '/about', '/privacy', '/terms', '/contact', '/register',
    '/c/'.'alpha-society/annual-meeting',
    '/q/abcd1234',
    '/s/'.str_repeat('a', 64),
    '/invite/'.str_repeat('a', 64),
    '/files/01ARZ3NDEKTSV4RRFFQ69G5FAV',
    '/org', '/admin', '/review',
    // Percent-encoded first characters. Laravel's UriValidator matches
    // rawurldecode($path), so these route exactly like the plain forms above -
    // a middleware that reads Request::path() instead of decodedPath() lets
    // every one of them through and serves the platform's registration form on
    // somebody else's domain.
    '/%72egister', '/%61bout', '/%6frg', '/%61dmin', '/%73/'.str_repeat('a', 64),
]);

it('keeps livewire and the health check reachable on a custom domain', function () {
    // The submission form posts here. A middleware that forgets this prefix
    // makes the submit page a form nobody can submit.
    onDomain('/up')->assertOk();

    // Livewire 4.4.4 derives its endpoint prefix from APP_KEY
    // (EndpointResolver::prefix() -> /livewire-<8 hex>), so the literal
    // '/livewire/update' is not a route at all and would answer 404. Ask the
    // framework for the path rather than spelling it.
    $update = EndpointResolver::updatePath();

    expect($update)->toStartWith('/livewire-');

    // Not a POST - the route exists and is what matters; a GET of a POST-only
    // route is a 405, which proves routing reached it rather than the
    // middleware's 404.
    onDomain($update)->assertStatus(405);
});

it('refuses a host that is not a verified domain', function () {
    // TrustHosts answers 400 before this middleware in production; in the test
    // harness TrustHosts is not in the stack, so this asserts the middleware's
    // own answer, which is the defence in depth.
    onDomain('/annual-meeting', 'not-ours.example.net')->assertNotFound();
});

it('leaves every platform route working on the platform host', function () {
    get('https://cass.towardpcc.com/about')->assertOk();
    get('https://cass.towardpcc.com/c/'.$this->organization->slug.'/annual-meeting')->assertOk();
    // The root-level conference route must not answer on the platform host: a
    // path that happens to look like a slug is a 404, not somebody's
    // conference.
    get('https://cass.towardpcc.com/annual-meeting')->assertNotFound();
});

it('prints the custom domain as the canonical url once verified', function () {
    expect($this->conference->fresh()?->publicUrl())->toBe('https://abstracts.example.org/annual-meeting')
        ->and($this->conference->fresh()?->publicSubmitUrl())->toBe('https://abstracts.example.org/annual-meeting/submit');

    onDomain('/annual-meeting')
        ->assertSee('<link rel="canonical" href="https://abstracts.example.org/annual-meeting">', escape: false)
        ->assertSee('https://abstracts.example.org/annual-meeting/submit', escape: false);
});

it('falls back to the platform url when the domain is claimed but not verified', function () {
    $this->organization->forceFill(['custom_domain_verified_at' => null])->save();
    CustomDomains::forget();

    // The shape, not the absolute host: this call is made outside any request,
    // where route() builds on the root the harness bootstrapped from .env
    // before beforeEach could set config('app.url') (SetRequestForConsole). The
    // claim under test is that an UNVERIFIED domain never appears in the
    // canonical URL and the platform's /c/{org}/{slug} is used instead.
    expect($this->conference->fresh()?->publicUrl())
        ->toEndWith('/c/'.$this->organization->slug.'/annual-meeting')
        ->and($this->conference->fresh()?->publicUrl())->not->toContain('abstracts.example.org');

    onDomain('/annual-meeting')->assertNotFound();
});

it('substitutes the two models in the order the controller declares them', function () {
    Route::middleware([RequireCustomDomain::class])->get('/{conference}/probe-order', function (Organization $organization, Conference $conference) {
        return response($organization->slug.'|'.$conference->slug);
    })->where('conference', '[a-z0-9-]+');

    // ControllerDispatcher calls the method with array_values($parameters), so
    // a middleware that APPENDS `organization` after the URI's `conference`
    // calls show(Conference, Organization) - a TypeError on every conference
    // page on every custom domain.
    onDomain('/annual-meeting/probe-order')
        ->assertOk()
        ->assertSee($this->organization->slug.'|annual-meeting');
});

it('mints status, file and invitation urls on the platform host even when the page is served on a custom domain', function () {
    $seen = null;

    Route::middleware([RequireCustomDomain::class])->get('/{conference}/probe-urls', function () use (&$seen) {
        $seen = route('submission.status', ['token' => str_repeat('a', 64)]);

        return response('ok');
    })->where('conference', '[a-z0-9-]+');

    onDomain('/annual-meeting/probe-urls')->assertOk();

    // Without URL::forceRootUrl() this is https://abstracts.example.org/s/...:
    // a path the RESERVED list 404s, carrying a 64-character bearer token, on a
    // host whose DNS the organizer can repoint tomorrow.
    expect($seen)->toStartWith('https://cass.towardpcc.com/s/');
});

it('links privacy, terms and contact at the platform host from a custom domain', function () {
    $platform = rtrim((string) config('app.url'), '/');

    // The footer's three links are route('contact'|'privacy'|'terms'), and the
    // forceRootUrl() pin is the only reason they are not dead links to the
    // reserved 404s on this host.
    onDomain('/annual-meeting')
        ->assertSee($platform.'/privacy', escape: false)
        ->assertSee($platform.'/terms', escape: false)
        ->assertSee($platform.'/contact', escape: false);
});

it('keeps the submit page\'s own links on the custom domain', function () {
    $this->conference->forceFill(['submission_deadline' => now()->subDay()])->save();

    // The closed-window back-link renders only when the window is shut. Every
    // route('conference.show') left in SubmissionForm or its view sends an
    // author from the organizer's domain to cass.towardpcc.com mid-flow.
    onDomain('/annual-meeting/submit')
        ->assertSee('https://abstracts.example.org/annual-meeting', escape: false)
        ->assertDontSee('cass.towardpcc.com/c/', escape: false);
});
