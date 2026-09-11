<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\SubmissionFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The row first and committed, the object second: a row without an object is a
 * broken download link the organizer cannot explain, while an object without a
 * row is an orphan nothing will ever reference (content addressing means every
 * object belongs to exactly one row - see StoreSubmissionFile) and which the
 * Plan 6 purge can sweep.
 *
 * Which is why the object is *not* deleted inside the transaction. Removing it
 * first and rolling the row back on a failed delete leaves exactly the state
 * this order exists to avoid - a live row whose bytes are gone - and no
 * rollback can put a deleted object back.
 */
class DeleteSubmissionFile
{
    public function handle(SubmissionFile $file): void
    {
        $path = (string) $file->path;

        DB::transaction(static function () use ($file): void {
            $file->delete();
        });

        // The bool is deliberately not checked: with the row gone there is
        // nothing left to refuse, and a disk that answered false has left an
        // orphan, which is the half of the pair this action chooses to risk.
        Storage::disk('local')->delete($path);
    }
}
