<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\Conference;
use Illuminate\Support\Facades\DB;

/**
 * The `GPCC26-017` half of a reference (the `GPCC26` half is
 * App\Support\Submissions\ReferencePrefix, decided when the conference is
 * created).
 *
 * The locked read and the write it depends on are wrapped in a transaction
 * *here* rather than asked of the caller in a docblock. `lockForUpdate()`
 * outside a transaction is a no-op on MySQL - the row lock is released at
 * statement end - so two authors pressing Submit in the same second would draw
 * the same number and the second insert would hit the unique
 * (conference_id, reference) index. Under SubmitAbstract's transaction this
 * nests as a savepoint and the lock is held until the outer commit; called on
 * its own it opens a real transaction. SQLite ignores the lock clause entirely,
 * so no local test could ever catch a caller that forgot.
 */
class AllocateReference
{
    public function handle(Conference $conference): string
    {
        /** @var string $reference */
        $reference = DB::transaction(fn (): string => $this->allocate($conference));

        return $reference;
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

    private function allocate(Conference $conference): string
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
        // request does not read a stale counter off it - and sync the original
        // straight away so the copy is *clean*. A dirty convenience copy is a
        // trap: any later save() of that instance would write this number back
        // over a concurrent allocation and hand the next author a reference
        // that is already taken.
        $conference->setAttribute('submission_counter', $next);
        $conference->syncOriginalAttribute('submission_counter');

        return self::format($locked->referencePrefix(), $next);
    }
}
