<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Exceptions\AdminPasswordRefused;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Replace a platform admin's password from outside the admin panel, for an operator at the host: the admin's
 * own way is Profile. `cass:admin-password --force` is the caller. PlatformAdminSeeder never replaces an
 * existing admin's password, so nothing else on the console does.
 */
class ResetPlatformAdminPassword
{
    /**
     * The fewest characters a platform admin's password set from the console may have: here on top of Profile's
     * rule, Password::defaults(), so it is never weaker than one the admin could set in the panel, and in
     * PlatformAdminSeeder for CASS_ADMIN_PASSWORD.
     */
    public const MIN_LENGTH = 12;

    public function admin(string $email): ?User
    {
        return User::query()->where('email', $email)->where('is_platform_admin', true)->first();
    }

    /** @return list<string> empty when the password may be set */
    public function blockers(User $admin, string $password): array
    {
        $reasons = $admin->is_platform_admin === true
            ? []
            : ['That account is not a platform admin, and only a platform admin\'s password is replaced here.'];

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', 'min:'.self::MIN_LENGTH, Password::defaults()]],
        );

        foreach ($validator->errors()->all() as $message) {
            $reasons[] = (string) $message;
        }

        return $reasons;
    }

    public function handle(User $admin, string $password): void
    {
        $reasons = $this->blockers($admin, $password);

        if ($reasons !== []) {
            throw new AdminPasswordRefused($reasons);
        }

        // One transaction, so no password changes without its activity row.
        DB::transaction(function () use ($admin, $password): void {
            $admin->forceFill([
                'password' => $password,
                // A new token, as Laravel's own password reset sets: a browser remembered under the old password
                // is not signed back in by its cookie. Open sessions end through AuthenticateSession, which
                // compares the password hash each one was opened with.
                'remember_token' => Str::random(60),
            ])->save();

            activity()->performedOn($admin)->log('user.password_reset');
        });
    }
}
