# CASS session guide

Conference Abstract Submission System: a multi-tenant Laravel 13 + Filament 5 platform where conference organisers publish a call for abstracts, collect submissions, run peer review (open pool or assigned), rank by normalised score and send decision letters. Live at https://cass.towardpcc.com; one operator (the owner), one host. Spec `docs/superpowers/specs/2026-09-10-cass-v2-design.md`; plans `docs/superpowers/plans/`; current state `docs/HANDOVER.md`.

## Map

- Users. Platform admin: `/admin`, gated by `users.is_platform_admin`. Organisation members (owner, admin, member): `/org`, Filament tenancy on `Organization` by slug. Reviewers: `/review`, joined through `/invite/{token}`. Authors: no account; they submit at `/c/{org}/{conference}/submit` and return through `/s/{token}`. Public: `/c/{org}/{conference}`, short links `/q/{code}`, verified custom domains.
- Lifecycles. Organisation: pending, then approved or suspended. Conference: draft, open, closed, reviewing, decided, archived; `app/Enums/ConferenceStatus.php` holds the allowed transitions. Submission statuses: `app/Enums/SubmissionStatus.php`. Only Actions change a status, and `status` is never mass-assigned.
- Code. Business logic `app/Actions/<Domain>` (`blockers()` then `handle()`); authorisation `app/Policies`; panels `app/Filament/{Admin,Organizer,Reviewer}` with providers in `app/Providers/Filament`; public site `routes/web.php`, `app/Livewire/Public`, `app/Http/Controllers/Public`; helpers `app/Support`; commands `app/Console/Commands` (`cass:*`); schedule `routes/console.php`.
- Runtime. One image (nginx, php-fpm, queue worker and scheduler under supervisord) plus MySQL 8.4, built and run by Coolify on the owner's ARM64 host behind Cloudflare. Runbook: `docs/runbooks/deploy-production.md`.

## Rules

Engineering

- Verify every gate by exit code: `php artisan test > /tmp/t-task-N.log 2>&1; echo "rc=$?"`. Never judge a gate from piped or truncated output.
- Test first. A new test is run and shown failing (log path in the report) before the code that makes it pass. Never delete, skip or weaken a test to get green; report why it is red.
- Business logic lives in `app/Actions`. Filament resources and Livewire components validate and delegate.
- Every model reachable from a panel, a route, an export or an Action has a policy, and the policy reaches the tenant through the model's organisation chain (direct, or through its conference or submission). Every new screen, action or route ships with a cross-tenant negative test.
- Tokens are stored hashed; the DNS verification nonce is the one documented exception. Secrets only in `.env`. `.env.example` lists every variable `config/` reads, and `tests/Unit/EnvExampleTest.php` enforces it.
- Comments explain the code, never its provenance: no "Plan N", "Task N" or "fact N" in new code.
- When state changes, update `docs/HANDOVER.md`. A deferral goes in `docs/superpowers/plans/backlog.md`. A decision goes in `docs/decisions/`, one short file each.

Security-sensitive changes

- These paths are sensitive: `app/Policies`, `app/Support/Tokens`, `app/Support/Turnstile.php`, `app/Support/ClientIp.php`, `app/Http/Middleware`, `app/Filament/Auth`, `bootstrap/app.php`, `config/`, `database/migrations`, `docker/`, `Dockerfile`, `docker-compose.*.yml`, `.github/`, `.claude/`. A PR that touches one lists each such file with one line of why, and the owner approves that list before merge.
- A Filament bump re-publishes the three nonce'd views and updates their digests in the same commit. Never update a digest alone.

Dependencies

- Dependabot PRs merge only with CI green. Base-image and MySQL major bumps wait for the owner. `composer audit` and `npm audit --omit=dev` run in CI; a high or critical finding blocks merge unless it is recorded in `docs/security-exceptions.md` with a reason and a review date.

Production

- A session never holds production credentials and never acts on the host, Coolify, Cloudflare or OCI unattended. A deploy happens only when the owner asks for it in that session, from CI-green `main`, through `tools/claude/deploy.sh`.
- Migrations never run at container boot. They are additive (expand now, contract in a later release) and the owner runs them. A migration release begins with a hand-run `cass-backup` and ends with `migrate:status` and `cass:health` both clean.
- No deploy within 48 hours of any open conference's submission or review deadline. `db:seed` ran once at first deploy and never runs again.

Data

- `Legacy/`, `legacy-review.md` and `assets/envato/` are never committed and never read into an agent's context; `Legacy/` holds real people's data. Tests use factories only.

## Definition of done

A task is done when all of these hold: the new tests were shown red, then green; `php artisan test`, `./vendor/bin/phpstan analyse` and `./vendor/bin/pint --test` exit 0 locally; CI is green on the PR; every new environment variable is in `.env.example` and the runbook; every new operational step is in the runbook; `docs/HANDOVER.md` reflects the new state; the PR description lists sensitive files touched, migrations, environment variables and docs changed, with the gate log paths.

## Commands

- Before testing: `npm run build`. Panels load `resources/css/filament/theme.css` through the Vite manifest, so a missing or stale `public/build` fails with "Unable to locate file in Vite manifest". Stop `npm run dev` first; with `public/hot` present the panels link the dev server.
- Tests: `php artisan test` (SQLite in memory). MySQL suite: `docker compose -f docker-compose.dev.yml up -d`, then `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass php artisan test`.
- Static analysis `./vendor/bin/phpstan analyse`. Style `./vendor/bin/pint --test`.
- Dev server `composer run dev` (or `php artisan serve`); Mailpit at http://localhost:8025. Local accounts: `admin@cass.test` (platform admin) and `owner@cass.test` (owner of `gulf-pediatric-society`).

## How work is done

- One orchestrating session briefs agents, reads their short reports, verifies gates by exit code and talks to the owner. Plans, reviews, implementation and fixes go to the strongest available model; a mechanical one-file edit with a complete spec may go to a mid-tier model; never a small model for code. Model names and the per-script instructions live in `tools/claude/README.md`.
- Pipeline per plan: plan, adversarial review, implementation one task at a time (implement, spec check, quality review, at most two fix rounds), pre-merge review of the whole branch, PR, owner approval, merge, owner deploy, live checks.
- The orchestrator does not read plans, vendor code or long logs itself, but it does read the pre-merge review's list of sensitive files and migrations before asking the owner to approve.
- Keep context lean: per-task plan files, single-line JSON report fields, grep over cat, no re-reading a plan after its review, background tasks with notifications over polling. Parallel worktrees share `/tmp`, so every gate log carries its task number.

## Start here

Read `docs/HANDOVER.md`, then `docs/launch-checklist.md` and `docs/superpowers/plans/backlog.md`. This machine's paths and quirks are in `CLAUDE.local.md` (git-ignored; template in `docs/dev-setup.md`). Always pass `repo`, `worktreeRoot` and `scratch` explicitly to the `tools/claude` scripts.
