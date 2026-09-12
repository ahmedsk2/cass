<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Legacy\ImportLegacy;
use App\Exceptions\LegacyImportRefused;
use App\Support\Legacy\ImportReport;
use Illuminate\Console\Command;

/**
 * Spec 5.10: *"Console command `cass:import-legacy {sql} {uploads-dir}` … Run
 * once on production by the owner."*
 *
 * It runs once, by a human, at a shell - so every refusal is a sentence and the
 * output is a table somebody reads, not a log line. The one deliberate
 * unfriendliness is the exit code: a real run that produced any manual-review
 * line exits FAILURE, because an import nobody reads the report of is an import
 * that wrote guesses.
 */
class ImportLegacyCommand extends Command
{
    protected $signature = 'cass:import-legacy
        {sql : Path to the legacy mysqldump file}
        {uploads-dir : Path to the legacy uploads directory}
        {--organization-slug= : The existing organization to import into}
        {--dry-run : Run everything and roll it back}';

    protected $description = 'Import the legacy conferences, users, review forms, abstracts and reviews from a mysqldump';

    public function handle(ImportLegacy $import): int
    {
        $slug = trim((string) $this->option('organization-slug'));
        $dryRun = (bool) $this->option('dry-run');

        if ($slug === '') {
            $this->components->error(__('legacy.errors.no_slug'));

            return self::FAILURE;
        }

        try {
            $report = $import->handle(
                (string) $this->argument('sql'),
                (string) $this->argument('uploads-dir'),
                $slug,
                $dryRun,
            );
        } catch (LegacyImportRefused $refused) {
            foreach ($refused->reasons as $reason) {
                $this->components->error($reason);
            }

            return self::FAILURE;
        }

        $this->counts($report);

        if ($dryRun) {
            $this->components->warn(__('legacy.command.dry_run'));
        } else {
            $this->components->info(__('legacy.command.done', ['organization' => $slug]));
        }

        if ($report->manual->isEmpty()) {
            $this->components->info(__('legacy.command.manual_review_none'));

            return self::SUCCESS;
        }

        $this->components->warn(__('legacy.command.manual_review', [
            'count' => (string) $report->manual->count(),
            'path' => (string) $report->reportPath,
        ]));
        $this->components->warn(__('legacy.command.read_the_report'));

        if (! $dryRun) {
            $this->components->info(__('legacy.command.rescore'));
        }

        // A dry run is a rehearsal: its whole purpose is to produce this list
        // before anything is written, so a list is not a failure there.
        return $dryRun ? self::SUCCESS : self::FAILURE;
    }

    private function counts(ImportReport $report): void
    {
        $keys = $report->keys();

        if ($keys === []) {
            $this->components->warn(__('legacy.command.nothing'));

            return;
        }

        $this->table(
            ['', __('legacy.command.created'), __('legacy.command.skipped')],
            array_map(
                static fn (string $key): array => [
                    $key,
                    (string) ($report->created[$key] ?? 0),
                    (string) ($report->skipped[$key] ?? 0),
                ],
                $keys,
            ),
        );
    }
}
