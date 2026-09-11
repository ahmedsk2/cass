<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Spec 5.4 step 5: "the scheduler emails reviewers with outstanding work at 7,
 * 3 and 1 days before the review deadline, and once after it passes".
 *
 * The order of `cases()` is the order they fire in, and
 * App\Support\Reviews\ReminderSchedule::due() walks it - so a conference whose
 * reminders were switched on late fires the *latest* applicable threshold only,
 * rather than three emails in one hour.
 */
enum ReminderThreshold: string implements HasLabel
{
    case Days7 = 'days_7';
    case Days3 = 'days_3';
    case Days1 = 'days_1';
    case Overdue = 'overdue';

    public function getLabel(): string
    {
        return match ($this) {
            self::Days7 => '7 days before the deadline',
            self::Days3 => '3 days before the deadline',
            self::Days1 => '1 day before the deadline',
            self::Overdue => 'After the deadline',
        };
    }

    /** Null for the overdue threshold, which is defined by the deadline having passed. */
    public function daysBefore(): ?int
    {
        return match ($this) {
            self::Days7 => 7,
            self::Days3 => 3,
            self::Days1 => 1,
            self::Overdue => null,
        };
    }

    public function templateKey(): EmailTemplateKey
    {
        return $this === self::Overdue
            ? EmailTemplateKey::ReviewerOverdue
            : EmailTemplateKey::ReviewerReminder;
    }
}
