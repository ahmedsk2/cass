<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conference;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Track> */
class TrackFactory extends Factory
{
    protected $model = Track::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conference_id' => Conference::factory(),
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(8),
            'sort' => 0,
        ];
    }
}
