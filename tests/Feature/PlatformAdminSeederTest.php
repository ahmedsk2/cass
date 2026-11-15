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

it('leaves an existing platform admin\'s password as it is when seeded again with another one', function () {
    config(['cass.admin_email' => 'admin@example.org', 'cass.admin_password' => 'the-first-admin-password']);
    $this->seed(PlatformAdminSeeder::class);
    $hash = User::query()->where('email', 'admin@example.org')->value('password');

    config(['cass.admin_password' => 'a-different-admin-password']);
    $this->seed(PlatformAdminSeeder::class);

    $admin = User::query()->where('email', 'admin@example.org')->sole();
    expect($admin->password)->toBe($hash)
        ->and(password_verify('the-first-admin-password', $admin->password))->toBeTrue()
        ->and($admin->is_platform_admin)->toBeTrue();
});

it('runs db:seed without CASS_ADMIN_PASSWORD once the platform admin exists, and names the command that replaces the password', function () {
    config(['cass.admin_email' => 'admin@example.org', 'cass.admin_password' => null]);
    $admin = User::factory()->platformAdmin()->create(['email' => 'admin@example.org']);

    $this->artisan('db:seed')
        ->expectsOutputToContain('cass:admin-password --force')
        ->assertExitCode(0);

    expect($admin->fresh()?->password)->toBe($admin->password)
        ->and($admin->fresh()?->is_platform_admin)->toBeTrue();
});

it('makes an existing account the platform admin only with CASS_ADMIN_PASSWORD, which replaces its password', function () {
    // An account somebody registered with the admin's address before the first seed: its password is theirs.
    config(['cass.admin_email' => 'admin@example.org', 'cass.admin_password' => null]);
    $account = User::factory()->unverified()->create(['email' => 'admin@example.org']);

    expect(fn () => $this->seed(PlatformAdminSeeder::class))->toThrow(RuntimeException::class);
    expect($account->fresh()?->is_platform_admin)->toBeFalse()
        ->and($account->fresh()?->hasVerifiedEmail())->toBeFalse();

    config(['cass.admin_password' => 'a-long-admin-password']);
    $this->seed(PlatformAdminSeeder::class);

    $admin = User::query()->whereKey($account->getKey())->sole();
    expect($admin->is_platform_admin)->toBeTrue()
        ->and($admin->hasVerifiedEmail())->toBeTrue()
        ->and(password_verify('a-long-admin-password', $admin->password))->toBeTrue()
        ->and(password_verify('password', $admin->password))->toBeFalse()
        // A browser the previous holder ticked Remember me in is not signed back in by its cookie.
        ->and($admin->remember_token)->not->toBe($account->remember_token);
});

it('refuses to create the platform admin with a password under twelve characters', function () {
    config(['cass.admin_email' => 'admin@example.org', 'cass.admin_password' => 'elevenchars']);

    expect(fn () => $this->seed(PlatformAdminSeeder::class))->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0);
});

it('creates the platform admin with a password of exactly twelve characters', function () {
    config(['cass.admin_email' => 'admin@example.org', 'cass.admin_password' => 'twelve-chars']);

    $this->seed(PlatformAdminSeeder::class);

    expect(password_verify('twelve-chars', User::query()->where('email', 'admin@example.org')->sole()->password))->toBeTrue();
});

it('counts the password in characters, not bytes, as the admin-password command does', function () {
    // Eleven characters in twenty-two bytes.
    config(['cass.admin_email' => 'admin@example.org', 'cass.admin_password' => 'ééééééééééé']);

    expect(fn () => $this->seed(PlatformAdminSeeder::class))->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0);
});
