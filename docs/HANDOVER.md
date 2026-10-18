# Handover — start here (open as of 2026-10-18)

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
| Production | https://cass.towardpcc.com runs commit `57a8c4f` (deployed 2026-10-17 23:57 UTC, the A8 release): Plans 1–8, the launch decisions, Filament 5.10.2, Laravel 13.37, the MySQL 8.4 image on digest `24989179…`. The deploy script printed `DEPLOY_DONE`: new container healthy 3.5 minutes after the call, 36 migrations applied and none pending, eight probes correct, `cass:health` all OK. `/org/login` links `/build/assets/theme-GVTPeIlS.css` (the Vite theme) and Livewire answers 200. Measured two minutes after the start: app container 184–186 MiB of 768, MySQL 208–215 MiB of 512, poster A3 render 94.7 MiB peak, conference page 0.031 s and submit page 0.034 s inside the container. Every user's saved theme and collapsed navigation groups reset once with this release (Filament renamed its storage keys). |
| `main` | `0f56755` (2026-10-18 16:41 UTC, the merge of #43), three merges ahead of production's `57a8c4f`: #42 (the A8 record, docs only), #40 (playwright 1.64.0 to 1.64.1, a dev dependency) and #43 (the `composer:2`, `node:24-alpine` and `php:8.4-fpm-alpine` digests in the `Dockerfile`). No migration and no new environment variable since the A8 release; the next deploy builds the image on the new base-image digests and changes nothing else. `main`'s push runs were green on all six jobs after each merge (39143288961 on `48e452b`, 39146152286 on `0f56755`). Plan 8's two optional environment variables, `CASS_STATUS_LINK_PER_ADDRESS_PER_DAY` (3) and `CASS_STATUS_LINK_PER_IP_PER_DAY` (10), take their defaults in production; nothing is set in Coolify. |
| Open pull requests | This record (the handover's `main` and open-PR rows and one README bullet; docs only). Dependabot's 2026-10-17 pair was triaged on 2026-10-18 under "Dependabot" in `tools/claude/README.md`: #40 (npm group, playwright 1.64.1) was rebased onto `fc9a94c` and merged green as `48e452b`; #39 (docker group, two digests) was closed by Dependabot itself when asked to rebase, because the group had gained the `node:24-alpine` digest, and its replacement #43 (three digests) was rebased onto `48e452b` and merged green as `0f56755`, the arm64 image built on both runs. The ignore rule of decision 0012 limits the docker group's `php` and `node` images to digest updates. |
| CI | One run per push (`push` on `main`, `pull_request` elsewhere), plus the Monday schedule and a manual `workflow_dispatch`; a newer push cancels the stale run on pull requests only, and on `main` it never cancels the run in progress (GitHub still drops an older waiting run when a newer one joins the group). Jobs, all on `ubuntu-24.04` and each with a timeout: changes, test (SQLite + MySQL), scripts, browser (Playwright), smoke, and image (arm64 under QEMU), which runs only on the Monday schedule, a manual dispatch, or a change to `Dockerfile`, `.dockerignore`, `docker/`, the compose files, `package.json`, `package-lock.json` or the workflow; test and browser cache Composer downloads, and the MySQL service is pinned to the compose files' digest with a drift check in `scripts`. Before: run 38900953920 (2026-10-16 12:30 UTC) took 38 min wall-clock (test 5, browser 1, smoke 4, image 33). After: run 38999242693 (PR #35, 2026-10-17 03:33 UTC) took 7.5 min wall-clock (changes 0.1, scripts 0.1, test 4.6, browser 0.8, smoke 2.8); `image` was skipped because the PR changes no Docker input (`changes` reported `docker=false`). |
| Dependabot | A compose `mysql` digest PR needs one commit moving `ci.yml`'s two service digests, which also stops Dependabot rebasing it; see `tools/claude/README.md`, "Dependabot". |
| Repository | Public since 2026-10-16 (`docs/decisions/0011-repository-public-for-ci.md`): GitHub Actions refused to start jobs on the private repository and the owner chose not to change billing. The tree and every commit on every ref (192) were scanned for secrets before the flip. The owner decides at the end of the phase-A work order whether to make it private again. |
| Demo tenant | Still seeded on production (`demo-society`, stage `reviewing`, 15 abstracts, 3 reviewers). The reviewer password from the seed is gone; re-seed to get a new one. `cass:demo-reset --confirm` removes it — do that before real use. |
| Email | Production SMTP authenticates (mail.towardpicu.com:587, STARTTLS). `email_logs` is empty: no real message has left the platform yet. |
| CSP | `Content-Security-Policy-Report-Only` is what the site sends (`CASS_CSP_REPORT_ONLY=true` in Coolify). Flip to `false` after one walk-through with a clean browser console. |
| Backups | Nightly database dump 03:17 and weekly storage archive Sunday 03:47, from root's crontab on the host, into `/srv/backups/cass` (0700). Latest dumps 2026-10-17 23:53 and 23:58 UTC, taken by hand around the A8 deploy (28.9 and 33.7 KB, 33 tables, both ending in `-- Dump completed`), plus a hand storage archive of the same night. A restore into a scratch database matched production row counts on 2026-09-13. |
| Turnstile | Keys still empty in Coolify; the submit form fails open (no bot challenge) until they are set. |

## Open — phase A, before any other code change

Phase A is complete: A1 to A9 are done, A8 last, on 2026-10-17. The deploy's record is in the
Production row above and the A8 row below; the launch checklist's section 3 carries that run's ticks.

| # | Item | Who | Status |
|---|---|---|---|
| A1 | Confirm Coolify can still fetch the repository now that it is private; switch to a GitHub App or deploy key if not, and say which in runbook "One-time setup" step 2. **Nothing else ships until this is known.** | Owner, with the engineer | **Fixed 2026-10-16.** The application used Coolify's built-in "Public GitHub" source, which can no longer clone (anonymous `git ls-remote` failed, GitHub 401). It now uses a read-only deploy key like every other app on the host: `git@github.com:ahmedsk2/cass.git` with Coolify private key `cass-deploy-key` (id 9). The key was proven with `git ls-remote` from the host before the switch; the switch itself was a one-row edit of Coolify's `applications` table, because neither the API nor the UI can attach a first key to a public-source app (old values saved on the host as `~/cass-app-source-backup-20261016-185435.txt`). Runbook "One-time setup" step 2 says so. The first real build through the key was the A8 deploy of 2026-10-17: Coolify 4.1.2 cloned and built `main` in 3.5 minutes |
| A2 | Rule book and guardrails: `CLAUDE.md`, `docs/dev-setup.md`, `.claude/settings.json`, the production hook, `tools/git-hooks/pre-push`, model policy in `tools/claude/README.md` | Engineer; owner puts PHP and Composer on the user PATH | Done (#32); PATH set (checked 2026-10-17) |
| A3 | `.env.example` complete and enforced by `tests/Unit/EnvExampleTest.php`; `MAIL_ENCRYPTION` retired for `MAIL_SCHEME` | Engineer; owner deletes `MAIL_ENCRYPTION` in Coolify | Done (#32). Both Coolify entries (normal and preview) deleted on 2026-10-16 |
| A4 | `docs/decisions/` and `docs/security-exceptions.md` | Engineer | Done (#32) |
| A5 | Patch `filament/filament` to 5.8.2+ (CVE-2026-104181) and `league/commonmark` past 2.10.1; re-publish the three nonce'd views with their digests; closes Dependabot #26 | Engineer | Done (#35) |
| A6 | `tools/claude/deploy.sh` fails loudly (`set -euo pipefail`, no `tail`, exit codes checked), backs up before a pending migration, prints `DEPLOY_DONE` only when clean | Engineer | Done (#36) |
| A7 | Remove the old PC's default paths from the `tools/claude` Workflow scripts | Engineer | Done (#37) |
| A8 | Deploy `main` (Plan 7 and the launch decisions; no migration), then re-run and tick launch checklist section 3 | Owner deploys, engineer checks | **Done 2026-10-17 23:57 UTC.** With the owner present: hand `cass-backup` first (the MySQL digest changed), then `tools/claude/deploy.sh` through the runbook's command; Coolify 4.1.2 accepted the POST, built through the read-only deploy key, and the script printed `DEPLOY_DONE` with no migration pending. The build took `main`'s HEAD of that moment, `57a8c4f`, not the `6256b06` checked hours earlier: the owner had merged #41 (Plan 8) sixteen minutes before the start. The merge commit's tree is the PR's tip, whose CI was green including the arm64 image, and `main`'s own run (39079795575) finished green two minutes after `DEPLOY_DONE`; runbook "Every release" step 2 now says to re-fetch and compare right before starting. Checklist section 3 was run and ticked the same night; left open there: the enforcing CSP (after the walk-through, B12), HSTS (Cloudflare, B4), the restore drill (B8) and the ranking timing against a few hundred abstracts. Also for the owner: regenerate the application's four manual webhook secrets in Coolify (Webhooks tab), which the engineer's pre-flight printed into the session by mistake |
| A9 | Triage Dependabot #27, #30, #31 (green), #25 (red until `ci.yml` took its digest) and #29 (red: base images) | Engineer; owner decides any base-image major | Done (#38). Merged on 2026-10-17 with CI green: #27 (composer dev group), #30 (actions), #25 (compose `mysql` digest, plus one commit moving `ci.yml`'s two service digests to match) and #31 (npm group, Vite 8: the three panel login pages and the public landing page were checked on its build with no console or server errors; no signed-in pass, because the local accounts' passwords are not in the repository; CI's browser and smoke jobs cover the Vite build and the built image). Closed #29 (docker group: PHP 8.5, Node 25): OPcache is built into PHP 8.5, so `docker-php-ext-install opcache` fails the image build (backlog "Dependencies"; Dependabot now ignores these lines, decision 0012). #26 and #28 were superseded by #35 |

## Open — phase B, before real conferences and real authors

| # | Item | Who | Status |
|---|---|---|---|
| B1 | Turnstile keys in Coolify | Owner | Not started |
| B2 | Platform-admin second factor enrolled, and enforced on the admin panel | Owner and engineer | Not started |
| B3 | Origin lockdown: 80/443 to Cloudflare only, 22 to known addresses | Owner | Not started |
| B4 | Cloudflare settings (checklist section 2) | Owner | Not started |
| B5 | External uptime monitor on `/up`, runbook "Monitoring" | Owner; engineer documents | Not started |
| B6 | Dead-man heartbeats: hourly `cass:health`, both backup crons | Engineer; owner creates the checks | Not started |
| B7 | Error tracking | Engineer; owner picks the service | Not started |
| B8 | Off-host encrypted backups and a restore drill from the off-host copy | Owner, with the engineer | Not started |
| B9 | Suspended organisations cannot send invitations or reminders | Engineer | Done (#41) |
| B10 | Cap status-link emails per address and per IP | Engineer | Done (#41) |
| B11 | Lock `review_mode` and `blind_review` from `reviewing`; no deleting referenced tracks or custom fields; log deadline edits | Engineer | Done (#41) |
| B12 | CSP enforcing after the walk-through | Owner | Not started |
| B13 | Launch checklist closed (walk-through, data, sign-off, memory readings) | Owner and engineer | Not started |

Also open for the owner: the questions Plans 7 and 8 raised (`backlog.md`, "Open questions for the
owner"), and, after A8, whether the repository goes private again (decision 0011): forks taken
while it is public persist (GitHub counted none on 2026-10-17), and branch protection is free only
while it is public (`main` has none today). Phases C (CI audits, build once and deploy that image,
disk check, seeder hardening, native submission tenancy, one membership check, test hygiene, docs
restructure) and D (on touch) are in the brief.

## How the work is done

`CLAUDE.md` "How work is done" is the method and `tools/claude/README.md` has the detail: the
model policy, the four owner review points, what an agent never does without the owner, and the
length budget. Plans are written, adversarially reviewed, implemented task by task, reviewed
again over the whole branch, merged through a pull request the owner approves, and deployed by
the owner through the deploy script, which backs up and runs any pending migration in the new
container.

Plans 1–8 and the backlog are in `docs/superpowers/plans/`; the design is
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
