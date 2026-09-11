<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Enums\ReviewStatus;
use App\Models\Review;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Review> */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory()->submitted(),
            'reviewer_user_id' => User::factory(),
            // Derived from the submission rather than a free-standing
            // ReviewForm::factory(): a review answered against another
            // conference's form would satisfy every foreign key and mean
            // nothing. Factory closures receive the already-resolved
            // attributes, so `submission_id` is a real id by the time this runs.
            'review_form_id' => function (array $attributes): int {
                $submission = Submission::query()->findOrFail($attributes['submission_id']);

                return app(CreateDefaultReviewForm::class)->handle($submission->conference)->id;
            },
            'status' => ReviewStatus::Draft,
            'submitted_at' => null,
            'reopened_at' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => ReviewStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }
}
