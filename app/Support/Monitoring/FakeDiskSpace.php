<?php

declare(strict_types=1);

namespace App\Support\Monitoring;

use App\Contracts\DiskSpace;

/**
 * The test double, in app/ rather than tests/ for FakeDnsResolver's reasons:
 * Larastan analyses it, and the two implementations are provably the same
 * shape.
 *
 * It is never bound in production: AppServiceProvider binds SystemDiskSpace,
 * and a test replaces the instance.
 */
final class FakeDiskSpace implements DiskSpace
{
    /** @var list<string> */
    private array $measured = [];

    /** @param array{free: float, total: float}|null $reading */
    private function __construct(private readonly ?array $reading) {}

    public static function reporting(float $freeBytes, float $totalBytes): self
    {
        return new self(['free' => $freeBytes, 'total' => $totalBytes]);
    }

    public static function failing(): self
    {
        return new self(null);
    }

    public function measure(string $path): ?array
    {
        $this->measured[] = $path;

        return $this->reading;
    }

    /**
     * Every path this fake was asked about, in order, so a test can prove the
     * command measured the private disk and nothing else.
     *
     * @return list<string>
     */
    public function measuredPaths(): array
    {
        return $this->measured;
    }
}
