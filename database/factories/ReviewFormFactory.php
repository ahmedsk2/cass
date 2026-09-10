<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conference;
use App\Models\ReviewForm;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewForm> */
class ReviewFormFactory extends Factory
{
    protected $model = ReviewForm::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conference_id' => Conference::factory(),
            'name' => 'Review form',
            'is_active' => true,
            'locked_at' => null,
        ];
    }

    /** Stands in for "Plan 4 recorded the first submitted review". */
    public function locked(): static
    {
        return $this->state(fn () => ['locked_at' => now()]);
    }
}
