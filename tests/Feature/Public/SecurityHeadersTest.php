<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/** Every directive this application's policy must carry, and the reason each is here. */
function policyOf(TestResponse $response): string
{
    return (string) $response->headers->get('Content-Security-Policy');
}

it('sets a nonce policy on every html page, public and panel', function (string $url) {
    $policy = policyOf(get($url)->assertOk());

    expect($policy)->toContain("default-src 'self'")
        ->and($policy)->toContain("object-src 'none'")
        ->and($policy)->toContain("base-uri 'self'")
        ->and($policy)->toContain("frame-ancestors 'none'")
        ->and($policy)->toContain("form-action 'self'")
        // A fresh 40-character nonce, in both directives.
        ->and($policy)->toMatch("/script-src [^;]*'nonce-[A-Za-z0-9]{40}'/")
        ->and($policy)->toMatch("/style-src [^;]*'nonce-[A-Za-z0-9]{40}'/")
        // Task 7 decision 4: a nonce never reaches a style ATTRIBUTE, and this
        // application has about forty-five of them - including the
        // organization's branding variables and the brand lock-up on all three
        // panels.
        ->and($policy)->toContain("style-src-attr 'unsafe-inline'")
        // Task 7 decision 3: Alpine's evaluator is new Function(), Livewire
        // bundles Alpine, and Filament's views are written in a dialect the
        // CSP-safe build cannot parse.
        ->and($policy)->toContain("'unsafe-eval'")
        ->and($policy)->toContain('https://challenges.cloudflare.com')
        ->and($policy)->not->toContain("script-src 'self' 'unsafe-inline'");
})->with(['/', '/about', '/org/login', '/admin/login', '/review/login']);

it('mints a different nonce per request, and puts the same one in every tag', function () {
    $first = get('/');
    $second = get('/');

    preg_match("/'nonce-([A-Za-z0-9]{40})'/", policyOf($first), $one);
    preg_match("/'nonce-([A-Za-z0-9]{40})'/", policyOf($second), $two);

    expect($one[1] ?? 'a')->not->toBe($two[1] ?? 'b');

    // Laravel's Vite tags carry it, which is the half of fact 2 this app owns.
    $first->assertSee('nonce="'.$one[1].'"', escape: false);
});

it('nonces the livewire script and its config on a page that uses livewire', function () {
    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->create([
        'status' => ConferenceStatus::Open,
        'submission_opens_at' => now()->subWeek(),
        'submission_deadline' => now()->addWeek(),
    ]);

    $response = get('/c/'.$organization->slug.'/'.$conference->slug.'/submit')->assertOk();

    preg_match("/'nonce-([A-Za-z0-9]{40})'/", policyOf($response), $matches);
    $nonce = $matches[1] ?? '';

    expect($nonce)->not->toBe('');

    // FrontendAssets::nonce() reads Vite::cspNonce(), so ONE call in the
    // middleware covers Laravel's @vite tags and Livewire's own script alike.
    $html = (string) $response->getContent();

    // Livewire 4.4.4 with auto-injected assets does NOT emit an inline
    // window.livewireScriptConfig block: scriptConfig() is only reached through
    // the @livewireScriptConfig directive (FrontendAssets.php:71-73), and the
    // auto-injected path puts csrf, the module url and the update uri on the
    // <script src> tag as data-* attributes instead. What fact 3 actually buys
    // is this tag's nonce, with no wiring beyond Vite::useCspNonce().
    expect(substr_count($html, 'nonce="'.$nonce.'"'))->toBeGreaterThanOrEqual(3)
        ->and($html)->toMatch('/<script src="[^"]*\/livewire\.js[^"]*" nonce="'.$nonce.'"/');
});

it('nonces the turnstile callback and allows cloudflare to be framed', function () {
    config()->set('cass.turnstile.site_key', 'site-key');
    config()->set('cass.turnstile.secret_key', 'secret-key');

    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->create([
        'status' => ConferenceStatus::Open,
        'submission_opens_at' => now()->subWeek(),
        'submission_deadline' => now()->addWeek(),
    ]);

    $response = get('/c/'.$organization->slug.'/'.$conference->slug.'/submit')->assertOk();
    $policy = policyOf($response);

    // Plan 3's backlog named all three: the branding style attribute, the
    // inline Turnstile callback, and challenges.cloudflare.com as a script
    // source. "A nonce strategy that forgets the third turns the widget into a
    // form nobody can submit."
    expect($policy)->toContain('frame-src https://challenges.cloudflare.com')
        ->and($policy)->toContain('connect-src')
        ->and($response->getContent())->toContain('cassTurnstileCallback');

    preg_match("/'nonce-([A-Za-z0-9]{40})'/", $policy, $matches);
    $response->assertSee('<script nonce="'.($matches[1] ?? '').'">', escape: false);
});

