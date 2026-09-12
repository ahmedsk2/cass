<?php

declare(strict_types=1);

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
    // Not a fake: the real disk, pointed at a directory that does not exist.
    // A full or unmounted cass-storage volume is how an abstract upload starts
    // failing at four in the morning before a deadline.
    config()->set('filesystems.disks.local.root', '/no/such/place');

    artisan('cass:health')
        ->expectsOutputToContain('private disk')
        ->assertExitCode(1);
})->skip('Enable once the check is written; Storage::fake in beforeEach has to be undone for this case.');

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
