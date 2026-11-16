<?php

declare(strict_types=1);

use App\Support\ClientIp;
use Illuminate\Http\Request;

beforeEach(function () {
    // Trusted proxies are global static state on the Request class, set by the
    // TrustProxies middleware during a real request, so this test sets its own
    // and puts back whatever was there.
    $this->proxies = Request::getTrustedProxies();
    $this->headerSet = Request::getTrustedHeaderSet();

    Request::setTrustedProxies(['10.0.0.0/8'], Request::HEADER_X_FORWARDED_FOR);
});

afterEach(function () {
    Request::setTrustedProxies($this->proxies, $this->headerSet);
});

function requestFrom(string $remoteAddress, ?string $cfConnectingIp = null): Request
{
    $request = Request::create('/q/ABCD1234', server: ['REMOTE_ADDR' => $remoteAddress]);

    if ($cfConnectingIp !== null) {
        $request->headers->set('CF-Connecting-IP', $cfConnectingIp);
    }

    return $request;
}

it('ignores CF-Connecting-IP from a client that did not come through a trusted proxy', function () {
    // Anyone who reaches the origin directly can send this header, and the
    // value becomes a rate-limit key: honouring it unconditionally lets one
    // host mint a fresh counting budget per request.
    expect(ClientIp::from(requestFrom('203.0.113.9', '198.51.100.7')))->toBe('203.0.113.9');
});

it('honours CF-Connecting-IP from a trusted proxy', function () {
    expect(ClientIp::from(requestFrom('10.0.0.5', '198.51.100.7')))->toBe('198.51.100.7');
});

it('falls back to the peer address when the header is absent or not an address', function () {
    expect(ClientIp::from(requestFrom('10.0.0.5')))->toBe('10.0.0.5')
        ->and(ClientIp::from(requestFrom('10.0.0.5', 'not-an-ip')))->toBe('10.0.0.5')
        ->and(ClientIp::from(requestFrom('203.0.113.9')))->toBe('203.0.113.9');
});

it('keys an IPv4 client on its address as it is', function () {
    expect(ClientIp::limiterKeyFor('203.0.113.7'))->toBe('203.0.113.7')
        ->and(ClientIp::limiterKey(requestFrom('203.0.113.9')))->toBe('203.0.113.9');
});

it('keys an IPv6 client on its /64, spelled one way however the address is written', function () {
    // One subscriber is routed the whole /64 and can take a fresh address in it
    // for every request, so a limit keyed on the full address would never fill.
    expect(ClientIp::limiterKeyFor('2001:db8::1'))->toBe('2001:db8::/64')
        ->and(ClientIp::limiterKeyFor('2001:0db8:0000:0000:0000:0000:0000:0001'))->toBe('2001:db8::/64')
        ->and(ClientIp::limiterKeyFor('2001:db8::2'))->toBe('2001:db8::/64')
        ->and(ClientIp::limiterKeyFor('2001:DB8:0:0:FFFF:FFFF:FFFF:FFFF'))->toBe('2001:db8::/64')
        // The next /64 along is another client.
        ->and(ClientIp::limiterKeyFor('2001:db8:0:1::1'))->toBe('2001:db8:0:1::/64')
        ->and(ClientIp::limiterKeyFor('2001:db8:1:2:3:4:5:6'))->toBe('2001:db8:1:2::/64');
});

it('keys an IPv4 address written as IPv6 on the IPv4 address', function () {
    expect(ClientIp::limiterKeyFor('::ffff:203.0.113.5'))->toBe('203.0.113.5')
        ->and(ClientIp::limiterKeyFor('::ffff:cb00:7105'))->toBe('203.0.113.5');
});

it('returns a value that is not an address unchanged', function () {
    // It still counts as itself: a request with no peer address gives from() an
    // empty string, and that is one bucket, not none.
    expect(ClientIp::limiterKeyFor('not-an-ip'))->toBe('not-an-ip')
        ->and(ClientIp::limiterKeyFor(''))->toBe('');
});

it('keys the client from() resolves, while from() keeps the full address', function () {
    // Through a trusted proxy the key is the Cloudflare client's /64; straight to
    // the origin it is the peer's own, whatever header the peer sends.
    expect(ClientIp::limiterKey(requestFrom('10.0.0.5', '2001:db8:1:2::a')))->toBe('2001:db8:1:2::/64')
        ->and(ClientIp::limiterKey(requestFrom('2001:db8:9:9::1', '2001:db8:1:2::a')))->toBe('2001:db8:9:9::/64')
        ->and(ClientIp::from(requestFrom('10.0.0.5', '2001:db8:1:2::a')))->toBe('2001:db8:1:2::a');
});
