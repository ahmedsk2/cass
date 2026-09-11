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

    /**
     * Whether a plaintext a *caller* is holding really belongs to this stored
     * hash. Both actions that accept a token from their caller check it here
     * before putting it into a link they email, because an unchecked one is
     * either a dead link (stale) or - far worse - a working editing credential
     * for a different abstract mailed to the wrong author.
     *
     * This is a comparison in PHP rather than the indexed lookup
     * Submission::findByPlainToken() uses, so it is hash_equals: the hash on
     * the left is not a secret, but the habit costs nothing and the next
     * comparison written here might be.
     */
    public static function matches(?string $storedHash, ?string $plain): bool
    {
        if ($storedHash === null || $storedHash === '' || $plain === null || $plain === '') {
            return false;
        }

        return hash_equals($storedHash, self::hash($plain));
    }
}
