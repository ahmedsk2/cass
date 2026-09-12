<?php

declare(strict_types=1);

use App\Console\Commands\HealthCommand;
use App\Console\Commands\ImportLegacyCommand;
use App\Console\Commands\RescoreConferenceCommand;
use App\Console\Commands\SendReviewerRemindersCommand;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\RequireCustomDomain;
use App\Http\Middleware\ResolveCustomDomain;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Domains\CustomDomains;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // `withRouting(commands: routes/console.php)` registers that *file* only;
    // app/Console/Commands is NOT scanned without this (fact 2). The class is
    // named rather than the directory, so the registration is greppable and a
    // second command has to be declared on purpose.
    ->withCommands([
        SendReviewerRemindersCommand::class,
        RescoreConferenceCommand::class,
        ImportLegacyCommand::class,
        HealthCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        // Before ResolveCustomDomain, not after: Illuminate\Routing\Pipeline
        // catches an abort() at the pipe that threw and returns the rendered
        // response UPWARD, so anything appended after that middleware never
        // runs for a refused host or a reserved path - and those 404s are
        // documents a browser renders, with @vite tags in them. Minting the
        // nonce first also means it exists before any view is compiled, which
        // is what the "still sends a policy on the 404 a reserved path
        // produces" case in CustomDomainRoutingTest asserts.
        $middleware->append(ContentSecurityPolicy::class);

        // Global, not a group: this has to run before routing, so that a
        // request for /about on a custom domain never reaches the route that
        // serves the platform's about page (fact 15).
        $middleware->append(ResolveCustomDomain::class);

        // GET /{conference} is registered inside the `web` group, so on the
        // PLATFORM host every slug-shaped 404 - /wp-admin, /backup, /login,
        // whatever a scanner is asking for this minute - matched that route and
        // ran EncryptCookies, StartSession and ValidateCsrfToken before
        // RequireCustomDomain aborted. With SESSION_DRIVER=database that is one
        // INSERT and one Set-Cookie per scan, which no unmatched path produced
        // before this branch.
        //
        // prependToPriorityList, not priority(): it inserts one entry into
        // Laravel's own default list rather than replacing the list, so
        // Filament's and Livewire's ordering stay exactly as the framework
        // defines them. Safe because RequireCustomDomain reads only request
        // attributes and route parameters - no session, no auth, no bindings -
        // and rendering a 404 without a session is already what every reserved
        // path on a custom domain does.
        $middleware->prependToPriorityList(
            StartSession::class,
            RequireCustomDomain::class,
        );

        // env() is used here (not config()) because the application config
        // files are not loaded yet when bootstrap/app.php runs - this is
        // the earliest point in the boot sequence, before the config
        // repository exists. Production runs with real environment
        // variables from Docker, so this is safe.
        $middleware->trustProxies(
            at: array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'))))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
        // Spec 5.8 and the Plan 1 backlog item: a verified custom domain has
        // to be a trusted host or TrustHosts answers 400 before any route
        // runs. The platform's own host is first and unconditional, so a
        // database failure inside verifiedHosts() (which returns [] and
        // reports) leaves the main site working.
        //
        // TrustHosts entries are PATTERNS: Symfony wraps each one as `{...}i`
        // and matches it UNANCHORED (Request::setTrustedHosts), so a bare
        // `abstracts.example.org` would also trust
        // `abstracts.example.org.evil.test` and would treat every dot as "any
        // character". Laravel's own default anchors and preg_quote()s for
        // exactly this reason; so does this. (verifiedHosts() still returns
        // plain hosts - only the closure's output is a pattern list, and
        // preg_quote() already escapes `{` and `}`, so no delimiter argument is
        // needed.)
        //
        // This closure is LAZY - Laravel calls it per request from
        // TrustHosts::hosts(), long after the config repository and the
        // container are up - which is why config() and a database-backed
        // lookup are safe here and only here. The env() comment above applies
        // to the eagerly evaluated arguments, not to this.
        $middleware->trustHosts(at: fn (): array => array_map(
            static fn (string $host): string => '^'.preg_quote($host).'$',
            [
                parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
                ...CustomDomains::verifiedHosts(),
            ],
        ), subdomains: false);

        // Filament's own Authenticate middleware redirects panel routes to
        // that panel's login page. Plain `auth` routes (the conference
        // asset downloads) need an explicit target because the app has no
        // route named "login".
        $middleware->redirectGuestsTo(fn (): string => route('filament.organizer.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
