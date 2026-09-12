<?php

declare(strict_types=1);

namespace App\Support\Export;

/**
 * **The** rule for putting untrusted text into a spreadsheet.
 *
 * Excel and LibreOffice execute a cell that begins with `=`, `+`, `-`, `@`, a
 * tab or a carriage return, and every text column this application exports was
 * typed by an author nobody vetted. A leading apostrophe makes the cell text,
 * which is what it always was.
 *
 * In CSV that is a defence against the *reader*. In XLSX it is a defence
 * against the *writer*: openspout's Cell::fromValue() returns a FormulaCell
 * when the first character is `=`
 * (vendor/openspout/openspout/src/Common/Entity/Cell.php:58-60), so an
 * unguarded value is written into the workbook as an `<f>` element - a live
 * formula, authored by whoever typed the abstract. **The guard must therefore
 * run before Row::fromValues(), not after.**
 *
 * Moved here from ExportSubmissionsCsv::guard() unchanged, so there is one copy
 * rather than one per writer; that action's existing test
 * (tests/Feature/Organizer/SubmissionResourceTest.php:183) still passes
 * unedited, which is the proof the move changed nothing.
 */
final class SpreadsheetCell
{
    public static function text(?string $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * A number stays a number, so openspout writes a NumericCell and the
     * spreadsheet can sort, filter and chart it. A number cannot begin with `=`
     * so there is nothing to guard - and passing "91.25" as a string instead
     * would make every score column in the file left-aligned text.
     */
    public static function number(int|float|string|null $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        // `decimal:2` casts hand back a string on every driver; is_numeric
        // keeps a non-numeric surprise out of the sheet rather than coercing it
        // to 0.
        return is_numeric($value) ? (float) $value : null;
    }
}
