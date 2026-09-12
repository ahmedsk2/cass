<?php

declare(strict_types=1);

namespace App\Support\Scoring;

use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Builder;

/**
 * **The** definition of which abstracts a conference's committee is looking at.
 *
 * Six things ask this question - the ranking table, the CSV export, the XLSX
 * export, SendDecisionEmails, MarkDecided::blockers() and the summary strip -
 * and six copies of a `whereIn('status', [...])` is how "under consideration"
 * comes to mean six things, the worst of which is a decision email sent to an
 * author who withdrew.
 *
 * The rule: everything except a draft and a withdrawal.
 *
 * - A **draft** was never submitted. Nobody reviewed it, it has no reference,
 *   and it is not in front of the committee.
 * - A **withdrawal** is the author's own exit. Spec 5.3 lets them take it back
 *   until the deadline, and an abstract that was withdrawn must never appear in
 *   a ranking, be decided, or receive a decision letter.
 * - Everything else stays: `submitted` and `under_review` are the work in
 *   progress, and `accepted`, `rejected` and `waitlisted` are rows that have
 *   already been decided and that the organizer still has to see - a ranking
 *   that hides its own decisions is a ranking you cannot check.
 *
 * The mirror of Plan 4's App\Support\Reviews\ReviewerScope, and deliberately a
 * different class: that one answers "what may this REVIEWER see", which also
 * depends on the conference status and on an assignment. This one answers "what
 * is this CONFERENCE deciding", which depends on nothing but the row.
 */
final class RankedSubmissions
{
    /** @return list<string> */
    public static function statuses(): array
    {
        return array_values(array_map(
            static fn (SubmissionStatus $status): string => $status->value,
            array_filter(
                SubmissionStatus::cases(),
                static fn (SubmissionStatus $status): bool => ! in_array(
                    $status,
                    [SubmissionStatus::Draft, SubmissionStatus::Withdrawn],
                    true,
                ),
            ),
        ));
    }

    /**
     * @return Builder<Submission>
     */
    public static function query(Conference $conference): Builder
    {
        return Submission::query()
            ->where('conference_id', $conference->getKey())
            ->whereIn('status', self::statuses());
    }

    /**
     * Narrow an existing builder instead of starting one, for the exports,
     * which are handed the table's own already-filtered query.
     *
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public static function constrain(Builder $query, Conference $conference): Builder
    {
        return $query
            ->where('conference_id', $conference->getKey())
            ->whereIn('status', self::statuses());
    }
}
