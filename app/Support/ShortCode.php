<?php

declare(strict_types=1);

namespace App\Support;

use Random\RandomException;

final class ShortCode
{
    /**
     * Crockford-style: no 0/O, no 1/I/L, no U. Codes are printed on posters
     * and read aloud, so every remaining character survives a bad photocopy.
     */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LENGTH = 8;

    /**
     * 30^8 is about 6.6e11 codes, so a collision needs a retry roughly never;
     * ShortLink::forTarget() still loops until the insert is unique.
     *
     * @throws RandomException
     */
    public static function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    public static function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }
}
