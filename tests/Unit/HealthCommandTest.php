<?php

declare(strict_types=1);

use App\Contracts\DiskSpace;
use App\Support\Monitoring\FakeDiskSpace;
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
    // A roomy disk, so that the free space of the machine running the suite
    // decides no case; the disk-row cases install their own reading.
    app()->instance(DiskSpace::class, FakeDiskSpace::reporting(60 * 1024 ** 3, 100 * 1024 ** 3));
});

/**
 * The rows of one `cass:health --json` run, keyed by name.
 *
 * @return array<string, array{name: string, ok: bool, detail: string}>
 */
function healthRows(string $output): array
{
    return collect(json_decode(trim($output), true))->keyBy('name')->all();
}

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

it('reports the free space of the filesystem holding the private disk', function () {
    $disk = FakeDiskSpace::reporting(12.25 * 1024 ** 3, 45 * 1024 ** 3);
    app()->instance(DiskSpace::class, $disk);

    expect(Artisan::call('cass:health'))->toBe(0)
        ->and(Artisan::output())->toContain('12.2 GiB free of 45.0 GiB (27%)')
        ->and($disk->measuredPaths())->toBe([Storage::disk('local')->path('')]);
});

it('fails below either floor, whichever a filling disk reaches first, and names the floor', function (float $freeGib, float $totalGib, string $detail) {
    app()->instance(DiskSpace::class, FakeDiskSpace::reporting($freeGib * 1024 ** 3, $totalGib * 1024 ** 3));

    expect(Artisan::call('cass:health', ['--json' => true]))->toBe(1)
        ->and(healthRows(Artisan::output())['disk'])->toBe(['name' => 'disk', 'ok' => false, 'detail' => $detail]);
})->with([
    // 15% of 100 GiB is 15 GiB, far above 2 GiB: a large disk crosses the share first.
    'a large disk under 15% but over 2 GiB' => [10, 100, '10.0 GiB free of 100.0 GiB (10%) - below the 15% minimum'],
    // 15% of 10 GiB is 1.5 GiB, under 2 GiB: a small disk crosses the absolute floor first.
    'a small disk under 2 GiB but over 15%' => [1.75, 10, '1.7 GiB free of 10.0 GiB (17%) - below the 2 GiB minimum'],
    'a disk under both' => [1, 100, '1.0 GiB free of 100.0 GiB (1%) - below the 15% minimum and the 2 GiB minimum'],
    // Rounded to the nearest, these two would print 15% and 2.0 GiB beside "below".
    'just under 15%, printed as 14%' => [14.75, 100, '14.7 GiB free of 100.0 GiB (14%) - below the 15% minimum'],
    'just under 2 GiB, printed as 1.9' => [1.96875, 10, '1.9 GiB free of 10.0 GiB (19%) - below the 2 GiB minimum'],
]);

it('passes at exactly either floor', function (float $freeGib, float $totalGib, string $detail) {
    app()->instance(DiskSpace::class, FakeDiskSpace::reporting($freeGib * 1024 ** 3, $totalGib * 1024 ** 3));

    expect(Artisan::call('cass:health', ['--json' => true]))->toBe(0)
        ->and(healthRows(Artisan::output())['disk'])->toBe(['name' => 'disk', 'ok' => true, 'detail' => $detail]);
})->with([
    'exactly 15% of a large disk' => [15, 100, '15.0 GiB free of 100.0 GiB (15%)'],
    'exactly 2 GiB of a small disk' => [2, 10, '2.0 GiB free of 10.0 GiB (20%)'],
]);

