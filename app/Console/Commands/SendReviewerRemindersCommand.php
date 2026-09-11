<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Reviews\SendReviewerReminders;
use App\Enums\ConferenceStatus;
use App\Models\Conference;
use Illuminate\Console\Command;
use Throwable;

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
        $failed = 0;

        Conference::query()
            ->where('status', ConferenceStatus::Reviewing->value)
            ->whereNotNull('review_deadline')
            ->orderBy('id')
            ->each(function (Conference $conference) use ($reminders, &$total, &$failed): void {
                // One conference must not be able to end the pass. automatic()
                // catches only a unique violation, and everything else it can
                // raise - a mail transport refusal, a relation the data lets be
                // null, a template a Plan 6 import left broken - escaped here
                // and abandoned the loop, so every conference AFTER this one in
                // id order silently got nothing. Hourly, for ever, with nothing
                // to see but a red scheduled command that named one conference.
                try {
                    $result = $reminders->automatic($conference);
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->error(sprintf('%s: %s', (string) $conference->name, $exception->getMessage()));

                    return;
                }

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

        if ($failed > 0) {
            // The rest of the pass ran, but a conference was skipped and that is
            // not a success: the scheduled run has to be visibly red, or the
            // only symptom is reviewers who quietly stop being reminded.
            $this->error($failed.' conference(s) were skipped.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
