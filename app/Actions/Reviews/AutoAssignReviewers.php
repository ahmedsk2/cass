<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewerStatus;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\User;
use App\Support\Reviews\AssignmentPlan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spec 5.5's balanced auto-assign.
 *
 * **Deterministic without a seed.** Submissions are walked in `id` order and
 * candidates are sorted by `(current load asc, conference_reviewers.id asc)`,
 * so there is no tie left to break randomly. That matters more than it sounds:
 * the spec asks for the plan to be shown before it is saved, and a random
 * tie-break would mean the plan shown is not the plan saved.
 *
 * `plan()` writes nothing; `apply()` re-plans and saves through AssignReviewers
 * so the eligibility checks and the audit entries are the same ones a manual
 * assignment gets. Re-planning on save is deliberate: between the preview and
 * the click a reviewer may have been removed.
 */
class AutoAssignReviewers
{
    public function __construct(private readonly AssignReviewers $assignReviewers) {}

    public function plan(Conference $conference): AssignmentPlan
    {
        $target = max(1, (int) $conference->reviewers_per_submission);

        /** @var Collection<int, ConferenceReviewer> $reviewers */
        $reviewers = $conference->reviewers()
            ->where('status', ReviewerStatus::Active->value)
            ->with('user')
            ->orderBy('id')
            ->get();

        /** @var Collection<int, Submission> $submissions */
        $submissions = $conference->submissions()
            ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])
            ->with(['authors', 'reviewAssignments'])
            ->orderBy('id')
            ->get();

        // Current load per reviewer, across this conference only: a reviewer's
        // work on another conference is not this conference's business.
        $loads = [];

        foreach ($reviewers as $reviewer) {
            $loads[(int) $reviewer->user_id] = 0;
        }

        foreach ($submissions as $submission) {
            foreach ($submission->reviewAssignments as $assignment) {
                $id = (int) $assignment->reviewer_user_id;

                if (array_key_exists($id, $loads)) {
                    $loads[$id]++;
                }
            }
        }

        $rows = [];
        $shortfalls = [];
        $total = 0;

        foreach ($submissions as $submission) {
            $already = $submission->reviewAssignments->pluck('reviewer_user_id')->map('intval')->all();
            $need = $target - count($already);

            if ($need <= 0) {
                continue;
            }

            $candidates = $reviewers
                ->reject(fn (ConferenceReviewer $reviewer): bool => in_array((int) $reviewer->user_id, $already, true))
                ->reject(fn (ConferenceReviewer $reviewer): bool => self::conflicts($reviewer, $submission))
                ->sortBy([
                    fn (ConferenceReviewer $a, ConferenceReviewer $b): int => $loads[(int) $a->user_id] <=> $loads[(int) $b->user_id],
                    // The total ordering that removes the tie, and with it the
                    // need for a seed.
                    fn (ConferenceReviewer $a, ConferenceReviewer $b): int => (int) $a->getKey() <=> (int) $b->getKey(),
                ])
                ->take($need)
                ->values();

            $add = $candidates->map(fn (ConferenceReviewer $reviewer): int => (int) $reviewer->user_id)->all();

            foreach ($add as $id) {
                $loads[$id]++;
            }

            $total += count($add);

            if ($add !== []) {
                $rows[] = [
                    'submission_id' => (int) $submission->getKey(),
                    'label' => (string) ($submission->reference ?? $submission->title),
                    'add' => $add,
                    // No nullsafe walk and no "-" fallback:
                    // conference_reviewers.user_id is NOT NULL and cascades on
                    // delete, so a reviewer row without its user cannot exist.
                    // ConferenceAssignments::reviewerOptions() reads
                    // $reviewer->user->name for the same reason, and Larastan
                    // level 6 rejects the nullsafe form outright here
                    // (nullsafe.neverNull: "Using nullsafe property access
                    // ?->name on left side of ?? is unnecessary").
                    'names' => $candidates
                        ->map(fn (ConferenceReviewer $reviewer): string => $reviewer->user->name)
                        ->all(),
                ];
            }

            if (count($add) < $need) {
                $shortfalls[] = __('reviewer.assign.shortfall', [
                    'label' => (string) ($submission->reference ?? $submission->title),
                    'have' => count($already) + count($add),
                    'target' => $target,
                ]);
            }
        }

        return new AssignmentPlan($rows, $shortfalls, $total, $loads);
    }

    /**
     * Re-plans and saves. Every write goes through AssignReviewers, so the
     * eligibility checks and the `review.assignments_changed` entries are
     * identical to a manual assignment, and one extra entry records that this
     * batch was automatic.
     */
    public function apply(Conference $conference, User $actor): AssignmentPlan
    {
        $plan = $this->plan($conference);

        if ($plan->isEmpty()) {
            return $plan;
        }

        DB::transaction(function () use ($conference, $plan, $actor): void {
            foreach ($plan->rows as $row) {
                /** @var Submission $submission */
                $submission = $conference->submissions()->whereKey($row['submission_id'])->firstOrFail();

                $keep = $submission->reviewAssignments()->pluck('reviewer_user_id')->map('intval')->all();

                // The union, not the plan alone: auto-assign tops up, it never
                // takes away an assignment an organizer made by hand.
                $this->assignReviewers->handle(
                    $submission,
                    array_values(array_unique([...$keep, ...$row['add']])),
                    $actor,
                );
            }

            activity()
                ->performedOn($conference)
                ->causedBy($actor)
                ->withProperties([
                    'assignments' => $plan->assignments,
                    'submissions' => count($plan->rows),
                    'shortfalls' => count($plan->shortfalls),
                ])
                ->log('review.auto_assigned');
        });

        return $plan;
    }

    /**
     * Spec 5.5's conflict rule, plus the one refinement it needs to be usable:
     *
     *  - an exact email match is always a conflict;
     *  - a shared email *domain* is a conflict unless the domain is a free
     *    mailbox (config('cass.review.free_email_domains')) - most authors and
     *    most reviewers here submit from one, and "both on gmail" is an
     *    artefact rather than a conflict of interest;
     *  - a shared affiliation is a conflict, compared case-insensitively after
     *    collapsing whitespace and dropping punctuation;
     *  - organization membership is deliberately **not** a conflict.
     *
     * Static so the tests can ask it one reviewer and one submission at a time
     * and so the rule has exactly one home.
     */
    public static function conflicts(ConferenceReviewer $reviewer, Submission $submission): bool
    {
        $email = mb_strtolower(trim((string) $reviewer->user?->email));
        $domain = self::domain($email);
        $affiliation = self::normalise((string) $reviewer->affiliation);

        $free = array_map('mb_strtolower', (array) config('cass.review.free_email_domains', []));

        /** @var SubmissionAuthor $author */
        foreach ($submission->authors as $author) {
            $authorEmail = mb_strtolower(trim((string) $author->email));

            if ($email !== '' && $email === $authorEmail) {
                return true;
            }

            $authorDomain = self::domain($authorEmail);

            if ($domain !== '' && $domain === $authorDomain && ! in_array($domain, $free, true)) {
                return true;
            }

            if ($affiliation !== '' && $affiliation === self::normalise((string) $author->affiliation)) {
                return true;
            }
        }

        return false;
    }

    private static function domain(string $email): string
    {
        $at = mb_strrpos($email, '@');

        return $at === false ? '' : mb_substr($email, $at + 1);
    }

    /**
     * "King Faisal Specialist Hospital." and "  king faisal specialist hospital "
     * are the same institution. Anything more clever - abbreviations, "Dept of"
     * prefixes - is guesswork, and guessing wrong here means an abstract nobody
     * reviews.
     */
    private static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
