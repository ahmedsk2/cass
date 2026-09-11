<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewAnswer> */
class ReviewAnswerFactory extends Factory
{
    protected $model = ReviewAnswer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'review_id' => Review::factory(),
            'review_question_id' => ReviewQuestion::factory(),
            'value_int' => 4,
            'value_text' => null,
            'value_bool' => null,
            'choice_key' => null,
        ];
    }
}
