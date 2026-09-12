<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\ReviewStatus;
use App\Models\Conference;
use App\Models\Review;
use App\Models\Submission;
use App\Support\Scoring\ReviewScorer;
use App\Support\Scoring\SubmissionScorer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **The** writer of `reviews.score` and of the four denormalised columns on
 * `submissions` (spec 5.6, spec section 10's ranking budget).
 *
 * Exactly three things call it: Plan 4's SubmitReview, Plan 4's ReopenReview,
 * and `cass:rescore`. Nothing else may write those columns - a second writer is
 * how a ranking comes to disagree with the reviews behind it.
 *
 * It recomputes the WHOLE submission every time rather than patching one
 * review. Two or three reviews is one query for the answers and one small
 * update each, and the result is idempotent by construction: calling it twice
 * writes the same rows, and calling it after anything at all leaves the
 * abstract correct rather than correct-if-the-right-thing-changed.
 *
 * `reviews.score` is written for a draft as well as for a submitted review - it
 * is what those answers are worth - and only the submitted ones reach the
 * aggregate. That is what makes reopening a review a pure status change.
 */
class ComputeSubmissionScore
{
    public function __construct(private readonly ReviewScorer $reviewScorer) {}

    public function handle(Submission $submission): Submission
    {
        // One query for the reviews, one for their answers, one for the
        // questions those answers point at. Everything below is in memory.
        $reviews = $submission->reviews()->with('answers.question')->get();

        /** @var list<float> $submittedScores */
        $submittedScores = [];
        $submitted = 0;

        DB::transaction(function () use ($reviews, &$submittedScores, &$submitted, $submission): void {
            /** @var Review $review */
            foreach ($reviews as $review) {
                $score = $this->reviewScorer->forReview($review);

                // ->toBase() before ->update(), not forceFill()->save() and not
                // a bare Eloquent update. The model has LogsActivity and
                // nothing about a recomputed number belongs in the audit trail;
                // and `updated_at` on a review means "the reviewer touched it",
                // which an Eloquent Builder::update() would rewrite on every
                // recompute because it calls addUpdatedAtColumn()
                // (vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:1303-1306).
                // A whole-conference cass:rescore would then reset that
                // timestamp on every review in the conference. The base query
                // keeps the model's global scopes and skips both the timestamp
                // and the model events, which is exactly what is wanted here.
                Review::query()->whereKey($review->getKey())->toBase()->update(['score' => $score]);

                if ($review->status !== ReviewStatus::Submitted) {
                    continue;
                }

                $submitted++;

                if ($score !== null) {
                    // The unrounded-once value the scorer just produced, not the
                    // decimal:2 string the column would hand back: rounding a
                    // mean of already-rounded numbers is a second rounding, and
                    // a test written locally would drift from the MySQL run.
                    $submittedScores[] = $score;
                }
            }

            $summary = SubmissionScorer::summarise($submittedScores, $submitted);

            $submission->forceFill([
                'score' => $summary['score'],
                'score_spread' => $summary['spread'],
                'review_count' => $summary['review_count'],
                // Always moves, even when the answer is "no reviews": an
                // organizer asking "is this up to date" needs the timestamp to
                // mean "last computed", not "last non-null".
                'scored_at' => now(),
            ])->save();
        });

        return $submission->refresh();
    }

    /**
     * Every abstract of one conference, in chunks, for `cass:rescore` and for
     * the Plan 6 legacy import.
     *
     * Deliberately every row, not only the reviewable ones: the command is a
     * repair tool, and a withdrawn abstract that is reinstated by hand must not
     * carry a number computed under different weights.
     *
     * @return int how many submissions were recomputed
     */
    public function forConference(Conference $conference): int
    {
        $count = 0;

        $conference->submissions()
            ->select('submissions.*')
            ->chunkById(100, function (Collection $submissions) use (&$count): void {
                /** @var Submission $submission */
                foreach ($submissions as $submission) {
                    $this->handle($submission);
                    $count++;
                }
            });

        return $count;
    }
}
