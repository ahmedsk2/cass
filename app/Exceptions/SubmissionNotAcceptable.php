<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Mirrors App\Exceptions\ConferenceNotPublishable from Plan 2: a list of
 * sentences an author (or an organizer) can act on, not a validation code.
 * SubmitAbstract::blockers() is the read-only half; this is what handle()
 * throws when a caller ignored it.
 */
class SubmissionNotAcceptable extends RuntimeException
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
