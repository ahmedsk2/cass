<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewAssignment> */
class ReviewAssignmentFactory extends Factory
{
    protected $model = ReviewAssignment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory()->submitted(),
            'reviewer_user_id' => User::factory(),
            'assigned_by' => null,
            'assigned_at' => now(),
        ];
    }
}
