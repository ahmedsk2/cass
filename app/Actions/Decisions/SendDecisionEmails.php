<?php

declare(strict_types=1);

namespace App\Actions\Decisions;

use App\Enums\Decision;
use App\Exceptions\DecisionNotAcceptable;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\User;
use App\Support\Scoring\RankedSubmissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Spec 5.6: "Decision emails use the matching template and are sent when the
 * organizer clicks 'Send decision emails' (so decisions can be prepared quietly
 * first)."
 *
 * The whole action is one query and one loop:
 *
 *   under consideration + has a decision + has never been notified
 *
 * Sending stamps `decision_notified_at`, so a second click finds nothing: the
 * action is idempotent by its own query rather than by a flag somebody has to
 * remember to check. A change-after-send puts the timestamp back to null
 * (ApplyDecision), which is how a corrected decision re-enters this queue.
 *
 * A row whose corresponding author has no usable address is **skipped,
 * reported, and left unstamped**, so fixing the address and clicking again
 * reaches it.
 *
 * The run is bounded by `cass.decisions.send_chunk`, because the whole loop
 * happens inside one php-fpm request (docker/php.ini's 60-second budget) and
 * each row is a render, a token mint, an email_logs insert and a queue push.
 * The report says how many are left so the organizer clicks again rather than
 * wondering.
 */
class SendDecisionEmails
{
    public function __construct(private readonly SendOneDecisionEmail $sendOne) {}

    /**
     * The rows a click would send to, newest decisions last so the order is
     * stable across chunks.
     *
     * @return Builder<Submission>
     */
    public function pending(Conference $conference): Builder
    {
        return RankedSubmissions::query($conference)
            // SendOneDecisionEmail reads all three of these TWICE per row -
            // once in blockers() and once in handle() - and the ranking query
            // selects `submissions` alone, so every one of them was a lazy load
            // per letter: a send_chunk of 200 paid for it four hundred times.
            // `decisions` is what makes currentDecision()'s relationLoaded()
            // branch fire (Submission::currentDecision()), and the relation
            // carries its own newest-first order, so eager-loading it cannot
            // change which row that method picks.
            //
            // counts() and the `remaining` count both reorder or aggregate this
            // builder without hydrating models, where an eager load costs
            // nothing.
            ->with(['conference.organization', 'authors', 'decisions'])
            ->whereNotNull('decision')
            ->whereNull('decision_notified_at')
            ->orderBy('id');
    }

    /**
     * How many letters each decision would send, for the confirmation modal.
     * One grouped query.
     *
     * @return array<string, int>
     */
    public function counts(Conference $conference): array
    {
        /** @var array<string, int> $byDecision */
        $byDecision = $this->pending($conference)
            ->reorder()
            ->selectRaw('decision, count(*) as aggregate')
            ->groupBy('decision')
            ->pluck('aggregate', 'decision')
            ->all();

        $counts = [];

        foreach (Decision::inReportOrder() as $decision) {
            $counts[$decision->value] = (int) ($byDecision[$decision->value] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array{sent: int, skipped: array<string, list<string>>, remaining: int}
     */
    public function handle(Conference $conference, User $actor): array
    {
        $chunk = max(1, (int) config('cass.decisions.send_chunk'));

        $sent = 0;
        /** @var array<string, list<string>> $skipped */
        $skipped = [];

        /** @var Submission $submission */
        foreach ($this->pending($conference)->limit($chunk)->get() as $submission) {
            $key = (string) ($submission->reference ?? $submission->ulid);

            try {
                // The actor goes down with it: SendOneDecisionEmail writes one
                // `submission.decision_letter_sent` entry per letter, so the
                // bulk run and the per-row resend record the same thing about
                // the same act.
                $this->sendOne->handle($submission, $actor);
            } catch (DecisionNotAcceptable $exception) {
                $skipped[$key] = $exception->reasons;

                continue;
            }

            $sent++;
        }

        if ($sent > 0) {
            // The RUN, as one entry. The per-letter entries are written by
            // SendOneDecisionEmail; this one answers "who pressed the button".
            activity()
                ->performedOn($conference)
                ->causedBy($actor)
                ->withProperties(['sent' => $sent, 'skipped' => count($skipped)])
                ->log('conference.decisions_sent');
        }

        return [
            'sent' => $sent,
            'skipped' => $skipped,
            // Counted after the send, so it is "what is left", including the
            // rows this run skipped.
            'remaining' => $this->pending($conference)->count(),
        ];
    }

    /**
     * The report as one sentence for a Filament notification, escaped once
     * here for the reason Plan 2 fact 15 records.
     *
     * @param  array{sent: int, skipped: array<string, list<string>>, remaining: int}  $report
     */
    public static function summarise(array $report): string
    {
        $parts = [__('decisions.send.sent', ['count' => $report['sent']])];

        if ($report['skipped'] !== []) {
            $lines = [];

            foreach ($report['skipped'] as $reference => $reasons) {
                $lines[] = e($reference).' — '.e(implode(' ', $reasons));
            }

            $parts[] = __('decisions.send.skipped', [
                'count' => count($report['skipped']),
                'rows' => implode('; ', $lines),
            ]);
        }

        if ($report['remaining'] > 0) {
            $parts[] = __('decisions.send.remaining', ['count' => $report['remaining']]);
        }

        return implode(' ', $parts);
    }
}
