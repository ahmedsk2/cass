<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Users\ResetPlatformAdminPassword;
use App\Models\User;
use Illuminate\Console\Command;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleInputException;

/**
 * Replace the platform admin's password from a shell on the host, for a lost password or one somebody else
 * may know. PlatformAdminSeeder creates the admin and never replaces an existing admin's password, so this is
 * the only console path that does; the admin's own is Profile.
 *
 * The admin is the account CASS_ADMIN_EMAIL names, and it has to be a platform admin already.
 *
 * The password is never written to the output. On a terminal it is asked for twice, hidden, and a terminal
 * that cannot hide what is typed is refused rather than echoed. CASS_ADMIN_PASSWORD is read only under
 * --no-interaction, so a value left in the environment after the first seed never becomes the password of an
 * operator who meant to type a new one.
 *
 * Exit codes: 0, replaced; 1, nothing changed (no such admin, no --force, no password, or one the rule refuses).
 */
class AdminPasswordCommand extends Command
{
    protected $signature = 'cass:admin-password
        {--force : Required. Replaces the password, and every session of that account ends.}';

    protected $description = 'Replace the password of the platform admin CASS_ADMIN_EMAIL names, asked for without being shown.';

    public function handle(ResetPlatformAdminPassword $reset): int
    {
        $email = trim((string) config('cass.admin_email'));
        $admin = $email === '' ? null : $reset->admin($email);

        if (! $admin instanceof User) {
            $this->error($email === ''
                ? 'CASS_ADMIN_EMAIL is empty, so there is no platform admin to change.'
                : "No platform admin has the address [{$email}] in CASS_ADMIN_EMAIL.");
            $this->error('"php artisan db:seed --force" creates the platform admin. Nothing was changed.');

            return self::FAILURE;
        }

        // Before anything is asked for, so an operator who typed the command by mistake is not led into typing
        // a password first.
        if ($this->option('force') !== true) {
            $this->error("This replaces the password of the platform admin [{$email}], and every session of that account ends on its next request.");
            $this->error('Re-run with --force if that is what you want. Nothing was changed.');

            return self::FAILURE;
        }

        $password = $this->newPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $reasons = $reset->blockers($admin, $password);

        if ($reasons !== []) {
            foreach ($reasons as $reason) {
                $this->error($reason);
            }

            $this->error('Nothing was changed.');

            return self::FAILURE;
        }

        $reset->handle($admin, $password);

        $this->info("The password of the platform admin [{$email}] is replaced. Every session of that account ends on its next request.");

        return self::SUCCESS;
    }

    /** The new password, or null once the reason there is none has been printed. */
    private function newPassword(): ?string
    {
        $fromEnvironment = (string) config('cass.admin_password');

        if (! $this->input->isInteractive()) {
            if ($fromEnvironment === '') {
                $this->error('CASS_ADMIN_PASSWORD is empty, and --no-interaction stops the command asking for a password. Nothing was changed.');

                return null;
            }

            $this->line('The new password is the value of CASS_ADMIN_PASSWORD.');

            return $fromEnvironment;
        }

        if ($fromEnvironment !== '') {
            $this->line('CASS_ADMIN_PASSWORD is not used on a terminal. Take it out of the environment: the seeder no longer needs it.');
        }

        try {
            // false: on a terminal that cannot hide the answer, refuse rather than fall back to echoing it.
            $password = (string) $this->secret('New password', false);
            $again = (string) $this->secret('The same password again', false);
        } catch (ConsoleInputException) {
            $this->error('No password was read: this terminal cannot hide what is typed, or there is nothing to read. Use "docker exec -it", or pass --no-interaction with CASS_ADMIN_PASSWORD set. Nothing was changed.');

            return null;
        }

        if (! hash_equals($password, $again)) {
            $this->error('The two passwords differ. Nothing was changed.');

            return null;
        }

        return $password;
    }
}
