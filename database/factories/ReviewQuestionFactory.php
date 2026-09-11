<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReviewQuestionType;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewQuestion> */
class ReviewQuestionFactory extends Factory
{
    protected $model = ReviewQuestion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'review_form_id' => ReviewForm::factory(),
            'prompt' => fake()->sentence(10),
            'help_text' => null,
            'type' => ReviewQuestionType::Likert,
            'scale_min' => 1,
            'scale_max' => 5,
            'options' => null,
            'weight' => '1.00',
            'required' => true,
            'sort' => 0,
        ];
    }

    public function freeText(): static
    {
        return $this->state(fn () => [
            'type' => ReviewQuestionType::Text,
            'scale_min' => null,
            'scale_max' => null,
            'required' => false,
        ]);
    }
}
