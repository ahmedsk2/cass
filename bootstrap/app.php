<?php

declare(strict_types=1);

use App\Console\Commands\ImportLegacyCommand;
use App\Console\Commands\RescoreConferenceCommand;
use App\Console\Commands\SendReviewerRemindersCommand;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

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
        $middleware->trustHosts(at: fn (): array => [parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'], subdomains: false);

        // Filament's own Authenticate middleware redirects panel routes to
        // that panel's login page. Plain `auth` routes (the conference
        // asset downloads) need an explicit target because the app has no
        // route named "login".
        $middleware->redirectGuestsTo(fn (): string => route('filament.organizer.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
