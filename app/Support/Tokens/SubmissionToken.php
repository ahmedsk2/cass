<?php

declare(strict_types=1);

namespace App\Support\Tokens;

final class SubmissionToken
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
