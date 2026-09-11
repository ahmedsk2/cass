<?php

declare(strict_types=1);

use App\Models\ShortLink;
use App\Models\ShortLinkVisit;
use Illuminate\Console\Scheduling\Schedule;

it('prunes visit rows older than the retention window', function () {
    config(['cass.short_link_visit_retention_days' => 90]);

    $link = ShortLink::factory()->create();
    $link->visits()->create(['visited_at' => now()->subDays(120)]);
    $link->visits()->create(['visited_at' => now()->subDays(89)]);

    $this->artisan('model:prune', ['--model' => [ShortLinkVisit::class]])->assertExitCode(0);

    expect(ShortLinkVisit::count())->toBe(1)
        ->and(ShortLinkVisit::first()?->visited_at?->lessThan(now()->subDays(80)))->toBeTrue();
});

it('schedules the nightly prune so the window cannot grow forever', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn (object $event): string => (string) ($event->command ?? ''));

    expect($commands->contains(fn (string $command): bool => str_contains($command, 'model:prune')))->toBeTrue();
});
