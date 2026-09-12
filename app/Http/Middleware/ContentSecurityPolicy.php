<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec section 9's Content-Security-Policy, with a per-request nonce.
 *
 * ONE call does most of the work. Vite::useCspNonce() nonces every @vite tag
 * (Illuminate\Foundation\Vite::nonceAttribute()), and Livewire 4.4.4 reads
 * Vite::cspNonce() itself in FrontendAssets::nonce() - so its <style>, its
 * <script src>, its inline window.livewireScriptConfig and the nonce it hands
 * its own runtime all follow from it. Filament 5.8.1 has no nonce support at
 * all, which is why three of its views are published into
 * resources/views/vendor with {{ Vite::cspNonce() }} added - filament::assets,
 * filament-panels::components.layout.base and filament-panels::livewire.sidebar
 * - pinned by tests/Feature/Security/PublishedFilamentViewsTest.php.
 *
 * Global (bootstrap/app.php), so it covers the public pages, all three panels
 * and the /livewire endpoints alike.
 */
class ContentSecurityPolicy
{
    /**
     * The platform's own origin, named explicitly because a page served on a
     * verified CUSTOM domain still loads the organization's logo from
     * APP_URL: config/filesystems.php:55 builds the branding disk's url as
     * APP_URL.'/storage/branding', which is cross-origin there. 'self' alone
     * would blank every organizer's logo - and every og:image - on the domain
     * they just verified. It is a placeholder because config('app.url') cannot
     * be read from a constant; policy() substitutes it per request.
     */
    private const PLATFORM_ORIGIN = '__platform__';

    /**
     * Everything below is a deliberate choice, and the four that look loose
     * are argued in Plan 6 Task 7:
     *
     * - 'unsafe-eval' in script-src: Alpine's evaluator is new Function(), and
     *   Livewire's CSP-safe build understands a dialect Filament's views are
     *   not written in. The nonce is what stops an INJECTED script; this lets
     *   already-loaded trusted code compile strings the app itself wrote.
     * - style-src-attr 'unsafe-inline': a nonce never reaches a style
     *   ATTRIBUTE, and the organization's branding variables and the brand
     *   lock-up on all three panels are attributes.
     * - challenges.cloudflare.com in script-src, connect-src and frame-src:
     *   Turnstile is a script, a fetch and an iframe. Plan 3's backlog: "a
     *   nonce strategy that forgets the third turns the widget into a form
     *   nobody can submit."
     * - img-src blob:: Filament's FileUpload previews an image the visitor
     *   just chose from a blob URL, and the submission form uses that endpoint.
     * - img-src PLATFORM_ORIGIN: the branding disk builds absolute URLs on
     *   APP_URL, so on a verified custom domain the organization's own logo
     *   and the og:image are cross-origin.
     *
     * @var array<string, list<string>>
     */
    private const DIRECTIVES = [
        'default-src' => ["'self'"],
        'base-uri' => ["'self'"],
        'object-src' => ["'none'"],
        'frame-ancestors' => ["'none'"],
        'form-action' => ["'self'"],
        'script-src' => ["'self'", "'unsafe-eval'", 'https://challenges.cloudflare.com'],
        'style-src' => ["'self'"],
        'style-src-attr' => ["'unsafe-inline'"],
        'img-src' => ["'self'", 'data:', 'blob:', self::PLATFORM_ORIGIN],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'", 'https://challenges.cloudflare.com'],
        'frame-src' => ['https://challenges.cloudflare.com'],
        'media-src' => ["'none'"],
        'worker-src' => ["'self'", 'blob:'],
        'manifest-src' => ["'self'"],
    ];

    /** The two directives the nonce is appended to. */
    private const NONCED = ['script-src', 'style-src'];

    public function handle(Request $request, Closure $next): Response
    {
        // BEFORE $next: every tag downstream reads Vite::cspNonce(), so the
        // nonce has to exist before a single view renders.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        if (! $this->applies($response)) {
            return $response;
        }

        $header = $this->headerName();
        $response->headers->set($header, $this->policy($nonce));

        return $response;
    }

    /**
     * HTML only, and never over the top of a stricter policy a controller
     * already set. SubmissionFileController sends "default-src 'none'; sandbox"
     * on a download; overwriting it would turn a sandboxed file into a document
     * carrying this application's script sources.
     */
    private function applies(Response $response): bool
    {
        // `composer run dev` serves assets from http://localhost:5173 and the
        // Vite client both loads scripts from that origin and injects <style>
        // elements from JavaScript. Adding the origin to script-src/connect-src
        // would fix the first; nothing fixes the second, because style-src
        // carries a nonce and the CSP spec ignores 'unsafe-inline' whenever a
        // nonce is present. So while the hot file exists, send no policy: the
        // alternative is every local page unstyled with no hot reload and no
        // error a developer can attribute to CSP.
        //
        // Vite::isRunningHot() is is_file(public_path('hot')). That file exists
        // only while `npm run dev` is running - never in CI, never in the
        // browser suite (which runs against `npm run build`), never in the
        // image - so no test and no production response is affected.
        if (Vite::isRunningHot()) {
            return false;
        }

        if ($response->headers->has('Content-Security-Policy')
            || $response->headers->has('Content-Security-Policy-Report-Only')) {
            return false;
        }

        $type = (string) $response->headers->get('Content-Type', '');

        // An empty Content-Type is Laravel's default HTML response before the
        // header is finalised, so it counts; a CSV, an XLSX and a PDF do not.
        return $type === '' || str_contains($type, 'text/html');
    }

    private function headerName(): string
    {
        // Report-only for the first production week, with a human watching the
        // browser console. There is no report endpoint on purpose: a public
        // POST that any browser on the internet can fill is a spam sink.
        return config('cass.security.csp_report_only')
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }

    private function policy(string $nonce): string
    {
        $parts = [];
        $platform = rtrim((string) config('app.url'), '/');

        foreach (self::DIRECTIVES as $directive => $configured) {
            $sources = [];

            foreach ($configured as $source) {
                $source = $source === self::PLATFORM_ORIGIN ? $platform : $source;

                // APP_URL empty is not a production state, but a bare harness
                // can reach it, and an empty token inside a directive is a
                // parse error for the browser rather than a missing source.
                if ($source === '') {
                    continue;
                }

                $sources[] = $source;
            }

            if (in_array($directive, self::NONCED, true)) {
                $sources = [...$sources, "'nonce-{$nonce}'"];
            }

            $parts[] = $directive.' '.implode(' ', $sources);
        }

        return implode('; ', $parts);
    }
}
