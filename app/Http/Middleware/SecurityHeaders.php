<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        // Cross-origin isolation for the panels: an opener that keeps a handle
        // on a window it launched can read window.name and navigate it. Nothing
        // in this application is opened by a third party on purpose.
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        // No crossdomain.xml is served, and this says so rather than leaving a
        // legacy Flash/PDF policy fetch to a 404 page.
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        return $response;
    }
}
