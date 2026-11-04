<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Hashed once per run, as $password is: the recovery codes every enrolled factory admin holds.
     *
     * @var list<string>|null
     */
    protected static ?array $recoveryCodes = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A platform administrator enrolled with an authenticator app, as the admin panel requires: the secret and
     * the hashed recovery codes Filament's set-up action stores. withoutSecondFactor() is the admin before enrolment.
     */
    public function platformAdmin(): static
    {
        return $this->state(fn (): array => [
            'is_platform_admin' => true,
            'app_authentication_secret' => AppAuthentication::make()->generateSecret(),
            'app_authentication_recovery_codes' => static::$recoveryCodes ??= array_map(
                static fn (string $code): string => Hash::make($code),
                AppAuthentication::make()->generateRecoveryCodes(),
            ),
        ]);
    }

    /** No second factor yet: the admin panel sends this user to set one up. */
    public function withoutSecondFactor(): static
    {
        return $this->state(fn (): array => [
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ]);
    }
}
