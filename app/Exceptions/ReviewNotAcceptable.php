<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A list of sentences a reviewer can act on, keyed where possible by the
 * question they belong to, so the panel can turn them into field errors rather
 * than one banner.
 */
final class ReviewNotAcceptable extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     * @param  array<string, string>  $fieldErrors  question ULID => message
     */
    public function __construct(public readonly array $reasons, public readonly array $fieldErrors = [])
    {
        parent::__construct(implode(' ', $reasons));
    }

    public static function because(string $reason): self
    {
        return new self([$reason]);
    }
}
