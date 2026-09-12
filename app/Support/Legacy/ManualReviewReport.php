<?php

declare(strict_types=1);

namespace App\Support\Legacy;

/**
 * Every row the import refused to guess about, collected while it runs and
 * written to one Markdown file a human reads afterwards.
 *
 * Spec section 15's mitigation for the legacy data is explicit: *"Import
 * command normalises encoding and reports rows needing manual review instead of
 * guessing."* This class is that promise, so "the mapper did not know" is a
 * line in a file rather than a value somebody invented.
 *
 * **It holds legacy identifiers, never legacy secrets.** Three of the real
 * dump's `reviewer_invitations` rows still carry a live plaintext MD5 token,
 * and this file lands on the `cass-storage` volume and stays there until
 * somebody deletes it - a longer life than the dump itself gets, because the
 * runbook removes that. So a reason names `reviewer_invitations#24` and says a
 * token was dropped; it never prints the token.
 */
final class ManualReviewReport
{
    /** @var list<array{table: string, legacy_id: int, reason: string}> */
    private array $entries = [];

    public function add(string $table, int $legacyId, string $reason): void
    {
        $this->entries[] = ['table' => $table, 'legacy_id' => $legacyId, 'reason' => $reason];
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * One line per entry, `table#id: reason`. The prefix is the whole point:
     * an operator reading the file has to be able to find the row in the dump.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(
            static fn (array $entry): string => "{$entry['table']}#{$entry['legacy_id']}: {$entry['reason']}",
            $this->entries,
        );
    }

    /** Grouped by legacy table, in first-seen order, with a count per group. */
    public function toMarkdown(string $organizationName): string
    {
        $out = '# '.__('legacy.report.title')."\n\n";
        $out .= __('legacy.report.generated', [
            'at' => now()->toDateTimeString(),
            'organization' => $organizationName,
        ])."\n\n";

        if ($this->entries === []) {
            return $out.__('legacy.report.none')."\n";
        }

        /** @var array<string, list<string>> $groups */
        $groups = [];

        foreach ($this->entries as $entry) {
            $groups[$entry['table']][] = "{$entry['table']}#{$entry['legacy_id']}: {$entry['reason']}";
        }

        $out .= __('legacy.report.summary', [
            'rows' => (string) count($this->entries),
            'tables' => (string) count($groups),
        ])."\n";

        foreach ($groups as $table => $lines) {
            $out .= "\n## ".__('legacy.report.group', ['table' => $table, 'rows' => (string) count($lines)])."\n\n";

            foreach ($lines as $line) {
                $out .= '- '.$line."\n";
            }
        }

        return $out;
    }
}
