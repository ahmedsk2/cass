<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Demo\SeedDemo;
use App\Enums\DemoStage;
use App\Exceptions\DemoRefused;
use App\Models\Organization;
use App\Support\Demo\DemoSeedReport;
use Illuminate\Console\Command;

/**
 * Seed a self-contained demonstration tenant so the owner can walk the whole
 * loop - organization, conference, abstracts, reviewers, reviews, ranking,
 * decisions, letters - on the live site without inventing fifteen abstracts
 * first, and remove every trace of it afterwards with `cass:demo-reset`.
 *
 * Every decision this command makes is in App\Actions\Demo\SeedDemo; what lives
 * here is the three things a console owns: parsing the options, choosing the
 * exit code, and printing the summary.
 *
 * **Exit codes matter here**, because this runs from a deploy shell:
 *
 * - 0 and a summary: seeded.
 * - 0 and a sentence: the demo tenant is already there. Re-running the seeder
 *   is a normal thing to do and must not fail a script; the sentence says to
 *   run `cass:demo-reset` first.
 * - 1: the slug is taken by something that is NOT demo data, the owner address
 *   has no account, or the stage is not one of the three. Nothing was written
 *   in any of those cases.
 */
class DemoSeedCommand extends Command
{
    protected $signature = 'cass:demo-seed
        {--stage=reviewing : How far to drive the loop: open, reviewing or decided}
        {--owner-email= : The existing account that will own the demo organization. Defaults to CASS_ADMIN_EMAIL.}
        {--reviewer-password= : The password for the three demo reviewer accounts. A 16-character one is generated and printed when this is omitted.}';

    protected $description = 'Seed a demo organization, conference, abstracts, reviewers, reviews and decisions. Sends nothing.';

    public function handle(SeedDemo $seed): int
    {
        $requested = (string) $this->option('stage');
        $stage = DemoStage::tryFrom($requested);

        if (! $stage instanceof DemoStage) {
            $this->error("[{$requested}] is not a stage. Use one of: ".implode(', ', DemoStage::names()).'.');

            return self::FAILURE;
        }

        $existing = $seed->existingDemoOrganization();

        if ($existing instanceof Organization) {
            return $this->refuseExisting($existing);
        }

        try {
            $owner = $seed->owner($this->stringOption('owner-email'));

            $report = $seed->handle($stage, $owner, $this->stringOption('reviewer-password'));
        } catch (DemoRefused $exception) {
            foreach ($exception->reasons as $reason) {
                $this->error($reason);
            }

            return self::FAILURE;
        }

        $this->summarise($report);

        return self::SUCCESS;
    }

    private function refuseExisting(Organization $existing): int
    {
        if ($existing->is_demo === true) {
            $this->line('The demo organization ['.SeedDemo::ORGANIZATION_SLUG.'] is already seeded. Nothing was changed.');
            $this->line('Run "php artisan cass:demo-reset --confirm" first if you want a fresh one.');

            return self::SUCCESS;
        }

        $this->error('An organization already owns the slug ['.SeedDemo::ORGANIZATION_SLUG.'] and it is not demonstration data.');
        $this->error('Nothing was changed. Rename or remove that organization by hand if the demo really has to live at this address.');

        return self::FAILURE;
    }

    /** An option that was not given arrives as null; an empty one is the same thing. */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function summarise(DemoSeedReport $report): void
    {
        $rows = [
            ['Stage', $report->stage->value],
            ['Organization slug', $report->organizationSlug],
            ['Conference', $report->conferenceName],
            ['Public page', $report->conferenceUrl],
            ['Short link', $report->shortLinkUrl ?? '-'],
            ['Organizer panel', $report->organizerPanelUrl],
            ['Reviewer panel', $report->reviewerPanelUrl],
            ['Owner (sign in as)', $report->ownerEmail],
        ];

        foreach ($report->reviewerEmails as $index => $email) {
            $rows[] = ['Reviewer '.($index + 1), $email];
        }

        if ($report->generatedPassword !== null) {
            // Printed once, and nowhere else: it is not stored in plaintext and
            // this line is the only place it will ever appear.
            $rows[] = ['Reviewer password', $report->generatedPassword];
        }

        foreach ($report->counts as $label => $count) {
            $rows[] = [$label, (string) $count];
        }

        $rows[] = ['Remove all of it', $report->resetCommand];

        $this->newLine();
        $this->table(['Demo data', 'Value'], $rows);

        if ($report->generatedPassword !== null) {
            $this->line('The reviewer password is shown above for the only time. Re-seed if it is lost.');
        }

        $this->line('No email was sent and no email_logs row was written. The demo flows you run from here use the real mailer.');
    }
}
