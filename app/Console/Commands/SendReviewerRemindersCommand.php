<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Reviews\SendReviewerReminders;
use App\Enums\ConferenceStatus;
use App\Models\Conference;
use Illuminate\Console\Command;

/**
 * Spec 5.4 step 5. Runs hourly (routes/console.php) and decides nothing itself:
 * every rule lives in ReminderSchedule and SendReviewerReminders, which are
 * unit-tested without a console at all.
 *
 * Hourly rather than daily because spec section 10 gives every conference its
 * own timezone and one Schedule entry carries exactly one - see the Task 10
 * preamble.
 */
class SendReviewerRemindersCommand extends Command
{
    protected $signature = 'cass:reviewer-reminders';

    protected $description = 'Email reviewers with outstanding work at 7, 3 and 1 days before the review deadline, and once after it.';

    public function handle(SendReviewerReminders $reminders): int
    {
        $total = 0;

        Conference::query()
            ->where('status', ConferenceStatus::Reviewing->value)
            ->whereNotNull('review_deadline')
            ->orderBy('id')
            ->each(function (Conference $conference) use ($reminders, &$total): void {
                $result = $reminders->automatic($conference);

                if ($result['sent'] > 0) {
                    $total += $result['sent'];

                    $this->info(sprintf(
                        '%s: %d reminder(s) at the %s threshold.',
                        (string) $conference->name,
                        $result['sent'],
                        // `->`, not `?->`: the `??` already covers a null
                        // threshold, and a nullsafe fetch on the left of it is
                        // what PHPStan's nullsafe.neverNull rule refuses.
                        $result['threshold']->value ?? '-',
                    ));
                }
            });

        $this->info($total.' reminder(s) queued.');

        return self::SUCCESS;
    }
}