it('reads both floors from configuration', function () {
    app()->instance(DiskSpace::class, FakeDiskSpace::reporting(25 * 1024 ** 3, 100 * 1024 ** 3));

    expect(Artisan::call('cass:health'))->toBe(0);

    config()->set('cass.health.disk_min_percent', 30);

    expect(Artisan::call('cass:health', ['--json' => true]))->toBe(1)
        ->and(healthRows(Artisan::output())['disk']['detail'])->toBe('25.0 GiB free of 100.0 GiB (25%) - below the 30% minimum');

    config()->set('cass.health.disk_min_percent', 15);
    config()->set('cass.health.disk_min_gib', 30);

    expect(Artisan::call('cass:health', ['--json' => true]))->toBe(1)
        ->and(healthRows(Artisan::output())['disk']['detail'])->toBe('25.0 GiB free of 100.0 GiB (25%) - below the 30 GiB minimum');
});

it('turns a floor off at 0, as the configuration documents', function (string $floor, float $freeGib, float $totalGib) {
    // Each reading is red at the defaults, on the floor set to 0 here and on that floor alone.
    config()->set("cass.health.{$floor}", 0);
    app()->instance(DiskSpace::class, FakeDiskSpace::reporting($freeGib * 1024 ** 3, $totalGib * 1024 ** 3));

    expect(Artisan::call('cass:health', ['--json' => true]))->toBe(0)
        ->and(healthRows(Artisan::output())['disk']['ok'])->toBeTrue();
})->with([
    'the percentage, on a large disk under 15% but over 2 GiB' => ['disk_min_percent', 10, 100],
    'the GiB, on a small disk under 2 GiB but over 15%' => ['disk_min_gib', 1.75, 10],
]);

it('fails, naming the path, when the free space cannot be measured', function (FakeDiskSpace $disk) {
    app()->instance(DiskSpace::class, $disk);

    expect(Artisan::call('cass:health', ['--json' => true]))->toBe(1)
        ->and(healthRows(Artisan::output())['disk'])->toBe([
            'name' => 'disk',
            'ok' => false,
            'detail' => 'could not measure the free space at ['.Storage::disk('local')->path('').']',
        ]);
})->with([
    'no reading at all' => fn (): FakeDiskSpace => FakeDiskSpace::failing(),
    // The contract promises bytes, not a size above zero, and nothing is a share of nothing.
    'a reading with no size' => fn (): FakeDiskSpace => FakeDiskSpace::reporting(0, 0),
]);

it('prints the disk row in --json after the private disk row, in the shape of every other row', function () {
    app()->instance(DiskSpace::class, FakeDiskSpace::reporting(12.25 * 1024 ** 3, 45 * 1024 ** 3));

    expect(Artisan::call('cass:health', ['--json' => true]))->toBe(0);
    $rows = json_decode(trim(Artisan::output()), true);

    expect(array_column($rows, 'name'))->toBe(['database', 'migrations', 'queue', 'scheduler', 'private disk', 'disk', 'mail'])
        ->and($rows[5])->toBe(['name' => 'disk', 'ok' => true, 'detail' => '12.2 GiB free of 45.0 GiB (27%)']);
});

it('measures the real filesystem through the container binding', function () {
    // Every other case runs on a fake so that the machine's own free space
    // decides nothing; this one drops it. Green or red is then the machine's
    // business, so only the reading's shape is checked.
    app()->forgetInstance(DiskSpace::class);

    Artisan::call('cass:health', ['--json' => true]);

    expect(healthRows(Artisan::output())['disk']['detail'])->toMatch('/^\d+\.\d GiB free of \d+\.\d GiB \(\d+%\)/');
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
        ->and(json_decode($lines[0], true))->toBeArray()->toHaveCount(7);
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

it('pings nothing when the disk row is the only red one', function () {
    // A filling disk silences the heartbeat like any other red row, so the
    // alert arrives while the next uploads still fit.
    Http::fake();
    config()->set('cass.heartbeat_url', 'https://hc-ping.example/test-check');
    app()->instance(DiskSpace::class, FakeDiskSpace::reporting(1 * 1024 ** 3, 100 * 1024 ** 3));

    expect(Artisan::call('cass:health', ['--json' => true, '--ping' => true]))->toBe(1);
    $red = array_keys(array_filter(healthRows(Artisan::output()), fn (array $row): bool => $row['ok'] === false));

    expect($red)->toBe(['disk']);
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
