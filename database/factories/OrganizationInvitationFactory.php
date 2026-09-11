<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OrganizationInvitation> */
class OrganizationInvitationFactory extends Factory
{
    protected $model = OrganizationInvitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory()->approved(),
            'email' => fake()->unique()->safeEmail(),
            'role' => OrganizationRole::Member,
            // A hash of *something*, not of a token any test can use: a test
            // that needs a working link generates the plaintext itself and
            // passes ['token_hash' => InvitationToken::hash($plain)].
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
