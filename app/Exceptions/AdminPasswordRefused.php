<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Actions\Users\ResetPlatformAdminPassword when the password may not be set: sentences an
 * operator at a production shell can act on, never the password itself.
 */
final class AdminPasswordRefused extends RuntimeException
{
    /** @param  list<string>  $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
