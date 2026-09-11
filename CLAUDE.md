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
- `ext-sockets` must be on, or `composer install` fails the platform check: `pestphp/pest-plugin-browser` requires it. Windows PHP ships `php_sockets.dll` but leaves it commented out — uncomment `extension=sockets` in `php.ini` (`php --ini` finds the file). CI turns it on through `extensions:` in `.github/workflows/ci.yml`; the production image never installs dev dependencies and does not need it.

## Commands

- Tests: `php artisan test` (SQLite in-memory). MySQL suite: `docker compose -f docker-compose.dev.yml up -d` then `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass php artisan test`.
- Static analysis: `./vendor/bin/phpstan analyse`. Style: `./vendor/bin/pint --test`.
- Dev server: `composer run dev` (or `php artisan serve`), Mailpit UI at http://localhost:8025.

## Models and cost

- Fable 5.1 is the orchestrator only: it decides, briefs agents, reads their one-line reports, verifies gates by exit code, deploys, and talks to the owner. It does not read plans, vendor code, diffs or long logs itself; it asks an agent for the answer instead.
- Opus 5 at maximum effort does the bulk of the work: writing plans, reviewing plans, implementing tasks, reviewing code, fixing findings, writing scripts, asset work. Multi-step work runs through the Workflow tool; single tasks through the Agent tool with `model: opus`; both in the background.
- Sonnet only for mechanical one-file edits with a complete spec. Never Haiku for code.
- Keep the orchestrator's context lean: split plans into per-task files, require single-line JSON report fields, grep instead of cat, never re-read a plan after the review, prefer background tasks with notifications over polling.
- Pipeline per plan: Opus writes the plan → adversarial review Workflow (lens reviewers, skeptics, one editor, one critic) → implementation Workflow one task at a time (implement, spec check, quality review, up to two fix rounds) → CI → pull request → merge → Coolify deploy → migrations run in the container → live checks.
