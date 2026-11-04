<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Cache::put('cass:scheduler-heartbeat', now()->toIso8601String(), 3600);
});

it('passes when everything it checks is healthy', function () {
    artisan('cass:health')
        ->expectsOutputToContain('database')
        ->expectsOutputToContain('queue')
        ->expectsOutputToContain('scheduler')
        ->expectsOutputToContain('private disk')
        ->assertExitCode(0);
});

it('fails when the scheduler heartbeat is stale', function () {
    // The one thing nothing else notices. schedule:work dying under
    // supervisord means no reviewer reminders and no pruning, silently, for
    // as long as nobody looks.
    Cache::put('cass:scheduler-heartbeat', now()->subHour()->toIso8601String(), 3600);

    artisan('cass:health')
        ->expectsOutputToContain('scheduler')
        ->assertExitCode(1);
});

it('fails when the heartbeat has never been written', function () {
    Cache::forget('cass:scheduler-heartbeat');

    artisan('cass:health')->assertExitCode(1);
});

it('fails when a migration is pending', function () {
    // The host rule is that migrations are never run at boot, so "the image
    // deployed and nobody ran migrate" is a real and recurring state - and
    // every page that touches the new column 500s until somebody does.
    DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

    artisan('cass:health')
        ->expectsOutputToContain('migrations')
        ->assertExitCode(1);
});

it('fails when the private disk cannot be written', function () {
    // Not a fake: the real disk, pointed somewhere no directory can be made.
    // A full or unmounted cass-storage volume is how an abstract upload starts
    // failing at four in the morning before a deadline, and Storage::fake() in
    // beforeEach installs a manager instance that masks the real config
    // entirely - so the fake is undone here.
    //
    // A plain '/no/such/place' is not enough: a standard user can create it on
    // Windows and on a writable root. An existing FILE as the disk root cannot
    // be turned into a directory on any OS.
    config()->set('filesystems.disks.local.root', base_path('composer.json'));
    Storage::forgetDisk('local');

    artisan('cass:health')
        ->expectsOutputToContain('private disk')
        ->assertExitCode(1);
});

it('fails in production when the mailer delivers nowhere', function () {
    // checkMail() short-circuits outside production and the suite runs as
    // `testing`, so every one of its failing branches was unreachable - for the
    // command whose stated purpose includes "a misconfigured mailer queues
    // perfectly and delivers nothing".
    app()->detectEnvironment(fn (): string => 'production');

    config()->set('mail.default', 'array');

    artisan('cass:health')
        ->expectsOutputToContain('mail')
        ->assertExitCode(1);

    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp.host', '');

    artisan('cass:health')
        ->expectsOutputToContain('mail')
        ->assertExitCode(1);

    config()->set('mail.mailers.smtp.host', 'mailpit');

    artisan('cass:health')->assertExitCode(0);
});

it('schedules the heartbeat cass:health reads', function () {
    // beforeEach puts the key into the cache by hand, so deleting the
    // Schedule::call() block in routes/console.php left this file green while
    // production wrote no heartbeat at all and cass:health reported a dead
    // scheduler for ever.
    Cache::forget('cass:scheduler-heartbeat');

    // ->name() sets the event description, which is how a closure event can be
    // found at all.
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => $event->description === 'cass-scheduler-heartbeat');

    expect($event)->not->toBeNull();

    $event?->run(app());

    expect(Cache::get('cass:scheduler-heartbeat'))->toBeString();

    artisan('cass:health')->assertExitCode(0);
});

it('warns rather than fails on a queue with a backlog', function () {
    // A backlog is a worker that is slow or a burst that is large; a backlog
    // whose oldest job is hours old is a worker that is dead. Only the second
    // is a failure, because the first is what a decision-email run looks like.
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'available_at' => now()->subMinutes(2)->getTimestamp(),
        'created_at' => now()->subMinutes(2)->getTimestamp(),
    ]);

    artisan('cass:health')->assertExitCode(0);

    DB::table('jobs')->update([
        'available_at' => now()->subHours(3)->getTimestamp(),
        'created_at' => now()->subHours(3)->getTimestamp(),
    ]);

    artisan('cass:health')
        ->expectsOutputToContain('queue')
        ->assertExitCode(1);
});

it('pings the heartbeat URL once, with a GET, when every check passed, and prints one JSON line', function () {
    Http::fake();
    config()->set('cass.heartbeat_url', 'https://hc-ping.example/test-check');

    expect(Artisan::call('cass:health', ['--json' => true, '--ping' => true]))->toBe(0);
    $lines = preg_split('/\R/', trim(Artisan::output())) ?: [];
    // The scheduled run is `--json --ping`: the ping adds nothing to stdout.
    expect($lines)->toHaveCount(1)
        ->and(json_decode($lines[0], true))->toBeArray()->toHaveCount(6);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'https://hc-ping.example/test-check');
});

it('pings nothing without --ping or without a URL', function (array $options, ?string $url) {
    Http::fake();
    config()->set('cass.heartbeat_url', $url);

    artisan('cass:health', $options)->assertExitCode(0);
    Http::assertNothingSent();
})->with([
    'without --ping' => [[], 'https://hc-ping.example/test-check'],
    'without a URL' => [['--ping' => true], null],
    'with an empty URL, as the compose file passes it unset' => [['--ping' => true], ''],
]);

it('pings nothing when a check failed', function () {
    // The point of a dead-man heartbeat: a red row is silence, which the service turns into an alert.
    Http::fake();
    config()->set('cass.heartbeat_url', 'https://hc-ping.example/test-check');
    Cache::put('cass:scheduler-heartbeat', now()->subHour()->toIso8601String(), 3600);

    artisan('cass:health', ['--ping' => true])->assertExitCode(1);
    Http::assertNothingSent();
});

it('keeps the exit code when the ping fails, and logs a warning without the URL', function () {
    // Http::fake() merges stub sets, so only the failure is faked, in Guzzle's words (its URL has no query).
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: hc-ping.example (see https://curl.se/libcurl/c/libcurl-errors.html) for https://hc-ping.example/test-check'));
    Sleep::fake();
    Log::spy();
    config()->set('cass.heartbeat_url', 'https://hc-ping.example/test-check?rid=1');

    artisan('cass:health', ['--ping' => true])->assertExitCode(0);
    // Exact: json_encode() escapes '/', so a substring search of it finds no URL even when one leaks.
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context === ['error' => 'cURL error 6: Could not resolve host: hc-ping.example (see [url]) for [url]']
            && ! str_contains($message, 'test-check'),
    );
});

it('keeps the exit code when the heartbeat service answers with an error', function () {
    // retry() throws a RequestException after the last 503; the catch takes every Throwable.
    Http::fake(fn () => Http::response('', 503));
    Sleep::fake();
    Log::spy();
    config()->set('cass.heartbeat_url', 'https://hc-ping.example/test-check');
    artisan('cass:health', ['--ping' => true])->assertExitCode(0);
    Log::shouldHaveReceived('warning')->once();
});

it('schedules cass:health --json --ping every hour, its output appended to a log file', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'cass:health'));
    expect($event)->not->toBeNull()
        ->and((string) $event?->command)->toContain('--json')->toContain('--ping')
        ->and($event?->expression)->toBe('0 * * * *')
        ->and($event?->output)->toBe(storage_path('logs/cass-health.log'))->and($event?->shouldAppendOutput)->toBeTrue();
});
