<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ConferenceNotPublishable extends RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('This conference cannot be published yet: '.implode(' ', $reasons));
    }
}
