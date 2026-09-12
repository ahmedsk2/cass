<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Domains\CustomDomains;
use App\Support\Domains\DomainName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a Host header into an Organization, or into a 404.
 *
 * Appended to the GLOBAL stack in bootstrap/app.php, which means it runs
 * *before* routing (fact 15) - and that is the whole point. A request for
 * /about on a custom domain must not reach the route that would serve the
 * platform's about page, and only a global middleware sees it in time.
 */
class ResolveCustomDomain
{
    /** The request attribute every downstream reader uses. */
    public const ATTRIBUTE = 'cass.custom_domain_organization';

    /**
     * The first path segment of every platform-only route (routes/web.php and
     * the three panel paths). On a custom domain each of these is a 404: an
     * organizer publishing their call for abstracts is not opening a second
     * front door to CASS, and a registration form on somebody else's domain is
     * a phishing surface with our name on it.
     *
     * `up` and Livewire's endpoints are deliberately absent from RESERVED: the
     * submission form posts to `/livewire-{hash}/update` and uploads to
     * `/livewire-{hash}/upload-file`, where {hash} is the first eight hex
     * characters of sha256(APP_KEY.'livewire-endpoint')
     * (Livewire\Mechanisms\HandleRequests\EndpointResolver::prefix()) - so no
     * fixed segment could be listed here anyway, the form posts to that prefix
     * on whatever host rendered it, and the health check must answer
     * everywhere.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'c', 'q', 's', 'invite', 'files', 'conference-assets',
        'about', 'privacy', 'terms', 'contact', 'register',
        'org', 'admin', 'review',
        // Not in routes/web.php, but registered all the same and therefore
        // answerable here: Filament's export/import download routes
        // (filament/exports/{export}/download) and Laravel's local-disk serve
        // route (storage/{path}). Neither is needed on a tenant host - the
        // panels are already reserved and the public disk's URLs are built
        // from APP_URL - and the derived case in CustomDomainRoutingTest is
        // what stops the next registered segment from being missed too.
        'filament', 'storage',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $host = DomainName::normalise($request->getHost());

        if ($host === '' || $host === DomainName::platformHost()) {
            return $next($request);
        }

        $organization = CustomDomains::organizationFor($host);

        if (! $organization instanceof Organization) {
            // TrustHosts answers 400 for an unknown host before this runs in
            // production. This is the defence in depth for a host that IS
            // trusted - one whose organization was suspended, deleted or had
            // its domain released between the cached list and now.
            abort(404);
        }

        // decodedPath(), not path(): Laravel's UriValidator matches
        // rawurldecode($path), so `/%72egister` routes to `register` while
        // `path()` still reads `%72egister`. Comparing the encoded form is how
        // a reserved path gets served on somebody else's domain. Verified by
        // booting the app: path() = '%72egister', matched route = 'register'.
        $path = trim($request->decodedPath(), '/');

        // No `?? ''`: explode() always returns a non-empty list, and for the
        // root path that first element is already the empty string.
        $segment = strtolower(explode('/', $path)[0]);

        if (in_array($segment, self::RESERVED, true)) {
            abort(404);
        }

        // Allow-list rather than deny-list for everything else this host may
        // serve: the health check, Livewire's APP_KEY-derived endpoint prefix,
        // and a conference slug. Anything else is a platform path this
        // middleware has not been taught about yet. (Static files such as
        // /build/... and /storage/... are served by nginx from disk in
        // production and never reach PHP.)
        $allowed = $segment === ''
            || $segment === 'up'
            || str_starts_with($segment, 'livewire-')
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $segment) === 1;

        if (! $allowed) {
            abort(404);
        }

        $request->attributes->set(self::ATTRIBUTE, $organization);

        // The URL root is deliberately NOT forced to APP_URL here.
        //
        // route() and url() both build from UrlGenerator::formatRoot(), and so
        // does Livewire: FrontendAssets emits `data-update-uri` and
        // window.livewireScriptConfig.uri as url(getUpdateUri())
        // (FrontendAssets.php:221 and :242). Forcing the root therefore moved
        // Livewire's own POST onto the platform host, where it is cross-origin
        // - refused by this branch's connect-src 'self', unanswered by any CORS
        // middleware, and carrying none of the SameSite=lax host-only session
        // cookie. The submission form rendered and could never save.
        //
        // The links that genuinely belong to the platform - /s/{token},
        // /files/{ulid}, /invite/{token} and the footer's own pages, every one
        // of them a 404 by the RESERVED list above - are minted through
        // App\Support\Domains\PlatformUrl instead, which prefixes APP_URL onto
        // a path taken from the route table.

        return $next($request);
    }
}
