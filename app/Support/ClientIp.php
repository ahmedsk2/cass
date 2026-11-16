<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

final class ClientIp
{
    /** The first twelve bytes of an IPv4 address written as IPv6, ::ffff:a.b.c.d. */
    private const string IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /**
     * CF-Connecting-IP is authoritative only for a request that actually
     * arrived through the proxy tier that writes it: Cloudflare overwrites the
     * header on everything it forwards, but any peer that reaches the origin
     * directly - a health check, a container on the same network, a scanner
     * that found the origin address - can put anything in it, and the result is
     * used as a rate-limit key. Anything else falls back to the peer address
     * Laravel already resolved (itself proxy-aware through TrustProxies).
     */
    public static function from(Request $request): string
    {
        if ($request->isFromTrustedProxy()) {
            $cf = $request->headers->get('CF-Connecting-IP');

            if (is_string($cf) && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
                return $cf;
            }
        }

        return (string) $request->ip();
    }

    /**
     * The key a rate limit counts this client under: its address for IPv4, and
     * the /64 its address sits in for IPv6. One IPv6 subscriber is routed at
     * least a /64 and can take a fresh address in it for every request, so a
     * limit keyed on the full address would never fill. The /64 is the smallest
     * block a subscriber holds, as the single address is for IPv4, not always
     * the whole of it: one delegated a /56 or a /48 still has 256 or 65,536
     * budgets. Clients that share a /64, like clients behind one IPv4 NAT
     * address, share a budget.
     *
     * Only for limiter keys: from() stays the full address, for anything that
     * sends, stores or logs one (Turnstile's remoteip, for one).
     */
    public static function limiterKey(Request $request): string
    {
        return self::limiterKeyFor(self::from($request));
    }

    /**
     * limiterKey() for an address already read, for an Action that is handed
     * one. An IPv6 /64 is spelled one way, inet_ntop()'s with the interface half
     * zeroed and "/64" added, so 2001:db8::1 and 2001:0db8:0:0:0:0:0:1 share the
     * key 2001:db8::/64. An IPv4 address written as IPv6 (::ffff:203.0.113.5) is
     * keyed as the IPv4 address, and a value that is not an address comes back
     * unchanged, so it still counts as itself.
     */
    public static function limiterKeyFor(string $address): string
    {
        $packed = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? false : inet_pton($address);

        if ($packed === false) {
            return $address;
        }

        if (str_starts_with($packed, self::IPV4_MAPPED_PREFIX)) {
            return (string) inet_ntop(substr($packed, 12));
        }

        $prefix = inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8));

        return $prefix === false ? $address : $prefix.'/64';
    }
}
