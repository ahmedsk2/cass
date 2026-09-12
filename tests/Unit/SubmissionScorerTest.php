<?php

declare(strict_types=1);

use App\Support\Scoring\SubmissionScorer;

// No database at all: this class is arithmetic over a list of numbers, and
// ComputeSubmissionScore (Task 3) is the thing that reads rows.

it('summarises a list of review scores', function (array $scores, int $submitted, ?float $score, ?float $spread) {
    $summary = SubmissionScorer::summarise($scores, $submitted);

    expect($summary['score'])->toBe($score)
        ->and($summary['spread'])->toBe($spread)
        ->and($summary['review_count'])->toBe($submitted);
})->with([
    // Spec 5.6: "spread = sample standard deviation", which divides by n-1 and
    // is undefined below two observations.
    'no reviews' => [[], 0, null, null],
    'one review' => [[80.0], 1, 80.0, null],
    'two that agree' => [[80.0, 80.0], 2, 80.0, 0.0],
    'two that do not' => [[70.0, 90.0], 2, 80.0, 14.14],
    'three' => [[60.0, 70.0, 80.0], 3, 70.0, 10.0],
    'three with a decimal' => [[61.5, 70.25, 80.0], 3, 70.58, 9.25],
    // The two numbers answer different questions (rule 4): three people
    // reviewed, one of them filled in an all-text form.
    'more reviewers than scores' => [[70.0, 90.0], 3, 80.0, 14.14],
    // Submitted reviews, none of which scored: the count still stands.
    'reviewed but unscored' => [[], 2, null, null],
]);

it('is not fooled by a zero score', function () {
    // 0.0 is a real score - every reviewer gave the bottom of the scale - and
    // an implementation that uses `?:` or array_filter() without a strict
    // callback loses it.
    $summary = SubmissionScorer::summarise([0.0, 0.0], 2);

    expect($summary['score'])->toBe(0.0)
        ->and($summary['spread'])->toBe(0.0)
        ->and($summary['review_count'])->toBe(2);
});

it('rounds the mean and the spread to two places', function () {
    $summary = SubmissionScorer::summarise([1 / 3 * 100, 2 / 3 * 100], 2);

    expect($summary['score'])->toBe(50.0)
        ->and($summary['spread'])->toBe(23.57);
});

it('never divides by zero', function () {
    // The values, not the absence of one exception class: Pest's
    // ->not->toThrow() passes for any other Throwable as well
    // (OppositeExpectation.php:627-641), so it would be green before the class
    // exists and could never be shown failing first.
    expect(SubmissionScorer::summarise([], 0)['score'])->toBeNull()
        ->and(SubmissionScorer::summarise([], 0)['spread'])->toBeNull()
        ->and(SubmissionScorer::summarise([50.0], 1)['score'])->toBe(50.0)
        ->and(SubmissionScorer::summarise([50.0], 1)['spread'])->toBeNull();
});
