<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Demo\PurgeDemoOrganization;
use App\Actions\Demo\SeedDemo;
use App\Exceptions\DemoRefused;
use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * Remove the demonstration tenant `cass:demo-seed` created: every row under it
 * and every object it put on the private disk, permanently.
 *
 * There is no soft delete and no undo. Three things stand between an operator
 * and that: the organization has to be the one at the `demo-society` slug, its
 * `is_demo` flag has to be set - a column no form can write - and `--confirm`
 * has to be given explicitly.
 *
 * The owner's own account is never touched. The three demo reviewer accounts
 * are removed only if the purge leaves them with nothing at all.
 */
class DemoResetCommand extends Command
{
    protected $signature = 'cass:demo-reset {--confirm : Required. Everything under the demo organization is deleted permanently.}';

    protected $description = 'Hard-delete the demo organization seeded by cass:demo-seed, and its files on the private disk.';

    public function handle(PurgeDemoOrganization $purge): int
    {
        $organization = $purge->organization();

        if (! $organization instanceof Organization) {
            $this->line('No organization exists at the slug ['.SeedDemo::ORGANIZATION_SLUG.']. Nothing to reset.');

            return self::SUCCESS;
        }

        // Before the --confirm check on purpose: an operator who typed the
        // command against a real organization should be told *that*, rather
        // than be invited to add a flag that would then destroy it.
        if ($organization->is_demo !== true) {
            $this->error('['.$organization->slug.'] is not flagged is_demo. This command only removes data that cass:demo-seed created.');

            return self::FAILURE;
        }

        if ($this->option('confirm') !== true) {
            $this->error('This hard-deletes the demo organization, its conference, every abstract, review and decision under it, and every file on the private disk. There is no undo.');
            $this->error('Re-run with --confirm if that is what you want.');

            return self::FAILURE;
        }

        try {
            $counts = $purge->handle($organization);
        } catch (DemoRefused $exception) {
            foreach ($exception->reasons as $reason) {
                $this->error($reason);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Deleted', 'Rows'],
            array_map(
                static fn (string $label, int $count): array => [$label, (string) $count],
                array_keys($counts),
                array_values($counts),
            ),
        );

        $this->line('The demo organization is gone. Run "php artisan cass:demo-seed" to build a fresh one.');

        return self::SUCCESS;
    }
}
