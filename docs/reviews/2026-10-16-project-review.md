> Archived copy of `PROJECT-REVIEW.md` from the review pack sent to the outside expert on 2026-10-16. Inside it, `docs/backlog.md`, `docs/2026-09-10-cass-v2-design.md` and `docs/deploy-production.md` are the pack's names for `docs/superpowers/plans/backlog.md`, `docs/superpowers/specs/2026-09-10-cass-v2-design.md` and `docs/runbooks/deploy-production.md`. The expert's answer is `2026-10-16-expert-brief.md`.

# CASS — project review for an outside expert

Written on 2026-10-16 from the repository itself (`ahmedsk2/cass`, branch `main`, commit `51caee1`), right after the project was moved to a new development PC and its build proved green there. It describes what the code does, not what the plans intended. Every claim names a file (and usually a line) so it can be checked. "(inferred)" marks the few conclusions drawn rather than read directly.

Nothing in this pack comes from the owner's private files: no legacy data, no `.env`, no server address, no Coolify identifier. One identifier in the runbook copy is replaced by `<coolify-project-id>`.

**What the owner asks of you:** review `CLAUDE.md`, the rule book every AI coding session follows on this project, and the project itself. Section 15 lists the questions. The owner will apply your feedback to `CLAUDE.md` and the docs **before** any further code change.

---

## Contents

1. What CASS is and who uses it
2. Stack and repository layout
3. The three panels and the public site
4. Domain model and lifecycle
5. How business logic, authorisation and tenancy are organised
6. Testing and quality gates
7. Continuous integration and dependency updates
8. Build, deployment and operations
9. Security posture
10. Production versus `main`
11. Open decisions and backlog
12. How the project is developed (the role of `CLAUDE.md`)
13. Verification performed for this review
14. Risks and weak spots, ranked
15. Questions for the reviewer

---

## 1. What CASS is and who uses it

CASS (Conference Abstract Submission System) is a multi-tenant web platform. Conference organisers use it to:

- publish a call for abstracts;
- collect submissions;
- run peer review, in one of two modes: an open pool, or assigned reviewers;
- rank submissions by normalised scores;
- send templated decision letters.

It replaces a small single-tenant legacy PHP site. Two past conferences from that site can be imported (`cass:import-legacy`). The design document is `docs/2026-09-10-cass-v2-design.md`; in the repository it lives at `docs/superpowers/specs/`.

The live site is https://cass.towardpcc.com. The owner is a paediatric physician who runs it for medical societies and is its only operator.

| Who | How they get in | Where |
|---|---|---|
| Platform admin | a boolean `users.is_platform_admin` plus a verified email (`app/Models/User.php:45`, `:118`) | `/admin` panel |
| Organisation member, with role owner, admin or member | membership in `organization_members` (`app/Enums/OrganizationRole.php:11-13`) | `/org` panel, one tenant per organisation |
| Reviewer | an active `conference_reviewers` row, per conference (`User.php:84-93`), created by accepting an emailed invitation at `/invite/{token}` | `/review` panel |
| Author | **no account**: a per-submission bearer token, sent by email (`app/Support/Tokens/SubmissionToken.php:26-34`) | `/c/{org}/{conference}/submit`, then `/s/{token}` |
| Public | anonymous | landing, about, contact, register, conference pages, short links `/q/{code}`, verified custom domains |

## 2. Stack and repository layout

**Stack:**
- PHP 8.4 (Composer platform pinned to 8.4.1, `composer.json:93-95`).
- Laravel 13.32 and Filament 5.8.1 for the three authenticated panels.
- Livewire 4.4 with Blade and Tailwind 4 for the public pages.
- MySQL 8.4 in production; SQLite for tests and local development.
- Pest 5.1 for tests, Larastan 3.11 at level 6 for static analysis, Pint for code style.
- Supporting libraries: dompdf for posters, chillerlan/php-qrcode, openspout for XLSX export, Spatie activity log.

**Size:**
- `app/`: 280 PHP files, about 31,000 lines, about a quarter of which are comments.
- `resources/views`: 52 Blade files.
- `tests/`: 128 files, about 24,000 lines.
- `database/migrations`: 36 migrations.
- History: 179 commits since 2026-09-10.

