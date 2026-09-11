<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReviewerStatus;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConferenceReviewer> */
class ConferenceReviewerFactory extends Factory
{
    protected $model = ConferenceReviewer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conference_id' => Conference::factory()->published(),
            'user_id' => User::factory(),
            'status' => ReviewerStatus::Active,
            'affiliation' => null,
            'invited_by' => null,
            'invited_at' => now()->subWeek(),
            'accepted_at' => now()->subDays(6),
            'removed_at' => null,
        ];
    }

    public function removed(): static
    {
        return $this->state(fn () => [
            'status' => ReviewerStatus::Removed,
            'removed_at' => now(),
        ]);
    }
}
