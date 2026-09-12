<?php

declare(strict_types=1);

use App\Support\Legacy\SqlDumpReader;
use Illuminate\Support\Str;

// No database at all: this class turns a file into arrays.

beforeEach(function () {
    $this->reader = new SqlDumpReader(base_path('tests/Fixtures/legacy/dump.sql'));

    // A fixture path of this run's own. The two cases below used to write to
    // a fixed name and unlink it only on the happy path, so a failing
    // assertion left the file behind for the next run to read.
    $this->scratch = sys_get_temp_dir().'/cass-legacy-'.Str::random(12).'.sql';
});

afterEach(function () {
    if (is_string($this->scratch ?? null) && is_file($this->scratch)) {
        unlink($this->scratch);
    }
});

it('lists the tables the dump declares', function () {
    expect($this->reader->tables())->toContain('conferences', 'users', 'submissions', 'reviews', 'newsletters');
});

it('reads a simple table into associative rows', function () {
    $rows = iterator_to_array($this->reader->rows('conferences'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe([
            'id' => '5',
            'name' => 'Example Pediatric Symposium',
            'submission_deadline' => '2023-12-02',
        ]);
});

it('answers no rows for a table with no insert statement', function () {
    // The real `newsletters` has an auto-increment of 4 and no rows at all.
    // "No rows" and "no such table" are different answers and only one of them
    // is a problem.
    expect(iterator_to_array($this->reader->rows('newsletters')))->toBe([])
        ->and($this->reader->tables())->toContain('newsletters');
});

it('throws for a table the dump does not declare', function () {
    expect(fn () => iterator_to_array($this->reader->rows('orders')))
        ->toThrow(RuntimeException::class);
});

it('unescapes the four things a mysqldump escapes', function () {
    $rows = iterator_to_array($this->reader->rows('submissions'));

    // \' inside a single-quoted literal.
    expect($rows[1]['title'])->toBe("A child's response to early mobilisation")
        // \r\n written as an escape sequence, not as physical newlines: this is
        // why a statement can be accumulated line by line without a scanner
        // splitting a string across lines.
        ->and($rows[1]['abstract'])->toBe("Line one.\r\nLine two, with a semicolon; inside it.")
        // A semicolon INSIDE a string must not terminate the statement - and a
        // semicolon is one of the five author separators in the real data.
        ->and($rows[1]['abstract'])->toContain(';');
});

it('reads NULL as null and everything else as a string', function () {
    $rows = iterator_to_array($this->reader->rows('conference_reviewers'));

    expect($rows[2])->toBe(['id' => '13', 'conference_id' => null, 'reviewer_id' => null])
        // Numbers stay strings: the mapper casts, the reader does not guess a
        // type from a dump that declares none.
        ->and($rows[0]['conference_id'])->toBe('5');
});

it('reads two insert statements into one table as one list', function () {
    // The real `submissions` is split across two INSERT INTO statements
    // (ids 5-25, then 28-30); a reader that stops at the first loses three
    // abstracts silently.
    $sql = (string) file_get_contents(base_path('tests/Fixtures/legacy/dump.sql'));
    $sql .= "\nINSERT INTO `conferences` (`id`, `name`, `submission_deadline`) VALUES\n(9, 'A third edition', '2025-11-25');\n";

    $path = $this->scratch;
    file_put_contents($path, $sql);

    expect(iterator_to_array((new SqlDumpReader($path))->rows('conferences')))->toHaveCount(3);
});

it('reports a value that is not valid utf-8 rather than transcoding it', function () {
    // Every latin1-declared column in the real dump holds UTF-8 bytes and
    // there is no mojibake in it (Plan 6 fact 26). A latin1 -> utf8 conversion
    // step would CREATE the corruption that is currently absent, so the reader
    // never converts - it flags.
    //
    // The fixture needs the CREATE TABLE as well as the INSERT: rows() refuses
    // a table the dump does not DECLARE, and tables() reads CREATE statements -
    // so an INSERT on its own throws "The dump declares no table [t]." before
    // value()'s encoding guard is ever reached.
    $path = $this->scratch;
    file_put_contents($path, "CREATE TABLE `t` (\n  `a` varchar(255) NOT NULL\n) ENGINE=MyISAM DEFAULT CHARSET=latin1;\n\nINSERT INTO `t` (`a`) VALUES\n('caf\xE9');\n");

    $reader = new SqlDumpReader($path);
    $rows = iterator_to_array($reader->rows('t'));

    expect($reader->encodingProblems())->toHaveCount(1)
        ->and($rows[0]['a'])->toBeString();
});

it('refuses a file that is not there', function () {
    expect(fn () => (new SqlDumpReader('/no/such/dump.sql'))->tables())
        ->toThrow(RuntimeException::class);
});
