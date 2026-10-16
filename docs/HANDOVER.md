# Handover — start here (open as of 2026-10-16)

Written when the owner moved to a new PC. A new session reads this first, then
`docs/launch-checklist.md` and `docs/superpowers/plans/backlog.md`. Keep this file
current: every line under "Open" is either done or still true.

## Where things stand

| What | State |
|---|---|
| Production | https://cass.towardpcc.com runs commit `c32bb30` (deployed 2026-09-13 22:23 UTC): Plans 1–6, the demo commands, the backup-script fix. Container healthy for 3 weeks; `cass:health` all OK on 2026-10-16; 36 migrations applied, none pending. |
| `main` | `a6e7981` (2026-10-02). Ahead of production by Plan 7 (PR #23: the one Vite-built theme for all three panels, platform-admin writes, failed-notification logging, encrypted queued notifications, the hourly temporary-upload sweep, reviewer-corrected affiliation, the language sweep), the owner's eight launch decisions (PR #21), the registration-email fix (PR #22) and three Dependabot bumps (laravel/framework 13.32, actions, a mysql digest). **Not deployed.** Plan 7 adds no migration and no environment variable; it changes the `Dockerfile` (assets stage order) and both compose files, so it needs a full Coolify build, then the runbook's "Every release" step 4 checks (the panels now load a Vite-built stylesheet, not `/css/filament/filament/app.css`). |
| Open pull requests | Seven Dependabot PRs (#25–#31: compose digest, composer framework/dev/production groups, docker base images, actions, npm). Triage each one; never merge a base-image or MySQL major by hand-waving — see "Dependabot" in `tools/claude/README.md`. |
| CI | One run per push (`push` on `main`, `pull_request` elsewhere, concurrency cancel). Jobs: test (SQLite + MySQL), scripts, smoke, browser (Playwright), image (arm64 under QEMU, ~20 min). |
| Demo tenant | Still seeded on production (`demo-society`, stage `reviewing`, 15 abstracts, 3 reviewers). The reviewer password from the seed is gone; re-seed to get a new one. `cass:demo-reset --confirm` removes it — do that before real use. |
| Email | Production SMTP authenticates (mail.towardpicu.com:587, STARTTLS). `email_logs` is empty: no real message has left the platform yet. |
| CSP | `Content-Security-Policy-Report-Only` is what the site sends (`CASS_CSP_REPORT_ONLY=true` in Coolify). Flip to `false` after one walk-through with a clean browser console. |
| Backups | Nightly database dump 03:17 and weekly storage archive Sunday 03:47, from root's crontab on the host, into `/srv/backups/cass` (0700). Latest dump 2026-10-16. A restore into a scratch database matched production row counts on 2026-09-13. |
| Turnstile | Keys still empty in Coolify; the submit form fails open (no bot challenge) until they are set. |

## Open — owner

From `docs/launch-checklist.md` (its eight product decisions were answered on 2026-10-01 and are recorded there):

1. Section 2, services only the owner controls: Turnstile keys, two-factor on the platform admin account, one real email to check SPF/DKIM on `towardpicu.com`, the Cloudflare settings (Always Use HTTPS, HSTS, WAF + Bot Fight Mode, Rocket Loader off, cache rules for `/build/*` and `/storage/*`), port 22 restricted on the OCI security list.
2. Section 4, the manual walk-through on the demo tenant.
3. Section 5, data: decide whether to import the two legacy conferences (`cass:import-legacy`, runbook "Importing the legacy conferences"); reset the demo tenant.
4. Section 6: flip CSP to enforcing after the walk-through.
5. The six open questions Plan 7 raised, in `backlog.md` under "Open questions for the owner (raised by Plan 7)".

## Open — engineering

1. Deploy `main` (Plan 7) once the owner wants it live: `tools/claude/deploy.sh` on the host, then runbook "Every release" step 4. No migration to run.
2. Triage the seven Dependabot PRs.
3. Section 3 of the launch checklist (production readiness) was executed by hand on 2026-09-13 with every command green, but the boxes were never ticked in the file. Re-run after the Plan 7 deploy and tick them.
4. The backlog's Plan 6/7 leftovers (69 inline `style` attributes in panel views, the language sweep's uncovered files, `ClaimCustomDomain` not forgetting the verified-host cache after a re-claim).

## How the work is done

`CLAUDE.md` "Models and cost" is the method: the orchestrating session briefs Opus agents and
reads one-line reports; plans are written, adversarially reviewed, implemented in staged
Workflows (parallel worktrees for independent tasks), reviewed again over the whole branch
before merge, then deployed with migrations run by hand in the container. The scripts that
did this for Plans 2–6 are in `tools/claude/` with their own README.

Plans 1–7 and the backlog are in `docs/superpowers/plans/`; the design is
`docs/superpowers/specs/2026-09-10-cass-v2-design.md`; the runbook is
`docs/runbooks/deploy-production.md`.

## Files that are not in this repository

Everything below is deliberately git-ignored. On the old PC it was collected into
**`C:\cass-extras`** (README inside, `MANIFEST.csv`, `verify-bundle.ps1`), to be copied by USB
or an encrypted drive, never a cloud drive:

| Item | Why it is outside git | Restore to |
|---|---|---|
| `.env` | local development config, `APP_KEY` | repo root |
| `database/database.sqlite` | local dev database (fictional users `admin@cass.test`, `owner@cass.test`) | `database/` |
| `Legacy/` (`CASS.zip`, `dbg1vzqja6lgef.sql`) | the old application and its dump, **real people's data** — the import source | repo root |
| `legacy-review.md` | the walkthrough of the old app, names its vulnerabilities and data quirks | repo root |
| `assets/envato/` | licensed Envato downloads (logo template, illustrations, icons, email template) | `assets/envato/` |
| Claude project memory | `%USERPROFILE%\.claude\projects\C--Users-ahmed-Documents-CASS\memory\` — host facts, Coolify uuids, decisions | same path (the key is the repo path; copy it if the path or username changes) |
| Workflow scripts of the build sessions | `%USERPROFILE%\.claude\projects\C--Users-ahmed-Documents-CASS\<session>\workflows\scripts\` | reference only; `tools/claude/` has the reusable versions |
| `old-main-history` git bundle | the pre-purge history that still contains the host IP (the public repo was recreated without it on 2026-09-10) | keep private; `git bundle verify` then `git fetch <bundle> old-main-history` if ever needed |

Secrets that are **not** on any PC: the Coolify API token, the Cloudflare token and
`cass-secrets.env` (APP_KEY, DB passwords) live only on the host under `/home/ubuntu` (mode
0600). The SMTP password, admin password and the Plan 6 knobs live in Coolify's environment
screen. The SSH key `~/.ssh/oci_server` and the owner's infrastructure ledger
(`Documents\ORACLE MCP\infra\`) move with the owner's global Claude migration bundle.

## Setting up the new PC

1. Clone: `git clone https://github.com/ahmedsk2/cass.git` into `C:\Users\<user>\Documents\CASS`.
   The identity is set per repo on the old PC: `git config user.name "Ahmed"` and
   `git config user.email ahmedsk2@gmail.com`.
2. Restore the `C:\cass-extras` items per its README (verify the bundle first).
3. Toolchain (see `CLAUDE.md` "Toolchain (this machine)"): PHP 8.4 with `gd`, `intl`, `sockets`
   (`extension=sockets` uncommented in `php.ini` — `composer install` fails its platform check
   without it), Composer, Node 24, Docker Desktop, Python 3.14, `gh` logged in as `ahmedsk2`,
   `~/.ssh/oci_server`.
4. Build and prove it, in Git Bash, each exit code captured:
   `php <composer.phar> install`, `npm ci`, `npm run build`,
   `php artisan test > /tmp/t.log 2>&1; echo "rc=$?"` (expect rc=0, about 1,250 tests),
   `./vendor/bin/phpstan analyse`, `./vendor/bin/pint --test`. The browser suite is CI-only.
5. Local server: the Claude desktop app's Browser pane starts `cass-dev` from
   `.claude/launch.json` (now tracked); sign in with the two local accounts above.
6. Host access: `ssh -i ~/.ssh/oci_server ubuntu@<host>` (the address is in the project memory
   and the infra ledger, never in this repository). Deploys and migrations follow the runbook.
