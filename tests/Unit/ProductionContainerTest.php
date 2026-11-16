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

it('passes the former application keys to the application container', function () {
    // Without APP_PREVIOUS_KEYS an APP_KEY rotation leaves every stored second factor unreadable.
    $compose = (string) file_get_contents(base_path('docker-compose.production.yml'));
    preg_match('/^  app:\R(.*?)^  mysql:/ms', $compose, $app);

    expect($app[1] ?? '')->toMatch('/^ {6}APP_PREVIOUS_KEYS: \$\{APP_PREVIOUS_KEYS:-\}$/m');
});

it('leaves the release and the deployed commit out of the compose file', function () {
    // Coolify creates an application variable for every ${...} this file names, empty unless set, and adds its
    // own SOURCE_COMMIT, the deployed commit, to the container only while the application has no variable of
    // that name; a SENTRY_RELEASE line here would override the one set in Coolify. config/sentry.php reads
    // SENTRY_RELEASE when it is set and the commit otherwise.
    $compose = (string) file_get_contents(base_path('docker-compose.production.yml'));

    expect($compose)
        ->not->toContain('SOURCE_COMMIT')
        ->not->toContain('SENTRY_RELEASE');
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
 * config/sentry.php read again under the given SENTRY_RELEASE and SOURCE_COMMIT, as the application reads it at
 * boot; null leaves a variable unset. Coolify sets SOURCE_COMMIT in the container to the deployed commit, or to
 * 'unknown' when the deployment has none. An empty variable is one env() returns as '' rather than null.
 */
$releaseUnder = function (?string $release, ?string $commit): mixed {
    foreach (['SENTRY_RELEASE' => $release, 'SOURCE_COMMIT' => $commit] as $name => $value) {
        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            continue;
        }

        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }

    config(['sentry' => require config_path('sentry.php')]);

    return config('sentry.release');
};

afterEach(function () {
    foreach (['SENTRY_RELEASE', 'SOURCE_COMMIT'] as $name) {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }
});

it('names the release after SENTRY_RELEASE when it is set, whatever the commit', function () use ($releaseUnder) {
    expect($releaseUnder('cass-2026.11', '0123456789abcdef0123456789abcdef01234567'))->toBe('cass-2026.11');
});

it('names the release after the deployed commit when SENTRY_RELEASE is empty or unset', function (?string $release) use ($releaseUnder) {
    $commit = '0123456789abcdef0123456789abcdef01234567';

    expect($releaseUnder($release, $commit))->toBe($commit);
})->with(['empty' => [''], 'unset' => [null]]);

it('sends no release when the deployment has no commit to report', function (?string $release) use ($releaseUnder) {
    // Every such deployment would share the one release 'unknown'.
    expect($releaseUnder($release, 'unknown'))->toBeNull();
})->with(['empty' => [''], 'unset' => [null]]);

it('sends no release when neither variable holds one', function (?string $release, ?string $commit) use ($releaseUnder) {
    expect($releaseUnder($release, $commit))->toBeNull();
})->with([
    'both empty' => ['', ''],
    'both unset' => [null, null],
    'release empty, commit unset' => ['', null],
    'release unset, commit empty' => [null, ''],
]);
