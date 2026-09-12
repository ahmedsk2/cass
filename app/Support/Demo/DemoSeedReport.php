<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Enums\DemoStage;

/**
 * What `cass:demo-seed` prints when it is done: every address the owner has to
 * type into a browser, and every number they need to recognise the data as
 * theirs. Separate from the command so the action can be tested without a
 * console, and readonly so nothing downstream can edit the report it was given.
 *
 * `generatedPassword` is null when the operator supplied one with
 * `--reviewer-password`: they already know it, and printing it a second time
 * only puts it into one more shell history.
 */
final readonly class DemoSeedReport
{
    /**
     * @param  list<string>  $reviewerEmails
     * @param  array<string, int>  $counts  label => number, in print order
     */
    public function __construct(
        public DemoStage $stage,
        public string $organizationSlug,
        public string $conferenceName,
        public string $conferenceUrl,
        public ?string $shortLinkUrl,
        public string $organizerPanelUrl,
        public string $reviewerPanelUrl,
        public string $ownerEmail,
        public array $reviewerEmails,
        public ?string $generatedPassword,
        public array $counts,
        public string $resetCommand,
    ) {}
}
