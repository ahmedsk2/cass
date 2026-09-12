<?php

declare(strict_types=1);

namespace App\Support\Scoring;

/**
 * Spec 5.6, third bullet: "Submission score = mean of submitted review scores;
 * spread = sample standard deviation; review count shown alongside."
 *
 * Two numbers that answer two different questions, kept apart on purpose:
 *
 * - `$scores` are the review scores that EXIST - the weighted means
 *   ReviewScorer produced for the submitted reviews that scored anything.
 * - `$submitted` is how many reviews were SUBMITTED, scored or not.
 *
 * They can disagree - three reviewers, two scores, because one conference's
 * form is all free text - and the ranking prints both so the disagreement is
 * visible rather than averaged away. A reviewer who filled in an all-text form
 * did the work, and "fewer than two reviews" is a question about people.
 *
 * The spread is the SAMPLE standard deviation (divides by n-1), which is what
 * the spec names and which is undefined for one observation. Null, not 0.00: a
 * zero would print perfect agreement between one person and themselves, right
 * beside a genuine zero from two reviewers who agreed, and an organizer sorting
 * by spread to find the contentious abstracts would find neither.
 */
final class SubmissionScorer
{
    /**
     * @param  list<float>  $scores  the review scores that exist, unrounded
     * @param  int  $submitted  how many reviews were submitted, scored or not
     * @return array{score: float|null, spread: float|null, review_count: int}
     */
    public static function summarise(array $scores, int $submitted): array
    {
        $count = count($scores);

        if ($count === 0) {
            return ['score' => null, 'spread' => null, 'review_count' => max(0, $submitted)];
        }

        $mean = array_sum($scores) / $count;

        return [
            'score' => round($mean, 2),
            'spread' => self::sampleStandardDeviation($scores, $mean),
            'review_count' => max(0, $submitted),
        ];
    }

    /**
     * @param  list<float>  $scores
     */
    private static function sampleStandardDeviation(array $scores, float $mean): ?float
    {
        $count = count($scores);

        // n - 1 is the sample denominator, so one observation has no spread at
        // all. This is the guard, and it is also the division-by-zero guard.
        if ($count < 2) {
            return null;
        }

        $sumOfSquares = 0.0;

        foreach ($scores as $score) {
            $sumOfSquares += ($score - $mean) ** 2;
        }

        return round(sqrt($sumOfSquares / ($count - 1)), 2);
    }
}
