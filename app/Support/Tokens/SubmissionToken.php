<?php

declare(strict_types=1);

namespace App\Support\Tokens;

use Random\RandomException;

/**
 * 32 random bytes rendered as 64 hex characters. Hex rather than base64url
 * because the value travels in a path segment constrained to
 * [A-Za-z0-9]{64} - no padding, no `-`, no `_`, nothing a mail client can
 * mangle when it decides where a link ends.
 *
 * sodium is not built into this machine's PHP or into the production image, so
 * the comparison in findByPlainToken() is an indexed lookup on the stored hash
 * rather than a constant-time compare. That is the right shape anyway: the
 * secret is 256 bits of entropy behind a database index, and a timing oracle on
 * an index probe is not a route to guessing one.
 */
final class SubmissionToken
{
    public const LENGTH = 64;

    /** @throws RandomException */
    public static function generate(): string
    {
        return bin2hex(random_bytes(self::LENGTH / 2));
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