| Path | What is there |
|---|---|
| `app/Actions/<Domain>` | business logic, 63 classes in 11 groups: Conferences, Decisions, Demo, Invitations, Legacy, Mail, Organizations, Reviewers, Reviews, ShortLinks, Submissions |
| `app/Policies` | 17 authorisation policies |
| `app/Filament/{Admin,Organizer,Reviewer}` | the three panels; providers in `app/Providers/Filament` |
| `app/Livewire/Public`, `app/Http/Controllers/Public`, `routes/web.php` | the public site |
| `app/Support` | 41 helper classes: scoring, tokens, Turnstile, client IP, custom domains, legacy import parsing, exports |
| `app/Console/Commands`, `routes/console.php` | seven `cass:*` commands and the schedule |
| `docker/`, `Dockerfile`, `docker-compose.*.yml` | the production image and its processes |
| `docs/` | design, implementation plans 1-7, backlog, launch checklist, runbook, handover |
| `tools/claude/` | the scripts the AI-agent build pipeline uses (section 12) |

The project was built in seven numbered implementation plans, kept in `docs/superpowers/plans/`:

1. foundation
2. conferences
3. submissions
4. reviewers
5. decisions
6. launch hardening
7. post-launch polish

Code comments refer to them as "Plan N".

## 3. The three panels and the public site

Each panel is a Filament panel with its own path (`app/Providers/Filament/*PanelProvider.php`).

All three panels share:
- one login class with two throttles: 5 attempts a minute per email and IP, and 20 a minute per IP across all accounts (`app/Filament/Auth/Login.php:31-104`);
- password reset;
- optional app-based two-factor authentication with recovery codes.

The organizer and reviewer panels also require a verified email.

- **Admin, `/admin`** (no tenancy):
  - *Organisations*: view, edit, approve, reject, purge.
  - *Conferences*: view, restore, purge.
  - *Submissions, Reviews and Email logs*: read-only.
  - There is no Users resource, so no screen can grant platform admin. The only way is the seeder (`database/seeders/PlatformAdminSeeder.php:14-26`).
- **Organizer, `/org`** (Filament tenancy on `Organization` by slug, `OrganizerPanelProvider.php:46`):
  - *Conferences*: create, edit, share (QR code, posters), ranking, email templates, reviewers, assignments.
  - *Submissions*: list and view, withdraw, resend link, CSV and XLSX export.
  - *Members* and the organisation profile, including branding and custom domain.
- **Reviewer, `/review`** (no tenancy; a scope class decides what a reviewer sees, `app/Support/Reviews/ReviewerScope.php`):
  - a review queue;
  - the review form;
  - a dashboard;
  - a per-conference affiliation editor.
- **Public** (`routes/web.php`):
  - conference page `/c/{org}/{conference}` and submit form `.../submit`;
  - author status page `/s/{token}`;
  - invitation acceptance `/invite/{token}`;
  - signed file downloads `/files/{ulid}`, valid for 30 minutes;
  - short links `/q/{code}`;
  - the same conference pages on an organisation's verified custom domain (`:191-203`).

## 4. Domain model and lifecycle

The design document's section 3 has the full tree. In short: an Organisation (the tenant) has Members and Conferences. A Conference has:
- Tracks and Custom fields;
- one Review form of weighted Questions;
- Reviewer invitations and Reviewers;
- Submissions, each with Authors, Files, Assignments, Reviews and a Decision history;
- Email templates and a Short link.

Public identifiers are ULIDs. Only Organisation, Conference and Submission are soft-deleted. A hard purge is application code (`app/Actions/Conferences/PurgeConference.php`, `app/Actions/Organizations/PurgeOrganization.php`).

**Lifecycles:**
- Organisation: `pending → approved | suspended`.
- Conference: `draft → open → closed → reviewing → decided → archived`. The allowed transitions are one table (`app/Enums/ConferenceStatus.php:51-61`).
- Submission: `draft, submitted, withdrawn, under_review, accepted, rejected, waitlisted` (`app/Enums/SubmissionStatus.php:19-25`).

`status` is not mass-assignable. Only Actions change it, each checking its exact source state (for example `app/Actions/Conferences/CloseSubmissions.php:16-26`).

**The end-to-end flow:**
1. **Registration** creates a pending organisation (`app/Actions/Organizations/RegisterOrganization.php`). A platform admin approves it.
2. **Publishing:** an organiser creates a conference and publishes it. Publishing checks for an approved organisation, a valid submission window and at least one review question (`PublishConference.php:25-90`).
3. **Submission:** authors submit (`SubmitAbstract.php:124-201`). Each gets a reference number allocated under a row lock (`AllocateReference.php:54`).
4. **Review:** the organiser closes submissions, invites reviewers and (in assigned mode) assigns them by hand or automatically (`AutoAssignReviewers.php`), then starts reviewing.
5. **Scoring:** reviews are scored as they are submitted (`SubmitReview.php:144-195`). The ranking page shows normalised scores and their spread.
6. **Decisions:** the organiser applies decisions (`ApplyDecision.php`) and sends decision letters (`SendDecisionEmails.php`). Each letter rotates the author's status-link token (owner decision, `docs/launch-checklist.md:53-61`).
7. **Close-out:** the conference is marked decided, then archived.

