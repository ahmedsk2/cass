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
