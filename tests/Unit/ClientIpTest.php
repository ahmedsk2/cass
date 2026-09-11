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
