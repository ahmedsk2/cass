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
