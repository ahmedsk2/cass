<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('shows the hummingbird lock-up in the public header', function () {
    get('/')->assertOk()
        ->assertSee('brand/cass-mark.svg', escape: false)
        ->assertSee('cass-wordmark', escape: false)
        ->assertSee('>CASS</span>', escape: false);
});

it('shows the hummingbird lock-up on the organizer login page', function () {
    get('/org/login')->assertOk()
        ->assertSee('brand/cass-mark.svg', escape: false);
});

it('ships every brand file the layouts and manifests reference', function (string $path) {
    $absolute = public_path($path);

    expect(file_exists($absolute))->toBeTrue("public/{$path} is missing");
    expect(filesize($absolute))->toBeGreaterThan(0, "public/{$path} is empty");
})->with([
    'brand/cass-mark.svg',
    'brand/cass-mark-mono.svg',
    'brand/cass-mark-white.svg',
    'brand/cass-mark-144.png',
    'favicon.ico',
    'favicon.svg',
    'apple-touch-icon.png',
]);

// The pre-brand placeholder was a PNG crop of the legacy logo. Nothing may
// reference it any more, so nothing may ship it either - a leftover file is
// how a half-finished rebrand quietly survives.
it('no longer ships the legacy bird crop', function (string $directory) {
    expect(glob($directory.'/cass-bird*'))->toBe([]);
})->with([
    fn () => public_path('brand'),
    fn () => resource_path('brand'),
]);
