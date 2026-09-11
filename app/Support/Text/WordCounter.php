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
        // Both patterns below carry /u, and PCRE refuses a subject that is not
        // valid UTF-8 by answering false rather than by throwing. Passed
        // straight through, one stray byte from a bad copy-paste would make
        // this answer 0 - a word limit that accepts anything, and a
        // `word_count` column of 0 next to a 5000-word abstract. The invalid
        // bytes are substituted and what the author actually wrote is counted.
        // mb_convert_encoding from UTF-8 to UTF-8 is the documented way to do
        // that; the browser half has no equivalent because a JavaScript string
        // cannot hold an invalid sequence in the first place.
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

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
