<?php

declare(strict_types=1);

/**
 * Every variable the configuration reads is listed in .env.example, commented
 * out when it is optional, except the ones named below. Each omission is a
 * conscious one: a variable for a driver this application does not use, or a
 * framework internal that is never changed per environment.
 */
it('lists every variable the configuration reads in .env.example', function () {
    $unusedDrivers = [
        'REDIS_' => 'no Redis: cache, queue and sessions use the database',
        'MEMCACHED_' => 'no Memcached',
        'DYNAMODB_' => 'no DynamoDB',
        'SQS_' => 'no SQS queue',
        'BEANSTALKD_' => 'no Beanstalkd queue',
        'AWS_' => 'no S3 disk: uploads live on the private local disk',
        'PAPERTRAIL_' => 'logs go to stderr',
        'LOG_PAPERTRAIL_' => 'logs go to stderr',
        'LOG_SLACK_' => 'logs go to stderr',
        'SLACK_' => 'no Slack notifications',
        'POSTMARK_' => 'mail goes out over SMTP',
        'RESEND_' => 'mail goes out over SMTP',
    ];

    $frameworkOnly = [
        // PostgreSQL and SQL Server connections, never used.
        'DB_SSLMODE', 'DB_ENCRYPT', 'DB_TRUST_SERVER_CERTIFICATE',
        // Connection details the defaults already get right.
        'DB_URL', 'DB_SOCKET', 'DB_CHARSET', 'DB_COLLATION', 'DB_FOREIGN_KEYS',
        // Table and connection names of the database-backed cache, queue and sessions.
        'DB_CACHE_CONNECTION', 'DB_CACHE_TABLE', 'DB_CACHE_LOCK_CONNECTION', 'DB_CACHE_LOCK_TABLE',
        'DB_QUEUE', 'DB_QUEUE_CONNECTION', 'DB_QUEUE_TABLE', 'DB_QUEUE_RETRY_AFTER', 'QUEUE_FAILED_DRIVER',
        'SESSION_CONNECTION', 'SESSION_STORE', 'SESSION_TABLE',
        // The default guard, model and password broker.
        'AUTH_GUARD', 'AUTH_MODEL', 'AUTH_PASSWORD_BROKER', 'AUTH_PASSWORD_RESET_TOKEN_TABLE',
        // Log channels other than the ones .env.example already sets.
        'LOG_DAILY_DAYS', 'LOG_DEPRECATIONS_TRACE', 'LOG_STDERR_FORMATTER', 'LOG_SYSLOG_FACILITY',
        // Mail transports other than SMTP.
        'MAIL_URL', 'MAIL_LOG_CHANNEL', 'MAIL_SENDMAIL_PATH',
    ];

    $files = [...glob(config_path('*.php')), base_path('bootstrap/app.php')];

    $keys = collect($files)
        ->flatMap(function (string $file): array {
            preg_match_all('/env\(\s*[\'"]([A-Z0-9_]+)[\'"]/', (string) file_get_contents($file), $matches);

            return $matches[1];
        })
        ->unique()
        ->reject(fn (string $key): bool => in_array($key, $frameworkOnly, true)
            || collect(array_keys($unusedDrivers))->contains(fn (string $prefix): bool => str_starts_with($key, $prefix)))
        ->sort()
        ->values();

    $example = (string) file_get_contents(base_path('.env.example'));
    $missing = $keys->reject(fn (string $key): bool => preg_match('/^#?\s*'.$key.'=/m', $example) === 1);

    expect($missing->values()->all())->toBe([]);
});
