<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * One sentence an organizer can act on, in the shape ReviewNotAcceptable uses:
 * a named constructor taking an already-translated string, so every call site
 * reads __('domain.errors.…') and nothing assembles English.
 */
final class CustomDomainRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