## 5. How business logic, authorisation and tenancy are organised

- **Actions.** Business logic sits in single-purpose classes under `app/Actions`. They follow one pattern: `blockers()` returns the reasons an operation is refused without changing anything, and `handle()` re-checks and performs it (for example `app/Actions/Decisions/ApplyDecision.php:16-24`). Filament resources and Livewire components mostly validate input and delegate. The exceptions (inferred to be deliberate) are:
  - conference editing uses Filament's default save, and there is no `UpdateConference` action (`EditConference.php:13-24`);
  - tracks, custom fields and review questions use Filament's default create, edit and delete;
  - the review-form lock lives in model events (`ReviewQuestion.php:57-82`);
  - about 270 lines of reporting aggregates live in the `Conference` model (`Conference.php:340-612`).
- **Policies.** Laravel discovers the 17 policies by name. Most of them:
  - give a platform admin everything through `before()` (for example `SubmissionPolicy.php:22-25`);
  - otherwise check membership of the current tenant.

  The membership check is repeated in 15 policies rather than shared.
- **Tenancy.**
  - Conferences use Filament's tenant scope (`ConferenceResource.php:54`).
  - Submissions have no `organization_id`, so the organizer `SubmissionResource` turns Filament tenancy off and scopes its query by hand. With no tenant it falls back to `1 = 0` (`app/Filament/Organizer/Resources/Submissions/SubmissionResource.php:53, 71-89`). The reason is recorded in `docs/backlog.md:36`.
  - Custom pages call `abort_unless(... canView ...)` themselves (for example `ConferenceRanking.php:78`).
  - The project rule (`CLAUDE.md` line 11) is that every tenant model has an `organization()` relation and a policy. It holds only partly:

    | Models | Relation | Policy |
    |---|---|---|
    | Conference, Submission, EmailLog, OrganizationInvitation, OrganizationMember | yes | yes |
    | 11 child models | no — they reach the tenant through their conference or submission | yes |
    | ReviewAnswer, ReviewerReminder, ShortLink, ShortLinkVisit, SubmissionAuthor | no | no |

    None of the five models with neither has its own screen.
  - Cross-tenant negative tests are widespread (section 6).
- **Custom domains.** An organisation claims a domain and publishes a TXT token. Verification compares it with `hash_equals` (`VerifyCustomDomain.php:122`). Verified hosts are added to the trusted-host list, cached for 60 seconds (`app/Support/Domains/CustomDomains.php:34-60`, `bootstrap/app.php:106-112`). A middleware 404s the platform's own pages on tenant hosts (`ResolveCustomDomain.php:45-104`). Adding the domain to the reverse proxy is a manual step in Coolify.

## 6. Testing and quality gates

- **Suite size.** The tests were run on the new PC on 2026-10-16: **1,340 passed, 1 skipped, 6,332 assertions, 122 s**. They ran on SQLite in memory, with the array mailer and a synchronous queue (`phpunit.xml:27-52`).
  - By `it()` calls the suite is about 400 unit and 730 feature tests; data sets raise the run count.
  - Feature tests are grouped by panel: Organizer 263, Public 204, Admin 88, Reviewer 47, Mail 27, Console 19, Security 13.
- **What is covered well.**
  - Every Action has unit tests.
  - Panel access and cross-tenant negative cases appear throughout (for example `tests/Feature/Organizer/MembersPageTest.php:293`, `ConferenceRankingTest.php:82`, `Admin/MembersManagementTest.php:141`).
  - The mail pipeline, including token redaction.
  - Security headers, the rate-limit inventory and trusted proxies.
  - Encryption of queued mail.
  - Text checks on `docker/nginx.conf` and the `Dockerfile`.
  - The legacy importer.
  - Language coverage (no untranslated literals in covered files).
- **Browser tests.** `phpunit.browser.xml` (Pest with Playwright, Chromium, CI only) has **5 cases**: four probe the Content-Security-Policy, and one is an anonymous end-to-end abstract submission (`tests/Browser/CspTest.php`, `SubmitAbstractTest.php:178`). No signed-in panel flow is tested in a browser.
- **Static analysis and style.**
  - Larastan level 6 over `app`, `config`, `database` and `routes`. `tests/` is not analysed, and there is no baseline file (`phpstan.neon`).
  - Pint uses the Laravel preset with strict types (`pint.json`).
  - Both pass with no errors.
- **Gaps.**
  - No coverage measurement (`.github/workflows/ci.yml:45`, `:105`).
  - No JavaScript tests.
  - Three policies are never named in a test; they are exercised through panel tests (inferred).
  - A stale conditional skip in `tests/Feature/Admin/PurgeTest.php:93`.
  - Framework scaffold `ExampleTest` files remain.
  - The Filament table test helpers the suite uses are deprecated (`docs/backlog.md:52`).
  - One wall-clock performance test is skipped by design (`backlog.md:66`).
