<?php

declare(strict_types=1);

/*
 * Error tracking through the Sentry SDK, whose protocol Sentry, GlitchTip and Bugsink all accept
 * (docs/decisions/0015-error-tracking-and-heartbeats.md). A key left out keeps the package's default
 * (vendor/sentry/sentry-laravel/config/sentry.php): the merge is by top-level key,
 * so `breadcrumbs` below replaces that list.
 * Authors' abstracts, names and email addresses must never reach the tracker: no request bodies, no
 * personal data (IP address, signed-in user, cookies), no SQL bindings, and none of the breadcrumbs that carry them.
 */
return [

    // A secret, set only in the deployment's environment. Unset or empty: nothing is sent.
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // Optional, for grouping events by release (the deployed commit, say).
    'release' => env('SENTRY_RELEASE'),

    // Unset: APP_ENV.
    'environment' => env('SENTRY_ENVIRONMENT'),

    'send_default_pii' => false,
    'max_request_body_size' => 'never',

    // Errors only: no performance traces, profiles, logs or metrics.
    'traces_sample_rate' => null,
    'profiles_sample_rate' => null,
    'enable_logs' => false,
    'enable_metrics' => false,

    'breadcrumbs' => [
        // Every public property and update: on the submission form, an abstract and its authors.
        'livewire' => false,
        // Rate-limiter keys hold client IP addresses and an invitee's address.
        'cache' => false,
        // Outgoing requests include the heartbeat URL, which is a secret.
        'http_client_requests' => false,
        'sql_bindings' => false,
    ],

];
