<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conference;
use App\Models\ReviewerInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ReviewerInvitation> */
class ReviewerInvitationFactory extends Factory
{
    protected $model = ReviewerInvitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conference_id' => Conference::factory()->published(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'affiliation' => null,
            'token_hash' => hash('sha256', (string) Str::ulid()),
            'invited_by' => null,
            'expires_at' => now()->addDays((int) config('cass.invitations.expiry_days')),
            'accepted_at' => null,
            'accepted_by' => null,
            'revoked_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
