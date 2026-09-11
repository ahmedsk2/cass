<?php

declare(strict_types=1);

use App\Support\Turnstile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config()->set('cass.turnstile.site_key', 'site-key');
    config()->set('cass.turnstile.secret_key', 'secret-key');
});

it('is off until both keys are configured', function (?string $site, ?string $secret, bool $expected) {
    config()->set('cass.turnstile.site_key', $site);
    config()->set('cass.turnstile.secret_key', $secret);

    expect(Turnstile::isConfigured())->toBe($expected);
})->with([
    [null, null, false],
    ['site-key', null, false],
    [null, 'secret-key', false],
    ['', '', false],
    ['site-key', 'secret-key', true],
]);

it('waves everything through when it is not configured', function () {
    Http::fake();
    config()->set('cass.turnstile.site_key', null);
    config()->set('cass.turnstile.secret_key', null);

    expect(Turnstile::verify(null, '203.0.113.7'))->toBeTrue();

    Http::assertNothingSent();
});

it('posts the secret, the response and the client address and accepts a success', function () {
    Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => true], 200)]);

    expect(Turnstile::verify('a-token', '203.0.113.7'))->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->url() === Turnstile::VERIFY_URL
        && $request['secret'] === 'secret-key'
        && $request['response'] === 'a-token'
        && $request['remoteip'] === '203.0.113.7'
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
});

it('refuses an explicit failure and an empty token', function () {
    Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']], 200)]);

    expect(Turnstile::verify('a-token', '203.0.113.7'))->toBeFalse();

    // An empty token never leaves the process: there is nothing to verify and
    // Cloudflare would only answer the same thing more slowly.
    Http::fake();
    expect(Turnstile::verify('', '203.0.113.7'))->toBeFalse()
        ->and(Turnstile::verify(null, '203.0.113.7'))->toBeFalse();
});

it('lets a submission through when cloudflare itself is unreachable, and says so in the log', function () {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    // Fail *open*, deliberately. The alternative is that an outage at
    // Cloudflare silently refuses every abstract in the last hour before a
    // deadline - which is the exact moment it would hurt most - while the
    // honeypot, the minimum fill time and the per-IP throttle are all still
    // running. An explicit `success: false` is still a refusal; only an
    // unreachable verifier is waved through, and it is logged so the outage is
    // visible rather than inferred.
    expect(Turnstile::verify('a-token', '203.0.113.7'))->toBeTrue();

    Log::shouldHaveReceived('warning')->once();
});

it('refuses when cloudflare answers with a server error', function () {
    Http::fake([Turnstile::VERIFY_URL => Http::response('', 500)]);

    expect(Turnstile::verify('a-token', '203.0.113.7'))->toBeFalse();
});
