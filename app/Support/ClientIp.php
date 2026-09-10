<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

final class ClientIp
{
    /**
     * Cloudflare is the only network allowed to reach the host on 80/443, so
     * CF-Connecting-IP is authoritative when present; otherwise fall back to
     * the proxy-resolved IP.
     */
    public static function from(Request $request): string
    {
        $cf = $request->headers->get('CF-Connecting-IP');

        return is_string($cf) && filter_var($cf, FILTER_VALIDATE_IP) ? $cf : (string) $request->ip();
    }
}
