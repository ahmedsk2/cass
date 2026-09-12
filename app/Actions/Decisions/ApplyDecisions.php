<?php

declare(strict_types=1);

namespace App\Actions\Decisions;

use App\Enums\Decision;
use App\Exceptions\DecisionNotAcceptable;
use App\Models\Submission;
use App\Models\User;

/**
 * Spec 5.6's "or in bulk", with a per-row report.
 *
 * The report is the point. Filament's own `authorizeIndividualRecords()`
 * collapses every refusal into a count
 * (vendor/filament/actions/src/Concerns/InteractsWithSelectedRecords.php:77),
 * and "2 rows were skipped" is precisely the message that makes an organizer
 * re-click until something breaks. Here the three outcomes are three different
 * answers - applied, unchanged, refused with a sentence - and the action prints
 * the third by reference so the organizer knows which rows to go and look at.
 *
 * Every row goes through ApplyDecision, so the rules live in exactly one place;
 * this class is a loop, a counter and a report.
 */
class ApplyDecisions
{
    public function __construct(private readonly ApplyDecision $applyDecision) {}

    /**
     * @param  iterable<Submission>  $submissions
     * @return array{applied: int, unchanged: int, refused: array<string, list<string>>}
     */
    public function handle(
        iterable $submissions,
        Decision $decision,
        User $actor,
        ?string $note = null,
        bool $changeAfterSend = false,
    ): array {
        $applied = 0;
        $unchanged = 0;
        /** @var array<string, list<string>> $refused */
        $refused = [];

        foreach ($submissions as $submission) {
            // The row is keyed by its reference, which is what an organizer can
            // find on screen. A draft has none, so fall back to the ULID rather
            // than to an empty key that would collapse two rows into one.
            $key = (string) ($submission->reference ?? $submission->ulid);

            $before = $submission->decision;
            // `?->note` and no `?? ''`: (string) null is already the empty
            // string, and Larastan rejects a nullsafe read on the left of `??`
            // outright (nullsafe.neverNull) because `??` suppresses the null
            // read by itself.
            $beforeNote = (string) $submission->currentDecision()?->note;

            try {
                $this->applyDecision->handle($submission, $decision, $actor, $note, $changeAfterSend);
            } catch (DecisionNotAcceptable $exception) {
                $refused[$key] = $exception->reasons;

                continue;
            }

            // ApplyDecision returns the row untouched when nothing changed, so
            // the difference is read from what was there before rather than
            // from a second return value nobody else needs.
            if ($before === $decision && $beforeNote === (string) $note) {
                $unchanged++;

                continue;
            }

            $applied++;
        }

        return ['applied' => $applied, 'unchanged' => $unchanged, 'refused' => $refused];
    }

    /**
     * The report as one sentence for a Filament notification. Built here so the
     * row action, the bulk action and any future caller say the same thing, and
     * so the escaping rule is applied once: Filament renders a notification
     * body through Str::sanitizeHtml(), whose shared config keeps `style` and
     * `class` on every element (Plan 2 fact 15), and a reference is
     * organizer-supplied text.
     *
     * @param  array{applied: int, unchanged: int, refused: array<string, list<string>>}  $report
     */
    public static function summarise(array $report): string
    {
        $parts = [__('decisions.bulk.applied', ['count' => $report['applied']])];

        if ($report['unchanged'] > 0) {
            $parts[] = __('decisions.bulk.unchanged', ['count' => $report['unchanged']]);
        }

        if ($report['refused'] !== []) {
            $lines = [];

            foreach ($report['refused'] as $reference => $reasons) {
                $lines[] = e($reference).' — '.e(implode(' ', $reasons));
            }

            $parts[] = __('decisions.bulk.refused', [
                'count' => count($report['refused']),
                'rows' => implode('; ', $lines),
            ]);
        }

        return implode(' ', $parts);
    }
}
