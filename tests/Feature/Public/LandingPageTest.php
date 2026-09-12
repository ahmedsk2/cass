<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('renders the landing page with the main calls to action', function () {
    get('/')->assertOk()
        ->assertSee('Conference Abstract Submission System')
        ->assertSee('Register organization')
        ->assertSee('Organizer login')
        ->assertSee('QR code');
});

it('renders the static pages', function (string $path, string $heading) {
    get($path)->assertOk()->assertSee($heading);
})->with([
    ['/about', 'About CASS'],
    ['/privacy', 'Privacy policy'],
    ['/terms', 'Terms of use'],
]);

it('sends security headers', function () {
    get('/')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

// SecurityHeaders is global middleware (bootstrap/app.php), not web-group
// middleware, so it also runs for a request that matches no route at all.
// The smoke job in .github/workflows/ci.yml relies on exactly that: it tells
// a Laravel 404 apart from nginx's own 404 by this header, which is the only
// way to prove docker/nginx.conf still falls through to Laravel for a URI
// ending in a static extension. Move the middleware into the web group and
// that assertion silently stops testing anything.
it('sends security headers on a 404 for an unrouted static-looking path', function () {
    get('/nonexistent-probe.css')->assertNotFound()
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('gives every illustration an intrinsic size and serves the hero as webp', function () {
    $response = get('/')->assertOk();
    $html = (string) $response->getContent();

    // Five of the six site images had no width/height (Plan 6 fact 41), which
    // is five separate layout shifts on the slowest connection the site has.
    preg_match_all('/<img\b[^>]*>/i', $html, $images);

    foreach ($images[0] as $tag) {
        expect($tag)->toMatch('/\bwidth="\d+"/', $tag)
            ->and($tag)->toMatch('/\bheight="\d+"/', $tag);
    }

    // The hero is 158 KB of PNG at 1600x1051. WebP at the size it is displayed
    // is the single biggest byte on this page.
    expect($html)->toContain('hero-researcher.webp');
});

it('points the organizer login at the panel route rather than a hardcoded path', function () {
    $response = get('/')->assertOk();

    // The rendered page cannot tell the two apart: route() is absolute by
    // default and the panel's path is '/org', so route('filament.organizer.
    // auth.login') and url('/org/login') produce the identical string - both
    // assertions below would pass on the old code, and the literal "/org/login"
    // never appears in either. The page assertion is kept because it proves the
    // route NAME resolves; the source assertions are what can actually fail on
    // a revert.
    expect((string) $response->getContent())->toContain(route('filament.organizer.auth.login'));

    foreach ([
        'resources/views/components/layouts/public.blade.php',
        'resources/views/public/landing.blade.php',
        'resources/views/livewire/public/register-organization.blade.php',
    ] as $view) {
        // No second argument: Pest's toContain() is variadic over NEEDLES, not
        // (needle, message), so a "message" there is a second string the source
        // is asserted to contain.
        $source = (string) file_get_contents(base_path($view));

        expect($source)->toContain("route('filament.organizer.auth.login')")
            ->and($source)->not->toContain("url('/org/login')");
    }
});
