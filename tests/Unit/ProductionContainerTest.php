<?php

declare(strict_types=1);

/**
 * docker/php.ini and docker-compose.production.yml are not PHP, so nothing else in this suite reads
 * them, and each setting below fails quietly when it goes: nothing breaks until a trace is logged or
 * APP_KEY is rotated. These cases read the files as text; the CI `smoke` job is what proves the image.
 */
it('keeps argument values out of every trace the image records', function () {
    // The Dockerfile copies this file into PHP's conf.d, which php-fpm and the CLI (the queue worker
    // and the scheduler) both read. Off, a logged trace quotes the start of each string argument:
    // an abstract, an author's address. The tracker scrubs its own events; the server log has no scrubber.
    $ini = parse_ini_file(base_path('docker/php.ini'), false, INI_SCANNER_TYPED);

    expect($ini)->toBeArray()
        ->and($ini['zend.exception_ignore_args'] ?? null)->toBeTrue();
});

it('passes the former application keys and the deployed commit to the application container', function () {
    // A variable set in Coolify reaches the container only through ${...} in this file. Without
    // APP_PREVIOUS_KEYS an APP_KEY rotation leaves every stored second factor unreadable.
    $compose = (string) file_get_contents(base_path('docker-compose.production.yml'));
    preg_match('/^  app:\R(.*?)^  mysql:/ms', $compose, $app);

    expect($app[1] ?? '')
        ->toMatch('/^ {6}APP_PREVIOUS_KEYS: \$\{APP_PREVIOUS_KEYS:-\}$/m')
        ->toMatch('/^ {6}SENTRY_RELEASE: \$\{SOURCE_COMMIT:-\}$/m');
});
