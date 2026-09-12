<?php

declare(strict_types=1);

use App\Enums\ReviewQuestionType;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Support\Scoring\ReviewScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- The pure half ------------------------------------------------------

it('takes the weighted mean of what was scored', function (array $scored, ?float $expected) {
    expect(ReviewScorer::weightedMean($scored))->toBe($expected);
})->with([
    'nothing scored' => [[], null],
    'one answer' => [[['score' => 80.0, 'weight' => 1.0]], 80.0],
    'equal weights' => [[['score' => 100.0, 'weight' => 1.0], ['score' => 0.0, 'weight' => 1.0]], 50.0],
    'one worth three' => [[['score' => 100.0, 'weight' => 3.0], ['score' => 0.0, 'weight' => 1.0]], 75.0],
    'fractional weights' => [[['score' => 100.0, 'weight' => 0.5], ['score' => 50.0, 'weight' => 0.25]], 83.33],
    // Rule 3: a zero weight is "do not count this", not "count this as zero".
    'a zero weight is out' => [[['score' => 100.0, 'weight' => 1.0], ['score' => 0.0, 'weight' => 0.0]], 100.0],
    'a negative weight is out' => [[['score' => 100.0, 'weight' => 1.0], ['score' => 0.0, 'weight' => -2.0]], 100.0],
    'every weight zero' => [[['score' => 100.0, 'weight' => 0.0], ['score' => 0.0, 'weight' => 0.0]], null],
    // Rounded once, on return, because the column is decimal(5,2).
    'rounds to two places' => [[['score' => 100.0, 'weight' => 1.0], ['score' => 0.0, 'weight' => 2.0]], 33.33],
]);

it('never divides by zero however the weights are set', function () {
    // The value, not the absence of one exception class: Pest's ->not->toThrow()
    // passes for a DIFFERENT Throwable too (OppositeExpectation.php:627-641),
    // so it would be green with ReviewScorer missing entirely.
    expect(ReviewScorer::weightedMean([['score' => 50.0, 'weight' => 0.0]]))->toBeNull();
});

// --- The model half -----------------------------------------------------

function reviewWithAnswers(array $questions, array $values): Review
{
    $form = ReviewForm::factory()->create();
    $review = Review::factory()->create(['review_form_id' => $form->getKey()]);

    foreach ($questions as $index => $attributes) {
        $question = ReviewQuestion::factory()->for($form)->create($attributes);
        $answer = new ReviewAnswer;
        $answer->forceFill([
            'review_id' => $review->getKey(),
            'review_question_id' => $question->getKey(),
            'value_int' => null, 'value_text' => null, 'value_bool' => null, 'choice_key' => null,
            ...($values[$index] ?? []),
        ])->save();
    }

    return $review->fresh() ?? $review;
}

it('weights a real review by its questions', function () {
    $review = reviewWithAnswers(
        [
            ['scale_min' => 1, 'scale_max' => 5, 'weight' => '3.00'],
            ['scale_min' => 1, 'scale_max' => 5, 'weight' => '1.00'],
        ],
        [['value_int' => 5], ['value_int' => 1]],
    );

    // (100 * 3 + 0 * 1) / 4 = 75
    expect(app(ReviewScorer::class)->forReview($review))->toBe(75.0);
});

it('ignores the text answers and scores the rest', function () {
    $review = reviewWithAnswers(
        [
            ['scale_min' => 1, 'scale_max' => 5, 'weight' => '1.00'],
            ['type' => ReviewQuestionType::Text, 'scale_min' => null, 'scale_max' => null, 'weight' => '5.00', 'required' => false],
        ],
        [['value_int' => 4], ['value_text' => 'A long comment that must not count for anything']],
    );

    // The comment's weight of 5 must not appear in the denominator either.
    expect(app(ReviewScorer::class)->forReview($review))->toBe(75.0);
});

it('has no score at all when nothing on the form scores', function () {
    $review = reviewWithAnswers(
        [['type' => ReviewQuestionType::Text, 'scale_min' => null, 'scale_max' => null, 'required' => false]],
        [['value_text' => 'Only words']],
    );

    // Spec 12's "weighting" case that an implementation most often gets wrong
    // by returning 0.0: an all-text review is UNSCORED, and an unscored review
    // must not drag a submission's mean to zero.
    expect(app(ReviewScorer::class)->forReview($review))->toBeNull();
});

it('has no score when the review has no answers at all', function () {
    $review = Review::factory()->create();

    expect(app(ReviewScorer::class)->forReview($review))->toBeNull();
});

it('skips an answer whose question belongs to another form', function () {
    // review_answers.review_question_id is a real foreign key but it does not
    // constrain the question to the review's own form. ReviewScorer reads the
    // question each answer points at, so the arithmetic is right either way -
    // this pins that it does not crash or double-count.
    $review = reviewWithAnswers([['scale_min' => 1, 'scale_max' => 5, 'weight' => '1.00']], [['value_int' => 5]]);

    $stray = ReviewQuestion::factory()->create(['scale_min' => 1, 'scale_max' => 5, 'weight' => '1.00']);
    $answer = new ReviewAnswer;
    $answer->forceFill([
        'review_id' => $review->getKey(),
        'review_question_id' => $stray->getKey(),
        'value_int' => 1, 'value_text' => null, 'value_bool' => null, 'choice_key' => null,
    ])->save();

    expect(app(ReviewScorer::class)->forReview($review->fresh() ?? $review))->toBe(50.0);
});
