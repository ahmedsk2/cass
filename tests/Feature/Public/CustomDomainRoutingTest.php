<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Http\Middleware\RequireCustomDomain;
use App\Http\Middleware\ResolveCustomDomain;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Support\Domains\CustomDomains;
use App\Support\Domains\PlatformUrl;
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
    // Registered first segments nobody typed into this list when it was
    // written: Filament's export/import download routes and the local-disk
    // serve route. Both match the slug allow-list, so without them in RESERVED
    // they answer on every verified custom domain.
    '/filament/exports/1/download',
    '/storage/branding/logo.png',
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
    $seen = [];

    Route::middleware([RequireCustomDomain::class])->get('/{conference}/probe-urls', function () use (&$seen) {
        // The three shapes the application actually mints on a custom-domain
        // page. All of them go through PlatformUrl, because the URL root is
        // deliberately not forced any more (that broke Livewire's own POST).
        $seen['status'] = Submission::factory()->make()->statusUrl(str_repeat('a', 64));
        $seen['invite'] = PlatformUrl::route('invitation.accept', ['token' => str_repeat('a', 64)]);
        $seen['contact'] = PlatformUrl::route('contact');

        return response('ok');
    })->where('conference', '[a-z0-9-]+');

    onDomain('/annual-meeting/probe-urls')->assertOk();

    // A request-relative URL here is https://abstracts.example.org/s/...: a
    // path the RESERVED list 404s, carrying a 64-character bearer token, on a
    // host whose DNS the organizer can repoint tomorrow.
    expect($seen['status'])->toStartWith('https://cass.towardpcc.com/s/')
        ->and($seen['invite'])->toStartWith('https://cass.towardpcc.com/invite/')
        ->and($seen['contact'])->toBe('https://cass.towardpcc.com/contact');
});

it('links privacy, terms and contact at the platform host from a custom domain', function () {
    $platform = rtrim((string) config('app.url'), '/');

    // The footer's three links are reserved 404s on this host, so they are
    // minted through PlatformUrl rather than through the request root.
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

it('allows the branding origin in the image policy, because the logo is not same-origin here', function () {
    $platform = rtrim((string) config('app.url'), '/');

    // config/filesystems.php:55 builds the branding disk's url from
    // env('APP_URL') at boot, which the harness read as http://localhost long
    // before this file's beforeEach could move config('app.url'). In production
    // the two are one string; pin them together here so this case is about the
    // policy rather than about the harness's boot order.
    config()->set('filesystems.disks.branding.url', $platform.'/storage/branding');

    $this->organization->forceFill(['logo_path' => 'logo.png'])->save();

    $response = onDomain('/annual-meeting')->assertOk();

    // config/filesystems.php:55 builds the branding disk's url from APP_URL, so
    // on this host the logo and the og:image are cross-origin and img-src
    // 'self' alone would blank both - the first thing an organizer would see on
    // the domain they just verified.
    expect((string) $response->headers->get('Content-Security-Policy'))->toContain('img-src')
        ->and((string) $response->headers->get('Content-Security-Policy'))->toContain($platform)
        ->and($response->getContent())->toContain($platform.'/storage/branding/logo.png');
});

it('still sends a policy on the 404 a reserved path produces', function () {
    // ContentSecurityPolicy is appended BEFORE ResolveCustomDomain for exactly
    // this: Illuminate\Routing\Pipeline catches the abort() at the pipe that
    // threw and returns the rendered response upward, so anything appended
    // after it never runs for a refused host or a reserved path - and those
    // 404s are documents a browser renders, with @vite tags in them.
    $response = onDomain('/register')->assertNotFound();

    expect((string) $response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'");
});

it('posts livewire updates back to the custom domain rather than to the platform host', function () {
    // FrontendAssets builds both endpoints with url() (FrontendAssets.php:221
    // and :242), so anything that forces the URL root moves Livewire's POST to
    // the platform host - where it is cross-origin under this branch's own
    // connect-src 'self', carries no SameSite=lax host-only session cookie and
    // has no CORS middleware to answer the preflight. The submit page would
    // render and never save.
    $content = (string) onDomain('/annual-meeting/submit')->assertOk()->getContent();

    expect($content)->toContain('data-update-uri="https://abstracts.example.org/livewire-')
        ->and($content)->not->toContain('data-update-uri="https://cass.towardpcc.com')
        ->and($content)->not->toContain('"uri":"https:\/\/cass.towardpcc.com\/livewire-');
});

it('reserves every registered platform first segment, including ones nobody typed into the list', function () {
    // Derived from the route table rather than hand-written, because the
    // dataset above cannot notice a segment a later package registers. Read
    // the constant by reflection so the production class keeps it private.
    /** @var list<string> $reserved */
    $reserved = (new ReflectionClassConstant(ResolveCustomDomain::class, 'RESERVED'))->getValue();

    $segments = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => strtolower(explode('/', trim((string) $route->uri(), '/'))[0]))
        ->unique()
        ->reject(fn (string $segment): bool => $segment === ''
            // Deliberately allowed on a custom domain, and argued in the
            // middleware's own docblock: the health check must answer
            // everywhere and Livewire's prefix is derived from APP_KEY.
            || $segment === 'up'
            || str_starts_with($segment, 'livewire-')
            // The catch-all conference route itself.
            || str_starts_with($segment, '{'))
        ->values();

    expect($segments)->not->toBeEmpty()
        ->and($segments->diff($reserved)->values()->all())->toBe([]);
});

it('starts no session for a slug-shaped 404 on the platform host', function () {
    // GET /{conference} is registered inside the `web` group, so before this
    // ordering a scanner asking for /wp-admin, /backup or /wordpress ran
    // EncryptCookies, StartSession and ValidateCsrfToken before
    // RequireCustomDomain aborted - one row in the database-backed sessions
    // table, and one cookie, per unmatched scan. RequireCustomDomain reads only
    // request attributes and route parameters, so it is safe ahead of the
    // session.
    $response = get('https://cass.towardpcc.com/backup')->assertNotFound();

    $cookies = collect($response->headers->getCookies())
        ->map(fn ($cookie): string => $cookie->getName())
        ->all();

    expect($cookies)->not->toContain((string) config('session.cookie'));
});
