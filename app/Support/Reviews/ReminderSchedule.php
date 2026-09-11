<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\ReminderThreshold;
use App\Models\Conference;
use Carbon\CarbonInterface;

/**
 * Spec 5.4 step 5's thresholds, computed in the conference's own timezone
 * (spec section 10).
 *
 * Carbon 3 changed `diffInDays`: it is signed by default and returns a float
 * (fact 4). `absolute: false` is passed explicitly and the result is rounded,
 * because a day across a DST boundary is 23 or 25 hours long and would
 * otherwise floor to the day before.
 */
final class ReminderSchedule
{
    /** Whole local days from today to the deadline; negative once it has passed. */
    public static function daysLeft(Conference $conference, ?CarbonInterface $now = null): ?int
    {
        $deadline = $conference->review_deadline;

        if ($deadline === null) {
            return null;
        }

        $zone = (string) $conference->timezone;
        $today = ($now ?? now())->copy()->setTimezone($zone)->startOfDay();
        $due = $deadline->copy()->setTimezone($zone)->startOfDay();

        return (int) round($today->diffInDays($due, absolute: false));
    }

    /**
     * The threshold that applies *now*, or null.
     *
     * `<=` rather than `===` on purpose: a conference whose reviewers were
     * invited five days before the deadline gets the seven-day reminder five
     * days out - late, but sent - instead of nothing because the exact day was
     * missed. Three emails never land in one hour, because the caller writes a
     * reviewer_reminders row for whatever it sent and the next run asks again.
     */
    public static function due(Conference $conference, ?CarbonInterface $now = null): ?ReminderThreshold
    {
        $deadline = $conference->review_deadline;

        if ($deadline === null) {
            return null;
        }

        if ($deadline->isBefore($now ?? now())) {
            return ReminderThreshold::Overdue;
        }

        $days = self::daysLeft($conference, $now);

        return match (true) {
            $days === null => null,
            $days <= 1 => ReminderThreshold::Days1,
            $days <= 3 => ReminderThreshold::Days3,
            $days <= 7 => ReminderThreshold::Days7,
            default => null,
        };
    }

    /**
     * The command runs hourly (one Schedule entry cannot carry every
     * conference's timezone), so this is what makes it behave like "daily at
     * 07:00 local": the first run at or after the local send hour does the
     * work, and the reviewer_reminders unique key stops the rest of the day's
     * runs repeating it.
     */
    public static function isSendHour(Conference $conference, ?CarbonInterface $now = null): bool
    {
        if ($conference->review_deadline === null) {
            return false;
        }

        $hour = (int) ($now ?? now())->copy()->setTimezone((string) $conference->timezone)->hour;

        return $hour >= (int) config('cass.reminders.send_hour');
    }
}
