# CASS expert review and engineering brief

Oct 16, 2026 · @ahmed

CASS is in good technical shape: 1,340 passing tests, Larastan level 6 clean, a hardened image and a CI that boots it. The rule book is right in substance and wrong in shape, and the risks that matter before real authors use the platform are operational, not code. This brief gives the engineer a ready-to-commit `CLAUDE.md`, the guardrail files that make its rules enforceable, and a ranked work order with a done condition per item. It is based on the nine files in the review pack, not on the repository itself.

## Verdict at a glance

The application is sound; the envelope around it (deploys, alerting, backups, the admin account) is where a real conference would get hurt. Check one thing before anything else: whether Coolify can still clone `ahmedsk2/cass` now that the repository is private. If it cannot, nothing in this brief ships until it can.

| Area | State | Evidence (review pack) | What changes |
| --- | --- | --- | --- |
| Code and tests | Solid | 1,340 tests, 6,332 assertions, Larastan level 6 and Pint clean; every Action unit-tested; cross-tenant negatives throughout | Keep the method. Add audits and coverage to CI (phase C) |
| Rule book | Right rules, wrong shape | Durable rules sit beside one PC's paths and one session's model choices; the tenant rule on line 11 is not true of the code; nothing on security changes, dependencies, production access or "done" | Three files (section 2) and a rewrite (section 3) |
| Deploy path | At risk | Repository now private, Coolify set up from the public URL; no branch protection on this plan; `deploy.sh` hides failures; every release is downtime plus a 500 window until migrations run | Phase A items A1, A6, A8 |
| Alerting | Absent | No uptime monitor, no error tracker, `cass:health` run by hand, backup failures written to a log nobody reads | Phase B items B5 to B7 |
| Backups | Half | Nightly dump and weekly file archive on the same host; off-host copy described, not configured; last restore test 2026-09-13 | Phase B item B8 |
| Public submit form | Under-protected | Turnstile keys empty, so protection is honeypot, timer and 5/min/IP; first draft save emails any typed address | Phase B items B1, B10 |
| Platform admin | Exposed | Bypasses every policy through `before()`, no second factor enrolled or enforced, Filament 5.8.1 carries CVE-2026-104181 | Phase A item A5, phase B item B2 |
| Production versus `main` | Behind | 38 commits, including encrypted queued notifications and the upload sweep | Phase A item A8 |
| Documentation | Thorough and expensive | 1,364-line runbook mixing procedure with reasoning; backlog paragraphs that are decision records in disguise; 223 plan or fact references in comments | Section 13 |

## CLAUDE.md: keep the rules, change the shape

