<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The same shape as SubmissionNotAcceptable and ConferenceNotPublishable: a
 * list of sentences an operator standing at a production shell can act on, not
 * a code and not a stack trace.
 *
 * Thrown by App\Actions\Demo\SeedDemo and App\Actions\Demo\PurgeDemoOrganization
 * for every refusal that must end the run with a non-zero exit code. The one
 * refusal that is NOT an error - a demo organization that is already seeded -
 * is not an exception at all, because re-running the seeder is a normal thing
 * for an operator to do and exit 1 on it would fail a deploy script.
 */
class DemoRefused extends RuntimeException
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
