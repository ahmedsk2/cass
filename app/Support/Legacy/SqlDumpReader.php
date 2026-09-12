<?php

declare(strict_types=1);

namespace App\Support\Legacy;

use Generator;
use RuntimeException;

/**
 * One phpMyAdmin dump, read into associative rows, with no database involved.
 *
 * It handles exactly what the legacy dump contains and refuses everything else,
 * which is why it is 120 lines rather than a SQL parser:
 *
 *   - `CREATE TABLE \`x\` (` … `) ENGINE=MyISAM DEFAULT CHARSET=latin1;`
 *   - `INSERT INTO \`x\` (\`a\`, \`b\`) VALUES (…),(…);`, possibly several per
 *     table (the real `submissions` is split across two)
 *   - single-quoted literals with MySQL's backslash escapes, and NULL
 *
 * **Encoding: read as UTF-8, never transcoded.** Every table in the dump is
 * declared latin1 and the file is UTF-8 - the legacy application wrote UTF-8
 * bytes into latin1 columns and phpMyAdmin passed them through unconverted.
 * Greps for every classic double-encoding marker return zero. A latin1 -> utf8
 * step here would CREATE the corruption that is currently absent, so a value
 * that is not valid UTF-8 is recorded in encodingProblems() and handed back
 * with its invalid bytes substituted, for a human to look at.
 */
final class SqlDumpReader
{
    /** @var list<string> */
    private array $problems = [];

    public function __construct(private readonly string $path) {}

    /**
     * Every table the dump declares, whether or not it has rows.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $tables = [];

        foreach ($this->statements() as $statement) {
            if (preg_match('/^CREATE TABLE `([a-z0-9_]+)`/i', $statement, $matches) === 1) {
                $tables[] = $matches[1];
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Every row of one table, in dump order, as column => value|null.
     *
     * A Generator rather than an array because `reviews` is 599 rows in the
     * real dump and the caller writes as it reads - and because a table with
     * no INSERT is then an empty iteration rather than a special case.
     *
     * @return Generator<int, array<string, string|null>>
     */
    public function rows(string $table): Generator
    {
        if (! in_array($table, $this->tables(), true)) {
            throw new RuntimeException("The dump declares no table [{$table}].");
        }

        foreach ($this->statements() as $statement) {
            if (preg_match('/^INSERT INTO `'.preg_quote($table, '/').'` \(([^)]*)\) VALUES/i', $statement, $matches) !== 1) {
                continue;
            }

            $columns = array_map(
                static fn (string $column): string => trim(trim($column), '`'),
                explode(',', $matches[1]),
            );

            foreach ($this->tuples(substr($statement, strlen($matches[0]))) as $values) {
                if (count($values) !== count($columns)) {
                    // A row with the wrong arity is a dump this reader does not
                    // understand, and guessing which column is missing is how
                    // an import silently writes an abstract's phone number into
                    // its title.
                    throw new RuntimeException("Row in [{$table}] has ".count($values).' values for '.count($columns).' columns.');
                }

                yield array_combine($columns, $values);
            }
        }
    }

    /** @return list<string> */
    public function encodingProblems(): array
    {
        return $this->problems;
    }

    /**
     * Statements, one at a time, accumulated across physical lines.
     *
     * A string literal never spans a physical line in a mysqldump - newlines
     * inside a value are written as the escape sequence `\r\n` - so a line is
     * always a safe place to stop reading. What is NOT safe is splitting on
     * `;`, because a semicolon inside a value is common (it is one of the five
     * author separators in the real data), which is why the terminator is
     * found with the quote state tracked.
     *
     * @return Generator<int, string>
     */
    private function statements(): Generator
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot read [{$this->path}].");
        }

        try {
            $buffer = '';

            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);

                // Comments and the /*!40101 … */ conditional directives carry
                // no rows. Only skipped when nothing is buffered: a `--` can
                // appear inside a value.
                if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*'))) {
                    continue;
                }

                $buffer .= ($buffer === '' ? '' : "\n").$trimmed;

                if ($this->isComplete($buffer)) {
                    yield rtrim($buffer, "; \t\n");
                    $buffer = '';
                }
            }

            if (trim($buffer) !== '') {
                yield rtrim($buffer, "; \t\n");
            }
        } finally {
            fclose($handle);
        }
    }

    /** A statement ends at the first `;` that is not inside a single-quoted literal. */
    private function isComplete(string $buffer): bool
    {
        $inString = false;
        $length = strlen($buffer);

        for ($index = 0; $index < $length; $index++) {
            $character = $buffer[$index];

            if ($inString) {
                if ($character === '\\') {
                    $index++;   // the escaped character, whatever it is

                    continue;
                }

                if ($character === "'") {
                    $inString = false;
                }

                continue;
            }

            if ($character === "'") {
                $inString = true;

                continue;
            }

            if ($character === ';') {
                return true;
            }
        }

        return false;
    }

    /**
     * `(…),(…),(…)` into a list of lists of values.
     *
     * @return Generator<int, list<string|null>>
     */
    private function tuples(string $values): Generator
    {
        $length = strlen($values);
        $index = 0;

        while ($index < $length) {
            // Skip to the next `(` that opens a tuple.
            while ($index < $length && $values[$index] !== '(') {
                $index++;
            }

            if ($index >= $length) {
                return;
            }

            $index++;   // past the (
            $row = [];
            $current = '';
            $isString = false;
            $inString = false;

            while ($index < $length) {
                $character = $values[$index];

                if ($inString) {
                    if ($character === '\\') {
                        $current .= $this->unescape($values[$index + 1] ?? '');
                        $index += 2;

                        continue;
                    }

                    if ($character === "'") {
                        // Two quotes in a row is an escaped quote in some
                        // dumps; phpMyAdmin uses a backslash, but handling
                        // both costs one comparison.
                        if (($values[$index + 1] ?? '') === "'") {
                            $current .= "'";
                            $index += 2;

                            continue;
                        }

                        $inString = false;
                        $index++;

                        continue;
                    }

                    $current .= $character;
                    $index++;

                    continue;
                }

                if ($character === "'") {
                    $inString = true;
                    $isString = true;
                    $index++;

                    continue;
                }

                if ($character === ',' || $character === ')') {
                    $row[] = $this->value(trim($current), $isString);
                    $current = '';
                    $isString = false;
                    $index++;

                    if ($character === ')') {
                        break;
                    }

                    continue;
                }

                $current .= $character;
                $index++;
            }

            yield $row;
        }
    }

    private function value(string $raw, bool $wasQuoted): ?string
    {
        if (! $wasQuoted && strcasecmp($raw, 'NULL') === 0) {
            return null;
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            // Recorded, not converted. The whole dump is valid UTF-8 today;
            // if that ever stops being true, a person has to look at the row
            // rather than an algorithm guessing a source charset.
            $this->problems[] = mb_substr(
                (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-8'),
                0,
                120,
            );

            return (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        }

        return $raw;
    }

    private function unescape(string $character): string
    {
        return match ($character) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            '0' => "\0",
            'Z' => "\x1A",
            'b' => "\x08",
            default => $character,   // \' \" \\ and anything else, literally
        };
    }
}