Adopt the proposed "Project at a glance" and the command split; do not commit the proposed toolchain section. Split the current file into three: `CLAUDE.md` (committed, durable project rules, about 100 lines), `CLAUDE.local.md` (git-ignored, this PC's paths and quirks) and `tools/claude/README.md` (the agent method and model policy). Section 3 has the full text of the first; section 4 the second.

Four reasons:

1. Every session loads `CLAUDE.md` in full, on every task. Machine paths and model names cost context on every task, help with none, and go stale: they went stale once already, and the proposed version would commit a Windows username and a Desktop path that go stale again on the next machine.
2. A rule that is not true weakens the ones that are. Line 11 says every tenant model has an `organization()` relation and a policy; five models have neither and eleven reach the tenant only through a parent. Restate the rule as what the code and tests actually enforce (section 3 does).
3. The rules that matter most are missing: a definition of done, what counts as a security-sensitive change and who approves it, how a dependency bump is handled, that a session never acts on production unattended, and that `Legacy/` data never enters an agent's context.
4. A rule in prose is skipped under pressure. Where a file can enforce it, enforce it: a deny list for `.env` and `Legacy/`, a pre-push hook that refuses direct pushes to `main`, a test that `.env.example` is complete (section 4).

Where each piece of the current file goes:

| Content | Goes to | Committed |
| --- | --- | --- |
| What CASS is, who uses it, lifecycles, where the code lives, how it runs | `CLAUDE.md`, "Map" | Yes |
| Engineering, security, dependency, production and data rules; definition of done; commands | `CLAUDE.md` | Yes |
| Model policy, pipeline detail, how each script is run | `tools/claude/README.md` | Yes |
| PHP, Composer, Node, Docker paths; PATH line; session quirks; local accounts | `CLAUDE.local.md` | No, git-ignored |
| Windows setup: `ext-sockets`, the `composer.bat` caveat, putting tools on PATH | `docs/dev-setup.md` | Yes |
| Current state of production versus `main`, open items | `docs/HANDOVER.md` (unchanged role) | Yes |

Verdict on each change in `CLAUDE.proposed.md`:

| Proposed change | Verdict | Note |
| --- | --- | --- |
| 1. Add "Project at a glance" | Accept, trimmed | It is the "Map" section in section 3 |
| 2. Composer line points at the tools folder | Reject in `CLAUDE.md` | Put `tools\php84` and `tools\composer` on the user PATH once (Windows environment variables); then no session needs a path. The `composer.bat` caveat moves to `docs/dev-setup.md` |
| 3. MySQL suite on its own line | Accept |  |
| 4. Pass explicit paths to the `tools/claude` scripts | Accept, and go further | Change each script's default to fail with a message when `repo`, `worktreeRoot` or `scratch` is unset, so the old PC's path can never be used by accident |
| 5. Rewrite "Toolchain (this machine)" | Move to `CLAUDE.local.md` | The username and the Desktop path never enter the repository |
| 6. "Models and cost" unchanged | Change it | Make it tool-agnostic and add the one thing it lacks: the orchestrator reads the pre-merge list of sensitive files and migrations before asking the owner to approve a merge |

## The new CLAUDE.md, ready to commit

Replace the whole file with the text below (about 60 lines; the official guidance is to stay under 200). Three lines name things the work order creates in phase A (the `.env.example` test, `docs/decisions/`, `docs/security-exceptions.md`), so commit this file in the same PR as items A2 to A4.

```markdown
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
```

## The guardrail files

Five small files turn the rules that matter most from prose into something a session cannot skip. Facts about Claude Code below were checked against its documentation on 2026-10-16: `CLAUDE.local.md` loads automatically after `CLAUDE.md` but is not git-ignored automatically; permission rules are evaluated deny first, then ask, then allow, so an allow rule cannot carve an exception out of a deny rule; a `PreToolUse` hook blocks a command by exiting 2, and its stderr reaches the model; the recommended ceiling is 200 lines per `CLAUDE.md`.

**1. `CLAUDE.local.md` (git-ignored).** Fill the placeholders from `CLAUDE.proposed.md`; the real username and paths stay on the machine.

```markdown
# CASS: this machine (git-ignored, never commit)

Windows 11. Repository at <repo path>. Tools folder <tools path>, outside AppData (the desktop app sandboxes writes under AppData). Checked <date>.

- PHP 8.4.26 (gd, intl, sockets on) and Composer 2.10.2 are on the user PATH: `php -v` and `composer -V` work from Git Bash. If a session cannot see them: `export PATH="<tools>/php84:<tools>/composer:$PATH"`.
- Node 24.19 with npm 12. npm 12 blocks install scripts; `npm ci` skips esbuild's postinstall and `npm run build` still works.
- Docker Desktop 4.95, per-user install. CLI at `%LOCALAPPDATA%\Programs\DockerDesktop\resources\bin\docker.exe`; a session started before Docker was installed calls it by full path.
- Python 3.11, not 3.14. PyMuPDF, Pillow and pypdf are installed on demand for the icon and PDF helpers.
- Mailpit at `<tools>\mailpit\mailpit.exe`. `gh` signed in as the owner. git identity set per repo. SSH key for the host in `~/.ssh/`; the host address lives in the project memory, never in a file.
- No Inkscape, ImageMagick or Ghostscript: rasterise SVGs with Node `sharp` (`docs/brand/build-icons.mjs`).

Session quirks

- `php -` and `python -` (a script on stdin) hang: write the script to a file and run it.
- Long heredocs fail with "unexpected EOF": write the script with the Write tool, then `sed -i 's/\r$//'` before handing it to the Workflow tool.
- Local dev: `.claude/launch.json` runs `php artisan serve` on port 8000 against `database/database.sqlite`.
```

**2. `.claude/settings.json` (committed).** Reads of secrets and real data, force pushes and unverified commits are denied for every session. Production access is not denied here, because a deny cannot be lifted by the owner's local settings; the hook in file 3 gates it instead.

```json
{
  "permissions": {
    "deny": [
      "Read(./.env)",
      "Read(./Legacy/**)",
      "Read(./legacy-review.md)",
      "Read(./database/database.sqlite)",
      "Bash(git push --force *)",
      "Bash(git push -f *)",
      "Bash(git commit --no-verify *)"
    ]
  },
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Bash",
        "hooks": [
          { "type": "command", "command": "bash \"${CLAUDE_PROJECT_DIR}/.claude/hooks/guard-production.sh\"" }
        ]
      }
    ]
  }
}
```

Rules match the command text, so `git -C . push --force` would slip past the deny list; the hook and the git hooks below are the backstop, not the deny list.

**3. `.claude/hooks/guard-production.sh` (committed).** Any command that reaches the host is refused unless the owner has unlocked deploys in the last 60 minutes with `touch .claude/deploy-unlocked`. The unlock expires by itself, so an unattended session never finds the door open.

```bash
#!/usr/bin/env bash
# PreToolUse hook: refuse production access unless the owner unlocked it recently.
input=$(cat)
if printf '%s' "$input" | grep -Eq '"command":"[^"]*(ssh |scp |deploy\.sh|coolify)'; then
  unlock="${CLAUDE_PROJECT_DIR}/.claude/deploy-unlocked"
  if [ -f "$unlock" ] && [ $(( $(date +%s) - $(stat -c %Y "$unlock") )) -lt 3600 ]; then
    exit 0
  fi
  echo "Production access is locked. The owner unlocks one deploy with: touch .claude/deploy-unlocked (valid 60 minutes)." >&2
  exit 2
fi
exit 0
```

**4. `tools/git-hooks/pre-push` (committed) and `git config core.hooksPath tools/git-hooks` on every clone.** Branch protection is unavailable on a free private repository, so this is the local stand-in: a direct push to `main` is refused and every change reaches `main` through a pull request. The owner's alternative is a paid GitHub plan, which restores real branch protection; confirm the current price on GitHub's pricing page before choosing.

```bash
#!/usr/bin/env bash
# Refuse direct pushes to main; changes reach main through a pull request with CI green.
while read -r local_ref local_sha remote_ref remote_sha; do
  if [ "$remote_ref" = "refs/heads/main" ]; then
    echo "pre-push: direct pushes to main are refused. Open a pull request." >&2
    exit 1
  fi
done
exit 0
```

**5. `tests/Unit/EnvExampleTest.php` (committed).** Makes the `.env.example` rule self-enforcing. Keep a short `$frameworkOnly` list for variables the project deliberately leaves unlisted (the PostgreSQL and SQL Server keys in `config/database.php`, for example) so each omission is a conscious one.

```php
<?php

declare(strict_types=1);

it('lists every variable config reads in .env.example', function () {
    $frameworkOnly = ['DB_URL', 'DB_SOCKET', 'DB_CHARSET', 'DB_COLLATION'];

    $keys = collect(glob(config_path('*.php')))
        ->flatMap(function (string $file): array {
            preg_match_all('/env\(\s*[\'"]([A-Z0-9_]+)[\'"]/', (string) file_get_contents($file), $m);

            return $m[1];
        })
        ->unique()
        ->diff($frameworkOnly)
        ->sort()
        ->values();

    $example = (string) file_get_contents(base_path('.env.example'));
    $missing = $keys->reject(fn (string $key): bool => preg_match('/^#?\s*'.$key.'=/m', $example) === 1);

    expect($missing->all())->toBe([]);
});
```

**`.gitignore` additions:** `CLAUDE.local.md`, `.claude/settings.local.json`, `.claude/deploy-unlocked`.

## Work order, phase A: before any other code change

In this order. A1 is a check, not code; A2 is what the owner asked for before engineering resumes; A5 to A8 get production onto `main` safely. Every item ends with its gate logs captured by exit code and a one-line `docs/HANDOVER.md` update.

| # | Item | Where | Done when | Who | Status |
| --- | --- | --- | --- | --- | --- |
| A1 | Confirm Coolify can still fetch the repository now that it is private | Coolify resource source; runbook "One-time setup" step 2 | A Coolify build of `main` succeeds with no code change, or the source is switched to a GitHub App or deploy key and the runbook's step 2 says which | Owner, with the engineer | Not started |
| A2 | Commit the rule book and the guardrails | `CLAUDE.md` (section 3), `docs/dev-setup.md` with the `CLAUDE.local.md` template, `.claude/settings.json`, `.claude/hooks/guard-production.sh`, `tools/git-hooks/pre-push`, `.gitignore`, `tools/claude/README.md` (model policy moved here) | PHP and Composer on the user PATH; a fresh session builds and tests from the committed file alone; the hook refuses `ssh` without the unlock file and allows it with one; `git push origin main` is refused locally | Engineer; owner sets PATH | Not started |
| A3 | Make `.env.example` complete and self-enforcing; retire `MAIL_ENCRYPTION` | `.env.example`, `tests/Unit/EnvExampleTest.php`, `docs/runbooks/deploy-production.md` step 4, Coolify environment | The test passes with `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE`, `APP_PREVIOUS_KEYS`, `DB_SSLMODE`, `MAIL_SCHEME` listed and commented; `MAIL_ENCRYPTION` gone from the runbook and Coolify; `MAIL_SCHEME` set explicitly (`smtps` on 465 if the mail server offers it, which gives TLS from the first byte) | Engineer; owner edits Coolify | Not started |
| A4 | Create `docs/decisions/` and `docs/security-exceptions.md` | New files; the eight owner decisions copied from the backlog, one file each, unedited | Both exist with a two-line format note; `CLAUDE.md` references resolve | Engineer | Not started |
| A5 | Patch the two advisories: `filament/filament` to 5.8.2 or later (CVE-2026-104181) and `league/commonmark` past 2.10.1 (GFM table DoS) | `composer.json`, `composer.lock`, the three published views under `resources/views/vendor/`, `tests/Feature/Security/PublishedFilamentViewsTest.php`, the `EditProfile::content()` drift pin | `composer audit` reports no high or critical finding; views re-published with the nonce and digests updated in the same commit; suite green on SQLite and MySQL; Dependabot #26 closed by this PR | Engineer | Not started |
| A6 | Make `tools/claude/deploy.sh` fail loudly and take a backup first | `tools/claude/deploy.sh` lines 7, 16, 25, 26; runbook "Every release" | `set -euo pipefail`; no `tail` on migrate or health output; every remote step's exit code checked; `cass-backup` runs before `migrate --force` when `migrate:status` shows anything pending; `DEPLOY_DONE` printed only when `migrate:status` is clean and `cass:health` exits 0; a dry-run flag exercises the failure path; the runbook adds the expand-then-contract migration rule | Engineer | Not started |
| A7 | Remove the old PC's default paths from the pipeline scripts | `tools/claude/implement-plan-staged.js:14-15`, `premerge-review.js:14`, `review-plan.template.js:18,31`, `tools/claude/README.md` | Each script exits with a clear message when `repo`, `worktreeRoot` or `scratch` is missing | Engineer | Not started |
| A8 | Deploy `main` (Plan 7 and the launch decisions) | Runbook "Every release"; `docs/HANDOVER.md` engineering item 1 | Production runs the new commit; `/up` is 200; `/org/login` is styled by the Vite theme; `cass:health` all OK; no migration pending; launch checklist section 3 commands re-run and ticked; `HANDOVER.md` carries the commit and date | Owner deploys, engineer checks | Not started |
| A9 | Triage the remaining Dependabot pull requests | #25, #27, #28, #30, #31 green: merge; #29 (base images) red: find the cause | Each PR merged with CI green or closed with a one-line reason in `HANDOVER.md`; #29 either fixed or closed with the reason | Engineer; owner decides any base-image major | Not started |

## Work order, phase B: before real conferences and real authors

Nothing here is optional for a launch with real authors' data and real decision letters. B1 to B4 are owner actions on services outside the repository and take an afternoon; B5 to B8 are the operations floor; B9 to B13 are small code changes with tests.

| # | Item | Where | Done when | Who | Status |
| --- | --- | --- | --- | --- | --- |
| B1 | Turnstile keys | Cloudflare Turnstile widget for `cass.towardpcc.com`; `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` in Coolify; redeploy | The widget renders on the submit form as a logged-out visitor; a submission with a failed challenge is refused | Owner | Not started |
| B2 | Second factor on the platform admin: enrolled and enforced | Owner enrols at `/admin` profile; engineer makes MFA required on the admin panel provider (Filament's panel-level required option) with a test | A platform admin without a second factor cannot reach any admin page; recovery codes stored outside the platform; `docs/HANDOVER.md` says so | Owner and engineer | Not started |
| B3 | Origin lockdown | OCI security list: 80 and 443 open to Cloudflare's ranges only; 22 restricted to known addresses | A direct request to the origin address from outside Cloudflare times out; the two checklist boxes are ticked. Every IP-based rate limit depends on this (`ClientIp.php:20-31`) | Owner | Not started |
| B4 | Cloudflare settings | Always Use HTTPS, HSTS (6 months, no subdomains), WAF managed rules and Bot Fight Mode, Rocket Loader off, cache rules for `/build/*` and `/storage/*` only | Checklist section 2 boxes ticked; `curl -sI` shows HSTS; the Rocket Loader grep returns 0; one real abstract submitted through the WAF | Owner | Not started |
| B5 | External uptime monitor | An outside service checking `https://cass.towardpcc.com/up` every 1 to 5 minutes, alerting by email and push; documented in a new runbook section "Monitoring" | A failed check produces an alert (use the service's test alert or a wrong URL for a minute) | Owner signs up; engineer documents | Not started |
| B6 | Dead-man heartbeats | `routes/console.php`: hourly `cass:health --json`, pinging `CASS_HEARTBEAT_URL` only when every row is OK; both backup cron lines end with `&& curl -fsS <their own ping url>`; a heartbeat service that alerts when a ping is late | Three heartbeats listed in `HANDOVER.md`; pausing one produces an alert; tests for the scheduled entry and for "no ping when a row is red" | Engineer; owner creates the three checks | Not started |
| B7 | Error tracking | Report exceptions to a tracker (Flare, a self-hosted GlitchTip, or similar; the choice is the owner's); DSN as an env var in `.env.example` and the runbook | `php artisan tinker --execute='report(new RuntimeException("tracker test"));'` on production produces an event in the tracker | Engineer; owner picks the service | Not started |
| B8 | Off-host backups and a restore drill | Nightly copy of `/srv/backups/cass` (dumps and the weekly storage archive) to object storage in a different failure domain (OCI Object Storage, Cloudflare R2, Backblaze B2 or similar), client-side encrypted (`rclone crypt` or `age`), 30-day retention, with its own heartbeat; runbook "Backups" updated | A dump restored from the off-host copy, not the host copy, matches production row counts; the drill date and the next one are in `HANDOVER.md` | Owner, with the engineer writing the cron line | Not started |
| B9 | Suspended organisations cannot send mail | `InviteReviewer`, `InviteMember`, the reminder sender: a blocker when the organisation is not approved, matching `PublishConference` and `SendDecisionEmails` | Unit tests for each blocker, red then green | Engineer | Not started |
| B10 | Cap outbound mail to unverified addresses | `SubmissionForm.php:356-368` and `SendSubmissionStatusLink`: at most 3 status-link emails per address per 24 hours and a per-IP cap; a plain refusal message | Tests: the fourth draft save to one address in 24 hours sends nothing and says so; the IP cap holds; existing resend-link behaviour unchanged for organisers | Engineer | Not started |
| B11 | Lock settings that change the meaning of a review in progress | `ConferenceForm.php`: `review_mode` and `blind_review` read-only from `reviewing` onward; `TrackPolicy` and the custom-field policy refuse deletion when submissions reference the row; deadline edits logged with the actor | Form and policy tests, red then green; a cross-tenant negative on the new paths | Engineer | Not started |
| B12 | CSP enforcing | After the walk-through with a clean console: `CASS_CSP_REPORT_ONLY=false`, redeploy | Checklist section 3 shows an enforcing header; one full walk-through done with it enforcing | Owner | Not started |
| B13 | Launch checklist closed | Section 4 walk-through, section 5 data (legacy import or not; `cass:demo-reset --confirm`), section 6 sign-off; the two memory readings written into the runbook ("Resource limits") | Every box ticked or carries a written reason; `HANDOVER.md` updated | Owner and engineer | Not started |

## Work order, phase C: first month after launch

These make the pipeline cheaper and the deploy honest. C1 pays for itself on the next CI run; C2 is the structural fix for "the tested artefact is not the deployed artefact".

| # | Item | Where | Done when | Who | Status |
| --- | --- | --- | --- | --- | --- |
| C1 | CI hygiene and audits | `.github/workflows/ci.yml`: `composer audit` and `npm audit --omit=dev --audit-level=high` steps; a secret scan (gitleaks); `timeout-minutes` on every job; Composer and npm caches; `cancel-in-progress` only for pull requests; MySQL service image pinned by digest; the `image` job only on the weekly schedule and on changes under `Dockerfile` or `docker/**` | The audit step goes red on a known advisory and green after the fix; a push to `main` no longer cancels the previous run; wall-clock time per push recorded before and after in `HANDOVER.md` | Engineer | Not started |
| C2 | Build once, deploy that image | CI builds the arm64 image on `main` and pushes it to GHCR tagged with the commit SHA; Coolify deploys that image instead of building on the host; runbook "Every release" and "Rollback" rewritten (rollback = previous tag) | The running container's image digest equals the one CI pushed; a rollback to the previous tag works in a quiet window; host CPU no longer spent on builds | Engineer; owner reconfigures Coolify | Not started |
| C3 | Free-space check in `cass:health` (open question 6) | `HealthCommand.php`: a `disk` row that warns below 15% free or 2 GiB, whichever comes first; included in `--json`; the heartbeat in B6 stays silent when it is red | Test with a faked `disk_free_space`; runbook "Health" table gains the row | Engineer | Not started |
| C4 | Seeder and admin password hardening | `PlatformAdminSeeder`: refuse to overwrite an existing admin's password unless `--force-password` is passed; `CASS_ADMIN_PASSWORD` removed from Coolify after the first seed; runbook step 6 and the "Every release" warning updated | Test for the refusal; the variable absent from `php artisan about` on production | Engineer; owner edits Coolify | Not started |
| C5 | Native tenancy for submissions | Additive migration adding `submissions.organization_id` (backfilled from `conferences`), set in `SubmitAbstract` and draft creation; `SubmissionResource` returns to Filament tenancy; the `1 = 0` fallback and the hand-scoped query go | Backfill verified in the MySQL suite; all cross-tenant tests green; `docs/backlog.md:36` entry closed with the reason | Engineer | Not started |
| C6 | One membership check | A `TenantPolicy` base class or `ResolvesTenant` trait replaces the membership check repeated in 15 policies | One implementation; every policy test green; no behaviour change | Engineer | Not started |
| C7 | Test hygiene | Remove the scaffold `ExampleTest` files; fix the stale skip in `tests/Feature/Admin/PurgeTest.php:93`; coverage measured in CI (pcov) and reported, no threshold yet | CI shows a coverage number; `HANDOVER.md` records it | Engineer | Not started |
| C8 | Documentation restructure | Section 13 of this brief: backlog to a table with one line per item, decisions to `docs/decisions/`, a quick-procedure index at the top of the runbook, no new plan or fact references in comments | Backlog under 60 lines; runbook index in place; `CLAUDE.md` rule in force | Engineer | Not started |

## Work order, phase D: when a task next touches the area

None of these justify a PR of their own. Each is done inside the next task that opens the file, and the backlog entry is closed in that PR.

| # | Item | Where | Rule |
| --- | --- | --- | --- |
| D1 | 69 inline `style` attributes | The ten panel views listed in `docs/backlog.md:20` | Convert the ones in any view a task edits; the brand lock-up and the sparkline bars stay inline |
| D2 | Untranslated literals | The nine visitor-facing files first (`docs/backlog.md:81`), then the organiser panel's 15 | Do the visitor-facing nine when Arabic work starts; otherwise convert on touch |
| D3 | Deprecated Filament table test helpers | `callTableAction`, `mountTableAction`, `assertTableAction*`, `callTableBulkAction` across the suite | One mechanical sweep in its own PR, after A5 has landed, never mixed with a behaviour change |
| D4 | Reporting aggregates in the model | `app/Models/Conference.php:340-612` | Move to a query class under `app/Support/Reporting` the next time a report changes |
| D5 | Long classes | `app/Livewire/Public/SubmissionForm.php` (893 lines), `app/Actions/Legacy/ImportLegacy.php` (1,354 lines) | Split the form into a form object plus component when it next changes; leave the importer alone, it runs once |
| D6 | Duplicated filters | "active reviewer" in 12 places, the same submission-status pair in 8 | Replace with query scopes on touch |
| D7 | Low-severity security items | Registration reveals existing accounts; sessions stored unencrypted; author links never expire; Livewire upload throttle keys on the proxy IP (`config/livewire.php:39`) | Fix the enumeration message and the upload throttle key on touch; rotate the author token on withdraw; leave session encryption unless a requirement appears |
| D8 | Docs and comments that disagree with the code | `bootstrap/app.php:26-29`, `routes/web.php:129-133` and `:155-156`, design doc section 4 (admin granted "by command or another admin"), docs that call the repository public | Fix in the same PR as C8, one sweep |
| D9 | Custom-domain cache | `ClaimCustomDomain` does not forget the verified-host cache after a re-claim (`HANDOVER.md` engineering item 4) | Fix with a test the next time the domain code changes |

## Questions 1 to 3: the rule book, the agent method, what blocks launch

**Q1, the rule book.** The existing rules are the right ones and a session can obey them; the file fails on completeness and on shape, and section 3 is the markup. Yes to explicit rules for security-sensitive changes, dependency upgrades, production access and deploys, and yes to a definition of done; all five are in the new file. Two further changes: the tenant rule is restated as what the code enforces, and the model and machine sections leave the committed file.

**Q2, the agent method.** An orchestrator briefing worker agents, with adversarial plan review and task-by-task spec and quality review, is a sound way to produce this code, and the result shows it: 1,340 tests, clean static analysis, cross-tenant negatives everywhere, a hardened image. It is not yet a sound way to change production, for three reasons. First, no human reads a diff: the orchestrator is instructed not to, so every review is AI reviewing AI. Second, nothing limits what an agent may do unattended, and the orchestrator holds the deploy. Third, nothing constrains output length, so agents write exhaustively (a 1,364-line runbook, 100-line backlog paragraphs), and every future session pays for it in context.

Add four human review points and one list of limits:

| Point | The owner reads | Then |
| --- | --- | --- |
| 1. Plan approval | The plan's summary and its list of migrations, policies, environment variables and sensitive files, not the plan | Approves, or asks |
| 2. Pre-merge | The pre-merge review in a fixed format: sensitive files with one line each, migrations, tests added, anything skipped, gate log paths | Approves the merge |
| 3. Deploy | The CI result and the `HANDOVER.md` line for this release | Unlocks production for 60 minutes and watches the deploy |
| 4. Post-deploy | `cass:health`, `migrate:status`, the two page timings | Confirms `HANDOVER.md` is updated in the same session |

Without the owner in the session, an agent never: pushes to `main`; merges a pull request; deploys or runs anything on the host, in Coolify, Cloudflare or OCI; edits `.github/`, `Dockerfile`, a compose file or `.claude/`; deletes, skips or weakens a test; updates a pinned digest; reads `Legacy/`; raises a major version constraint; runs a `tinker` write anywhere. The hook and deny list in section 4 enforce the production and data items; the rest are rules in `CLAUDE.md` and a line in the pre-merge format.

Add a length budget to the pipeline's briefs: a task file under 150 lines, a review report under 40 lines plus a findings list, a backlog entry of three lines plus a link to a decision file, a runbook procedure under 40 lines. An agent that needs more writes a decision file and links it.

**Q3, launch readiness.** Phases A and B must be done before a real conference opens; C and D can wait. The mapping from the review's ranked risks: High 1 (one host) is B8; High 2 (no alerting) is B5 to B7; High 3 (submit form) is B1 and B10; High 4 (admin) is A5 and B2; High 5 (private repository) is A1; High 6 (origin) is B3. Medium 7 (releases) is A6; Medium 8 (tested versus deployed) is C2 and can wait because the smoke test still boots the same Dockerfile; Medium 9 (behind `main`) is A8; Medium 10 (dependencies) is A5 and A9; Medium 11 (CSP, HSTS) is B4 and B12; Medium 12 (settings mid-cycle, suspended organisations) is B9 and B11; Medium 13 (backups) is B8; Medium 14 (memory) is B13; Medium 15 (checklist) is B13; Medium 16 (one maintainer, drift) is A2, A7 and C8. Everything under Low is phase D.

## Question 4, operations: the minimum for one person on one host

The minimum is three outside signals (up, heartbeat, error), one off-host encrypted copy with a monthly restore, and a deploy script that cannot report success falsely. Everything below is a few hours of setup and then nearly free to run.

| Need | Minimum adequate | How | Item |
| --- | --- | --- | --- |
| Know the site is down | An external monitor on `/up` every 1 to 5 minutes, alerting by email and push | Any hosted uptime service; the runbook gains a "Monitoring" section naming it | B5 |
| Know a silent failure happened (queue dead, scheduler dead, migration pending, disk full, backup skipped) | Dead-man heartbeats: a ping only on success, an alert when the ping is late | Hourly `cass:health --json` that pings `CASS_HEARTBEAT_URL` when all rows are OK; each backup cron line pings its own URL after success | B6, C3 |
| Know an exception happened | Exceptions reported to a tracker with the stack trace and the request | Flare, self-hosted GlitchTip, or similar; DSN in the environment | B7 |
| Survive losing the host | A nightly encrypted copy of the dumps and the storage archive somewhere the host cannot delete it | `rclone crypt` or `age` to object storage in a different failure domain; 30-day retention; its own heartbeat | B8 |
| Know the backup is a backup | A monthly restore from the off-host copy, row counts compared, date recorded | The runbook's existing procedure, run against the remote copy; date and next date in `HANDOVER.md` | B8 |
| Release without lying to yourself | `deploy.sh` with `set -euo pipefail`, no piped `tail`, a backup before any migration, `DEPLOY_DONE` only on clean `migrate:status` and `cass:health` | A6 | A6 |
| Release without breaking a conference | No deploy within 48 hours of an open submission or review deadline; migration releases in a quiet window; expand-then-contract migrations | Rule in `CLAUDE.md`; the deadlines are in `conferences`, so `deploy.sh` can refuse when one is near | A2, A6 |
| Roll back | Redeploy the previous image; schema rollback only from the pre-migration backup | Today: Coolify's previous build. After C2: the previous image tag | C2 |
| Not cause downtime | Not achievable with Coolify's stop-then-start compose deploy. Accept it and schedule it | Runbook note; the 300 ms timing loop after each release stays | A6 |

Maintenance mode is not worth adding: `php artisan down` cannot survive the container swap, and the downtime is the swap itself. The 500 window between the new container going live and `migrate --force` is the real cost; A6 shortens it to seconds by running the migration the moment the container is healthy, and C2 removes the host build from the critical path.

## Question 5, security: the trade-offs and what section 9 misses

Four of the five recorded trade-offs stand; the fifth (Turnstile failing open) is sound only once Turnstile exists, which today it does not.

| Trade-off | Verdict | Condition |
| --- | --- | --- |
| Turnstile fails open when Cloudflare is unreachable | Agree | Only with keys set (B1) and the "could not reach Cloudflare" warning routed to the error tracker (B7) so an hour of fail-open is seen. With no keys, "fail open" is a misnomer: there is no challenge at all |
| Plaintext custom-domain token | Agree | It is a public DNS nonce; it authorises nothing. Keep the docblock that says so |
| Author links never expire, only rotate | Agree for v1 | Rotate on withdraw too (D7). Archiving already makes `/s/{token}` a 404 |
| Report-only CSP before enforcement | Agree, with a date | It has been report-only since 2026-09-13 with no real traffic to learn from. Enforce after the walk-through (B12), not after "a clean console" at some undefined time |
| `'unsafe-eval'` and `style-src-attr 'unsafe-inline'` | Agree | Upstream limitation; the nonce still blocks injected scripts. Revisit the day Filament ships nonce support, as the backlog already says |

What section 9 misses, in order of weight:

1. **The platform admin is the whole system.** `before()` grants it everything, there is no second factor, and the one account's password is reset by a seeder from an environment variable that is still in the container. B2 and C4 close this; the absence of a Users resource is good and should stay.
2. **Mail to strangers.** A draft save emails a status link to any address typed, with branding. Combined with no Turnstile, this is a free branded-mail relay. B1 and B10.
3. **No audit in the pipeline.** Two known advisories sat in the lock file with nothing to notice them. C1 adds `composer audit`, `npm audit` and a secret scan; A5 patches today's two.
4. **Backups readable at rest.** Host-only, unencrypted, mode 0600. Fine against a stranger, useless against the host being lost or imaged. B8.
5. **Suspension is not enforced on outbound mail.** A suspended organisation can still invite and remind (B9).
6. **Dependabot bumps are merged without a human reading a changelog.** Acceptable for patch releases with CI green; the rule in `CLAUDE.md` holds majors for the owner.
7. **The `.env.example` rule is not true**, and a missing `SESSION_SECURE_COOKIE` default is a cookie over HTTP in any environment that forgets it. A3.

Not a gap: PID 1 as root with every serving process as `app` is a normal and acceptable supervisord pattern; the CI check that enforces the split is the right control.

## Question 6, architecture

Actions plus policies is the right structure for a Laravel application of this size, and it is sustainable as long as the two deliberate exceptions stay deliberate and small. Three specific answers:

1. **Hand-scoped submission tenancy.** Safe today (the `1 = 0` fallback fails closed, and the negative tests exist), but it is the one place where tenancy is a convention rather than a mechanism, and every future submission screen must remember it. The fix is cheap and additive: `submissions.organization_id`, backfilled from `conferences`, set by `SubmitAbstract` and draft creation, after which `SubmissionResource` uses Filament tenancy like every other resource and the policies simplify. It is C5, not phase B, because nothing is broken.
2. **Settings and tracks should lock by status, minimally.** Two settings change the meaning of work already done: `review_mode` (who may see what) and `blind_review` (whether reviewers see authors). Lock both from `reviewing` onward. A track or custom field referenced by a submission must not be deletable. Deadlines stay editable, because extending one is a normal need, but the change is logged with the actor. That is B11; it is three blockers and their tests.
3. **The model and policy duplication is maintenance cost, not risk.** The membership check repeated in 15 policies (C6), the 270 lines of reporting aggregates in `Conference` (D4) and the 12 copies of "active reviewer" (D6) are all refactors to do on touch, never in a PR of their own.

The exceptions to "logic lives in Actions" are fine where they are: Filament's default save for conference editing, tracks, custom fields and review questions, the review-form lock in model events. Write them down as exceptions in a one-line decision file (A4) so a future agent does not "fix" them, and add `UpdateConference` only when B11's locks need a place to live, which they will.

## Question 7, documentation: keep, condense, move

The documentation is accurate and well argued, and that is the problem: reasoning and procedure are interleaved, so a person (or a session) looking for "how do I roll back" reads about queue payload encryption first. Separate the three kinds of text and keep all of them.

| Document | Keep | Condense | Move |
| --- | --- | --- | --- |
| `docs/runbooks/deploy-production.md` (1,364 lines) | Every procedure and every incident note (the backup script that matched nothing, the 45-byte archive, Rocket Loader) | Add a "Quick procedures" index at the top: every release, rollback, restore, health, a stuck queue, each as a numbered list of commands with the expected output; target under 40 lines per procedure | The "why" paragraphs (token design, email-log semantics, the 500-row budget reasoning) to `docs/decisions/` or to the docblock of the class they describe, one at a time, when a section is next edited |
| `docs/superpowers/plans/backlog.md` (100 dense lines) | Every item | One table: item, area, trigger, the one-line change, link; a row is at most three lines | Every "Decided (owner, date)" paragraph becomes a file in `docs/decisions/` (they are architecture decision records already, just unfiled) |
| `docs/HANDOVER.md` | Its role as the single state document | The "Open" lists duplicate the checklist and the backlog: keep one line each with a link, not the restatement | Nothing |
| `docs/launch-checklist.md` | As is until sign-off | After sign-off, archive it under `docs/launch/` with the ticked boxes; a second conference does not need it | Section 1's decisions are already in the backlog; after A4 they point to `docs/decisions/` |
| The design document | As is, marked "as approved 2026-09-10; the code is authoritative where they differ" | Nothing | Nothing |
| Code comments | The ones that explain the code | Stop writing "Plan N", "Task N" and "fact N" references (rule in `CLAUDE.md`); do not bulk-rewrite the 223 existing ones, which would churn 280 files for no behaviour change | When a file is touched, replace a "fact N" reference with the fact itself or delete it |

Two additions, both short: `docs/dev-setup.md` (Windows setup, the `CLAUDE.local.md` template, `core.hooksPath`, under 40 lines) and `docs/decisions/README.md` (the one-paragraph format: context, decision, consequence, the one-line change to reverse it). The backlog's existing "to flip it" sentences are exactly that last field.

## What was reviewed and how

Read in full: all nine files in the pack (`README.md`, `PROJECT-REVIEW.md`, `CLAUDE.md`, `CLAUDE.proposed.md`, `docs/HANDOVER.md`, `docs/2026-09-10-cass-v2-design.md`, `docs/launch-checklist.md`, `docs/backlog.md`, `docs/deploy-production.md`).

Not accessed: the repository itself. Every file path, line number, count and advisory identifier in this brief is taken from `PROJECT-REVIEW.md` as written on 2026-10-16 and was not verified against the code. The engineer should treat a line number as a pointer, not a fact, and confirm the two CVE references against `composer audit` output before writing them anywhere else.

Verified against primary sources on 2026-10-16: the Claude Code behaviour cited in section 4 (memory files, permission rules, hooks, settings precedence), from the official documentation at code.claude.com.

From memory, to be confirmed by the owner: that branch protection and rulesets on a private repository require a paid GitHub plan, and the plan's current price; the name of Filament's panel-level option that makes multi-factor authentication required (check the installed version's documentation before B2); the free tiers of the monitoring and object-storage services named as examples.

No source disagreements were found between the nine files except the ones the review itself records (the repository described as public, the design document's account of how admin is granted, and the `.env.example` rule).
