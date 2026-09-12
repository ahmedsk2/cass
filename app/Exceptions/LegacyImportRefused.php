<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The same shape as DemoRefused: a list of sentences an operator standing at a
 * production shell can act on, not a code and not a stack trace.
 *
 * Thrown by App\Actions\Legacy\ImportLegacy for every refusal that must end the
 * run before a single row is written - an organization slug that names nothing,
 * an organization that is not approved, a dump that cannot be read, an uploads
 * directory that is not there. Everything the import meets *after* it starts
 * and cannot decide is a manual-review line, not an exception: a run that dies
 * halfway through is worse than one that finishes and says what it was unsure
 * about.
 */
class LegacyImportRefused extends RuntimeException
{
    /** @param  list<string>  $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }

    public static function because(string ...$reasons): self
    {
        return new self(array_values($reasons));
    }
}
