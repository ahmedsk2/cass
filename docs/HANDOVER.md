# Handover — start here (open as of 2026-10-16)

The single state document. A new session reads this first, then `docs/launch-checklist.md` and
`docs/superpowers/plans/backlog.md`. Keep this file current: every line under "Open" is either
done or still true.

On 2026-10-16 an outside expert reviewed the project (`docs/reviews/2026-10-16-project-review.md`)
and answered with a ranked work order (`docs/reviews/2026-10-16-expert-brief.md`). "Open" below
is that work order: phase A before any other code change, phase B before real conferences and
real authors, phases C and D after. Each item's "done when" is in the brief.

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

## Open — phase A, before any other code change

| # | Item | Who | Status |
|---|---|---|---|
| A1 | Confirm Coolify can still fetch the repository now that it is private; switch to a GitHub App or deploy key if not, and say which in runbook "One-time setup" step 2. **Nothing else ships until this is known.** | Owner, with the engineer | **Fixed 2026-10-16.** The application used Coolify's built-in "Public GitHub" source, which can no longer clone (anonymous `git ls-remote` failed, GitHub 401). It now uses a read-only deploy key like every other app on the host: `git@github.com:ahmedsk2/cass.git` with Coolify private key `cass-deploy-key` (id 9). The key was proven with `git ls-remote` from the host before the switch; the switch itself was a one-row edit of Coolify's `applications` table, because neither the API nor the UI can attach a first key to a public-source app (old values saved on the host as `~/cass-app-source-backup-20261016-185435.txt`). Runbook "One-time setup" step 2 says so. The first real build is A8 |
| A2 | Rule book and guardrails: `CLAUDE.md`, `docs/dev-setup.md`, `.claude/settings.json`, the production hook, `tools/git-hooks/pre-push`, model policy in `tools/claude/README.md` | Engineer; owner puts PHP and Composer on the user PATH | Done (#32); PATH pending |
| A3 | `.env.example` complete and enforced by `tests/Unit/EnvExampleTest.php`; `MAIL_ENCRYPTION` retired for `MAIL_SCHEME` | Engineer; owner deletes `MAIL_ENCRYPTION` in Coolify | Done (#32). Both Coolify entries (normal and preview) deleted on 2026-10-16 |
| A4 | `docs/decisions/` and `docs/security-exceptions.md` | Engineer | Done (#32) |
| A5 | Patch `filament/filament` to 5.8.2+ (CVE-2026-104181) and `league/commonmark` past 2.10.1; re-publish the three nonce'd views with their digests; closes Dependabot #26 | Engineer | Not started |
| A6 | `tools/claude/deploy.sh` fails loudly (`set -euo pipefail`, no `tail`, exit codes checked), backs up before a pending migration, prints `DEPLOY_DONE` only when clean | Engineer | Not started |
| A7 | Remove the old PC's default paths from the `tools/claude` Workflow scripts | Engineer | Not started |
| A8 | Deploy `main` (Plan 7 and the launch decisions; no migration), then re-run and tick launch checklist section 3 | Owner deploys, engineer checks | Not started |
| A9 | Triage Dependabot #25, #27, #28, #30, #31 (green) and #29 (red: base images) | Engineer; owner decides any base-image major | Not started |

## Open — phase B, before real conferences and real authors

| # | Item | Who |
|---|---|---|
| B1 | Turnstile keys in Coolify | Owner |
| B2 | Platform-admin second factor enrolled, and enforced on the admin panel | Owner and engineer |
| B3 | Origin lockdown: 80/443 to Cloudflare only, 22 to known addresses | Owner |
| B4 | Cloudflare settings (checklist section 2) | Owner |
| B5 | External uptime monitor on `/up`, runbook "Monitoring" | Owner; engineer documents |
| B6 | Dead-man heartbeats: hourly `cass:health`, both backup crons | Engineer; owner creates the checks |
| B7 | Error tracking | Engineer; owner picks the service |
| B8 | Off-host encrypted backups and a restore drill from the off-host copy | Owner, with the engineer |
| B9 | Suspended organisations cannot send invitations or reminders | Engineer |
| B10 | Cap status-link emails per address and per IP | Engineer |
| B11 | Lock `review_mode` and `blind_review` from `reviewing`; no deleting referenced tracks or custom fields; log deadline edits | Engineer |
| B12 | CSP enforcing after the walk-through | Owner |
| B13 | Launch checklist closed (walk-through, data, sign-off, memory readings) | Owner and engineer |

Also open for the owner: the six questions Plan 7 raised (`backlog.md`, "Open questions for the
owner"). Phases C (CI audits, build once and deploy that image, disk check, seeder hardening,
native submission tenancy, one membership check, test hygiene, docs restructure) and D (on
touch) are in the brief.

## How the work is done

`CLAUDE.md` "How work is done" is the method and `tools/claude/README.md` has the detail: the
model policy, the four owner review points, what an agent never does without the owner, and the
length budget. Plans are written, adversarially reviewed, implemented task by task, reviewed
again over the whole branch, merged through a pull request the owner approves, and deployed by
the owner with migrations run by hand in the container.

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
| Claude project memory | `%USERPROFILE%\.claude\projects\<key>\memory\`, where the key is the repository path with `:\` and every `\` turned into `-` — host facts, Coolify uuids, decisions | the new key whenever the clone moves |
| Workflow scripts of the build sessions | kept in the owner's private bundle | reference only; `tools/claude/` has the reusable versions |
| `old-main-history` git bundle | the pre-purge history that still contains the host IP (the public repo was recreated without it on 2026-09-10) | keep private; `git bundle verify` then `git fetch <bundle> old-main-history` if ever needed |

Secrets that are **not** on any PC: the Coolify API token, the Cloudflare token and
`cass-secrets.env` (APP_KEY, DB passwords) live only on the host under `/home/ubuntu` (mode
0600). The SMTP password, admin password and the Plan 6 knobs live in Coolify's environment
screen. The SSH key `~/.ssh/oci_server` and the owner's infrastructure ledger
(`Documents\ORACLE MCP\infra\`) move with the owner's global Claude migration bundle.

## Setting up a development PC

`docs/dev-setup.md` has the steps; this machine's own paths go in the git-ignored
`CLAUDE.local.md`. The owner's current PC was set up on 2026-10-16: bundle restored, build green
(1,340 tests passed, 1 skipped), MySQL suite run against `docker-compose.dev.yml` (green but for
one wall-clock-sensitive case, backlog "Testing"), review pack sent and answered. The private extras bundle is no longer needed on that PC; its originals stay
on the owner's transfer drive. The Claude project memory is keyed by the repository path, so it
must be copied to the new key whenever the clone moves. Host access is
`ssh -i ~/.ssh/<key> <user>@<host>`; the address is in the project memory and the infra ledger,
never in this repository.
