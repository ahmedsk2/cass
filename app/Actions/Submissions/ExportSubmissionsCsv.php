<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\Submission;
use App\Support\Export\SpreadsheetCell;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spec 5.6 names CSV and XLSX for the ranking table (Plan 5). This is the
 * submission list's half: CSV only, streamed, and never buffered - the caller
 * hands in the table's own already-scoped and already-filtered query, so an
 * organizer exports exactly the rows they can see.
 *
 * openspout writes to `php://output` inside a StreamedResponse, so a conference
 * with five thousand abstracts costs one chunk of rows in memory rather than
 * the whole file. Livewire turns a StreamedResponse returned from an action
 * into a download (Plan 2 fact 13).
 */
class ExportSubmissionsCsv
{
    /**
     * The heading row. A method rather than the constant it was, because a
     * constant cannot call __(): the words are lang/en/export.php's, shared
     * with RankingRows::headers() wherever the two files print the same
     * column, and the order is this method's because it is the file format
     * and must match row() below.
     *
     * @return list<string>
     */
    private static function headers(): array
    {
        return [
            __('export.headings.reference'),
            __('export.headings.conference'),
            __('export.headings.title'),
            __('export.headings.status'),
            __('export.headings.track'),
            __('export.headings.presentation_preference'),
            __('export.headings.corresponding_author'),
            __('export.headings.corresponding_email'),
            __('export.headings.all_authors'),
            __('export.headings.affiliations'),
            __('export.headings.contact_phone'),
            __('export.headings.word_count'),
            __('export.headings.files'),
            __('export.headings.extra_answers'),
            __('export.headings.submitted_at'),
            __('export.headings.last_edited_at'),
        ];
    }

    /**
     * @param  Builder<Submission>  $query
     */
    public function handle(Builder $query, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($query): void {
            $options = new Options;
            // Excel needs the BOM to open a UTF-8 file without mangling an
            // Arabic affiliation. It is openspout's default; stated so nobody
            // "tidies" it away.
            $options->SHOULD_ADD_BOM = true;

            $writer = new Writer($options);
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(self::headers()));

            $query
                ->with(['conference', 'track', 'authors', 'files'])
                ->reorder()
                ->chunkById(200, function (Collection $submissions) use ($writer): void {
                    foreach ($submissions as $submission) {
                        $writer->addRow(Row::fromValues($this->row($submission)));
                    }
                });

            $writer->close();
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return list<string> */
    private function row(Submission $submission): array
    {
        $corresponding = $submission->correspondingAuthor();

        // Conference soft deletes, and the foreign key only restricts a *hard*
        // delete, so this relation resolves to null while the row survives.
        // Today the only caller is SubmissionResource::getEloquentQuery(),
        // whose whereHas('conference') excludes exactly those rows - so this
        // was safe by the grace of one caller. A future caller handing in a
        // plain Submission::query() got a fatal *after* the response had begun
        // streaming: a truncated download, and no error page to explain it.
        $conference = $submission->conference;
        $timezone = (string) ($conference->timezone ?? config('app.timezone'));

        return array_map(SpreadsheetCell::text(...), [
            (string) $submission->reference,
            (string) ($conference->name ?? ''),
            (string) $submission->title,
            $submission->status->getLabel(),
            // `->` and not `?->` on the left of a `??`: the null coalesce
            // already swallows a property read on null, so `?->` is redundant
            // there and Larastan says so (nullsafe.neverNull) - the same rule
            // NewSubmissionNotice follows. `presentation_preference` below
            // keeps its `?->` because that one is a method *call*, which `??`
            // does not make safe.
            (string) ($submission->track->name ?? ''),
            $submission->presentation_preference?->getLabel() ?? '',
            (string) ($corresponding->name ?? ''),
            (string) ($corresponding->email ?? ''),
            $submission->authors->pluck('name')->implode('; '),
            $submission->authors->pluck('affiliation')->filter()->unique()->implode('; '),
            (string) $submission->contact_phone,
            (string) $submission->word_count,
            $submission->files->pluck('original_name')->implode('; '),
            $this->extraAnswers($submission),
            // `->copy()` first, the rule Conference::deadlineInConferenceTimezone()
            // sets: Illuminate\Support\Carbon is mutable, so setting the zone on
            // the instance a cast handed out is a write, not a read.
            $submission->submitted_at?->copy()->setTimezone($timezone)->format('Y-m-d H:i') ?? '',
            $submission->last_edited_at?->copy()->setTimezone($timezone)->format('Y-m-d H:i') ?? '',
        ]);
    }

    /**
     * The conference's own extra questions, flattened into one column. A column
     * per field would be wrong the moment two conferences are exported together
     * - which the "all conferences" filter allows - and a spreadsheet with
     * shifting columns is worse than one readable cell.
     */
    private function extraAnswers(Submission $submission): string
    {
        /** @var array<string, mixed> $values */
        $values = $submission->custom_field_values ?? [];
        $parts = [];

        foreach ($values as $key => $value) {
            $printable = match (true) {
                is_bool($value) => $value ? __('export.answers.yes') : __('export.answers.no'),
                is_scalar($value) => (string) $value,
                default => json_encode($value) ?: '',
            };

            $parts[] = $key.': '.$printable;
        }

        return implode('; ', $parts);
    }
}
