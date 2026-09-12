<?php

declare(strict_types=1);

namespace App\Support\Scoring;

use App\Models\Review;
use App\Models\ReviewAnswer;

/**
 * Spec 5.6, second bullet: "Review score = weighted mean of scored answers."
 *
 * The weight is `review_questions.weight`, which is cast `decimal:2` and
 * therefore arrives as the string "1.00" on both drivers - every read below
 * casts it, and a reader that forgets would get PHP's string-to-number
 * coercion, which happens to be right until somebody writes a weight with a
 * thousands separator.
 *
 * A weight of zero or less REMOVES the question from the mean rather than
 * zeroing it: `minValue(0)` in the panel makes 0.00 a value an organizer can
 * set, and it plainly means "do not count this". Including it would leave the
 * mean unchanged but shrink the denominator for no reason, and a form where
 * every weight is 0 would divide by zero.
 */
class ReviewScorer
{
    /**
     * One review's weighted mean, or null when nothing on it scored.
     *
     * Reads `answers.question` through the relation rather than through the
     * form, so an answer whose question was moved to another form still scores
     * the question it actually answers. `loadMissing` rather than `load`, so a
     * caller that already eager-loaded the pair (ComputeSubmissionScore does)
     * does not pay for a second query per review.
     */
    public function forReview(Review $review): ?float
    {
        $review->loadMissing('answers.question');

        $scored = [];

        /** @var ReviewAnswer $answer */
        foreach ($review->answers as $answer) {
            $question = $answer->question;

            if ($question === null) {
                continue;
            }

            $score = AnswerNormaliser::normalise($question, $answer);

            if ($score === null) {
                continue;
            }

            $scored[] = ['score' => $score, 'weight' => (float) $question->weight];
        }

        return self::weightedMean($scored);
    }

    /**
     * The arithmetic, with no model in sight, so the boundary table in
     * tests/Unit/ReviewScorerTest.php can be exhaustive without touching a
     * database.
     *
     * @param  list<array{score: float, weight: float}>  $scored
     */
    public static function weightedMean(array $scored): ?float
    {
        $weighted = 0.0;
        $total = 0.0;

        foreach ($scored as $entry) {
            if ($entry['weight'] <= 0.0) {
                continue;
            }

            $weighted += $entry['score'] * $entry['weight'];
            $total += $entry['weight'];
        }

        // The only guard this method needs, and it covers both "no scored
        // answers" and "every weight is zero".
        if ($total <= 0.0) {
            return null;
        }

        return round($weighted / $total, 2);
    }
}
