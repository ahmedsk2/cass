<?php

declare(strict_types=1);

use function Pest\Laravel\get;

// Production once SENTRY_LARAVEL_DSN is set: with a DSN at boot the package binds its event handlers and
// HTTP middleware and decorates the route dispatchers, none of which ErrorTrackingTest reaches (it sets
// its DSN after boot). A .invalid host never resolves, so nothing can be sent.
beforeAll(function () {
    $_SERVER['SENTRY_LARAVEL_DSN'] = $_ENV['SENTRY_LARAVEL_DSN'] = 'https://public@sentry.invalid/1';
    putenv('SENTRY_LARAVEL_DSN=https://public@sentry.invalid/1');
});

afterAll(function () {
    unset($_SERVER['SENTRY_LARAVEL_DSN']);
    $_ENV['SENTRY_LARAVEL_DSN'] = '';
    putenv('SENTRY_LARAVEL_DSN=');
});

it('serves the site and the panel logins with the tracker wired at boot', function (string $path) {
    expect(config('sentry.dsn'))->not->toBeEmpty();
    get($path)->assertOk();
})->with(['/', '/admin/login', '/org/login', '/review/login']);
