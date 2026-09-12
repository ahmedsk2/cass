<?php

declare(strict_types=1);

use App\Models\EmailLog;
use Illuminate\Database\Eloquent\MassPrunable;

use function Pest\Laravel\artisan;

it('prunes email logs past the retention window and keeps the rest', function () {
    config()->set('cass.email_log_retention_days', 30);

    // The factory, not `new EmailLog` + forceFill: email_logs.mailable and
    // email_logs.subject are NOT NULL with no default
    // (2026_09_11_001500_create_email_logs_table.php:29-31), so a hand-built
    // row never gets as far as the prune.
    $old = EmailLog::factory()->create();
    $old->forceFill(['created_at' => now()->subDays(31)])->save();

    $recent = EmailLog::factory()->create();

    // routes/console.php:19 already runs model:prune daily, so the trait is
    // the whole change - no scheduler edit.
    artisan('model:prune')->assertExitCode(0);

    expect(EmailLog::query()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(EmailLog::query()->whereKey($recent->getKey())->exists())->toBeTrue();
});

it('treats an empty or zero retention as one day, never as "prune everything"', function () {
    config()->set('cass.email_log_retention_days', 0);

    $old = EmailLog::factory()->create();
    $old->forceFill(['created_at' => now()->subDays(3)])->save();

    $today = EmailLog::factory()->create();

    artisan('model:prune')->assertExitCode(0);

    // max(1, …), the ShortLinkVisit shape: an empty CASS_ variable is how a
    // knob gets disabled, and "disabled" must not mean "truncate the table".
    // A zero becomes one day - it does not become zero days.
    expect(EmailLog::query()->whereKey($today->getKey())->exists())->toBeTrue()
        ->and(EmailLog::query()->whereKey($old->getKey())->exists())->toBeFalse();
});

it('uses the mass-prunable trait, which fires no model events', function () {
    // EmailLog has a `creating` hook and no deleting hook, so a mass delete is
    // safe; Prunable (not Mass) would load every row to fire events nobody
    // listens for.
    expect(in_array(MassPrunable::class, class_uses_recursive(EmailLog::class), true))->toBeTrue();
});
