<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Submission;
use App\Models\SubmissionAuthor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubmissionAuthor> */
class SubmissionAuthorFactory extends Factory
{
    protected $model = SubmissionAuthor::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'sort' => 1,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'affiliation' => fake()->company(),
            'is_presenter' => false,
            'is_corresponding' => false,
        ];
    }

    public function corresponding(): static
    {
        return $this->state(fn () => ['is_corresponding' => true, 'is_presenter' => true]);
    }
}
