<?php

declare(strict_types=1);

use App\Actions\Users\ResetPlatformAdminPassword;
use App\Exceptions\AdminPasswordRefused;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('will not set the password of an account that is not a platform admin', function () {
    $user = User::factory()->create();

    expect(app(ResetPlatformAdminPassword::class)->blockers($user, 'Fresh-admin-pass-2026'))
        ->toBe(['That account is not a platform admin, and only a platform admin\'s password is replaced here.']);
});

it('gives a password under twelve characters one sentence about its length, not Profile\'s ten as well', function () {
    $admin = User::factory()->platformAdmin()->create();

    expect(app(ResetPlatformAdminPassword::class)->blockers($admin, 'removed'))
        ->toBe(['The password field must be at least 12 characters.']);
});

it('throws on a password the rule refuses, and changes nothing', function () {
    $admin = User::factory()->platformAdmin()->create();

    expect(fn () => app(ResetPlatformAdminPassword::class)->handle($admin, 'Short-pw-26'))
        ->toThrow(fn (AdminPasswordRefused $refused) => expect($refused->reasons)
            ->toBe(['The password field must be at least 12 characters.']));

    $stored = User::query()->whereKey($admin->getKey())->sole();

    expect($stored->password)->toBe($admin->password)
        ->and($stored->remember_token)->toBe($admin->remember_token)
        ->and(Activity::query()->where('description', 'user.password_reset')->exists())->toBeFalse();
});
