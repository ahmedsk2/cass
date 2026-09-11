<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

final class ClientIp
{
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
}
