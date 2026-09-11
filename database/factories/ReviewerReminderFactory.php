<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReminderThreshold;
use App\Models\Conference;
use App\Models\ReviewerReminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewerReminder> */
class ReviewerReminderFactory extends Factory
{
    protected $model = ReviewerReminder::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conference_id' => Conference::factory()->published(),
            'user_id' => User::factory(),
            'threshold' => ReminderThreshold::Days7,
            'sent_at' => now(),
        ];
    }
}