it('leaves a stricter policy alone on a file download', function () {
    Storage::fake('local');
    $submission = Submission::factory()->submitted()->create();
    $file = SubmissionFile::factory()->for($submission)->create();
    Storage::disk('local')->put((string) $file->path, 'pdf bytes');

    $response = get($file->temporaryUrl())->assertOk();

    // SubmissionFileController sets default-src 'none'; sandbox. A middleware
    // that overwrote it would turn a sandboxed download into a document with
    // the app's own script sources.
    expect(policyOf($response))->toBe("default-src 'none'; sandbox");
});

it('can be switched to report-only for the first week on production', function () {
    config()->set('cass.security.csp_report_only', true);

    $response = get('/')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBeNull()
        ->and((string) $response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'");
});

it('still sets the four plan 1 headers and adds two more', function () {
    $response = get('/')->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and((string) $response->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->and($response->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin')
        ->and($response->headers->get('X-Permitted-Cross-Domain-Policies'))->toBe('none')
        // Spec section 9 gives HSTS to Cloudflare. The app must not also send
        // one, or two sources of truth disagree about max-age at the worst
        // possible moment.
        ->and($response->headers->get('Strict-Transport-Security'))->toBeNull();
});

it('sets the policy on an error page too', function () {
    // The reason ContentSecurityPolicy is GLOBAL rather than web-group
    // middleware: a 404 is a page a browser renders, and Laravel's error view
    // is where an injected script would be least expected. LandingPageTest's
    // sibling case pins the same property for SecurityHeaders, on the same URL.
    $response = get('/nonexistent-probe.css')->assertNotFound();

    expect(policyOf($response))->toContain("default-src 'self'")
        ->and(policyOf($response))->toMatch("/script-src [^;]*'nonce-[A-Za-z0-9]{40}'/");
});

it('nonces the brand lock-up stylesheet the layout inlines', function () {
    // brand/logo.blade.php ends in a <style> ELEMENT (the dark-mode wordmark
    // rule, which has no inline-attribute equivalent). style-src is 'self'
    // plus the nonce, so an un-nonced copy is refused and nothing else in this
    // suite would notice: the page still renders, just with a blue wordmark on
    // a dark panel.
    $response = get('/')->assertOk();

    preg_match("/'nonce-([A-Za-z0-9]{40})'/", policyOf($response), $matches);

    expect((string) $response->getContent())
        ->toContain('<style nonce="'.($matches[1] ?? '').'">.dark .cass-wordmark');
});

it('nonces the sidebar script filament renders on every authenticated panel page', function () {
    // filament-panels::livewire.sidebar is the third published view and the one
    // easiest to miss: /org/login has no sidebar at all, so every other case in
    // this file would stay green with its one bare inline <script> un-nonced -
    // and the navigation-group collapse state would silently stop working on
    // every authenticated page of all three panels.
    $admin = User::factory()->platformAdmin()->create();

    $response = actingAs($admin)->get('/admin')->assertOk();
    $html = (string) $response->getContent();

    preg_match("/'nonce-([A-Za-z0-9]{40})'/", policyOf($response), $matches);
    $nonce = $matches[1] ?? '';

    expect($nonce)->not->toBe('')
        ->and($html)->toMatch('/<script nonce="'.$nonce.'">\s*var collapsedGroups/')
        // And nothing on the page emits a bare inline <script>: a tag without a
        // nonce and without a src is a tag the policy refuses.
        ->and($html)->not->toContain('<script>');
});

it('renders an authenticated panel page with no off-origin image source', function () {
    $admin = User::factory()->platformAdmin()->create();

    $html = (string) actingAs($admin)->get('/admin')->assertOk()->getContent();

    // Filament's default avatar provider is UiAvatarsProvider, which emits
    // https://ui-avatars.com/api/?name=<the user's name> in the topbar of every
    // authenticated page - cross-origin under img-src, and every panel user's
    // name sent to a third party on every page load. Every case above requests
    // a LOGIN page, which has no avatar, so nothing else here would catch it.
    expect($html)->not->toContain('ui-avatars.com');
});
