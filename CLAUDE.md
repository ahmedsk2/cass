# CASS — session guide

Conference Abstract Submission System. Multi-tenant Laravel 13 + Filament 5. Spec:
`docs/superpowers/specs/2026-09-10-cass-v2-design.md`. Plans: `docs/superpowers/plans/`.

## Rules

- Verify by exit code: `php artisan test > /tmp/t.log 2>&1; echo "rc=$?"`. Never gate a commit on piped output.
- A new test must be shown failing before the code that makes it pass.
- Business logic lives in `app/Actions`. Filament resources and Livewire components validate and delegate.
- Every model with a tenant has an `organization()` relation and a policy. Every panel test includes a cross-tenant negative case.
- Tokens are stored hashed. Secrets only in `.env`; `.env.example` lists every variable.
- Never commit `Legacy/`, `legacy-review.md`, `assets/envato/`.
- Migrations are never run at container boot. The owner runs them in production.
- Composer on this machine: run `php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar <args>` from Git Bash. The `composer.bat` wrapper passes through cmd.exe and silently strips `^` from version constraints.

## Commands

- Tests: `php artisan test` (SQLite in-memory). MySQL suite: `docker compose -f docker-compose.dev.yml up -d` then `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass php artisan test`.
- Static analysis: `./vendor/bin/phpstan analyse`. Style: `./vendor/bin/pint --test`.
- Dev server: `composer run dev` (or `php artisan serve`), Mailpit UI at http://localhost:8025.
