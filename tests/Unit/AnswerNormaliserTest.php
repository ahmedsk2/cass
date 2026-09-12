<?php

declare(strict_types=1);

use App\Enums\ReviewQuestionType;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewQuestion;
use App\Support\Scoring\AnswerNormaliser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- The pure half: no models, no database ------------------------------

it('turns a likert value into a percentage of its own scale', function (?int $value, ?int $min, ?int $max, ?float $expected) {
    expect(AnswerNormaliser::likert($value, $min, $max))->toBe($expected);
})->with([
    'bottom of 1-5' => [1, 1, 5, 0.0],
    'middle of 1-5' => [3, 1, 5, 50.0],
    'top of 1-5' => [5, 1, 5, 100.0],
    'an awkward point of 1-5' => [2, 1, 5, 25.0],
    'bottom of 0-10' => [0, 0, 10, 0.0],
    'middle of 0-10' => [5, 0, 10, 50.0],
    'a two point scale' => [1, 0, 1, 100.0],
    // Rule 1: clamped, not extrapolated.
    'above its own scale' => [7, 1, 5, 100.0],
    'below its own scale' => [0, 1, 5, 0.0],
    // Rule 2: no range, no information. None of these may divide by zero.
    'min equals max' => [3, 3, 3, null],
    'max below min' => [3, 5, 1, null],
    'no bounds at all' => [3, null, null, null],
    'only a minimum' => [3, 1, null, null],
    'only a maximum' => [3, null, 5, null],
    // No answer is not a zero answer.
    'unanswered' => [null, 1, 5, null],
]);

it('never divides by zero, whatever the bounds', function () {
    // The explicit statement of rule 2: a DivisionByZeroError here is a 500 on
    // the ranking page, and the guard is one line that is easy to "simplify".
    //
    // Assert the ANSWER, not the absence of one exception class. Pest's
    // ->not->toThrow() catches the ExpectationFailedException that a
    // non-matching Throwable raises and treats it as a pass
    // (pest/src/Expectations/OppositeExpectation.php:627-641), so
    // `->not->toThrow(DivisionByZeroError::class)` is green even when the class
    // does not exist yet - it could never be shown failing first.
    foreach ([[3, 3, 3], [0, 0, 0], [10, 10, 10], [1, 5, 5]] as [$value, $min, $max]) {
        expect(AnswerNormaliser::likert($value, $min, $max))->toBeNull();
    }
});

it('clamps any score into the reportable range', function () {
    expect(AnswerNormaliser::clamp(-5.0))->toBe(0.0)
        ->and(AnswerNormaliser::clamp(0.0))->toBe(0.0)
        ->and(AnswerNormaliser::clamp(50.0))->toBe(50.0)
        ->and(AnswerNormaliser::clamp(100.0))->toBe(100.0)
        ->and(AnswerNormaliser::clamp(150.0))->toBe(100.0);
});

// --- The model half -----------------------------------------------------

function answerFor(ReviewQuestion $question, array $columns): ReviewAnswer
{
    $review = Review::factory()->create(['review_form_id' => $question->review_form_id]);

    $answer = new ReviewAnswer;
    $answer->forceFill([
        'review_id' => $review->getKey(),
        'review_question_id' => $question->getKey(),
        'value_int' => null, 'value_text' => null, 'value_bool' => null, 'choice_key' => null,
        ...$columns,
    ])->save();

    return $answer->fresh() ?? $answer;
}

it('scores a likert answer from the question it answers', function () {
    $question = ReviewQuestion::factory()->create(['scale_min' => 1, 'scale_max' => 5]);

    expect(AnswerNormaliser::normalise($question, answerFor($question, ['value_int' => 4])))->toBe(75.0)
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['value_int' => null])))->toBeNull();
});

it('scores yes as a hundred and no as a zero', function () {
    $question = ReviewQuestion::factory()->create([
        'type' => ReviewQuestionType::Boolean,
        'scale_min' => null,
        'scale_max' => null,
    ]);

    // `false` is an answer and must not be swallowed by a `??`. This is the
    // case a naive implementation gets wrong and no other case catches.
    expect(AnswerNormaliser::normalise($question, answerFor($question, ['value_bool' => true])))->toBe(100.0)
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['value_bool' => false])))->toBe(0.0)
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['value_bool' => null])))->toBeNull();
});

it('scores a select answer from the chosen option, and only when it carries one', function () {
    $question = ReviewQuestion::factory()->create([
        'type' => ReviewQuestionType::Select,
        'scale_min' => null,
        'scale_max' => null,
        // The panel's own editor declares these 0-100
        // (ReviewQuestionsRelationManager:103-104), so they are passed through
        // rather than converted from some other scale.
        'options' => [
            ['label' => 'Strong accept', 'score' => 100],
            ['label' => 'Weak accept', 'score' => 60],
            ['label' => 'No opinion', 'score' => null],
        ],
    ]);

    expect(AnswerNormaliser::normalise($question, answerFor($question, ['choice_key' => 'Strong accept'])))->toBe(100.0)
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['choice_key' => 'Weak accept'])))->toBe(60.0)
        // A choice with no score contributes nothing at all - not a zero.
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['choice_key' => 'No opinion'])))->toBeNull()
        // A key that is not on the question any more: the label was edited
        // before the form locked. Visible as a missing score, never as a zero.
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['choice_key' => 'Deleted choice'])))->toBeNull()
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['choice_key' => null])))->toBeNull();
});

it('never scores a text answer', function () {
    $question = ReviewQuestion::factory()->freeText()->create();

    // Spec 5.6: "text answers do not score." Not even a numeric-looking one -
    // this is the case that turns "4" into a silent 4/100.
    expect(AnswerNormaliser::normalise($question, answerFor($question, ['value_text' => 'Excellent work'])))->toBeNull()
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['value_text' => '100'])))->toBeNull();
});

it('reads the value column the question type names, not the first one that is set', function () {
    // A question edited from Likert to Boolean before the form locked leaves a
    // value_int behind. The type decides which column means anything.
    $question = ReviewQuestion::factory()->create([
        'type' => ReviewQuestionType::Boolean,
        'scale_min' => null,
        'scale_max' => null,
    ]);

    expect(AnswerNormaliser::normalise($question, answerFor($question, ['value_int' => 5, 'value_bool' => false])))->toBe(0.0);
});
