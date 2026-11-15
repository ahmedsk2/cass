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
 * that cannot hide what is typed is refused rather than echoed. CASS_ADMIN_PASSWORD is read only when
 * --no-interaction (-n) is given, and then as the configuration cached at the container's boot. Symfony also
 * stops questions for --quiet, --silent and a negative SHELL_VERBOSITY; such a run is refused, so neither a
 * quiet run nor a value left in the environment sets a password nobody typed or asked for.
 *
 * Refusals are written at quiet verbosity: --quiet hides every other line, but not why nothing changed.
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
            $this->refuse(
                $email === ''
                    ? 'CASS_ADMIN_EMAIL is empty, so there is no platform admin to change.'
                    : "No platform admin has the address [{$email}] in CASS_ADMIN_EMAIL.",
                '"php artisan db:seed --force" creates the platform admin. Nothing was changed.',
            );

            return self::FAILURE;
        }

        // Before anything is asked for, so an operator who typed the command by mistake is not led into typing
        // a password first.
        if ($this->option('force') !== true) {
            $this->refuse(
                "This replaces the password of the platform admin [{$email}], and every session of that account ends on its next request.",
                'Re-run with --force if that is what you want. Nothing was changed.',
            );

            return self::FAILURE;
        }

        $password = $this->newPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $reasons = $reset->blockers($admin, $password);

        if ($reasons !== []) {
            $this->refuse(...[...$reasons, 'Nothing was changed.']);

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

        // Asked by name, not by isInteractive(), which --quiet and --silent also turn false.
        if ($this->input->hasParameterOption(['--no-interaction', '-n'], true)) {
            if ($fromEnvironment === '') {
                $this->refuse('CASS_ADMIN_PASSWORD is empty, and --no-interaction stops the command asking for a password. Nothing was changed.');

                return null;
            }

            $this->line('The new password is the value of CASS_ADMIN_PASSWORD.');

            return $fromEnvironment;
        }

        if (! $this->input->isInteractive()) {
            $this->refuse('This run cannot ask for the password: --quiet, --silent and a negative SHELL_VERBOSITY stop questions. Run it on a terminal, or pass --no-interaction to use CASS_ADMIN_PASSWORD. Nothing was changed.');

            return null;
        }

        // Long enough to be a password, so a secret is still there; the runbook's placeholder is shorter.
        if (mb_strlen($fromEnvironment) >= ResetPlatformAdminPassword::MIN_LENGTH) {
            $this->line('CASS_ADMIN_PASSWORD is not used on a terminal, and only the first seed needs it: replace it with the placeholder, as the runbook\'s One-time setup step 6 says.');
        }

        try {
            // false: on a terminal that cannot hide the answer, refuse rather than fall back to echoing it.
            $password = (string) $this->secret('New password', false);
            $again = (string) $this->secret('The same password again', false);
        } catch (ConsoleInputException) {
            $this->refuse('No password was read: this terminal cannot hide what is typed, or there is nothing to read. Use "docker exec -it", or pass --no-interaction with CASS_ADMIN_PASSWORD set. Nothing was changed.');

            return null;
        }

        if (! hash_equals($password, $again)) {
            $this->refuse('The two passwords differ. Nothing was changed.');

            return null;
        }

        return $password;
    }

    /** Each line as an error, at quiet verbosity. */
    private function refuse(string ...$lines): void
    {
        foreach ($lines as $line) {
            $this->error($line, 'quiet');
        }
    }
}
