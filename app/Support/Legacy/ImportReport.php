<?php

declare(strict_types=1);

namespace App\Support\Legacy;

/**
 * What one run of `cass:import-legacy` did: how many rows of each kind it
 * created, how many it found already imported and skipped, everything a human
 * has to look at, and where that was written.
 *
 * A plain container rather than a readonly value object: the action fills it as
 * it walks the dump, and the command reads it afterwards.
 */
final class ImportReport
{
    /**
     * Rows this run wrote, keyed by the v2 noun ("conferences", "review
     * questions"). A key is absent rather than zero when nothing of that kind
     * was written, which is what makes a second run's `created` empty.
     *
     * @var array<string, int>
     */
    public array $created = [];

    /**
     * Rows a previous run had already imported, found through the
     * `legacy_imports` mapping table and left alone.
     *
     * @var array<string, int>
     */
    public array $skipped = [];

    /** Path on the `local` disk of the manual-review Markdown, once written. */
    public ?string $reportPath = null;

    public function __construct(public readonly ManualReviewReport $manual = new ManualReviewReport) {}

    public function recordCreated(string $key, int $count = 1): void
    {
        $this->created[$key] = ($this->created[$key] ?? 0) + $count;
    }

    public function recordSkipped(string $key, int $count = 1): void
    {
        $this->skipped[$key] = ($this->skipped[$key] ?? 0) + $count;
    }

    /** @return list<string> */
    public function manualReview(): array
    {
        return $this->manual->lines();
    }

    /** Every noun either half of the run touched, in a stable order. */
    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys($this->created + $this->skipped);
        // sort() reindexes, so the result is already a list.
        sort($keys);

        return $keys;
    }
}
