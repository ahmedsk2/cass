<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\Conference;
use App\Models\Submission;
use App\Support\Scoring\RankedSubmissions;
use App\Support\Scoring\RankingRows;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spec 5.6's CSV half of the ranking export. The same streaming shape as
 * ExportSubmissionsCsv: openspout writes to `php://output` inside a
 * StreamedResponse, so a conference with five thousand abstracts costs one
 * chunk of rows in memory rather than the whole file, and Livewire turns a
 * StreamedResponse returned from an action into a download.
 *
 * The caller hands in the table's own already-filtered query, so the file
 * matches the screen - and this action re-applies the conference scope anyway
 * (RankedSubmissions::constrain), because "the file matches the screen" is a
 * convenience and "the file never contains another conference" is a rule.
 */
class ExportRankingCsv
{
    /**
     * @param  Builder<Submission>  $query
     */
    public function handle(Builder $query, Conference $conference, string $fileName): StreamedResponse
    {
        $scoped = RankedSubmissions::constrain($query, $conference);

        return response()->streamDownload(function () use ($scoped, $conference): void {
            $options = new Options;
            // Excel needs the BOM to open a UTF-8 file without mangling an
            // Arabic affiliation. It is openspout's default; stated so nobody
            // "tidies" it away.
            $options->SHOULD_ADD_BOM = true;

            $writer = new Writer($options);
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(RankingRows::headers()));

            $scoped
                ->with(['track', 'authors'])
                ->reorder()
                ->chunkById(200, function (Collection $submissions) use ($writer, $conference): void {
                    /** @var Submission $submission */
                    foreach ($submissions as $submission) {
                        $writer->addRow(Row::fromValues(RankingRows::row($submission, $conference)));
                    }
                });

            $writer->close();
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
