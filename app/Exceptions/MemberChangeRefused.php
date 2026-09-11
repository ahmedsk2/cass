<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class MemberChangeRefused extends RuntimeException
{
    /** @param  list<string>  $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
