<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use Carbon\CarbonImmutable;
use League\Flysystem\FilesystemException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/**
 * Livewire's own cleanup of its temporary upload directory, on a clock instead
 * of on the next upload.
 *
 * Every upload lands in storage/app/private/livewire-tmp first, and nothing
 * takes it out again: StoreSubmissionFile COPIES the bytes onto the private
 * disk with writeStream(), so a submitted abstract leaves its temporary copy
 * behind exactly as an abandoned one does. WithFileUploads::_finishUpload()
 * runs cleanupOldUploads() only when the NEXT upload finishes, so after a
 * deadline rush all of it stays inside the cass-storage volume until somebody
 * uploads something again. This deletes exactly what that method would: every
 * file under FileUploadConfiguration::path() on
 * FileUploadConfiguration::storage(), last modified more than a day ago. The
 * same disk, the same directory and the same age, read from the same class, so
 * the two cannot drift apart however config/livewire.php changes.
 */
class SweepTemporaryUploads
{
    /** WithFileUploads::cleanupOldUploads() uses now()->subDay(). */
    public const MAX_AGE_HOURS = 24;

    /**
     * @return int the number of files deleted - an upload is two, the file and
     *             its `.json` sidecar
     */
    public function handle(): int
    {
        $disk = FileUploadConfiguration::storage();
        $cutoff = CarbonImmutable::now()->subHours(self::MAX_AGE_HOURS)->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles(FileUploadConfiguration::path()) as $path) {
            try {
                if ($disk->lastModified($path) >= $cutoff) {
                    continue;
                }
            } catch (FilesystemException) {
                // Gone between the listing and the stat: Livewire's own
                // cleanup, running inside an author's upload request, got to
                // it first. Its source says the same race happens to it.
                continue;
            }

            if ($disk->delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
