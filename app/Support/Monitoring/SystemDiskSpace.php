<?php

declare(strict_types=1);

namespace App\Support\Monitoring;

use App\Contracts\DiskSpace;
use Throwable;

/**
 * disk_free_space() and disk_total_space(). The free figure is the space an
 * unprivileged process may still use, without the blocks a filesystem keeps
 * for root: the `app` user that writes every upload cannot use those either.
 */
final class SystemDiskSpace implements DiskSpace
{
    public function measure(string $path): ?array
    {
        try {
            $free = disk_free_space($path);
            $total = disk_total_space($path);
        } catch (Throwable) {
            // Both warn on a path they cannot read, and Laravel's error handler
            // turns the warning into an ErrorException. Without that handler
            // they return false, which the check below catches.
            return null;
        }

        // A filesystem that reports no size is no reading either: nothing can
        // be a share of it.
        if ($free === false || $total === false || $total <= 0) {
            return null;
        }

        return ['free' => $free, 'total' => $total];
    }
}
