<?php

declare(strict_types=1);

namespace App\Support\Tokens;

use Random\RandomException;

/**
 * 32 random bytes rendered as 64 hex characters, stored only as a SHA-256 hash
 * (spec section 9). Hex rather than base64url for the same reason
 * SubmissionToken uses it: the value travels in a path segment constrained to
 * [A-Za-z0-9]{64}, with no padding, no `-` and no `_` for a mail client to
 * mangle when it decides where a link ends.
 *
 * A separate class from SubmissionToken rather than a shared base: the two have
 * the same shape today and entirely different lifetimes - an author token lives
 * as long as the submission and is reissued on demand, an invitation token
 * lives 14 days and dies on acceptance - and a shared parent would be a place
 * for a change to one to silently become a change to both.
 */
final class InvitationToken
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
