<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\Conference;

/**
 * The `GPCC26-017` half of a reference (the `GPCC26` half is
 * App\Support\Submissions\ReferencePrefix, decided when the conference is
 * created).
 *
 * **Call this inside a transaction.** `lockForUpdate()` outside one is a no-op
 * that releases the row lock immediately, and two authors pressing Submit in
 * the same second would then draw the same number and the second insert would
 * hit the unique (conference_id, reference) index. SubmitAbstract is the only
 * caller and it opens the transaction.
 */
class AllocateReference
{
    public function handle(Conference $conference): string
    {
        // Re-read rather than trust the instance handed in: the route binder
        // loaded it when the author opened the form, which can be an hour and
        // a hundred submissions ago.
        /** @var Conference $locked */
        $locked = Conference::query()
            ->whereKey($conference->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $next = (int) $locked->submission_counter + 1;

        $locked->forceFill(['submission_counter' => $next])->save();

        // Keep the caller's copy honest so a second allocation in the same
        // request does not read a stale counter off it.
        $conference->setAttribute('submission_counter', $next);

        return self::format($locked->referencePrefix(), $next);
    }

    /**
     * Three digits is what a printed badge and a spoken "oh one seven" want.
     * Past 999 the number simply grows: GPCC26-1000 is still sortable as text
     * within a conference because every reference in one conference has the
     * same prefix, and str_pad never truncates.
     */
    public static function format(string $prefix, int $number): string
    {
        return $prefix.'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }
}
