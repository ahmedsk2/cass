<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Users\ResetPlatformAdminPassword;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The platform admin: the account CASS_ADMIN_EMAIL names, created with CASS_ADMIN_PASSWORD as its password.
 *
 * Once that account is a platform admin the seeder never changes its password, so a later run leaves its
 * sign-in as it is and CASS_ADMIN_PASSWORD may be gone from the environment. Replacing that password is
 * `php artisan cass:admin-password --force`.
 *
 * An existing account that is not a platform admin yet becomes one only with CASS_ADMIN_PASSWORD, which then
 * replaces its password: the admin panel is never handed to a password somebody else chose, such as that of
 * an organizer who registered with the address first.
 */
class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) config('cass.admin_email'));

        if ($email === '') {
            throw new RuntimeException('CASS_ADMIN_EMAIL must be set to seed the platform admin.');
        }

        $user = User::query()->firstOrNew(['email' => $email]);

        if ($user->is_platform_admin === true) {
            $this->command?->info("The platform admin [{$email}] exists, so its password is left as it is and CASS_ADMIN_PASSWORD is not needed. Replace the password with \"php artisan cass:admin-password --force\".");
        } else {
            $password = (string) config('cass.admin_password');

            // Characters, not bytes, as the validator's min rule counts them for cass:admin-password.
            if (mb_strlen($password) < ResetPlatformAdminPassword::MIN_LENGTH) {
                throw new RuntimeException(sprintf(
                    'CASS_ADMIN_PASSWORD (%d+ characters) must be set to make [%s] the platform admin.',
                    ResetPlatformAdminPassword::MIN_LENGTH,
                    $email,
                ));
            }

            $user->forceFill([
                'password' => $password,
                // A browser the account's previous holder told to remember them is not signed back in by its cookie.
                'remember_token' => Str::random(60),
            ]);
        }

        $user->forceFill([
            'name' => $user->name ?: 'Platform Admin',
            'is_platform_admin' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
    }
}