- **Engineering rules.** `CLAUDE.md` requires two things:
  - a gate is verified by its exit code, never by piped output;
  - a new test is shown failing before the code that makes it pass.

## 7. Continuous integration and dependency updates

`.github/workflows/ci.yml` runs on every push to `main`, on pull requests, and weekly (Monday 03:00 UTC). Every action is pinned by commit SHA, and the token has read-only permissions.

| Job | What it does |
|---|---|
| `test` | builds the front end, then runs Pint, Larastan, the full suite on SQLite and the full suite again on MySQL 8.4 (`:24-68`) |
| `scripts` | `sh -n` on the shell scripts, plus a test of the backup script with a fake `docker` (`:77-85`) |
| `browser` | the five Playwright cases (`:91-128`) |
| `image` | builds the production image for arm64 under QEMU, about 20 minutes. It is never run or pushed (`:130-143`) |
| `smoke` | builds the image for amd64, boots it against MySQL and checks (`:145-276`):<br>• `/up` and the landing page, plus a large POST that must not return 500<br>• no request-serving process runs as root, and php-fpm listens on loopback only<br>• the Filament and Livewire assets load, and guests are redirected<br>• dompdf can render<br>• the CSP header is enforcing and carries a matching nonce |

**Gaps:**
- No `composer audit`, `npm audit`, container CVE scan or SAST step.
- No Composer or Docker layer cache.
- Three jobs have no timeout.
- `concurrency` with `cancel-in-progress` also applies to `main`, so a quick second merge cancels the first run.
- The MySQL service image is a tag, not a digest.
- **The repository is now private on a free GitHub plan, which offers no branch protection.** The GitHub API answers 403 for `branches/main/protection`, so "merge only with CI green" is a habit, not something GitHub enforces.

