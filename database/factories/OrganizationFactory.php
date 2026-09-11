<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Organization> */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Society',
            'type' => OrganizationType::Society,
            'country' => 'SA',
            'website' => fake()->url(),
            'contact_email' => fake()->safeEmail(),
            'publish_contact_email' => false,
            'purpose' => fake()->sentence(12),
            'status' => OrganizationStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => OrganizationStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function suspended(string $reason = 'Not a recognised organization.'): static
    {
        return $this->state(fn () => [
            'status' => OrganizationStatus::Suspended,
            'status_reason' => $reason,
        ]);
    }
}
