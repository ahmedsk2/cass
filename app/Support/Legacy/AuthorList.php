<?php

declare(strict_types=1);

namespace App\Support\Legacy;

/**
 * One legacy byline into a list of authors.
 *
 * The legacy `submissions` table stores three overlapping text columns and no
 * author rows at all: `authors` (the full byline as one string),
 * `presenter_names` (sometimes a subset, sometimes one person the byline does
 * not contain) and `affiliation` (an institution in 2024, a person's name in
 * most of 2023, the whole author list in two rows, and a research question in
 * one). Every rule below was written for a shape that is actually in the data,
 * and every rule that cannot decide hands the row to the manual-review report
 * rather than guessing.
 */
final class AuthorList
{
    /**
     * The five separators the twenty-four real rows use between them. Order
     * matters only in that the multi-character ones are matched first, which
     * the alternation below does.
     */
    private const SEPARATORS = '/\s*(?:\r\n|\n|•|&|;|,)\s*/u';

    /**
     * Journal-style markers pasted in from a manuscript: a trailing
     * affiliation number, an asterisk, a superscript glued to a surname, and
     * the literal word "corresponding", which appears three times in the real
     * data as though it were a name.
     */
    private const MARKERS = [
        '/\bcorresponding\b/iu' => '',
        '/[0-9*†‡§¶]+/u' => '',
        '/\s{2,}/u' => ' ',
    ];

    /**
     * @return list<array{name: string, email: string|null, affiliation: string|null, is_presenter: bool, is_corresponding: bool, sort: int}>
     */
    public static function parse(
        string $authors,
        string $presenters = '',
        ?string $contactEmail = null,
        ?string $affiliation = null,
    ): array {
        $names = self::names($authors);
        $presenterNames = self::names($presenters);

        // A presenter the byline does not contain is a real shape (two rows),
        // and dropping them would lose the one person who actually stood up.
        foreach ($presenterNames as $presenter) {
            if (! self::contains($names, $presenter)) {
                $names[] = $presenter;
            }
        }

        $correspondingIndex = self::correspondingIndex($names, $contactEmail);

        $parsed = [];

        // $names is already a list: it is built as one and only appended to,
        // so the index below is the author's position and nothing else.
        foreach ($names as $index => $name) {
            $parsed[] = [
                'name' => $name,
                // Only the corresponding author gets the one address the
                // abstract has; the caller invents a non-routable one for the
                // rest, because the column is NOT NULL.
                'email' => $index === $correspondingIndex ? $contactEmail : null,
                'affiliation' => $affiliation,
                'is_presenter' => self::contains($presenterNames, $name),
                'is_corresponding' => $index === $correspondingIndex,
                'sort' => $index + 1,
            ];
        }

        return $parsed;
    }

    /** Did the contact address actually match a name, or was the first author the fallback? */
    public static function matchedContact(string $authors, ?string $contactEmail): bool
    {
        $names = self::names($authors);

        return $contactEmail !== null && self::indexOfEmailOwner($names, $contactEmail) !== null;
    }

    /**
     * Is this legacy `affiliation` value really an affiliation?
     *
     * The one heuristic, and it is deliberately narrow: a value that parses as
     * one or more names that ALSO appear in the byline is a person, not an
     * institution. Everything else is treated as an affiliation - including
     * row 7's research question, which is why the caller reports every value
     * it keeps as well as every value it drops.
     */
    public static function looksLikeAffiliation(string $affiliation, string $authors): bool
    {
        $value = trim($affiliation);

        if ($value === '') {
            return false;
        }

        $names = self::names($authors);
        $candidates = self::names($value);

        if ($candidates === []) {
            return false;
        }

        foreach ($candidates as $candidate) {
            if (self::contains($names, $candidate)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function names(string $raw): array
    {
        $parts = preg_split(self::SEPARATORS, $raw) ?: [];
        $names = [];

        foreach ($parts as $part) {
            $name = (string) preg_replace(array_keys(self::MARKERS), array_values(self::MARKERS), $part);
            // Trailing whitespace is endemic in the real data, and so are
            // trailing full stops left by a split.
            $name = trim($name, " \t\n\r\0\x0B.,");

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @param  list<string>  $names */
    private static function contains(array $names, string $needle): bool
    {
        foreach ($names as $name) {
            if (self::fold($name) === self::fold($needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $names */
    private static function correspondingIndex(array $names, ?string $contactEmail): ?int
    {
        if ($names === [] || $contactEmail === null || $contactEmail === '') {
            return null;
        }

        // Somebody has to be corresponding: it is the only address the
        // abstract has. The first author is the conventional answer, and
        // matchedContact() is what tells the importer to report this row.
        return self::indexOfEmailOwner($names, $contactEmail) ?? 0;
    }

    /** @param  list<string>  $names */
    private static function indexOfEmailOwner(array $names, string $email): ?int
    {
        $local = self::fold((string) strstr($email, '@', true));

        if ($local === '') {
            return null;
        }

        foreach ($names as $index => $name) {
            $folded = self::fold($name);

            // `badr.example@` against "Dr Badr Example": the local part's
            // pieces all appear in the name. Deliberately not fuzzy - a
            // near-match that is wrong puts a stranger's address on somebody
            // else's abstract.
            $pieces = array_filter(preg_split('/[._-]+/', $local) ?: []);

            if ($pieces === []) {
                continue;
            }

            $hits = 0;

            foreach ($pieces as $piece) {
                if (strlen($piece) > 2 && str_contains($folded, $piece)) {
                    $hits++;
                }
            }

            if ($hits === count($pieces)) {
                return $index;
            }
        }

        return null;
    }

    private static function fold(string $value): string
    {
        // Honorifics are baked into the legacy names ("Dr ", "dr. ", "D. ")
        // and are not part of anybody's identity.
        $value = (string) preg_replace('/^\s*(dr|prof|mr|mrs|ms|miss)\.?\s+/iu', '', trim($value));

        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $value));
    }
}
