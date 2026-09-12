<?php

declare(strict_types=1);

namespace App\Support\Scoring;

use App\Enums\ReviewQuestionType;
use App\Models\ReviewAnswer;
use App\Models\ReviewQuestion;

/**
 * Spec 5.6, first bullet: "Each answer is normalised to 0-100: Likert
 * `(value - min) / (max - min) x 100`; boolean yes = 100, no = 0; select
 * options carry an optional score; text answers do not score."
 *
 * Null everywhere means "this answer contributes nothing", and it is the
 * difference between an abstract nobody scored and an abstract everybody hated.
 * Zero is a score. Null is not.
 *
 * Nothing here reads the database, writes anything, or knows what a conference
 * is: it takes a question and an answer and returns a number or null. The two
 * genuinely pure helpers - likert() and clamp() - are public so they can be
 * exercised without a row, which is what makes the boundary table in
 * tests/Unit/AnswerNormaliserTest.php cheap enough to be exhaustive.
 */
final class AnswerNormaliser
{
    public const MIN = 0.0;

    public const MAX = 100.0;

    public static function normalise(ReviewQuestion $question, ReviewAnswer $answer): ?float
    {
        // The TYPE decides which column means anything, never "the first column
        // that is not null": a question edited from Likert to Boolean before
        // the form locked leaves a stale value_int behind, and a `??` chain
        // over four columns would read it.
        return match ($question->type) {
            ReviewQuestionType::Likert => self::likert($answer->value_int, $question->scale_min, $question->scale_max),
            ReviewQuestionType::Boolean => self::boolean($answer->value_bool),
            ReviewQuestionType::Select => self::select($question, $answer->choice_key),
            // Spec 5.6, verbatim. Not even a numeric-looking comment.
            ReviewQuestionType::Text => null,
        };
    }

    /**
     * The spec formula, with two guards the sentence leaves implicit.
     *
     * `max <= min` (including both null, one null, or a legacy row where they
     * are equal) answers null rather than dividing by zero: a scale with no
     * range carries no information, and a DivisionByZeroError here is a 500 on
     * the ranking page.
     *
     * A value outside its own scale is clamped rather than extrapolated. Plan
     * 4's SubmitReview validates `between:scale_min,scale_max`, so a *submitted*
     * review cannot hold one - but a draft is deliberately unvalidated, the
     * Plan 6 import will carry whatever the legacy database holds, and an
     * organizer may narrow a scale before the form locks. A 7 on a 1-5 scale
     * would otherwise score 150 and drag a mean above 100.
     */
    public static function likert(?int $value, ?int $min, ?int $max): ?float
    {
        if ($value === null || $min === null || $max === null || $max <= $min) {
            return null;
        }

        return self::clamp((($value - $min) / ($max - $min)) * self::MAX);
    }

    public static function boolean(?bool $value): ?float
    {
        // Not `$value ? 100 : 0` over a nullable: false is an answer and null
        // is not, and collapsing them scores every unanswered yes/no as a no.
        return match ($value) {
            true => self::MAX,
            false => self::MIN,
            null => null,
        };
    }

    /**
     * Spec 5.6: "select options carry an optional score". The panel declares
     * that score to be 0-100 already
     * (ReviewQuestionsRelationManager's choice repeater:
     * `->numeric()->minValue(0)->maxValue(100)`), so there is no scale to
     * convert from - only a range to enforce, for the same reason likert()
     * clamps.
     *
     * A choice with no score, and a key that is no longer on the question,
     * both answer null: the first is the organizer saying "this choice means
     * nothing numerically", the second is a label edited before the form
     * locked. Neither is a zero.
     */
    public static function select(ReviewQuestion $question, ?string $key): ?float
    {
        if ($key === null || $key === '') {
            return null;
        }

        $score = $question->optionScore($key);

        return $score === null ? null : self::clamp((float) $score);
    }

    public static function clamp(float $value): float
    {
        return max(self::MIN, min(self::MAX, $value));
    }
}
