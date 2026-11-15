<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * How much room the filesystem holding a path has left, for cass:health's
 * disk row.
 *
 * An interface for DnsResolver's reason: what stands behind it is a pair of
 * global functions (disk_free_space, disk_total_space), and a test has no other
 * way to put a nearly full disk under the command.
 */
interface DiskSpace
{
    /**
     * Bytes free and bytes in total on the filesystem that holds $path, or
     * null when either could not be read.
     *
     * @return array{free: float, total: float}|null
     */
    public function measure(string $path): ?array;
}
