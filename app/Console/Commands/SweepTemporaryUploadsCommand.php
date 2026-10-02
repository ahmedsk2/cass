<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Submissions\SweepTemporaryUploads;
use Illuminate\Console\Command;

/**
 * Hourly (routes/console.php). Also the command to run by hand when
 * `cass:health` reports the private disk full: the abandoned uploads are the
 * one thing on that volume that is always safe to delete.
 */
class SweepTemporaryUploadsCommand extends Command
{
    protected $signature = 'cass:sweep-uploads';

    protected $description = 'Delete Livewire temporary uploads more than a day old, which Livewire itself only sweeps when the next upload arrives.';

    public function handle(SweepTemporaryUploads $sweep): int
    {
        $this->info(sprintf(
            'Deleted %d temporary upload file(s) older than %d hours.',
            $sweep->handle(),
            SweepTemporaryUploads::MAX_AGE_HOURS,
        ));

        return self::SUCCESS;
    }
}
