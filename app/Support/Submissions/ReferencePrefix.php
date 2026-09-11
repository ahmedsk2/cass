<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use Carbon\CarbonInterface;

/**
 * The letters in front of a reference number: GPCC26 for "Gulf Pediatric
 * Critical Care" starting in 2026. Printed on badges and read out loud, so the
 * rules are deliberately dull - initials of the words in the name that a human
 * would read out, with the joining words (a, an, and, at, for, in, of, on, the,
 * to, with) dropped, capped at six letters, plus the two-digit year of the
 * first day.
 *
 * Pure, so it is unit-testable without a database, and separate from
 * AllocateReference (Task 2) which owns the counter and the final format.
 */
final class ReferencePrefix
{
    public const MAX_LETTERS = 6;

    /** Used when a name has no usable A-Z letters at all. */
    public const FALLBACK = 'CASS';

    /** Joining words a human would not read out as an initial. English only. */
    private const SKIP = ['a', 'an', 'and', 'at', 'for', 'in', 'of', 'on', 'the', 'to', 'with'];

    public static function derive(string $name, ?CarbonInterface $startsAt): string
    {
        return self::letters($name).($startsAt?->format('y') ?? date('y'));
    }

    private static function letters(string $name): string
    {
        // Transliteration is not attempted: a name with no latin letters gets
        // the fallback stem rather than a machine-made approximation that the
        // organizer never chose. The prefix is editable in the form.
        $words = preg_split('/[^A-Za-z]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // "The Annual Meeting of the Saudi Society" is AMSS, not TAMOTS: a
        // joining word contributes nothing anyone would recognise on a badge.
        $significant = array_values(array_filter(
            $words,
            static fn (string $word): bool => ! in_array(strtolower($word), self::SKIP, true),
        ));

        // A name made only of joining words keeps them rather than vanishing.
        if ($significant === []) {
            $significant = $words;
        }

        $initials = '';
        foreach ($significant as $word) {
            $initials .= strtoupper($word[0]);
        }

        if (strlen($initials) >= 2) {
            return substr($initials, 0, self::MAX_LETTERS);
        }

        // One word (or one usable letter) makes a prefix nobody recognises, so
        // use the start of the word itself: "Symposium" becomes SYMP, not S.
        if ($significant !== []) {
            return strtoupper(substr($significant[0], 0, 4));
        }

        return self::FALLBACK;
    }
}
