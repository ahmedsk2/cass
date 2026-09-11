<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ReviewFormLocked extends RuntimeException
{
    public static function make(): self
    {
        return new self('This review form is locked because reviews have already been submitted. Existing questions can no longer be changed or removed; you can still add new questions.');
    }
}
