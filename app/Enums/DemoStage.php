<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far `cass:demo-seed` drives the loop before it hands the conference back
 * to the owner.
 *
 * The three cases are cumulative, which is what `reaches()` expresses: `decided`
 * is `reviewing` plus decisions, and `reviewing` is `open` plus a closed
 * submission window and a round of reviews. The owner performs the rest by hand
 * on the live site - that is the point of the demo - so each stage deliberately
 * stops one click short of the next irreversible thing: `open` stops before
 * closing submissions, `reviewing` stops before deciding, and `decided` stops
 * before sending the letters and before `Reviewing -> Decided`.
 */
enum DemoStage: string
{
    case Open = 'open';
    case Reviewing = 'reviewing';
    case Decided = 'decided';

    /** True when this stage includes everything $stage does. */
    public function reaches(self $stage): bool
    {
        return $this->rank() >= $stage->rank();
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $stage): string => $stage->value, self::cases());
    }

    private function rank(): int
    {
        return match ($this) {
            self::Open => 0,
            self::Reviewing => 1,
            self::Decided => 2,
        };
    }
}
