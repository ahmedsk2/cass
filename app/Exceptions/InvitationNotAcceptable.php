<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Mirrors ConferenceNotPublishable and SubmissionNotAcceptable: a list of
 * sentences a person can act on, not a validation code.
 */
final class InvitationNotAcceptable extends RuntimeException
{
    /** @param  list<string>  $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }

    public static function because(string $reason): self
    {
        return new self([$reason]);
    }
}
