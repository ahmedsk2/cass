<?php

declare(strict_types=1);

namespace App\Support\Domains;

use Illuminate\Support\Facades\URL;

/**
 * A route that must be minted on the PLATFORM host even when the page asking
 * for it is being served from a verified custom domain.
 *
 * ResolveCustomDomain used to pin URL::forceRootUrl() to APP_URL for the whole
 * request, which moved every route() onto the platform host - including
 * Livewire's, because FrontendAssets builds `data-update-uri` and
 * window.livewireScriptConfig.uri with url() (FrontendAssets.php:221, :242).
 * That made every Livewire POST from a custom-domain page cross-origin:
 * refused by this branch's own connect-src 'self', unanswered by any CORS
 * middleware, and stripped of the SameSite=lax host-only session cookie - so
 * the submission form on the organizer's own domain could not save a draft,
 * submit, or upload a file.
 *
 * So the root is no longer forced and the handful of links that genuinely
 * belong to the platform say so here. Every one of them is a path the
 * RESERVED list in ResolveCustomDomain 404s on a custom domain: /s/{token},
 * /files/{ulid}, /invite/{token}, and the platform's own footer pages.
 *
 * The path still comes from the route table (URL::route(..., absolute: false)),
 * never from a hardcoded string, so moving a route moves these links with it.
 */
final class PlatformUrl
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function route(string $name, array $parameters = []): string
    {
        return self::origin().URL::route($name, $parameters, absolute: false);
    }

    /** APP_URL without its trailing slash. */
    public static function origin(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
