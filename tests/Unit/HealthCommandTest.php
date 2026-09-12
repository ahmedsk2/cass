<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
