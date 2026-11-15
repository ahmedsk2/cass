<?php

declare(strict_types=1);

/**
 * docker/php.ini and docker-compose.production.yml are not PHP, so nothing else in this suite reads
 * them, and each setting below fails quietly when it goes: nothing breaks until a trace is logged or
 * APP_KEY is rotated. The cases that read a file as text prove what the file says, not that a running
 * container applies those settings: nothing in CI reads them back from the image.
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

it('passes the disk row floors to the application container, each with its default', function () {
    // Without these lines the two settings cannot change in production. The defaults are explicit because
    // ${VAR:-} would hand env() an empty string, which (int) reads as 0, and 0 turns that floor off.
    $compose = (string) file_get_contents(base_path('docker-compose.production.yml'));
    preg_match('/^  app:\R(.*?)^  mysql:/ms', $compose, $app);

    expect($app[1] ?? '')
        ->toMatch('/^ {6}CASS_HEALTH_DISK_MIN_PERCENT: \$\{CASS_HEALTH_DISK_MIN_PERCENT:-15\}$/m')
        ->toMatch('/^ {6}CASS_HEALTH_DISK_MIN_GIB: \$\{CASS_HEALTH_DISK_MIN_GIB:-2\}$/m');
});

/**
 * config/sentry.php read again under the given SENTRY_RELEASE, as the application reads it at boot. The compose
 * file sets the variable from SOURCE_COMMIT, so a deploy where Coolify passes no commit sets it to an empty
 * string, which env() returns as '' rather than null.
 */
$releaseUnder = function (string $release): mixed {
    $_SERVER['SENTRY_RELEASE'] = $_ENV['SENTRY_RELEASE'] = $release;
    putenv("SENTRY_RELEASE={$release}");
    config(['sentry' => require config_path('sentry.php')]);

    return config('sentry.release');
};

afterEach(function () {
    unset($_SERVER['SENTRY_RELEASE'], $_ENV['SENTRY_RELEASE']);
    putenv('SENTRY_RELEASE');
});

it('sends no release when the deployed commit arrives empty', function () use ($releaseUnder) {
    expect($releaseUnder(''))->toBeNull();
});

it('passes the deployed commit through as the release', function () use ($releaseUnder) {
    $commit = '0123456789abcdef0123456789abcdef01234567';

    expect($releaseUnder($commit))->toBe($commit);
});
