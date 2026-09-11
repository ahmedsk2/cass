<?php

declare(strict_types=1);

namespace App\Support\Text;

/**
 * The number an author is held to by `conferences.word_limit`. It has to agree
 * with the live counter in the browser (resources/js/word-count.js uses the
 * same two rules), because the only thing worse than a limit is a limit that
 * counts differently on each side of the Submit button.
 *
 * Two rules, both chosen to match what a human counts:
 *
 * 1. Words are whitespace separated, and "whitespace" includes the characters
 *    a paste from Word brings with it. PCRE's `\s` does *not* match U+00A0 or
 *    U+202F even in UTF mode, so they are listed. A hyphenated compound is one
 *    word because no abstract limit in the world has ever meant otherwise.
 * 2. A token counts only if it contains a letter or a digit in any script, so
 *    a stray em dash or a lone emoji is punctuation, and Arabic counts exactly
 *    like English.
 */
final class WordCounter
{
    /** Everything PCRE calls space, plus the ones it does not. */
    private const SEPARATORS = '/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u';

    public static function count(string $text): int
    {
        $tokens = preg_split(self::SEPARATORS, trim($text), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false || $tokens === []) {
            return 0;
        }

        return count(array_filter(
            $tokens,
            static fn (string $token): bool => preg_match('/[\p{L}\p{N}]/u', $token) === 1,
        ));
    }
}
