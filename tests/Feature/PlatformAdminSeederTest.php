<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\PlatformAdminSeeder;

it('refuses to seed without credentials', function () {
    config(['cass.admin_email' => '', 'cass.admin_password' => '']);

    expect(fn () => $this->seed(PlatformAdminSeeder::class))->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0);
});

it('creates a verified platform admin from config', function () {
    config(['cass.admin_email' => 'admin@example.org', 'cass.admin_password' => 'a-long-admin-password']);

    $this->seed(PlatformAdminSeeder::class);

    $user = User::query()->where('email', 'admin@example.org')->firstOrFail();
    expect($user->is_platform_admin)->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(password_verify('a-long-admin-password', $user->password))->toBeTrue();
});

it('is idempotent and keeps an existing name', function () {
    config(['cass.admin_email' => 'admin@example.org', 'cass.admin_password' => 'a-long-admin-password']);
    User::factory()->create(['email' => 'admin@example.org', 'name' => 'Ahmed']);

    $this->seed(PlatformAdminSeeder::class);

    expect(User::query()->where('email', 'admin@example.org')->count())->toBe(1)
        ->and(User::query()->where('email', 'admin@example.org')->value('name'))->toBe('Ahmed')
        ->and(User::query()->where('email', 'admin@example.org')->value('is_platform_admin'))->toBeTruthy();
});
