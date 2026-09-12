<?php

declare(strict_types=1);

use App\Console\Commands\SendReviewerRemindersCommand;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('queue:prune-failed --hours=720')->daily();

// Prunable models: ShortLinkVisit rows outside the retention window in
// config/cass.php. /q/{code} is unauthenticated, so that table only stops
// growing if something deletes from it.
Schedule::command('model:prune')->daily();

// Spec 5.4 step 5. Hourly, not daily: every conference has its own timezone
// (spec section 10) and a Schedule entry carries one, so the command asks each
// conference whether it is past its local send hour.
Schedule::command(SendReviewerRemindersCommand::class)
    ->hourly()
    // withoutOverlapping() is safe on a command event - it is only a closure
    // event that needs a ->name() first (fact 3).
    //
    // 55 minutes, NOT the 1440-minute default
    // (Illuminate\Console\Scheduling\ManagesAttributes::withoutOverlapping,
    // :180, and CacheEventMutex::create, :43, which multiplies it by 60). This
    // command finishes in seconds. A container killed mid-run - an OOM kill or
    // `docker kill`, which skip the signal handler that would have released the
    // mutex - must not then suppress every reviewer reminder on the platform for
    // a whole day. In production the lock is a durable row in `cache_locks`
    // (docker-compose.production.yml sets CACHE_STORE=database), so nothing
    // clears it on restart either. At 55 minutes the next hourly run takes over.
    ->withoutOverlapping(55);

// The scheduler has no "last run" anywhere in Laravel, so cass:health cannot
// answer "is schedule:work alive" without one. Five minutes, a one-hour TTL:
// a heartbeat older than fifteen minutes is a scheduler that has been dead
// long enough to have missed something, and in production the cache store is
// the database (CACHE_STORE=database), so this is one small write.
//
// ->name() BEFORE ->withoutOverlapping(): a closure event has no command
// string to key its mutex on and throws RuntimeException without one (the
// comment on the reminder schedule above records the same fact from the other
// side, where the name is optional).
Schedule::call(static function (): void {
    Cache::put('cass:scheduler-heartbeat', CarbonImmutable::now()->toIso8601String(), 3600);
})->everyFiveMinutes()->name('cass-scheduler-heartbeat')->withoutOverlapping();
