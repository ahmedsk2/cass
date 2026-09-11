<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConferenceStatus;
use App\Enums\ReviewMode;
use App\Models\Conference;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conference> */
class ConferenceFactory extends Factory
{
    protected $model = Conference::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory()->approved(),
            // company(), not catchPhrase(): catchPhrase() lives only in the
            // locale-specific Faker providers, so it is absent from
            // Faker\Generator's @method list (Larastan level 6 rejects it) and
            // would disappear entirely under another APP_FAKER_LOCALE.
            'name' => fake()->unique()->company().' Conference',
            'short_description' => fake()->sentence(14),
            'description' => '<p>'.fake()->paragraph(4).'</p>',
            'venue' => fake()->company().' Convention Centre',
            'city' => 'Riyadh',
            'country' => 'SA',
            'starts_at' => now()->addMonths(6)->toDateString(),
            'ends_at' => now()->addMonths(6)->addDays(2)->toDateString(),
            'timezone' => 'Asia/Riyadh',
            'submission_opens_at' => null,
            'submission_deadline' => null,
            'review_deadline' => null,
            'review_mode' => ReviewMode::OpenPool,
            'blind_review' => true,
            'reviewers_per_submission' => 2,
            'word_limit' => 500,
            'max_files' => 3,
            'allowed_file_types' => ['pdf'],
            'presentation_types' => ['oral', 'poster', 'either'],
            'terms' => 'Presenting authors must register for the conference.',
            'status' => ConferenceStatus::Draft,
        ];
    }

    /** A conference with a valid, currently open submission window. */
    public function withSubmissionWindow(): static
    {
        return $this->state(fn () => [
            'submission_opens_at' => now()->subWeek(),
            'submission_deadline' => now()->addMonth(),
            'review_deadline' => now()->addMonths(2),
        ]);
    }

    public function upcomingWindow(): static
    {
        return $this->state(fn () => [
            'submission_opens_at' => now()->addWeek(),
            'submission_deadline' => now()->addMonths(2),
            'review_deadline' => now()->addMonths(3),
        ]);
    }

    public function published(): static
    {
        return $this->withSubmissionWindow()->state(fn () => [
            'status' => ConferenceStatus::Open,
            'published_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->withSubmissionWindow()->state(fn () => [
            'status' => ConferenceStatus::Closed,
            'published_at' => now()->subMonth(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => ConferenceStatus::Archived,
            'published_at' => now()->subYear(),
        ]);
    }

    public function assignedReview(): static
    {
        return $this->state(fn () => [
            'review_mode' => ReviewMode::Assigned,
            'reviewers_per_submission' => 3,
        ]);
    }
}
