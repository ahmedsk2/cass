<?php

declare(strict_types=1);

namespace App\Support\Purge;

/**
 * Reading helpers over the array<string, int> the two purge actions return.
 * Static, and deliberately not a value object: the actions' return type is
 * already the contract cass:demo-reset and DemoResetTest depend on, and
 * changing it to a class would be a breaking change for a formatting
 * convenience.
 */
final class PurgeCounts
{
    /**
     * Only the tables that actually had rows, biggest first - what the modal
     * prints. A list of eighteen zeroes is not a confirmation.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    public static function significant(array $counts): array
    {
        $rows = array_filter($counts, static fn (int $count): bool => $count > 0);
        arsort($rows);

        return $rows;
    }

    /** @param array<string, int> $counts */
    public static function total(array $counts): int
    {
        return array_sum($counts);
    }
}