Dependabot (`.github/dependabot.yml`) runs weekly. It covers five ecosystems, grouped, and never takes a MySQL major version on its own (`:42-69`). **Seven Dependabot pull requests are open (#25-#31).** Two are red:

- **#26, the framework group:** fails `test`. This is expected for any Filament bump, because three Filament views are published with a CSP nonce and pinned by digest (`tests/Feature/Security/PublishedFilamentViewsTest.php`; `docs/backlog.md:88`).
- **#29, the docker base-image group:** fails `image` and `smoke`.

## 8. Build, deployment and operations

**Image** (`Dockerfile`):
- Three stages: Composer vendor without dev packages, Node asset build, and a `php:8.4-fpm-alpine` runtime. All base images are pinned by digest.
- supervisord runs four programs: nginx, php-fpm, `queue:work --tries=3` and `schedule:work` (`docker/supervisord.conf:40-79`).
- Request-serving processes, the queue and the scheduler run as user `app`. PID 1 (supervisord) and the nginx and php-fpm master processes run as root (there is no `USER` line), and CI checks this split.
- `docker/entrypoint.sh` waits for the database, builds the caches and **only reports** pending migrations (`:22-23`). Migrations are never run at boot; this is a project rule.
- nginx strips the `/s/`, `/invite/` and file-signature tokens from the access log and from Referer (`docker/nginx.conf:7-31`).

**Topology** (`docker-compose.production.yml`, `docs/deploy-production.md`):
- One ARM64 Oracle Cloud host runs Coolify with Traefik, behind Cloudflare.
- Coolify builds the image **on the host** from the GitHub repository.
- Two containers: `app` (768 MB, 1.5 CPU) and `mysql` (512 MB, internal network only).
- Queue, cache and sessions use the database; there is no Redis.
- Uploaded files live on one named volume.

**Releasing** (runbook "Every release", `:68-117`):
1. Merge with CI green.
2. Deploy from Coolify.
3. Coolify stops the old container **before** starting the new one, so a release means downtime.
4. If the release has migrations, the owner runs `migrate --force` by hand in the new container as soon as it is healthy. Until then, new pages return 500.
5. Check `/up` and a styled login page, and time two public pages against a 300 ms budget.

There is no maintenance-mode step and no backup taken before migrating. Rollback means redeploying the previous build; schema rollback only by restoring a backup (`:1058-1070`).

`tools/claude/deploy.sh` automates this over SSH, but it hides failures:
- it uses `set -u`, not `-e` (`:7`);
- it pipes migrate and health output through `tail` (`:16`, `:25`);
- it always prints `DEPLOY_DONE` (`:26`).

**Backups** (runbook `:1072-1215`): root's crontab on the same host runs two jobs into `/srv/backups/cass`.

| What | Script | When | Kept | Guard |
|---|---|---|---|---|
| database | `docker/backup.sh` | nightly 03:17 | 14 days | refuses a dump under 4 KB, checks "Dump completed" |
| uploaded files | `docker/storage-backup.sh` | Sundays 03:47 | 56 days | checks the archive's entry count |

- The directory is mode 0700 and the files 0600; they are not encrypted.
- The off-host copy is described as "the existing NAS sync" (`:1074`), but nothing in the repository configures it, and the checklist item to confirm it is open (`docs/launch-checklist.md:137-140`).
- A restore test is described as monthly and "not optional" (`:1136`). The last one recorded was on 2026-09-13 (`docs/HANDOVER.md:18`).
- Both cron lines send their output to `/var/log/cass-backup.log`, so a failing backup **does not** mail anyone, although the runbook says it would (`:1104`, `:1202`, `:1210`).

**Health and monitoring:**
- `/up` is what Docker and Coolify watch.
- `php artisan cass:health` checks the database, pending migrations, queue age, a scheduler heartbeat, a write to the private disk, and that a real mailer is configured (`app/Console/Commands/HealthCommand.php`). **It is run by hand.**
- There is no uptime monitor, no error tracking (no Sentry, Flare or similar), no log shipping and no free-disk check. Logs are the containers' stdout and stderr as Coolify keeps them.

## 9. Security posture

**Tokens.**
- Author status links and invitations use 32 random bytes, stored as SHA-256 hashes and looked up by hash (`SubmissionToken.php`, `InvitationToken.php`).
- Invitations expire after 14 days (`config/cass.php:66`). Author links never expire; they only rotate, on a resend or a decision letter.
- File downloads are temporary signed URLs, valid 30 minutes.
- The custom-domain token is plaintext on purpose: it is published in DNS (`ClaimCustomDomain.php:67`; `docs/backlog.md:71`).

**Authentication.**
- Passwords need at least 10 characters with letters and numbers, plus a breached-password check in production (`AppServiceProvider.php:56-60`).
- Two-factor authentication is available on all panels but **not enforced anywhere**. No `requiresMultiFactorAuthentication()` call exists, and the platform admin has not enrolled yet (`docs/HANDOVER.md:25`).
- Sessions use the database driver, 120 minutes, HttpOnly and SameSite=Lax. The cookie is marked Secure only in the production compose file (`config/session.php:174`; compose `:36`).

**Rate limits.**
- Named limiters for assets, downloads, status pages and invitations (`AppServiceProvider.php:66-87`).
- Per-component limits on submission, registration, contact and invitation sending.
- Keys use the visitor's real IP from Cloudflare's `CF-Connecting-IP`, trusted when the request arrives through the local proxy (`app/Support/ClientIp.php:20-31`). This is safe only if the origin's ports 80 and 443 accept Cloudflare alone. The runbook says they do (`:1232`); the checklist item is unticked (`launch-checklist.md:133-136`).

**Bot protection on the public submit form.**
- A honeypot, a 4-second minimum fill time, 5 submissions a minute per IP, and Cloudflare Turnstile.
- Turnstile **passes when no keys are configured, and production has none** (`app/Support/Turnstile.php:72-74`; `HANDOVER.md:19`).
- If Cloudflare is unreachable it passes and logs a warning (an owner decision); a 5xx answer is a refusal.
- The first draft save emails a status link to whatever address the visitor typed (`app/Livewire/Public/SubmissionForm.php:356-368`).

**Headers and Content-Security-Policy.**
- `app/Http/Middleware/SecurityHeaders.php` sets nosniff, `X-Frame-Options: DENY`, Referrer-Policy, Permissions-Policy and COOP.
- HSTS is left to Cloudflare, and that item is still open.
- The CSP (`ContentSecurityPolicy.php:127-143`) uses a per-request nonce, keeps `'unsafe-eval'` (Alpine needs it) and `style-src-attr 'unsafe-inline'`, and has no report endpoint by design.
- **Production sends it as Report-Only** (`CASS_CSP_REPORT_ONLY=true`, `HANDOVER.md:17`).

**Injection and output.**
- No `$guarded = []`.
- Raw SQL fragments are constant strings.
- Every `{!! !!}` output is escaped or sanitised first: conference descriptions through the Symfony HTML sanitiser, decision letters escaped before Markdown.
- Uploads are limited to PDF, DOC and DOCX, 10 MB each. The sniffed MIME type must match the extension, and files sit on a private disk.
- dompdf has remote, PHP and JavaScript turned off.

**Secrets.**
- Production secrets live in Coolify's environment screen and in a mode-0600 file on the host. They are never in the repository.
- `.env.example` covers the application's own variables. It omits some framework ones (`SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE`, `APP_PREVIOUS_KEYS`, `DB_SSLMODE`, `MAIL_SCHEME`), which falls short of the `CLAUDE.md` rule that it "lists every variable".
- `MAIL_ENCRYPTION` is set and listed as required in the runbook, but **nothing reads it**: Laravel 13 reads `MAIL_SCHEME` (`config/mail.php:44`).

**Known vulnerable dependencies** (audits run 2026-10-16):

| Package | Locked version | Advisory | Severity | Fixed in / notes |
|---|---|---|---|---|
| `filament/filament` | 5.8.1 | CVE-2026-104181: MFA management actions do not ask for the password again | medium | 5.8.2 |
| `league/commonmark` | 2.10.1 | quadratic-time DoS in GFM tables | high | used to render organiser-written templates (inferred to be reachable by signed-in organisers) |
| `league/commonmark` | 2.10.1 | DisallowedRawHtml bypass | medium | not applicable; that extension is unused |

`npm audit` reports 5 findings: 2 critical, 1 high, 2 moderate. All are in development or build-time packages: `concurrently` via `shell-quote`, `source-map-js`, and `@tailwindcss/typography` via `postcss-selector-parser`. The runtime image ships only the built `public/build`.

## 10. Production versus `main`

| | Commit | Content |
|---|---|---|
| Production | `c32bb30`, deployed 2026-09-13 | Plans 1-6. 36 migrations applied. Healthy according to `cass:health` on 2026-10-16 (`docs/HANDOVER.md:11`) |
| `main` | `51caee1` | 38 commits ahead, 110 files changed, about 14,000 lines added |

What is on `main` but not yet in production:
- Plan 7, merged 2026-10-02:
  - one Vite-built theme for all panels;
  - platform-admin edit actions;
  - logging of failed notifications;
  - **encrypted queued notifications**;
  - the hourly sweep of temporary uploads;
  - a reviewer affiliation editor;
  - a language sweep.
- Two of the owner's eight launch decisions, which changed code: withdraw and resend-link narrowed to owner/admin, and no unassigning a reviewer who has already submitted.
- A registration email fix.
- Dependency bumps.
- The handover documents.

Plan 7 adds no migration and no environment variable, but it changes the `Dockerfile` and both compose files (`HANDOVER.md:12`).

Production also still has:
- the demo tenant seeded;
- CSP in report-only mode;
- empty Turnstile keys;
- an empty `email_logs` table, meaning no real email has yet left the platform (`HANDOVER.md:15-19`).

## 11. Open decisions and backlog

- **Launch checklist** (`docs/launch-checklist.md`): 9 of 61 boxes ticked.
  - The eight product decisions in section 1 were answered on 2026-10-01. They cover Turnstile failing open, who may withdraw, remove reviewers or send letters, what the deadline freezes, token rotation, and letter overwrite.
  - Still open:
    - the owner's service settings: Turnstile keys, admin two-factor, SPF/DKIM, the Cloudflare settings, restricting port 22, backup space and the off-host sync;
    - the production-readiness commands. They were run by hand on 2026-09-13 but never ticked (`HANDOVER.md:35`);
    - the full manual walk-through;
    - the legacy import and demo reset;
    - sign-off.
- **Backlog** (`docs/backlog.md`): every deferral and decision is recorded with its reason and the one-line change that would reverse it. Open engineering items include:
  - measuring memory under load: four php-fpm workers at 256 MB inside a 768 MB container that also runs the queue and scheduler (`:8`);
  - 69 inline `style` attributes;
  - about 320 untranslated English literals, 46 of them on the public site where Arabic is promised (`:81`);
  - no re-verification of custom domains;
  - a manual Coolify domain step;
  - deprecated test helpers.
- **Six questions Plan 7 raised for the owner** (`backlog.md:94-99`):
  1. May the admin rename an organisation slug?
  2. May the admin invite members?
  3. Should owners be emailed when the admin edits their organisation?
  4. Should a conflict created by a reviewer's new affiliation be flagged?
  5. May organisers edit a reviewer's affiliation?
  6. At what free-space threshold should `cass:health` warn?

## 12. How the project is developed (the role of `CLAUDE.md`)

`CLAUDE.md` is the rule book that every Claude Code session reads first. It has these sections:

- **Rules:** verify by exit code; test-first; logic in Actions; tenant relation plus policy plus a cross-tenant test; hashed tokens; never commit private files; never migrate at boot; Composer invocation on Windows.
- **Commands:** tests, the MySQL suite, analysis, the dev server.
- **Models and cost:** one orchestrating session briefs worker agents and verifies their gates, and a stronger model does the substantive work. The pipeline per plan is: write the plan, adversarial multi-agent review, staged implementation with per-task spec and quality review, then CI, pull request, merge, deploy, hand-run migrations and live checks.
- **Start here:** which documents a new session reads.
- **Toolchain (this machine):** paths and quirks of the development PC.

The pipeline scripts are in `tools/claude/`. Their default paths still name the old PC (`implement-plan-staged.js:14-15`, `premerge-review.js:14`, `review-plan.template.js:18`, `:31`).

Things the reviewer should know:

- **`CLAUDE.md` is out of date for the new PC.** It still names the old `%LOCALAPPDATA%` paths for PHP and Composer (lines 15 and 39) and Python 3.14. This PC has PHP 8.4.26 and Composer in the owner's tools folder (not on PATH) and Python 3.11.
- **The pack includes a proposed revision, `CLAUDE.proposed.md`.** It changes three things:
  - adds a "Project at a glance" section;
  - replaces the toolchain paths with this PC's, as checked on 2026-10-16;
  - tells sessions to pass explicit paths to the `tools/claude` scripts.

  Its rules are unchanged on purpose. It is not committed; the owner wants your view first.
- **Comments cite the plan documents heavily:** "Plan N" or "Task N" 173 times and "fact N" 50 times. A reader without the plans cannot resolve the "fact" references.
- **One maintainer.** Much of the knowledge is in `docs/` (the runbook alone is 1,364 lines) and in the owner's private Claude memory.

## 13. Verification performed for this review

On 2026-10-16, on the new PC (Windows 11, PHP 8.4.26, Composer 2.10.2, Node 24.19 with npm 12.0, Docker Desktop 4.95). Every step was checked by its exit code:

| Step | Result |
|---|---|
| `composer install` | exit 0 |
| `npm ci` | exit 0; npm 12 skipped esbuild's install script, and the build still works |
| `npm run build` | exit 0 |
| `php artisan test` | exit 0: 1,340 passed, 1 skipped, 6,332 assertions, 122 s |
| `./vendor/bin/phpstan analyse` | exit 0, no errors |
| `./vendor/bin/pint --test` | exit 0 |
| `composer audit` / `npm audit` | exit 1 for both; findings in section 9 |
| `git status` after restoring the git-ignored local files | clean |

Not run: the MySQL test suite (needs Docker to pull MySQL; CI runs it on every push), the browser suite (CI only), anything on the production host.

## 14. Risks and weak spots, ranked

### High

1. **One host holds everything.** The app, database, Coolify, the image build, the backups and the deploy secrets all live on one server. The off-host copy is described but neither configured in the repository nor confirmed. Losing the host could lose the data and the secrets together.
   - Evidence: `docs/deploy-production.md:1074`, `:1164`; `launch-checklist.md:137-140`; `HANDOVER.md:67-69`.
2. **No alerting.**
   - There is no uptime monitor and no error tracking, and `cass:health` is run by hand.
   - Backup failures go to a log file nobody reads.
   - Production has never sent a real email.
   - Evidence: section 8; runbook `:1104`, `:1202`, `:1297`; `HANDOVER.md:16`.
3. **The public submission form is protected only by a honeypot, a fill timer and a per-IP throttle**, because the Turnstile keys are empty. The form emails a link to any address typed into it, so it could be used to send branded mail to strangers.
   - Evidence: `Turnstile.php:72-74`; `SubmissionForm.php:356-368`; `HANDOVER.md:19`.
4. **The platform admin has no second factor.** It is neither enforced nor enrolled, yet this account bypasses every policy. The installed Filament version also has the MFA-management advisory (CVE-2026-104181, fixed in 5.8.2).
   - Evidence: section 9; `SubmissionPolicy.php:22-25`.
5. **The next deploy may fail to fetch the code (needs checking).** The repository is now **private**, but the runbook sets Coolify up from the *public* repository URL. If Coolify has no GitHub credentials, a build cannot clone. Separately, branch protection is unavailable on this plan.
   - Evidence: `deploy-production.md:52`; `gh api` above.
6. **Every IP-based rate limit relies on the origin accepting only Cloudflare.** This is unconfirmed, and the origin address appeared in early public history.
   - Evidence: `ClientIp.php:20-31`; `launch-checklist.md:133-136`.

### Medium

7. **Releases are manual and cause downtime.**
   - The old container stops first, and new pages 500 until someone runs the migrations by hand.
   - No backup is taken before migrating, and there is no schema rollback.
   - `deploy.sh` reports success even when the migration fails (`deploy.sh:7`, `:16`, `:26`).
8. **The tested artefact is not the deployed artefact.** Coolify builds on the host; CI builds arm64 but never boots it, and smoke-tests amd64 instead (`ci.yml:130-163`; compose `:6-9`).
9. **Production is 38 commits behind `main`,** including security work (encrypted queued notifications, the upload sweep). Seven Dependabot pull requests are waiting, two of them red.
10. **Vulnerable dependencies:** `filament/filament` 5.8.1 and `league/commonmark` 2.10.1. CI has no audit step.
11. **The CSP is report-only in production**, and `'unsafe-eval'` remains even when it is enforced. HSTS is not on yet.
12. **Conference settings can change mid-cycle.**
    - Review mode, blind review and deadlines stay editable in every status. Only the slug and reference prefix lock (`ConferenceForm.php:93`, `:166-167`).
    - Tracks and custom fields can be deleted mid-cycle (`TrackPolicy.php:31-39`).
    - A suspended organisation can still send reviewer invitations and reminders. `InviteReviewer` and `InviteMember` do not check the organisation's status, unlike publishing and decision letters (inferred from `app/Actions/Reviewers/InviteReviewer.php:28-57`).
13. **Backup quality.** The restore test is overdue against the runbook's monthly rule. Uploaded files are backed up weekly, so up to a week can be lost. Backups are not encrypted.
14. **Memory limits are unmeasured** (`backlog.md:8`).
15. **The launch checklist is mostly open:** 52 of 61 items. The demo tenant is still live.
16. **One maintainer, and the docs drift.** `CLAUDE.md`'s toolchain is stale, the `tools/claude` defaults are stale, and the plan-number references in comments assume the reader has the plans.

### Low

- `MAIL_ENCRYPTION` is set and documented but unused. STARTTLS on port 587 is therefore opportunistic (inferred).
- `.env.example` misses the session-cookie variables, and `SESSION_SECURE_COOKIE` has no safe default.
- The `Turnstile::verify()` docblock (`:62-63`) contradicts its code on non-2xx answers.
- Author status links never expire.
- Sessions are stored unencrypted in the database.
- Registration reveals whether an email address already has an account.
- `CASS_ADMIN_PASSWORD` stays in the container environment, and re-running the seeder resets the admin password to it (runbook `:72`).
- Livewire's upload throttle keys on the proxy IP rather than the visitor IP (`config/livewire.php:39`), so authors may share one bucket per Cloudflare edge (inferred).
- The container's PID 1 runs as root. This is mitigated: every request-serving process runs as `app`.
- The Coolify project identifier appears in committed comments, tests and the runbook.
- CI has no cache, three jobs have no timeout, and the MySQL service image is not digest-pinned.
- Test hygiene: a stale skip, scaffold tests, deprecated helpers, only five browser cases, no coverage figure, and PHPStan does not cover tests.
- Code hot spots:
  - `app/Actions/Legacy/ImportLegacy.php`, 1,354 lines;
  - `app/Livewire/Public/SubmissionForm.php`, 893 lines;
  - `app/Models/Conference.php`, 697 lines;
  - duplicated filters: "active reviewer" appears 12 times, and the same pair of submission statuses appears in 8 places.
- Places where the docs and code disagree:
  - `bootstrap/app.php:26-29` says the commands folder is not scanned;
  - `routes/web.php:129-133` and `:155-156` have stale comments;
  - the design document says admin is granted by command or by another admin, but only the seeder grants it;
  - the docs call the repository public, but it is private.

## 15. Questions for the reviewer

1. **`CLAUDE.md` as an operating manual.** Are its rules the right ones, stated so a session can obey them, and complete? In particular:
   - should it carry explicit rules for security-sensitive changes, dependency upgrades, production access and deploys?
   - should it carry a definition of "done" (for example: CI green, `HANDOVER.md` updated)?
   - Please mark up `CLAUDE.md` directly, or comment on `CLAUDE.proposed.md`.
2. **The agent method** ("Models and cost"): is an orchestrator plus worker agents with adversarial plan review a sound way to change production code that one person maintains? What would you add: human review points, limits on what an agent may do unattended?
3. **Launch readiness.** Which items in section 14 must be fixed before real conferences and real authors use the platform? Which can wait?
4. **Operations.** For a one-person service on one host, what is the minimum adequate set of:
   - monitoring and alerting;
   - off-host backup and restore drills;
   - release safety (maintenance mode, a backup before migrating, failing loudly)?
5. **Security.** Do you agree with the recorded trade-offs? These are:
   - Turnstile failing open;
   - plaintext domain tokens;
   - author links that never expire but rotate;
   - a report-only CSP before enforcement;
   - `'unsafe-eval'`.

   Is anything missing from section 9?
6. **Architecture.** Is the Actions-plus-policies structure, with hand-scoped submission tenancy, sustainable? Should conference settings and tracks lock by status?
7. **Documentation.** The project carries a 1,364-line runbook, a long backlog, and comments that cite plan numbers. What should be kept, condensed or moved?
