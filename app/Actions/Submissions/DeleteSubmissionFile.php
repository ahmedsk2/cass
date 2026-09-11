<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\SubmissionFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The object first, the row second, both in one transaction: a row without an
 * object is a broken download link the organizer cannot explain, while an
 * object without a row is an orphan nothing will ever reference (content
 * addressing means every object belongs to exactly one row - see
 * StoreSubmissionFile) and which the Plan 6 purge can sweep.
 */
class DeleteSubmissionFile
{
    public function handle(SubmissionFile $file): void
    {
        DB::transaction(function () use ($file): void {
            Storage::disk('local')->delete((string) $file->path);
            $file->delete();
        });
    }
}
