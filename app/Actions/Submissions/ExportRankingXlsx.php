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
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spec 5.6's XLSX half. The same query, the same rows, a different writer.
 *
 * **This one is not streamed to the client row by row, and saying so matters.**
 * openspout assembles an XLSX under Options::getTempFolder() - which defaults
 * to sys_get_temp_dir() (Common/TempFolderOptionTrait.php:25-32) - and copies
 * the finished zip to the file pointer at close()
 * (Writer/Common/Helper/ZipHelper.php:128). Memory stays flat; the file is
 * built on disk and sent in one go. For the conference sizes this platform is
 * for that is a few hundred kilobytes, comfortably inside php-fpm's 60-second
 * budget (docker/php.ini).
 *
 * The temp folder is deliberately left at its default rather than pointed at
 * `storage/`: /tmp in the runtime image is writable by the `app` user and is
 * NOT on the cass-storage volume, so a half-written export cannot survive a
 * container restart or fill the volume the author uploads live on.
 *
 * `ext-zip` is required by openspout itself and is installed everywhere this
 * application runs (Dockerfile:25, .github/workflows/ci.yml:44 and :87).
 */
class ExportRankingXlsx
{
    /**
     * @param  Builder<Submission>  $query
     */
    public function handle(Builder $query, Conference $conference, string $fileName): StreamedResponse
    {
        $scoped = RankedSubmissions::constrain($query, $conference);

        return response()->streamDownload(function () use ($scoped, $conference): void {
            $writer = new Writer(new Options);
            $writer->openToFile('php://output');

            $header = (new Style)->setFontBold();
            $writer->addRow(Row::fromValues(RankingRows::headers(), $header));

            $scoped
                ->with(['track', 'authors'])
                ->reorder()
                ->chunkById(200, function (Collection $submissions) use ($writer, $conference): void {
                    /** @var Submission $submission */
                    foreach ($submissions as $submission) {
                        // RankingRows::row() has already run every string
                        // through SpreadsheetCell::text(), which is what stops
                        // Cell::fromValue() turning a title into a FormulaCell
                        // below.
                        $writer->addRow(Row::fromValues(RankingRows::row($submission, $conference)));
                    }
                });

            $writer->close();
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
