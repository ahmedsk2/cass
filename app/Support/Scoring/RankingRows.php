<?php

declare(strict_types=1);

namespace App\Support\Scoring;

use App\Models\Conference;
use App\Models\Submission;
use App\Support\Export\SpreadsheetCell;

/**
 * The headers and one row of the ranking export, shared by the CSV and the
 * XLSX writer so the two files cannot drift.
 *
 * **What is deliberately not here: the abstract body.** A 500-word paragraph
 * per row makes a spreadsheet unreadable, and Plan 3's submission-list export
 * (ExportSubmissionsCsv) already carries the full text, the affiliations, the
 * custom-field answers and the file names. These are two different views on
 * purpose, and the runbook says which is which.
 *
 * Strings go through SpreadsheetCell::text() - the formula guard, which must
 * run before Row::fromValues(). Numbers stay numbers, so the spreadsheet can
 * sort them.
 */
final class RankingRows
{
    /** @return list<string> */
    public static function headers(): array
    {
        return [
            'Reference', 'Title', 'Track', 'Presentation preference',
            'Score', 'Spread', 'Reviews',
            'Status', 'Decision', 'Decision letter sent',
            'Corresponding author', 'Corresponding email', 'All authors',
            'Submitted at',
        ];
    }

    /**
     * One row. The eager loads the caller must have applied are `track` and
     * `authors`; without them this is two queries per row, which is the one
     * thing an export of five hundred abstracts cannot afford.
     *
     * @return list<string|int|float|null>
     */
    public static function row(Submission $submission, Conference $conference): array
    {
        $corresponding = $submission->correspondingAuthor();
        $timezone = (string) ($conference->timezone ?: config('app.timezone'));

        return [
            SpreadsheetCell::text($submission->reference),
            SpreadsheetCell::text($submission->title),
            SpreadsheetCell::text($submission->track->name ?? ''),
            SpreadsheetCell::text($submission->presentation_preference?->getLabel() ?? ''),
            SpreadsheetCell::number($submission->score),
            SpreadsheetCell::number($submission->score_spread),
            (int) $submission->review_count,
            SpreadsheetCell::text($submission->status->getLabel()),
            SpreadsheetCell::text($submission->decision?->getLabel() ?? ''),
            // `->copy()` before `->setTimezone()`, the rule
            // Conference::deadlineInConferenceTimezone() sets:
            // Illuminate\Support\Carbon is mutable, so setting the zone on the
            // instance a cast handed out is a write, not a read.
            SpreadsheetCell::text($submission->decision_notified_at?->copy()->setTimezone($timezone)->format('Y-m-d H:i') ?? ''),
            SpreadsheetCell::text($corresponding->name ?? ''),
            SpreadsheetCell::text($corresponding->email ?? ''),
            SpreadsheetCell::text($submission->authors->pluck('name')->implode('; ')),
            SpreadsheetCell::text($submission->submitted_at?->copy()->setTimezone($timezone)->format('Y-m-d H:i') ?? ''),
        ];
    }

    /**
     * The file name both exports use, so the two differ only in extension:
     * `ranking-alpha-annual-meeting-2026-09-13-083000.xlsx`.
     */
    public static function fileName(Conference $conference, string $extension): string
    {
        return 'ranking-'.$conference->slug.'-'.now()->format('Y-m-d-His').'.'.$extension;
    }
}
