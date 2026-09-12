<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Submissions\ComputeSubmissionScore;
use App\Models\Conference;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recompute every review score and every denormalised submission score for one
 * conference.
 *
 * **Not an organizer feature and not scheduled.** Spec section 3 locks the
 * review form the moment the first review is submitted, and a question's weight
 * is locked with it (ReviewQuestion::booted()'s `updating` hook throws
 * ReviewFormLocked), so nothing an organizer can do makes a stored score wrong.
 * This command exists for the four cases where the stored numbers are not what
 * the application itself would have written:
 *
 *   1. once immediately after the release that adds the score columns, which
 *      land empty for every abstract that already exists and are never
 *      recomputed on read — the one-time backfill in the runbook;
 *   2. after the Plan 6 legacy import, which inserts reviews and answers
 *      directly and has no SubmitReview to hook;
 *   3. after a platform admin corrects data by hand;
 *   4. after a bug in App\Support\Scoring is fixed, when every affected
 *      conference has to be recomputed with the new code.
 *
 * A nightly schedule was deliberately NOT added: a rescore rewrites numbers an
 * organizer may be looking at, and the four cases above are all deliberate
 * human acts.
 */
class RescoreConferenceCommand extends Command
{
    /**
     * The ULID is the public identifier (spec section 3) and is what an
     * organizer can read off a panel URL; the numeric id is accepted because a
     * platform admin working in the database has that and not the ULID.
     */
    protected $signature = 'cass:rescore {conference : The conference ULID or numeric id}';

    protected $description = 'Recompute review and submission scores for one conference';

    public function handle(ComputeSubmissionScore $compute): int
    {
        $key = (string) $this->argument('conference');

        /** @var Conference|null $conference */
        $conference = Conference::query()
            ->where('ulid', $key)
            ->when(ctype_digit($key), fn (Builder $query): Builder => $query->orWhere('id', (int) $key))
            ->first();

        if (! $conference instanceof Conference) {
            // A sentence and exit 1, not a ModelNotFoundException trace in an
            // operator's terminal at two in the morning.
            $this->components->error("No conference found for [{$key}]. Pass the ULID from the panel URL, or the numeric id.");

            return self::FAILURE;
        }

        $this->components->info("Rescoring [{$conference->name}]...");

        $count = $compute->forConference($conference);

        $this->components->info("Rescored {$count} abstract(s).");

        return self::SUCCESS;
    }
}
