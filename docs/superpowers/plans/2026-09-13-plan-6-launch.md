# CASS v2 Plan 6: Custom Domains, the Legacy Import, the Hard Purge, the Admin Read-Only Panel, CSP and Launch

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The application stops being something that works on one hostname with no history, and becomes something that can be launched. An organizer types `abstracts.example.org` on their profile page, is shown one TXT record and one CNAME target, clicks **Verify**, and — once the DNS says what it should — that host serves their conferences with no `/c/{org}` prefix, while the platform admin gets the one email that tells them to add the host to Coolify so Traefik issues the certificate. The two legacy conferences, their twenty-four abstracts, their authors, their six reviewers, their five hundred and ninety-nine review answers and their twenty-four uploaded PDFs arrive through one idempotent console command that un-swaps the fields the 2023 form swapped, refuses to guess where it cannot know, recomputes every score rather than trusting a stored one, and writes a report of every row a human has to look at. A platform admin can finally *see* what the policies have allowed since Plan 3 — submissions with their authors, files and decision history, reviews with their answers, reviewers and assignments, organization members and invitations, all read-only — and can *destroy* it on purpose, through two purge actions that walk the whole tree in application code, delete the objects from the private disk, and count what they are about to remove before they remove it. Every HTML response gains a Content-Security-Policy with a per-request nonce that Laravel's Vite, Livewire 4 and Filament 5 all carry; `email_logs` stops growing for ever; a queued email stops writing a live author token into `failed_jobs` in plaintext; every base image is pinned by digest and watched by Dependabot; a nightly `mysqldump` rotates for fourteen days; every English string Plans 1 and 2 hardcoded moves into `lang/en`; and the last task before the pull request is a checklist a human works through on the live site.

**Architecture:** Single Laravel application, three Filament panels, unchanged. Five new seams, each with exactly one implementation and one fake or one caller:

- **DNS** is `App\Contracts\DnsResolver`, with one production implementation over `dns_get_record()` (`App\Support\Domains\SystemDnsResolver`) and one array-backed `App\Support\Domains\FakeDnsResolver` bound in tests. `VerifyCustomDomain` is the only class that asks it a question and the only class that writes `custom_domain_verified_at`. This is the **first container binding in this codebase** — `AppServiceProvider::register()` is `//` today — and Task 1 argues why a `$this->mock()` of a concrete class is not enough here.
- **Host resolution** is one global middleware, `App\Http\Middleware\ResolveCustomDomain`, which turns a `Host` header into an `Organization` or into a 404, plus one route group mounted at the end of `routes/web.php`. `App\Support\Domains\CustomDomains` is the single list of verified hosts; it is read by that middleware, by the trusted-hosts closure in `bootstrap/app.php`, and by nothing else.
- **The purge** is one deletion order, in one class. `PurgeOrganization` already exists — the `demo-seed` branch landed it as the engine behind `cass:demo-reset` — and Plan 6 adds the conference-scoped `PurgeConference`, which **owns** the order, and refactors `PurgeOrganization` to call it per conference rather than repeat it. `tests/Feature/Console/DemoResetTest.php` passing unedited is the proof that refactor changed no behaviour. Both delete file objects after the transaction commits, never inside it, for the reason `DeleteSubmissionFile`'s own docblock gives.
- **The legacy import** is a reader, a mapper and a writer, not one nine-hundred-line command. `App\Support\Legacy\SqlDumpReader` turns a `mysqldump` file into typed rows with no database involved; `App\Support\Legacy\AuthorList` splits one legacy byline five different ways; `App\Actions\Legacy\ImportLegacy` maps and writes; `App\Support\Legacy\ManualReviewReport` collects everything the mapper refused to guess. Idempotency is one `legacy_imports` mapping table, not a `legacy_id` column on eight production tables — Task 9 argues the choice.
- **The CSP nonce** is one middleware that mints a nonce, hands it to `Vite::useCspNonce()` — which Livewire 4.4.4 reads for free (`FrontendAssets::nonce()`) — and writes the header. Filament 5.8.1 has no nonce support at all, so two of its Blade views are published verbatim into `resources/views/vendor/` with the nonce added, and a drift test pins the SHA-256 of the vendor originals so an upgrade is a red suite rather than a silently broken panel.

**Tech Stack:** PHP 8.4, Laravel 13.31, Filament 5.8.1, Livewire 4.4.4, Tailwind 4.3 (Vite, public pages only), Pest 5.1.4 with the Laravel, Livewire and Browser (Playwright) plugins, Larastan level 6, Pint (strict types), spatie/laravel-activitylog 5.1, MySQL 8.4 in CI and production, SQLite in-memory locally.

**Spec:** `docs/superpowers/specs/2026-09-10-cass-v2-design.md` section 5.8 (branding and custom domain — the domain half; branding shipped in Plan 2) and the custom-domain row of section 6; section 5.10 (legacy import) in full, with `legacy-review.md` as its input; the "hard purge is a platform-admin action that cascades in application code" sentence of section 3; the platform-admin column of section 4 wherever a policy already answers and no screen exists (submissions, files, reviews, reviewers, assignments, decisions, members, invitations); section 8's backups paragraph; **section 9 in full**, which is the only section this plan can finish — CSP, rate limits, hashed tokens, signed file URLs, policies and cross-tenant tests, password rules, security headers, no secret in the repository, audit log, error pages; section 10's "all strings in language files" requirement for the Plans 1-2 views, the countdown script and the poster template, and the memory-limit measurement of its first paragraph; section 11's "base images pinned by digest" and the Dependabot that keeps them honest; and the **"Before launch: security review, production-readiness audit, and a manual walk-through of all flows on the deployed site"** line of section 12, which becomes `docs/launch-checklist.md`.

**Read this before you trust a single symbol below — and before you `git checkout main`.**

1. **A second agent was working in this repository on a branch called `demo-seed` while this plan was being written, and it landed four commits that Plan 6 builds on.** `main` at the time of writing is `da63ec7` ("Plan 5: …"); `demo-seed` is four commits ahead of it:

   ```
   0e9ffa8 docs: the demo commands in the runbook, and what Plan 6 still owes the purge
   5f00528 Add PurgeOrganization and cass:demo-reset
   240231f Add cass:demo-seed, a whole conference loop the owner can drive live
   ee2dc4c Add organizations.is_demo, the flag the demo purge asks for
   ```

   Seventeen files, +2,327 lines: `app/Actions/Demo/{SeedDemo,PurgeDemoOrganization,SilentTemplatedEmail}.php`, **`app/Actions/Organizations/PurgeOrganization.php`**, `app/Console/Commands/{DemoSeedCommand,DemoResetCommand}.php`, `app/Enums/DemoStage.php`, `app/Exceptions/DemoRefused.php`, `app/Support/Demo/*`, `database/migrations/2026_09_14_000100_add_is_demo_to_organizations_table.php`, `tests/Feature/Console/{DemoSeedTest,DemoResetTest}.php`, 102 new runbook lines under `## Demo data`, and one backlog entry naming what Plan 6 still owes the purge.

   **Every other fact in this plan was read off `main`.** The purge facts (9, 10 and the new 46) were read off `demo-seed`. Task 1 Step 1 branches from `main` *after* pulling, and Task 4 Step 1 detects which world it is in and says what to do in each:
   - **If the demo branch has merged** (the expected case): `PurgeOrganization` is there, Task 4 extends it rather than writing it, and `DemoResetTest` is the refactor's guard.
   - **If it has not**, Task 4 writes both classes from the complete code it carries, and its Step 7 run has two fewer files in it.
   - Either way, this plan's migrations are dated **`2026_09_15_*`** so they sort after `2026_09_14_000100`.
   - Either way, the purge **must not gate on `is_demo`** — the demo branch's own backlog entry requires that, and Task 4 has the test.
   - Task 14 Step 4's backlog edit **rewrites** the demo branch's entry rather than deleting it.
2. **Everything else in this plan is Plans 1-5 and is on `main`.** Task 1 Step 1 greps for every symbol this plan calls. A missing line means read the code and adjust this plan's call site to it — never edit `main` to match this document.

**Environment facts for every command below**

- Repo root: `C:\Users\ahmed\Documents\CASS` (Git Bash path `/c/Users/ahmed/Documents/CASS`). All commands are Git Bash.
- Composer: run `php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar <args>`. Do **not** use the `composer.bat` wrapper: it passes through cmd.exe and silently strips `^` from version constraints. **This plan installs nothing**, so the only composer command below is the `show` in Task 1 Step 1.
- PHP 8.4.23 at `C:\Users\ahmed\AppData\Local\php84\php.exe`. `php -m` lists `bcmath calendar ctype curl date dom fileinfo filter gd hash iconv intl json libxml mbstring mysqlnd openssl pcre PDO pdo_mysql pdo_sqlite Phar random readline Reflection session SimpleXML sockets SPL sqlite3 standard tokenizer xml xmlreader xmlwriter OPcache zip zlib` — **no `sodium`, no `exif`**. Note what is *not* in that list and what this plan therefore does not use: there is no `ext-dns`; `dns_get_record()` is in `standard` and is always available, but it is **not** available in the CI container for arbitrary names and must never be called in a test.
- `php -` (reading a script from stdin) does not work on this machine. Every ad-hoc PHP snippet below is written to a file first and run as `php <file>`.
- Node 24.15.0, npm 11.12.1, Docker 29 (daemon running), git, `gh` (logged in as `ahmedsk2`).
- **Baseline before this plan:** branch `plan-6-launch`, created from `main`. **"Baseline" throughout this plan means one thing: the number of passing tests `php artisan test` reports on the freshly branched tree, before a single line of Plan 6 exists.** It is **not** hardcoded anywhere below, and every "Expected: `baseline + N`" line is measured from what Step 1 wrote down. **The count on `main` at `bee0fbb` — Plans 1-5 plus the demo seed/reset commands, merged and deployed — is 962 passed, 1 skipped**, and that is the number to expect; but **Task 1 Step 1 runs the suite and records the real number before changing anything**, because that is the only number this branch can be measured against. If Step 1 prints 450-ish, the branch has the wrong parent.
- Commit after every task with the trailer `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`. **No task may commit with a failing test.**
- **Verify test runs by exit code**, never by reading piped output. This plan uses a **per-task log name** so a stale log cannot be mistaken for a fresh one: `php artisan test > /tmp/t-task-3.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-3.log`.
- **The test *counts* in every "Expected" line below are approximate.** They were computed by counting `it()` blocks and dataset rows while writing the plan. The `rc=0` in the same line is the gate; a count off by a few is not a failure, a count off by *dozens* means a file did not run.
- **`Legacy/` and `legacy-review.md` are git-ignored and must stay that way** (`.gitignore:1-3`, and `CLAUDE.md`'s rule). Tasks 9 and 10 write **fixtures** — small SQL excerpts typed by hand into `tests/Fixtures/legacy/` — and never copy a byte of the real dump into the repository.

**Packages added by this plan**

**None.** Everything here is PHP, Laravel, Filament and Livewire that is already installed:

| Thing that might look like it needs a package | What is used instead | Why |
|---|---|---|
| A CSP builder / `spatie/laravel-csp` | 60 lines in `App\Http\Middleware\ContentSecurityPolicy` + `Vite::useCspNonce()` | The policy is one string of directives assembled from three constants. `Vite::useCspNonce()` (`vendor/laravel/framework/src/Illuminate/Foundation/Vite.php:161`) already nonces `@vite` tags, and Livewire 4.4.4 reads `Vite::cspNonce()` itself (`FrontendAssets.php:306`), so the package would replace one middleware with a config file, a service provider and a profile abstraction — and would still not solve the one hard part, which is that Filament 5.8.1 emits un-nonceable inline tags no package can reach. |
| A DNS library | `dns_get_record()` behind `App\Contracts\DnsResolver` | One function in `ext-standard`, one interface, one fake. A resolver library would add a socket layer this app does not need for one TXT lookup per organizer click. |
| A SQL-dump parser | `App\Support\Legacy\SqlDumpReader`, ~120 lines | The input is one phpMyAdmin dump of ten MyISAM tables whose `INSERT INTO ... VALUES (...),(...)` statements are the only thing that needs reading. A general SQL parser would be a dependency to justify in every future audit, for a command that runs once. The reader uses `str_getcsv`-style manual tokenising because MySQL's escaping (`\'`, `\\`, `\r\n`) is not CSV's. |
| An import/ETL framework | one `app/Actions/Legacy` class and one console command | The whole import is twenty-four abstracts and five hundred and ninety-nine answers. |
| A backup package | `docker/backup.sh`, 30 lines of `sh` + one host cron line | Spec section 8 asks for "nightly `mysqldump` to the data volume with 14-day rotation". That is `mysqldump | gzip`, `find -mtime +14 -delete`, and a line in the runbook. A package would want a scheduler entry inside the app container, which is the one place that must not hold the database root password. |
| A health-check package | Laravel's `health: '/up'` (already in `bootstrap/app.php:17`) plus one `cass:health` command | `/up` already answers for Docker and Coolify. `cass:health` is for a human at a terminal and checks the four things `/up` deliberately does not: the queue worker's last heartbeat, the private disk, the mailer configuration and pending migrations. |

**What this plan does NOT build (kept honest):**

- **Automating the Coolify domain step.** Spec 5.8 says the platform admin is *notified* to add the host, and section 15 says "v2 may automate through the Coolify API". Verification emails the admin, the runbook gives the exact `PATCH /api/v1/applications/{uuid}` call, and nothing in the application talks to Coolify. An app that can add its own domains needs a Coolify API token in its environment, which is a credential that can rewrite the whole deployment — a much larger blast radius than the problem.
- **Wildcard or apex custom domains, and per-conference domains.** One verified host serves one organization. `organizations.custom_domain` is `unique`, so two organizations cannot claim the same host; an organization has one domain, not a list.
- **Automatic re-verification or expiry of a verified domain.** Nothing re-checks the TXT record on a schedule. If an organizer deletes the record the domain keeps working, because by then Traefik holds a certificate and the host is in the trusted-host list — and a nightly job that could take a live conference offline because a DNS provider had a bad minute is a worse failure than a stale record. Re-verification is the **Verify** button, and removing a domain is an explicit organizer action.
- **Importing legacy *decisions*.** There are none. `legacy-review.md:239` is explicit: *"there is no average, weighting, ranking, or accept/reject decision anywhere in the code"*. `submission_decisions` stays empty after the import and the imported conferences land in `archived`, not `decided`.
- **Importing legacy passwords.** All ten legacy rows carry real `$2y$10$` bcrypt hashes that Laravel would accept, and spec 5.10 still says "users (without passwords; they receive a reset link on first login)" — so the import creates users with an unguessable random password and no `email_verified_at`. Carrying the hashes would silently grant ten accounts on a new platform to whoever knew a password on a dead PHP application that stored reset tokens in a reusable column (`legacy-review.md:276`).
- **Importing the legacy `newsletters` table** (0 rows, and v2 has no subscriber concept), the two `php_errorlog` files or `to do.txt`.
- **A per-conference manager model.** Legacy `conference_managers` scopes a manager to one edition; v2 scopes an organizer to an *organization* (spec section 3). Both legacy managers become organization members, and the import reports the widening rather than pretending it did not happen.
- **Restoring a purged row.** There is no undo. The purge is behind a typed confirmation, it prints what it is about to destroy, and the runbook's answer to "I purged the wrong conference" is the nightly backup this plan also ships.
- **A platform-admin screen that *writes* anything new.** Every admin resource this plan adds is index-plus-view. The only writes a platform admin gains are the two purges and Plan 1's approve/reject — and the purge actions refuse for anybody who is not `is_platform_admin`, in the action, because there is no `OrganizationPolicy` in this codebase at all and Filament treats a missing policy method as ALLOW.
- **An organizer panel theme.** Still the Plan 2 backlog item: Tailwind utility classes do nothing inside a Filament panel, so every new panel view here uses `<x-filament::section>` and inline styles — which is also why `style-src-attr 'unsafe-inline'` is unavoidable in Task 7 and is argued there rather than apologised for.
- **HSTS.** Spec section 9 gives it to Cloudflare. `SecurityHeaders` does not set `Strict-Transport-Security`, and Task 13's checklist has the Cloudflare toggle instead.
- **Arabic.** Task 12 extracts English into `lang/en`; it does not add `lang/ar`. Spec section 10 asks for the strings to *live* in language files so Arabic "can be added without code changes", and section 14 puts the Arabic interface itself in v2. Task 12 does add `dir=` to both public layouts, because a layout with no direction attribute makes that promise false in one line.
- **A `lang/` sweep of the panels.** Filament's own strings stay Filament's. Task 12's sweep covers the public Blade views of Plans 1-2, the countdown script and the poster template, exactly as the backlog names them, plus the two Plan-2 organizer views the backlog forgot — and Task 14 records what is still hardcoded, with the same honesty Plan 5 used.

---

## File structure created or changed by this plan

```
app/
  Actions/
    Conferences/
      PurgeConference.php                  # THE deletion order, in application code
    Legacy/
      ImportLegacy.php                     # maps and writes; returns an ImportReport
    Organizations/
      ClaimCustomDomain.php                # set/replace the domain, mint the token
      PurgeOrganization.php                (modified: delegates to PurgeConference,
                                            gains preview() and an optional actor)
      ReleaseCustomDomain.php              # clear all three columns
      VerifyCustomDomain.php               # the ONLY writer of custom_domain_verified_at
  Console/
    Commands/
      HealthCommand.php                    # cass:health
      ImportLegacyCommand.php              # cass:import-legacy
  Contracts/
    DnsResolver.php
  Exceptions/
    CustomDomainRefused.php
    LegacyImportRefused.php
  Filament/
    Admin/Resources/
      Conferences/ConferenceResource.php   (modified: the purge action, relation managers)
      Conferences/Tables/ConferencesTable.php   (modified: purgeAction())
      Organizations/OrganizationResource.php    (modified: the purge action, members)
      Organizations/Tables/OrganizationsTable.php (modified: purgeAction())
      Organizations/RelationManagers/MembersRelationManager.php
      Organizations/RelationManagers/InvitationsRelationManager.php
      Conferences/RelationManagers/ReviewersRelationManager.php
      Submissions/SubmissionResource.php        # index + view, NOT tenant-scoped
      Submissions/Pages/ListSubmissions.php
      Submissions/Pages/ViewSubmission.php
      Submissions/Schemas/SubmissionInfolist.php
      Submissions/Tables/SubmissionsTable.php
      Submissions/RelationManagers/ReviewsRelationManager.php
      Submissions/RelationManagers/AssignmentsRelationManager.php
      Reviews/ReviewResource.php                # index + view, with the answers
      Reviews/Pages/ListReviews.php
      Reviews/Pages/ViewReview.php
      Reviews/Schemas/ReviewInfolist.php
      Reviews/Tables/ReviewsTable.php
    Organizer/Pages/Tenancy/
      EditOrganizationProfile.php          (modified: the Custom domain section + 3 actions)
    Organizer/Resources/Submissions/
      Schemas/SubmissionInfolist.php       (modified: the read-only Reviews section)
  Http/
    Controllers/Public/
      CustomDomainController.php           # GET / on a verified domain
    Middleware/
      ContentSecurityPolicy.php            # the nonce and the header
      RequireCustomDomain.php              # route middleware for the two root routes
      ResolveCustomDomain.php              # global; Host -> Organization, or 404
      SecurityHeaders.php                  (modified: COOP, X-Permitted-Cross-Domain-Policies)
  Mail/
    TemplatedMail.php                      (modified: implements ShouldBeEncrypted)
    ContactMessage.php                     (modified: implements ShouldBeEncrypted)
  Models/
    Conference.php                         (modified: publicUrl() prefers the domain)
    EmailLog.php                           (modified: MassPrunable)
    LegacyImport.php                       # the mapping table's model
    Organization.php                       (modified: 4 custom-domain methods)
    Submission.php                         (modified: submittedReviews() relation, Task 6)
  Notifications/
    CustomDomainVerified.php               # to the platform admin, queued
  Policies/
    OrganizationPolicy.php                 # the first one; purge lives here
    ConferencePolicy.php                   (modified: purge(), and the standing warning rewritten)
  Support/
    Domains/
      CustomDomains.php                    # THE list of verified hosts, cached
      DomainName.php                       # normalise + validate one hostname
      FakeDnsResolver.php
      SystemDnsResolver.php
    Purge/
      PurgeCounts.php                      # significant() and total() over the counts array
    Legacy/
      AuthorList.php                       # one byline -> a list of authors
      ImportReport.php
      LegacyRow.php
      ManualReviewReport.php
      SqlDumpReader.php
bootstrap/
  app.php                                  (modified: trustHosts, 2 middleware, 2 commands)
config/
  cass.php                                 (modified: domains, security, legacy blocks)
database/
  factories/LegacyImportFactory.php
  migrations/
    2026_09_15_000100_create_legacy_imports_table.php
docker/
  backup.sh                                # nightly mysqldump, gzip, 14-day rotation
  nginx.conf                               (modified: the /storage/ CSP keeps its own)
Dockerfile                                 (modified: three digest pins)
docker-compose.production.yml              (modified: mysql digest pin)
docker-compose.dev.yml                     (modified: two digest pins)
.github/
  dependabot.yml                           # docker, composer, npm, github-actions
  workflows/ci.yml                         (modified: CSP smoke assertions)
lang/
  en/public.php                            # landing, about, privacy, terms, nav, footer
  en/conference.php                        # the public conference page and the CTA
  en/domain.php                            # the custom-domain section and its errors
  en/admin.php                             # the read-only admin screens and the purge
  en/legacy.php                            # the import command's output and its report
  en/poster.php                            # three strings
  en/submission.php                        (modified: countdown.*)
resources/
  js/countdown.js                          (modified: strings come from data-*)
  views/
    components/layouts/public.blade.php    (modified: dir, nonce, og, route())
    components/layouts/conference.blade.php (modified: dir, nonce, og:image, canonical)
    public/about.blade.php                 (modified: __() throughout)
    public/conference.blade.php            (modified: __() throughout)
    public/landing.blade.php               (modified: __(), width/height, WebP)
    public/partials/submit-cta.blade.php   (modified: __() + the countdown data-*)
    public/privacy.blade.php               (modified)
    public/terms.blade.php                 (modified)
    livewire/public/contact-form.blade.php (modified)
    livewire/public/register-organization.blade.php (modified)
    livewire/public/submission-form.blade.php (modified: the Turnstile script nonce)
    pdf/conference-poster.blade.php        (modified: three keys, lang)
    emails/contact-message.blade.php       (modified: escaped plain text)
    filament/organizer/pages/dashboard.blade.php (modified: __())
    filament/organizer/resources/conferences/pages/short-link.blade.php (modified: __())
    filament/organizer/partials/custom-domain-records.blade.php  # the two DNS records
    filament/admin/partials/purge-counts.blade.php   # the purge preview
    vendor/filament/assets.blade.php             # published, + the nonce
    vendor/filament-panels/components/layout/base.blade.php  # published, + the nonce
    vendor/filament-panels/livewire/sidebar.blade.php        # published, + the nonce
public/
  images/illustrations/hero-researcher.webp
docs/
  launch-checklist.md                      # the section 12 "Before launch" line
  runbooks/deploy-production.md            (modified: 5 new sections, 2 rewritten)
  superpowers/plans/backlog.md             (modified)
.env.example                               (modified)
tests/
  Browser/
    CspTest.php                            # no CSP violation on 3 pages
  Feature/
    Admin/AdminReadOnlyTest.php
    Admin/PurgeTest.php
    Admin/SubmissionResourceTest.php
    LanguageCoverageTest.php               (modified: $plan6Sources + $plan12Sources)
    Organizer/CustomDomainTest.php
    Public/CustomDomainRoutingTest.php
    Public/SecurityHeadersTest.php
    Security/EmailLogPruningTest.php
    Security/PublishedFilamentViewsTest.php
    Security/QueuedMailPayloadTest.php
    Security/RateLimitInventoryTest.php
  Unit/
    AuthorListTest.php
    CustomDomainsTest.php
    DomainNameTest.php
    HealthCommandTest.php
    ImportLegacyTest.php
    PurgeConferenceTest.php                # incl. the tenant-purge parity case
    SqlDumpReaderTest.php
    VerifyCustomDomainTest.php
  Fixtures/legacy/
    dump.sql                               # hand-written, tiny, never the real dump:
                                           # schema and rows in one file, the shape a
                                           # phpMyAdmin dump actually has
```

(`tests/Fixtures/abstract.pdf` already exists from Plan 3 and is copied into a scratch uploads directory by the import tests; no second PDF is committed.)

Files this plan *modifies* that are easy to miss in the tree above: `bootstrap/app.php` (Tasks 3, 7, 9 and 11 — **four** tasks, **five** edits to one file: two appended middleware, two appended commands and one rewritten `trustHosts` closure; Task 1 only *reads* this file in its Step 1 verification block — its container binding goes in `app/Providers/AppServiceProvider.php`), `app/Http/Middleware/SecurityHeaders.php` (Task 7, two headers appended to a file Plan 1 wrote and CI greps), `app/Models/Conference.php` (Task 3, one method rewritten and one added), `config/cass.php` (Tasks 1, 7, 8 and 9 — four blocks), `.env.example` (Tasks 1, 7, 8 and 9), `.github/workflows/ci.yml` (Tasks 7 and 11), `routes/console.php` (Task 11, one scheduled closure), `app/Filament/Organizer/Resources/Submissions/Schemas/SubmissionInfolist.php` (Task 6 — the *organizer's* read-only review screen, which the Plan 4 backlog assigned to Plan 6 and which is not an admin screen), and `app/Actions/Organizations/PurgeOrganization.php` (Task 4 — a refactor whose proof is that the demo branch's own tests stay green unedited).

**Four policies this plan deliberately does NOT touch.** `CustomFieldPolicy`, `ReviewFormPolicy`, `ReviewQuestionPolicy` and `TrackPolicy` contain no `is_platform_admin` check at all (fact 11) and answer **false** for a platform admin in the untenanted admin panel. Nothing here needs them: a relation manager consults the *related* model's policy, and all five models this plan hangs off records (`Review`, `ConferenceReviewer`, `ReviewAssignment`, `OrganizationMember`, `OrganizationInvitation`) have a `before()`; the review answers are rendered from a relation inside an infolist, where no policy is consulted. Task 14 records that an admin screen over tracks or custom fields has to amend those four first.

---

## Facts verified in `vendor`, in `php -m`, in `Legacy/` and in the repository before writing this plan

Read these before you doubt a name or a claim below. Every one was checked on this machine on 2026-09-12 against `main` at commit `da63ec7` (Plan 5 merged). Plans 3, 4 and 5's facts still hold; the ones this plan leans on are restated with their own numbers.

1. **Installed versions, from `composer.lock`:** `filament/filament` v5.8.1 (`:1279-1280`) with every sibling at the same version, `laravel/framework` v13.31.0 (`:2329`), `livewire/livewire` v4.4.4 (`:3479`), `pestphp/pest` v5.1.4 (`:11096`), `pestphp/pest-plugin-browser` v5.0.1 (`:11359`), `larastan/larastan` v3.11.0 (`:10619`), `spatie/laravel-activitylog` 5.1.1 (`:5517`), `chillerlan/php-qrcode` 5.0.5 (`:435`), `barryvdh/laravel-dompdf` v3.1.2 (`:75`), `openspout/openspout` v4.32.0 (`:4208`). `phpstan.neon` is `level: 6` over `app`, `config`, `database`, `routes` — **`tests` is not analysed**. `pint.json` is the `laravel` preset plus `declare_strict_types` and alphabetical imports.

2. **`Vite::useCspNonce()` exists and is called nowhere today.** `vendor/laravel/framework/src/Illuminate/Foundation/Vite.php`: `protected $nonce;` (`:21`), `cspNonce()` (`:150-152`), `useCspNonce($nonce = null)` (`:161-163`, `return $this->nonce = $nonce ?? Str::random(40);`), `useIntegrityKey($key)` (`:172`), `nonceAttribute()` (`:1067-1073`, `return new HtmlString(' nonce="'.$this->cspNonce().'"');`). The nonce reaches the tag attribute arrays at `:706`, `:712`, `:786`, `:804`, `:849`, `:1169`, `:1199` and the prefetch scripts at `:526` and `:569`. `grep -rn "useCspNonce\|cspNonce" app/ config/ resources/ bootstrap/` returns **zero hits** on `main`.

3. **Livewire 4.4.4 reads Laravel's Vite nonce by itself — this is the single most load-bearing fact in Task 7.** `vendor/livewire/livewire/src/Mechanisms/FrontendAssets/FrontendAssets.php:304-309`:
   ```php
   protected static function nonce($options = [])
   {
       $nonce = $options['nonce'] ?? Vite::cspNonce();

       return $nonce ? "nonce=\"{$nonce}\"" : '';
   }
   ```
   Consumers: `styles()` emits `<style {$nonce}>` (`:114-115`, `:123`); `js()` emits `<script src="…" {$nonce} …>` (`:213`, `:228`); `scriptConfig()` emits `<script {$nonce} data-navigate-once="true">window.livewireScriptConfig = …</script>` (`:236`, `:249`) **and puts the nonce inside the JSON payload** at `:245` (`'nonce' => $options['nonce'] ?? Vite::cspNonce() ?? ''`) so Livewire's own runtime can nonce scripts it injects later; the stale-asset warning at `:290`. Assets are auto-injected (`config('livewire.inject_assets')` defaults `true` at `vendor/livewire/livewire/config/livewire.php:182`; the repo's `config/livewire.php` overrides only `temporary_file_upload`). **So one `Vite::useCspNonce()` call covers `@vite`, `@livewireStyles`, `@livewireScripts` and the Livewire config script, with no further work.**

4. **Livewire ships a CSP-safe Alpine build, and this plan deliberately does not use it.** `vendor/livewire/livewire/dist/` contains `livewire.csp.js`, `livewire.csp.min.js` and their maps beside the normal builds; `LivewireManager::isCspSafe()` (`:329-331`) is `config('livewire.csp_safe', false)`; `FrontendAssets` picks the filename at `:83-97` and `:265-271`. The default build contains exactly one `new Function(["scope"], "with (scope…")` — Alpine's expression evaluator — which is why `script-src` needs `'unsafe-eval'`. Turning `csp_safe` on would remove that need and would also (a) break `.github/workflows/ci.yml:179`, which curls `/vendor/livewire/livewire.min.js` by name, and (b) break every Filament panel, because Filament's Blade views are full of Alpine expressions that the CSP build's restricted parser (property access and method calls only) cannot evaluate. Task 7 argues the trade in full.

5. **Filament 5.8.1 has NO nonce support anywhere.** `grep -rn "nonce" vendor/filament/*/src/ --include=*.php` and `grep -rn "nonce" vendor/filament/*/resources/views/` both return **zero hits**; the only `nonce` strings under `vendor/filament/` are inside three bundled JS files. The rendering path is `@filamentScripts` → `SupportServiceProvider.php:198` → `AssetManager::renderScripts()` (`vendor/filament/support/src/Assets/AssetManager.php:192-208`) → `view('filament::assets', ['assets' => …, 'data' => …])`; `@filamentStyles` → `:202` → `renderStyles()` (`:279-307`) → the same view with `cssVariables` and `customColors`. `vendor/filament/support/resources/views/assets.blade.php` is eleven lines and emits **one un-nonced inline `<script>`** (`window.filamentData = @js($data)`) and **one un-nonced inline `<style>`** (the `:root` custom-property block). `vendor/filament/filament/resources/views/components/layout/base.blade.php` emits seven more: `<style>` at `:46-68` (`[x-cloak]`), `<style>` at `:80-93` (`--font-family`, `--sidebar-width`), `<script>` at `:100-102` and `:104-106` (`localStorage.setItem('theme', …)`), `<script>` at `:108-125` (`loadDarkMode()`), `<script data-navigate-once>` at `:152-156` (the Echo/broadcasting bootstrap, inside `@if (filament()->hasBroadcasting() && config('filament.broadcasting.echo'))` — it renders on no page today, and it is nonced anyway because the day broadcasting is switched on is not the day to remember this), and `<script>` at `:160-162` (`loadDarkMode()`). **Two `<style>` and five `<script>`, so seven nonces in that one file.** The one escape hatch that exists is `Js::extraAttributes(array)` (`vendor/filament/support/src/Assets/Js.php:92-106`, rendered at `:122` through `getExtraAttributesHtml()` at `:145-155`) — for **registered external scripts only**; `Css::getHtml()` (`:43-51`) has no equivalent and needs none, because a `<link rel="stylesheet">` is not inline. And there is a **third** view, which is easy to miss because it is not part of the layout file: `vendor/filament/filament/resources/views/livewire/sidebar.blade.php:155` is a bare inline `<script>` (the `collapsedGroups` localStorage block inside `fi-sidebar-nav`, **not** wrapped in Livewire's `@script`), and `vendor/filament/filament/resources/views/components/layout/index.blade.php:83` renders it with `@livewire(filament()->getSidebarLivewireComponent())` on **every authenticated panel page** of all three panels. **Conclusion: three Blade views must be published into `resources/views/vendor/` with the nonce added** — `filament::assets`, `filament-panels::components.layout.base` and `filament-panels::livewire.sidebar`. The other Filament views that contain `<script>` — `components/page/index.blade.php`, `components/unsaved-action-changes-alert.blade.php`, `notifications/…/notifications.blade.php` and `database-notifications.blade.php` — are all inside Livewire's `@script`/`@endscript`, which never emits a raw inline tag and is covered by `'unsafe-eval'`, so they need nothing. Filament's render hooks that are free to use (no panel calls `renderHook()` today) are `STYLES_BEFORE` (`base.blade.php:44`), `STYLES_AFTER` (`:97`), `HEAD_END` (`:128`), `BODY_START` (`:141`), `SCRIPTS_BEFORE` (`:147`), `SCRIPTS_AFTER` (`:167`), `BODY_END` (`:169`), plus `@stack('styles')` (`:95`) and `@stack('scripts')` (`:165`).

6. **Inline `style=` attributes are unreachable by any nonce, and this application has about forty-five of them.** A CSP nonce applies to `<style>` *elements*, never to `style` *attributes*; the directive that governs attributes is `style-src-attr`. The load-bearing ones are `resources/views/components/layouts/conference.blade.php:17` (`<body … style="{{ $theme->cssVariables() }}">`, the organization's branding — Plan 2) and `resources/views/brand/logo.blade.php:15-17` (three attributes, and this partial is `->brandLogo()` on **all three panels**); the rest are the Plan 2/4/5 organizer and reviewer panel views that use inline styles because there is no organizer theme. `style-src-attr 'unsafe-inline'` is therefore in the policy, and Task 7 says so out loud rather than quietly.

7. **`ShouldBeEncrypted` reaches a queued *mailable*.** `Illuminate\Mail\SendQueuedMailable::__construct()` sets `$this->shouldBeEncrypted = $mailable instanceof ShouldBeEncrypted;` (`vendor/laravel/framework/src/Illuminate/Mail/SendQueuedMailable.php:76`, property declared `:55`), and `Illuminate\Queue\Queue::jobShouldBeEncrypted($job)` (`:293-300`) returns true for `$job instanceof ShouldBeEncrypted` **or** `isset($job->shouldBeEncrypted) && $job->shouldBeEncrypted`; `createObjectPayload()` then encrypts at `:196-197`. So `class TemplatedMail extends Mailable implements ShouldQueue, ShouldBeEncrypted` puts the whole serialized payload — body included — behind `APP_KEY` in both `jobs.payload` and `failed_jobs.payload`. This is Task 8's fix for the finding below.

8. **The finding it fixes.** `app/Actions/Mail/SendTemplatedEmail.php:57-61` redacts `/s/{64}` and `/invite/{64}` out of the *subject* and says at `:48` "The body still carries the real link - that is the email"; `:79-85` then queues `new TemplatedMail(logUlid: …, subjectLine: $subject, body: $rendered->body, …)`, and `app/Mail/TemplatedMail.php:35-41` promotes `public string $body`. `SerializesModels` replaces Eloquent models with identifiers and leaves plain strings alone, so every author status link — a 64-character bearer credential that grants `/s/{token}` with no account at all — is written verbatim into `failed_jobs.payload` on a final failure, and `routes/console.php:14` keeps failed jobs for **720 hours**. `app/Mail/ContactMessage.php:20` has the same shape with PII rather than a token.

9. **The purge's deletion order is dictated by eleven `RESTRICT` foreign keys**, seven of which are inside the conference subtree: `conferences.organization_id`, `tracks.conference_id`, `custom_fields.conference_id`, `review_forms.conference_id`, `review_questions.review_form_id`, `submissions.conference_id`, `reviews.submission_id`, `reviews.reviewer_user_id`, `reviews.review_form_id`, `review_answers.review_question_id`, `submission_decisions.submission_id`. Three tables soft-delete and must be `forceDelete()`d: `organizations` (`2026_09_10_000200:35`), `conferences` (`2026_09_11_000100:46`), `submissions` (`2026_09_11_001100:55`). Four things have **no foreign key at all** and nothing will delete them for you: `short_links` (polymorphic `target_type`/`target_id`, `unique(['target_type','target_id'])`), `short_link_visits` (cascades from `short_links` only), `activity_log` (`nullableMorphs`), and `sessions.user_id` (index only).

10. **`ReviewQuestion::deleting` throws, and it is the single most likely way a first draft of `PurgeConference` blows up.** `app/Models/ReviewQuestion.php:69-85` throws `ReviewFormLocked` when `$question->reviewForm?->isLocked()` and `ReviewQuestionInUse` when any `review_answers` row references it. A conference that ever had a submitted review has a locked form, so `$question->delete()` throws **even after every answer is gone**. The purge deletes `review_questions` through the query builder (`ReviewQuestion::query()->whereIn(…)->delete()`), which does not fire model events. `static::updating` throws on the same model (`:63-67`). No other model in `app/Models/` has a `deleting`/`deleted` hook.

11. **There is no `OrganizationPolicy`.** `find app -iname "*OrganizationPolicy*"` returns nothing, which is why `OrganizationResource::canAccess()` carries the comment "must not default to allow just because no model policy is registered for Organization" (`:36-42`). Eleven of sixteen policies have a byte-identical `before()` returning `true` for `is_platform_admin`; `EmailLogPolicy` has none and answers `is_platform_admin` on every method by design (`:10-15`); and **`CustomFieldPolicy`, `ReviewFormPolicy`, `ReviewQuestionPolicy` and `TrackPolicy` contain no `is_platform_admin` check at all** — every ability goes through `Filament::getTenant()`, which is `null` in the untenanted admin panel, so they answer **false** for a platform admin. **Nothing in this plan amends those four** — Task 6's decision 2 argues why (a relation manager consults the *related* model's policy, and all five models this plan hangs off records have a `before()`), and Task 14 records that a future admin screen over tracks or custom fields has to amend them first. Task 4 creates the one policy this plan does add, `OrganizationPolicy`.

12. **`ConferencePolicy::forceDelete` and `forceDeleteAny` already answer `true` for a platform admin** through `before()` (`:21-24`), which is exactly what `app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php:64-69` and `ConferencePolicy.php:17-19` warn about: *"no ForceDeleteAction may be added to either panel until the application-code purge exists"*. This plan is the release that makes that warning expire — and it still does not add a `ForceDeleteAction`, because Filament's built-in one calls `$record->forceDelete()` and would hit the `RESTRICT` keys. The purge is a custom `Action` that calls `PurgeConference`.

13. **`organizations.custom_domain`, `custom_domain_token` and `custom_domain_verified_at` have existed since Plan 1 and nothing reads them.** `database/migrations/2026_09_10_000200_create_organizations_table.php:31-33`: `string('custom_domain')->nullable()->unique()`, `string('custom_domain_token', 64)->nullable()`, `timestamp('custom_domain_verified_at')->nullable()`. There are exactly two code references, both passive: the `'custom_domain_verified_at' => 'datetime'` cast (`app/Models/Organization.php:41`) and `->logOnly(['name','slug','custom_domain','custom_domain_verified_at'])` in `getActivitylogOptions()` (`:134`). None of the three is in `$fillable` (`:29-32`), and `Model::preventSilentlyDiscardingAttributes()` is on outside production (`app/Providers/AppServiceProvider.php:35`), so they are written with `forceFill()` from an action — the `is_demo` precedent the demo branch is adding follows the same rule. **No migration is needed for custom domains**; `string(64)` is exactly `bin2hex(random_bytes(32))`.

14. **`bootstrap/app.php:41` pins trusted hosts to `APP_URL` and the runbook says so.** `$middleware->trustHosts(at: fn (): array => [parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'], subdomains: false);` — and `docs/runbooks/deploy-production.md:639-641` is two lines: *"## Custom domain for an organization / Not yet supported: trusted hosts are pinned to APP_URL until custom domains ship in Plan 6."* An unknown `Host` gets HTTP 400 from `TrustHosts` before any route runs, which is also why both healthchecks send an explicit `Host` header (`Dockerfile:60`, `docker-compose.production.yml:62`, runbook `:660-662`).

15. **`Middleware::append()` is the *global* stack, so it runs before routing.** `bootstrap/app.php:28` is `$middleware->append(SecurityHeaders::class);` and `.github/workflows/ci.yml:215-216` proves it reaches a 404 fall-through response by grepping for `x-frame-options: DENY`. Task 3's `ResolveCustomDomain` and Task 7's `ContentSecurityPolicy` are appended the same way and therefore see every request, including one whose path matches no route — which is what lets `ResolveCustomDomain` refuse a reserved path on a custom domain *before* Laravel picks the route.

16. **`Conference::publicUrl()` is one method and has one shape.** `app/Models/Conference.php:652-658` returns `route('conference.show', ['organization' => $this->organization, 'conference' => $this])` — models, not keys, so the binding uses `Organization::getRouteKeyName()` (`slug`) and `Conference::getRouteKeyName()` (`ulid`)… except the route declares `{conference:slug}` (`routes/web.php:38-40`), so the *slug* is what appears. It is called from `app/Filament/Admin/Resources/Conferences/ConferenceResource.php:68` and `:70`, and it dereferences `$this->organization`, which is why the purge confirmation renders it **before** the cascade. `ShortLink::url()` (`app/Models/ShortLink.php:112-115`) and the two `landingUrl()` methods are the only other URL builders on models.

17. **Every named route, from `routes/web.php`:** `landing` `/`, `about`, `privacy`, `terms`, `contact`, `register`, `conference.show` `/c/{organization}/{conference:slug}` (`->scopeBindings()`), `conference.submit` `/c/{organization}/{conference:slug}/submit` (`->scopeBindings()`), `conference-assets.qr.svg|qr.png|poster` (auth + `throttle:conference-assets`), `shortlink.show` `/q/{code}` (`[A-Za-z0-9]{8}`, deliberately un-throttled), `files.download` `/files/{ulid}` (`signed` + `throttle:file-download`), `submission.status` `/s/{token}` (`[A-Za-z0-9]{64}` + `throttle:submission-status`), `invitation.accept` `/invite/{token}` (+ `throttle:invitation-accept`). Health is `/up` from `bootstrap/app.php:17`. **There is no named login route in `routes/web.php`** — `filament.organizer.auth.login` comes from the panel provider, which is why three views fall back to `url('/org/login')` and why `bootstrap/app.php:47` can already call `route('filament.organizer.auth.login')`.

18. **Rate limits, complete.** Four named limiters in `AppServiceProvider::boot()`: `conference-assets` 10/min keyed by user-or-IP (`:49-50`), `file-download` 60/min by `ClientIp` (`:55-56`), `submission-status` `config('cass.status_page_rate_limit')`=20 (`:61-63`), `invitation-accept` `config('cass.invitations.accept_rate_limit')`=10 (`:68-70`). In-component: submission 5/min plus a 600 s penalty bucket (`SubmissionForm.php:662-695`), contact **3 per 600 s** (`ContactForm.php:31-53`), register 5 per 600 s (`RegisterOrganization.php:64-81`), invitation password check 5/min keyed by email+IP (`AcceptInvitation.php:98-127`), invite sending 200/hour per organization (`InviteMember.php:83-87`, `InviteReviewer.php:96-100`), short-link *counting* 60/min/client and 600/min/link (`RecordShortLinkVisit.php:33-45`). Livewire uploads are `throttle:20,1` from `config/livewire.php:39`. **The one spec-section-9 limit that is not met is login.** `Filament\Auth\Pages\Login:70` calls `$this->rateLimit(5)`, and `vendor/danharrin/livewire-rate-limiting/src/WithRateLimiting.php:27` keys it `'livewire-rate-limiter:'.sha1($component.'|'.$method.'|'.request()->ip())` — IP only, not email+IP, and `request()->ip()` rather than `App\Support\ClientIp::from()`, so behind Cloudflare every login attempt on a panel collapses onto one bucket. Task 8 fixes both halves.

19. **`EmailLog` is not prunable and `model:prune` already runs.** `app/Models/EmailLog.php` uses `HasFactory` only; `routes/console.php:19` is `Schedule::command('model:prune')->daily();`. The pattern to copy is `app/Models/ShortLinkVisit.php:9,15,43-48` — `use Illuminate\Database\Eloquent\MassPrunable;` plus `public function prunable(): Builder { $days = max(1, (int) config('cass.short_link_visit_retention_days')); return static::query()->where('visited_at', '<', CarbonImmutable::now()->subDays($days)); }`. `MassPrunable` issues a mass delete and fires no model events; `EmailLog` has a `creating` hook (`:35-40`) and no deleting hook, so it is safe. Adding the trait needs **no scheduler change**.

20. **Every notification in this application is already queued.** All six in `app/Notifications/` implement `ShouldQueue` (`MemberInvitation:29`, `NewSubmissionNotice:25`, `OrganizationApproved:14`, `OrganizationRegistered:15`, `OrganizationRejected:14`, `QueuedVerifyEmail:11`), and so do both mailables (`TemplatedMail:30`, `ContactMessage:16`). **Filament's own password-reset notification is queued too**: `vendor/filament/filament/src/Auth/Notifications/ResetPassword.php:9` is `class ResetPassword extends BaseNotification implements ShouldQueue`, wired at `vendor/filament/filament/src/Auth/Pages/PasswordReset/RequestPasswordReset.php:84-85`. **So the backlog's "Queue the Filament password-reset notification" item is already true and Task 14 retires it with this evidence rather than doing work.**

21. **The browser suite is Pest 4-style over Playwright and runs from its own config.** `tests/Browser/SubmitAbstractTest.php` is the only file; it uses the global `visit('/path')` helper returning a fluent awaitable page, with `->assertSee()`, `->click()`, `->type()`, `->select()`, `->radio()`, `->check()`, `->press()`, `->assertPathBeginsWith()`. `waitForText` is deprecated in v5.0.1 and is a plain alias for `assertSee`. `phpunit.browser.xml` exists because the plugin starts Playwright from a *collection* hook that runs before PHPUnit applies `group` filters, so `tests/Browser` must be outside the suite `php artisan test` loads (`phpunit.xml:8-13` lists only `tests/Unit` and `tests/Feature`). Its `<php>` block mirrors `phpunit.xml` exactly, **including `TURNSTILE_SITE_KEY=""`**, so the Turnstile inline `<script>` is not rendered under the browser suite — Task 7's CSP browser test must set the key through a route the server sees, or assert the other two inline cases instead. CI runs `npx playwright install --with-deps chromium`, `npm run build`, `cp .env.example .env && php artisan key:generate`, then `./vendor/bin/pest --configuration=phpunit.browser.xml`, and uploads `tests/Browser/Screenshots` on failure.

22. **`tests/Pest.php` has exactly four globals** — `bootOrganizerPanel(Organization)`, `bootReviewerPanel()`, `withoutTenant(Closure)`, `scoredReview(...)` — and `RefreshDatabase` is applied to `Feature` and `Browser` but **not** to `Unit` (`:18`), so a unit test that touches the database says `uses(RefreshDatabase::class);` itself. SQLite foreign keys are on because `config/database.php:42` is `'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true)` and neither phpunit file overrides it; **do not add a PRAGMA anywhere**.

23. **The admin panel test idiom is `bootCurrentPanel()`, and its negative is 403, not 404.** `tests/Feature/Admin/ConferencesTest.php` `beforeEach`: `User::factory()->platformAdmin()->create()` → `actingAs(...)` → `Filament::setCurrentPanel('admin')` → `Filament::bootCurrentPanel()`. Cross-role negative: `actingAs($owner)->get(Resource::getUrl('index', panel: 'admin'))->assertForbidden();`. Every admin URL passes the explicit `panel: 'admin'` named argument, and every organizer URL passes `tenant: $this->organization`. The organizer cross-tenant negative goes **through a real request** — `get(ConferenceResource::getUrl('emails', ['record' => $theirs->getRouteKey()], tenant: $this->organization))->assertNotFound()` — because `InteractsWithRecord::resolveRecord()` throws `ModelNotFoundException` and Livewire's test harness rethrows everything but `HttpException` and `AuthorizationException`.

24. **There is no container binding and exactly one interface in `app/` today.** `AppServiceProvider::register()` is `//`; `grep` for `->bind(`, `->singleton(`, `->scoped(`, `app()->instance(` across `app/ bootstrap/ config/` returns nothing. `App\Contracts\Invitation` is a *model-shape* contract implemented by two Eloquent models and resolved by `App\Support\Invitations\InvitationLookup`, not by the container. The established substitution idiom in tests is `$this->mock(Concrete::class, fn (MockInterface $mock) => …)`, including a pass-through variant (`tests/Feature/ReviewerRemindersTest.php:237`) and a call-counting variant (`tests/Feature/Organizer/DecisionEmailsTest.php:190`). Task 1 introduces the first real binding and argues why: `dns_get_record()` is a *global function*, not a class, so there is nothing to `mock()` — a concrete `SystemDnsResolver` would have to be mocked by class name in every test, and the one production implementation would then never be type-checked against the thing tests use.

25. **`EditOrganizationProfile` lives under `Pages/Tenancy/`, is a `Filament\Pages\Tenancy\EditTenantProfile`, and has no `save()` of its own.** `app/Filament/Organizer/Pages/Tenancy/EditOrganizationProfile.php` uses Filament v5 schema namespaces (`Filament\Schemas\Schema`, `Filament\Schemas\Components\Section`, `$schema->components([...])`), groups fields in `Section::make('Details')->columns(2)->components([...])` and `Section::make('Branding')`, gates with `public static function canView(Model $tenant): bool` → `$user->roleIn($tenant)?->canManageOrganization() ?? false` (a false answer is **404**, not 403), and puts complex validation in `protected static function contrastRule(): Closure` — **a zero-argument closure that returns the real rule closure**, because Filament evaluates a `Closure` rule as a callback and an unwrapped `function (string $attribute, …)` throws `BindingResolutionException`. Task 2's domain field copies that double-closure shape exactly.

26. **The legacy dump is UTF-8 bytes inside latin1 columns, and must NOT be transcoded.** `Legacy/dbg1vzqja6lgef.sql` is 86,622 bytes, a phpMyAdmin 5.2.2 dump of ten tables, every one `) ENGINE=MyISAM DEFAULT CHARSET=latin1;`, with `/*!40101 SET NAMES utf8mb4 */;` in the header. `file` reports the dump as UTF-8. Greps for `â€™`, `â€œ`, `â€`, `Ã©`, `Ã `, `Â `, `ï»¿` all return **zero**: there is no mojibake. Twelve lines carry non-ASCII and every one is well-formed UTF-8 (`’ “ ” • – ° ±`). **A latin1→utf8 conversion would *create* the corruption that is currently absent.** The reader therefore validates with `mb_check_encoding($value, 'UTF-8')` and reports a row rather than converting it.

27. **The legacy schema, complete.** `conferences(id, name, submission_deadline DATE)` — 2 rows, ids **5** and **6**, both named `Common Pediatric Diseases Symposium` byte-identically, deadlines `2023-12-02` and `2024-11-25`; **no slug, no year, no venue, no status, no description anywhere**. `users(id, email UNIQUE, password, role ENUM('admin','manager','reviewer','multi-role'), conference_id, reset_token, reset_token_expiry, full_name)` — 10 rows, all with real `$2y$10$` bcrypt hashes, all `reset_token` NULL, all `conference_id` NULL, **no `multi-role` row**. `conference_managers(conference_id, manager_id)` — 4 rows. `conference_reviewers(id, conference_id, reviewer_id)` — 7 rows, **no unique on the pair**, both columns nullable. `evaluation_forms(id, conference_id, created_at)` — 2 rows, ids 6 and 7. `evaluation_questions(id, evaluation_form_id, question TEXT, question_type ENUM('likert','textarea'), created_at, likert_scale, order_column)` — 17 rows: form 6 has 8 (ids 50-57, **`order_column` 0 on all of them**), form 7 has 9 (ids 59-67, order 0-8), `likert_scale` = 5 everywhere, **no `scale_min` column at all**. `submissions(id, conference_id, title, affiliation, presenter_names, authors, abstract, attachment, contact_email, contact_phone, submission_date)` — 24 rows (ids 5-17 and 20-30; 1-4, 18, 19 are gaps from direct deletes). `reviews(id, submission_id, reviewer_id, question_id, answer TEXT)` — **599 rows, one per answer, with no review header row at all**: no status, no `submitted_at`, no timestamps. `reviewer_invitations(id, manager_id, email, full_name, token UNIQUE, status ENUM('pending','accepted','expired'), role ENUM('reviewer'), sent_at, expires_at, accepted_at, conference_id)` — 10 rows. `newsletters(id, email, date)` — **0 rows, no `INSERT` statement in the dump**.

28. **The quirks the import must handle, each with its source.** *The affiliation swap is conference-5-only and row-by-row, not uniform* — `legacy-review.md:82` says *"For the 2023 edition the `affiliation` column mostly holds a person's name"*, and the data is worse than that: submission **7**'s affiliation is a research question (`'How teacher's knowledge and attitude towards epilepsy affects school age children'`), submissions **12** and **13** hold the full author list in `affiliation` while `presenter_names` holds one person, and conference 6's eleven rows are clean institutional strings. *Five author separators across 24 rows* — `, `, `\r\n` (47 occurrences), ` & ` (id 23), ` • ` (id 7), `;` — plus journal superscripts inside the names (`'Ibrahim Alharbi,1 Reem Alharthi,corresponding …'`, `'Ali F. Atwah 1,*, …'`, `'Isaq A. AlMughaizel1* , …'`) and the literal word `corresponding` three times. *One title has PDF ligature damage*: submission 10 reads `Discon7nua7on`, `Au7s7c`, `Pa7ent`. *`attachment` is documented as comma-separated (`legacy-review.md:279`) but every one of the 24 rows holds exactly one `uploads/<unixtime>_<rand4>.pdf` path* — the importer still splits on commas, because the review is the contract and one row with two files must not silently lose one. *`submission_date` is the browser's clock* (`:280`). *`reviewer_invitations.status` is written `'Pending'` against a lowercase enum* (`:269`). *Three invitations still hold live plaintext MD5 tokens past expiry* (ids 22, 24, 28). *`users.reset_token` doubles as the manager-invitation token* (`:276`). *Reviews were grouped by reviewer **name** in the legacy UI, so two reviewers with the same name were merged* (`:268`), and *Total Score was an un-normalised `SUM`* (`:267`) — which is why this import stores no score at all and runs `cass:rescore` instead. *Two 2024 reviews are incomplete*: `(sub 22, rev 21)` and `(sub 23, rev 22)` have 8 of 9 answers. *Reviewer 20 (`kfmc.education@gmail.com`) is assigned to conference 6 and wrote nothing.* *`contact_email` collides on case*: submission 9 is `Dr.Alhamoud1990@gmail.com` and submission 10 is `Dr.alhamoud1990@gmail.com`.

29. **`Legacy/CASS.zip` is 13,552,248 bytes, 155 entries, and its `uploads/` directory is flat** — `uploads/<unixtime>_<rand4>.pdf`, no nesting, so `{uploads-dir}` is one directory and every `attachment` value is `basename`-able. It holds **27 PDFs while the database references 24**; the three orphans are `1699176516_2893.pdf`, `1727756783_9881.pdf` and `1727761521_2265.pdf` — the residue of the deleted submission ids. They cannot be imported (`submission_files.submission_id` is NOT NULL) and the import reports them. Referenced files run from 19,253 to 3,105,298 bytes, about 14.4 MB in total — note that `config('cass.max_file_bytes')` is 10 MB and every legacy file is under it, but the import must not route through `StoreSubmissionFile`'s upload validation anyway, because there is no `UploadedFile` here.

30. **The v2 columns the legacy data cannot fill, and what this plan does about each.** `submission_authors.email` is `string('email')` **NOT NULL** and legacy stores no author email anywhere — the import puts the submission's single `contact_email` on the corresponding author and a **synthetic, non-routable** address (`legacy-{submission legacy id}-{n}@import.invalid`, the reserved `.invalid` TLD of RFC 2606) on every other author, flags each one in the manual-review report, and never emails any of them because a legacy conference is imported `archived`. `submissions.reference` is unique per conference and legacy has none — the import mints them through Plan 3's `AllocateReference` in `submission_date` order and back-fills `conferences.submission_counter`. `submissions.access_token_hash` is `char(64)` unique NOT NULL — the import mints a token per submission and **throws the plaintext away**, so no legacy author gets a working `/s/{token}` until somebody uses "Resend status link". `review_questions.scale_min` is nullable and legacy has no minimum — **the import must write `1`**, because `AnswerNormaliser::likert()` returns null when `min` is null (`app/Support/Scoring/AnswerNormaliser.php:63-69`) and every legacy answer would silently score nothing. `reviews` needs a synthesised header row per `(submission_id, reviewer_id)` pair with an invented `submitted_at`. `review_assignments` stays **empty**: legacy is open-pool and `conferences.review_mode` defaults to `open_pool`, so there is nothing to assign.

31. **`conferences.allowed_file_types` and `presentation_types` are `json()` NOT NULL with no default**, so the import must write both. `word_limit` default 500 matches the legacy client-side check; `max_files` is set to **1**, because every legacy row has exactly one file.

32. **The four `cass:` command facts.** `app/Console/Commands` is not auto-discovered: `bootstrap/app.php:14-16` has no `$commandPaths`, so a class is registered only by being named in `->withCommands([...])` (`:23-26`, currently `SendReviewerRemindersCommand` and `RescoreConferenceCommand`). Commands use `protected $signature` / `protected $description` string properties, inject Actions as `handle()` parameters, return `self::SUCCESS` / `self::FAILURE`, and a partial failure still returns `FAILURE` (`SendReviewerRemindersCommand`'s closing block). Both output styles are in use — `$this->components->info()` and plain `$this->info()`. The console test idiom is `artisan('cass:reviewer-reminders')->assertExitCode(1)` with `use function Pest\Laravel\artisan;`.

33. **The activity-log conventions, from 25 call sites.** `activity()->performedOn($x)->causedBy($actor)->log('noun.verb_past');` on one line when short, one method per line when `withProperties()` is present; the description is snake_case, dotted and past tense (`conference.published`, `organization.approved`, `submission.decision_letter_sent`); `withProperties()` takes a flat array of scalars with enums as `->value`; `causedBy($actor)` is always passed explicitly, never inferred from `auth()`. This plan adds `organization.custom_domain_claimed`, `organization.custom_domain_verified`, `organization.custom_domain_released`, `conference.purged`, `organization.purged` and `legacy.imported`.

34. **The Dockerfile pins nothing and there is no Dependabot.** Three floating `FROM` tags — `node:24-alpine AS assets` (`:6`), `composer:2 AS vendor` (`:14`), `php:8.4-fpm-alpine AS runtime` (`:21`) — plus `mysql:8.4` in both compose files and `axllent/mailpit:latest` in the dev one, and twelve `uses:` refs in `.github/workflows/ci.yml` on floating major tags (`actions/checkout@v4` ×3, `shivammathur/setup-php@v2` ×2, `actions/setup-node@v4` ×2, `actions/upload-artifact@v4`, `docker/setup-qemu-action@v3`, `docker/setup-buildx-action@v3`, `docker/build-push-action@v6`). `find .github -type f` returns exactly one path. **The assets (node) stage is FIRST and the vendor (composer) stage is SECOND** — which matters because the Plan 2 backlog's organizer-theme item needs them the other way round, and this plan does **not** reorder them (no theme is built here).

35. **`docker/nginx.conf:59` is the only Content-Security-Policy on any response in this application**, and it is scoped to `location ^~ /storage/`: `add_header Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:" always;`. `app/Http/Controllers/Public/SubmissionFileController.php:61` sets `"default-src 'none'; sandbox"` on a download. `SecurityHeaders.php:17-20` sets four headers and no CSP. `app/Support/Html/RichText.php:27` carries a comment saying *"there is no CSP until Plan 6"*. **Both of the existing CSPs stay exactly as they are**: a stricter policy on a specific response is not weakened by the app-wide one, and Task 7's middleware must not overwrite a header a controller already set.

36. **The `cass-storage` named volume mounts only `/var/www/html/storage/app`** (`docker-compose.production.yml:52`), so `storage/logs`, `storage/framework` and `storage/fonts` live in the container layer and vanish on redeploy. Task 9's manual-review report is therefore written to `storage/app/private/legacy/` — inside the volume — and not to `storage/logs`.

37. **Spec section 10's memory numbers are starting values to be measured.** `docker-compose.production.yml:11-15` limits the app to `768M`/`1.5` CPU and `:71-75` limits MySQL to `512M`/`1.0`; `Dockerfile:31-32` caps php-fpm at `pm.max_children = 4`; `docker/php.ini:1` is `memory_limit=256M` and `:4` `max_execution_time=60`; MySQL runs with `--innodb-buffer-pool-size=256M --performance-schema=OFF` (`:76`). 4 × 256 MiB of php-fpm children is 1 GiB against a 768 MiB limit, which is only safe because a real request uses a fraction of the cap — Task 11 documents the measurement rather than guessing a new number.

38. **`LanguageCoverageTest` has three per-plan cases and their regexes disagree.** Plan 3's alternation is `(?:submission|mail)`, Plan 4's is `(?:members|reviewer|decisions)` and matches only `__(` with single quotes, Plan 5's is `(?:submission|mail|members|reviewer|decisions)`. The hardcoded-English sweep Blade-compiles each view, strips `<?php`/`<script>`/`<style>`, pulls `placeholder|title|alt|aria-label` attribute values, `strip_tags()`es the rest and flags any line with **`str_word_count($line) >= 3`**. Task 12 must lower that threshold for its own case or the sweep goes green with "About", "Contact", "Terms", "Privacy", "Organizer login", "Call for abstracts" and "What to prepare" still in English — every one of them is two words or fewer.

39. **Five Plan 1-2 strings already exist in `lang/en/submission.php` word-for-word**, and Task 12 reuses them rather than duplicating: `submission.window.upcoming` / `.closed` / `.not_configured` match `resources/views/public/partials/submit-cta.blade.php:28`/`:34`/`:40`; `submission.buttons.submit` matches `:14`; `submission.fields.honeypot` matches `contact-form.blade.php:25` and `register-organization.blade.php:70`. Three more match `members.invite.*` (`password_help`, `password_confirmation`, `name`) in `register-organization.blade.php:22`/`:26`/`:9`.

40. **`resources/js/countdown.js` (67 lines) is the only script with user-facing English and `__()` cannot reach it.** Its strings are the tokens `day`/`days`, `hour`/`hours`, `minute`/`minutes` and `left`, built by inline ternaries at `:15` and `:18`, plus a `· ` prefix at `:55`. **There is no "Deadline passed" string** — when `remaining()` returns null the handler calls `badge.remove()` and clears the interval (`:43-53`). The badge is bound by the single `data-countdown` attribute at `submit-cta.blade.php:19`. `resources/js/word-count.js` has no user-facing strings at all.

41. **Only one of the six site images carries `width`/`height`.** `landing.blade.php:15` has `width="1600" height="1051"` on `hero-researcher.png` (158,579 bytes); `landing.blade.php:24`, `:29`, `:34` (the three 40 px SVG icons) and `:44` (`review-lab.png`, 154,884 bytes) and `about.blade.php:10` (`about-lab.png`, 136,589 bytes) have none. All three illustrations are PNG under `public/images/illustrations/` — there is **no `resources/images/`**, so they are served by `asset()` and a WebP has to be a committed file, not a Vite import. `public/images/icons/badge.svg` (4,098 bytes) is referenced by nothing.

42. **There are no Open Graph tags anywhere in the repository** — every `grep "og:"` hit is the substring inside the word "backlog:" in four Filament view comments. `components/layouts/public.blade.php:4-13` has no `<meta name="description">` at all; `components/layouts/conference.blade.php:8` is the only description in the application. Neither layout sets `dir=`, though both already set `lang="{{ str_replace('_', '-', app()->getLocale()) }}"`.

43. **No public page paginates.** `grep` for `->paginate(` and `links()` across `app/Livewire/Public/` and the public views returns nothing, so the backlog's "add the pagination views to the Tailwind `@source` list when the first paginated public page appears" is still waiting for its trigger and Task 14 re-defers it with that sentence.

44. **`resources/css/app.css` has exactly two `@source` roots** — `'../views'` (`:9`) and `'../../app/Livewire'` (`:10`) — and the Dockerfile's `assets` stage copies only `resources` and `public` (`:10-11`), so **`app/Livewire` is not present when the production image builds its CSS**. That is a live bug for any Tailwind class used only inside a Livewire PHP file; Task 11 records it in the backlog rather than reordering the Dockerfile's stages, which is the organizer-theme item's job.

45. **`.dockerignore` excludes `.env.*` with no `!.env.example` re-include** (unlike `.gitignore:9`), excludes `tests`, `Legacy`, `legacy-review.md`, `docs` and `phpunit*.xml`, and does **not** exclude `docker/`. So `docker/backup.sh` is copied into the image by `COPY --chown=app:app . .` (`Dockerfile:35`) whether or not the Dockerfile names it — Task 11 names it anyway, with `chmod`, so its mode is not an accident of the host filesystem.

46. **`App\Actions\Organizations\PurgeOrganization` already exists on `demo-seed`, and Task 4 extends it rather than competing with it.** Read the whole class before Task 4. Its shape: `final class`, `handle(Organization $organization): array` returning `array<string, int>` keyed by table with `'private files'` appended after the disk pass; ids read up front through a private `ids(Builder $query): list<int>`; short links found through a private `shortLinks(Organization, array $conferenceIds): Builder` that covers **both** the conference morph and the organization morph; one `DB::transaction` containing every delete; `Storage::disk('local')->delete()` per path **after** it commits. Two of its choices Plan 6 keeps and argues rather than changes: **`email_logs` rows are deleted with the tenant** (*"leaving them would turn a tenant's whole mail history into unattributed rows in the admin panel rather than removing it"* — all three keys are `nullOnDelete`, so the alternative is not "kept" but "kept and anonymised"), and **`activity_log` is untouched** (*"the audit trail of who deleted what is the last thing a purge should erase"*). The backlog entry that same branch wrote lists what Plan 6 owes it — an admin screen behind a typed-name confirmation, a count-per-table preview *before* the delete, a conference-scoped variant, and a decision about `activity_log` — and Task 4 does all four. `app/Actions/Demo/PurgeDemoOrganization.php` is a thin `is_demo`-gated wrapper over it; Plan 6's admin action deliberately calls the **ungated** class, because a platform-admin purge must not gate on that flag.

---

### Task 1: Baseline, the DNS seam, and the three custom-domain actions

Spec 5.8's second paragraph, minus the user interface, which is Task 2, and minus the routing, which is Task 3. One interface, two implementations, one value object, three actions, one notification and one language file. No screens, no routes and no middleware.

**Four decisions this task makes, with the reasoning, because Tasks 2 and 3 depend on all four.**

**1. `custom_domain_token` holds a plaintext random token, and this is a deliberate, argued exception to "tokens are stored hashed."** `CLAUDE.md` says tokens are stored hashed; spec section 9 says *"All tokens (author access, invitations, domain verification) stored as SHA-256 hashes; plaintext appears only in the emailed link."* Every other token in this application obeys that rule for the same reason: it is a **bearer credential** that arrives *from* a client, so the server only ever needs to recognise it, never to reproduce it. A domain-verification token is the opposite of that in every respect:

- It is **published in public DNS by design**. The whole point is that anybody in the world can read it with `dig`. A secret that is broadcast is not a secret.
- It **authenticates and authorises nothing**. Holding it lets you do exactly one thing: prove you control a zone you already control. It never appears in a request; it is compared against what the resolver returns.
- The organizer must be shown it **again and again** — when they first claim the domain, when they come back three days later because their hosting provider's DNS panel ate the record, and when verification fails and they need to compare what we expect with what they published. A hash cannot be un-hashed, so a hashed token means minting a new one on every page load, which invalidates the record the organizer already published, which makes verification impossible. There is no version of this feature in which the token is hashed and the screen works.

So: 32 random bytes as hex (64 characters, which is exactly what `string('custom_domain_token', 64)` already holds), stored as written, shown as often as asked, rotated whenever the domain changes and cleared when the domain is released. Task 14 records this as a spec deviation in the plan's own words, and the runbook says it too, so nobody later "fixes" it back into a hash and breaks the screen.

**2. Verification checks the TXT record and does *not* check the CNAME.** The screen shows both — the organizer needs the CNAME to make the domain actually resolve to us — but only the TXT record is a *gate*. The reason is `docs/runbooks/deploy-production.md:10`: this platform sits behind Cloudflare with the record proxied (orange cloud). A proxied CNAME is flattened by Cloudflare's authoritative servers into **A records pointing at Cloudflare**, so a public resolver asked for `CNAME abstracts.example.org` gets nothing at all. Requiring the CNAME would refuse precisely the setup the runbook recommends, and would pass only for the unproxied setup that leaks the origin IP. The TXT record is the proof of control; the CNAME is the thing that makes traffic arrive, and the traffic either arrives or it does not — Traefik will tell the organizer that far more clearly than a DNS lookup could.

**3. The resolver is an interface with a container binding — the first in this codebase — and not a concrete class faked with `$this->mock()`.** Fact 24: `AppServiceProvider::register()` is `//`, there are no bindings, and the house idiom for substitution is `$this->mock(Concrete::class, …)`. That idiom does not work here, because the thing being substituted is a **global function**. `dns_get_record()` cannot be mocked; only a class wrapping it can be, and then every test has to know the concrete class's name and stub a method signature nothing type-checks. An interface makes the fake and the real implementation provably the same shape, lets `FakeDnsResolver` live in `app/Support` where Larastan analyses it (fact 1: `tests` is not analysed), and gives Task 2's panel test and Task 1's unit test one line of setup instead of four lines of Mockery. It is also the one dependency in this application that reaches the *network from a synchronous request*, which is the case an interface exists for.

**4. `VerifyCustomDomain` is the only writer of `custom_domain_verified_at`, and `ClaimCustomDomain` is the only writer of `custom_domain` and `custom_domain_token`.** Task 3's routing, Task 3's trusted-host list and Task 2's screen all read those three columns and none of them writes one. `grep` for them in Task 14 Step 8 proves it.

**Files:**
- Create: `app/Contracts/DnsResolver.php`
- Create: `app/Support/Domains/SystemDnsResolver.php`, `app/Support/Domains/FakeDnsResolver.php`, `app/Support/Domains/DomainName.php`, `app/Support/Domains/CustomDomains.php` (the cache key and `forget()` only; Task 3 fills in `verifiedHosts()` and `organizationFor()`)
- Create: `app/Actions/Organizations/ClaimCustomDomain.php`, `app/Actions/Organizations/VerifyCustomDomain.php`, `app/Actions/Organizations/ReleaseCustomDomain.php`
- Create: `app/Exceptions/CustomDomainRefused.php`
- Create: `app/Notifications/CustomDomainVerified.php`
- Create: `lang/en/domain.php`
- Modify: `app/Models/Organization.php`, `app/Providers/AppServiceProvider.php`, `config/cass.php`, `.env.example`
- Test: `tests/Unit/DomainNameTest.php`, `tests/Unit/VerifyCustomDomainTest.php`

- [ ] **Step 1: Branch, record the baseline, and verify every symbol this plan calls**

```bash
cd /c/Users/ahmed/Documents/CASS && git checkout main && git pull --ff-only && \
git checkout -b plan-6-launch && \
php artisan test > /tmp/t-baseline.log 2>&1; echo "baseline rc=$?"; tail -3 /tmp/t-baseline.log && \
./vendor/bin/pint --test > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?" && \
php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar show filament/filament livewire/livewire laravel/framework | grep -E "^(name|versions)"
```

Expected: `baseline rc=0`, `pint rc=0`, `stan rc=0`, filament `v5.8.1`, livewire `v4.4.4`, laravel `v13.31.0`. On `main` at `bee0fbb` the suite is **962 passed, 1 skipped** — but **write the real passing-test count from `tail -3 /tmp/t-baseline.log` into your notes before changing a single line**, because every "Expected" line in this plan is `baseline + N` measured from that number, not from this one. If any of the three is non-zero, stop: `main` is not green and this branch has the wrong parent.

Now record what the demo branch did or did not bring with it, because three later tasks branch on the answer:

```bash
cd /c/Users/ahmed/Documents/CASS && \
echo "--- demo columns and commands ---" && \
ls database/migrations/ | tail -4 && \
grep -rn "is_demo" app/Models/Organization.php database/migrations/ 2>/dev/null | head -5 && \
(php artisan list 2>/dev/null | grep -E "cass:" || echo "(no cass: commands beyond the two)") && \
echo "--- purge: must be empty ---" && \
git log --all --oneline -- app/Actions/Organizations/PurgeOrganization.php app/Actions/Conferences/PurgeConference.php
```

What the output means:

- **A `2026_09_14_*` migration and an `is_demo` column present:** the demo branch merged. Nothing in this plan changes; its own migration is dated `2026_09_15_000100` and sorts after. Note whether `cass:demo-reset` exists — Task 4 Step 1 compares its deletion order with the purge's.
- **Neither present:** the demo branch has not merged. Also fine. Do **not** wait for it and do **not** merge it; this plan branches from `main` and Task 14's pull request rebases if it has to.
- **The `git log` printing anything at all:** somebody has already written a purge action. Read it before writing Task 4, and adjust that task's code to what is there rather than adding a second one.

Then the Plans 1-5 symbol check. Write `/tmp/plan6symbols.sh`:

```bash
#!/usr/bin/env bash
# Every Plans 1-5 name Plan 6 calls. A MISSING line is not a blocker - it means
# read the code on main and adjust this plan's call site to it.
cd /c/Users/ahmed/Documents/CASS
missing=0
check () { # $1 = pattern, $2 = path
  if grep -rqn -- "$1" "$2" 2>/dev/null; then
    printf 'ok      %s\n' "$1"
  else
    printf 'MISSING %-52s (looked in %s)\n' "$1" "$2"; missing=$((missing+1))
  fi
}
# --- custom domains (Tasks 1-3)
check "custom_domain_token"                      database/migrations/2026_09_10_000200_create_organizations_table.php
check "'custom_domain_verified_at' => 'datetime'" app/Models/Organization.php
check 'logOnly(\['                                app/Models/Organization.php
check 'class EditOrganizationProfile'             app/Filament/Organizer/Pages/Tenancy/EditOrganizationProfile.php
check 'protected static function contrastRule'    app/Filament/Organizer/Pages/Tenancy/EditOrganizationProfile.php
check 'public function publicUrl'                 app/Models/Conference.php
check 'trustHosts('                               bootstrap/app.php
check 'public function canManageOrganization'     app/Enums/OrganizationRole.php
check 'class OrganizationTheme'                   app/Support/Branding/OrganizationTheme.php
check 'isPubliclyVisible'                         app/Models/Conference.php
# --- the purge (Task 4)
check 'class DeleteSubmissionFile'                app/Actions/Submissions/DeleteSubmissionFile.php
check 'static::deleting'                          app/Models/ReviewQuestion.php
check 'public static function forTarget'          app/Models/ShortLink.php
check 'class ConferencePolicy'                    app/Policies/ConferencePolicy.php
# --- the admin panel (Tasks 5-6)
check 'class OrganizationResource'                app/Filament/Admin/Resources/Organizations/OrganizationResource.php
check 'public static function canAccess'          app/Filament/Admin/Resources/Conferences/ConferenceResource.php
check 'class EmailLogResource'                    app/Filament/Admin/Resources/EmailLogs/EmailLogResource.php
check 'class SubmissionInfolist'                  app/Filament/Organizer/Resources/Submissions/Schemas/SubmissionInfolist.php
check 'public function answers()'                 app/Models/Review.php
check 'public function decisions()'               app/Models/Submission.php
# --- security (Tasks 7-8)
check 'class SecurityHeaders'                     app/Http/Middleware/SecurityHeaders.php
check 'class TemplatedMail'                       app/Mail/TemplatedMail.php
check 'public string \$body'                      app/Mail/TemplatedMail.php
check 'use MassPrunable'                          app/Models/ShortLinkVisit.php
check "RateLimiter::for\('file-download'"         app/Providers/AppServiceProvider.php
check 'class ClientIp'                            app/Support/ClientIp.php
# --- the legacy import (Tasks 9-10)
check 'class AllocateReference'                   app/Actions/Submissions/AllocateReference.php
check 'class ComputeSubmissionScore'              app/Actions/Submissions/ComputeSubmissionScore.php
check 'public function forConference'             app/Actions/Submissions/ComputeSubmissionScore.php
check 'class SubmissionToken'                     app/Support/Tokens/SubmissionToken.php
check 'class WordCounter'                         app/Support/Text/WordCounter.php
check 'class ReferencePrefix'                     app/Support/Submissions/ReferencePrefix.php
check 'withCommands('                             bootstrap/app.php
# --- language (Task 12)
check 'plan5Sources'                              tests/Feature/LanguageCoverageTest.php
check 'data-countdown'                            resources/views/public/partials/submit-cta.blade.php
check 'class GenerateConferencePoster'            app/Actions/Conferences/GenerateConferencePoster.php
printf '\n%d missing\n' "$missing"
```

```bash
cd /c/Users/ahmed/Documents/CASS && bash /tmp/plan6symbols.sh
```

Expected: `0 missing`.

- [ ] **Step 2: Write the failing tests**

`tests/Unit/DomainNameTest.php`
```php
<?php

declare(strict_types=1);

use App\Support\Domains\DomainName;

// No RefreshDatabase: this class touches nothing but a string and config().

beforeEach(function () {
    config()->set('app.url', 'https://cass.towardpcc.com');
});

it('normalises a hostname the way a resolver would', function (string $input, string $expected) {
    expect(DomainName::normalise($input))->toBe($expected);
})->with([
    // Case, whitespace and the root label's trailing dot are all noise: DNS is
    // case-insensitive and `example.org.` and `example.org` are the same name.
    ['Abstracts.Example.ORG', 'abstracts.example.org'],
    ['  abstracts.example.org  ', 'abstracts.example.org'],
    ['abstracts.example.org.', 'abstracts.example.org'],
    // An organizer who pastes a URL instead of a hostname is the single most
    // likely input on this field, and refusing it teaches nothing.
    ['https://abstracts.example.org/', 'abstracts.example.org'],
    ['http://abstracts.example.org/submit?x=1', 'abstracts.example.org'],
    // intl is installed (php -m), so an Arabic or accented domain becomes the
    // A-label the resolver and the certificate will actually use.
    ['müller.example.org', 'xn--mller-kva.example.org'],
]);

it('refuses a hostname that cannot be a custom domain', function (string $input, string $reason) {
    expect(DomainName::problem($input))->toBe($reason);
})->with([
    ['', 'domain.errors.required'],
    ['localhost', 'domain.errors.not_a_domain'],
    // An IP literal has no zone to put a TXT record in.
    ['203.0.113.7', 'domain.errors.not_a_domain'],
    ['abstracts..example.org', 'domain.errors.not_a_domain'],
    ['-abstracts.example.org', 'domain.errors.not_a_domain'],
    ['abstracts.example.org/submit', 'domain.errors.not_a_domain'],
    [str_repeat('a', 64).'.example.org', 'domain.errors.label_too_long'],
    [str_repeat('a.', 130).'org', 'domain.errors.too_long'],
    // The platform's own host, and anything under it. Claiming
    // cass.towardpcc.com would point the trusted-host list and the routing
    // middleware at the platform itself; claiming a subdomain of it would let
    // an organizer serve pages from a name that looks like ours.
    ['cass.towardpcc.com', 'domain.errors.platform_host'],
    ['CASS.TOWARDPCC.COM', 'domain.errors.platform_host'],
    ['org.cass.towardpcc.com', 'domain.errors.platform_host'],
]);

it('accepts a plain second-level and a deep subdomain', function () {
    expect(DomainName::problem('example.org'))->toBeNull()
        ->and(DomainName::problem('abstracts.cpds.example.org'))->toBeNull()
        ->and(DomainName::problem('abstracts.example.co.uk'))->toBeNull();
});

it('builds the two records an organizer has to publish', function () {
    config()->set('cass.domains.cname_target', 'cass.towardpcc.com');

    expect(DomainName::txtRecordName('abstracts.example.org'))->toBe('_cass-verify.abstracts.example.org')
        ->and(DomainName::cnameTarget())->toBe('cass.towardpcc.com');
});
```

`tests/Unit/VerifyCustomDomainTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Organizations\ClaimCustomDomain;
use App\Actions\Organizations\ReleaseCustomDomain;
use App\Actions\Organizations\VerifyCustomDomain;
use App\Contracts\DnsResolver;
use App\Exceptions\CustomDomainRefused;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CustomDomainVerified;
use App\Support\Domains\FakeDnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.url', 'https://cass.towardpcc.com');
    config()->set('cass.domains.cname_target', 'cass.towardpcc.com');

    Notification::fake();

    $this->dns = new FakeDnsResolver;
    // The first container binding in this application (Task 1 decision 3).
    // A concrete class could not be substituted at all: dns_get_record() is a
    // global function, not a method.
    app()->instance(DnsResolver::class, $this->dns);

    $this->organization = Organization::factory()->approved()->create();
    $this->actor = User::factory()->create();
});

it('claims a domain, mints a token and leaves it unverified', function () {
    $organization = app(ClaimCustomDomain::class)->handle($this->organization, ' Abstracts.Example.ORG ', $this->actor);

    expect($organization->custom_domain)->toBe('abstracts.example.org')
        // 32 random bytes as hex. The column is string(64) and this is exactly
        // 64 characters - see the migration, which sized it for a hash.
        ->and($organization->custom_domain_token)->toHaveLength(64)
        ->and($organization->custom_domain_token)->toMatch('/^[0-9a-f]{64}$/')
        ->and($organization->custom_domain_verified_at)->toBeNull()
        ->and($organization->hasVerifiedCustomDomain())->toBeFalse();

    expect(Activity::query()->where('description', 'organization.custom_domain_claimed')->exists())->toBeTrue();
});

it('refuses a domain another organization already claimed', function () {
    $other = withoutTenant(fn (): Organization => Organization::factory()->approved()->create());
    app(ClaimCustomDomain::class)->handle($other, 'abstracts.example.org', $this->actor);

    // The column is UNIQUE, so the alternative to this check is a
    // QueryException in an organizer's face. Answer the same way whether the
    // other claim is verified or not: an unverified claim is somebody
    // mid-setup, and stealing the name from under them is worse than asking
    // them to finish.
    expect(fn () => app(ClaimCustomDomain::class)->handle($this->organization, 'Abstracts.Example.org', $this->actor))
        ->toThrow(CustomDomainRefused::class);
});

it('lets an organization re-claim the domain it already holds, and rotates the token', function () {
    $first = app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);
    $token = (string) $first->custom_domain_token;

    $second = app(ClaimCustomDomain::class)->handle($this->organization->fresh(), 'abstracts.example.org', $this->actor);

    // Same domain, new token: the only reason to press "claim" again is that
    // the record was lost, and handing back the same string would leave an
    // organizer staring at a value they already published.
    expect($second->custom_domain)->toBe('abstracts.example.org')
        ->and($second->custom_domain_token)->not->toBe($token);
});

it('un-verifies when the domain changes', function () {
    app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);
    $this->dns->set('_cass-verify.abstracts.example.org', [$this->organization->fresh()?->custom_domain_token]);
    app(VerifyCustomDomain::class)->handle($this->organization->fresh(), $this->actor);

    expect($this->organization->fresh()?->hasVerifiedCustomDomain())->toBeTrue();

    app(ClaimCustomDomain::class)->handle($this->organization->fresh(), 'submit.example.org', $this->actor);

    // A verified flag that survives a domain change would leave the trusted
    // host list and the routing middleware answering for a name this
    // organization no longer claims.
    expect($this->organization->fresh()?->custom_domain)->toBe('submit.example.org')
        ->and($this->organization->fresh()?->custom_domain_verified_at)->toBeNull();
});

it('verifies when the txt record carries the token', function () {
    // A real platform admin, because Notification::assertSentTo() throws
    // "No notifiable given." on an empty collection (NotificationFake:68-72)
    // and neither UserFactory::definition() nor OrganizationFactory::approved()
    // creates one.
    $admin = User::factory()->platformAdmin()->create();

    $organization = app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);

    // Real zones carry other TXT records at the same name; the token has to be
    // found among them, not be the only one.
    $this->dns->set('_cass-verify.abstracts.example.org', [
        'v=spf1 include:example.net ~all',
        (string) $organization->custom_domain_token,
    ]);

    $verified = app(VerifyCustomDomain::class)->handle($organization, $this->actor);

    expect($verified->custom_domain_verified_at)->not->toBeNull()
        ->and($verified->hasVerifiedCustomDomain())->toBeTrue()
        ->and($this->dns->queriedNames())->toBe(['_cass-verify.abstracts.example.org']);

    Notification::assertSentTo($admin, CustomDomainVerified::class);

    expect(Activity::query()->where('description', 'organization.custom_domain_verified')->exists())->toBeTrue();
});

it('tolerates a provider that wraps the value in quotes and pads it', function () {
    $organization = app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);
    $this->dns->set('_cass-verify.abstracts.example.org', ['  "'.$organization->custom_domain_token.'"  ']);

    // Several DNS panels store the quotes an operator typed. Refusing a record
    // that is correct apart from its punctuation produces a support request
    // nobody can diagnose from a screenshot.
    expect(app(VerifyCustomDomain::class)->handle($organization, $this->actor)->hasVerifiedCustomDomain())->toBeTrue();
});

it('refuses when the record is missing, wrong, or the zone does not resolve', function (array $records, string $reason) {
    $organization = app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);
    $this->dns->set('_cass-verify.abstracts.example.org', $records);

    expect(fn () => app(VerifyCustomDomain::class)->handle($organization, $this->actor))
        ->toThrow(CustomDomainRefused::class, __($reason));

    expect($organization->fresh()?->custom_domain_verified_at)->toBeNull();
    Notification::assertNothingSent();
})->with([
    'no records at all' => [[], 'domain.errors.no_record'],
    'the wrong token' => [[str_repeat('b', 64)], 'domain.errors.token_mismatch'],
    'somebody else\'s spf' => [['v=spf1 -all'], 'domain.errors.token_mismatch'],
]);

it('reports a resolver failure as a failure and not as a mismatch', function () {
    $organization = app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);
    $this->dns->fail('_cass-verify.abstracts.example.org');

    // SERVFAIL and "no such record" are different answers and an organizer can
    // act on only one of them. Collapsing both into "wrong token" sends them
    // to re-type a record that is already correct.
    expect(fn () => app(VerifyCustomDomain::class)->handle($organization, $this->actor))
        ->toThrow(CustomDomainRefused::class, __('domain.errors.lookup_failed'));
});

it('refuses to verify an organization with no domain claimed', function () {
    expect(fn () => app(VerifyCustomDomain::class)->handle($this->organization, $this->actor))
        ->toThrow(CustomDomainRefused::class, __('domain.errors.none_claimed'));
});

it('releases a domain and clears all three columns', function () {
    $organization = app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);
    $this->dns->set('_cass-verify.abstracts.example.org', [(string) $organization->custom_domain_token]);
    app(VerifyCustomDomain::class)->handle($organization, $this->actor);

    $released = app(ReleaseCustomDomain::class)->handle($organization->fresh(), $this->actor);

    expect($released->custom_domain)->toBeNull()
        ->and($released->custom_domain_token)->toBeNull()
        ->and($released->custom_domain_verified_at)->toBeNull();

    // The name has to become claimable again, immediately, by anybody -
    // including the organization that just gave it up.
    $other = withoutTenant(fn (): Organization => Organization::factory()->approved()->create());
    expect(app(ClaimCustomDomain::class)->handle($other, 'abstracts.example.org', $this->actor)->custom_domain)
        ->toBe('abstracts.example.org');

    expect(Activity::query()->where('description', 'organization.custom_domain_released')->exists())->toBeTrue();
});
```

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/DomainNameTest.php tests/Unit/VerifyCustomDomainTest.php > /tmp/t-task-1.log 2>&1; echo "rc=$?"; tail -20 /tmp/t-task-1.log
```

Expected: `rc=1`, with errors naming `App\Support\Domains\DomainName` and `App\Contracts\DnsResolver`. **This is the failing-first gate: do not continue until you have seen it fail for that reason.**

- [ ] **Step 3: The value object and the resolver seam**

`app/Support/Domains/DomainName.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Domains;

/**
 * Everything this application knows about turning what an organizer typed into
 * a hostname a resolver would recognise, and about refusing the ones it must
 * not accept.
 *
 * Static, like App\Support\ShortCode and App\Support\Turnstile: there is no
 * state, the answer depends only on the argument and on config, and a value
 * object with one string property would add a constructor to every call site
 * for nothing.
 */
final class DomainName
{
    /** RFC 1035: 253 octets for the whole name, 63 for one label. */
    public const MAX_LENGTH = 253;

    public const MAX_LABEL_LENGTH = 63;

    /** The name the organizer publishes the token at. */
    public const TXT_PREFIX = '_cass-verify';

    /**
     * Lower-case, trimmed, without a trailing root dot, without a scheme or a
     * path, and IDN-encoded to its A-label. Never throws: an input this cannot
     * make sense of comes back as best it can and problem() refuses it.
     */
    public static function normalise(string $input): string
    {
        $value = trim($input);

        // An organizer pasting a URL is the most likely wrong input on this
        // field, and parse_url() is the cheapest way to be kind about it.
        if (str_contains($value, '://')) {
            $value = (string) (parse_url($value, PHP_URL_HOST) ?? '');
        }

        $value = rtrim(strtolower(trim($value)), '.');

        // intl is installed everywhere this runs (php -m, Dockerfile:25,
        // ci.yml:44). The A-label is what a resolver is asked for and what
        // Traefik puts on the certificate, so it is what is stored.
        if ($value !== '' && ! mb_check_encoding($value, 'ASCII')) {
            $ascii = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (is_string($ascii) && $ascii !== '') {
                $value = $ascii;
            }
        }

        return $value;
    }

    /**
     * The translation key of the reason this hostname cannot be a custom
     * domain, or null when it can. A key rather than a sentence so the caller
     * decides whether it is a validation message, an exception or a log line.
     */
    public static function problem(string $input): ?string
    {
        $value = self::normalise($input);

        if ($value === '') {
            return 'domain.errors.required';
        }

        if (strlen($value) > self::MAX_LENGTH) {
            return 'domain.errors.too_long';
        }

        $labels = explode('.', $value);

        // A single label is `localhost` or a machine name: there is no zone to
        // put a TXT record in and no certificate authority will issue for it.
        if (count($labels) < 2) {
            return 'domain.errors.not_a_domain';
        }

        // An IP literal parses as four labels of digits and would otherwise
        // pass the label rules below.
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return 'domain.errors.not_a_domain';
        }

        foreach ($labels as $label) {
            if (strlen($label) > self::MAX_LABEL_LENGTH) {
                return 'domain.errors.label_too_long';
            }

            // Letters, digits and inner hyphens. An empty label catches the
            // `a..b` case; a leading or trailing hyphen is invalid in a
            // hostname and is what an organizer produces by typing a dash
            // where they meant a dot.
            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                return 'domain.errors.not_a_domain';
            }
        }

        // The last label cannot be all digits: that is the other shape an IP
        // address takes, and it is not a TLD.
        if (preg_match('/^[0-9]+$/', $labels[count($labels) - 1]) === 1) {
            return 'domain.errors.not_a_domain';
        }

        $platform = self::platformHost();

        if ($platform !== '' && ($value === $platform || str_ends_with($value, '.'.$platform))) {
            return 'domain.errors.platform_host';
        }

        return null;
    }

    public static function platformHost(): string
    {
        return strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: ''));
    }

    public static function txtRecordName(string $domain): string
    {
        return self::TXT_PREFIX.'.'.self::normalise($domain);
    }

    /**
     * What the organizer points their CNAME at. Configurable because a staging
     * deployment is not cass.towardpcc.com, and because the runbook's Coolify
     * step names the same value.
     */
    public static function cnameTarget(): string
    {
        $configured = (string) config('cass.domains.cname_target');

        return $configured !== '' ? strtolower($configured) : self::platformHost();
    }
}
```

`app/Contracts/DnsResolver.php`
```php
<?php

declare(strict_types=1);

namespace App\Contracts;

use RuntimeException;

/**
 * One question, asked once per organizer click: what TXT records exist at this
 * name?
 *
 * An interface rather than a concrete class because the thing behind it is a
 * *global function* (dns_get_record) and there is nothing to substitute in a
 * test otherwise - see Task 1's decision 3. It is deliberately narrow: this
 * application never needs A, CNAME, MX or anything else (Task 1 decision 2
 * argues why the CNAME is not checked), and an interface that grows a method
 * per record type is an interface nobody can fake in one line.
 */
interface DnsResolver
{
    /**
     * Every TXT string published at $name, in whatever order the resolver
     * returned them. An empty array means the name resolved and has no TXT
     * records; that is a different answer from a failure, which throws.
     *
     * @return list<string>
     *
     * @throws RuntimeException when the lookup itself failed - SERVFAIL, a
     *                          timeout, no reachable resolver. The caller has
     *                          to tell an organizer "we could not ask" rather
     *                          than "your record is wrong".
     */
    public function txtRecords(string $name): array;
}
```

`app/Support/Domains/SystemDnsResolver.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Domains;

use App\Contracts\DnsResolver;
use RuntimeException;

/**
 * dns_get_record() over the container's resolver. Nothing caches: verification
 * happens when a human clicks a button, and a cached negative answer is the
 * one thing guaranteed to waste that human's afternoon.
 */
final class SystemDnsResolver implements DnsResolver
{
    public function txtRecords(string $name): array
    {
        // The @ is load-bearing and is the only one in this application.
        // dns_get_record() emits a PHP warning on SERVFAIL and on a timeout
        // *and* returns false, so without it a DNS outage becomes an
        // ErrorException in an organizer's face instead of the sentence
        // VerifyCustomDomain is about to write. The false is still checked.
        $records = @dns_get_record($name, DNS_TXT);

        if ($records === false) {
            throw new RuntimeException("DNS lookup failed for [{$name}].");
        }

        $values = [];

        foreach ($records as $record) {
            // PHP gives a TXT record two shapes: `txt` is every string in the
            // record joined, `entries` is the list. A value longer than 255
            // octets arrives as several entries and only `txt` has the whole
            // thing - a 64-character token never splits, but reading both
            // costs nothing and makes this correct for a longer value later.
            if (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = $record['txt'];
            }

            foreach ((array) ($record['entries'] ?? []) as $entry) {
                if (is_string($entry)) {
                    $values[] = $entry;
                }
            }
        }

        return array_values(array_unique($values));
    }
}
```

`app/Support/Domains/FakeDnsResolver.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Domains;

use App\Contracts\DnsResolver;
use RuntimeException;

/**
 * The test double, in app/ rather than tests/ so Larastan analyses it
 * (phpstan.neon lists app, config, database, routes - not tests) and so the
 * two implementations are provably the same shape.
 *
 * It is never bound in production: AppServiceProvider binds SystemDnsResolver,
 * and a test replaces the instance.
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    private array $records = [];

    /** @var list<string> */
    private array $failing = [];

    /** @var list<string> */
    private array $queried = [];

    /** @param list<string> $values */
    public function set(string $name, array $values): self
    {
        $this->records[strtolower($name)] = array_values($values);

        return $this;
    }

    public function fail(string $name): self
    {
        $this->failing[] = strtolower($name);

        return $this;
    }

    public function txtRecords(string $name): array
    {
        $key = strtolower($name);
        $this->queried[] = $key;

        if (in_array($key, $this->failing, true)) {
            throw new RuntimeException("DNS lookup failed for [{$name}].");
        }

        return $this->records[$key] ?? [];
    }

    /**
     * Every name this resolver was asked about, in order. The tests assert on
     * it so that a second lookup added later - a CNAME check, a retry loop -
     * is a visible change rather than a silent one.
     *
     * @return list<string>
     */
    public function queriedNames(): array
    {
        return $this->queried;
    }
}
```

`app/Providers/AppServiceProvider.php` — `register()` is `//` today (fact 24). Replace it:

```php
    public function register(): void
    {
        // The first container binding in this application, and the reason is
        // narrow: DnsResolver wraps dns_get_record(), a global function, which
        // no test can substitute any other way. Everything else in app/ is
        // resolved by autowiring and faked with $this->mock().
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
    }
```

with `use App\Contracts\DnsResolver;` and `use App\Support\Domains\SystemDnsResolver;` added to the file's imports, alphabetically (Pint's `ordered_imports`).

- [ ] **Step 4: `config/cass.php` and `.env.example`**

Append a `domains` block to `config/cass.php`, after the `decisions` block (`:100-124`) and before `countries` (`:126`):

```php
    /*
     * Custom domains (spec 5.8). The three columns have existed since Plan 1;
     * Plan 6 is what reads them.
     */
    'domains' => [
        // What an organizer points their CNAME at. Defaults to APP_URL's host
        // so a staging deployment is correct without a second variable, and is
        // settable because the runbook's Coolify step names the same value.
        'cname_target' => env('CASS_DOMAIN_CNAME_TARGET'),
        // How long the verified-host list is cached. Every request through
        // TrustHosts reads it, so it is not read from the database every time;
        // 60 seconds is short enough that a newly verified domain works before
        // the organizer has finished reading the confirmation.
        'cache_seconds' => (int) env('CASS_DOMAIN_CACHE_SECONDS', 60),
        // Verification is one outbound DNS lookup per click, from the php-fpm
        // worker that is also serving public pages. Metered per actor.
        'verify_rate_limit' => (int) env('CASS_DOMAIN_VERIFY_LIMIT', 10),
    ],
```

`.env.example`, appended after Plan 5's decisions block:

```bash
# --- Custom domains (Plan 6) ---
# The CNAME target an organizer publishes. Empty means "APP_URL's host", which
# is right for production and for staging alike; set it only when the public
# name and the name Traefik answers on differ.
CASS_DOMAIN_CNAME_TARGET=
# Seconds the verified-domain list is cached. Every request consults it through
# the trusted-hosts closure, so this is a read-per-request that must not be a
# query-per-request.
CASS_DOMAIN_CACHE_SECONDS=60
# "Verify" clicks per organizer per minute. Each one is a synchronous DNS
# lookup on a php-fpm worker.
CASS_DOMAIN_VERIFY_LIMIT=10
```

- [ ] **Step 5: `lang/en/domain.php`**

```php
<?php

declare(strict_types=1);

/*
 * The custom-domain section of the organization profile, its three actions and
 * every sentence they can produce (spec 5.8, spec section 10).
 *
 * Spec section 10: Arabic is a copy of this file, not a branch in a Blade view.
 * Keys are grouped in the order an organizer meets them - section, fields,
 * records, actions, notices, errors - and no group is defined twice.
 */

return [
    'section' => [
        'heading' => 'Custom domain',
        'description' => 'Serve your conference pages from your own address, such as abstracts.example.org, instead of :platform.',
    ],

    'fields' => [
        'domain' => 'Your domain',
        'domain_help' => 'A hostname you control, without https:// — for example abstracts.example.org. Do not use a domain that is already serving a website: every address on it will point here.',
        'status' => 'Status',
    ],

    'state' => [
        'none' => 'No custom domain',
        'pending' => 'Waiting for DNS',
        'verified' => 'Verified',
    ],

    'records' => [
        'heading' => 'Publish these two records',
        'intro' => 'Add both records in the DNS panel for :domain, then come back and press Verify. DNS changes can take a few minutes to appear.',
        'txt_name' => 'TXT record name',
        'txt_value' => 'TXT record value',
        'cname_name' => 'CNAME record name',
        'cname_value' => 'CNAME points to',
        'cname_note' => 'The CNAME is what sends visitors here. We do not check it — if it is wrong, the address simply will not open.',
        'after' => 'Leave the TXT record in place. It is how we re-check the domain if you ever need to.',
    ],

    'actions' => [
        'claim' => 'Save domain',
        'verify' => 'Verify',
        'release' => 'Remove domain',
        'release_confirm_heading' => 'Remove :domain?',
        'release_confirm_body' => 'Your conference pages will go back to :platform addresses immediately. Anyone following a link to :domain will get an error until you point it somewhere else.',
    ],

    'notices' => [
        'claimed' => 'Saved. Publish the two records below, then press Verify.',
        'verified_title' => 'Verified',
        'verified_body' => ':domain is verified. Your conference pages are live there as soon as the platform team finishes the certificate — we have emailed them.',
        'released' => 'Removed. :domain no longer serves your pages.',
        'pending_admin' => 'Verified. The platform team has been emailed to finish the certificate; this usually takes less than a day.',
    ],

    'mail' => [
        'admin_subject' => 'Custom domain verified: :domain',
        'admin_line_one' => ':organization verified **:domain**.',
        'admin_line_two' => 'Add the host to the Coolify resource so Traefik issues the certificate. The runbook section "Custom domain for an organization" has the exact call.',
        'admin_action' => 'Open the organization',
    ],

    'errors' => [
        'required' => 'Enter the domain you want to use.',
        'not_a_domain' => 'That is not a domain name. Enter a hostname such as abstracts.example.org.',
        'too_long' => 'That domain is too long.',
        'label_too_long' => 'One part of that domain is longer than 63 characters.',
        'platform_host' => 'That address belongs to the platform. Use a domain you control.',
        'taken' => 'Another organization has already claimed that domain. If it is yours, write to :contact.',
        'none_claimed' => 'Save a domain before verifying it.',
        'no_record' => 'We could not find a TXT record at :name. Add it in your DNS panel and try again — changes can take a few minutes.',
        'token_mismatch' => 'We found a TXT record at :name, but not the value shown below. Check that you copied the whole value.',
        'lookup_failed' => 'We could not reach the DNS servers for that domain just now. Try again in a few minutes.',
        'already_verified' => 'That domain is already verified.',
        'not_approved' => 'Your organization has to be approved before you can use a custom domain.',
    ],
];
```

- [ ] **Step 6: The exception, the notification, and the four methods on `Organization`**

`app/Exceptions/CustomDomainRefused.php`
```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * One sentence an organizer can act on, in the shape ReviewNotAcceptable uses:
 * a named constructor taking an already-translated string, so every call site
 * reads __('domain.errors.…') and nothing assembles English.
 */
final class CustomDomainRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
```

`app/Notifications/CustomDomainVerified.php`
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Spec 5.8: "the platform admin is notified to add the host to the Coolify
 * resource so Traefik issues the certificate (documented runbook)."
 *
 * Queued like every other notification in this application (fact 20). Sent to
 * every is_platform_admin user, the shape RegisterOrganization already uses.
 */
class CustomDomainVerified extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Organization $organization, public string $domain) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('domain.mail.admin_subject', ['domain' => $this->domain]))
            ->line(__('domain.mail.admin_line_one', [
                'organization' => $this->organization->name,
                'domain' => $this->domain,
            ]))
            ->line(__('domain.mail.admin_line_two'))
            ->action(__('domain.mail.admin_action'), OrganizationResource::getUrl(
                'view', ['record' => $this->organization], panel: 'admin'
            ));
    }
}
```

`app/Models/Organization.php` — append four methods after `approver()` (`:105-108`). Nothing else on the model changes: the cast (`:41`) and the `logOnly` list (`:134`) are already right, and none of the three columns becomes fillable.

```php
    /**
     * A domain is only a *routing* fact once it is verified. Every reader in
     * this application asks this question and not `custom_domain !== null`,
     * because a claimed-but-unverified domain is somebody halfway through a
     * DNS panel and must serve nothing.
     */
    public function hasVerifiedCustomDomain(): bool
    {
        return $this->custom_domain !== null && $this->custom_domain_verified_at !== null;
    }

    /** The host this organization's public pages are served from, or null for the platform's own. */
    public function customDomainHost(): ?string
    {
        return $this->hasVerifiedCustomDomain() ? (string) $this->custom_domain : null;
    }

    /** The full name the TXT record lives at, or null when no domain is claimed. */
    public function customDomainTxtName(): ?string
    {
        return $this->custom_domain === null ? null : DomainName::txtRecordName((string) $this->custom_domain);
    }

    /**
     * https://{domain} with no trailing slash. Plain concatenation rather than
     * url(): url() builds from APP_URL, which is the one host this method
     * exists to avoid.
     */
    public function customDomainUrl(): ?string
    {
        $host = $this->customDomainHost();

        return $host === null ? null : 'https://'.$host;
    }
```

with `use App\Support\Domains\DomainName;` added to the imports.

- [ ] **Step 7: The three actions**

`app/Actions/Organizations/ClaimCustomDomain.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Exceptions\CustomDomainRefused;
use App\Models\Organization;
use App\Models\User;
use App\Support\Domains\DomainName;
use Illuminate\Support\Facades\DB;

/**
 * THE writer of organizations.custom_domain and organizations.custom_domain_token.
 *
 * The token is stored as plaintext, deliberately, and Task 1's decision 1
 * argues it at length: it is published in public DNS, it authorises nothing,
 * and the screen has to be able to show it again tomorrow. Hashing it would
 * make the feature impossible rather than safer.
 */
class ClaimCustomDomain
{
    public function handle(Organization $organization, string $input, User $actor): Organization
    {
        $domain = DomainName::normalise($input);
        $problem = DomainName::problem($input);

        if ($problem !== null) {
            throw CustomDomainRefused::because(__($problem));
        }

        // An organization the platform has not approved (or has suspended) must
        // not be able to put a host into the trusted-host list or to mail every
        // platform admin by pressing Verify. Its pages 404 anyway (approval is
        // only checked at render today); this keeps the routing surface and the
        // notification out of its reach too.
        if (! $organization->isApproved()) {
            throw CustomDomainRefused::because(__('domain.errors.not_approved'));
        }

        // The column is UNIQUE (Plan 1's migration), so the alternative to
        // this read is a QueryException with a MySQL error string in it.
        // withTrashed(): a soft-deleted organization still holds the row and
        // still holds the unique index.
        $taken = Organization::query()
            ->withTrashed()
            ->where('custom_domain', $domain)
            ->whereKeyNot($organization->getKey())
            ->exists();

        if ($taken) {
            throw CustomDomainRefused::because(__('domain.errors.taken', [
                'contact' => (string) config('cass.platform_contact_email'),
            ]));
        }

        return DB::transaction(function () use ($organization, $domain, $actor): Organization {
            $previous = $organization->custom_domain;

            $organization->forceFill([
                'custom_domain' => $domain,
                // 32 bytes of randomness as hex is 64 characters, which is
                // exactly what string('custom_domain_token', 64) holds. Minted
                // on every claim, including a re-claim of the same domain:
                // the only reason to press the button twice is that the record
                // was lost, and handing back the old value helps nobody.
                'custom_domain_token' => bin2hex(random_bytes(32)),
                // A changed domain is an unverified domain. Leaving the
                // timestamp would leave the trusted-host list and the routing
                // middleware answering for a name this organization no longer
                // claims.
                'custom_domain_verified_at' => null,
            ])->save();

            activity()
                ->performedOn($organization)
                ->causedBy($actor)
                ->withProperties(['domain' => $domain, 'previous' => $previous])
                ->log('organization.custom_domain_claimed');

            return $organization->refresh();
        });
    }
}
```

`app/Actions/Organizations/VerifyCustomDomain.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Contracts\DnsResolver;
use App\Exceptions\CustomDomainRefused;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CustomDomainVerified;
use App\Support\Domains\CustomDomains;
use App\Support\Domains\DomainName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Spec 5.8. THE only writer of organizations.custom_domain_verified_at.
 *
 * One TXT lookup, one comparison, one timestamp, one email to the platform
 * admin and one activity entry. It does not check the CNAME - Task 1's
 * decision 2 explains why a proxied CNAME cannot be checked from outside - and
 * it does not talk to Coolify: the admin does that by hand, from the runbook.
 */
class VerifyCustomDomain
{
    public function __construct(private readonly DnsResolver $dns) {}

    public function handle(Organization $organization, User $actor): Organization
    {
        // Same guard as ClaimCustomDomain, and for the same reason: every
        // verify click emails every is_platform_admin user and adds a host to
        // the trusted-host list. An organization the platform has declined or
        // suspended reaches neither.
        if (! $organization->isApproved()) {
            throw CustomDomainRefused::because(__('domain.errors.not_approved'));
        }

        $domain = $organization->custom_domain;
        $token = $organization->custom_domain_token;

        if ($domain === null || $token === null || $token === '') {
            throw CustomDomainRefused::because(__('domain.errors.none_claimed'));
        }

        $name = DomainName::txtRecordName($domain);

        try {
            $records = $this->dns->txtRecords($name);
        } catch (RuntimeException $exception) {
            // "We could not ask" and "your record is wrong" are different
            // answers and an organizer can only act on one of them. report()
            // so a persistent resolver failure is visible to the platform,
            // because ten organizers seeing this is an outage, not ten typos.
            report($exception);

            throw CustomDomainRefused::because(__('domain.errors.lookup_failed'));
        }

        if ($records === []) {
            throw CustomDomainRefused::because(__('domain.errors.no_record', ['name' => $name]));
        }

        if (! $this->carriesToken($records, $token)) {
            throw CustomDomainRefused::because(__('domain.errors.token_mismatch', ['name' => $name]));
        }

        return DB::transaction(function () use ($organization, $domain, $actor): Organization {
            $organization->forceFill(['custom_domain_verified_at' => now()])->save();

            activity()
                ->performedOn($organization)
                ->causedBy($actor)
                ->withProperties(['domain' => $domain])
                ->log('organization.custom_domain_verified');

            // The trusted-host list and the routing middleware both read a
            // cached list (Task 3). Forgetting it here is what makes the
            // domain work in the same second it is verified rather than up to
            // CASS_DOMAIN_CACHE_SECONDS later.
            CustomDomains::forget();

            Notification::send(
                User::query()->where('is_platform_admin', true)->get(),
                new CustomDomainVerified($organization, $domain),
            );

            return $organization->refresh();
        });
    }

    /**
     * Several DNS panels store the quotation marks an operator typed, and some
     * pad the value. A record that is correct apart from its punctuation is a
     * support request nobody can diagnose from a screenshot, so trim both.
     *
     * hash_equals() rather than ===: the comparison is against a value an
     * outsider can choose, and a constant-time compare costs nothing here.
     *
     * @param  list<string>  $records
     */
    private function carriesToken(array $records, string $token): bool
    {
        foreach ($records as $record) {
            if (hash_equals($token, trim(trim($record), '"\''))) {
                return true;
            }
        }

        return false;
    }
}
```

`app/Actions/Organizations/ReleaseCustomDomain.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use App\Support\Domains\CustomDomains;
use Illuminate\Support\Facades\DB;

/**
 * All three columns back to null, so the name is immediately claimable by
 * anybody - including by the organization that just gave it up, which is what
 * a typo in the domain field looks like from the outside.
 *
 * Nothing tells Coolify. The host stays on the resource and Traefik keeps a
 * certificate for it until an admin removes it; that is a cleanup step in the
 * runbook, not an application concern, and leaving it is harmless: the
 * middleware answers 404 for a host with no verified organization behind it.
 */
class ReleaseCustomDomain
{
    public function handle(Organization $organization, User $actor): Organization
    {
        $previous = $organization->custom_domain;

        return DB::transaction(function () use ($organization, $previous, $actor): Organization {
            $organization->forceFill([
                'custom_domain' => null,
                'custom_domain_token' => null,
                'custom_domain_verified_at' => null,
            ])->save();

            activity()
                ->performedOn($organization)
                ->causedBy($actor)
                ->withProperties(['domain' => $previous])
                ->log('organization.custom_domain_released');

            CustomDomains::forget();

            return $organization->refresh();
        });
    }
}
```

**`CustomDomains::forget()` does not exist yet** — it arrives with the rest of `App\Support\Domains\CustomDomains` in Task 3. Write the minimal version now, in Task 3's file, so this task compiles; Task 3 fills in `verifiedHosts()` and `organizationFor()` around it:

`app/Support/Domains/CustomDomains.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Domains;

use Illuminate\Support\Facades\Cache;

/**
 * THE list of verified custom domains. Task 3 gives it verifiedHosts() and
 * organizationFor(); this task needs only the cache key and the invalidation,
 * because the two actions that change a domain have to forget it.
 */
final class CustomDomains
{
    public const CACHE_KEY = 'cass:custom-domains';

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
```

- [ ] **Step 8: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/DomainNameTest.php tests/Unit/VerifyCustomDomainTest.php > /tmp/t-task-1.log 2>&1; echo "rc=$?"; tail -8 /tmp/t-task-1.log && \
php artisan test > /tmp/all-task-1.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-1.log
```

Expected: both `rc=0`; about 27 passed in the first (the two datasets contribute 6 and 11 rows) and `baseline + 27` in the second.

Then prove the config and the language file agree with the code:

```bash
cd /c/Users/ahmed/Documents/CASS && \
php -r "require 'vendor/autoload.php'; \$c = require 'config/cass.php'; var_dump(\$c['domains']);" && \
php -r "\$a = require 'lang/en/domain.php'; echo implode(', ', array_keys(\$a)), PHP_EOL;" && \
grep -c "CASS_DOMAIN_CNAME_TARGET\|CASS_DOMAIN_CACHE_SECONDS\|CASS_DOMAIN_VERIFY_LIMIT" .env.example
```

Expected: an array with `cname_target`, `cache_seconds` and `verify_rate_limit`; the seven group names `section, fields, state, records, actions, notices, mail, errors` (eight); and `3`.

- [ ] **Step 9: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all-task-1.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(domains): claim, verify and release a custom domain

The verification token is stored as plaintext and published in public DNS,
which is a deliberate exception to the hashed-token rule: it authorises
nothing, and a hashed value cannot be shown to the organizer a second time.
The resolver is the first container binding in this codebase, because
dns_get_record() is a global function and nothing else can substitute it.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

**If Larastan complains** about `dns_get_record()`'s return type, the fix is the explicit `=== false` check that is already there plus an `@param` on nothing — the function is declared `array|false` in the stubs, so the branch narrows it. If it complains about `idn_to_ascii()` (declared `string|false`), the `is_string($ascii)` guard is what narrows that one. Do not add `@phpstan-ignore` lines; both are real narrowings.

---

### Task 2: The custom-domain section on the organization profile

The screen for Task 1's three actions: one section on `EditOrganizationProfile` that shows the current state, the two records to publish, and three actions — **Save domain**, **Verify** and **Remove domain**. Spec 5.8's first three sentences. No routing yet; a verified domain serves nothing until Task 3.

**Three decisions this task makes.**

**1. The domain is not a form field the inherited `save()` writes; it is an action's modal.** `EditOrganizationProfile` extends `Filament\Pages\Tenancy\EditTenantProfile` and has **no `save()` of its own** (fact 25) — the parent's `save()` fills the tenant from the dehydrated form state. Putting `custom_domain` in that schema would mean either making it fillable (it is not, deliberately — fact 13, and `Model::preventSilentlyDiscardingAttributes()` would throw) or overriding `save()` to special-case one field. Both are worse than the alternative: the section shows **read-only** state with an infolist `Filament\Infolists\Components\TextEntry` (`Filament\Schemas\Components\Text` has no `label()` — see the check after Step 2), and every write goes through one of three `Filament\Actions\Action`s embedded with `Filament\Schemas\Components\Actions` (verified present in 5.8.1 at `vendor/filament/schemas/src/Components/Actions.php:19`, taking `array<Action|ActionGroup>`). The colours, the logo and the profile fields keep saving exactly as they did.

**2. The section is visible to anybody who can open the page, and the actions are gated by the same rule as the page.** `EditOrganizationProfile::canView()` is already `$user->roleIn($tenant)?->canManageOrganization() ?? false` (owner or admin; a plain member gets 404). The three actions repeat that check in `->visible()` rather than inheriting it silently, because an action is a Livewire endpoint a client can call by name and "the page was 404" is not an authorization check on the call.

**3. Verify is metered per actor, not per organization.** Each click is a synchronous outbound DNS lookup on one of four php-fpm workers (`Dockerfile:31`), and the natural failure mode is an organizer clicking Verify every two seconds while waiting for DNS to propagate. `CASS_DOMAIN_VERIFY_LIMIT` (10/minute) is keyed on the user, the shape `InviteMember` uses for its own send limit.

**Files:**
- Modify: `app/Filament/Organizer/Pages/Tenancy/EditOrganizationProfile.php`
- Create: `resources/views/filament/organizer/partials/custom-domain-records.blade.php`
- Modify: `lang/en/domain.php` (one group appended)
- Test: `tests/Feature/Organizer/CustomDomainTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Organizer/CustomDomainTest.php`
```php
<?php

declare(strict_types=1);

use App\Contracts\DnsResolver;
use App\Enums\OrganizationRole;
use App\Filament\Organizer\Pages\Tenancy\EditOrganizationProfile;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CustomDomainVerified;
use App\Support\Domains\FakeDnsResolver;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    config()->set('app.url', 'https://cass.towardpcc.com');
    config()->set('cass.domains.cname_target', 'cass.towardpcc.com');

    Notification::fake();

    $this->dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $this->dns);

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->owner = User::factory()->create();
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
    actingAs($this->owner);
    bootOrganizerPanel($this->organization);
});

it('shows the section with nothing claimed', function () {
    livewire(EditOrganizationProfile::class)
        ->assertSee(__('domain.section.heading'))
        ->assertSee(__('domain.state.none'))
        ->assertActionExists('claimCustomDomain')
        // Nothing to verify and nothing to remove until a domain is saved:
        // an action that is visible and refuses is a worse screen than one
        // that is not there.
        ->assertActionHidden('verifyCustomDomain')
        ->assertActionHidden('releaseCustomDomain');
});

it('saves a domain and then prints the two records', function () {
    livewire(EditOrganizationProfile::class)
        ->callAction('claimCustomDomain', ['domain' => ' Abstracts.Example.ORG '])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $this->organization->refresh();

    expect($this->organization->custom_domain)->toBe('abstracts.example.org')
        ->and($this->organization->custom_domain_token)->toHaveLength(64);

    livewire(EditOrganizationProfile::class)
        ->assertSee(__('domain.state.pending'))
        ->assertSee('_cass-verify.abstracts.example.org')
        // The token itself is on the page, on purpose: it is published in
        // public DNS and the organizer has to copy it. Task 1's decision 1.
        ->assertSee((string) $this->organization->custom_domain_token)
        ->assertSee('cass.towardpcc.com')
        ->assertActionExists('verifyCustomDomain')
        ->assertActionExists('releaseCustomDomain');
});

it('refuses a domain that is not a domain, without touching the row', function () {
    livewire(EditOrganizationProfile::class)
        ->callAction('claimCustomDomain', ['domain' => 'localhost'])
        ->assertHasActionErrors(['domain']);

    expect($this->organization->fresh()?->custom_domain)->toBeNull();
});

it('refuses the platform host', function () {
    livewire(EditOrganizationProfile::class)
        ->callAction('claimCustomDomain', ['domain' => 'org.cass.towardpcc.com'])
        ->assertHasActionErrors(['domain']);
});

it('refuses a domain another organization already holds', function () {
    $other = withoutTenant(fn (): Organization => Organization::factory()->approved()->create());
    $other->forceFill(['custom_domain' => 'abstracts.example.org', 'custom_domain_token' => str_repeat('a', 64)])->save();

    livewire(EditOrganizationProfile::class)
        ->callAction('claimCustomDomain', ['domain' => 'abstracts.example.org'])
        ->assertHasActionErrors(['domain']);

    expect($this->organization->fresh()?->custom_domain)->toBeNull();
});

it('verifies, tells the organizer the platform team has been emailed, and logs it', function () {
    livewire(EditOrganizationProfile::class)->callAction('claimCustomDomain', ['domain' => 'abstracts.example.org']);
    $this->organization->refresh();
    $this->dns->set('_cass-verify.abstracts.example.org', [(string) $this->organization->custom_domain_token]);

    $admin = User::factory()->platformAdmin()->create();

    livewire(EditOrganizationProfile::class)
        ->callAction('verifyCustomDomain')
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($this->organization->fresh()?->hasVerifiedCustomDomain())->toBeTrue();

    Notification::assertSentTo($admin, CustomDomainVerified::class);

    livewire(EditOrganizationProfile::class)->assertSee(__('domain.state.verified'));
});

it('keeps the organizer on the page and says what is wrong when the record is missing', function () {
    livewire(EditOrganizationProfile::class)->callAction('claimCustomDomain', ['domain' => 'abstracts.example.org']);

    // No record set on the fake at all: the same answer an organizer gets two
    // minutes after adding one, before it has propagated.
    livewire(EditOrganizationProfile::class)
        ->callAction('verifyCustomDomain')
        ->assertNotified();

    expect($this->organization->fresh()?->custom_domain_verified_at)->toBeNull();
    Notification::assertNotSentTo(User::factory()->platformAdmin()->create(), CustomDomainVerified::class);
});

it('stops an organizer who clicks verify in a loop', function () {
    config()->set('cass.domains.verify_rate_limit', 2);
    livewire(EditOrganizationProfile::class)->callAction('claimCustomDomain', ['domain' => 'abstracts.example.org']);

    $page = livewire(EditOrganizationProfile::class);

    $page->callAction('verifyCustomDomain');
    $page->callAction('verifyCustomDomain');
    $page->callAction('verifyCustomDomain');

    // Three clicks, two lookups: the third is refused by the limiter before
    // the resolver is asked. Asserting the resolver's own log rather than a
    // notification body, because the notification is a sentence and this is a
    // count.
    expect($this->dns->queriedNames())->toHaveCount(2);
});

it('removes a domain and frees the name', function () {
    livewire(EditOrganizationProfile::class)->callAction('claimCustomDomain', ['domain' => 'abstracts.example.org']);

    livewire(EditOrganizationProfile::class)
        ->callAction('releaseCustomDomain')
        ->assertNotified();

    $this->organization->refresh();

    expect($this->organization->custom_domain)->toBeNull()
        ->and($this->organization->custom_domain_token)->toBeNull()
        ->and($this->organization->custom_domain_verified_at)->toBeNull();
});

it('hides every domain action from a plain member, and the page with it', function () {
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);

    // Filament 5.8 resolves a false canView() on a tenant profile page into a
    // 404, not a 403 - the behaviour tests/Feature/Organizer/OrganizationProfileTest.php
    // already pins.
    actingAs($member)->get('/org/'.$this->organization->slug.'/profile')->assertNotFound();
});

it('is unreachable from another organization', function () {
    $outsider = User::factory()->create();
    $other = withoutTenant(fn (): Organization => Organization::factory()->approved()->create());
    $other->addMember($outsider, OrganizationRole::Owner);

    actingAs($outsider)->get('/org/'.$this->organization->slug.'/profile')->assertNotFound();
});
```

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/CustomDomainTest.php > /tmp/t-task-2.log 2>&1; echo "rc=$?"; tail -20 /tmp/t-task-2.log
```

Expected: `rc=1`, with failures naming the missing action `claimCustomDomain`. **Failing-first gate.**

**If `callAction()` cannot find an action nested in a schema** — the one API risk in this task — the fallback is `Filament\Actions\Testing\TestAction`:

```php
use Filament\Actions\Testing\TestAction;

->callAction(TestAction::make('claimCustomDomain')->schemaComponent('custom_domain_actions'), ['domain' => '...'])
```

where `custom_domain_actions` is the `->key()` given to the `Actions` component in Step 2. Try the plain string first; the failing run above tells you which you have.

- [ ] **Step 2: The section**

`app/Filament/Organizer/Pages/Tenancy/EditOrganizationProfile.php` — append a third `Section` after `Branding`, and add the three private methods below it. The `Details` and `Branding` sections are **unchanged**.

```php
            Section::make(__('domain.section.heading'))
                ->description(__('domain.section.description', ['platform' => DomainName::platformHost()]))
                ->columns(1)
                ->components([
                    // Read-only: every write goes through one of the three
                    // actions below, because the parent's save() fills the
                    // tenant from the form state and none of the three columns
                    // is fillable (Task 2 decision 1).
                    // An infolist TextEntry, NOT Filament\Schemas\Components\Text:
                    // that component has badge() and color() but no label() - it
                    // does not use HasLabel, so ->label() falls through
                    // Macroable::__call and throws BadMethodCallException, which
                    // would make /org/{slug}/profile a 500 for every organizer.
                    // An entry is never dehydrated, so the parent save() still
                    // sees no `custom_domain_status` key.
                    TextEntry::make('custom_domain_status')
                        ->label(__('domain.fields.status'))
                        ->state(fn (): string => static::stateLabel())
                        ->badge()
                        ->color(fn (): string => static::stateColor()),
                    View::make('filament.organizer.partials.custom-domain-records')
                        ->viewData(['organization' => static::tenant()])
                        ->visible(fn (): bool => static::tenant()?->custom_domain !== null),
                    Actions::make([
                        static::claimAction(),
                        static::verifyAction(),
                        static::releaseAction(),
                    ])->key('custom_domain_actions'),
                ]),
```

and, after `contrastRule()`:

```php
    /** The tenant this page is editing, typed once so every closure below reads it the same way. */
    protected static function tenant(): ?Organization
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization ? $tenant : null;
    }

    protected static function stateLabel(): string
    {
        $organization = static::tenant();

        if ($organization?->custom_domain === null) {
            return __('domain.state.none');
        }

        return $organization->hasVerifiedCustomDomain()
            ? __('domain.state.verified').' — '.$organization->custom_domain
            : __('domain.state.pending').' — '.$organization->custom_domain;
    }

    protected static function stateColor(): string
    {
        $organization = static::tenant();

        return match (true) {
            $organization?->custom_domain === null => 'gray',
            $organization->hasVerifiedCustomDomain() => 'success',
            default => 'warning',
        };
    }

    protected static function canManage(): bool
    {
        $user = auth()->user();
        $organization = static::tenant();

        // The page's canView() already answers this, but an action is a
        // Livewire endpoint a client can call by name, and "the page would
        // have 404ed" is not an authorization check on that call.
        return $user instanceof User
            && $organization instanceof Organization
            && ($user->roleIn($organization)?->canManageOrganization() ?? false);
    }

    protected static function claimAction(): Action
    {
        return Action::make('claimCustomDomain')
            ->label(__('domain.actions.claim'))
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->visible(fn (): bool => static::canManage())
            ->schema([
                TextInput::make('domain')
                    ->label(__('domain.fields.domain'))
                    ->helperText(__('domain.fields.domain_help'))
                    ->required()
                    ->maxLength(DomainName::MAX_LENGTH)
                    ->rule(static::domainRule()),
            ])
            ->fillForm(fn (): array => ['domain' => static::tenant()?->custom_domain])
            ->action(function (array $data): void {
                $organization = static::tenant();
                $user = auth()->user();

                if (! $organization instanceof Organization || ! $user instanceof User) {
                    return;
                }

                try {
                    app(ClaimCustomDomain::class)->handle($organization, (string) $data['domain'], $user);
                } catch (CustomDomainRefused $exception) {
                    // The uniqueness check lives in the action, because the
                    // action is also what the console and any later caller
                    // use. Surfacing it on the field rather than as a
                    // notification is what keeps the modal open with the value
                    // still in it.
                    throw ValidationException::withMessages(['domain' => $exception->getMessage()]);
                }

                Notification::make()->success()->title(__('domain.notices.claimed'))->send();
            });
    }

    protected static function verifyAction(): Action
    {
        return Action::make('verifyCustomDomain')
            ->label(__('domain.actions.verify'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('primary')
            ->visible(fn (): bool => static::canManage() && static::tenant()?->custom_domain !== null)
            ->action(function (): void {
                $organization = static::tenant();
                $user = auth()->user();

                if (! $organization instanceof Organization || ! $user instanceof User) {
                    return;
                }

                // One outbound DNS lookup per click on a four-worker pool, and
                // the natural behaviour of somebody waiting for propagation is
                // to click every two seconds. Keyed on the actor, the shape
                // InviteMember uses.
                $key = 'domain-verify:'.$user->getAuthIdentifier();
                $limit = max(1, (int) config('cass.domains.verify_rate_limit'));

                if (RateLimiter::tooManyAttempts($key, $limit)) {
                    Notification::make()->warning()
                        ->title(__('domain.errors.lookup_failed'))
                        ->body(__('reviewer.errors.throttled', ['seconds' => RateLimiter::availableIn($key)]))
                        ->send();

                    return;
                }

                RateLimiter::hit($key, 60);

                try {
                    app(VerifyCustomDomain::class)->handle($organization, $user);
                } catch (CustomDomainRefused $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title(__('domain.notices.verified_title'))
                    ->body(__('domain.notices.verified_body', [
                        'domain' => (string) $organization->custom_domain,
                    ]))
                    ->persistent()
                    ->send();
            });
    }

    protected static function releaseAction(): Action
    {
        return Action::make('releaseCustomDomain')
            ->label(__('domain.actions.release'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (): bool => static::canManage() && static::tenant()?->custom_domain !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => __('domain.actions.release_confirm_heading', [
                'domain' => (string) static::tenant()?->custom_domain,
            ]))
            ->modalDescription(fn (): string => __('domain.actions.release_confirm_body', [
                'domain' => (string) static::tenant()?->custom_domain,
                'platform' => DomainName::platformHost(),
            ]))
            ->action(function (): void {
                $organization = static::tenant();
                $user = auth()->user();

                if (! $organization instanceof Organization || ! $user instanceof User) {
                    return;
                }

                $domain = (string) $organization->custom_domain;

                app(ReleaseCustomDomain::class)->handle($organization, $user);

                Notification::make()->success()->title(__('domain.notices.released', ['domain' => $domain]))->send();
            });
    }

    /**
     * The double-closure shape this file already uses for contrastRule(), and
     * for the same reason: Filament evaluates a Closure rule as a callback, so
     * an unwrapped `function (string $attribute, …)` throws
     * BindingResolutionException on its first parameter. The outer closure
     * takes nothing and returns the real Illuminate rule.
     */
    protected static function domainRule(): Closure
    {
        return static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $problem = DomainName::problem($value);

            if ($problem !== null) {
                $fail(__($problem));
            }
        };
    }
```

New imports on that file, alphabetically: `App\Actions\Organizations\ClaimCustomDomain`, `App\Actions\Organizations\ReleaseCustomDomain`, `App\Actions\Organizations\VerifyCustomDomain`, `App\Exceptions\CustomDomainRefused`, `App\Support\Domains\DomainName`, `Filament\Actions\Action`, `Filament\Facades\Filament`, `Filament\Infolists\Components\TextEntry`, `Filament\Notifications\Notification`, `Filament\Schemas\Components\Actions`, `Filament\Schemas\Components\View`, `Filament\Support\Icons\Heroicon`, `Illuminate\Support\Facades\RateLimiter`, `Illuminate\Validation\ValidationException`. `Closure`, `Organization`, `User`, `TextInput` and `Section` are already imported.

**Two things to check against the code rather than against this page.** `Filament\Schemas\Components\Text` has `badge()`/`color()` but **NO** `label()` — it does not use `HasLabel`, so `->label()` hits `Filament\Support\Concerns\Macroable::__call` and throws `BadMethodCallException`. The labelled badge is therefore an infolist `TextEntry` with `->state()`, which is the same component Plan 5's infolists use, and `Filament\Forms\Components\Placeholder` is **not** the fallback: it is marked `@deprecated Use TextEntry with the state() method instead` in 5.8.1 and extends `TextEntry` anyway. And `__('reviewer.errors.throttled')` is a Plan 4 key — `grep -n "throttled" lang/en/reviewer.php` before using it; if it is spelled differently, add `domain.errors.throttled` to `lang/en/domain.php` instead of reaching into another file's namespace.

- [ ] **Step 3: The records partial**

`resources/views/filament/organizer/partials/custom-domain-records.blade.php`
```blade
{{-- The panel loads no Tailwind utilities (backlog: the organizer theme), so
     this is inline-styled like every other organizer partial in this app. --}}
@php
    $txtName = $organization?->customDomainTxtName();
    $token = (string) ($organization?->custom_domain_token ?? '');
    $cname = \App\Support\Domains\DomainName::cnameTarget();
    $domain = (string) ($organization?->custom_domain ?? '');
@endphp

<div style="border:1px solid #e2e8f0;border-radius:0.5rem;padding:1rem;background:#f8fafc">
    <p style="font-weight:600;margin:0 0 0.25rem">{{ __('domain.records.heading') }}</p>
    <p style="margin:0 0 0.75rem;color:#475569;font-size:0.875rem">
        {{ __('domain.records.intro', ['domain' => $domain]) }}
    </p>

    <dl style="display:grid;grid-template-columns:minmax(0,14rem) minmax(0,1fr);gap:0.5rem 1rem;margin:0;font-size:0.875rem">
        <dt style="color:#475569">{{ __('domain.records.txt_name') }}</dt>
        <dd style="margin:0;font-family:ui-monospace,monospace;overflow-wrap:anywhere">{{ $txtName }}</dd>

        <dt style="color:#475569">{{ __('domain.records.txt_value') }}</dt>
        <dd style="margin:0;font-family:ui-monospace,monospace;overflow-wrap:anywhere">{{ $token }}</dd>

        <dt style="color:#475569">{{ __('domain.records.cname_name') }}</dt>
        <dd style="margin:0;font-family:ui-monospace,monospace;overflow-wrap:anywhere">{{ $domain }}</dd>

        <dt style="color:#475569">{{ __('domain.records.cname_value') }}</dt>
        <dd style="margin:0;font-family:ui-monospace,monospace;overflow-wrap:anywhere">{{ $cname }}</dd>
    </dl>

    <p style="margin:0.75rem 0 0;color:#475569;font-size:0.8125rem">{{ __('domain.records.cname_note') }}</p>
    @if ($organization?->hasVerifiedCustomDomain())
        <p style="margin:0.25rem 0 0;color:#475569;font-size:0.8125rem">{{ __('domain.records.after') }}</p>
    @endif
</div>
```

The token is printed in full, on purpose. It is published in public DNS, and an organizer who cannot read the whole value cannot copy it. Task 1's decision 1 is the argument; the runbook repeats it so nobody later "redacts" it.

- [ ] **Step 4: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/CustomDomainTest.php tests/Feature/Organizer/OrganizationProfileTest.php > /tmp/t-task-2.log 2>&1; echo "rc=$?"; tail -10 /tmp/t-task-2.log && \
php artisan test > /tmp/all-task-2.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-2.log
```

Expected: both `rc=0`; about 10 passed in the first file and `baseline + 37` in the second.

**Watch `OrganizationProfileTest`.** It is in the same run on purpose: it asserts that `->fillForm([...])->call('save')` writes the name, the colours and the logo, and a third section added to the schema is exactly the kind of change that makes a `fillForm` of the other two sections start failing on a missing required field. If it goes red, the honest fix is that the new section holds no *field* at all — only `TextEntry`, `View` and `Actions` — so check for a stray `TextInput` outside the claim action's modal.

- [ ] **Step 5: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all-task-2.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(domains): the custom-domain section on the organization profile

Read-only state plus three actions, because the tenant profile page's inherited
save() fills the tenant from the form and none of the three columns is
fillable. Verify is metered per actor: each click is a synchronous DNS lookup
on a four-worker pool.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---

### Task 3: Serving a verified domain — routing, trusted hosts and the public URLs

Spec 5.8's last sentence: *"Once live, `https://abstracts.example.org/{conference-slug}` serves the conference without the `/c/{org}` prefix."* Plus the backlog item that has been waiting since Plan 1: *"when custom domains ship, the trusted-host closure must also return verified `organizations.custom_domain` values, and the runbook section on custom domains becomes valid."*

**Five decisions this task makes, and the fourth is the one that keeps tenants apart.**

**1. Exactly four things resolve on a verified custom domain, and everything else is 404.** The URL set, decided:

| On `https://abstracts.example.org` | What it is |
|---|---|
| `/` | 302 to the organization's conference page **when it has exactly one publicly visible conference**; 404 otherwise |
| `/{conference-slug}` | the public conference page — the same controller as `/c/{org}/{slug}` |
| `/{conference-slug}/submit` | the submission form — the same Livewire component as `/c/{org}/{slug}/submit` |
| `/livewire-{hash}/*` and `/up` | Livewire's own endpoints (the prefix is derived from `APP_KEY` by `Livewire\Mechanisms\HandleRequests\EndpointResolver::prefix()` — it is **NOT** `/livewire`; on this application it is `livewire-99b7d536`), and the health check |

Everything else — `/about`, `/register`, `/contact`, `/privacy`, `/terms`, `/q/{code}`, `/s/{token}`, `/invite/{token}`, `/files/{ulid}`, `/conference-assets/…`, `/org`, `/admin`, `/review` — answers **404 on a custom domain**. An organizer who points `abstracts.example.org` at us is publishing *their* call for abstracts, not a second front door to the CASS platform, and a registration form on their domain is a phishing surface with our name on it.

`/livewire-{hash}/*` is **not** optional: the submission form is a Livewire component and its updates and its file uploads both post to that prefix on whatever host rendered the page. Forgetting it makes the submit page a form nobody can submit — the same failure mode Plan 3's backlog recorded for a CSP that forgets Turnstile. Note the shape: because the prefix is `'/livewire-'.substr(hash('sha256', config('app.key').'livewire-endpoint'), 0, 8)`, **there is no fixed segment to reserve or to allow-list by name** — it moves if `APP_KEY` is rotated, and every place below that needs it asks `EndpointResolver` rather than spelling it.

**2. `/s/{token}`, `/files/{ulid}` and `/q/{code}` stay on the platform host, deliberately.** An author's status token is a bearer credential with no account behind it (`routes/web.php:116`), a file URL is a signature bound to its full URL, and a short code is printed on posters that outlive a domain an organizer may stop paying for. `route()` builds from the **CURRENT request root**, not from `APP_URL` (`UrlGenerator::formatRoot()` uses `$this->forcedRoot ?: $this->request->root()`), so on a custom domain every absolute URL would be minted on the organizer's host. `ResolveCustomDomain` therefore pins URL generation to the platform host for the rest of the request, and pins *asset* generation to the host that is actually serving the page so `@vite` stays same-origin under `script-src 'self'`. With that pin in place all three keep working exactly as they do today. The consequence, stated so nobody is surprised: **submitting an abstract on a custom domain ends with a redirect to the platform host**, because that is where the status page lives. The runbook says so.

**3. `GET /` redirects only when the answer is unambiguous.** One publicly visible conference → 302 to it. Zero, or two or more → 404. Inventing a conference-listing page is a feature spec 5.8 does not ask for, and picking "the newest" would silently send visitors to the wrong meeting in the one week a society runs two calls at once.

**4. The conference is resolved *through* the organization, never by implicit binding.** `Route::get('/{conference:slug}', …)` would make Laravel resolve the first conference in the whole database with that slug — and `(conference_id, slug)` is unique **per organization**, not globally (spec section 8), so `alpha.example.org/annual-meeting` would cheerfully serve Beta Society's `annual-meeting`. So the route parameter is a **plain string**, and `RequireCustomDomain` looks it up with `$organization->conferences()->where('slug', $slug)->first()` and substitutes the two real models into the route before the controller or the Livewire component sees them. A verified domain of organization A cannot serve B's conference because there is no query in the code that could return one. `CustomDomainRoutingTest` asserts it directly.

**5. The trusted-host list is cached, and a database failure widens nothing.** `TrustHosts` runs on every request (fact 14) and its closure now has to know every verified domain. A query per request is avoidable; a query per request that 400s the whole site when MySQL blinks is not acceptable at all. So `CustomDomains::verifiedHosts()` caches for `CASS_DOMAIN_CACHE_SECONDS` (60) and catches `Throwable`, returning `[]` — the platform host is added by the closure separately and unconditionally, so a database outage leaves the main site working and custom domains 400ing, which is the correct direction to fail. `VerifyCustomDomain` and `ReleaseCustomDomain` already call `CustomDomains::forget()` (Task 1), so a newly verified domain works in the same second.

**Files:**
- Create: `app/Http/Middleware/ResolveCustomDomain.php`, `app/Http/Middleware/RequireCustomDomain.php`
- Create: `app/Http/Controllers/Public/CustomDomainController.php`
- Modify: `app/Support/Domains/CustomDomains.php` (Task 1 left the cache key and `forget()`)
- Modify: `bootstrap/app.php`, `routes/web.php`, `phpunit.browser.xml` (one `APP_URL` line — see Step 4)
- Modify: `app/Models/Conference.php` (`publicUrl()`, new `publicSubmitUrl()`)
- Modify: `resources/views/public/partials/submit-cta.blade.php`, `resources/views/components/layouts/conference.blade.php`, `app/Livewire/Public/SubmissionForm.php` (the `route('conference.show', …)` call sites)
- Test: `tests/Unit/CustomDomainsTest.php`, `tests/Feature/Public/CustomDomainRoutingTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/CustomDomainsTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Support\Domains\CustomDomains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.url', 'https://cass.towardpcc.com');
    CustomDomains::forget();
});

it('lists only verified domains', function () {
    $verified = Organization::factory()->approved()->create();
    $verified->forceFill([
        'custom_domain' => 'abstracts.example.org',
        'custom_domain_token' => str_repeat('a', 64),
        'custom_domain_verified_at' => now(),
    ])->save();

    $claimed = Organization::factory()->approved()->create();
    $claimed->forceFill([
        'custom_domain' => 'pending.example.org',
        'custom_domain_token' => str_repeat('b', 64),
    ])->save();

    expect(CustomDomains::verifiedHosts())->toBe(['abstracts.example.org']);
});

it('leaves a soft-deleted organization out of the list', function () {
    $organization = Organization::factory()->approved()->create();
    $organization->forceFill([
        'custom_domain' => 'abstracts.example.org',
        'custom_domain_token' => str_repeat('a', 64),
        'custom_domain_verified_at' => now(),
    ])->save();
    $organization->delete();

    CustomDomains::forget();

    // A deleted organization's pages 404 anyway, but leaving the host in the
    // trusted list means the request gets that far, which is a routing surface
    // nothing owns.
    expect(CustomDomains::verifiedHosts())->toBe([]);
});

it('caches the list and forgets it on demand', function () {
    $organization = Organization::factory()->approved()->create();
    $organization->forceFill([
        'custom_domain' => 'abstracts.example.org',
        'custom_domain_token' => str_repeat('a', 64),
        'custom_domain_verified_at' => now(),
    ])->save();

    expect(CustomDomains::verifiedHosts())->toBe(['abstracts.example.org']);

    // Written straight through the query builder so no model event and no
    // action can invalidate the cache for us: this asserts the cache, not the
    // invalidation.
    DB::table('organizations')->whereKey($organization->getKey())->update(['custom_domain' => 'moved.example.org']);

    expect(CustomDomains::verifiedHosts())->toBe(['abstracts.example.org']);

    CustomDomains::forget();

    expect(CustomDomains::verifiedHosts())->toBe(['moved.example.org']);
});

it('answers an empty list rather than throwing when the lookup fails', function () {
    // Not DB::shouldReceive(): Eloquent holds the DatabaseManager directly
    // (Model::setConnectionResolver), so swapping the facade changes nothing,
    // and with no organizations in the test the real query would return []
    // either way. Break the cache instead - Cache::remember is the first line
    // inside the same try - which is the same class of failure from
    // verifiedHosts()'s point of view.
    Cache::shouldReceive('remember')->andThrow(new RuntimeException('cache gone'));

    // The trusted-host closure runs on EVERY request, before anything can
    // render an error page. A throw here is a 500 on the main site because a
    // custom domain's lookup failed.
    expect(CustomDomains::verifiedHosts())->toBe([]);
});

it('leaves a suspended organization out of the trusted list', function () {
    $organization = Organization::factory()->approved()->create();
    $organization->forceFill([
        'custom_domain' => 'abstracts.example.org',
        'custom_domain_token' => str_repeat('a', 64),
        'custom_domain_verified_at' => now(),
    ])->save();

    expect(CustomDomains::verifiedHosts())->toBe(['abstracts.example.org']);

    $organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
    CustomDomains::forget();

    // An organization the platform has suspended keeps neither a routing
    // surface nor a place in TrustHosts. Its domain 400s within
    // CASS_DOMAIN_CACHE_SECONDS, which is the same direction the rest of this
    // task fails in.
    expect(CustomDomains::verifiedHosts())->toBe([]);
});

it('produces anchored, quoted trusted-host patterns', function () {
    // bootstrap/app.php turns each host into a PATTERN. Symfony wraps every
    // TrustHosts entry as `{...}i` and matches it UNANCHORED, so a bare
    // `abstracts.example.org` would also trust `abstracts.example.org.evil.test`
    // and would treat every dot as "any character".
    foreach (['cass.towardpcc.com', 'abstracts.example.org'] as $host) {
        $pattern = '{^'.preg_quote($host).'$}i';

        expect(preg_match($pattern, $host))->toBe(1)
            ->and(preg_match($pattern, $host.'.evil.test'))->toBe(0)
            ->and(preg_match($pattern, str_replace('.', 'X', $host)))->toBe(0);
    }
});

it('puts the platform host first and every verified domain after it in the trusted-host list', function () {
    // TrustHosts is a no-op under runningUnitTests() (TrustHosts:98-101), so
    // the closure in bootstrap/app.php runs nowhere else in this suite - and a
    // closure that forgot verifiedHosts() is HTTP 400 on every custom domain
    // in production with a green suite. hosts() is public and reads the static
    // the bootstrap file set, so it can be called directly.
    $organization = Organization::factory()->approved()->create();
    $organization->forceFill([
        'custom_domain' => 'abstracts.example.org',
        'custom_domain_token' => str_repeat('a', 64),
        'custom_domain_verified_at' => now(),
    ])->save();

    CustomDomains::forget();

    $hosts = app(\Illuminate\Http\Middleware\TrustHosts::class)->hosts();

    expect($hosts[0] ?? null)->toBe('^'.preg_quote('cass.towardpcc.com').'$')
        ->and($hosts)->toContain('^'.preg_quote('abstracts.example.org').'$');
});

it('still trusts the platform host when the domain lookup throws', function () {
    // The direction this has to fail in: the main site keeps working and
    // custom domains 400, never the other way round.
    Cache::shouldReceive('remember')->andThrow(new RuntimeException('cache gone'));

    expect(app(\Illuminate\Http\Middleware\TrustHosts::class)->hosts())
        ->toBe(['^'.preg_quote('cass.towardpcc.com').'$']);
});

it('resolves a host to its organization, case-insensitively, and nothing else', function () {
    $organization = Organization::factory()->approved()->create();
    $organization->forceFill([
        'custom_domain' => 'abstracts.example.org',
        'custom_domain_token' => str_repeat('a', 64),
        'custom_domain_verified_at' => now(),
    ])->save();

    expect(CustomDomains::organizationFor('ABSTRACTS.Example.org')?->is($organization))->toBeTrue()
        ->and(CustomDomains::organizationFor('abstracts.example.org.')?->is($organization))->toBeTrue()
        ->and(CustomDomains::organizationFor('other.example.org'))->toBeNull()
        ->and(CustomDomains::organizationFor('cass.towardpcc.com'))->toBeNull();
});
```

`tests/Feature/Public/CustomDomainRoutingTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Http\Middleware\RequireCustomDomain;
use App\Models\Conference;
use App\Models\Organization;
use App\Support\Domains\CustomDomains;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

use function Pest\Laravel\get;

beforeEach(function () {
    config()->set('app.url', 'https://cass.towardpcc.com');
    CustomDomains::forget();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->organization->forceFill([
        'custom_domain' => 'abstracts.example.org',
        'custom_domain_token' => str_repeat('a', 64),
        'custom_domain_verified_at' => now(),
    ])->save();

    $this->conference = Conference::factory()->for($this->organization)->create([
        'name' => 'Alpha Annual Meeting',
        'slug' => 'annual-meeting',
        'status' => ConferenceStatus::Open,
        'submission_opens_at' => now()->subWeek(),
        'submission_deadline' => now()->addWeek(),
    ]);

    CustomDomains::forget();
});

/**
 * Every request in this file is made with an explicit Host header, because
 * that is the only thing that distinguishes the two route sets. `get()` with a
 * full URL sets it; the array form is here so the intent is visible.
 */
function onDomain(string $path, string $host = 'abstracts.example.org'): Illuminate\Testing\TestResponse
{
    return get('http://'.$host.$path);
}

it('serves the conference page at the root of a verified domain', function () {
    onDomain('/annual-meeting')
        ->assertOk()
        ->assertSee('Alpha Annual Meeting')
        ->assertSee('Alpha Society');
});

it('serves the submit page under the conference slug', function () {
    onDomain('/annual-meeting/submit')->assertOk();
});

it('redirects the root to the one public conference', function () {
    onDomain('/')->assertRedirect('https://abstracts.example.org/annual-meeting');
});

it('404s the root when there is nothing, or more than one thing, to redirect to', function () {
    Conference::factory()->for($this->organization)->create([
        'slug' => 'second-meeting',
        'status' => ConferenceStatus::Open,
    ]);

    // Two open conferences: picking one would silently send visitors to the
    // wrong meeting in exactly the week a society runs two calls at once.
    onDomain('/')->assertNotFound();

    Conference::query()->update(['status' => ConferenceStatus::Draft]);

    onDomain('/')->assertNotFound();
});

it('never serves another organization conference from this domain', function () {
    $theirs = Conference::factory()->create(['slug' => 'their-meeting', 'status' => ConferenceStatus::Open]);

    expect($theirs->organization->is($this->organization))->toBeFalse();

    // The route parameter is a plain string and RequireCustomDomain looks it
    // up through $organization->conferences(), so there is no query in the
    // application that could return this row for this host.
    onDomain('/their-meeting')->assertNotFound();
    onDomain('/their-meeting/submit')->assertNotFound();
});

it('404s every platform page on a custom domain', function (string $path) {
    onDomain($path)->assertNotFound();
})->with([
    '/about', '/privacy', '/terms', '/contact', '/register',
    '/c/'.'alpha-society/annual-meeting',
    '/q/abcd1234',
    '/s/'.str_repeat('a', 64),
    '/invite/'.str_repeat('a', 64),
    '/files/01ARZ3NDEKTSV4RRFFQ69G5FAV',
    '/org', '/admin', '/review',
    // Percent-encoded first characters. Laravel's UriValidator matches
    // rawurldecode($path), so these route exactly like the plain forms above -
    // a middleware that reads Request::path() instead of decodedPath() lets
    // every one of them through and serves the platform's registration form on
    // somebody else's domain.
    '/%72egister', '/%61bout', '/%6frg', '/%61dmin', '/%73/'.str_repeat('a', 64),
]);

it('keeps livewire and the health check reachable on a custom domain', function () {
    // The submission form posts here. A middleware that forgets this prefix
    // makes the submit page a form nobody can submit.
    onDomain('/up')->assertOk();

    // Livewire 4.4.4 derives its endpoint prefix from APP_KEY
    // (EndpointResolver::prefix() -> /livewire-<8 hex>), so the literal
    // '/livewire/update' is not a route at all and would answer 404. Ask the
    // framework for the path rather than spelling it.
    $update = EndpointResolver::updatePath();

    expect($update)->toStartWith('/livewire-');

    // Not a POST - the route exists and is what matters; a GET of a POST-only
    // route is a 405, which proves routing reached it rather than the
    // middleware's 404.
    onDomain($update)->assertStatus(405);
});

it('refuses a host that is not a verified domain', function () {
    // TrustHosts answers 400 before this middleware in production; in the test
    // harness TrustHosts is not in the stack, so this asserts the middleware's
    // own answer, which is the defence in depth.
    onDomain('/annual-meeting', 'not-ours.example.net')->assertNotFound();
});

it('leaves every platform route working on the platform host', function () {
    get('https://cass.towardpcc.com/about')->assertOk();
    get('https://cass.towardpcc.com/c/'.$this->organization->slug.'/annual-meeting')->assertOk();
    // The root-level conference route must not answer on the platform host: a
    // path that happens to look like a slug is a 404, not somebody's
    // conference.
    get('https://cass.towardpcc.com/annual-meeting')->assertNotFound();
});

it('prints the custom domain as the canonical url once verified', function () {
    expect($this->conference->fresh()?->publicUrl())->toBe('https://abstracts.example.org/annual-meeting')
        ->and($this->conference->fresh()?->publicSubmitUrl())->toBe('https://abstracts.example.org/annual-meeting/submit');

    onDomain('/annual-meeting')
        ->assertSee('<link rel="canonical" href="https://abstracts.example.org/annual-meeting">', escape: false)
        ->assertSee('https://abstracts.example.org/annual-meeting/submit', escape: false);
});

it('falls back to the platform url when the domain is claimed but not verified', function () {
    $this->organization->forceFill(['custom_domain_verified_at' => null])->save();
    CustomDomains::forget();

    expect($this->conference->fresh()?->publicUrl())
        ->toBe('https://cass.towardpcc.com/c/'.$this->organization->slug.'/annual-meeting');

    onDomain('/annual-meeting')->assertNotFound();
});

it('substitutes the two models in the order the controller declares them', function () {
    Route::middleware([RequireCustomDomain::class])->get('/{conference}/probe-order', function (Organization $organization, Conference $conference) {
        return response($organization->slug.'|'.$conference->slug);
    })->where('conference', '[a-z0-9-]+');

    // ControllerDispatcher calls the method with array_values($parameters), so
    // a middleware that APPENDS `organization` after the URI's `conference`
    // calls show(Conference, Organization) - a TypeError on every conference
    // page on every custom domain.
    onDomain('/annual-meeting/probe-order')
        ->assertOk()
        ->assertSee($this->organization->slug.'|annual-meeting');
});

it('mints status, file and invitation urls on the platform host even when the page is served on a custom domain', function () {
    $seen = null;

    Route::middleware([RequireCustomDomain::class])->get('/{conference}/probe-urls', function () use (&$seen) {
        $seen = route('submission.status', ['token' => str_repeat('a', 64)]);

        return response('ok');
    })->where('conference', '[a-z0-9-]+');

    onDomain('/annual-meeting/probe-urls')->assertOk();

    // Without URL::forceRootUrl() this is https://abstracts.example.org/s/...:
    // a path the RESERVED list 404s, carrying a 64-character bearer token, on a
    // host whose DNS the organizer can repoint tomorrow.
    expect($seen)->toStartWith('https://cass.towardpcc.com/s/');
});

it('links privacy, terms and contact at the platform host from a custom domain', function () {
    $platform = rtrim((string) config('app.url'), '/');

    // The footer's three links are route('contact'|'privacy'|'terms'), and the
    // forceRootUrl() pin is the only reason they are not dead links to the
    // reserved 404s on this host.
    onDomain('/annual-meeting')
        ->assertSee($platform.'/privacy', escape: false)
        ->assertSee($platform.'/terms', escape: false)
        ->assertSee($platform.'/contact', escape: false);
});

it('keeps the submit page\'s own links on the custom domain', function () {
    $this->conference->forceFill(['submission_deadline' => now()->subDay()])->save();

    // The closed-window back-link renders only when the window is shut. Every
    // route('conference.show') left in SubmissionForm or its view sends an
    // author from the organizer's domain to cass.towardpcc.com mid-flow.
    onDomain('/annual-meeting/submit')
        ->assertSee('https://abstracts.example.org/annual-meeting', escape: false)
        ->assertDontSee('cass.towardpcc.com/c/', escape: false);
});

```

**Two more cases belong in this file and are written by Task 7, not here**, because both assert the `Content-Security-Policy` header and that middleware does not exist until then: `allows the branding origin in the image policy, because the logo is not same-origin here` and `still sends a policy on the 404 a reserved path produces`. Task 7 Step 1 appends them. Writing them now would leave this task's suite red, which no task in this plan is allowed to commit on.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/CustomDomainsTest.php tests/Feature/Public/CustomDomainRoutingTest.php > /tmp/t-task-3.log 2>&1; echo "rc=$?"; tail -20 /tmp/t-task-3.log
```

Expected: `rc=1`, with errors naming `CustomDomains::verifiedHosts()`. **Failing-first gate.**

- [ ] **Step 2: `CustomDomains`**

Replace the stub Task 1 wrote:

`app/Support/Domains/CustomDomains.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Domains;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * THE list of verified custom domains, and the only lookup from a host to an
 * organization. Three readers and no others: the trusted-host closure in
 * bootstrap/app.php, ResolveCustomDomain, and the two actions that invalidate
 * the cache.
 */
final class CustomDomains
{
    public const CACHE_KEY = 'cass:custom-domains';

    /**
     * Every host with a verified domain, lower-cased.
     *
     * Cached because the trusted-host closure reads it on EVERY request
     * (fact 14) and a query per request is avoidable. Guarded because a query
     * per request that throws is a 500 on the main site whenever the database
     * blinks - and the platform's own host is added by the closure separately,
     * so an empty answer here leaves cass.towardpcc.com working and custom
     * domains 400ing, which is the correct direction to fail.
     *
     * @return list<string>
     */
    public static function verifiedHosts(): array
    {
        $seconds = max(1, (int) config('cass.domains.cache_seconds'));

        try {
            /** @var list<string> $hosts */
            $hosts = Cache::remember(self::CACHE_KEY, $seconds, static fn (): array => Organization::query()
                // An organization the platform has refused or suspended keeps
                // neither a routing surface nor a place in TrustHosts. Its
                // pages 404 anyway (approval is checked at render), and this
                // takes the host out of the trusted list within
                // CASS_DOMAIN_CACHE_SECONDS.
                ->where('status', OrganizationStatus::Approved)
                ->whereNotNull('custom_domain')
                ->whereNotNull('custom_domain_verified_at')
                ->orderBy('custom_domain')
                ->pluck('custom_domain')
                ->map(static fn (mixed $host): string => strtolower((string) $host))
                ->all());

            return $hosts;
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * The organization a request's Host header belongs to, or null.
     *
     * Deliberately NOT cached as a model: an Organization carries a status, a
     * soft-delete flag and branding that the page then renders, and a cached
     * copy of all that would be stale for up to a minute in the one place
     * where suspending an organization has to take its pages offline
     * immediately (the owner's recorded decision, backlog).
     */
    public static function organizationFor(string $host): ?Organization
    {
        $normalised = DomainName::normalise($host);

        if ($normalised === '' || ! in_array($normalised, self::verifiedHosts(), true)) {
            return null;
        }

        return Organization::query()
            ->where('custom_domain', $normalised)
            ->whereNotNull('custom_domain_verified_at')
            ->first();
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
```

`Organization` uses `SoftDeletes`, so its global scope keeps trashed rows out of both queries without a `whereNull('deleted_at')` — which is what the "leaves a soft-deleted organization out" case asserts.

- [ ] **Step 3: The two middleware**

`app/Http/Middleware/ResolveCustomDomain.php`
```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Domains\CustomDomains;
use App\Support\Domains\DomainName;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a Host header into an Organization, or into a 404.
 *
 * Appended to the GLOBAL stack in bootstrap/app.php, which means it runs
 * *before* routing (fact 15) - and that is the whole point. A request for
 * /about on a custom domain must not reach the route that would serve the
 * platform's about page, and only a global middleware sees it in time.
 */
class ResolveCustomDomain
{
    /** The request attribute every downstream reader uses. */
    public const ATTRIBUTE = 'cass.custom_domain_organization';

    /**
     * The first path segment of every platform-only route (routes/web.php and
     * the three panel paths). On a custom domain each of these is a 404: an
     * organizer publishing their call for abstracts is not opening a second
     * front door to CASS, and a registration form on somebody else's domain is
     * a phishing surface with our name on it.
     *
     * `up` and Livewire's endpoints are deliberately absent from RESERVED: the
     * submission form posts to `/livewire-{hash}/update` and uploads to
     * `/livewire-{hash}/upload-file`, where {hash} is the first eight hex
     * characters of sha256(APP_KEY.'livewire-endpoint')
     * (Livewire\Mechanisms\HandleRequests\EndpointResolver::prefix()) - so no
     * fixed segment could be listed here anyway, the form posts to that prefix
     * on whatever host rendered it, and the health check must answer
     * everywhere.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'c', 'q', 's', 'invite', 'files', 'conference-assets',
        'about', 'privacy', 'terms', 'contact', 'register',
        'org', 'admin', 'review',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $host = DomainName::normalise($request->getHost());

        if ($host === '' || $host === DomainName::platformHost()) {
            return $next($request);
        }

        $organization = CustomDomains::organizationFor($host);

        if (! $organization instanceof Organization) {
            // TrustHosts answers 400 for an unknown host before this runs in
            // production. This is the defence in depth for a host that IS
            // trusted - one whose organization was suspended, deleted or had
            // its domain released between the cached list and now.
            abort(404);
        }

        // decodedPath(), not path(): Laravel's UriValidator matches
        // rawurldecode($path), so `/%72egister` routes to `register` while
        // `path()` still reads `%72egister`. Comparing the encoded form is how
        // a reserved path gets served on somebody else's domain. Verified by
        // booting the app: path() = '%72egister', matched route = 'register'.
        $path = trim($request->decodedPath(), '/');
        $segment = strtolower(explode('/', $path)[0] ?? '');

        if (in_array($segment, self::RESERVED, true)) {
            abort(404);
        }

        // Allow-list rather than deny-list for everything else this host may
        // serve: the health check, Livewire's APP_KEY-derived endpoint prefix,
        // and a conference slug. Anything else is a platform path this
        // middleware has not been taught about yet. (Static files such as
        // /build/... and /storage/... are served by nginx from disk in
        // production and never reach PHP.)
        $allowed = $segment === ''
            || $segment === 'up'
            || str_starts_with($segment, 'livewire-')
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $segment) === 1;

        if (! $allowed) {
            abort(404);
        }

        $request->attributes->set(self::ATTRIBUTE, $organization);

        // route(), URL::temporarySignedRoute() and Filament's getUrl() all
        // build from UrlGenerator::formatRoot(), which is the CURRENT request
        // root unless a root is forced. Without these two lines an abstract
        // submitted here would be emailed a /s/{token} link on the organizer's
        // own host - a 404 by the RESERVED list above, and a 64-character
        // bearer token handed to whoever that host's DNS points at tomorrow.
        // The asset origin stays on THIS host so @vite and the Livewire
        // endpoint remain same-origin under script-src 'self'.
        URL::forceRootUrl((string) config('app.url'));
        URL::useAssetOrigin($request->getSchemeAndHttpHost());

        return $next($request);
    }
}
```

`app/Http/Middleware/RequireCustomDomain.php`
```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Conference;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware for the three root-level custom-domain routes. Two jobs:
 *
 * 1. Refuse when there is no resolved organization - which is what keeps
 *    /{conference} from answering on the platform host, where any unmatched
 *    single-segment path would otherwise reach it.
 * 2. Resolve {conference} THROUGH that organization and substitute the two
 *    real models into the route.
 *
 * The second is the cross-tenant guarantee. `(conference_id, slug)` is unique
 * per organization, not globally (spec section 8), so an implicit
 * {conference:slug} binding would resolve the first row in the database with
 * that slug and happily serve another society's meeting from this domain.
 * Looking it up through $organization->conferences() means no query exists
 * that could return one.
 */
class RequireCustomDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->attributes->get(ResolveCustomDomain::ATTRIBUTE);

        if (! $organization instanceof Organization) {
            abort(404);
        }

        $route = $request->route();

        if ($route === null) {
            abort(404);
        }

        $slug = $route->parameter('conference');

        if (is_string($slug)) {
            $conference = $organization->conferences()->where('slug', $slug)->first();

            if (! $conference instanceof Conference) {
                abort(404);
            }

            // The organization is already loaded; setting the inverse relation
            // is what keeps the page's 300 ms budget (spec section 10) from
            // paying for a second query on every route() call in the view -
            // the same reason ConferenceController::show() does it.
            $conference->setRelation('organization', $organization);

            // ORDER MATTERS. ControllerDispatcher calls the method with
            // array_values($route->parameters()), and ResolvesRouteDependencies
            // skips a parameter whose class is already in that array - so a
            // plain setParameter('organization', ...) would append it AFTER
            // {conference} and call show(Conference, Organization), a TypeError
            // on every conference page on every custom domain. Forget the URI
            // parameter, then set both in the signature's order.
            $route->forgetParameter('conference');
            $route->setParameter('organization', $organization);
            $route->setParameter('conference', $conference);
        }

        return $next($request);
    }
}
```

`bootstrap/app.php` — append inside the existing `withMiddleware` closure, **after** the `SecurityHeaders` line and **before** `trustProxies`:

```php
        // Before ResolveCustomDomain, not after: Illuminate\Routing\Pipeline
        // catches an abort() at the pipe that threw and returns the rendered
        // response UPWARD, so anything appended after that middleware never
        // runs for a refused host or a reserved path - and those 404s are
        // documents a browser renders, with @vite tags in them. Minting the
        // nonce first also means it exists before any view is compiled.
        // (Task 7 adds this line; it is shown here so the order is decided in
        // one place rather than twice.)
        $middleware->append(ContentSecurityPolicy::class);

        // Global, not a group: this has to run before routing, so that a
        // request for /about on a custom domain never reaches the route that
        // serves the platform's about page (fact 15).
        $middleware->append(ResolveCustomDomain::class);
```

and replace the `trustHosts` line:

```php
        // Spec 5.8 and the Plan 1 backlog item: a verified custom domain has
        // to be a trusted host or TrustHosts answers 400 before any route
        // runs. The platform's own host is first and unconditional, so a
        // database failure inside verifiedHosts() (which returns [] and
        // reports) leaves the main site working.
        //
        // TrustHosts entries are PATTERNS: Symfony wraps each one as `{...}i`
        // and matches it UNANCHORED (Request::setTrustedHosts), so a bare
        // `abstracts.example.org` would also trust
        // `abstracts.example.org.evil.test` and would treat every dot as "any
        // character". Laravel's own default anchors and preg_quote()s for
        // exactly this reason; so does this. (verifiedHosts() still returns
        // plain hosts - only the closure's output is a pattern list, and
        // preg_quote() already escapes `{` and `}`, so no delimiter argument is
        // needed.)
        $middleware->trustHosts(at: fn (): array => array_map(
            static fn (string $host): string => '^'.preg_quote($host).'$',
            [
                parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
                ...CustomDomains::verifiedHosts(),
            ],
        ), subdomains: false);
```

with `use App\Http\Middleware\ContentSecurityPolicy;`, `use App\Http\Middleware\ResolveCustomDomain;` and `use App\Support\Domains\CustomDomains;` added to the imports.

**Read the comment already on that file before you touch it.** `bootstrap/app.php:29-34` explains that `env()` is used rather than `config()` because the config repository does not exist when this file runs. That is true of the *arguments* evaluated eagerly — `trustProxies(at: …)` takes a value. The `trustHosts` closure is **lazy**: Laravel calls it per request from `TrustHosts::hosts()`, long after the config and the container are up, which is why the existing line already calls `config('app.url')` inside it and why `CustomDomains::verifiedHosts()` (which uses the cache and the database) is safe there and only there.

- [ ] **Step 4: The controller and the routes**

`app/Http/Controllers/Public/CustomDomainController.php`
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCustomDomain;
use App\Models\Conference;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomDomainController extends Controller
{
    /**
     * GET / on a verified custom domain.
     *
     * One publicly visible conference -> go there. Zero or several -> 404.
     * A listing page is a feature spec 5.8 does not ask for, and "the newest"
     * would silently send visitors to the wrong meeting in exactly the week a
     * society runs two calls at once.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $organization = $request->attributes->get(ResolveCustomDomain::ATTRIBUTE);

        abort_unless($organization instanceof Organization, 404);

        $conferences = $organization->conferences()
            ->get()
            ->filter(static fn (Conference $conference): bool => $conference->isPubliclyVisible());

        abort_unless($conferences->count() === 1 && $organization->isApproved(), 404);

        /** @var Conference $conference */
        $conference = $conferences->first();

        return redirect()->to($conference->publicUrl());
    }
}
```

`routes/web.php` — append **at the very end of the file**, after the invitation route, with this comment:

```php
// --- Verified custom domains (spec 5.8) -------------------------------------
//
// LAST in the file on purpose. `/{conference}` is a single-segment catch-all,
// and Laravel matches routes in registration order, so every platform route
// above wins on the platform host. On a *custom* domain the platform paths
// never get this far: ResolveCustomDomain (global, before routing) answers 404
// for each of them.
//
// {conference} is a plain string, NOT an implicit {conference:slug} binding.
// (conference_id, slug) is unique per organization, not globally, so implicit
// binding would resolve the first matching row in the database and serve
// another society's meeting from this domain. RequireCustomDomain resolves it
// through $organization->conferences() and substitutes both models.
//
// /s/{token}, /files/{ulid} and /q/{code} are deliberately NOT here: a status
// token is a bearer credential, a file URL is a signature bound to its host,
// and a short code is printed on posters that outlive a domain registration.
// All three stay on APP_URL's host, which is where route() puts them.
//
// RequireCustomDomain resolves {conference} THROUGH the resolved organization
// and sets both route parameters to real models. It is ROUTE middleware, and
// SubstituteBindings sits in the `web` GROUP and is in Kernel::$middlewarePriority
// while this one is not - so without the exclusion SubstituteBindings runs
// FIRST, ImplicitRouteBinding resolves the plain slug against
// Conference::getRouteKeyName() (the ULID) and throws ModelNotFoundException:
// a 404 before this middleware is ever reached. Excluding it is safe precisely
// because this group does its own binding, and Livewire re-runs
// substituteImplicitBindings() at mount (Drawer/ImplicitRouteBinding.php:83),
// where both parameters are already models and are skipped.
Route::middleware(RequireCustomDomain::class)
    ->withoutMiddleware(SubstituteBindings::class)
    ->group(function (): void {
        Route::get('/', CustomDomainController::class)->name('custom-domain.home');

        Route::get('/{conference}', [ConferenceController::class, 'show'])
            ->where('conference', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('custom-domain.conference.show');

        Route::get('/{conference}/submit', SubmissionForm::class)
            ->where('conference', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('custom-domain.conference.submit');
    });
```

with `use App\Http\Controllers\Public\CustomDomainController;`, `use App\Http\Middleware\RequireCustomDomain;` and `use Illuminate\Routing\Middleware\SubstituteBindings;` added to the imports.

**`Route::get('/', …)` is registered twice on purpose, and the landing route needs a host constraint for it to work.** Laravel matches in registration order, so without one the `landing` route at `routes/web.php:17` wins on every host and a verified custom domain's root would show the CASS landing page instead of the organizer's conference. The fix is one line moved: give `landing` an explicit `->domain()` (below), and **keep** `Route::get('/', CustomDomainController::class)->name('custom-domain.home')` in the group above. Do **NOT** invoke a controller from `ResolveCustomDomain` — a middleware that calls a controller is worse than the problem it solves — and do **not** move the landing route below the group, which would reorder a file every other plan depends on.

So, in `routes/web.php`, change line 17 from

```php
Route::view('/', 'public.landing')->name('landing');
```

to

```php
// The platform landing page. It carries an explicit host constraint because
// the custom-domain group at the bottom of this file also registers `/`, and
// Laravel matches in registration order: without ->domain() this route would
// win on every host and a verified custom domain's root would show the CASS
// landing page instead of the organizer's conference.
Route::view('/', 'public.landing')
    ->domain((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'))
    ->name('landing');
```

`Route::domain()` takes a literal host and Laravel compiles it into the route's host pattern, so this route now matches only on the platform host and the custom-domain group's `/` matches everywhere else. **`route('landing')` keeps working** — a domain-constrained route generates an absolute URL with that host (and with the current request's port, `RouteUrlGenerator::addPortToDomain()`), which is what every `route('landing')` call site already expects.

`route:cache` handles `->domain()` correctly; Task 14 Step 3's smoke assertion re-checks `/` inside the built image under `route:cache`.

**This constraint means every request whose `Host` is not `parse_url(APP_URL, PHP_URL_HOST)` misses the landing route — and `ResolveCustomDomain` 404s it as an unknown host.** Two places in this repository send a different one today: `php artisan serve`, reached on `127.0.0.1:8000`, and the **browser suite**, which serves the application on `127.0.0.1`. `vendor/pestphp/pest-plugin-browser/src/ServerManager.php:28` is `DEFAULT_HOST = '127.0.0.1'` and `Drivers/LaravelHttpServer.php:264-271` rewrites the `Host` header only when `Playwright::host()` is non-null — it is null unless something calls `setHost()`. `.env` and `.env.example` carry `APP_URL=http://localhost`, so under `phpunit.browser.xml` every request would arrive with a host that is neither `DomainName::platformHost()` nor a verified domain: the existing `tests/Browser/SubmitAbstractTest.php` and every case Task 7 adds would 404. **The fix belongs in the browser config, not in the middleware** — add to the `<php>` block of `phpunit.browser.xml`:

```xml
        <!-- The one line in this block that deliberately diverges from
             phpunit.xml. The plugin serves the application on 127.0.0.1
             (ServerManager.php:28) and that is the Host Laravel sees, so
             APP_URL's host has to be the same name: otherwise
             ResolveCustomDomain 404s every browser request and the
             ->domain() constraint on `landing` matches nothing. -->
        <env name="APP_URL" value="http://127.0.0.1"/>
```

`->domain()` is matched against `$request->getHost()`, which excludes the port, so the plugin's random port is irrelevant. Add `phpunit.browser.xml` to this task's **Files: Modify** list, and run the browser suite at the end of Task 3, **before** Task 7 adds three more cases to it:

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pest --configuration=phpunit.browser.xml > /tmp/t-browser-task-3.log 2>&1; echo "browser rc=$?"; tail -12 /tmp/t-browser-task-3.log
```

Expected: `rc=0`, with `tests/Browser/SubmitAbstractTest.php` unedited. A 404 here after Task 7 lands must never be mistaken for a CSP failure — this run is what separates the two.

One consequence to record in Task 13's runbook: `php artisan serve` answers on `127.0.0.1:8000`, which is not `APP_URL`'s host locally, so `ResolveCustomDomain` now 404s a developer who opens `http://127.0.0.1:8000`. Use `http://localhost:8000`.

- [ ] **Step 5: `Conference::publicUrl()`, `publicSubmitUrl()`, and their call sites**

`app/Models/Conference.php` — replace `publicUrl()` (`:652-658`) and add its sibling:

```php
    /**
     * Where the public conference page lives. The organization's verified
     * custom domain when it has one (spec 5.8: "serves the conference without
     * the /c/{org} prefix"), the platform URL otherwise.
     *
     * Not route('custom-domain.conference.show'): that route has no host
     * constraint and route() would build it on APP_URL's host, which is the
     * one host this branch exists to avoid.
     */
    public function publicUrl(): string
    {
        $base = $this->organization->customDomainUrl();

        if ($base !== null) {
            return $base.'/'.$this->slug;
        }

        return route('conference.show', [
            'organization' => $this->organization,
            'conference' => $this,
        ]);
    }

    /** The submission form, on the same host publicUrl() chose. */
    public function publicSubmitUrl(): string
    {
        $base = $this->organization->customDomainUrl();

        if ($base !== null) {
            return $base.'/'.$this->slug.'/submit';
        }

        return route('conference.submit', [
            'organization' => $this->organization,
            'conference' => $this,
        ]);
    }
```

Now find every place that builds one of those two URLs by hand and change it:

```bash
cd /c/Users/ahmed/Documents/CASS && grep -rn "route('conference\.\(show\|submit\)'" app/ resources/ --include=*.php --include=*.blade.php
```

That grep returns **eight** hits, not three. Every one of them, with the line numbers as they are on `main` today:

| Where | Becomes |
|---|---|
| `resources/views/public/partials/submit-cta.blade.php:12` (and the `route('conference.submit', …)` in the header comment at `:4`) | `{{ $conference->publicSubmitUrl() }}` |
| `resources/views/livewire/public/submission-form.blade.php:43` | `{{ $conference->publicUrl() }}` — the closed-window back-link on the submit page itself |
| `app/Livewire/Public/SubmissionForm.php:349` (draft-updated redirect, token null) | `$this->conference->publicUrl()` |
| `app/Livewire/Public/SubmissionForm.php:383` (draft-saved redirect fallback) | `$this->conference->publicUrl()` |
| `app/Livewire/Public/SubmissionForm.php:448` (post-submit redirect, token null) | `$this->conference->publicUrl()` |
| `app/Livewire/Public/SubmissionForm.php:724` (`handledAsBot()`) | `$this->conference->publicUrl()` |
| `app/Models/Conference.php:654` | the method being rewritten above; leave it |
| anything in `app/Actions/` or `app/Filament/` | `$conference->publicUrl()` — these are the emails and the panel, and an author following a link from an email should land on the organizer's own domain |

**The grep is the contract, not this table**: every hit outside `Conference::publicUrl()`/`publicSubmitUrl()` must become one of the two methods, or an author on a verified domain is bounced to `cass.towardpcc.com` by a redirect the moment they save — and the three the old table omitted (`:349`, `:448` and the blade back-link) are exactly the ones that fire mid-flow, where it is least visible.

**Leave `Conference::publicUrl()`'s two existing callers alone**: `app/Filament/Admin/Resources/Conferences/ConferenceResource.php:68` and `:70` already call the method and now follow the domain for free.

Also update the header comment at the top of `submit-cta.blade.php` — it currently claims "Plan 3 replaces exactly one thing in this file… Nothing else here changes", which Task 12 falsifies again. Replace it with one sentence naming Plans 3, 6 and what each changed.

- [ ] **Step 6: The canonical and Open Graph URLs on the conference layout**

`resources/views/components/layouts/conference.blade.php` — inside `<head>`, after the `<meta name="description">` line (`:8`):

```blade
    {{-- The canonical URL is the organization's own domain when they have one
         (spec 5.8), so a conference that is reachable on two hosts tells a
         search engine which one is the page. --}}
    <link rel="canonical" href="{{ $conference->publicUrl() }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $conference->publicUrl() }}">
    <meta property="og:title" content="{{ $conference->name }}">
    <meta property="og:description" content="{{ Str::limit((string) $conference->short_description, 155) }}">
    <meta property="og:site_name" content="{{ $organization->name }}">
    @if ($organization->logo_path)
        {{-- The organization's logo, because it is the one image on this page
             that is theirs. The QR poster would be a better share card and is
             a render behind an authenticated route; the backlog keeps it. --}}
        <meta property="og:image" content="{{ Storage::disk('branding')->url($organization->logo_path) }}">
    @endif
    <meta name="twitter:card" content="summary">
```

The `og:image` half of the backlog's item is **partly** done here — the logo, not the poster — and Task 14 rewrites that backlog entry to say exactly that rather than deleting it.

**The footer's Contact, Privacy and Terms links need no change, and here is why.** `resources/views/components/layouts/conference.blade.php:46-48` renders `route('contact')`, `route('privacy')` and `route('terms')`, and `route()` builds on the current request root — which Step 3 has already pinned to `APP_URL` with `URL::forceRootUrl()`. So on `abstracts.example.org` they come out as `https://cass.towardpcc.com/privacy`, which is where they live; without that pin they would be dead links to the reserved 404s on the organizer's own host, on the page that collects author names, addresses and phone numbers. `route('landing')` on `:44` is safe for the same reason, plus the `->domain()` constraint Step 4 gives it. **Do not hardcode `config('app.url').'/privacy'` here** — that duplicates the route table and breaks silently if a path moves. The `links privacy, terms and contact at the platform host` case in `CustomDomainRoutingTest` is what stops a later refactor from removing the pin quietly.

- [ ] **Step 7: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/CustomDomainsTest.php tests/Feature/Public/CustomDomainRoutingTest.php tests/Feature/Public/ConferencePageTest.php tests/Feature/Public/LandingPageTest.php tests/Feature/Public/TrustedProxyTest.php > /tmp/t-task-3.log 2>&1; echo "rc=$?"; tail -12 /tmp/t-task-3.log && \
php artisan test > /tmp/all-task-3.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-3.log
```

Expected: both `rc=0`; `baseline + 72`. That number includes the cases this task adds beyond the routing happy path, each of which fails for a different reason if a step was skipped: `CustomDomainsTest`'s `leaves a suspended organization out of the trusted list`, `produces anchored, quoted trusted-host patterns`, `puts the platform host first and every verified domain after it in the trusted-host list`, `still trusts the platform host when the domain lookup throws` and the un-skipped `answers an empty list rather than throwing when the lookup fails`; and `CustomDomainRoutingTest`'s `substitutes the two models in the order the controller declares them`, `mints status, file and invitation urls on the platform host…`, `links privacy, terms and contact at the platform host from a custom domain`, `keeps the submit page's own links on the custom domain`, and the five percent-encoded rows in the reserved-path dataset.

**Three suites are in that first run for a reason.** `ConferencePageTest` asserts the public page renders and is the thing a changed `publicUrl()` breaks first. `LandingPageTest` is the one that fails if `->domain()` on the landing route was spelled wrong — a domain-constrained route stops matching a request made with the test harness's default host (`localhost`) unless `config('app.url')` agrees, which is why `phpunit.xml` has no `APP_URL` and the `.env` copied in CI does. **If `LandingPageTest` goes red with a 404, the fix is in the test's host, not in the route**: make the landing assertions request `config('app.url').'/'`. The same trap hits the **browser** suite, which serves the app from `127.0.0.1` — that one is pinned by the `APP_URL` line Step 4 adds to `phpunit.browser.xml`, and the browser run at the end of Step 4 is what proves it. `TrustedProxyTest` is the closest thing this repo has to a `TrustHosts` *middleware* test; the `trustHosts` closure itself is asserted directly by `CustomDomainsTest`'s two `TrustHosts::hosts()` cases, because `TrustHosts` is a no-op under `runningUnitTests()` and a closure that forgot `verifiedHosts()` would otherwise be HTTP 400 on every custom domain in production with a green suite.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan route:list --except-vendor | grep -E "custom-domain|landing"
```

Expected: `landing` **with** a host of `cass.towardpcc.com` (or whatever `APP_URL` is locally), and **three** `custom-domain.*` routes with no host constraint — `custom-domain.home`, `custom-domain.conference.show` and `custom-domain.conference.submit`.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan route:list --except-vendor --columns=uri,middleware | grep custom-domain
```

Expected: **no** `SubstituteBindings` on any of the three `custom-domain.*` rows. `Router::gatherRouteMiddleware()` applies `excludedMiddleware()` after the group is expanded, so the column reflects the real stack — and if `SubstituteBindings` is still there, every conference page on every custom domain is a 404 from `ModelNotFoundException`, not a middleware answer.

```bash
cd /c/Users/ahmed/Documents/CASS && \
  grep -rn "route('conference\.\(show\|submit\)'" app/ resources/ --include=*.php --include=*.blade.php \
  | grep -v "app/Models/Conference.php" \
  && echo 'STOP: a /c/{org} URL is still built by hand' || echo 'ok: publicUrl() is the only builder'
```

Expected: `ok: publicUrl() is the only builder`. The two hits inside `Conference::publicUrl()` and `publicSubmitUrl()` are the *definitions* and are excluded by name; anything else is a call site that will send an author on a verified domain back to `cass.towardpcc.com`.

- [ ] **Step 8: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all-task-3.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(domains): a verified domain serves its own conferences, and nothing else

The conference is resolved through the organization rather than by implicit
binding, because (conference_id, slug) is unique per organization and not
globally - an implicit {conference:slug} would serve another society's meeting
from this domain. Every platform path 404s on a custom domain; livewire and
/up do not, because the submission form posts to the first.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---

### Task 4: The hard purge — a conference-scoped variant, a preview, and the typed-confirmation screens

Spec section 3: *"Deleting a conference is soft-delete only; hard purge is a platform-admin action that cascades in application code."* Five backlog entries have been waiting for this, each adding tables to the list — Plan 2's original note, Plan 3's files, Plan 4's reviews, answers, assignments and reviewer rows, Plan 5's decision history.

**Read this first: half of this task may already exist.** While this plan was being written, the `demo-seed` branch landed `App\Actions\Organizations\PurgeOrganization` — a general, tested purge of one tenant's whole tree in constraint order, inside a transaction, with the private-disk objects removed after the commit — as the engine behind `cass:demo-reset`, and wrote the remainder into the backlog itself:

> **`organizations.is_demo` — hard purge groundwork: `PurgeOrganization` exists; Plan 6 exposes it to the platform admin.** … What Plan 6 still owes it: a `ForceDeleteAction` on the admin `OrganizationResource` behind a typed-name confirmation, a count-per-table preview shown *before* the delete rather than after it (the action already returns exactly that array), a conference-scoped variant for "purge one conference of a tenant that keeps its others", and a decision about `activity_log` … `organizations.is_demo` itself is not fillable and only the seeder writes it; a platform-admin purge must **not** gate on that flag, and must find its own confirmation instead.

So this task has two shapes and **Step 1 decides which**. Everything below assumes the class exists; if it does not, Step 1 says what to do instead.

**Five decisions this task makes.**

**1. The conference-scoped purge is the *same* code, not a second copy of the order.** Eleven foreign keys are `RESTRICT` (fact 9) and four tables have no key at all, so the order is the one thing that must not exist twice. `PurgeConference` gets the conference subtree; `PurgeOrganization` is refactored so that its transaction body *calls* `PurgeConference`'s row work per conference and then deletes the organization's own rows. **`tests/Feature/Console/DemoResetTest.php` must stay green, unedited** — that is the proof the refactor was behaviour-preserving, exactly the way Plan 5 proved the `ExportSubmissionsCsv::guard()` move.

**2. The disk deletion stays outside the transaction, and `PurgeConference` therefore has two entry points.** `PurgeOrganization`'s docblock already argues the rule, and `DeleteSubmissionFile`'s argues it harder: *"removing an object first and rolling the rows back leaves live rows whose bytes are gone, and no rollback can put a deleted object back."* A nested `DB::transaction` is a savepoint that rolls back with its parent, while a `Storage::delete` inside it has already run — so `PurgeConference::rows()` deletes rows and **returns the paths**, and `PurgeConference::handle()` is the public entry that wraps `rows()` in a transaction and then deletes the objects. `PurgeOrganization` calls `rows()` from inside its own transaction and does the disk pass itself, once, at the end.

**3. `activity_log` is left alone, deliberately, and the backlog's open question is answered here.** `PurgeOrganization`'s docblock already says *"the audit trail of who deleted what is the last thing a purge should erase"*, and the purge now writes its own entry into that same table. The counter-argument the backlog raises is real: `properties` carries invitee email addresses, decision notes and member roles, so a GDPR erasure request is not satisfied by this purge. But an erasure request is about a **person**, not a tenant: deleting every row whose *subject* was a purged record still leaves every row where that person was the *causer* somewhere else, and a half-erasure that looks complete is worse than a documented manual step. **Decision: the purge does not touch `activity_log`; Task 13's runbook carries the erasure query, and Task 14 records it as decided with this reasoning.**

**4. `email_logs` rows are deleted with the tenant, and that stays.** The existing class deletes them, with the argument that *"leaving them would turn a tenant's whole mail history into unattributed rows in the admin panel rather than removing it"* — all three foreign keys are `nullOnDelete`, so the alternative is not "kept" but "kept and anonymised", which is the worst of both. `PurgeConference` follows the same rule, scoped: the rows pointing at *this* conference or its submissions go; a row pointing only at the organization stays until the organization is purged.

**5. There is no `OrganizationPolicy` (fact 11), and this task writes one without a `before()`.** Every ability is spelled out — the `EmailLogPolicy` shape — because Filament treats a *missing* policy method as ALLOW. `delete`, `deleteAny`, `restore`, `restoreAny`, `forceDelete` and `forceDeleteAny` answer **false for everybody, platform admin included**, which is the only way to keep Filament's own `ForceDeleteAction` from ever appearing and running `$record->forceDelete()` straight into the `RESTRICT` key on `conferences.organization_id`. The purge gets its own ability, `purge`. `ConferencePolicy` already has a `before()` that answers `true` for a platform admin and therefore already answers `forceDelete` — which is what its own docblock warns about (fact 12) — so this task adds `ConferencePolicy::purge()` for the same explicitness and rewrites that warning rather than deleting it.

**Files:**
- Create: `app/Actions/Conferences/PurgeConference.php`, `app/Support/Purge/PurgeCounts.php`, `app/Policies/OrganizationPolicy.php`
- Modify: `app/Actions/Organizations/PurgeOrganization.php` (delegate; optional `$actor`; `preview()`)
- Modify: `app/Policies/ConferencePolicy.php`
- Modify: `app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php`, `.../Organizations/Tables/OrganizationsTable.php`, `.../Conferences/Pages/ViewConference.php`, `.../Organizations/Pages/ViewOrganization.php`
- Create: `lang/en/admin.php`, `resources/views/filament/admin/partials/purge-counts.blade.php`
- Test: `tests/Unit/PurgeConferenceTest.php`, `tests/Feature/Admin/PurgeTest.php`

- [ ] **Step 1: Read what is already there**

```bash
cd /c/Users/ahmed/Documents/CASS && \
ls app/Actions/Organizations/PurgeOrganization.php app/Actions/Demo/ 2>/dev/null && \
sed -n '1,80p' app/Actions/Organizations/PurgeOrganization.php && \
grep -n "public function handle\|private function\|counts\[" app/Actions/Organizations/PurgeOrganization.php && \
grep -rn "PurgeOrganization" app/ tests/ --include=*.php && \
php artisan list 2>/dev/null | grep -E "cass:"
```

**If `PurgeOrganization` exists** (the expected case): read the whole class and the whole of `tests/Feature/Console/DemoResetTest.php` before writing anything. Everything below is written against the class as the demo branch shipped it: `final class PurgeOrganization`, `handle(Organization $organization): array` returning `array<string, int>` keyed by table with a `'private files'` entry appended, ids read up front with a private `ids(Builder $query): array<int>` helper, short links found by a private `shortLinks(Organization, array $conferenceIds): Builder`, one `DB::transaction` and a disk pass after it. **If any of that has changed, follow the code** and adjust the refactor below to it.

**If `PurgeOrganization` does NOT exist**, the demo branch has not merged. Write it, and write `PurgeConference` first: the order in the table below is complete for both, the two classes below are complete as written, and `PurgeOrganization::handle()` becomes "for each conference `PurgeConference::rows()`, then the organization's own rows, then the disk pass" with the same body the refactor produces. Nothing else in this task changes, except that `DemoResetTest` is not there to stay green and Step 8's run has one fewer file in it.

**Also record one thing for Task 9.** If `php artisan list | grep cass:` prints `cass:rescore` and `cass:reviewer-reminders` but **not** `cass:demo-seed` / `cass:demo-reset`, those two commands exist in `app/Console/Commands` but are not in `bootstrap/app.php`'s `->withCommands([...])` array — which registers classes by name and does **not** scan the directory (fact 32). That is the demo branch's business, not this plan's: **do not fix it here**. Note it, and Task 14 Step 4 adds one backlog line so it is not lost.

The order this task's two classes implement, and the reason for each position:

| # | Rows removed | Why here |
|---|---|---|
| 1 | `review_answers` | `review_question_id` is RESTRICT onto `review_questions` (step 9) |
| 2 | `reviews` | RESTRICT onto `submissions`, `users` **and** `review_forms` — three parents, all later |
| 3 | `submission_decisions` | RESTRICT onto `submissions` |
| 4-6 | `review_assignments`, `submission_files`, `submission_authors` | cascade, but deleted explicitly so the preview can count them; the file **paths** are collected before the rows go |
| 7 | `email_logs` for this conference and its submissions | before the rows they point at, because all three keys are `nullOnDelete` and the alternative is anonymised rows, not absent ones |
| 8 | `reviewer_reminders`, `conference_reviewers`, `reviewer_invitations`, `email_templates` | cascade, counted |
| 9 | `review_questions` | RESTRICT onto `review_forms`; **must** be a query-builder delete (fact 10) |
| 10 | `review_forms` | RESTRICT onto `conferences` |
| 11 | `custom_fields`, `tracks` | RESTRICT onto `conferences`; `submissions.custom_field_values` is a JSON column and `submissions.track_id` is nullOnDelete, so the order against step 13 is free |
| 12 | `short_link_visits`, `short_links` | **no foreign key at all** — nothing removes these, and `unique(target_type, target_id)` would refuse a conference re-created at the same id |
| 13 | `submissions` | `withTrashed()->forceDelete()`: the table soft-deletes, so `delete()` would leave every row and make every RESTRICT above pointless. The **one** table inside the subtree that is not a line in `queries()` — steps 2 and 3 are RESTRICT onto it, so it cannot go earlier, and step 14 is RESTRICT onto step 13, so it cannot go later |
| 14 | `conferences` | `withTrashed()->forceDelete()` |
| — | `activity_log` | **untouched**, decision 3 |

- [ ] **Step 2: Write the failing tests**

`tests/Unit/PurgeConferenceTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Conferences\PurgeConference;
use App\Actions\Organizations\PurgeOrganization;
use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\ReviewStatus;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\CustomField;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\ReviewerInvitation;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\ShortLink;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\SubmissionDecision;
use App\Models\SubmissionFile;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * One conference carrying a row in every table the purge walks. Returns it.
 *
 * The locked review form is the point: ReviewQuestion::deleting throws
 * ReviewFormLocked for a locked form even after every answer is gone, and that
 * is the single most likely way a first draft of this action blows up.
 */
function conferenceWithEverything(?Organization $organization = null): Conference
{
    $conference = Conference::factory()
        ->for($organization ?? Organization::factory()->approved()->create())
        ->create(['status' => ConferenceStatus::Reviewing]);

    $form = ReviewForm::factory()->for($conference)->create(['is_active' => true]);
    $question = ReviewQuestion::factory()->for($form)->create(['scale_min' => 1, 'scale_max' => 5]);
    Track::factory()->for($conference)->create();
    CustomField::factory()->for($conference)->create();
    EmailTemplate::factory()->for($conference)->create();
    ReviewerInvitation::factory()->for($conference)->create();
    ShortLink::forTarget($conference);

    $reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($conference)->create(['user_id' => $reviewer->getKey()]);

    $submission = Submission::factory()->for($conference)->submitted()->create();
    SubmissionAuthor::factory()->for($submission)->create();
    SubmissionFile::factory()->for($submission)->create(['path' => 'ab/'.strtolower((string) str()->ulid()).'.pdf']);
    ReviewAssignment::factory()->create([
        'submission_id' => $submission->getKey(),
        'reviewer_user_id' => $reviewer->getKey(),
    ]);
    SubmissionDecision::factory()->for($submission)->create(['decision' => Decision::AcceptedOral]);

    $review = Review::factory()->create([
        'submission_id' => $submission->getKey(),
        'reviewer_user_id' => $reviewer->getKey(),
        'review_form_id' => $form->getKey(),
        'status' => ReviewStatus::Submitted,
        'submitted_at' => now(),
    ]);
    (new ReviewAnswer)->forceFill([
        'review_id' => $review->getKey(),
        'review_question_id' => $question->getKey(),
        'value_int' => 4, 'value_text' => null, 'value_bool' => null, 'choice_key' => null,
    ])->save();

    $form->forceFill(['locked_at' => now()])->save();

    // A soft-deleted abstract. A purge that only walks the visible ones leaves
    // rows behind and the RESTRICT key then refuses the conference itself.
    Submission::factory()->for($conference)->submitted()->create()->delete();

    return $conference->refresh();
}

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->platformAdmin()->create();
});

it('removes every row of one conference and touches nothing of the next', function () {
    $organization = Organization::factory()->approved()->create();
    $doomed = conferenceWithEverything($organization);
    $survivor = conferenceWithEverything($organization);

    $counts = app(PurgeConference::class)->handle($doomed, $this->admin);

    expect(Conference::withTrashed()->whereKey($doomed->getKey())->exists())->toBeFalse();

    foreach (['tracks', 'custom_fields', 'review_forms', 'email_templates', 'reviewer_invitations', 'conference_reviewers', 'reviewer_reminders'] as $table) {
        expect(DB::table($table)->where('conference_id', $doomed->getKey())->count())->toBe(0, $table);
    }

    expect(Submission::withTrashed()->where('conference_id', $doomed->getKey())->count())->toBe(0)
        // Exactly one of each is left: the survivor's.
        ->and(DB::table('review_questions')->count())->toBe(1)
        ->and(DB::table('reviews')->count())->toBe(1)
        ->and(DB::table('review_answers')->count())->toBe(1)
        ->and(DB::table('submission_decisions')->count())->toBe(1)
        ->and(DB::table('submission_authors')->count())->toBe(1)
        ->and(DB::table('submission_files')->count())->toBe(1)
        ->and(DB::table('review_assignments')->count())->toBe(1)
        // No foreign key reaches short_links, so this assertion is the only
        // thing that would notice them being forgotten.
        ->and(ShortLink::query()->where('target_id', $doomed->getKey())->count())->toBe(0)
        ->and(ShortLink::query()->count())->toBe(1);

    // And the organization itself is untouched: this is the whole point of a
    // conference-scoped variant.
    expect(Organization::whereKey($organization->getKey())->exists())->toBeTrue()
        ->and(Conference::whereKey($survivor->getKey())->exists())->toBeTrue()
        ->and($survivor->submissions()->count())->toBe(1);

    expect($counts['submissions'])->toBe(2)
        ->and($counts['reviews'])->toBe(1)
        ->and($counts['review_answers'])->toBe(1)
        ->and($counts['conferences'])->toBe(1)
        ->and($counts['private files'])->toBe(1);
});

it('deletes the file objects from the private disk, after the rows', function () {
    $conference = conferenceWithEverything();

    $path = (string) SubmissionFile::query()
        ->whereIn('submission_id', $conference->submissions()->withTrashed()->pluck('id'))
        ->value('path');

    Storage::disk('local')->put($path, 'pdf bytes');
    Storage::disk('local')->assertExists($path);

    app(PurgeConference::class)->handle($conference, $this->admin);

    // Otherwise cass-storage keeps objects nothing references, for ever.
    Storage::disk('local')->assertMissing($path);
});

it('survives a locked review form', function () {
    $conference = conferenceWithEverything();

    expect($conference->reviewForm()->first()?->isLocked())->toBeTrue();

    app(PurgeConference::class)->handle($conference, $this->admin);

    expect(DB::table('review_questions')->count())->toBe(0);
});

it('removes the email log rows of this conference and leaves the tenant-level ones', function () {
    $conference = conferenceWithEverything();
    $submission = $conference->submissions()->firstOrFail();

    // The factory, not `new EmailLog` + forceFill: email_logs.mailable and
    // email_logs.subject are NOT NULL with no default
    // (2026_09_11_001500_create_email_logs_table.php:29-31) and EmailLog::booted()
    // fills only `ulid`, so a hand-built row is a NOT NULL violation on both
    // SQLite and MySQL before the purge is ever called. Factory::make() wraps
    // creation in Model::unguarded(), so `$guarded = ['*']` is no obstacle.
    $scoped = EmailLog::factory()->create([
        'organization_id' => $conference->organization_id,
        'conference_id' => $conference->getKey(),
        'submission_id' => $submission->getKey(),
        'to_email' => 'author@example.org',
    ]);

    $tenantOnly = EmailLog::factory()->create([
        'organization_id' => $conference->organization_id,
        'to_email' => 'owner@example.org',
    ]);

    app(PurgeConference::class)->handle($conference, $this->admin);

    // All three keys are nullOnDelete, so "keep" would mean "keep and
    // anonymise", which is the worst of both. A row that is only the
    // organization's survives until the organization is purged.
    expect(EmailLog::query()->whereKey($scoped->getKey())->exists())->toBeFalse()
        ->and(EmailLog::query()->whereKey($tenantOnly->getKey())->exists())->toBeTrue();
});

it('leaves the activity log alone and adds one entry of its own', function () {
    $conference = conferenceWithEverything();
    activity()->performedOn($conference)->log('conference.published');

    app(PurgeConference::class)->handle($conference, $this->admin);

    // Decided: a purge does not erase the audit trail. The rows dangle -
    // activity_log's morphs are nullable and carry no foreign key - and an
    // erasure request is a person-shaped problem with a documented manual
    // query in the runbook, not a tenant-shaped one.
    expect(Activity::query()->where('description', 'conference.published')->exists())->toBeTrue();

    $purge = Activity::query()->where('description', 'conference.purged')->latest('id')->first();

    expect($purge)->not->toBeNull()
        // Performed on the ORGANIZATION: the conference no longer exists.
        ->and($purge?->subject_type)->toBe(Organization::class)
        ->and($purge?->causer_id)->toBe($this->admin->getKey())
        ->and($purge?->getExtraProperty('conference_ulid'))->toBe($conference->ulid);
});

it('previews exactly what it would delete, and deletes nothing', function () {
    $conference = conferenceWithEverything();

    $preview = app(PurgeConference::class)->preview($conference);

    expect(Conference::whereKey($conference->getKey())->exists())->toBeTrue();

    $counts = app(PurgeConference::class)->handle($conference, $this->admin);

    // Every table the real run reported has to be in the preview with the same
    // number, or the confirmation is a guess with a table in it.
    foreach ($counts as $table => $count) {
        expect($preview[$table] ?? null)->toBe($count, $table);
    }
});

it('finds nothing on a second call', function () {
    $conference = conferenceWithEverything();

    app(PurgeConference::class)->handle($conference, $this->admin);

    // An operator retrying a half-finished purge is exactly the case this has
    // to survive rather than fatal on.
    expect(array_sum(app(PurgeConference::class)->handle($conference, $this->admin)))->toBe(0);
});

it('leaves the tenant purge answering the same numbers as before', function () {
    // The refactor's own guard, beside DemoResetTest: PurgeOrganization now
    // delegates the conference subtree to PurgeConference, and this asserts the
    // two halves still add up to one whole tenant.
    $organization = Organization::factory()->approved()->create();
    conferenceWithEverything($organization);
    conferenceWithEverything($organization);

    $counts = app(PurgeOrganization::class)->handle($organization, $this->admin);

    expect(Organization::withTrashed()->whereKey($organization->getKey())->exists())->toBeFalse()
        ->and($counts['conferences'])->toBe(2)
        ->and($counts['submissions'])->toBe(4)
        ->and($counts['reviews'])->toBe(2)
        ->and($counts['private files'])->toBe(2)
        ->and($counts['organizations'])->toBe(1);
});
```

`tests/Feature/Admin/PurgeTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Admin\Resources\Conferences\Pages\ListConferences as AdminListConferences;
use App\Filament\Admin\Resources\Organizations\Pages\ListOrganizations;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Policies\ConferencePolicy;
use App\Policies\OrganizationPolicy;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->platformAdmin()->create();
    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();
});

it('shows what a purge would destroy, and refuses the wrong word', function () {
    $conference = Conference::factory()->create(['name' => 'Alpha Annual Meeting', 'slug' => 'annual-meeting']);
    Submission::factory()->for($conference)->submitted()->count(3)->create();

    livewire(AdminListConferences::class)
        ->mountTableAction('purge', $conference)
        // The count is in the modal before anything is typed: a yes/no
        // confirmation for an irreversible cascade over fourteen tables is not
        // a confirmation.
        ->assertMountedActionModalSee('3')
        ->setTableActionData(['confirmation' => 'not-the-slug'])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['confirmation']);

    expect(Conference::whereKey($conference->getKey())->exists())->toBeTrue();
});

it('purges a conference when the slug is typed', function () {
    $conference = Conference::factory()->create(['slug' => 'annual-meeting']);
    Submission::factory()->for($conference)->submitted()->create();

    livewire(AdminListConferences::class)
        ->callTableAction('purge', $conference, ['confirmation' => 'annual-meeting'])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    expect(Conference::withTrashed()->whereKey($conference->getKey())->exists())->toBeFalse();
});

it('purges an organization, and its conferences with it', function () {
    $organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $conference = Conference::factory()->for($organization)->create();

    livewire(ListOrganizations::class)
        ->callTableAction('purge', $organization, ['confirmation' => (string) $organization->slug])
        ->assertHasNoTableActionErrors();

    expect(Organization::withTrashed()->whereKey($organization->getKey())->exists())->toBeFalse()
        ->and(Conference::withTrashed()->whereKey($conference->getKey())->exists())->toBeFalse();
});

it('does not gate on the demo flag', function () {
    // The backlog is explicit: "a platform-admin purge must NOT gate on that
    // flag, and must find its own confirmation instead". The typed slug is
    // that confirmation.
    $organization = Organization::factory()->approved()->create();

    livewire(ListOrganizations::class)
        ->callTableAction('purge', $organization, ['confirmation' => (string) $organization->slug])
        ->assertHasNoTableActionErrors();

    expect(Organization::withTrashed()->whereKey($organization->getKey())->exists())->toBeFalse();
})->skip(fn (): bool => ! \Illuminate\Support\Facades\Schema::hasColumn('organizations', 'is_demo'), 'The demo branch has not merged.');

it('offers no purge to an organization owner, and no force delete to anybody', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->approved()->create();
    $organization->addMember($owner, OrganizationRole::Owner);
    $conference = Conference::factory()->for($organization)->create();

    $organizationPolicy = app(OrganizationPolicy::class);
    $conferencePolicy = app(ConferencePolicy::class);

    expect($organizationPolicy->purge($this->admin, $organization))->toBeTrue()
        ->and($organizationPolicy->purge($owner, $organization))->toBeFalse()
        // forceDelete is false for EVERYBODY on an organization, platform
        // admin included, so Filament can never surface a ForceDeleteAction
        // that would run into the RESTRICT key on conferences.organization_id.
        ->and($organizationPolicy->forceDelete($this->admin, $organization))->toBeFalse()
        ->and($organizationPolicy->forceDeleteAny($this->admin))->toBeFalse()
        ->and($conferencePolicy->purge($owner, $conference))->toBeFalse();

    actingAs($owner);
    Filament::setCurrentPanel('admin');
    livewire(ListOrganizations::class)->assertForbidden();
});

it('never offers a filament force-delete or a bulk purge on either admin table', function () {
    Conference::factory()->create();
    Organization::factory()->approved()->create();

    foreach ([AdminListConferences::class, ListOrganizations::class] as $page) {
        $table = livewire($page)->instance()->getTable();

        // array_merge, not `+`: on two numerically-indexed arrays `+` silently
        // drops every bulk-action name whose index already exists.
        $names = array_merge(
            array_keys($table->getFlatActions()),
            array_keys($table->getFlatBulkActions()),
        );

        expect($names)->not->toContain('forceDelete')
            ->and($names)->not->toContain('forceDeleteAny')
            // Fourteen tables and no undo is not a thing to apply to a
            // checkbox selection.
            ->and($names)->not->toContain('purgeSelected');
    }
});
```

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/PurgeConferenceTest.php tests/Feature/Admin/PurgeTest.php > /tmp/t-task-4.log 2>&1; echo "rc=$?"; tail -25 /tmp/t-task-4.log
```

Expected: `rc=1`, naming `App\Actions\Conferences\PurgeConference`. **Failing-first gate.**

**Factories to check first.** `EmailTemplate`, `ReviewerInvitation`, `SubmissionAuthor`, `SubmissionFile`, `ReviewAssignment`, `CustomField` and `Track` factories are Plans 2-5 fixtures. `ls database/factories/` and adjust `conferenceWithEverything()` to what exists rather than adding factories this task does not otherwise need. `tests/Feature/Console/DemoSeedTest.php` builds the same shape and is the quickest reference for which ones are real.

- [ ] **Step 3: `PurgeConference`**

`app/Actions/Conferences/PurgeConference.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\CustomField;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\ReviewerInvitation;
use App\Models\ReviewerReminder;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\ShortLink;
use App\Models\ShortLinkVisit;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\SubmissionDecision;
use App\Models\SubmissionFile;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One conference and everything under it, removed for good, in application
 * code — the conference-scoped half of the purge the backlog asked Plan 6 for:
 * "purge one conference of a tenant that keeps its others".
 *
 * This is where the ORDER lives. App\Actions\Organizations\PurgeOrganization
 * calls rows() per conference rather than repeating it, because the order is
 * the one thing in this application that must not exist twice: eleven foreign
 * keys are RESTRICT and four tables carry no key at all, and a second copy
 * that drifts is a QueryException in a platform admin's face at the worst
 * possible moment.
 *
 * Every delete is a MASS delete through the query builder, so no model event
 * fires: nothing writes an activity entry per row, and ReviewQuestion's
 * `deleting` guard against a locked form does not refuse — a purge is exactly
 * the case that guard is not about, and a conference that ever had a submitted
 * review has a locked form for ever.
 *
 * activity_log is deliberately untouched. See the class docblock on
 * PurgeOrganization and Plan 6 Task 4's decision 3: the audit trail of who
 * deleted what is the last thing a purge should erase, and a GDPR erasure
 * request is a person-shaped problem with a documented manual query in the
 * runbook.
 */
final class PurgeConference
{
    /**
     * Delete the tree, then the objects, then write the audit entry.
     *
     * @return array<string, int> what went, keyed by table, plus 'private files'
     */
    public function handle(Conference $conference, User $actor): array
    {
        if (! Conference::withTrashed()->whereKey($conference->getKey())->exists()) {
            return [];
        }

        $organization = $conference->organization;
        $paths = [];

        $counts = DB::transaction(function () use ($conference, &$paths): array {
            [$counts, $paths] = $this->rows($conference);

            return $counts;
        });

        // After the commit, never inside it. DeleteSubmissionFile's docblock
        // is the argument: a live row whose bytes are gone is the state to
        // avoid, and an orphan object nothing references is the failure this
        // chooses instead. A nested transaction would be a savepoint that
        // rolls back while the Storage::delete already ran.
        foreach ($paths as $path) {
            Storage::disk('local')->delete($path);
        }

        $counts['private files'] = count($paths);

        if ($organization instanceof Organization) {
            // Performed on the organization: the conference is gone, and this
            // is the one record that the purge happened.
            activity()
                ->performedOn($organization)
                ->causedBy($actor)
                ->withProperties([
                    'conference_ulid' => (string) $conference->ulid,
                    'conference_name' => (string) $conference->name,
                    'rows' => $counts,
                ])
                ->log('conference.purged');
        }

        return $counts;
    }

    /**
     * What handle() would delete, without deleting it — the count-per-table
     * preview the confirmation modal shows *before* anything happens.
     *
     * It runs the same queries with ->count() instead of ->delete(), from the
     * same private list, and repeats rows()'s two explicit soft-deleting tables
     * in the same positions, so the two cannot describe different trees.
     *
     * @return array<string, int>
     */
    public function preview(Conference $conference): array
    {
        $ids = $this->collect($conference);
        $counts = [];

        foreach ($this->queries($conference, $ids) as $table => $query) {
            $counts[$table] = $query->count();
        }

        // By hand, exactly where rows() force-deletes it, so the two arrays
        // carry the same keys in the same order.
        $counts['submissions'] = Submission::withTrashed()->whereIn('id', $ids['submissions'])->count();
        $counts['conferences'] = Conference::withTrashed()->whereKey($conference->getKey())->count();
        $counts['private files'] = count($ids['paths']);

        return $counts;
    }

    /**
     * The rows, in order, INSIDE a caller's transaction. Returns the counts and
     * the paths whose objects the caller must delete after it commits.
     *
     * PurgeOrganization calls this directly; nothing else should.
     *
     * @return array{0: array<string, int>, 1: list<string>}
     */
    public function rows(Conference $conference): array
    {
        $ids = $this->collect($conference);
        $counts = [];

        foreach ($this->queries($conference, $ids) as $table => $query) {
            $counts[$table] = $query->delete();
        }

        // The one soft-deleting table inside the subtree, and the reason it is
        // not in queries(): ->delete() on a SoftDeletes builder is an UPDATE.
        // forceDelete() is a real DELETE, and it is safe here because every
        // RESTRICT onto submissions went in the loop above and conferences -
        // the only RESTRICT submissions is under - goes next.
        $counts['submissions'] = Submission::withTrashed()->whereIn('id', $ids['submissions'])->forceDelete();

        // Last, and through the model, so anything observing a conference sees
        // it go. withTrashed(): the table soft-deletes.
        $counts['conferences'] = Conference::withTrashed()->whereKey($conference->getKey())->forceDelete();

        return [$counts, $ids['paths']];
    }

    /**
     * Every id the purge needs, read once and up front: after the first delete
     * the rows that would answer these questions are gone.
     *
     * @return array{submissions: list<int>, reviews: list<int>, forms: list<int>, shortLinks: list<int>, paths: list<string>}
     */
    private function collect(Conference $conference): array
    {
        $conferenceId = (int) $conference->getKey();

        $submissions = $this->ids(Submission::withTrashed()->where('conference_id', $conferenceId));
        $forms = $this->ids(ReviewForm::query()->where('conference_id', $conferenceId));
        $reviews = $this->ids(Review::query()->whereIn('submission_id', $submissions));
        $shortLinks = $this->ids(ShortLink::query()
            ->where('target_type', (new Conference)->getMorphClass())
            ->where('target_id', $conferenceId));

        /** @var list<string> $paths */
        $paths = SubmissionFile::query()
            ->whereIn('submission_id', $submissions)
            ->pluck('path')
            ->map(static fn (mixed $path): string => (string) $path)
            ->all();

        return [
            'submissions' => $submissions,
            'reviews' => $reviews,
            'forms' => $forms,
            'shortLinks' => $shortLinks,
            'paths' => $paths,
        ];
    }

    /**
     * THE ORDER. One entry per table, deepest first, each one a query that
     * preview() counts and rows() deletes. Adding a table to the schema means
     * adding a line here; Plan 6 Task 14 Step 8 greps the migrations against
     * this method.
     *
     * @param  array{submissions: list<int>, reviews: list<int>, forms: list<int>, shortLinks: list<int>, paths: list<string>}  $ids
     * @return array<string, Builder<covariant \Illuminate\Database\Eloquent\Model>>
     */
    private function queries(Conference $conference, array $ids): array
    {
        $conferenceId = (int) $conference->getKey();

        return [
            // review_answers.review_question_id RESTRICTs the questions below.
            'review_answers' => ReviewAnswer::query()->whereIn('review_id', $ids['reviews']),
            // RESTRICT onto submissions, users AND review_forms.
            'reviews' => Review::query()->whereIn('id', $ids['reviews']),
            // RESTRICT onto submissions: the record of what an author was told.
            'submission_decisions' => SubmissionDecision::query()->whereIn('submission_id', $ids['submissions']),
            // Cascade, counted: an admin destroying a conference is entitled to
            // know it is destroying 412 authors.
            'review_assignments' => ReviewAssignment::query()->whereIn('submission_id', $ids['submissions']),
            'submission_files' => SubmissionFile::query()->whereIn('submission_id', $ids['submissions']),
            'submission_authors' => SubmissionAuthor::query()->whereIn('submission_id', $ids['submissions']),
            // Before the rows they point at: all three keys are nullOnDelete,
            // so the alternative to deleting is anonymising, not keeping. A row
            // that is only the organization's survives until the organization
            // is purged.
            'email_logs' => EmailLog::query()
                ->where(function (Builder $query) use ($conferenceId, $ids): void {
                    $query->where('conference_id', $conferenceId)
                        ->orWhereIn('submission_id', $ids['submissions']);
                }),
            // `submissions` is DELIBERATELY absent from this list. The table
            // soft-deletes, so the uniform ->delete() loop in rows() would only
            // set deleted_at on rows that already carry it and leave every
            // RESTRICT above pointless. It is force-deleted by hand in rows()
            // and counted by hand in preview(), after this loop and before the
            // conference row - by which point every RESTRICT onto submissions
            // (reviews, submission_decisions) is gone and the only RESTRICT
            // submissions itself sits under, conferences, has not run yet.
            'reviewer_reminders' => ReviewerReminder::query()->where('conference_id', $conferenceId),
            'conference_reviewers' => ConferenceReviewer::query()->where('conference_id', $conferenceId),
            'reviewer_invitations' => ReviewerInvitation::query()->where('conference_id', $conferenceId),
            'email_templates' => EmailTemplate::query()->where('conference_id', $conferenceId),
            // THE line the model hook would have thrown on.
            'review_questions' => ReviewQuestion::query()->whereIn('review_form_id', $ids['forms']),
            'review_forms' => ReviewForm::query()->whereIn('id', $ids['forms']),
            // submissions.track_id is nullOnDelete, so the order against the
            // submissions line above is free.
            'custom_fields' => CustomField::query()->where('conference_id', $conferenceId),
            'tracks' => Track::query()->where('conference_id', $conferenceId),
            // NO foreign key reaches either: nothing would remove them, and
            // unique(target_type, target_id) would then refuse a conference
            // re-created at the same id.
            'short_link_visits' => ShortLinkVisit::query()->whereIn('short_link_id', $ids['shortLinks']),
            'short_links' => ShortLink::query()->whereIn('id', $ids['shortLinks']),
        ];
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return list<int>
     */
    private function ids(Builder $query): array
    {
        return $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }
}
```

**The one wrinkle the code above has already resolved, spelled out because it is the thing a reviewer will ask about.** `submissions` soft-deletes, so `->delete()` on a query in `queries()` is an `UPDATE deleted_at`, not a `DELETE`: it would leave every row in place and make every `RESTRICT` above it pointless, and the `RESTRICT` on `submissions.conference_id` would then refuse the conference row. `Builder::forceDelete()` is the call that is needed and a uniform `->delete()` loop cannot make it. Two ways:

- special-case the key `'submissions'` inside the loop (`$table === 'submissions' ? $query->forceDelete() : $query->delete()`); or
- **keep `queries()` for the hard-deleting tables only** and handle the one soft-deleting table explicitly, so the loop stays uniform — a loop that sometimes means `forceDelete` is a loop nobody can read.

The code above takes the second: `queries()` carries a comment where the entry would have been, `rows()` force-deletes and `preview()` counts, both immediately **after** the loop and before the conference row. *After*, not before: `reviews.submission_id` and `submission_decisions.submission_id` are `RESTRICT`, so a submission cannot go until the loop's first three lines have run — which is why the reported key lands second-to-last, and why the order table above numbers `submissions` 13 rather than 8. The deletion order is what the foreign keys care about; the key order is what the preview modal prints, and the two now agree.

- [ ] **Step 4: The `PurgeOrganization` refactor**

Replace the body of the existing `DB::transaction(...)` closure with a loop plus the organization's own rows. **Everything else in the class stays**: the docblock, `ids()`, `shortLinks()` and the disk pass. The signature gains one optional parameter:

```php
    /**
     * @return array<string, int> what was deleted, keyed by table, in the order
     *                            the rows went
     */
    public function handle(Organization $organization, ?User $actor = null): array
    {
        $organizationId = (int) $organization->getKey();

        if (! Organization::withTrashed()->whereKey($organizationId)->exists()) {
            return [];
        }

        $conferences = Conference::withTrashed()->where('organization_id', $organizationId)->get();

        /** @var list<string> $paths */
        $paths = [];
        /** @var array<string, int> $counts */
        $counts = [];

        $result = DB::transaction(function () use ($organization, $organizationId, $conferences, &$paths): array {
            $counts = [];

            // The conference subtree is PurgeConference's order, called rather
            // than repeated: it is the one thing in this application that must
            // not exist twice.
            foreach ($conferences as $conference) {
                [$rows, $conferencePaths] = $this->conferences->rows($conference);

                foreach ($rows as $table => $count) {
                    $counts[$table] = ($counts[$table] ?? 0) + $count;
                }

                $paths = [...$paths, ...$conferencePaths];
            }

            // What is left is the organization's own: the tenant-level mail
            // history (the per-conference rows went with their conference),
            // the memberships, the invitations, the organization-targeted
            // short links, and the row itself.
            $shortLinkIds = $this->ids($this->shortLinks($organization, []));

            $counts['email_logs'] = ($counts['email_logs'] ?? 0)
                + EmailLog::query()->where('organization_id', $organizationId)->delete();
            $counts['short_link_visits'] = ($counts['short_link_visits'] ?? 0)
                + ShortLinkVisit::query()->whereIn('short_link_id', $shortLinkIds)->delete();
            $counts['short_links'] = ($counts['short_links'] ?? 0)
                + ShortLink::query()->whereIn('id', $shortLinkIds)->delete();
            $counts['organization_invitations'] = OrganizationInvitation::query()->where('organization_id', $organizationId)->delete();
            $counts['organization_members'] = OrganizationMember::query()->where('organization_id', $organizationId)->delete();
            $counts['organizations'] = Organization::withTrashed()->whereKey($organizationId)->forceDelete();

            return $counts;
        });

        foreach ($paths as $path) {
            Storage::disk('local')->delete($path);
        }

        $result['private files'] = count($paths);

        // The slug and the custom domain are both unique and both checked
        // withTrashed(), so both are free the moment the row is gone - and the
        // cached verified-host list has to be told.
        CustomDomains::forget();

        if ($actor instanceof User) {
            // No performedOn(): the subject is gone. Optional, because
            // cass:demo-reset runs with no actor at all.
            activity()
                ->causedBy($actor)
                ->withProperties([
                    'organization_slug' => (string) $organization->slug,
                    'organization_name' => (string) $organization->name,
                    'rows' => $result,
                ])
                ->log('organization.purged');
        }

        return $result;
    }
```

with a promoted constructor `public function __construct(private readonly PurgeConference $conferences) {}` and `use App\Actions\Conferences\PurgeConference;`, `use App\Models\User;`, `use App\Support\Domains\CustomDomains;` added.

**`shortLinks($organization, [])` with an empty conference list is deliberate**: the conference arm of that method has nothing left to find (every conference's links went with it), and the organization arm is the one that still matters. If the existing method cannot take an empty array cleanly, inline the organization-only query here and leave `shortLinks()` alone.

Then, immediately:

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Console/DemoResetTest.php tests/Feature/Console/DemoSeedTest.php > /tmp/t-demo.log 2>&1; echo "demo rc=$?"; tail -20 /tmp/t-demo.log
```

Expected: `demo rc=0`, **with those two files unedited**. That is the whole proof the refactor preserved behaviour. If a count key moved — say `DemoResetTest` asserts `'private files'` or the exact key order — read the assertion and make the refactor match it rather than editing the test; the demo branch's contract is older than this task.

- [ ] **Step 5: The two policies**

`app/Policies/OrganizationPolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

/**
 * The first OrganizationPolicy in this application, and it exists mainly to
 * close a door.
 *
 * NO before(). Filament treats a MISSING policy method as allowed
 * (vendor/filament/filament/src/helpers.php), so a policy that exists has to
 * be exhaustive — and a before() answering true for a platform admin would
 * answer `forceDelete` too, which is exactly what this class must not do. An
 * Organization cannot be force-deleted by anybody through any UI: its
 * conferences are RESTRICT-ed onto it, so Filament's own ForceDeleteAction
 * would run $record->forceDelete() straight into a QueryException. Destroying
 * one is `purge`, which App\Actions\Organizations\PurgeOrganization implements
 * in application code.
 *
 * The shape is App\Policies\EmailLogPolicy's: one rule, stated on every method.
 */
class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->is_platform_admin || $user->roleIn($organization) !== null;
    }

    /** Organizations are created by RegisterOrganization, never from a panel. */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * The organizer panel's tenant profile page checks the same rule in its own
     * canView(); this is here so the answer is the same however it is asked.
     */
    public function update(User $user, Organization $organization): bool
    {
        return $user->is_platform_admin || ($user->roleIn($organization)?->canManageOrganization() ?? false);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Organization $organization): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Organization $organization): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Spec section 3. Platform admin only, and never an organizer: an owner who
     * wants their data gone asks the platform, which is also the moment
     * somebody checks whether a conference is mid-review.
     */
    public function purge(User $user, Organization $organization): bool
    {
        return $user->is_platform_admin;
    }
}
```

`app/Policies/ConferencePolicy.php` — add `purge()` beside `archive()`:

```php
    /**
     * Spec section 3's hard purge. Platform admin only.
     *
     * before() already answers true for a platform admin, so this method is
     * only ever reached for somebody else — which is the point: it is the
     * ability the admin panel's purge action authorizes against, and naming it
     * is what keeps that action from being authorized by `forceDelete`, which
     * Filament's own ForceDeleteAction would also match.
     */
    public function purge(User $user, Conference $conference): bool
    {
        return $user->is_platform_admin;
    }
```

and rewrite the standing warning in the class docblock (currently *"no ForceDeleteAction may be added to either panel until an action class deletes the tree in application code"*):

```php
 * Note this also makes `forceDelete` true for a platform admin. The
 * application-code purge now exists (App\Actions\Conferences\PurgeConference),
 * and the admin panel authorizes against the `purge` ability rather than
 * `forceDelete` — so Filament's own ForceDeleteAction still must not be added
 * to either panel: it calls $record->forceDelete() and hits the RESTRICT keys
 * on submissions, review_forms, tracks and custom_fields.
```

**Run `ChildPolicyBulkAbilitiesTest` immediately after this step.** Adding a policy for a model that had none changes what Filament asks, and that file is what pins the answers.

- [ ] **Step 6: `lang/en/admin.php`, the counts partial and the two table actions**

`lang/en/admin.php` — the file Tasks 5 and 6 also append to. Groups in the order an admin meets them, none defined twice:

```php
<?php

declare(strict_types=1);

/*
 * The platform-admin panel: the read-only screens of spec section 4 and the
 * hard purge of section 3. Spec section 10: Arabic is a copy of this file.
 */

return [
    'purge' => [
        'action' => 'Purge permanently',
        'conference_heading' => 'Purge :name?',
        'organization_heading' => 'Purge :name and everything in it?',
        'intro' => 'This deletes the rows below and the uploaded files behind them. It cannot be undone and there is no restore — the only copy afterwards is the nightly backup.',
        'nothing' => 'There is nothing left to remove.',
        'counts_heading' => 'What will be deleted',
        'files' => 'uploaded files',
        'audit_note' => 'The audit log is kept: the record of who did what, including this purge, is not erased.',
        'confirm_label' => 'Type :word to confirm',
        'confirm_help' => 'That is the slug from the URL. Typing it is the confirmation.',
        'confirm_mismatch' => 'That is not :word.',
        'submit' => 'Purge permanently',
        'done' => 'Purged :name — :rows rows and :files files removed.',
    ],
];
```

`app/Support/Purge/PurgeCounts.php` — the small formatting helper the modal and the notification share. No behaviour; it exists so `array_filter`/`arsort` are not written twice:

```php
<?php

declare(strict_types=1);

namespace App\Support\Purge;

/**
 * Reading helpers over the array<string, int> the two purge actions return.
 * Static, and deliberately not a value object: the actions' return type is
 * already the contract cass:demo-reset and DemoResetTest depend on, and
 * changing it to a class would be a breaking change for a formatting
 * convenience.
 */
final class PurgeCounts
{
    /**
     * Only the tables that actually had rows, biggest first — what the modal
     * prints. A list of eighteen zeroes is not a confirmation.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    public static function significant(array $counts): array
    {
        $rows = array_filter($counts, static fn (int $count): bool => $count > 0);
        arsort($rows);

        return $rows;
    }

    /** @param array<string, int> $counts */
    public static function total(array $counts): int
    {
        return array_sum($counts);
    }
}
```

`resources/views/filament/admin/partials/purge-counts.blade.php`:

```blade
@php use App\Support\Purge\PurgeCounts; @endphp

@if (PurgeCounts::total($counts) === 0)
    <p>{{ __('admin.purge.nothing') }}</p>
@else
    <p style="font-weight:600;margin:0 0 0.5rem">{{ __('admin.purge.counts_heading') }}</p>
    <ul style="margin:0;padding-left:1.25rem">
        @foreach (PurgeCounts::significant($counts) as $table => $count)
            <li><strong>{{ number_format($count) }}</strong> {{ $table === 'private files' ? __('admin.purge.files') : str_replace('_', ' ', $table) }}</li>
        @endforeach
    </ul>
    <p style="margin:0.75rem 0 0;color:#475569;font-size:0.8125rem">{{ __('admin.purge.audit_note') }}</p>
@endif
```

`app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php` — a static factory beside the existing ones (the `OrganizationsTable::approveAction()` idiom, so one object serves both the row and the view header), appended to `recordActions([...])` after `ViewAction` and `RestoreAction`:

```php
    /**
     * Spec section 3's hard purge. NOT a ForceDeleteAction: Filament's own
     * calls $record->forceDelete() and hits four RESTRICT keys. This one calls
     * PurgeConference, which deletes the tree in application code.
     */
    public static function purgeAction(): Action
    {
        return Action::make('purge')
            ->label(__('admin.purge.action'))
            ->icon(Heroicon::OutlinedFire)
            ->color('danger')
            ->authorize(fn (Conference $record): bool => Gate::allows('purge', $record))
            ->modalHeading(fn (Conference $record): string => __('admin.purge.conference_heading', ['name' => (string) $record->name]))
            ->modalDescription(__('admin.purge.intro'))
            ->modalSubmitActionLabel(__('admin.purge.submit'))
            // Counted for the modal and counted again by the run. Two reads of
            // the same tables a second apart is cheaper than a confirmation
            // nobody can check.
            ->modalContent(fn (Conference $record) => view('filament.admin.partials.purge-counts', [
                'counts' => app(PurgeConference::class)->preview($record),
            ]))
            ->schema([
                TextInput::make('confirmation')
                    ->label(fn (Conference $record): string => __('admin.purge.confirm_label', ['word' => (string) $record->slug]))
                    ->helperText(__('admin.purge.confirm_help'))
                    ->required()
                    ->rule(static::confirmationRule()),
            ])
            ->action(function (Conference $record): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $name = (string) $record->name;
                $counts = app(PurgeConference::class)->handle($record, $user);
                $files = $counts['private files'] ?? 0;
                unset($counts['private files']);

                Notification::make()->success()->title(__('admin.purge.done', [
                    'name' => $name,
                    'rows' => number_format(array_sum($counts)),
                    'files' => number_format($files),
                ]))->send();
            })
            ->successRedirectUrl(fn (): string => ConferenceResource::getUrl('index', panel: 'admin'));
    }

    /**
     * The double-closure shape ConferenceEmailTemplates::editAction() uses, for
     * the same reason: Filament evaluates a Closure rule as a callback, so an
     * unwrapped Illuminate rule closure throws BindingResolutionException on
     * its $attribute parameter. The record reaches the inner closure through
     * the outer one's injected argument.
     */
    protected static function confirmationRule(): Closure
    {
        return static fn (Conference $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if (! is_string($value) || trim($value) !== (string) $record->slug) {
                $fail(__('admin.purge.confirm_mismatch', ['word' => (string) $record->slug]));
            }
        };
    }
```

`app/Filament/Admin/Resources/Organizations/Tables/OrganizationsTable.php` gets the same pair, reading `admin.purge.organization_heading`, previewing and calling `PurgeOrganization`, and typing the organization's slug. `ViewConference` and `ViewOrganization` each add the action to `getHeaderActions()` beside the ones already there.

- [ ] **Step 7: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/PurgeConferenceTest.php tests/Feature/Admin/PurgeTest.php tests/Feature/Console/DemoResetTest.php tests/Feature/Console/DemoSeedTest.php tests/Feature/Organizer/ChildPolicyBulkAbilitiesTest.php tests/Feature/Admin/ConferencesTest.php tests/Feature/Admin/OrganizationApprovalTest.php > /tmp/t-task-4.log 2>&1; echo "rc=$?"; tail -12 /tmp/t-task-4.log && \
php artisan test > /tmp/all-task-4.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-4.log
```

Expected: both `rc=0`; `baseline + 86`.

**Five existing suites are in that run and none is padding.** The two demo files are the refactor's proof. `ChildPolicyBulkAbilitiesTest` is what a newly discovered `OrganizationPolicy` changes first. `ConferencesTest` and `OrganizationApprovalTest` are the two admin tables the purge action was just added to, and a broken `->authorize()` closure shows up there as *every* row action disappearing.

- [ ] **Step 8: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all-task-4.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(admin): purge one conference, preview what a purge destroys, and put both behind a typed name

The order now lives in exactly one place: PurgeConference owns it and
PurgeOrganization calls it per conference. The demo command's own tests pass
unedited, which is the proof that refactor changed no behaviour. The audit log
is deliberately not erased; the runbook carries the erasure query instead.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---

### Task 5: The read-only admin `SubmissionResource` — authors, files, decisions and global search

Spec section 4 gives the platform admin "See all organizations and conferences" and "View submissions and files". Plans 3, 4 and 5 each wrote the policy half and left the screen for this task, three times, in the same words:

> **The platform admin cannot read a submission (spec section 4).** `SubmissionPolicy::before()` and `SubmissionFilePolicy::before()` answer `true` for `is_platform_admin`, but the organizer panel is membership-gated … so there is no route in. **Plan 6** adds a read-only admin `App\Filament\Admin\Resources\Submissions\SubmissionResource` (index + view, `$isScopedToTenant = false`, `canAccess()` = `is_platform_admin`), minting file links through `SubmissionFilePolicy` exactly as the organizer infolist does.

**Four decisions this task makes.**

**1. `$isScopedToTenant` is not set, because the admin panel has no tenancy at all.** Plan 3's backlog entry was written for the *organizer* panel, where `SubmissionResource` has to override tenancy by hand because `Submission` has no `organization_id` and Filament's tenancy observer fatals on a `HasOneThrough` ownership relation. `AdminPanelProvider` never calls `->tenant()` (fact: the provider, read in full), so none of that applies: the admin resource is a plain `Filament\Resources\Resource` with no scoping and an eager-load on `conference.organization`. **Do not copy the organizer resource's tenancy override into it** — it would be dead code with a comment explaining a problem the admin panel does not have.

**2. Index plus view, and every write refused twice.** `EmailLogResource::getPages()` already carries the rule this follows: *"index and view only. There is no create, no edit and no delete page, and `EmailLogPolicy` answers false to every one of those abilities — **Filament treats a missing policy method as ALLOW**, so both halves are needed."* `SubmissionPolicy` does have `before()` answering `true` for a platform admin, so the policy half cannot be relied on here at all (Laravel returns a non-null `before()` result **without ever calling the ability** — Plan 4 recorded this and Plan 5 repeated it). The refusal therefore lives in the **resource**: `getPages()` names two, `ListSubmissions::getHeaderActions()` and `ViewSubmission::getHeaderActions()` both return `[]`, the table has no bulk actions and no row actions except `ViewAction`, and a test asserts the flat action list contains nothing that writes. **Be precise about what those empty overrides do:** Filament 5.8.1's `ListRecords` and `ViewRecord` add **no** header actions of their own — `getHeaderActions()` falls through to the deprecated `getActions()`, which returns `[]` (`InteractsWithHeaderActions.php:55-68`), and neither page class declares a default. The empty overrides are therefore belt-and-braces against a later edit or a scaffolded `CreateAction`, not the removal of a framework default. The test asserts them through `getCachedHeaderActions()`, because `getHeaderActions()` is **protected** and calling it on the instance is a PHP `Error`.

**3. File links are minted through the policy and are short-lived, exactly as the organizer's are.** `SubmissionFile::temporaryUrl()` builds a `URL::temporarySignedRoute('files.download', …)` good for `config('cass.file_url_minutes')` (30), and the organizer infolist mints one per render behind `Gate::allows('view', $record)`. The admin infolist does the same thing with the same two lines. The `blind` flag is a reviewer concern and is **absent** here: a platform admin is not blinded, and `temporaryUrl()` omits the parameter from the signature entirely when it is false.

**4. Global search is turned on for exactly two resources, with the title attributes that already exist.** `OrganizationResource` and `ConferenceResource` both already declare `$recordTitleAttribute = 'name'`; adding `getGloballySearchableAttributes()` and `getGlobalSearchResultDetails()` is what makes the admin panel's search bar answer, which is the practical form of "See all organizations and conferences" for somebody holding a support email and a conference name. **Submissions are deliberately not globally searchable**: an abstract title is author-supplied text, a global search box indexes it across every tenant at once, and the resource's own filtered table is one click further with the conference filter already on it.

**Files:**
- Create: `app/Filament/Admin/Resources/Submissions/SubmissionResource.php`, `Pages/ListSubmissions.php`, `Pages/ViewSubmission.php`, `Tables/SubmissionsTable.php`, `Schemas/SubmissionInfolist.php`
- Modify: `app/Filament/Admin/Resources/Organizations/OrganizationResource.php`, `app/Filament/Admin/Resources/Conferences/ConferenceResource.php` (global search only)
- Modify: `lang/en/admin.php`
- Test: `tests/Feature/Admin/SubmissionResourceTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Admin/SubmissionResourceTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\OrganizationRole;
use App\Filament\Admin\Resources\Conferences\ConferenceResource as AdminConferenceResource;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Filament\Admin\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\SubmissionDecision;
use App\Models\SubmissionFile;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->platformAdmin()->create();
    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->conference = Conference::factory()->for($this->organization)->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
    ]);
    $this->submission = Submission::factory()->for($this->conference)->submitted()->create([
        'title' => 'Early mobilisation after cardiac surgery',
    ]);
});

it('lists every organization submission, with the conference and the organization', function () {
    $theirs = Submission::factory()->submitted()->create(['title' => 'Another society abstract']);

    // The point of the screen: an admin sees across tenants, which nobody in
    // the organizer panel can (canAccessPanel('organizer') requires
    // membership) and which spec section 4 puts in the admin column.
    livewire(ListSubmissions::class)
        ->assertCanSeeTableRecords([$this->submission, $theirs])
        ->assertCanRenderTableColumn('reference')
        ->assertCanRenderTableColumn('title')
        ->assertCanRenderTableColumn('conference.name')
        ->assertCanRenderTableColumn('conference.organization.name')
        ->assertSee('Alpha Society');
});

it('filters by organization, by conference status and by decision', function () {
    $decided = Submission::factory()->for($this->conference)->submitted()->create();
    $decided->forceFill(['decision' => Decision::AcceptedOral, 'decision_notified_at' => now()])->save();

    livewire(ListSubmissions::class)
        ->filterTable('decision', Decision::AcceptedOral->value)
        ->assertCanSeeTableRecords([$decided])
        ->assertCanNotSeeTableRecords([$this->submission]);
});

it('opens one submission with its authors, its files and its decision history', function () {
    SubmissionAuthor::factory()->for($this->submission)->create([
        'name' => 'Sara Al-Harbi',
        'email' => 'sara@example.org',
        'is_presenter' => true,
    ]);
    SubmissionFile::factory()->for($this->submission)->create(['original_name' => 'abstract.pdf']);
    SubmissionDecision::factory()->for($this->submission)->create([
        'decision' => Decision::Waitlisted,
        'decided_at' => now()->subDay(),
    ]);
    $this->submission->forceFill(['decision' => Decision::Waitlisted])->save();

    get(SubmissionResource::getUrl('view', ['record' => $this->submission], panel: 'admin'))
        ->assertOk()
        ->assertSee('Early mobilisation after cardiac surgery')
        ->assertSee('Sara Al-Harbi')
        ->assertSee('abstract.pdf')
        ->assertSee(Decision::Waitlisted->getLabel())
        // Spec section 8: files are reachable only through a signed, expiring
        // route. The link is minted per render, behind the policy, exactly as
        // the organizer infolist mints it.
        ->assertSee('/files/', escape: false)
        ->assertSee('signature=', escape: false);
});

it('gives the platform admin nothing that writes', function () {
    $table = livewire(ListSubmissions::class)->instance()->getTable();

    // array_merge, not `+`: `+` on two numerically-indexed arrays silently
    // drops every bulk-action name whose index already exists in the first.
    $names = array_merge(
        array_keys($table->getFlatActions()),
        array_keys($table->getFlatBulkActions()),
    );

    // Filament treats a MISSING policy method as ALLOW, and SubmissionPolicy's
    // before() answers true for a platform admin without the ability ever
    // being called - so the refusal has to be the resource, not the policy.
    expect($names)->toBe(['view'])
        ->and(array_keys(SubmissionResource::getPages()))->toBe(['index', 'view']);

    // getHeaderActions() is protected (Pages/Concerns/InteractsWithHeaderActions.php:55);
    // getCachedHeaderActions() (:47) is the public accessor, populated on mount
    // by cacheInteractsWithHeaderActions().
    expect(livewire(ListSubmissions::class)->instance()->getCachedHeaderActions())->toBe([])
        ->and(livewire(ViewSubmission::class, ['record' => $this->submission->getRouteKey()])->instance()->getCachedHeaderActions())->toBe([]);
});

it('is forbidden to an organization owner who can see the same rows in their own panel', function () {
    $owner = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);

    actingAs($owner)->get(SubmissionResource::getUrl('index', panel: 'admin'))->assertForbidden();
    actingAs($owner)->get(SubmissionResource::getUrl('view', ['record' => $this->submission], panel: 'admin'))->assertForbidden();
});

it('links a submission to its conference and its organization, and both open', function () {
    $conferenceUrl = AdminConferenceResource::getUrl('view', ['record' => $this->conference], panel: 'admin');
    $organizationUrl = OrganizationResource::getUrl('view', ['record' => $this->organization], panel: 'admin');

    get(SubmissionResource::getUrl('view', ['record' => $this->submission], panel: 'admin'))
        ->assertOk()
        ->assertSee($conferenceUrl, escape: false)
        ->assertSee($organizationUrl, escape: false);

    get($conferenceUrl)->assertOk();
    get($organizationUrl)->assertOk();
});

it('finds an organization and a conference by name in global search, and never an abstract', function () {
    expect(OrganizationResource::getGloballySearchableAttributes())->toContain('name')
        ->and(AdminConferenceResource::getGloballySearchableAttributes())->toContain('name')
        // An abstract title is author-supplied text and a global search box
        // indexes it across every tenant at once. The resource's own filtered
        // table is one click further.
        ->and(SubmissionResource::canGloballySearch())->toBeFalse();

    $results = OrganizationResource::getGlobalSearchResults('Alpha');

    expect($results)->not->toBeEmpty();
});
```

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Admin/SubmissionResourceTest.php > /tmp/t-task-5.log 2>&1; echo "rc=$?"; tail -20 /tmp/t-task-5.log
```

Expected: `rc=1`, naming `App\Filament\Admin\Resources\Submissions\SubmissionResource`. **Failing-first gate.**

- [ ] **Step 2: The resource and its two pages**

`app/Filament/Admin/Resources/Submissions/SubmissionResource.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions;

use App\Filament\Admin\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Admin\Resources\Submissions\Schemas\SubmissionInfolist;
use App\Filament\Admin\Resources\Submissions\Tables\SubmissionsTable;
use App\Models\Submission;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Spec section 4's "View submissions and files" for the platform admin, three
 * plans late. The policies have answered `true` for is_platform_admin since
 * Plan 3 and there has been no route in: the organizer panel is
 * membership-gated (User::canAccessPanel('organizer') requires
 * organizations()->exists()), so an admin who is not a member of an
 * organization cannot open one of its abstracts at all.
 *
 * Read-only, and the refusal is HERE rather than in the policy. Laravel
 * returns a non-null before() result without ever calling the ability
 * (Gate::resolvePolicyCallback), so SubmissionPolicy's explicit
 * deleteAny(): false does not apply to a platform admin. Two pages, no header
 * actions, no bulk actions, one row action.
 *
 * No tenancy override. Plan 3's backlog entry about $isScopedToTenant and the
 * HasOneThrough fatal was written for the ORGANIZER panel, which has a tenant;
 * AdminPanelProvider never calls ->tenant(), so there is nothing here to scope
 * and nothing for Filament's tenancy observer to do.
 */
class SubmissionResource extends Resource
{
    protected static ?string $model = Submission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 30;

    /**
     * An abstract title is text an author typed, and a global search box asks
     * every tenant at once. The table's own conference filter is one click
     * further and shows who it belongs to.
     */
    protected static bool $isGloballySearchable = false;

    /** Defense in depth: the panel's middleware blocks non-admins at the HTTP layer, but Filament also runs this when a page's Livewire component is mounted directly. */
    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->is_platform_admin;
    }

    /**
     * The list prints the conference and the organization on every row, and
     * the infolist links to both. Eager-loading them here is what keeps that
     * from being two queries per row.
     *
     * withoutGlobalScopes is deliberately NOT used: a soft-deleted abstract is
     * an organizer's own deletion and the admin list is not a recycle bin. The
     * purge screen is on the conference, not here.
     *
     * @return Builder<Submission>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['conference.organization', 'track']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SubmissionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubmissionsTable::configure($table);
    }

    /**
     * Index and view only. There is no create, no edit and no delete page —
     * and because before() short-circuits the policy for a platform admin,
     * this array IS the refusal, together with the empty getHeaderActions()
     * on both pages.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSubmissions::route('/'),
            'view' => ViewSubmission::route('/{record}'),
        ];
    }
}
```

`Pages/ListSubmissions.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Pages;

use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Resources\Pages\ListRecords;

class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;

    /**
     * Empty on purpose, and asserted by the test. Filament 5.8.1's ListRecords
     * adds NO header action of its own - getHeaderActions() falls through to
     * the deprecated getActions(), which returns []
     * (InteractsWithHeaderActions.php:55-68) - so this override removes no
     * framework default. It is belt-and-braces against a later edit or a
     * scaffolded CreateAction: a platform admin creating an abstract on an
     * organizer's behalf is not a thing this platform does.
     *
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
```

`Pages/ViewSubmission.php` is the same shape with `extends ViewRecord` — and the same empty `getHeaderActions()`, with the same docblock: `ViewRecord` does not declare a default `EditAction` either, so this is the standing refusal rather than the removal of one. The test asserts both through the **public** `getCachedHeaderActions()`, since `getHeaderActions()` is protected.

- [ ] **Step 3: The table**

`app/Filament/Admin/Resources/Submissions/Tables/SubmissionsTable.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Tables;

use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Organization;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.submissions.columns.reference'))
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->placeholder('-'),
                TextColumn::make('title')
                    ->label(__('admin.submissions.columns.title'))
                    ->searchable()
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('conference.name')
                    ->label(__('admin.submissions.columns.conference'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('conference.organization.name')
                    ->label(__('admin.submissions.columns.organization'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.submissions.columns.status'))
                    ->badge(),
                TextColumn::make('decision')
                    ->label(__('admin.submissions.columns.decision'))
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('review_count')
                    ->label(__('admin.submissions.columns.reviews'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('score')
                    ->label(__('admin.submissions.columns.score'))
                    ->numeric(2)
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('submitted_at')
                    ->label(__('admin.submissions.columns.submitted'))
                    ->dateTime('j M Y, H:i')
                    // The conference's own timezone (spec section 10), read
                    // off the row the query already eager-loaded.
                    ->timezone(fn ($record): string => (string) $record->conference?->timezone)
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('organization')
                    ->label(__('admin.submissions.filters.organization'))
                    ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    // Through the conference: submissions have no
                    // organization_id, which is the same fact that made the
                    // organizer resource override tenancy.
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('conference', fn ($inner) => $inner->where('organization_id', $data['value']))
                        : $query),
                SelectFilter::make('conference')
                    ->label(__('admin.submissions.filters.conference'))
                    ->relationship('conference', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->label(__('admin.submissions.filters.status'))
                    ->options(SubmissionStatus::class)
                    ->multiple(),
                SelectFilter::make('decision')
                    ->label(__('admin.submissions.filters.decision'))
                    ->options(Decision::class)
                    ->multiple(),
                SelectFilter::make('conference_status')
                    ->label(__('admin.submissions.filters.conference_status'))
                    ->options(ConferenceStatus::class)
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('conference', fn ($inner) => $inner->where('status', $data['value']))
                        : $query),
            ])
            // One action, and it reads. No bulk actions at all: the flat
            // action list is asserted to be exactly ['view'].
            ->recordActions([ViewAction::make()]);
    }
}
```

**Larastan level 6 will object to the untyped closures** in the two `->query()` filters. Type them `fn (Builder $query, array $data): Builder` with `use Illuminate\Database\Eloquent\Builder;` and give the inner one `fn (Builder $inner): Builder`. Plan 5's `ConferenceRanking` hit the same rule and its filters are the reference.

- [ ] **Step 4: The infolist**

`app/Filament/Admin/Resources/Submissions/Schemas/SubmissionInfolist.php` — the organizer infolist is the model to copy, with three differences: no edit affordances, links out to the conference and the organization, and a decision **history** rather than only the current decision.

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Schemas;

use App\Filament\Admin\Resources\Conferences\ConferenceResource;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Models\Submission;
use App\Models\SubmissionDecision;
use App\Models\SubmissionFile;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

class SubmissionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.submissions.sections.abstract'))->columns(2)->components([
                TextEntry::make('reference')->fontFamily('mono')->placeholder('-'),
                TextEntry::make('status')->badge(),
                TextEntry::make('title')->columnSpanFull(),
                TextEntry::make('abstract')->columnSpanFull()->prose(),
                TextEntry::make('word_count')->numeric(),
                TextEntry::make('presentation_preference')->badge()->placeholder('-'),
                TextEntry::make('track.name')->placeholder('-'),
            ]),

            Section::make(__('admin.submissions.sections.owner'))->columns(2)->components([
                TextEntry::make('conference.name')
                    ->url(fn (Submission $record): ?string => $record->conference === null
                        ? null
                        : ConferenceResource::getUrl('view', ['record' => $record->conference], panel: 'admin')),
                TextEntry::make('conference.organization.name')
                    ->url(fn (Submission $record): ?string => $record->conference?->organization === null
                        ? null
                        : OrganizationResource::getUrl('view', ['record' => $record->conference->organization], panel: 'admin')),
                TextEntry::make('conference.status')->badge(),
                TextEntry::make('submitted_at')->dateTime('j M Y, H:i')
                    ->timezone(fn (Submission $record): string => (string) $record->conference?->timezone)
                    ->placeholder('-'),
            ]),

            Section::make(__('admin.submissions.sections.authors'))
                ->visible(fn (Submission $record): bool => $record->authors()->exists())
                ->components([
                    RepeatableEntry::make('authors')->hiddenLabel()->columns(4)->schema([
                        TextEntry::make('name'),
                        TextEntry::make('email'),
                        TextEntry::make('affiliation')->placeholder('-'),
                        // boolean() lives on IconEntry, not TextEntry - the
                        // same shape as ConferenceInfolist.php:232 and the
                        // organizer SubmissionInfolist.php:43. TextEntry has no
                        // boolean(), so ->boolean() there hits
                        // Macroable::__call and throws BadMethodCallException:
                        // a 500 on every admin submission view that has an
                        // author.
                        IconEntry::make('is_presenter')
                            ->boolean()
                            ->label(__('admin.submissions.columns.presenter')),
                    ]),
                ]),

            Section::make(__('admin.submissions.sections.files'))
                ->visible(fn (Submission $record): bool => $record->files()->exists())
                ->components([
                    RepeatableEntry::make('files')->hiddenLabel()->columns(3)->schema([
                        // A fresh signed URL per render, minted behind the
                        // policy exactly as the organizer infolist mints one.
                        // No `blind` flag: a platform admin is not blinded, and
                        // temporaryUrl() leaves the parameter out of the
                        // signature entirely when it is false.
                        TextEntry::make('original_name')
                            ->label(__('admin.submissions.columns.file'))
                            ->url(fn (SubmissionFile $record): ?string => Gate::allows('view', $record)
                                ? $record->temporaryUrl()
                                : null)
                            ->openUrlInNewTab(),
                        TextEntry::make('size')->formatStateUsing(fn (int $state): string => number_format($state / 1024).' KB'),
                        TextEntry::make('mime')->label(__('admin.submissions.columns.type')),
                    ]),
                ]),

            Section::make(__('admin.submissions.sections.decisions'))
                ->visible(fn (Submission $record): bool => $record->decisions()->exists())
                ->components([
                    TextEntry::make('decision')->badge()->placeholder('-'),
                    TextEntry::make('decision_notified_at')->dateTime('j M Y, H:i')
                        ->timezone(fn (Submission $record): string => (string) $record->conference?->timezone)
                        ->placeholder(__('admin.submissions.not_notified')),
                    // The whole history, newest first, with the letter that was
                    // actually sent - which is the thing no organizer screen
                    // shows either and which Plan 5 stored for exactly this.
                    RepeatableEntry::make('decisions')->hiddenLabel()->columnSpanFull()->columns(3)->schema([
                        TextEntry::make('decision')->badge(),
                        TextEntry::make('decided_at')->dateTime('j M Y, H:i'),
                        TextEntry::make('decidedBy.name')->label(__('admin.submissions.columns.decided_by'))
                            ->placeholder(__('admin.submissions.former_member')),
                        TextEntry::make('letter_subject')->columnSpanFull()->placeholder('-'),
                        TextEntry::make('note')->columnSpanFull()->placeholder('-'),
                    ]),
                ]),
        ]);
    }
}
```

**`SubmissionDecision` is imported and unused** in the block above — drop the import, or use it to type the repeatable's closures if Larastan asks. Pint will not remove it for you and `ordered_imports` will keep it tidy while it rots.

- [ ] **Step 5: Global search on the two resources that already have a title**

`OrganizationResource`, appended:

```php
    /**
     * Spec section 4's "See all organizations and conferences" in the form
     * somebody actually uses it: a support email arrives naming a society, and
     * the search bar has to find it. Both attributes are indexed columns.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'slug'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Organization $record */
        return [
            __('admin.search.status') => $record->status->getLabel(),
            __('admin.search.conferences') => (string) $record->conferences()->count(),
        ];
    }
```

`ConferenceResource` gets the same pair over `['name', 'slug']`, with details naming the organization and the status, plus:

```php
    /**
     * The global search runs one query per result for the details above, so
     * the relation it prints is eager-loaded here rather than lazily per row.
     *
     * @return Builder<Conference>
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('organization');
    }
```

- [ ] **Step 6: `lang/en/admin.php`**

Append a `submissions` group and a `search` group after `purge` (Task 4 created the file; keep the groups in the order an admin meets them and define none twice):

```php
    'submissions' => [
        'title' => 'Abstracts',
        'columns' => [
            'reference' => 'Reference',
            'title' => 'Title',
            'conference' => 'Conference',
            'organization' => 'Organization',
            'status' => 'Status',
            'decision' => 'Decision',
            'reviews' => 'Reviews',
            'score' => 'Score',
            'submitted' => 'Submitted',
            'presenter' => 'Presenter',
            'file' => 'File',
            'type' => 'Type',
            'decided_by' => 'Decided by',
        ],
        'filters' => [
            'organization' => 'Organization',
            'conference' => 'Conference',
            'status' => 'Status',
            'decision' => 'Decision',
            'conference_status' => 'Conference status',
        ],
        'sections' => [
            'abstract' => 'Abstract',
            'owner' => 'Where it belongs',
            'authors' => 'Authors',
            'files' => 'Files',
            'decisions' => 'Decisions',
        ],
        'not_notified' => 'Not sent',
        'former_member' => 'A former member',
    ],

    'search' => [
        'status' => 'Status',
        'conferences' => 'Conferences',
        'organization' => 'Organization',
    ],
```

- [ ] **Step 7: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Admin/ > /tmp/t-task-5.log 2>&1; echo "rc=$?"; tail -12 /tmp/t-task-5.log && \
php artisan test > /tmp/all-task-5.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-5.log
```

Expected: both `rc=0`; `baseline + 93`.

Then confirm the routes the new resource registered:

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan route:list --except-vendor | grep "admin/submissions"
```

Expected: exactly **two** — `admin/submissions` and `admin/submissions/{record}`. A third means a page was left in `getPages()`.

- [ ] **Step 8: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all-task-5.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(admin): a read-only view of every abstract, its files and its decisions

Three plans wrote the policy half and left the screen. The refusal to write is
in the resource rather than the policy, because Laravel returns a non-null
before() result without ever calling the ability - so deleteAny(): false does
not apply to a platform admin, and two pages plus empty header actions do.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---

### Task 6: The rest of the read-only screens — reviews and their answers, reviewers, assignments, members and invitations

Three backlog entries, all assigned to Plan 6, all in the same shape as Task 5's:

> **A platform-admin view of reviews, reviewers and assignments.** Every new policy's `before()` answers `true` for `is_platform_admin`, and there is no screen behind any of them … **Plan 6**, alongside the read-only admin `SubmissionResource`.
>
> **A platform-admin view of organization members.** … `canAccessPanel('organizer')` is `organizations()->exists()`. So spec section 4's platform-admin cell for "Manage organization members" has no screen. **Plan 6.**
>
> **Nothing in any panel prints a review's *content*.** Plan 5's ranking table reads the scores, not the answers … no organizer screen shows what a reviewer actually wrote, which is a real gap between spec section 4's "View submissions" and the code. **Plan 6** adds the read-only review screen, in the organizer panel beside the submission view **and** in the admin panel.

**Five decisions this task makes.**

**1. Reviews get a resource; everything else gets a relation manager.** A review is a thing an admin arrives at with a question of its own — "what did the three reviewers of abstract 17 actually say" — and it is worth a filterable list across every conference. Reviewers, assignments, members and invitations are always read *about something*: a conference's reviewers, an organization's members. Filament's relation managers put each of those on the record it belongs to, inherit the parent's authorization, and cost one class each instead of four resources with four tables and four sets of filters.

**2. The four policies with no `before()` are deliberately not touched.** `CustomFieldPolicy`, `ReviewFormPolicy`, `ReviewQuestionPolicy` and `TrackPolicy` contain no `is_platform_admin` check at all: every ability goes through `Filament::getTenant()`, which is `null` in the untenanted admin panel, so all four answer **false** for a platform admin (fact 11). Nothing in this task needs them: a relation manager consults the *related* model's policy, and `ReviewPolicy`, `ConferenceReviewerPolicy`, `ReviewAssignmentPolicy`, `OrganizationMemberPolicy` and `OrganizationInvitationPolicy` all have `before()`. The review answers are rendered from the `answers` relation inside an infolist, where no policy is consulted at all, and the *question text* comes off `$answer->question` as a string. So the four stay as they are, and Task 14 records that a future admin screen over tracks or custom fields has to amend them first.

**3. Every relation manager is read-only, and the refusal is the class.** `RelationManager` adds `CreateAction` in the header and `EditAction`/`DeleteAction` per row by default, and the parent's `before()` allows all of them for a platform admin — so `headerActions([])`, `recordActions([])` (or only `ViewAction`) and `toolbarActions([])` are the refusal, plus `public function isReadOnly(): bool { return true; }`, which Filament honours for the whole manager. The test asserts the flat action list, the same way Task 5's does.

**4. A reviewer's identity is shown to the admin and the blind flag is irrelevant here.** `Conference::hidesAuthorsFrom()` blinds a *reviewer* from the authors; it has never blinded anybody from the reviewer. Spec section 4's admin row is "See all"; the admin review screen prints the reviewer's name and email beside their answers, and the organizer one does too — which is what an organizer already sees on the reviewers page.

**5. The organizer's copy is a section on the existing submission infolist, not a new page.** The backlog asks for it "beside the submission view". A second page would need a route, a policy call and a cross-tenant negative of its own; a `Section` on `SubmissionInfolist` inherits all three from the page it is on, and puts the reviews exactly where an organizer is already standing when the question occurs to them. **It is `->visible()`-gated on the conference being past `open`** — a reviewer's text during an open call is not something an organizer should be reading over their shoulder while the reviewer is still editing it, and `ReviewStatus::Submitted` is the only status the section lists.

**Files:**
- Create: `app/Filament/Admin/Resources/Reviews/ReviewResource.php`, `Pages/ListReviews.php`, `Pages/ViewReview.php`, `Tables/ReviewsTable.php`, `Schemas/ReviewInfolist.php`
- Create: `app/Filament/Admin/Resources/Submissions/RelationManagers/ReviewsRelationManager.php`, `AssignmentsRelationManager.php`
- Create: `app/Filament/Admin/Resources/Conferences/RelationManagers/ReviewersRelationManager.php`
- Create: `app/Filament/Admin/Resources/Organizations/RelationManagers/MembersRelationManager.php`, `InvitationsRelationManager.php`
- Modify: `app/Filament/Admin/Resources/Submissions/SubmissionResource.php`, `.../Conferences/ConferenceResource.php`, `.../Organizations/OrganizationResource.php` (each gains `getRelations()`)
- Modify: `app/Models/Submission.php` (`submittedReviews()`), `app/Filament/Organizer/Resources/Submissions/Schemas/SubmissionInfolist.php` (one section)
- Modify: `lang/en/admin.php`, `lang/en/reviewer.php`
- Test: `tests/Feature/Admin/AdminReadOnlyTest.php`, `tests/Feature/Organizer/SubmissionResourceTest.php` (appended)

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/AdminReadOnlyTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewStatus;
use App\Filament\Admin\Resources\Conferences\Pages\ViewConference;
use App\Filament\Admin\Resources\Conferences\RelationManagers\ReviewersRelationManager;
use App\Filament\Admin\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Admin\Resources\Organizations\RelationManagers\InvitationsRelationManager;
use App\Filament\Admin\Resources\Organizations\RelationManagers\MembersRelationManager;
use App\Filament\Admin\Resources\Reviews\Pages\ListReviews;
use App\Filament\Admin\Resources\Reviews\ReviewResource;
use App\Filament\Admin\Resources\Submissions\RelationManagers\AssignmentsRelationManager;
use App\Filament\Admin\Resources\Submissions\RelationManagers\ReviewsRelationManager;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->platformAdmin()->create();
    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->conference = Conference::factory()->for($this->organization)->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
    ]);
    $this->form = ReviewForm::factory()->for($this->conference)->create(['is_active' => true]);
    $this->question = ReviewQuestion::factory()->for($this->form)->create([
        'question' => 'Is the methodology sound?',
        'scale_min' => 1,
        'scale_max' => 5,
    ]);
    $this->submission = Submission::factory()->for($this->conference)->submitted()->create([
        'title' => 'Early mobilisation after cardiac surgery',
    ]);

    $this->reviewer = User::factory()->create(['name' => 'Dr Salah Almubarak', 'email' => 'salah@example.org']);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->reviewer->getKey()]);

    $this->review = Review::factory()->create([
        'submission_id' => $this->submission->getKey(),
        'reviewer_user_id' => $this->reviewer->getKey(),
        'review_form_id' => $this->form->getKey(),
        'status' => ReviewStatus::Submitted,
        'submitted_at' => now(),
    ]);
    (new ReviewAnswer)->forceFill([
        'review_id' => $this->review->getKey(),
        'review_question_id' => $this->question->getKey(),
        'value_int' => 4,
        'value_text' => 'The sample size is small but the design is sound.',
        'value_bool' => null,
        'choice_key' => null,
    ])->save();
});

it('lists every review across every conference', function () {
    $theirs = withoutTenant(function (): Review {
        $conference = Conference::factory()->create();
        $form = ReviewForm::factory()->for($conference)->create(['is_active' => true]);
        $submission = Submission::factory()->for($conference)->submitted()->create();

        return Review::factory()->create([
            'submission_id' => $submission->getKey(),
            'reviewer_user_id' => User::factory()->create()->getKey(),
            'review_form_id' => $form->getKey(),
            'status' => ReviewStatus::Submitted,
            'submitted_at' => now(),
        ]);
    });

    livewire(ListReviews::class)
        ->assertCanSeeTableRecords([$this->review, $theirs])
        ->assertCanRenderTableColumn('submission.title')
        ->assertCanRenderTableColumn('reviewer.name')
        ->assertCanRenderTableColumn('score')
        ->assertSee('Dr Salah Almubarak');
});

it('prints what a reviewer actually wrote — the gap Plan 5 recorded', function () {
    get(ReviewResource::getUrl('view', ['record' => $this->review], panel: 'admin'))
        ->assertOk()
        ->assertSee('Early mobilisation after cardiac surgery')
        ->assertSee('Dr Salah Almubarak')
        // The question text and the answer, which no organizer or admin screen
        // has ever shown.
        ->assertSee('Is the methodology sound?')
        ->assertSee('The sample size is small but the design is sound.')
        // ReviewInfolist::answerText() renders a Likert answer as "4 / 5" (the
        // value and the question's scale_max). A bare '4' would match a ULID, a
        // date or Filament's own markup and prove nothing.
        ->assertSee('4 / 5');
});

it('hangs reviews and assignments off the submission, and reviewers off the conference', function () {
    ReviewAssignment::factory()->create([
        'submission_id' => $this->submission->getKey(),
        'reviewer_user_id' => $this->reviewer->getKey(),
    ]);

    livewire(ReviewsRelationManager::class, [
        'ownerRecord' => $this->submission,
        'pageClass' => App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission::class,
    ])->assertCanSeeTableRecords([$this->review]);

    livewire(AssignmentsRelationManager::class, [
        'ownerRecord' => $this->submission,
        'pageClass' => App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission::class,
    ])->assertSee('Dr Salah Almubarak');

    livewire(ReviewersRelationManager::class, [
        'ownerRecord' => $this->conference,
        'pageClass' => ViewConference::class,
    ])->assertSee('salah@example.org');
});

it('hangs members and invitations off the organization', function () {
    $owner = User::factory()->create(['name' => 'Basmalah Alabduljabbar']);
    $this->organization->addMember($owner, OrganizationRole::Owner);
    OrganizationInvitation::factory()->for($this->organization)->create(['email' => 'pending@example.org']);

    livewire(MembersRelationManager::class, [
        'ownerRecord' => $this->organization,
        'pageClass' => ViewOrganization::class,
    ])->assertSee('Basmalah Alabduljabbar')->assertSee(OrganizationRole::Owner->getLabel());

    livewire(InvitationsRelationManager::class, [
        'ownerRecord' => $this->organization,
        'pageClass' => ViewOrganization::class,
    ])->assertSee('pending@example.org');
});

it('offers nothing that writes on any of the five relation managers or the review list', function (string $manager, string $owner, string $page) {
    $table = livewire($manager, ['ownerRecord' => $this->{$owner}, 'pageClass' => $page])
        ->instance()
        ->getTable();

    $names = array_merge(
        array_keys($table->getFlatActions()),
        array_keys($table->getFlatBulkActions()),
    );

    // RelationManager adds CreateAction, EditAction and DeleteAction by
    // default, and every one of these parents' policies answers true for a
    // platform admin through before() - so the refusal is the class, not the
    // policy.
    expect(array_diff($names, ['view']))->toBe([]);
})->with([
    [ReviewsRelationManager::class, 'submission', App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission::class],
    [AssignmentsRelationManager::class, 'submission', App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission::class],
    [ReviewersRelationManager::class, 'conference', ViewConference::class],
    [MembersRelationManager::class, 'organization', ViewOrganization::class],
    [InvitationsRelationManager::class, 'organization', ViewOrganization::class],
]);

it('refuses the review list to an organization owner', function () {
    $owner = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);

    actingAs($owner)->get(ReviewResource::getUrl('index', panel: 'admin'))->assertForbidden();
    actingAs($owner)->get(ReviewResource::getUrl('view', ['record' => $this->review], panel: 'admin'))->assertForbidden();
});

it('registers exactly two review routes', function () {
    expect(array_keys(ReviewResource::getPages()))->toBe(['index', 'view']);
});
```

Append to `tests/Feature/Organizer/SubmissionResourceTest.php` (the file already has a `beforeEach` with `$this->organization`, `$this->conference` and a submission — read it and reuse those names), and add `use App\Enums\ConferenceStatus; use App\Enums\ReviewStatus; use App\Models\ConferenceReviewer; use App\Models\Review; use App\Models\ReviewAnswer; use App\Models\ReviewForm; use App\Models\ReviewQuestion;` to its import block in Pint's alphabetical order — that file's header today stops at `App\Models\Track` / `App\Models\User` and this application registers no facade aliases, so without these seven the two cases below are fatal "Class not found" errors rather than failing assertions. Concretely: `ConferenceStatus` goes before `App\Enums\Decision`, `ReviewStatus` between `App\Enums\PresentationPreference` and `App\Enums\SubmissionStatus`, `ConferenceReviewer` between `App\Models\Conference` and `App\Models\Organization`, and `Review`, `ReviewAnswer`, `ReviewForm`, `ReviewQuestion` in that order between `App\Models\Organization` and `App\Models\Submission` (Pint sorts case-insensitively, which is why `ReviewAnswer` precedes `ReviewForm`). `Pest\Laravel\get` is already imported.

```php
it('shows an organizer what the reviewers wrote, once reviewing has started', function () {
    $form = ReviewForm::factory()->for($this->conference)->create(['is_active' => true]);
    $question = ReviewQuestion::factory()->for($form)->create(['question' => 'Is the methodology sound?']);
    $reviewer = User::factory()->create(['name' => 'Dr Salah Almubarak']);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $reviewer->getKey()]);

    $review = Review::factory()->create([
        'submission_id' => $this->submission->getKey(),
        'reviewer_user_id' => $reviewer->getKey(),
        'review_form_id' => $form->getKey(),
        'status' => ReviewStatus::Submitted,
        'submitted_at' => now(),
    ]);
    (new ReviewAnswer)->forceFill([
        'review_id' => $review->getKey(),
        'review_question_id' => $question->getKey(),
        'value_int' => 4, 'value_text' => 'Small sample, sound design.', 'value_bool' => null, 'choice_key' => null,
    ])->save();

    $this->conference->forceFill(['status' => ConferenceStatus::Reviewing])->save();

    // The gap between spec section 4's "View submissions" and the code, closed
    // where an organizer is already standing when the question occurs to them.
    get(SubmissionResource::getUrl('view', ['record' => $this->submission], tenant: $this->organization))
        ->assertOk()
        ->assertSee('Dr Salah Almubarak')
        ->assertSee('Is the methodology sound?')
        ->assertSee('Small sample, sound design.');
});

it('hides a draft review and hides the whole section while the call is still open', function () {
    $form = ReviewForm::factory()->for($this->conference)->create(['is_active' => true]);
    $question = ReviewQuestion::factory()->for($form)->create(['question' => 'Is the methodology sound?']);
    $reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $reviewer->getKey()]);

    $draft = Review::factory()->create([
        'submission_id' => $this->submission->getKey(),
        'reviewer_user_id' => $reviewer->getKey(),
        'review_form_id' => $form->getKey(),
        'status' => ReviewStatus::Draft,
    ]);
    (new ReviewAnswer)->forceFill([
        'review_id' => $draft->getKey(),
        'review_question_id' => $question->getKey(),
        'value_int' => 1, 'value_text' => 'Half-written and still being edited.', 'value_bool' => null, 'choice_key' => null,
    ])->save();

    $this->conference->forceFill(['status' => ConferenceStatus::Reviewing])->save();

    // A draft is a reviewer mid-sentence. Reading it over their shoulder is
    // not what "View submissions" means.
    get(SubmissionResource::getUrl('view', ['record' => $this->submission], tenant: $this->organization))
        ->assertOk()
        ->assertDontSee('Half-written and still being edited.');

    $this->conference->forceFill(['status' => ConferenceStatus::Open])->save();

    get(SubmissionResource::getUrl('view', ['record' => $this->submission], tenant: $this->organization))
        ->assertOk()
        ->assertDontSee(__('reviewer.review.organizer_heading'));
});
```

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Admin/AdminReadOnlyTest.php tests/Feature/Organizer/SubmissionResourceTest.php > /tmp/t-task-6.log 2>&1; echo "rc=$?"; tail -25 /tmp/t-task-6.log
```

Expected: `rc=1`, naming `App\Filament\Admin\Resources\Reviews\ReviewResource`. **Failing-first gate.**

- [ ] **Step 2: `ReviewResource` and its two pages**

The class shape is Task 5's `SubmissionResource`, with `$model = Review::class`, `$recordTitleAttribute = null` (a review has no name), `$isGloballySearchable = false`, the same `canAccess()`, `getPages()` of exactly `index` and `view`, and:

```php
    /**
     * Four relations on every row of the list and the infolist: the abstract,
     * its conference, its organization and the reviewer. Eager-loaded here so a
     * page of fifty reviews is one query and not two hundred.
     *
     * @return Builder<Review>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'submission.conference.organization',
            'reviewer',
        ]);
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [];
    }
```

`Tables/ReviewsTable.php`: columns `submission.reference` (mono), `submission.title` (limit 50, searchable), `submission.conference.name`, `reviewer.name` (searchable), `status` (badge), `score` (numeric 2, sortable, placeholder `-`), `submitted_at` (dateTime, conference timezone). Filters: `SelectFilter::make('status')->options(ReviewStatus::class)->multiple()`, a conference filter through `submission.conference`, and an organization filter through `submission.conference.organization` — both written as `->query(fn (Builder $query, array $data): Builder => …whereHas(…))` exactly as Task 5's are. `recordActions([ViewAction::make()])` and nothing else.

`Schemas/ReviewInfolist.php` — the one screen in the application that prints a review's content:

```php
            Section::make(__('admin.reviews.sections.review'))->columns(3)->components([
                TextEntry::make('reviewer.name')->label(__('admin.reviews.columns.reviewer')),
                TextEntry::make('reviewer.email')->label(__('admin.reviews.columns.email')),
                TextEntry::make('status')->badge(),
                TextEntry::make('score')->numeric(2)->placeholder('-'),
                TextEntry::make('submitted_at')->dateTime('j M Y, H:i')
                    ->timezone(fn (Review $record): string => (string) $record->submission?->conference?->timezone)
                    ->placeholder('-'),
                TextEntry::make('reopened_at')->dateTime('j M Y, H:i')->placeholder('-'),
            ]),

            Section::make(__('admin.reviews.sections.abstract'))->columns(2)->components([
                TextEntry::make('submission.reference')->fontFamily('mono')->placeholder('-'),
                TextEntry::make('submission.title')
                    ->url(fn (Review $record): ?string => $record->submission === null
                        ? null
                        : SubmissionResource::getUrl('view', ['record' => $record->submission], panel: 'admin')),
                TextEntry::make('submission.conference.name'),
                TextEntry::make('submission.conference.organization.name'),
            ]),

            Section::make(__('admin.reviews.sections.answers'))->components([
                // The answers in the FORM's order, not the answers' own, so an
                // admin reads the review the way the reviewer filled it in.
                // ReviewAnswer stores one of four typed columns, which is why
                // the value is formatted rather than printed from a state path.
                RepeatableEntry::make('answers')->hiddenLabel()->columns(1)->schema([
                    TextEntry::make('question.question')
                        ->hiddenLabel()
                        ->weight(FontWeight::SemiBold),
                    TextEntry::make('id')
                        ->hiddenLabel()
                        ->formatStateUsing(fn (ReviewAnswer $record): string => static::answerText($record))
                        ->prose(),
                ]),
            ]),
```

with the one helper that turns a four-column answer into a sentence:

```php
    /**
     * ReviewAnswer stores value_int, value_text, value_bool or choice_key -
     * one of four columns per question type - so there is no single state path
     * to print. This is the only place in the application that renders one for
     * a human, which is why it lives here rather than on the model: Plan 4's
     * reviewer panel renders the *form*, not the answer.
     *
     * PUBLIC static, not protected: the organizer's SubmissionInfolist in
     * step 4 calls `ReviewInfolist::answerText($record)` from another class, so
     * `protected` here is a fatal visibility error there. See the note under
     * step 4.
     */
    public static function answerText(ReviewAnswer $answer): string
    {
        $question = $answer->question;

        $parts = [];

        if ($answer->value_int !== null) {
            $parts[] = $question?->scale_max === null
                ? (string) $answer->value_int
                : $answer->value_int.' / '.$question->scale_max;
        }

        if ($answer->value_bool !== null) {
            $parts[] = $answer->value_bool ? __('admin.reviews.yes') : __('admin.reviews.no');
        }

        if ($answer->choice_key !== null) {
            // The label the organizer wrote, not the stored key: `q3_opt2`
            // means nothing to anybody reading a review.
            $parts[] = (string) ($question?->optionLabels()[$answer->choice_key] ?? $answer->choice_key);
        }

        if (filled($answer->value_text)) {
            $parts[] = (string) $answer->value_text;
        }

        return $parts === [] ? __('admin.reviews.no_answer') : implode(' — ', $parts);
    }
```

**`ReviewQuestion::optionLabels()` is a Plan 4 method** (fact 15 of Plan 5 names it beside `optionScore()` and `optionKeys()`). `grep -n "function optionLabels" app/Models/ReviewQuestion.php` before using it; if it is absent or shaped differently, read `options` off the model and map it there.

- [ ] **Step 3: The five relation managers**

All five share one shape. `ReviewsRelationManager` in full; the other four are the same class with a different `$relationship`, different columns and the same three empty arrays:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\RelationManagers;

use App\Filament\Admin\Resources\Reviews\ReviewResource;
use App\Models\Review;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only, and the refusal is this class rather than the policy.
 * RelationManager adds a CreateAction in the header and Edit/Delete per row by
 * default, ReviewPolicy::before() answers true for a platform admin, and
 * Laravel returns a non-null before() result WITHOUT ever calling the ability -
 * so isReadOnly() plus three empty arrays are what actually refuse.
 */
class ReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviews';

    protected static ?string $title = null;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.reviews.title'))
            ->defaultSort('submitted_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('reviewer'))
            ->columns([
                TextColumn::make('reviewer.name')->label(__('admin.reviews.columns.reviewer')),
                TextColumn::make('status')->badge(),
                TextColumn::make('score')->numeric(2)->placeholder('-'),
                TextColumn::make('submitted_at')->dateTime('j M Y, H:i')->placeholder('-'),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->recordActions([
                // Not a ViewAction: the answers live on ReviewResource's own
                // page, and a second infolist here would be the same code twice.
                Action::make('view')
                    ->label(__('admin.reviews.open'))
                    ->url(fn (Review $record): string => ReviewResource::getUrl('view', ['record' => $record], panel: 'admin'))
                    ->icon(Heroicon::OutlinedEye),
            ]);
    }
}
```

The other four:

| Class | On | `$relationship` | Columns |
|---|---|---|---|
| `AssignmentsRelationManager` | `Submission` | `reviewAssignments` | `reviewer.name`, `assigner.name` (placeholder "a former member"), `assigned_at` |
| `ReviewersRelationManager` | `Conference` | `reviewers` | `user.name`, `user.email`, `affiliation` (placeholder `-`), `status` badge, `accepted_at`, `removed_at` |
| `MembersRelationManager` | `Organization` | `members` | `name`, `email`, `pivot.role` badge, `pivot.notify_on_submission` boolean, `pivot.created_at` |
| `InvitationsRelationManager` | `Organization` | `invitations` | `email`, `role` badge, `inviter.name`, `expires_at`, `accepted_at`, `revoked_at` |

each with `isReadOnly()`, `headerActions([])`, `toolbarActions([])` and `recordActions([])` — only the reviews manager has a row action, because only reviews have a second page to go to.

**`MembersRelationManager` reads pivot columns**, which is the one that will not work by copy-paste: `members` is a `BelongsToMany` with `->withPivot(['role', 'notify_on_submission'])->withTimestamps()`, so the column state path is `pivot.role`, and `role` is cast on the `OrganizationMember` pivot model, not on `User`. If `TextColumn::make('pivot.role')->badge()` renders the raw string rather than the enum label, format it: `->formatStateUsing(fn (mixed $state): string => OrganizationRole::from((string) $state)->getLabel())`.

Then register them:

```php
    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [
            ReviewsRelationManager::class,
            AssignmentsRelationManager::class,
        ];
    }
```

on the admin `SubmissionResource`; `ReviewersRelationManager::class` on the admin `ConferenceResource`; `MembersRelationManager::class` and `InvitationsRelationManager::class` on `OrganizationResource`.

- [ ] **Step 4: The organizer's own read-only reviews section**

`app/Filament/Organizer/Resources/Submissions/Schemas/SubmissionInfolist.php` — one `Section` appended before the existing `Activity` section:

```php
            Section::make(__('reviewer.review.organizer_heading'))
                ->description(__('reviewer.review.organizer_description'))
                // Past the open call, and only then. A draft is a reviewer
                // mid-sentence, and reading one over their shoulder while the
                // call is still open is not what spec section 4's "View
                // submissions" means. ReviewStatus::Submitted is the only
                // status listed, and the section itself is hidden until the
                // conference is closed or later.
                ->visible(fn (Submission $record): bool => ! $record->conference->status->isBefore(ConferenceStatus::Closed)
                    && $record->reviews()->where('status', ReviewStatus::Submitted)->exists())
                ->components([
                    RepeatableEntry::make('submittedReviews')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->schema([
                            TextEntry::make('reviewer.name')->label(__('reviewer.review.by'))->weight(FontWeight::SemiBold),
                            TextEntry::make('score')->numeric(2)->placeholder('-'),
                            RepeatableEntry::make('answers')->hiddenLabel()->columnSpanFull()->schema([
                                TextEntry::make('question.question')->hiddenLabel()->size(TextSize::Small)->color('gray'),
                                TextEntry::make('id')->hiddenLabel()
                                    ->formatStateUsing(fn (ReviewAnswer $record): string => ReviewInfolist::answerText($record))
                                    ->prose(),
                            ]),
                        ]),
                ]),
```

Two things this needs and does not have:

- **`Submission::submittedReviews()`** — a one-line relation beside `reviews()` on the model: `return $this->reviews()->where('status', ReviewStatus::Submitted)->latest('submitted_at');`, typed `HasMany<Review, $this>`. Filtering in the relation rather than in the entry is what keeps a draft out of the *query*, not merely out of the render.
- **`ReviewInfolist::answerText()` must be `public static`** so the organizer infolist can call the admin one. Two copies of that four-column formatter is exactly the kind of duplication that drifts, and a shared `App\Support\Reviews\AnswerText` class for one method is worse than a public static on the screen that owns it. If a reviewer's eye prefers the support class, that is fine too — but there must be **one**.
- **`ConferenceStatus::isBefore()`** may not exist. `grep -n "function isBefore\|function isAtLeast" app/Enums/ConferenceStatus.php`; if it does not, write the visibility as an explicit `in_array($record->conference->status, [ConferenceStatus::Closed, ConferenceStatus::Reviewing, ConferenceStatus::Decided, ConferenceStatus::Archived], true)`.

`lang/en/reviewer.php` gains three keys under the existing `review` group: `organizer_heading`, `organizer_description`, `by`. `lang/en/admin.php` gains a `reviews` group (`title`, `open`, `yes`, `no`, `no_answer`, `columns.*`, `sections.*`, `filters.*`).

- [ ] **Step 5: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Admin/ tests/Feature/Organizer/SubmissionResourceTest.php tests/Feature/Reviewer/ > /tmp/t-task-6.log 2>&1; echo "rc=$?"; tail -12 /tmp/t-task-6.log && \
php artisan test > /tmp/all-task-6.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-6.log
```

Expected: both `rc=0`; `baseline + 109`.

**`tests/Feature/Reviewer/` is in that run deliberately.** Adding `Submission::submittedReviews()` and a section that reads `answers` is the change most likely to trip a Plan 4 query-count assertion, and Plan 5's own note applies: if one goes red, the honest fix is to update that test's number with a comment naming this section, not to move the section.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan route:list --except-vendor | grep -E "admin/(reviews|submissions|conferences|organizations)"
```

Expected: two review routes, two submission routes, and the conference and organization routes unchanged — relation managers add no routes of their own.

- [ ] **Step 6: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all-task-6.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(panels): print what a reviewer wrote, and hang reviewers, members and invitations off the records they belong to

The answers are rendered from four typed columns by one formatter, public on
the admin infolist and called by the organizer's section rather than copied
into it. Every relation manager is read-only by class, because the parent
policies' before() answers true for a platform admin without the ability ever
being called.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---

### Task 7: Content-Security-Policy with a per-request nonce, on the public pages and all three panels

The backlog item that has been open since Plan 1: *"Content-Security-Policy for public pages and panels: needs a nonce strategy compatible with Livewire 4 and Filament 5."* Spec section 9 asks for *"CSP allowing self and inline styles for Livewire"*; HSTS stays Cloudflare's.

**Eight decisions this task makes. Read all eight before writing a line, because five of them are the difference between a CSP that works and a panel nobody can log into.**

**1. `Vite::useCspNonce()` is the whole nonce strategy, and Livewire 4.4.4 needs nothing else.** `FrontendAssets::nonce()` is `$options['nonce'] ?? Vite::cspNonce()` (fact 3), and it feeds Livewire's `<style>`, its `<script src>`, its inline `window.livewireScriptConfig` script **and** the `nonce` value inside that config's JSON, which is what Livewire's own runtime uses for scripts it injects later. One call in one middleware covers `@vite`, `@livewireStyles`, `@livewireScripts` and everything Livewire adds at runtime.

**2. Filament 5.8.1 has no nonce support at all (fact 5), so THREE of its Blade views are published verbatim with the nonce added, and a drift test pins the originals.** `grep -rn "nonce" vendor/filament/*/src/` and `vendor/filament/*/resources/views/` both return **zero hits**. Between them, `filament::assets` and `filament-panels::components.layout.base` emit one inline `<script>` (`window.filamentData`), three more (`localStorage.setItem('theme', …)` twice and `loadDarkMode()`), a `loadDarkMode()` call, and three inline `<style>` blocks. The third view is the one that is easy to miss because it is not part of the layout file: `filament-panels::livewire.sidebar` has **one** bare inline `<script>` at `:155` — the `collapsedGroups` localStorage block inside `fi-sidebar-nav`, not wrapped in Livewire's `@script` — and `components/layout/index.blade.php:83` renders it through `@livewire(filament()->getSidebarLivewireComponent())` on **every authenticated panel page** of all three panels. Under a nonce-only `script-src` it is blocked, the navigation-group collapse state silently breaks, and a `securitypolicyviolation` fires on every panel page. None of the three can be reached by a hook, a config value or a wrapper. The options were: allow `'unsafe-inline'` in `script-src` (which a nonce silently disables anyway, so it would mean *dropping the nonce* on panels); compute hashes (impossible for `window.filamentData`, which varies per page); or publish the three views. Publishing freezes three files against a Filament upgrade, which is a real cost — so `tests/Feature/Security/PublishedFilamentViewsTest.php` stores the SHA-256 of each **vendor original** and fails with instructions when it changes. An upgrade is then a red suite and a five-minute re-publish, not a panel that silently stops rendering.

**3. `script-src` carries `'unsafe-eval'`, deliberately, and this is the honest part of the task.** Livewire bundles Alpine, and Alpine's expression evaluator is one `new Function(["scope"], "with (scope…")` in `vendor/livewire/livewire/dist/livewire.min.js`. Livewire ships a CSP-safe Alpine build (`livewire.csp.min.js`, selected by `config('livewire.csp_safe')` — fact 4) whose evaluator understands property access and method calls only. **Every Filament panel view is full of Alpine expressions that the restricted parser cannot evaluate**, and turning the flag on is global, so it would trade a working panel for a stricter header. It would also break `.github/workflows/ci.yml:179`, which curls `/vendor/livewire/livewire.min.js` by name. So `'unsafe-eval'` stays, and it is worth saying plainly what that costs and what it does not: the nonce is what stops an **injected** `<script>` from running, which is the XSS case spec section 9 is about; `'unsafe-eval'` lets already-trusted, already-loaded code compile strings that the application itself wrote into `x-*` attributes. An attacker who can write those attributes has already won by a different route. Task 14 records it as a deviation and the backlog keeps "drop `unsafe-eval`" as a v2 item behind "Filament ships CSP-safe views".

**4. `style-src-attr 'unsafe-inline'` is unavoidable and is not a shortcut.** A nonce applies to `<style>` *elements* and never to `style` *attributes* (fact 6). This application has about forty-five inline `style=` attributes, and two of them are load-bearing for every page it serves: `components/layouts/conference.blade.php:17`, which carries the organization's branding as CSS custom properties, and `brand/logo.blade.php:15-17`, which is `->brandLogo()` on **all three panels**. The rest are the panel views that use inline styles because there is no organizer theme (the standing Plan 2 backlog item). Splitting `style-src` from `style-src-attr` is what keeps the *element* directive strict while the *attribute* one is permissive — a strictly better position than the `style-src 'unsafe-inline'` the spec sentence would have allowed, and one that gets stricter for free the day the organizer theme lands.

**5. The middleware never overwrites a CSP a response already carries, and never touches a non-HTML response.** `app/Http/Controllers/Public/SubmissionFileController.php:61` sets `default-src 'none'; sandbox` on a download and `docker/nginx.conf:59` sets a strict one on `/storage/`; both are stricter than the app-wide policy and both must survive. The guard is two lines: skip when the header is already set, skip when the `Content-Type` is not HTML. A streamed CSV or XLSX export (Plan 5) and a PDF poster (Plan 2) get nothing, which is correct — they are not documents a browser executes.

**6. It ships behind a switch, and the switch defaults to enforcing.** `CASS_CSP_REPORT_ONLY=true` sends `Content-Security-Policy-Report-Only` instead, so the first production deploy can run a week with a human watching the browser console before the header becomes a gate. The **default is false** so the test suite asserts the real header and nobody forgets to flip it. There is **no report endpoint**: a public POST route that accepts arbitrary JSON from any browser on the internet is a spam sink and a storage cost for a single-operator platform, so report-only mode means an operator with devtools open, which is exactly what Task 13's checklist asks for.

**7. The policy is off while Vite is hot, and only then.** `composer run dev` is the documented local workflow (`CLAUDE.md`) and a nonce-based policy and a Vite dev server cannot both hold: `@vite` emits `<script src="http://localhost:5173/@vite/client">`, the client opens a WebSocket to `ws://localhost:5173`, and — the part no allow-list fixes — it injects `<style>` elements from JavaScript, which a nonced `style-src` refuses because the CSP spec ignores `'unsafe-inline'` whenever a nonce is present. The switch is `Vite::isRunningHot()` (`is_file(public_path('hot'))`), **not** `app()->environment()`, so a local run with built assets still gets the real header — which is what the browser suite tests, and the `hot` file exists in no CI job, no test run and no image.

**8. Every panel gets a local avatar provider, because `img-src` does not name ui-avatars.com.** Filament 5.8.1's default is `UiAvatarsProvider` (`Panel/Concerns/HasAvatars.php:10`), which emits `https://ui-avatars.com/api/?name=<the user's name>` from `components/user-menu.blade.php` and, on the organizer panel, `tenant-menu.blade.php`; neither `User` nor `Organization` implements `HasAvatar` or carries an `avatar_url`. Allowing a third-party host so an initials bubble renders is the wrong trade, and sending every panel user's name to that host on every page load is worse. `App\Support\Panels\InitialsAvatarProvider` returns the initials as a `data:` URI, which `img-src` already allows. Note what would otherwise hide this: every CSP feature case requests a **login** page, which has no avatar — so `SecurityHeadersTest` gets one authenticated-panel case whose only job is `not->toContain('ui-avatars.com')`.

**Files:**
- Create: `app/Http/Middleware/ContentSecurityPolicy.php`, `app/Support/Panels/InitialsAvatarProvider.php`
- Create: `resources/views/vendor/filament/assets.blade.php`, `resources/views/vendor/filament-panels/components/layout/base.blade.php`, `resources/views/vendor/filament-panels/livewire/sidebar.blade.php`
- Modify: `app/Http/Middleware/SecurityHeaders.php`, `bootstrap/app.php`, `config/cass.php`, `.env.example`, `docker-compose.production.yml`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`, `OrganizerPanelProvider.php`, `ReviewerPanelProvider.php` (one `->defaultAvatarProvider()` line each)
- Modify: `resources/views/livewire/public/submission-form.blade.php` (two `<script>` tags), `resources/views/brand/logo.blade.php` (one `<style>` element)
- Modify: `.github/workflows/ci.yml`
- Test: `tests/Feature/Public/SecurityHeadersTest.php`, `tests/Feature/Security/PublishedFilamentViewsTest.php`, `tests/Browser/CspTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Public/SecurityHeadersTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/** Every directive this application's policy must carry, and the reason each is here. */
function policyOf(Illuminate\Testing\TestResponse $response): string
{
    return (string) $response->headers->get('Content-Security-Policy');
}

it('sets a nonce policy on every html page, public and panel', function (string $url) {
    $policy = policyOf(get($url)->assertOk());

    expect($policy)->toContain("default-src 'self'")
        ->and($policy)->toContain("object-src 'none'")
        ->and($policy)->toContain("base-uri 'self'")
        ->and($policy)->toContain("frame-ancestors 'none'")
        ->and($policy)->toContain("form-action 'self'")
        // A fresh 40-character nonce, in both directives.
        ->and($policy)->toMatch("/script-src [^;]*'nonce-[A-Za-z0-9]{40}'/")
        ->and($policy)->toMatch("/style-src [^;]*'nonce-[A-Za-z0-9]{40}'/")
        // Task 7 decision 4: a nonce never reaches a style ATTRIBUTE, and this
        // application has about forty-five of them - including the
        // organization's branding variables and the brand lock-up on all three
        // panels.
        ->and($policy)->toContain("style-src-attr 'unsafe-inline'")
        // Task 7 decision 3: Alpine's evaluator is new Function(), Livewire
        // bundles Alpine, and Filament's views are written in a dialect the
        // CSP-safe build cannot parse.
        ->and($policy)->toContain("'unsafe-eval'")
        ->and($policy)->toContain('https://challenges.cloudflare.com')
        ->and($policy)->not->toContain("script-src 'self' 'unsafe-inline'");
})->with(['/', '/about', '/org/login', '/admin/login', '/review/login']);

it('mints a different nonce per request, and puts the same one in every tag', function () {
    $first = get('/');
    $second = get('/');

    preg_match("/'nonce-([A-Za-z0-9]{40})'/", policyOf($first), $one);
    preg_match("/'nonce-([A-Za-z0-9]{40})'/", policyOf($second), $two);

    expect($one[1] ?? 'a')->not->toBe($two[1] ?? 'b');

    // Laravel's Vite tags carry it, which is the half of fact 2 this app owns.
    $first->assertSee('nonce="'.$one[1].'"', escape: false);
});

it('nonces the livewire script and its config on a page that uses livewire', function () {
    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->create([
        'status' => ConferenceStatus::Open,
        'submission_opens_at' => now()->subWeek(),
        'submission_deadline' => now()->addWeek(),
    ]);

    $response = get('/c/'.$organization->slug.'/'.$conference->slug.'/submit')->assertOk();

    preg_match("/'nonce-([A-Za-z0-9]{40})'/", policyOf($response), $matches);
    $nonce = $matches[1] ?? '';

    expect($nonce)->not->toBe('');

    // FrontendAssets::nonce() reads Vite::cspNonce(), so ONE call in the
    // middleware covers the script tag, the styles and the inline config.
    $html = $response->getContent();

    expect(substr_count((string) $html, 'nonce="'.$nonce.'"'))->toBeGreaterThanOrEqual(3)
        ->and($html)->toContain('window.livewireScriptConfig');
});

it('nonces the turnstile callback and allows cloudflare to be framed', function () {
    config()->set('cass.turnstile.site_key', 'site-key');
    config()->set('cass.turnstile.secret_key', 'secret-key');

    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->create([
        'status' => ConferenceStatus::Open,
        'submission_opens_at' => now()->subWeek(),
        'submission_deadline' => now()->addWeek(),
    ]);

    $response = get('/c/'.$organization->slug.'/'.$conference->slug.'/submit')->assertOk();
    $policy = policyOf($response);

    // Plan 3's backlog named all three: the branding style attribute, the
    // inline Turnstile callback, and challenges.cloudflare.com as a script
    // source. "A nonce strategy that forgets the third turns the widget into a
    // form nobody can submit."
    expect($policy)->toContain('frame-src https://challenges.cloudflare.com')
        ->and($policy)->toContain('connect-src')
        ->and($response->getContent())->toContain('cassTurnstileCallback');

    preg_match("/'nonce-([A-Za-z0-9]{40})'/", $policy, $matches);
    $response->assertSee('<script nonce="'.($matches[1] ?? '').'">', escape: false);
});

it('leaves a stricter policy alone on a file download', function () {
    Storage::fake('local');
    $submission = Submission::factory()->submitted()->create();
    $file = SubmissionFile::factory()->for($submission)->create();
    Storage::disk('local')->put((string) $file->path, 'pdf bytes');

    $response = get($file->temporaryUrl())->assertOk();

    // SubmissionFileController sets default-src 'none'; sandbox. A middleware
    // that overwrote it would turn a sandboxed download into a document with
    // the app's own script sources.
    expect(policyOf($response))->toBe("default-src 'none'; sandbox");
});

it('can be switched to report-only for the first week on production', function () {
    config()->set('cass.security.csp_report_only', true);

    $response = get('/')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBeNull()
        ->and((string) $response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'");
});

it('still sets the four plan 1 headers and adds two more', function () {
    $response = get('/')->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and((string) $response->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->and($response->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin')
        ->and($response->headers->get('X-Permitted-Cross-Domain-Policies'))->toBe('none')
        // Spec section 9 gives HSTS to Cloudflare. The app must not also send
        // one, or two sources of truth disagree about max-age at the worst
        // possible moment.
        ->and($response->headers->get('Strict-Transport-Security'))->toBeNull();
});

it('sets the policy on an error page too', function () {
    // The reason ContentSecurityPolicy is GLOBAL rather than web-group
    // middleware: a 404 is a page a browser renders, and Laravel's error view
    // is where an injected script would be least expected. LandingPageTest's
    // sibling case pins the same property for SecurityHeaders, on the same URL.
    $response = get('/nonexistent-probe.css')->assertNotFound();

    expect(policyOf($response))->toContain("default-src 'self'")
        ->and(policyOf($response))->toMatch("/script-src [^;]*'nonce-[A-Za-z0-9]{40}'/");
});

it('nonces the brand lock-up stylesheet the layout inlines', function () {
    // brand/logo.blade.php ends in a <style> ELEMENT (the dark-mode wordmark
    // rule, which has no inline-attribute equivalent). style-src is 'self'
    // plus the nonce, so an un-nonced copy is refused and nothing else in this
    // suite would notice: the page still renders, just with a blue wordmark on
    // a dark panel.
    $response = get('/')->assertOk();

    preg_match("/'nonce-([A-Za-z0-9]{40})'/", policyOf($response), $matches);

    expect((string) $response->getContent())
        ->toContain('<style nonce="'.($matches[1] ?? '').'">.dark .cass-wordmark');
});

it('renders an authenticated panel page with no off-origin image source', function () {
    $admin = User::factory()->platformAdmin()->create();

    $html = (string) actingAs($admin)->get('/admin')->assertOk()->getContent();

    // Filament's default avatar provider is UiAvatarsProvider, which emits
    // https://ui-avatars.com/api/?name=<the user's name> in the topbar of every
    // authenticated page - cross-origin under img-src, and every panel user's
    // name sent to a third party on every page load. Every case above requests
    // a LOGIN page, which has no avatar, so nothing else here would catch it.
    expect($html)->not->toContain('ui-avatars.com');
});
```

`tests/Feature/Security/PublishedFilamentViewsTest.php`
```php
<?php

declare(strict_types=1);

/**
 * Filament 5.8.1 has no nonce support anywhere (Plan 6 fact 5), so THREE of its
 * Blade views are published into resources/views/vendor with a nonce added.
 * Publishing freezes them against an upgrade, and this is the alarm.
 *
 * When a case here fails, the fix is NOT to update the hash. It is:
 *   1. diff the new vendor file against the published copy;
 *   2. re-publish it, re-adding the nonce attributes listed below;
 *   3. then update the hash here, in the same commit.
 */
it('has not drifted from the filament view it was published from', function (string $vendor, string $published, string $sha, array $nonced) {
    $vendorPath = base_path($vendor);
    $publishedPath = base_path($published);

    expect(file_exists($vendorPath))->toBeTrue("Missing {$vendor} - did Filament move it?")
        ->and(file_exists($publishedPath))->toBeTrue("Missing {$published} - the panel has no nonce without it.");

    expect(hash_file('sha256', $vendorPath))->toBe(
        $sha,
        "{$vendor} changed. Re-publish {$published} from it, re-add the nonce, then update this hash."
    );

    $copy = (string) file_get_contents($publishedPath);

    // Every tag that has to carry one, counted. A re-publish that forgets one
    // is a panel whose theme toggle silently stops working.
    foreach ($nonced as $needle => $expected) {
        expect(substr_count($copy, (string) $needle))->toBe($expected, (string) $needle);
    }
})->with([
    'filament::assets' => [
        'vendor/filament/support/resources/views/assets.blade.php',
        'resources/views/vendor/filament/assets.blade.php',
        // Replace with the real digest from Step 2's command.
        '<sha256 of the vendor file>',
        // One inline <script> (window.filamentData) and one inline <style>.
        ['{{ $cspNonce }}' => 2],
    ],
    'filament-panels::layout.base' => [
        'vendor/filament/filament/resources/views/components/layout/base.blade.php',
        'resources/views/vendor/filament-panels/components/layout/base.blade.php',
        '<sha256 of the vendor file>',
        // Two <style> and five <script>: [x-cloak], the :root variables, the
        // two theme setters, loadDarkMode()'s definition, the echo bootstrap
        // and the loadDarkMode() call.
        ['{{ $cspNonce }}' => 7],
    ],
    'filament-panels::livewire.sidebar' => [
        'vendor/filament/filament/resources/views/livewire/sidebar.blade.php',
        'resources/views/vendor/filament-panels/livewire/sidebar.blade.php',
        '<sha256 of the vendor file>',
        // One inline <script>: the collapsedGroups localStorage block.
        ['{{ $cspNonce }}' => 1],
    ],
]);
```

and the docblock above it says **three** views, not two.

**Two cases appended to `tests/Feature/Public/CustomDomainRoutingTest.php`**, which Task 3 wrote. They live there rather than in `SecurityHeadersTest` because both need the verified-domain fixture that file's `beforeEach` builds, and Task 3 deliberately did not write them — the middleware they assert did not exist yet:

```php
it('allows the branding origin in the image policy, because the logo is not same-origin here', function () {
    $this->organization->forceFill(['logo_path' => 'logo.png'])->save();

    $response = onDomain('/annual-meeting')->assertOk();
    $platform = rtrim((string) config('app.url'), '/');

    // config/filesystems.php:55 builds the branding disk's url from APP_URL, so
    // on this host the logo and the og:image are cross-origin and img-src
    // 'self' alone would blank both - the first thing an organizer would see on
    // the domain they just verified.
    expect((string) $response->headers->get('Content-Security-Policy'))->toContain('img-src')
        ->and((string) $response->headers->get('Content-Security-Policy'))->toContain($platform)
        ->and($response->getContent())->toContain($platform.'/storage/branding/logo.png');
});

it('still sends a policy on the 404 a reserved path produces', function () {
    // ContentSecurityPolicy is appended BEFORE ResolveCustomDomain for exactly
    // this: Illuminate\Routing\Pipeline catches the abort() at the pipe that
    // threw and returns the rendered response upward, so anything appended
    // after it never runs for a refused host or a reserved path - and those
    // 404s are documents a browser renders, with @vite tags in them.
    $response = onDomain('/register')->assertNotFound();

    expect((string) $response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'");
});
```

`tests/Browser/CspTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Models\Conference;
use App\Models\Organization;

/**
 * The CSP, in a real browser.
 *
 * Note what this does NOT use: assertNoConsoleLogs(). The plugin collects
 * console output by hooking console.* from an init script
 * (Pest\Browser\Playwright\Page::consoleLogs() reads
 * window.__pestBrowser.consoleLogs), and a CSP violation is reported by the
 * browser itself rather than through console.log - so that assertion would
 * pass on a page where every script was blocked.
 *
 * Instead each case PROBES the policy: it appends an un-nonced inline <script>
 * to the DOM and asserts it did not run (a DOM-inserted script IS subject to
 * CSP, unlike Playwright's own evaluate), and then asserts that the things the
 * page needs are there - which is what fails if the nonce is wrong.
 *
 * Every case here relies on phpunit.browser.xml's APP_URL being
 * http://127.0.0.1 (Task 3 Step 4): the plugin serves the application from
 * that host, the landing route is host-constrained to APP_URL's host, and
 * ResolveCustomDomain 404s anything else. A 404 here is that, not a CSP
 * failure - the browser run at the end of Task 3 is what separates them.
 */
$probe = <<<'JS'
(() => {
    // No securitypolicyviolation listener: the browser fires that event from a
    // queued task, not synchronously from appendChild, so anything read back
    // inside this IIFE is always empty and would assert nothing. The injected
    // script below is the real probe - a DOM-inserted inline script IS subject
    // to CSP, unlike Playwright's own evaluate().
    const injected = document.createElement('script');
    injected.textContent = 'window.__cspInlineRan = true;';
    document.body.appendChild(injected);

    return {
        inlineRan: window.__cspInlineRan === true,
        hasLivewire: typeof window.Livewire !== 'undefined',
        hasAlpine: typeof window.Alpine !== 'undefined',
        hasDarkMode: typeof window.loadDarkMode === 'function',
        // The sidebar's own inline <script> writes this key on first render
        // (filament-panels::livewire.sidebar:155). It is null on a page that
        // has no sidebar and an empty string on a blocked one.
        collapsedGroups: window.localStorage.getItem('collapsedGroups'),
        bodyBackground: getComputedStyle(document.body).backgroundColor,
    };
})()
JS;

it('refuses an injected inline script on the landing page, and still loads its stylesheet', function () use ($probe) {
    $result = visit('/')->assertSee('CASS')->script($probe);

    // The whole point of a nonce: an inline script the application did not
    // write does not run.
    expect($result['inlineRan'])->toBeFalse()
        // And style-src 'self' still let the Vite bundle through, so the page
        // is not unstyled - which is what a wrong style-src looks like.
        ->and($result['bodyBackground'])->not->toBe('rgba(0, 0, 0, 0)');
})->group('browser');

it('runs livewire and alpine on the submission form under the policy', function () use ($probe) {
    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->create([
        'status' => ConferenceStatus::Open,
        'submission_opens_at' => now()->subWeek(),
        'submission_deadline' => now()->addWeek(),
    ]);

    $result = visit('/c/'.$organization->slug.'/'.$conference->slug.'/submit')->script($probe);

    // If the nonce on Livewire's script tag were wrong, this is where it shows:
    // window.Livewire would be undefined and the form would be inert.
    expect($result['hasLivewire'])->toBeTrue()
        ->and($result['hasAlpine'])->toBeTrue()
        ->and($result['inlineRan'])->toBeFalse();
})->group('browser');

it('renders a panel login with filament own inline scripts intact', function () use ($probe) {
    $result = visit('/org/login')->assertSee('CASS')->script($probe);

    // loadDarkMode() is defined by the inline <script> at
    // vendor/filament/filament/resources/views/components/layout/base.blade.php:108-125.
    // If the published copy forgot its nonce, the CSP blocks that script and
    // this is undefined - which is the exact failure the drift test exists to
    // make loud.
    expect($result['hasDarkMode'])->toBeTrue()
        ->and($result['hasLivewire'])->toBeTrue()
        ->and($result['inlineRan'])->toBeFalse();
})->group('browser');

it('runs the sidebar script on a signed-in panel page', function () use ($probe) {
    // /org/login has no sidebar, so the third published view is not exercised
    // by the case above at all. filament-panels::livewire.sidebar is rendered
    // by @livewire(filament()->getSidebarLivewireComponent())
    // (components/layout/index.blade.php:83) on EVERY authenticated panel page,
    // and its one bare inline <script> writes `collapsedGroups` into
    // localStorage on first render. Blocked by the CSP, the key is never
    // written and the navigation-group collapse state silently stops working.
    $admin = App\Models\User::factory()->platformAdmin()->create();

    $result = visit('/admin')->actingAs($admin)->assertSee('CASS')->script($probe);

    expect($result['collapsedGroups'])->not->toBeNull()
        ->and($result['hasDarkMode'])->toBeTrue()
        ->and($result['inlineRan'])->toBeFalse();
})->group('browser');
```

**If `visit()->actingAs()` is not the plugin's shape in 5.0.1**, sign in through the form instead — `visit('/admin/login')->fill('email', …)->fill('password', …)->press('Sign in')` — and keep the assertion. The point of the case is the sidebar's script, not the authentication mechanism.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Public/SecurityHeadersTest.php tests/Feature/Security/PublishedFilamentViewsTest.php > /tmp/t-task-7.log 2>&1; echo "rc=$?"; tail -20 /tmp/t-task-7.log
```

Expected: `rc=1`, every case failing on a missing `Content-Security-Policy` header. **Failing-first gate.** The browser file is run separately in Step 6.

- [ ] **Step 2: Publish the three Filament views and record their digests**

```bash
cd /c/Users/ahmed/Documents/CASS && \
mkdir -p resources/views/vendor/filament resources/views/vendor/filament-panels/components/layout resources/views/vendor/filament-panels/livewire && \
cp vendor/filament/support/resources/views/assets.blade.php resources/views/vendor/filament/assets.blade.php && \
cp vendor/filament/filament/resources/views/components/layout/base.blade.php resources/views/vendor/filament-panels/components/layout/base.blade.php && \
cp vendor/filament/filament/resources/views/livewire/sidebar.blade.php resources/views/vendor/filament-panels/livewire/sidebar.blade.php && \
sha256sum vendor/filament/support/resources/views/assets.blade.php vendor/filament/filament/resources/views/components/layout/base.blade.php vendor/filament/filament/resources/views/livewire/sidebar.blade.php
```

**Copied, not `vendor:publish`-ed.** `php artisan vendor:publish --tag=filament-panels-views` would publish *every* panel view — dozens of files, each one frozen against the next upgrade — to nonce a handful of tags in three of them. Laravel's view finder checks `resources/views/vendor/<namespace>/…` per file, so three files is exactly three files.

Paste the three digests into the dataset in `PublishedFilamentViewsTest.php`.

Then edit each copy. In `resources/views/vendor/filament/assets.blade.php`, add one line at the top and a `nonce` to the two inline tags:

```blade
@php
    // Filament 5.8.1 has no nonce support (Plan 6 fact 5). This file is a
    // verbatim copy of vendor/filament/support/resources/views/assets.blade.php
    // with two nonce attributes added. tests/Feature/Security/PublishedFilamentViewsTest.php
    // fails if the vendor original changes, so an upgrade is a red suite and a
    // re-publish rather than a panel that silently stops rendering.
    $cspNonce = \Illuminate\Support\Facades\Vite::cspNonce();
@endphp

@if (isset($data))
    <script nonce="{{ $cspNonce }}">
        window.filamentData = @js($data)
    </script>
@endif

@foreach ($assets as $asset)
    @if (! $asset->isLoadedOnRequest())
        {{ $asset->getHtml() }}
    @endif
@endforeach

<style nonce="{{ $cspNonce }}">
    :root {
        @foreach ($cssVariables ?? [] as $cssVariableName => $cssVariableValue) --{{ $cssVariableName }}:{{ $cssVariableValue }}; @endforeach
    }

    @foreach ($customColors ?? [] as $customColorName => $customColorShades) .fi-color-{{ $customColorName }} { @foreach ($customColorShades as $customColorShade) --color-{{ $customColorShade }}:var(--{{ $customColorName }}-{{ $customColorShade }}); @endforeach } @endforeach
</style>
```

In `resources/views/vendor/filament-panels/components/layout/base.blade.php`, add the same `@php` block at the very top and a `nonce="{{ $cspNonce }}"` to **every** `<style>` and `<script>` that has no `src` — seven of them at `:46`, `:80`, `:100`, `:104`, `:108`, `:152` and `:160` of the vendor file. **Change nothing else.** The `<script src=…>` tags Filament emits come from `Js::getHtml()` and are external, so they need no nonce; the `{{ $asset->getHtml() }}` loop above is untouched for the same reason.

In `resources/views/vendor/filament-panels/livewire/sidebar.blade.php`, add the same `@php $cspNonce = \Illuminate\Support\Facades\Vite::cspNonce(); @endphp` block at the very top and change the one bare `<script>` at `:155` (the `collapsedGroups` localStorage block inside `fi-sidebar-nav`) to `<script nonce="{{ $cspNonce }}">`. **It is one tag.** The other Filament views that contain `<script>` — `components/page/index.blade.php`, `components/unsaved-action-changes-alert.blade.php`, `notifications/…/notifications.blade.php` and `database-notifications.blade.php` — are all inside Livewire's `@script`/`@endscript`, which never emits a raw inline tag and is covered by `'unsafe-eval'`, so they need nothing.

Three checks before moving on:

```bash
cd /c/Users/ahmed/Documents/CASS && \
diff <(sed 's/ nonce="{{ \$cspNonce }}"//g; /\$cspNonce = /d; /^@php$/d; /^@endphp$/d; /Filament 5.8.1 has no nonce/,+4d' resources/views/vendor/filament/assets.blade.php) vendor/filament/support/resources/views/assets.blade.php && echo "assets: only the nonce differs" ; \
grep -c 'nonce="{{ $cspNonce }}"' resources/views/vendor/filament-panels/components/layout/base.blade.php ; \
grep -c 'nonce="{{ $cspNonce }}"' resources/views/vendor/filament-panels/livewire/sidebar.blade.php
```

Expected: the diff clean apart from blank lines, then `7`, then `1`.

- [ ] **Step 3: The middleware**

`app/Http/Middleware/ContentSecurityPolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec section 9's Content-Security-Policy, with a per-request nonce.
 *
 * ONE call does most of the work. Vite::useCspNonce() nonces every @vite tag
 * (Illuminate\Foundation\Vite::nonceAttribute()), and Livewire 4.4.4 reads
 * Vite::cspNonce() itself in FrontendAssets::nonce() - so its <style>, its
 * <script src>, its inline window.livewireScriptConfig and the nonce it hands
 * its own runtime all follow from it. Filament 5.8.1 has no nonce support at
 * all, which is why three of its views are published into
 * resources/views/vendor with {{ Vite::cspNonce() }} added - filament::assets,
 * filament-panels::components.layout.base and filament-panels::livewire.sidebar
 * - pinned by tests/Feature/Security/PublishedFilamentViewsTest.php.
 *
 * Global (bootstrap/app.php), so it covers the public pages, all three panels
 * and the /livewire endpoints alike.
 */
class ContentSecurityPolicy
{
    /**
     * The platform's own origin, named explicitly because a page served on a
     * verified CUSTOM domain still loads the organization's logo from
     * APP_URL: config/filesystems.php:55 builds the branding disk's url as
     * APP_URL.'/storage/branding', which is cross-origin there. 'self' alone
     * would blank every organizer's logo - and every og:image - on the domain
     * they just verified. It is a placeholder because config('app.url') cannot
     * be read from a constant; policy() substitutes it per request.
     */
    private const PLATFORM_ORIGIN = '__platform__';

    /**
     * Everything below is a deliberate choice, and the four that look loose
     * are argued in Plan 6 Task 7:
     *
     * - 'unsafe-eval' in script-src: Alpine's evaluator is new Function(), and
     *   Livewire's CSP-safe build understands a dialect Filament's views are
     *   not written in. The nonce is what stops an INJECTED script; this lets
     *   already-loaded trusted code compile strings the app itself wrote.
     * - style-src-attr 'unsafe-inline': a nonce never reaches a style
     *   ATTRIBUTE, and the organization's branding variables and the brand
     *   lock-up on all three panels are attributes.
     * - challenges.cloudflare.com in script-src, connect-src and frame-src:
     *   Turnstile is a script, a fetch and an iframe. Plan 3's backlog: "a
     *   nonce strategy that forgets the third turns the widget into a form
     *   nobody can submit."
     * - img-src blob:: Filament's FileUpload previews an image the visitor
     *   just chose from a blob URL, and the submission form uses that endpoint.
     * - img-src PLATFORM_ORIGIN: the branding disk builds absolute URLs on
     *   APP_URL, so on a verified custom domain the organization's own logo
     *   and the og:image are cross-origin.
     *
     * @var array<string, list<string>>
     */
    private const DIRECTIVES = [
        'default-src' => ["'self'"],
        'base-uri' => ["'self'"],
        'object-src' => ["'none'"],
        'frame-ancestors' => ["'none'"],
        'form-action' => ["'self'"],
        'script-src' => ["'self'", "'unsafe-eval'", 'https://challenges.cloudflare.com'],
        'style-src' => ["'self'"],
        'style-src-attr' => ["'unsafe-inline'"],
        'img-src' => ["'self'", 'data:', 'blob:', self::PLATFORM_ORIGIN],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'", 'https://challenges.cloudflare.com'],
        'frame-src' => ['https://challenges.cloudflare.com'],
        'media-src' => ["'none'"],
        'worker-src' => ["'self'", 'blob:'],
        'manifest-src' => ["'self'"],
    ];

    /** The two directives the nonce is appended to. */
    private const NONCED = ['script-src', 'style-src'];

    public function handle(Request $request, Closure $next): Response
    {
        // BEFORE $next: every tag downstream reads Vite::cspNonce(), so the
        // nonce has to exist before a single view renders.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        if (! $this->applies($response)) {
            return $response;
        }

        $header = $this->headerName();
        $response->headers->set($header, $this->policy($nonce));

        return $response;
    }

    /**
     * HTML only, and never over the top of a stricter policy a controller
     * already set. SubmissionFileController sends "default-src 'none'; sandbox"
     * on a download; overwriting it would turn a sandboxed file into a document
     * carrying this application's script sources.
     */
    private function applies(Response $response): bool
    {
        // `composer run dev` serves assets from http://localhost:5173 and the
        // Vite client both loads scripts from that origin and injects <style>
        // elements from JavaScript. Adding the origin to script-src/connect-src
        // would fix the first; nothing fixes the second, because style-src
        // carries a nonce and the CSP spec ignores 'unsafe-inline' whenever a
        // nonce is present. So while the hot file exists, send no policy: the
        // alternative is every local page unstyled with no hot reload and no
        // error a developer can attribute to CSP.
        //
        // Vite::isRunningHot() is is_file(public_path('hot')). That file exists
        // only while `npm run dev` is running - never in CI, never in the
        // browser suite (which runs against `npm run build`), never in the
        // image - so no test and no production response is affected.
        if (Vite::isRunningHot()) {
            return false;
        }

        if ($response->headers->has('Content-Security-Policy')
            || $response->headers->has('Content-Security-Policy-Report-Only')) {
            return false;
        }

        $type = (string) $response->headers->get('Content-Type', '');

        // An empty Content-Type is Laravel's default HTML response before the
        // header is finalised, so it counts; a CSV, an XLSX and a PDF do not.
        return $type === '' || str_contains($type, 'text/html');
    }

    private function headerName(): string
    {
        // Report-only for the first production week, with a human watching the
        // browser console. There is no report endpoint on purpose: a public
        // POST that any browser on the internet can fill is a spam sink.
        return config('cass.security.csp_report_only')
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }

    private function policy(string $nonce): string
    {
        $parts = [];
        $platform = rtrim((string) config('app.url'), '/');

        foreach (self::DIRECTIVES as $directive => $sources) {
            $sources = array_values(array_filter(array_map(
                static fn (string $source): string => $source === self::PLATFORM_ORIGIN ? $platform : $source,
                $sources,
            ), static fn (string $source): bool => $source !== ''));

            if (in_array($directive, self::NONCED, true)) {
                $sources = [...$sources, "'nonce-{$nonce}'"];
            }

            $parts[] = $directive.' '.implode(' ', $sources);
        }

        return implode('; ', $parts);
    }
}
```

`app/Http/Middleware/SecurityHeaders.php` — two headers appended after the four Plan 1 wrote. **Do not remove or reorder the existing four**: `.github/workflows/ci.yml:216` greps for `x-frame-options: DENY` on a 404 fall-through to tell an nginx 404 from a Laravel one.

```php
        // Cross-origin isolation for the panels: an opener that keeps a handle
        // on a window it launched can read window.name and navigate it. Nothing
        // in this application is opened by a third party on purpose.
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        // No crossdomain.xml is served, and this says so rather than leaving a
        // legacy Flash/PDF policy fetch to a 404 page.
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
```

`bootstrap/app.php` — append after `SecurityHeaders` and **BEFORE** `ResolveCustomDomain` (Task 3 Step 3 shows the same two lines in the same order, so the order is decided in one place):

```php
        // Before ResolveCustomDomain, not after: Illuminate\Routing\Pipeline
        // catches an abort() at the pipe that threw and returns the rendered
        // response UPWARD, so anything appended after that middleware never
        // runs for a refused host or a reserved path - and those 404s are
        // documents a browser renders, with @vite tags in them. Minting the
        // nonce first also means it exists before any view is compiled, which
        // is what the "still sends a policy on the 404 a reserved path
        // produces" case in CustomDomainRoutingTest asserts.
        $middleware->append(ContentSecurityPolicy::class);
```

**All three panel providers get a local avatar provider.** Filament's default is `UiAvatarsProvider` (`vendor/filament/filament/src/Panel/Concerns/HasAvatars.php:10`), which puts `https://ui-avatars.com/api/?name=<the user's name>` in the topbar of every authenticated page and in the organizer panel's tenant menu. `img-src` deliberately does not name that host: allowing a third party so an initials bubble renders is the wrong trade, and sending every panel user's name to it on every page load is worse. In `AdminPanelProvider`, `OrganizerPanelProvider` and `ReviewerPanelProvider` add one line:

```php
            // Initials as a data: URI, not a fetch to ui-avatars.com - which
            // img-src does not allow and which would send every panel user's
            // name to a third party on every page load.
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
```

and create `app/Support/Panels/InitialsAvatarProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Panels;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class InitialsAvatarProvider implements AvatarProvider
{
    // Widened from the contract's Model to match UiAvatarsProvider: Filament
    // passes the Authenticatable for a user and a Model for a tenant.
    public function get(Model | Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))
            ->trim()->explode(' ')
            ->map(fn (string $segment): string => mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $segment) ?? '', 0, 1))
            ->filter()->take(2)->implode('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#18181b"/>'
            .'<text x="32" y="32" fill="#ffffff" font-family="sans-serif" font-size="26" text-anchor="middle" dominant-baseline="central">'
            .e(mb_strtoupper($initials)).'</text></svg>';

        // data: is already in img-src.
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
```

`config/cass.php` — a `security` block beside `domains`:

```php
    'security' => [
        // True sends Content-Security-Policy-Report-Only instead, so the first
        // production week can run with a human watching the browser console
        // before the header becomes a gate. There is no report endpoint.
        'csp_report_only' => filter_var(env('CASS_CSP_REPORT_ONLY', false), FILTER_VALIDATE_BOOL),
    ],
```

and `.env.example`:

```bash
# --- Security (Plan 6) ---
# Send Content-Security-Policy-Report-Only instead of the enforcing header.
# Deploy with this true, watch the browser console on every flow for a week
# (docs/launch-checklist.md), then set it false. There is no report endpoint:
# report-only means a person with devtools open.
CASS_CSP_REPORT_ONLY=false
```

`docker-compose.production.yml` — **one line in the `app` service's `environment:` block, beside the Turnstile pair.** Coolify's Environment Variables screen reaches the container only through `${...}` interpolation in this file (its header comment says so), so a variable that is not named here never reaches any process and the report-only rollout Task 13's checklist orders could not be performed on the live host at all:

```yaml
      # Send Content-Security-Policy-Report-Only instead of the enforcing
      # header. Deploy with this 'true', walk docs/launch-checklist.md section 4
      # with the browser console open, then set it 'false' and redeploy.
      CASS_CSP_REPORT_ONLY: ${CASS_CSP_REPORT_ONLY:-false}
```

- [ ] **Step 4: The two inline scripts on the submission form**

`resources/views/livewire/public/submission-form.blade.php:70` and `:79` — the Turnstile callback and Cloudflare's own `api.js`:

```blade
                    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
                        window.cassTurnstileCallback = (token) => window.Livewire.find('{{ $this->getId() }}').set('turnstileToken', token, false);
```

The external `<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer>` needs **no** nonce — `script-src` already names the host — but give it one anyway is *wrong*: a nonce on an external script is allowed but adds nothing, and a reader would wonder why one tag has it and the other does not. Leave it as it is, and add one comment line saying the host is in `script-src` instead.

Then grep for any other inline script this application emits:

```bash
cd /c/Users/ahmed/Documents/CASS && grep -rn "<script\|<style" resources/views/ --include=*.blade.php | grep -v "resources/views/vendor/"
```

Expected: **three** — the two `<script>` tags in `livewire/public/submission-form.blade.php` and the `<style>` element at `resources/views/brand/logo.blade.php:19`, which is `->brandLogo()` on all three panels and is `@include`d by `components/layouts/public.blade.php:18`. (`pdf/conference-poster.blade.php` is dompdf, which has no CSP; `resources/views/vendor/mail/**` is email.) `style-src` is `'self'` plus the nonce, so that element needs `nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"` or the dark-mode wordmark silently stops applying on every panel — the page still renders, just with a blue wordmark on a dark panel, which is why `SecurityHeadersTest` pins it with its own narrow case. **Anything else found here is a tag that will silently stop running under the new header**, and it either gets the nonce or moves into `resources/js/`.

And for inline event handlers, which no nonce can rescue:

```bash
cd /c/Users/ahmed/Documents/CASS && grep -rnE '\son(click|change|submit|load|error|input|focus|blur)=' resources/views/ --include=*.blade.php | grep -v "x-on:" | grep -v "wire:"
```

Expected: **nothing**. An `onclick="…"` attribute is refused by `script-src` with or without a nonce (only `'unsafe-hashes'` would allow it), and Alpine's `x-on:` / Livewire's `wire:` are attributes read by JavaScript, not handlers the browser compiles — so they are unaffected. If a hit appears, rewrite it as `x-on:` or move it into a listener in `resources/js/`.

- [ ] **Step 5: Run the feature tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Public/ tests/Feature/Security/ tests/Feature/Organizer/PanelAccessTest.php tests/Feature/Reviewer/PanelAccessTest.php > /tmp/t-task-7.log 2>&1; echo "rc=$?"; tail -12 /tmp/t-task-7.log && \
php artisan test > /tmp/all-task-7.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-7.log
```

Expected: both `rc=0`; `baseline + 132`. `tests/Feature/Public/` covers `CustomDomainRoutingTest`, so the two cases Step 1 appended to it — `allows the branding origin in the image policy…` and `still sends a policy on the 404 a reserved path produces` — run here; a red first one means `img-src` lost `PLATFORM_ORIGIN`, and a red second one means `ContentSecurityPolicy` was appended **after** `ResolveCustomDomain` in `bootstrap/app.php`. The three new `SecurityHeadersTest` cases are in the same run: `sets the policy on an error page too`, `nonces the brand lock-up stylesheet the layout inlines` and `renders an authenticated panel page with no off-origin image source`, plus the third dataset row in `PublishedFilamentViewsTest`.

**The two `PanelAccessTest` files are in that run because they are the fastest proof the published Filament views still render.** A Blade syntax error in the published `base.blade.php` makes every panel page a 500, and those two files are the ones that open all three.

- [ ] **Step 6: The browser suite**

```bash
cd /c/Users/ahmed/Documents/CASS && npm run build > /tmp/build.log 2>&1; echo "build rc=$?" && \
./vendor/bin/pest --configuration=phpunit.browser.xml > /tmp/browser.log 2>&1; echo "browser rc=$?"; tail -20 /tmp/browser.log
```

Expected: both `rc=0`, five cases (Plan 3's submission run plus this task's four).

**`npm run build` first, every time.** `phpunit.browser.xml` serves the application in-process and `@vite` throws without a manifest, so a stale or missing `public/build` is a 500 on every page and three failures that look like CSP failures and are not.

**If the third case fails on `hasDarkMode`**, the published `base.blade.php` is missing a nonce on the script at vendor line 108 — count them again (Step 2's grep says 7). **If it fails on `hasLivewire`**, `Vite::useCspNonce()` is being called after the view renders; the call must be before `$next($request)`. **If the fourth case fails on `collapsedGroups`**, the published `sidebar.blade.php` is missing its one nonce — Step 2's grep says `1`.

- [ ] **Step 7: The CI smoke assertions**

`.github/workflows/ci.yml` — append to the `smoke` job's "Assertions" step, after Plan 5's ranking checks:

```yaml
          # The CSP inside the built image, under route:cache + config:cache +
          # opcache validate_timestamps=0. The header is built in PHP, so this
          # is also the check that CASS_CSP_REPORT_ONLY defaults to enforcing.
          curl -sD /tmp/csp -o /dev/null -H 'Host: localhost' http://127.0.0.1:8080/
          grep -qi '^content-security-policy:' /tmp/csp
          grep -qi 'nonce-' /tmp/csp
          ! grep -qi '^content-security-policy-report-only:' /tmp/csp
          grep -qi '^cross-origin-opener-policy: same-origin' /tmp/csp
          # The panel login, which is the page the published Filament views
          # serve: a Blade error in either of them is a 500 here. Headers AND
          # body from ONE request - the nonce is minted per request, so taking
          # it from a separate `curl -I` compares two different nonces and can
          # only ever fail.
          curl -sf -D /tmp/login-h -o /tmp/login -H 'Host: localhost' http://127.0.0.1:8080/org/login
          grep -q 'window.filamentData' /tmp/login
          NONCE=$(grep -oiE "nonce-[A-Za-z0-9]{40}" /tmp/login-h | head -1 | cut -d'-' -f2)
          test -n "$NONCE"
          # Every inline script and style in that page carries the same nonce
          # the header does - a mismatch is a panel with no JavaScript at all.
          grep -q "nonce=\"$NONCE\"" /tmp/login
```

- [ ] **Step 8: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all-task-7.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(security): a content-security-policy with a per-request nonce, on every html response

Vite::useCspNonce() covers @vite and all of Livewire, which reads it in
FrontendAssets::nonce(). Filament 5.8.1 has no nonce support at all, so three of
its views are published verbatim with the nonce added and a drift test pins the
originals' digests. script-src keeps 'unsafe-eval' because Alpine's evaluator
is new Function() and Filament's views are not written in the CSP build's
dialect; style-src-attr keeps 'unsafe-inline' because a nonce never reaches an
attribute. Both are argued rather than assumed.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---

### Task 8: The rest of spec section 9 — the encrypted queue payload, the login limiter, `email_logs` pruning and the two platform emails

Section 9 is the only spec section this plan can finish. Task 7 did the CSP; this task does everything else that is still open, and each item names the finding it closes.

**Five decisions this task makes.**

**1. The fix for the plaintext bearer token in `failed_jobs` is one interface, not a re-render.** The finding (fact 8): `SendTemplatedEmail` redacts `/s/{64}` from the *subject* and says at `:48` *"The body still carries the real link - that is the email"*, then queues `new TemplatedMail(… body: $rendered->body …)` into a `public string $body`. `SerializesModels` replaces Eloquent models with identifiers and leaves plain strings alone, so every author status link — a 64-character credential that opens `/s/{token}` with no account at all — is written verbatim into `failed_jobs.payload`, a plaintext `longtext` that `routes/console.php:14` keeps for **720 hours**. The obvious fix, "pass the pieces and re-render in `content()`", does not work: the pieces *are* the status link, because `{{status_link}}`'s value is the token. `Illuminate\Contracts\Queue\ShouldBeEncrypted` does work, and reaches a queued *mailable*: `SendQueuedMailable::__construct()` sets `$this->shouldBeEncrypted = $mailable instanceof ShouldBeEncrypted` (`vendor/laravel/framework/src/Illuminate/Mail/SendQueuedMailable.php:76`) and `Queue::jobShouldBeEncrypted()` reads that property (`:293-300`), so the whole payload goes through `APP_KEY` before it reaches `jobs` or `failed_jobs`. One interface on two classes, one test that greps the stored payload for the token.

**2. Panel login is re-keyed on email **and** the Cloudflare client address, because today it is neither.** Spec section 9 asks for *"login 5/min/email+IP"*. `Filament\Auth\Pages\Login:70` calls `$this->rateLimit(5)`, and the trait keys it `'livewire-rate-limiter:'.sha1($component.'|'.$method.'|'.request()->ip())` (`vendor/danharrin/livewire-rate-limiting/src/WithRateLimiting.php:27`) — IP only, and `request()->ip()` rather than `App\Support\ClientIp::from()`, which every other limiter in this application uses. Behind Cloudflare that is one bucket for the whole internet: five wrong passwords anywhere lock out every organizer on the platform for a minute. `getRateLimitKey()` is `protected`, so one `App\Filament\Auth\Login` subclass overrides it and all three panel providers point at it.

**3. `email_logs` becomes `MassPrunable` and needs no scheduler change.** `routes/console.php:19` already runs `model:prune` daily, and `ShortLinkVisit` is the pattern to copy exactly (fact 19). Retention defaults to **365 days**, not 90: an email log is the answer to "did this author ever get their decision letter", and that question arrives months after a conference, whereas a short-link visit is a scan count nobody asks about twice. `MassPrunable` fires no model events, and `EmailLog` has a `creating` hook and no deleting hook, so it is safe.

**4. `organization_approved` and `organization_rejected` move onto `SendTemplatedEmail`, and the signature grows a union rather than a nullable.** Plan 3's backlog: *"Plan 6 moves them onto `SendTemplatedEmail`; the defaults are already in `lang/en/mail.php`, so that move is a deletion rather than a piece of writing."* Two things make it worth the churn. The copy stops existing twice — once in `lang/en/mail.php` where the editor shows it and once hardcoded in the notification that actually sends it. And the **failure** stops being invisible: Plan 3's other backlog item records that `Illuminate\Notifications\Notification` has no `failed()` hook, so a transport error on `OrganizationApproved` leaves its `email_logs` row at `queued` for ever, while `TemplatedMail::failed()` marks itself failed. The obstacle is that `SendTemplatedEmail::handle()` requires a `Conference` and these two keys exist *before any conference does* — which is exactly why `EmailTemplateKey::isConferenceScoped()` (the real method name; there is no `isOverridable()`) returns false for them (`app/Enums/EmailTemplateKey.php:57-60`). The answer for `SendTemplatedEmail` is `Conference|Organization $context`: one argument that says "brand as this and, if it is a conference, scope to it". `RenderEmailTemplate` is a level below and is **already** `?Conference`, so its union keeps the `null` — widening a signature is safe, narrowing one breaks `tests/Unit/RenderEmailTemplateTest.php:71`. And the move is not a straight swap: `OrganizationRejected` sends two different emails off a `$wasApproved` flag, so Step 6 adds a `reason` placeholder and a third key rather than losing both.

**5. `User::roleIn()` is deliberately **not** memoised, and that is a decision rather than an omission.** The backlog says *"use the loaded relation when present once tables show N+1 in profiling"*. Nothing this plan adds calls it per row: every admin policy answers on `is_platform_admin`, which is a column on the authenticated user, and the admin resources are not tenant-scoped. A per-instance memo would be stale for the rest of a request in which membership changed — `RemoveMember` and `ChangeMemberRole` both exist and both can act on the actor — so it trades a correctness risk for an unmeasured gain. Task 14 re-defers it in those words, with the trigger unchanged.

**Files:**
- Modify: `app/Models/EmailLog.php`, `app/Mail/TemplatedMail.php`, `app/Mail/ContactMessage.php`
- Modify: `app/Actions/Mail/SendTemplatedEmail.php`, `app/Actions/Mail/RenderEmailTemplate.php`, `app/Actions/Demo/SilentTemplatedEmail.php` (the override widens with its parent, or it is a fatal LSP error)
- Modify: `app/Actions/Organizations/ApproveOrganization.php`, `RejectOrganization.php`
- Modify: `app/Enums/EmailTemplateKey.php` (a `reason` placeholder, a twelfth case `OrganizationSuspended`, its `sampleValues()` entry), `lang/en/mail.php`
- Delete: `app/Notifications/OrganizationApproved.php`, `app/Notifications/OrganizationRejected.php`
- Create: `app/Filament/Auth/Login.php`
- Modify: the three panel providers, `resources/views/emails/contact-message.blade.php`, `config/cass.php`, `.env.example`
- Test: `tests/Feature/Security/QueuedMailPayloadTest.php`, `tests/Feature/Security/EmailLogPruningTest.php`, `tests/Feature/Security/RateLimitInventoryTest.php`
- Test (existing, edited): `tests/Feature/Admin/OrganizationApprovalTest.php`, `tests/Feature/Console/DemoSeedTest.php`, `tests/Feature/Mail/EmailLogPipelineTest.php`, `tests/Feature/NotificationMarkdownTest.php`, `tests/Feature/Organizer/ConferenceEmailTemplatesTest.php` (`toHaveCount(11)` → `12`)

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Security/QueuedMailPayloadTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Mail\SendTemplatedEmail;
use App\Enums\EmailTemplateKey;
use App\Mail\ContactMessage;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\Organization;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\DB;

it('declares both mailables encrypted', function (string $mailable) {
    // SendQueuedMailable::__construct() sets shouldBeEncrypted from the
    // MAILABLE (:76), and Queue::jobShouldBeEncrypted() reads that property
    // (:293-300) - so the interface on the mailable is what encrypts the job.
    expect(is_subclass_of($mailable, ShouldBeEncrypted::class))->toBeTrue($mailable);
})->with([TemplatedMail::class, ContactMessage::class]);

it('encrypts the queued payload, so a token cannot be read out of the jobs table', function () {
    config()->set('queue.default', 'database');

    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->create();
    $token = str_repeat('t', 64);

    app(SendTemplatedEmail::class)->handle(
        EmailTemplateKey::SubmissionReceived,
        $conference,
        'author@example.org',
        [
            'author_name' => 'Sara Al-Harbi',
            'title' => 'Early mobilisation',
            'reference' => 'AAM26-001',
            'conference' => (string) $conference->name,
            'organization' => (string) $organization->name,
            'deadline' => '1 January 2027',
            'status_link' => url('/s/'.$token),
        ],
    );

    $payload = (string) DB::table('jobs')->value('payload');
    $decoded = json_decode($payload, true);

    expect($payload)->not->toBe('')
        // /s/{token} opens an abstract with no account at all
        // (routes/web.php:116), and failed_jobs keeps the same payload for 720
        // hours (routes/console.php:14).
        ->and($payload)->not->toContain($token)
        // displayName (Queue.php:178) and commandName (:207) are metadata
        // Laravel never encrypts - displayName is literally
        // get_class($this->mailable) (SendQueuedMailable::displayName()), so
        // 'TemplatedMail' IS in the payload and asserting otherwise can never
        // pass. `command` is the serialized mailable and is what carries the
        // body: it is an encrypted blob rather than a PHP serialization.
        ->and($decoded['data']['commandName'])->toBe(\Illuminate\Mail\SendQueuedMailable::class)
        ->and($decoded['data']['command'])->not->toStartWith('O:')
        ->and(base64_decode($decoded['data']['command'], true))->not->toBeFalse();
});
```

Two cases, both live: the first pins the interface that does the encrypting, the second proves the token is unreadable in `jobs.payload` with the database driver actually serialising it. `config()->set('mail.default', 'array')` is not needed in either — nothing here delivers a message.

`tests/Feature/Security/EmailLogPruningTest.php`
```php
<?php

declare(strict_types=1);

use App\Models\EmailLog;
use Illuminate\Database\Eloquent\MassPrunable;

use function Pest\Laravel\artisan;

it('prunes email logs past the retention window and keeps the rest', function () {
    config()->set('cass.email_log_retention_days', 30);

    // The factory, not `new EmailLog` + forceFill: email_logs.mailable and
    // email_logs.subject are NOT NULL with no default
    // (2026_09_11_001500_create_email_logs_table.php:29-31), so a hand-built
    // row never gets as far as the prune.
    $old = EmailLog::factory()->create();
    $old->forceFill(['created_at' => now()->subDays(31)])->save();

    $recent = EmailLog::factory()->create();

    // routes/console.php:19 already runs model:prune daily, so the trait is
    // the whole change - no scheduler edit.
    artisan('model:prune')->assertExitCode(0);

    expect(EmailLog::query()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(EmailLog::query()->whereKey($recent->getKey())->exists())->toBeTrue();
});

it('treats an empty or zero retention as one day, never as "prune everything"', function () {
    config()->set('cass.email_log_retention_days', 0);

    $old = EmailLog::factory()->create();
    $old->forceFill(['created_at' => now()->subDays(3)])->save();

    $today = EmailLog::factory()->create();

    artisan('model:prune')->assertExitCode(0);

    // max(1, …), the ShortLinkVisit shape: an empty CASS_ variable is how a
    // knob gets disabled, and "disabled" must not mean "truncate the table".
    // A zero becomes one day - it does not become zero days.
    expect(EmailLog::query()->whereKey($today->getKey())->exists())->toBeTrue()
        ->and(EmailLog::query()->whereKey($old->getKey())->exists())->toBeFalse();
});

it('uses the mass-prunable trait, which fires no model events', function () {
    // EmailLog has a `creating` hook and no deleting hook, so a mass delete is
    // safe; Prunable (not Mass) would load every row to fire events nobody
    // listens for.
    expect(in_array(MassPrunable::class, class_uses_recursive(EmailLog::class), true))->toBeTrue();
});
```

`tests/Feature/Security/RateLimitInventoryTest.php`
```php
<?php

declare(strict_types=1);

use App\Filament\Auth\Login;
use App\Models\User;
use App\Support\ClientIp;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Livewire\livewire;

/**
 * Spec section 9: "Rate limits: submission 5/min/IP, login 5/min/email+IP,
 * invitation accept 10/min/IP, contact form 3/min/IP."
 *
 * Three of the four were met by Plans 1-4. This file is the inventory that
 * says so, and the login case is the one that was not.
 */
it('registers the four named limiters the routes ask for', function (string $limiter) {
    expect(RateLimiter::limiter($limiter))->not->toBeNull($limiter);
})->with(['conference-assets', 'file-download', 'submission-status', 'invitation-accept']);

it('keys panel login on the email and the cloudflare client address', function () {
    Filament::setCurrentPanel('organizer');

    $page = livewire(Login::class);

    $first = $page->instance()->rateLimitKeyFor('someone@example.org', '203.0.113.7');
    $sameEmailOtherIp = $page->instance()->rateLimitKeyFor('someone@example.org', '198.51.100.4');
    $otherEmailSameIp = $page->instance()->rateLimitKeyFor('other@example.org', '203.0.113.7');

    // Both halves matter. IP alone is what the package does, and behind
    // Cloudflare that is one bucket for the whole internet: five wrong
    // passwords anywhere would lock out every organizer for a minute.
    expect($first)->not->toBe($sameEmailOtherIp)
        ->and($first)->not->toBe($otherEmailSameIp)
        // Case-folded: an attacker retrying with a capital letter must not get
        // a fresh budget.
        ->and($page->instance()->rateLimitKeyFor('SOMEONE@example.org', '203.0.113.7'))->toBe($first);
});

it('honours the cloudflare header the rest of the application honours', function () {
    Filament::setCurrentPanel('organizer');

    $page = livewire(Login::class);

    // App\Support\ClientIp::from() reads CF-Connecting-IP; the package's own
    // key reads request()->ip(). Every other limiter in this application uses
    // the first.
    expect($page->instance()->rateLimitKeyFor('a@example.org', '203.0.113.7'))
        ->not->toBe($page->instance()->rateLimitKeyFor('a@example.org', request()->ip() ?? '127.0.0.1'));
});

it('locks one email out after five wrong passwords and leaves another alone', function () {
    User::factory()->create(['email' => 'owner@example.org', 'password' => bcrypt('correct-horse-battery')]);

    Filament::setCurrentPanel('organizer');

    // Five attempts fit inside the budget: WithRateLimiting checks
    // tooManyAttempts() BEFORE hit(), so the sixth call is the refused one.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        livewire(Login::class)
            ->fillForm(['email' => 'owner@example.org', 'password' => 'wrong'])
            ->call('authenticate')
            ->assertHasFormErrors();
    }

    // The sixth is refused by the limiter. Filament::authenticate() catches
    // TooManyRequestsException, sends a notification and returns null BEFORE
    // the form is validated (Auth/Pages/Login.php:68-75), so there is no form
    // error to assert - the proof is the notification plus the fact that the
    // CORRECT password no longer signs anybody in.
    livewire(Login::class)
        ->fillForm(['email' => 'owner@example.org', 'password' => 'correct-horse-battery'])
        ->call('authenticate')
        ->assertNotified();

    expect(Auth::guest())->toBeTrue()
        ->and(RateLimiter::tooManyAttempts(
            app(Login::class)->rateLimitKeyFor('owner@example.org', ClientIp::from(request())),
            5,
        ))->toBeTrue();

    // A different address is unaffected, which is the whole point of adding
    // the email to the key.
    User::factory()->create(['email' => 'other@example.org', 'password' => bcrypt('correct-horse-battery')]);

    livewire(Login::class)
        ->fillForm(['email' => 'other@example.org', 'password' => 'correct-horse-battery'])
        ->call('authenticate')
        ->assertHasNoFormErrors();
});

it('still stops one address spraying many accounts', function () {
    config()->set('cass.security.login_ip_limit', 5);

    Filament::setCurrentPanel('organizer');

    foreach (range(1, 6) as $n) {
        livewire(Login::class)
            ->fillForm(['email' => "victim{$n}@example.org", 'password' => 'Password123!'])
            ->call('authenticate');
    }

    // A fresh, never-tried address from the same client is refused: the
    // per-email bucket is empty, the per-IP one is not. Without the wide
    // bucket, keying only on email+IP would hand one client five attempts per
    // minute for EVERY address - password spraying with no ceiling at all.
    livewire(Login::class)
        ->fillForm(['email' => 'victim7@example.org', 'password' => 'Password123!'])
        ->call('authenticate')
        ->assertNotified()
        ->assertHasNoFormErrors();
});
```

`assertNotified()` is the Filament macro at `vendor/filament/notifications/src/Testing/TestsNotifications.php:17`.

**`rateLimitKeyFor()` is a public method this task adds** to the `Login` subclass purely so the key can be asserted without reaching into a `protected` trait method or a `sha1()` by hand. It takes the two inputs and returns the key; `getRateLimitKey()` calls it. That is a test seam, and it is the honest kind: it makes the *rule* assertable rather than the implementation.

Each case sets `Filament::setCurrentPanel('organizer')` before `livewire(Login::class)`; the login page is not tenant-scoped, so it needs no tenant. If a case fails to mount, add `Filament::getPanel('organizer')->boot();` after the `setCurrentPanel` call — **not** `Filament::bootCurrentPanel()`, for the reason `tests/Pest.php:42-57` spells out. **`use Filament\Facades\Filament;` is mandatory** in this file: `config/app.php` declares no `aliases` array, so a bare `Filament::` is `Class "Filament" not found`, which is a fatal rather than the intended missing-class failure at the Step 1 gate.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Security/ > /tmp/t-task-8.log 2>&1; echo "rc=$?"; tail -20 /tmp/t-task-8.log
```

Expected: `rc=1`, naming `App\Filament\Auth\Login`. **Failing-first gate.**

- [ ] **Step 2: The encrypted payload**

`app/Mail/TemplatedMail.php` and `app/Mail/ContactMessage.php` — one interface each:

```php
class TemplatedMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
```

with `use Illuminate\Contracts\Queue\ShouldBeEncrypted;` imported, and this above the class:

```php
 * ShouldBeEncrypted, and the reason is one promoted property. $body carries the
 * rendered email, and a rendered email carries {{status_link}} - a 64-character
 * bearer token that opens /s/{token} with no account at all. SerializesModels
 * swaps Eloquent models for identifiers and leaves plain strings alone, so
 * without this interface that token is written verbatim into jobs.payload and,
 * on a final failure, into failed_jobs.payload, which routes/console.php keeps
 * for 720 hours.
 *
 * SendQueuedMailable::__construct() copies this interface onto the job
 * (vendor/.../Illuminate/Mail/SendQueuedMailable.php:76) and
 * Queue::jobShouldBeEncrypted() reads it (:293-300), so declaring it here is
 * what encrypts the payload with APP_KEY.
 *
 * Rotating APP_KEY therefore makes any job still in the queue undecryptable.
 * The runbook's "Secret rotation" section says so: drain the queue first.
```

`ContactMessage` gets the same interface and a shorter note: the payload is a visitor's name, address and message — personal data rather than a credential, but the same table and the same 720 hours.

**The runbook edit belongs in this step, not in Task 13**, because it is a consequence of this change: `docs/runbooks/deploy-production.md`'s "Secret rotation" section gains one sentence — *"Drain the queue before rotating `APP_KEY`: queued mail payloads are encrypted with it (`ShouldBeEncrypted`), and a job written under the old key cannot be run under the new one. `php artisan queue:size` should read 0, and `queue:failed` should be empty or retried, before the redeploy."*

- [ ] **Step 3: `EmailLog` pruning**

`app/Models/EmailLog.php` — the `ShortLinkVisit` shape, verbatim in structure:

```php
    use HasFactory;
    use MassPrunable;

    // …

    /**
     * Spec section 8 has no retention rule for this table and three plans have
     * recorded that it grows by one row per email for ever. A year, rather
     * than short_link_visits' ninety days: an email log answers "did this
     * author ever receive their decision letter", and that question arrives
     * months after a conference, while a scan count is a number nobody asks
     * about twice.
     *
     * MassPrunable rather than Prunable: this fires no model events, and
     * EmailLog has a `creating` hook and no deleting hook, so there is nothing
     * to fire. routes/console.php already runs model:prune daily.
     *
     * @return Builder<EmailLog>
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('cass.email_log_retention_days'));

        return static::query()->where('created_at', '<', CarbonImmutable::now()->subDays($days));
    }
```

`config/cass.php`, beside `short_link_visit_retention_days`:

```php
    /*
     * Days an email_logs row is kept. Longer than a short-link visit's ninety
     * because this table answers support questions months after a conference.
     * Pruned nightly by model:prune (routes/console.php).
     */
    'email_log_retention_days' => (int) env('CASS_EMAIL_LOG_RETENTION_DAYS', 365),
```

`.env.example`, in the Security block Task 7 opened:

```bash
# Days an email_logs row is kept before the nightly model:prune removes it.
# One row per email sent, for ever, until this existed.
CASS_EMAIL_LOG_RETENTION_DAYS=365
```

- [ ] **Step 4: The login limiter**

`app/Filament/Auth/Login.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Support\ClientIp;
use Filament\Auth\Pages\Login as BaseLogin;

/**
 * Spec section 9 asks for "login 5/min/email+IP". Filament calls
 * $this->rateLimit(5) in authenticate() (Filament\Auth\Pages\Login:70) and the
 * package keys it
 *
 *   'livewire-rate-limiter:'.sha1($component.'|'.$method.'|'.request()->ip())
 *
 * (vendor/danharrin/livewire-rate-limiting/src/WithRateLimiting.php:27) — which
 * is neither half of what the spec asks. It omits the email, so five wrong
 * passwords from one address lock every account behind that address; and it
 * reads request()->ip() rather than App\Support\ClientIp::from(), which is the
 * only reader in this application that ignores CF-Connecting-IP. Behind
 * Cloudflare that second point is the serious one: without the real client
 * address, every login attempt on the platform shares one bucket.
 *
 * getRateLimitKey() is protected, so this subclass is the whole fix, and all
 * three panel providers point ->login() at it.
 */
class Login extends BaseLogin
{
    /**
     * The rule, as a public method, so a test can assert it without reaching
     * into a protected trait method or re-implementing sha1() by hand.
     *
     * The address is lower-cased: an attacker retrying with a capital letter
     * must not get a fresh budget, and users.email is stored lower-cased
     * anyway.
     */
    public function rateLimitKeyFor(string $email, string $ip): string
    {
        return 'livewire-rate-limiter:'.sha1(implode('|', [
            static::class,
            'authenticate',
            mb_strtolower(trim($email)),
            $ip,
        ]));
    }

    /**
     * @param  string|null  $method
     * @param  string|null  $component
     */
    protected function getRateLimitKey($method, $component = null): string
    {
        // $this->data is Livewire's raw form state and is populated before
        // authenticate() calls rateLimit(), so the address is available at the
        // moment the budget is checked. An empty one still produces a valid
        // key - the IP half carries it - which is what keeps a submit with no
        // email from being unmetered.
        $email = is_string($this->data['email'] ?? null) ? $this->data['email'] : '';

        return $this->rateLimitKeyFor($email, ClientIp::from(request()));
    }

    /**
     * The IP-only bucket the package gave us by accident and that this class
     * must not remove. Keying only on email+IP would hand one client five
     * attempts per minute for EVERY address - password spraying with no
     * ceiling at all, which is the attack a small platform actually sees.
     * Filament\Auth\Pages\Login:70's rateLimit(5) is the ONLY throttle on any
     * panel login route; no panel provider registers throttle: middleware and
     * no RateLimiter::for('login') exists.
     */
    public function ipRateLimitKey(string $ip): string
    {
        return 'livewire-rate-limiter:'.sha1(implode('|', [static::class, 'authenticate-ip', $ip]));
    }

    public function authenticate(): ?LoginResponse
    {
        // Checked and hit HERE, not in getRateLimitKey(): rateLimit() calls
        // getRateLimitKey() twice (itself, then hitRateLimiter()), so a hit
        // inside it would burn two attempts per submit.
        $ip = ClientIp::from(request());
        $key = $this->ipRateLimitKey($ip);
        $limit = max(1, (int) config('cass.security.login_ip_limit'));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $this->getRateLimitedNotification(new TooManyRequestsException(
                static::class,
                'authenticate',
                $ip,
                RateLimiter::availableIn($key),
            ))?->send();

            return null;
        }

        RateLimiter::hit($key, 60);

        return parent::authenticate();
    }
}
```

with `use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;`, `use Filament\Auth\Http\Responses\Contracts\LoginResponse;` and `use Illuminate\Support\Facades\RateLimiter;` imported alongside `ClientIp`, and in `config/cass.php`'s `security` block:

```php
        // The wide, per-IP login bucket. The per-email+IP key above is what
        // spec section 9 asks for; on its own it makes spraying unmetered,
        // because a fresh address is a fresh budget.
        'login_ip_limit' => (int) env('CASS_LOGIN_IP_LIMIT', 20),
```

and in `.env.example`'s Security block:

```bash
# Panel login attempts per minute from one client address, across every
# account. The per-email+IP limit is 5 and is not configurable.
CASS_LOGIN_IP_LIMIT=20
```

Then, in all three of `AdminPanelProvider`, `OrganizerPanelProvider` and `ReviewerPanelProvider`, change `->login()` to `->login(Login::class)` with `use App\Filament\Auth\Login;` imported. **All three**, or two panels keep the old key and the inventory test's dataset catches it.

**Check `Filament\Auth\Pages\Login`'s method signatures before writing either override.** If `getRateLimitKey` is typed in 5.8.1 (it is untyped in the package at `:22`), match whatever the parent declares; a narrowed return type on an untyped parent is fine in PHP 8.4, a widened parameter type is not. The same applies to `authenticate()`: the parent returns `?LoginResponse` (`Filament\Auth\Http\Responses\Contracts\LoginResponse`) — copy its declaration exactly rather than the one written here.

**And note what `parent::authenticate()` still does**, because the two buckets are not the same shape: the parent's own `rateLimit(5)` is checked *inside* it, against the per-email+IP key, and answers with its own notification. The override adds the wide bucket *in front* of that. A submit therefore costs one hit in each.

- [ ] **Step 5: The contact mailable as escaped plain text**

The backlog: *"Contact mailable: render the message as plain escaped text with line breaks instead of markdown so senders cannot inject headings or quotes into the internal mail."* It sits under "Public site polish" and belongs here, because it is an injection into a message the platform team reads.

`resources/views/emails/contact-message.blade.php` — the body line becomes:

```blade
{{-- nl2br(e(...)) and not a Markdown echo: a visitor typing "# Urgent" or
     "> forwarded from support@" would otherwise render as a heading or a
     quotation inside an internal email, which is the cheapest possible
     pretext. e() first, then nl2br, so the line breaks survive and nothing
     else does. --}}
<x-mail::message>
# {{ __('mail.contact.heading') }}

**{{ __('mail.contact.from') }}** {{ $senderName }} &lt;{{ $senderEmail }}&gt;

<x-mail::panel>
{!! nl2br(e($body)) !!}
</x-mail::panel>

{{ __('mail.contact.reply') }}
</x-mail::message>
```

`{!! !!}` after `e()` is the correct pairing and the only one: `{{ nl2br(e($body)) }}` would escape the `<br>` tags it just made. The three strings move into `lang/en/mail.php` under a new `contact` group, which Task 12's language sweep then covers.

- [ ] **Step 6: The two platform-wide template keys**

This is the largest edit in the task and the one to do last. Read `app/Actions/Mail/SendTemplatedEmail.php` and `RenderEmailTemplate.php` end to end first.

1. **`RenderEmailTemplate::handle(EmailTemplateKey $key, ?Conference $conference, array $values)` becomes `handle(EmailTemplateKey $key, Conference|Organization|null $context, array $values)`.** The parameter is already **nullable** — `tests/Unit/RenderEmailTemplateTest.php:71` renders a platform default with no conference at all — so the union **widens** and must keep `null`; a non-nullable union is a `TypeError` in an existing green test. Inside, the per-conference override lookup runs only `if ($context instanceof Conference)`; an `Organization` and a `null` both fall straight through to the platform default in `lang/en/mail.php`, which is already what `EmailTemplateKey::isConferenceScoped()` promises for these two keys (`app/Enums/EmailTemplateKey.php:57-60`). The public `template(EmailTemplateKey $key, ?Conference $conference)` helper below it keeps its own signature, and `handle()` calls it with `$context instanceof Conference ? $context : null`.
2. **`SendTemplatedEmail::handle()` takes the union with **no** `null`** in place of `Conference $conference` — every caller there has one or the other — and derives two things from it: `$organization = $context instanceof Conference ? $context->organization : $context;` for the branded layout, and `$conferenceId = $context instanceof Conference ? $context->getKey() : null;` for the `email_logs` row, whose `conference_id` is already nullable (`2026_09_11_001500_create_email_logs_table.php`).
3. **`ApproveOrganization` and `RejectOrganization`** stop calling `Notification::send(...)` and call `SendTemplatedEmail` once per owner instead. `app/Notifications/OrganizationRejected.php` sends **two different emails** off one `$wasApproved` flag, so this is not a one-for-one move and the following must land in the same commit or it is a behaviour regression rather than a refactor:
   - add `'reason'` to `placeholders()` for `self::OrganizationRejected` (`app/Enums/EmailTemplateKey.php:75-76`), add `'reason' => 'The society could not be verified from the details given.'` to the `$samples` array in `sampleValues()` (`:104-115`) so the editor preview does not show a literal placeholder, and add `{{reason}}` to the default body in `lang/en/mail.php:212-219` — `OrganizationRejected::toMail()` sends `"Reason: {$this->reason}"` today and an owner who is not told why cannot act. Spec 5.1 step 4 is "rejects with a reason (organization becomes suspended, owner emailed)".
   - add a third case `OrganizationSuspended = 'organization_suspended'` for the `$wasApproved` branch ("has been suspended and its conferences are no longer public"), because a union-typed context carries no room for a boolean and sending "we could not approve you" to an organization that *was* approved is wrong. `RejectOrganization` picks the key from `$wasApproved`.
   - The new case needs **four** mechanical follow-ons or the suite goes red: add it to the `in_array([...])` list in `isConferenceScoped()` (`:57-60` — the method is `isConferenceScoped()`, **not** `isOverridable()`; correct that name wherever this plan says otherwise), give it a `placeholders()` arm (`['organization', 'reason', 'status_link']`), add its `subject`/`body` to `lang/en/mail.php` (`DefaultTemplates::for()` throws without them, and `tests/Feature/LanguageCoverageTest.php:79-84` checks both), and change `tests/Feature/Organizer/ConferenceEmailTemplatesTest.php:39` from `toHaveCount(11)` to `toHaveCount(12)`.
4. **Delete `app/Notifications/OrganizationApproved.php` and `OrganizationRejected.php`**, and their imports.

Then find every other call site:

```bash
cd /c/Users/ahmed/Documents/CASS && \
grep -rn "SendTemplatedEmail\|RenderEmailTemplate" app/ tests/ --include=*.php | grep -v "^app/Actions/Mail/"
```

The union widens rather than moves, so every `Conference` caller keeps working — but **one call site passes `null`** (`tests/Unit/RenderEmailTemplateTest.php:71`), which is why `RenderEmailTemplate` keeps its nullability, and **one subclass narrows the parameter**.

**Widening `SendTemplatedEmail::handle()` breaks a subclass before it breaks any test.** `app/Actions/Demo/SilentTemplatedEmail.php:35-40` overrides `handle(EmailTemplateKey $key, Conference $conference, …)`. PHP allows a child to *widen* a parameter type, never to narrow it, so the moment the parent takes `Conference|Organization` this class is a fatal `Declaration must be compatible` at autoload time — and `SeedDemo.php:223` binds it over the real action for the length of every demo run. Widen `SilentTemplatedEmail::handle()` to the identical union in the same commit, and keep its `Conference`-only body honest by deriving nothing from the context it does not use.

**Four existing test files reference the two deleted notifications; read each before editing it:**

| File | What it asserts today | What it becomes |
|---|---|---|
| `tests/Feature/Admin/OrganizationApprovalTest.php:12,14,65,81` | `Notification::assertSentTo($owner, OrganizationApproved/Rejected::class)` | `Mail::assertQueued(TemplatedMail::class, fn ($mail) => $mail->hasTo($owner->email))`, plus an `email_logs` row with `template_key = organization_approved` / `organization_rejected` |
| `tests/Feature/Console/DemoSeedTest.php:18,127` | `Notification::assertSentTimes(OrganizationApproved::class, 1)` **and `expect(EmailLog::query()->count())->toBe(0)` two lines later** | the approval now goes through the bound `SilentTemplatedEmail`, so assert `Mail::assertNothingQueued()` still holds and the `email_logs` count is still `0` — that zero is the whole point of `SilentTemplatedEmail` and must survive this change |
| `tests/Feature/Mail/EmailLogPipelineTest.php:14,29,34` | `$owner->notify(new OrganizationApproved($organization))` and `$log->mailable === OrganizationApproved::class` | drive the pipeline through `SendTemplatedEmail` with `EmailTemplateKey::OrganizationApproved` and assert `$log->mailable === TemplatedMail::class` and `$log->template_key === 'organization_approved'`; the file deliberately uses no fakes, so keep it that way |
| `tests/Feature/NotificationMarkdownTest.php` | may render one of the two | drop those rows from its dataset |

`grep -rn "OrganizationApproved\|OrganizationRejected" app/ tests/` before the deletion and again after is the check, and Step 7's "Expected" line says **four** test files, not two.

And one new case, in `tests/Feature/Admin/OrganizationApprovalTest.php`, because nothing else asserts the half of the move that is easiest to lose:

```php
it('still tells a rejected owner why', function () {
    Mail::fake();
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->addMember($owner, App\Enums\OrganizationRole::Owner);

    app(App\Actions\Organizations\RejectOrganization::class)
        ->handle($organization, User::factory()->platformAdmin()->create(), 'Not a real society');

    Mail::assertQueued(App\Mail\TemplatedMail::class,
        fn (App\Mail\TemplatedMail $mail): bool => str_contains($mail->body, 'Not a real society'));
});
```

- [ ] **Step 7: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Security/ tests/Feature/Mail/ tests/Feature/Admin/ tests/Feature/Console/DemoSeedTest.php tests/Feature/Organizer/ConferenceEmailTemplatesTest.php tests/Feature/LanguageCoverageTest.php tests/Unit/RenderEmailTemplateTest.php tests/Feature/Public/ContactFormTest.php tests/Feature/NotificationMarkdownTest.php > /tmp/t-task-8.log 2>&1; echo "rc=$?"; tail -15 /tmp/t-task-8.log && \
php artisan test > /tmp/all-task-8.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-8.log
```

Expected: both `rc=0`; `baseline + 147`, and **four** edited test files rather than two. The three cases this task adds: `RateLimitInventoryTest`'s `still stops one address spraying many accounts` (the per-IP bucket; without it, keying on email+IP makes spraying unmetered), `EmailLogPruningTest`'s now-unskipped `treats an empty or zero retention as one day, never as "prune everything"`, and `OrganizationApprovalTest`'s `still tells a rejected owner why` (the `reason` placeholder, which is the half of the notification move that is easiest to lose). `ConferenceEmailTemplatesTest` must read `toHaveCount(12)`, not `11`, or the twelfth enum case has not been added.

**`phpunit.xml` sets `QUEUE_CONNECTION=sync`**, and the encryption test overrides it with `config()->set('queue.default', 'database')` so a row really lands in `jobs`. That works because `jobs` exists in the migrations and `RefreshDatabase` builds it; if the override does not take (Laravel resolves the queue manager once per container), resolve it again inside the test with `app()->forgetInstance('queue')` before the send, and say so in a comment. Task 14 Step 1 re-runs the same case against MySQL, which is where `payload`'s `longtext` actually behaves like production.

Then confirm nothing else still sends the two retired notifications:

```bash
cd /c/Users/ahmed/Documents/CASS && \
grep -rn "OrganizationApproved\|OrganizationRejected" app/ tests/ resources/ --include=*.php --include=*.blade.php ; \
echo "--- expected: only EmailTemplateKey cases and lang/mail keys ---"
```

Expected: hits in `app/Enums/EmailTemplateKey.php` (the enum cases, which stay) and in the **four** test files that now assert a `TemplatedMail` or an `email_logs` row. **No hit under `app/Notifications/`** — those two files are deleted.

- [ ] **Step 8: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all-task-8.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "fix(security): encrypt the queued mail payload, key login on email and the real client address, prune the email log

A rendered email carries the author's status token, SerializesModels leaves a
plain string alone, and failed_jobs keeps the payload for 720 hours - so
TemplatedMail is now ShouldBeEncrypted. Panel login was keyed on
request()->ip() alone, which behind Cloudflare is one bucket for the internet.
The two platform-wide templates move onto SendTemplatedEmail, so their copy
lives once and a transport failure is visible in the log.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 9: `cass:import-legacy`, part one — the dump reader, the mapping table, and everything above an abstract

Spec 5.10: *"Console command `cass:import-legacy {sql} {uploads-dir}` creates one organization for the legacy owner, two conferences, users (without passwords; they receive a reset link on first login), reviewer memberships, review forms and questions, submissions with authors parsed from the legacy text fields, files copied into private storage, and reviews with answers and computed scores. Idempotent by legacy id. Run once on production by the owner."*

This task does the reader, the idempotency mechanism, and everything from the organization down to the review questions. Task 10 does the abstracts, their authors, their files and the reviews.

**Six decisions this task makes.**

**1. Idempotency is one `legacy_imports` mapping table, not a `legacy_id` column on eight tables.** The alternative would put a nullable integer on `users`, `conferences`, `review_forms`, `review_questions`, `submissions`, `reviews`, `conference_reviewers` and `reviewer_invitations` — eight columns that every future reader of those tables has to know the meaning of, that appear in every export and every factory, for ever, to serve a command that runs **once**. One table keeps the artefact in one place, gives one `unique(legacy_table, legacy_id)` that makes the whole import idempotent by construction rather than table by table, and can be dropped by a later migration the day the owner is confident. The cost is a lookup per row during the import — on twenty-four abstracts and ten users, on a machine nobody is waiting at.

**2. The dump is read as UTF-8 and is never transcoded** (fact 26). Every table is declared `latin1`, the file is UTF-8, and greps for every classic double-encoding marker return zero. The legacy application wrote UTF-8 bytes into latin1 columns and phpMyAdmin passed them through. **A latin1→utf8 conversion step would create the corruption that is currently absent.** The reader therefore validates with `mb_check_encoding($value, 'UTF-8')` and *reports* a row it cannot read rather than converting it.

**3. The import refuses to guess, and everything it refuses goes into a report.** Spec section 15's mitigation is explicit: *"Import command normalises encoding and reports rows needing manual review instead of guessing."* That is the design rule for the whole command. The 2023 `affiliation` column holds a person's name in most rows, the full author list in two, and a research question in one (fact 28) — so the mapper does not "un-swap" it by rule; it applies one conference-scoped heuristic, records what it did for **every** affected row, and writes them all to a file a human reads. Same for every author with no email, every legacy invitation with a live plaintext token, and every file on disk with no row.

**4. Passwords are not carried, even though they would work.** All ten legacy rows hold real `$2y$10$` bcrypt hashes that `Hash::check()` would accept. Spec 5.10 says "users (without passwords; they receive a reset link on first login)", and the reason is better than the instruction: `legacy-review.md:276` records that `users.reset_token` doubled as the manager-invitation token, so a password on that platform was never the only way in. Each imported user gets a fresh `Str::random(64)` they will never see and no `email_verified_at`; the runbook's step is "send them a password reset".

**5. The organization is created by the command, from arguments, because the legacy schema has no concept of one.** There is no `organizations` table, no owner column, and three addresses belong to the same human (fact 28: `ahmedsk2@gmail.com` as the legacy admin, `ahmedsk2.coding@gmail.com` as a manager of both editions, `ahsalkhalifah@moh.gov.sa` on an unaccepted invitation). The command takes `--organization-slug=` and either **adopts an existing organization with that slug** or refuses. It does not invent a name, a type or a country — those are the owner's to set in the panel before the import runs, which is also the moment somebody looks at the branding.

**6. The imported conferences land `archived`, and nothing about them is guessed.** `conferences` has three columns in legacy — `id`, `name`, `submission_deadline` — and the two editions share a byte-identical name, so the slug is derived from the deadline's year (`common-pediatric-diseases-symposium-2023` / `-2024`). Everything else is either a schema default or explicitly chosen and recorded: `review_mode` = `open_pool` (legacy had no per-submission assignment at all), `max_files` = **1** (every legacy row has exactly one), `word_limit` = 500 (matching the legacy client-side check), `allowed_file_types` = `["pdf"]`, `blind_review` = false, `timezone` = `Asia/Riyadh`. `status` is `archived` and **not** `decided`: `legacy-review.md:239` is explicit that *"there is no average, weighting, ranking, or accept/reject decision anywhere in the code"*, so there is nothing to have decided.

**Files:**
- Create: `database/migrations/2026_09_15_000100_create_legacy_imports_table.php`, `app/Models/LegacyImport.php`, `database/factories/LegacyImportFactory.php`
- Create: `app/Support/Legacy/SqlDumpReader.php`, `app/Support/Legacy/ManualReviewReport.php`, `app/Support/Legacy/ImportReport.php`
- Create: `app/Actions/Legacy/ImportLegacy.php`, `app/Exceptions/LegacyImportRefused.php`
- Create: `app/Console/Commands/ImportLegacyCommand.php`, `lang/en/legacy.php`
- Create: `tests/Fixtures/legacy/dump.sql`
- Modify: `bootstrap/app.php`, `config/cass.php`, `.env.example`
- Test: `tests/Unit/SqlDumpReaderTest.php`, `tests/Unit/ImportLegacyTest.php`

- [ ] **Step 1: Read the inputs, and copy nothing out of them**

```bash
cd /c/Users/ahmed/Documents/CASS && \
ls -la Legacy/ && \
grep -c "^--" legacy-review.md && \
grep -n "CREATE TABLE" Legacy/dbg1vzqja6lgef.sql && \
grep -o "INSERT INTO \`[a-z_]*\`" Legacy/dbg1vzqja6lgef.sql | sort | uniq -c && \
grep -c "DEFAULT CHARSET=latin1" Legacy/dbg1vzqja6lgef.sql && \
file Legacy/dbg1vzqja6lgef.sql
```

Expected: ten `CREATE TABLE`s, eleven `INSERT INTO` statements across nine tables (`submissions` has two), ten `latin1` declarations, and `file` reporting **UTF-8**. Then read `legacy-review.md` sections 3 (the tables), and the quirks list at `:267-281`.

**`Legacy/` and `legacy-review.md` are git-ignored (`.gitignore:1-3`) and `CLAUDE.md` forbids committing either.** Nothing in this task or the next copies a byte out of them into the repository. The fixtures in Step 3 are **typed by hand** to reproduce the *shapes* the review documents — an escaped apostrophe, a `\r\n` inside a text column, a mixed-case email, a bullet-separated author list, a `'Pending'` where the enum is lowercase — with invented names and invented abstracts.

```bash
cd /c/Users/ahmed/Documents/CASS && git check-ignore -v Legacy/dbg1vzqja6lgef.sql legacy-review.md
```

Expected: both matched by `.gitignore:1-3`. If either is **not** ignored, stop and fix that before writing another line.

- [ ] **Step 2: The mapping table and its model**

`database/migrations/2026_09_15_000100_create_legacy_imports_table.php`
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_imports', function (Blueprint $table) {
            $table->id();
            // The legacy table's own name, as it appears in the dump:
            // conferences, users, submissions, reviews, evaluation_forms,
            // evaluation_questions, conference_reviewers,
            // reviewer_invitations.
            $table->string('legacy_table', 40);
            $table->unsignedBigInteger('legacy_id');

            // Polymorphic and deliberately WITHOUT a foreign key, like
            // short_links and activity_log: the row it points at may be in any
            // of eight tables, three of which soft-delete. Plan 6's purge
            // sweeps these rows by hand for the same reason it sweeps short
            // links.
            $table->string('imported_type');
            $table->unsignedBigInteger('imported_id');
            $table->timestamp('imported_at');

            // THE idempotency guarantee. Spec 5.10: "Idempotent by legacy id."
            // A second run finds the row and skips, whatever the command's own
            // control flow does, because the database refuses the duplicate.
            $table->unique(['legacy_table', 'legacy_id']);
            $table->index(['imported_type', 'imported_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_imports');
    }
};
```

`app/Models/LegacyImport.php` — `protected $guarded = ['*']`, a `morphTo('imported')` relation, `'imported_at' => 'datetime'`, and two static helpers that are the whole API:

```php
    /**
     * The model a legacy row was imported as, or null. The type is checked by
     * the caller: a mapping row whose target has since been purged returns
     * null from the morph, and an import that then re-creates it is the
     * correct behaviour.
     */
    public static function find(string $table, int $legacyId): ?Model
    {
        return static::query()
            ->where('legacy_table', $table)
            ->where('legacy_id', $legacyId)
            ->first()?->imported;
    }

    /**
     * Record one mapping, and RETURN the mapping row itself. Idempotent: the
     * unique index is the guarantee, this is the convenience. The return value
     * is what lets a caller (Task 10's purge case) assert on the mapping row's
     * own key. `static::query()` is `Builder<static>` and `updateOrCreate()`
     * returns `TModel`, so `static` is the honest return type at level 6.
     */
    public static function record(string $table, int $legacyId, Model $model): static
    {
        return static::query()->updateOrCreate(
            ['legacy_table' => $table, 'legacy_id' => $legacyId],
            [
                'imported_type' => $model->getMorphClass(),
                'imported_id' => $model->getKey(),
                'imported_at' => now(),
            ],
        );
    }
```

- [ ] **Step 3: The fixture**

`tests/Fixtures/legacy/dump.sql` — hand-written, tiny, and shaped to carry every parsing hazard the real dump has. **Invented names, invented abstracts.**

```sql
-- A miniature of the legacy dump: the same phpMyAdmin shape, the same latin1
-- declarations on UTF-8 bytes, and one instance of every parsing hazard the
-- real file contains. Nothing here is copied from Legacy/.
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
/*!40101 SET NAMES utf8mb4 */;

CREATE TABLE `conferences` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `submission_deadline` date NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

INSERT INTO `conferences` (`id`, `name`, `submission_deadline`) VALUES
(5, 'Example Pediatric Symposium', '2023-12-02'),
(6, 'Example Pediatric Symposium', '2024-11-25');

CREATE TABLE `users` (
  `id` int NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','manager','reviewer','multi-role') NOT NULL,
  `conference_id` int DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `full_name` varchar(255) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- A mixed-case address, a NULL reset_token, an unnormalised full_name, and a
-- mailbox rather than a person - all four are in the real data.
INSERT INTO `users` (`id`, `email`, `password`, `role`, `conference_id`, `reset_token`, `reset_token_expiry`, `full_name`) VALUES
(1, 'owner@example.org', '$2y$10$abcdefghijklmnopqrstuv', 'admin', NULL, NULL, NULL, 'admin'),
(14, 'Manager.One@example.org', '$2y$10$abcdefghijklmnopqrstuv', 'manager', NULL, NULL, NULL, 'dr. manager one'),
(16, 'reviewer.one@example.org', '$2y$10$abcdefghijklmnopqrstuv', 'reviewer', NULL, NULL, NULL, 'Dr Reviewer One'),
(17, 'Reviewer.Two@example.org', '$2y$10$abcdefghijklmnopqrstuv', 'reviewer', NULL, NULL, NULL, 'Dr Reviewer Two'),
(20, 'education@example.org', '$2y$10$abcdefghijklmnopqrstuv', 'reviewer', NULL, NULL, NULL, 'education');

CREATE TABLE `conference_managers` (
  `conference_id` int NOT NULL,
  `manager_id` int NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

INSERT INTO `conference_managers` (`conference_id`, `manager_id`) VALUES
(5, 14),
(6, 14);

CREATE TABLE `conference_reviewers` (
  `id` int NOT NULL,
  `conference_id` int DEFAULT NULL,
  `reviewer_id` int DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- Row 13 is the junk shape legacy-review.md:196 describes: both ids NULL.
INSERT INTO `conference_reviewers` (`id`, `conference_id`, `reviewer_id`) VALUES
(10, 5, 16),
(11, 5, 17),
(13, NULL, NULL),
(14, 6, 20);

CREATE TABLE `evaluation_forms` (
  `id` int NOT NULL,
  `conference_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

INSERT INTO `evaluation_forms` (`id`, `conference_id`, `created_at`) VALUES
(6, 5, '2023-11-28 17:44:09'),
(7, 6, '2024-11-26 17:58:01');

CREATE TABLE `evaluation_questions` (
  `id` int NOT NULL,
  `evaluation_form_id` int NOT NULL,
  `question` text NOT NULL,
  `question_type` enum('likert','textarea') NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `likert_scale` int DEFAULT NULL,
  `order_column` int DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- Form 6's order_column is 0 on every row (the legacy ordering bug,
-- legacy-review.md:176); form 7's is populated. Both shapes are here.
INSERT INTO `evaluation_questions` (`id`, `evaluation_form_id`, `question`, `question_type`, `created_at`, `likert_scale`, `order_column`) VALUES
(50, 6, 'Is the research question clearly stated?', 'likert', '2023-11-28 17:44:09', 5, 0),
(51, 6, 'Is the methodology sound?', 'likert', '2023-11-28 17:44:09', 5, 0),
(59, 7, 'Is the research question clearly stated?', 'likert', '2023-11-28 17:44:09', 5, 0),
(60, 7, 'Any free-text comments for the authors?', 'textarea', '2024-11-26 17:58:01', NULL, 1);

CREATE TABLE `reviewer_invitations` (
  `id` int NOT NULL,
  `manager_id` int DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `token` varchar(255) DEFAULT NULL,
  `status` enum('pending','accepted','expired') DEFAULT 'pending',
  `role` enum('reviewer') DEFAULT 'reviewer',
  `sent_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `conference_id` int DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- 'Pending' with a capital P against a lowercase enum (legacy-review.md:269),
-- and a live plaintext token past its expiry.
INSERT INTO `reviewer_invitations` (`id`, `manager_id`, `email`, `full_name`, `token`, `status`, `role`, `sent_at`, `expires_at`, `accepted_at`, `conference_id`) VALUES
(17, 14, 'reviewer.one@example.org', 'Dr Reviewer One', '4dfca4e3f1cedae5bd705c3302c50877', 'accepted', 'reviewer', '2023-11-20 09:00:00', '2023-12-04 09:00:00', '2023-11-21 10:00:00', 5),
(24, 14, 'never.accepted@example.org', 'Dr Never Accepted', '6fcf89e9c05b44a51d7d5239170e454c', 'Pending', 'reviewer', '2024-10-01 09:00:00', '2024-10-15 09:00:00', NULL, 6);

CREATE TABLE `submissions` (
  `id` int NOT NULL,
  `conference_id` int DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `affiliation` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `presenter_names` text NOT NULL,
  `authors` text NOT NULL,
  `abstract` text NOT NULL,
  `attachment` varchar(255) NOT NULL,
  `contact_email` varchar(255) NOT NULL,
  `contact_phone` varchar(15) NOT NULL,
  `submission_date` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- Row 5: affiliation holds a NAME (the 2023 swap). Row 6: an escaped
-- apostrophe, a \r\n inside a text column, and a bullet-separated author list.
-- Row 20: a clean 2024 row with a real institution.
INSERT INTO `submissions` (`id`, `conference_id`, `title`, `affiliation`, `presenter_names`, `authors`, `abstract`, `attachment`, `contact_email`, `contact_phone`, `submission_date`) VALUES
(5, 5, 'Bedside ultrasound in the paediatric ward', 'Dr Alia Example', 'Dr Alia Example', 'Dr Alia Example, Dr Badr Example', 'A prospective cohort of forty children.', 'uploads/1699719268_4752.pdf', 'Alia.Example@example.org', '00966500000001', '2023-11-11 16:14:27'),
(6, 5, 'A child\'s response to early mobilisation', 'How does mobilisation affect recovery', 'Dr Alia Example', 'Dr Alia Example • Dr Badr Example • Dr Carim Example', 'Line one.\r\nLine two, with a semicolon; inside it.', 'uploads/1699719578_3346.pdf', 'alia.example@example.org', '0500000002', '2023-11-12 09:00:00'),
(20, 6, 'Sepsis recognition at triage', 'Example Central Hospital', 'Dr Dalia Example', 'Dr Dalia Example & Dr Emad Example', 'Retrospective review of two hundred charts.', 'uploads/1729330644_2837.pdf', 'dalia.example@example.org', '966500000003', '2024-10-19 09:37:23');

CREATE TABLE `reviews` (
  `id` int NOT NULL,
  `submission_id` int NOT NULL,
  `reviewer_id` int NOT NULL,
  `question_id` int NOT NULL,
  `answer` text
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- Two complete reviews of submission 5 and one INCOMPLETE review of
-- submission 20 (one answer of the two questions on form 7), which is the
-- shape of the two real incomplete 2024 reviews.
INSERT INTO `reviews` (`id`, `submission_id`, `reviewer_id`, `question_id`, `answer`) VALUES
(1, 5, 16, 50, '4'),
(2, 5, 16, 51, '5'),
(3, 5, 17, 50, '3'),
(4, 5, 17, 51, '2'),
(5, 20, 20, 59, '4');

CREATE TABLE `newsletters` (
  `id` int UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `date` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- No INSERT: the real table has zero rows and an auto-increment of 4, so the
-- reader has to answer "no rows" rather than "no table".

ALTER TABLE `conferences` ADD PRIMARY KEY (`id`);
ALTER TABLE `users` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `email` (`email`);
COMMIT;
```

- [ ] **Step 4: Write the failing tests**

`tests/Unit/SqlDumpReaderTest.php`
```php
<?php

declare(strict_types=1);

use App\Support\Legacy\SqlDumpReader;

// No database at all: this class turns a file into arrays.

beforeEach(function () {
    $this->reader = new SqlDumpReader(base_path('tests/Fixtures/legacy/dump.sql'));
});

it('lists the tables the dump declares', function () {
    expect($this->reader->tables())->toContain('conferences', 'users', 'submissions', 'reviews', 'newsletters');
});

it('reads a simple table into associative rows', function () {
    $rows = iterator_to_array($this->reader->rows('conferences'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe([
            'id' => '5',
            'name' => 'Example Pediatric Symposium',
            'submission_deadline' => '2023-12-02',
        ]);
});

it('answers no rows for a table with no insert statement', function () {
    // The real `newsletters` has an auto-increment of 4 and no rows at all.
    // "No rows" and "no such table" are different answers and only one of them
    // is a problem.
    expect(iterator_to_array($this->reader->rows('newsletters')))->toBe([])
        ->and($this->reader->tables())->toContain('newsletters');
});

it('throws for a table the dump does not declare', function () {
    expect(fn () => iterator_to_array($this->reader->rows('orders')))
        ->toThrow(RuntimeException::class);
});

it('unescapes the four things a mysqldump escapes', function () {
    $rows = iterator_to_array($this->reader->rows('submissions'));

    // \' inside a single-quoted literal.
    expect($rows[1]['title'])->toBe("A child's response to early mobilisation")
        // \r\n written as an escape sequence, not as physical newlines: this is
        // why a statement can be accumulated line by line without a scanner
        // splitting a string across lines.
        ->and($rows[1]['abstract'])->toBe("Line one.\r\nLine two, with a semicolon; inside it.")
        // A semicolon INSIDE a string must not terminate the statement - and a
        // semicolon is one of the five author separators in the real data.
        ->and($rows[1]['abstract'])->toContain(';');
});

it('reads NULL as null and everything else as a string', function () {
    $rows = iterator_to_array($this->reader->rows('conference_reviewers'));

    expect($rows[2])->toBe(['id' => '13', 'conference_id' => null, 'reviewer_id' => null])
        // Numbers stay strings: the mapper casts, the reader does not guess a
        // type from a dump that declares none.
        ->and($rows[0]['conference_id'])->toBe('5');
});

it('reads two insert statements into one table as one list', function () {
    // The real `submissions` is split across two INSERT INTO statements
    // (ids 5-25, then 28-30); a reader that stops at the first loses three
    // abstracts silently.
    $sql = (string) file_get_contents(base_path('tests/Fixtures/legacy/dump.sql'));
    $sql .= "\nINSERT INTO `conferences` (`id`, `name`, `submission_deadline`) VALUES\n(9, 'A third edition', '2025-11-25');\n";

    $path = sys_get_temp_dir().'/cass-legacy-two-inserts.sql';
    file_put_contents($path, $sql);

    expect(iterator_to_array((new SqlDumpReader($path))->rows('conferences')))->toHaveCount(3);

    unlink($path);
});

it('reports a value that is not valid utf-8 rather than transcoding it', function () {
    // Every latin1-declared column in the real dump holds UTF-8 bytes and
    // there is no mojibake in it (Plan 6 fact 26). A latin1 -> utf8 conversion
    // step would CREATE the corruption that is currently absent, so the reader
    // never converts - it flags.
    //
    // The fixture needs the CREATE TABLE as well as the INSERT: rows() refuses
    // a table the dump does not DECLARE, and tables() reads CREATE statements -
    // so an INSERT on its own throws "The dump declares no table [t]." before
    // value()'s encoding guard is ever reached.
    $path = sys_get_temp_dir().'/cass-legacy-bad-bytes.sql';
    file_put_contents($path, "CREATE TABLE `t` (\n  `a` varchar(255) NOT NULL\n) ENGINE=MyISAM DEFAULT CHARSET=latin1;\n\nINSERT INTO `t` (`a`) VALUES\n('caf\xE9');\n");

    $reader = new SqlDumpReader($path);
    $rows = iterator_to_array($reader->rows('t'));

    expect($reader->encodingProblems())->toHaveCount(1)
        ->and($rows[0]['a'])->toBeString();

    unlink($path);
});

it('refuses a file that is not there', function () {
    expect(fn () => (new SqlDumpReader('/no/such/dump.sql'))->tables())
        ->toThrow(RuntimeException::class);
});
```

`tests/Unit/ImportLegacyTest.php` (part one — Task 10 appends to this file)
```php
<?php

declare(strict_types=1);

use App\Actions\Legacy\ImportLegacy;
use App\Enums\ConferenceStatus;
use App\Enums\ReviewMode;
use App\Enums\ReviewQuestionType;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewStatus;
use App\Enums\SubmissionStatus;
use App\Exceptions\LegacyImportRefused;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\LegacyImport;
use App\Models\Organization;
use App\Models\ReviewAnswer;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\ReviewerInvitation;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\SubmissionFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->dump = base_path('tests/Fixtures/legacy/dump.sql');
    $this->uploads = sys_get_temp_dir().'/cass-legacy-uploads';

    // The fixture's own INVENTED invitation tokens, copied verbatim from the
    // two `reviewer_invitations` rows in tests/Fixtures/legacy/dump.sql (legacy
    // ids 17 and 24, in that order) so the "never writes a legacy plaintext
    // token into the report" case asserts against strings the import actually
    // reads. If you edit those two rows, edit these two values with them.
    // Nothing from the real dump is ever typed into this repo.
    $this->fixtureInvitationTokens = [
        '4dfca4e3f1cedae5bd705c3302c50877',
        '6fcf89e9c05b44a51d7d5239170e454c',
    ];

    if (! is_dir($this->uploads)) {
        mkdir($this->uploads, 0777, true);
    }

    // Two of the three referenced files exist; the third does not, which is
    // the "a row points at a file that is not on disk" case Task 10 asserts.
    copy(base_path('tests/Fixtures/abstract.pdf'), $this->uploads.'/1699719268_4752.pdf');
    copy(base_path('tests/Fixtures/abstract.pdf'), $this->uploads.'/1699719578_3346.pdf');
    // And one file on disk that no row references (the real dump has three).
    copy(base_path('tests/Fixtures/abstract.pdf'), $this->uploads.'/1727756783_9881.pdf');

    // The organization is the owner's to create, in the panel, before the
    // import runs. The command adopts it; it does not invent a name, a type or
    // a country.
    $this->organization = Organization::factory()->approved()->create([
        'name' => 'Example Society',
        'slug' => 'example-society',
    ]);
});

it('refuses when the organization slug names nothing', function () {
    expect(fn () => app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'no-such-society'))
        ->toThrow(LegacyImportRefused::class, __('legacy.errors.no_organization', ['slug' => 'no-such-society']));
});

it('refuses when the uploads directory is not there', function () {
    expect(fn () => app(ImportLegacy::class)->handle($this->dump, '/no/such/uploads', 'example-society'))
        ->toThrow(LegacyImportRefused::class);
});

it('creates one conference per legacy edition, archived, with a year in the slug', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $conferences = Conference::query()->orderBy('slug')->get();

    expect($conferences)->toHaveCount(2)
        // The two legacy names are byte-identical, so the year - derived from
        // submission_deadline, the only column that carries one - is what makes
        // the slugs distinct.
        ->and($conferences[0]->slug)->toBe('example-pediatric-symposium-2023')
        ->and($conferences[1]->slug)->toBe('example-pediatric-symposium-2024')
        ->and($conferences[0]->status)->toBe(ConferenceStatus::Archived)
        // legacy-review.md:239: "there is no average, weighting, ranking, or
        // accept/reject decision anywhere in the code" - so there is nothing
        // to have decided, and `decided` would be a claim the data cannot make.
        ->and($conferences[0]->status)->not->toBe(ConferenceStatus::Decided)
        ->and($conferences[0]->review_mode)->toBe(ReviewMode::OpenPool)
        // Every legacy row has exactly one attachment.
        ->and($conferences[0]->max_files)->toBe(1)
        ->and($conferences[0]->allowed_file_types)->toBe(['pdf'])
        ->and($conferences[0]->submission_deadline?->toDateString())->toBe('2023-12-02');
});

it('creates users without a usable password and without email verification', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $reviewer = User::query()->where('email', 'reviewer.one@example.org')->first();

    expect($reviewer)->not->toBeNull()
        ->and($reviewer?->name)->toBe('Dr Reviewer One')
        // Spec 5.10: "users (without passwords; they receive a reset link on
        // first login)". The legacy hashes are real bcrypt and WOULD work,
        // which is exactly why they are not carried: legacy-review.md:276
        // records that users.reset_token doubled as the invitation token, so a
        // password there was never the only way in.
        ->and(Hash::check('anything', (string) $reviewer?->password))->toBeFalse()
        ->and($reviewer?->email_verified_at)->toBeNull()
        ->and($reviewer?->is_platform_admin)->toBeFalse();

    // Addresses are folded: users.email is UNIQUE in legacy but stored with
    // mixed case, and v2 compares against a lower-cased column.
    expect(User::query()->where('email', 'manager.one@example.org')->exists())->toBeTrue();
});

it('makes the legacy admin and managers organization members, and says so in the report', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $manager = User::query()->where('email', 'manager.one@example.org')->firstOrFail();

    // Legacy conference_managers scopes a manager to ONE edition; v2 scopes an
    // organizer to an organization (spec section 3). That widening is real and
    // is reported rather than hidden.
    expect($manager->roleIn($this->organization))->not->toBeNull()
        ->and($report->manualReview())->toContain(
            fn (string $line): bool => str_contains($line, 'manager.one@example.org') && str_contains($line, 'conference_managers'),
        );
})->skip('Rewrite the toContain closure to whatever ManualReviewReport::lines() returns; see Step 6.');

it('copies each legacy form and its questions, with a minimum of 1 on every scale', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $conference = Conference::query()->where('slug', 'example-pediatric-symposium-2023')->firstOrFail();
    $form = $conference->reviewForm()->firstOrFail();

    expect(ReviewForm::query()->count())->toBe(2)
        ->and($form->is_active)->toBeTrue()
        ->and($form->questions()->count())->toBe(2);

    $question = $form->questions()->orderBy('sort')->first();

    expect($question?->scale_max)->toBe(5)
        // THE line that decides whether the import scores anything at all.
        // Legacy has likert_scale and no minimum; AnswerNormaliser::likert()
        // returns null when min is null, so leaving it would silently make
        // every imported answer unscored.
        ->and($question?->scale_min)->toBe(1)
        ->and($question?->type)->toBe(ReviewQuestionType::Likert);

    // Form 6's order_column is 0 on every row (the legacy ordering bug), so
    // sort is synthesised from the id sequence rather than copied.
    expect($form->questions()->orderBy('sort')->pluck('sort')->all())->toBe([1, 2]);
});

it('maps a textarea question to a text question', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $conference = Conference::query()->where('slug', 'example-pediatric-symposium-2024')->firstOrFail();
    $text = $conference->reviewForm()->firstOrFail()->questions()->where('type', ReviewQuestionType::Text)->first();

    expect($text)->not->toBeNull()
        ->and($text?->scale_min)->toBeNull()
        ->and($text?->scale_max)->toBeNull();
});

it('attaches reviewers to their conference and skips the junk row', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $conference = Conference::query()->where('slug', 'example-pediatric-symposium-2023')->firstOrFail();

    expect($conference->reviewers()->count())->toBe(2)
        ->and($conference->activeReviewers()->count())->toBe(2)
        ->and($conference->reviewers()->first()?->status)->toBe(ReviewerStatus::Active)
        // legacy-review.md:196: "any request to this page without a token
        // inserts a junk row" - (NULL, NULL) in conference_reviewers. It is
        // not in this dump, but it is in the schema, and a mapper that
        // dereferences it fatals.
        ->and(ConferenceReviewer::query()->whereNull('conference_id')->count())->toBe(0);
});

it('imports invitations as expired, never as usable', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $invitation = ReviewerInvitation::query()->where('email', 'never.accepted@example.org')->first();

    expect($invitation)->not->toBeNull()
        // v2 stores char('token_hash', 64); legacy holds a 32-character
        // plaintext MD5, three of which are still live in the real dump. They
        // cannot be carried, and re-hashing one would MINT a working
        // invitation out of a dead one. The value compared against is the
        // FIXTURE's invented token for THIS row - legacy `reviewer_invitations`
        // id 24, which is index 1 of the pair recorded in beforeEach - so the
        // assertion is about the row under test and not about some other row's
        // string. No real legacy value is ever typed into this repository.
        ->and($invitation?->token_hash)->not->toBe($this->fixtureInvitationTokens[1])
        ->and($invitation?->expires_at?->isPast())->toBeTrue()
        ->and($invitation?->accepted_at)->toBeNull();

    // 'Pending' with a capital P against a lowercase enum
    // (legacy-review.md:269) must not become a fourth state.
    expect(ReviewerInvitation::query()->count())->toBe(2);
});

it('never writes a legacy plaintext token into the manual-review report', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $markdown = (string) Storage::disk('local')->get((string) $report->reportPath);

    // The fixture's own tokens, plus the shape of every legacy one (md5 hex).
    // The report lands on the cass-storage volume and the runbook tells an
    // operator to `cat` it, so a token value written there outlives the dump
    // itself.
    foreach ($this->fixtureInvitationTokens as $token) {
        expect($markdown)->not->toContain($token);
    }

    expect($markdown)->not->toMatch('/\b[0-9a-f]{32}\b/')
        ->and($markdown)->toContain('reviewer_invitations#');
});

it('is idempotent: a second run creates nothing and changes nothing', function () {
    $first = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');
    $counts = [
        'users' => User::query()->count(),
        'conferences' => Conference::query()->count(),
        'forms' => ReviewForm::query()->count(),
        'questions' => ReviewQuestion::query()->count(),
        'reviewers' => ConferenceReviewer::query()->count(),
        'mappings' => LegacyImport::query()->count(),
    ];

    $second = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    // Spec 5.10: "Idempotent by legacy id." The unique index on
    // (legacy_table, legacy_id) is the guarantee; the control flow is the
    // convenience.
    expect(User::query()->count())->toBe($counts['users'])
        ->and(Conference::query()->count())->toBe($counts['conferences'])
        ->and(ReviewForm::query()->count())->toBe($counts['forms'])
        ->and(ReviewQuestion::query()->count())->toBe($counts['questions'])
        ->and(ConferenceReviewer::query()->count())->toBe($counts['reviewers'])
        ->and(LegacyImport::query()->count())->toBe($counts['mappings'])
        ->and($second->created['conferences'] ?? 0)->toBe(0)
        ->and($first->created['conferences'] ?? 0)->toBe(2);
});

it('writes nothing at all in dry-run mode, and still reports what it would have done', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society', dryRun: true);

    expect(Conference::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(LegacyImport::query()->count())->toBe(0)
        ->and($report->created['conferences'] ?? 0)->toBe(2)
        ->and($report->manualReview())->not->toBeEmpty();
});
```

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/SqlDumpReaderTest.php tests/Unit/ImportLegacyTest.php > /tmp/t-task-9.log 2>&1; echo "rc=$?"; tail -20 /tmp/t-task-9.log
```

Expected: `rc=1`, naming `App\Support\Legacy\SqlDumpReader`. **Failing-first gate.**

- [ ] **Step 5: `SqlDumpReader`**

`app/Support/Legacy/SqlDumpReader.php` — the whole class, with the parsing rules stated:

```php
<?php

declare(strict_types=1);

namespace App\Support\Legacy;

use Generator;
use RuntimeException;

/**
 * One phpMyAdmin dump, read into associative rows, with no database involved.
 *
 * It handles exactly what the legacy dump contains and refuses everything else,
 * which is why it is 120 lines rather than a SQL parser:
 *
 *   - `CREATE TABLE \`x\` (` … `) ENGINE=MyISAM DEFAULT CHARSET=latin1;`
 *   - `INSERT INTO \`x\` (\`a\`, \`b\`) VALUES (…),(…);`, possibly several per
 *     table (the real `submissions` is split across two)
 *   - single-quoted literals with MySQL's backslash escapes, and NULL
 *
 * **Encoding: read as UTF-8, never transcoded.** Every table in the dump is
 * declared latin1 and the file is UTF-8 - the legacy application wrote UTF-8
 * bytes into latin1 columns and phpMyAdmin passed them through unconverted.
 * Greps for every classic double-encoding marker return zero. A latin1 -> utf8
 * step here would CREATE the corruption that is currently absent, so a value
 * that is not valid UTF-8 is recorded in encodingProblems() and handed back
 * with its invalid bytes substituted, for a human to look at.
 */
final class SqlDumpReader
{
    /** @var list<string> */
    private array $problems = [];

    public function __construct(private readonly string $path) {}

    /**
     * Every table the dump declares, whether or not it has rows.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $tables = [];

        foreach ($this->statements() as $statement) {
            if (preg_match('/^CREATE TABLE `([a-z0-9_]+)`/i', $statement, $matches) === 1) {
                $tables[] = $matches[1];
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Every row of one table, in dump order, as column => value|null.
     *
     * A Generator rather than an array because `reviews` is 599 rows in the
     * real dump and the caller writes as it reads - and because a table with
     * no INSERT is then an empty iteration rather than a special case.
     *
     * @return Generator<int, array<string, string|null>>
     */
    public function rows(string $table): Generator
    {
        if (! in_array($table, $this->tables(), true)) {
            throw new RuntimeException("The dump declares no table [{$table}].");
        }

        foreach ($this->statements() as $statement) {
            if (preg_match('/^INSERT INTO `'.preg_quote($table, '/').'` \(([^)]*)\) VALUES/i', $statement, $matches) !== 1) {
                continue;
            }

            $columns = array_map(
                static fn (string $column): string => trim(trim($column), '`'),
                explode(',', $matches[1]),
            );

            foreach ($this->tuples(substr($statement, strlen($matches[0]))) as $values) {
                if (count($values) !== count($columns)) {
                    // A row with the wrong arity is a dump this reader does not
                    // understand, and guessing which column is missing is how
                    // an import silently writes an abstract's phone number into
                    // its title.
                    throw new RuntimeException("Row in [{$table}] has ".count($values).' values for '.count($columns).' columns.');
                }

                yield array_combine($columns, $values);
            }
        }
    }

    /** @return list<string> */
    public function encodingProblems(): array
    {
        return $this->problems;
    }

    /**
     * Statements, one at a time, accumulated across physical lines.
     *
     * A string literal never spans a physical line in a mysqldump - newlines
     * inside a value are written as the escape sequence `\r\n` - so a line is
     * always a safe place to stop reading. What is NOT safe is splitting on
     * `;`, because a semicolon inside a value is common (it is one of the five
     * author separators in the real data), which is why the terminator is
     * found with the quote state tracked.
     *
     * @return Generator<int, string>
     */
    private function statements(): Generator
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot read [{$this->path}].");
        }

        try {
            $buffer = '';

            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);

                // Comments and the /*!40101 … */ conditional directives carry
                // no rows. Only skipped when nothing is buffered: a `--` can
                // appear inside a value.
                if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*'))) {
                    continue;
                }

                $buffer .= ($buffer === '' ? '' : "\n").$trimmed;

                if ($this->isComplete($buffer)) {
                    yield rtrim($buffer, "; \t\n");
                    $buffer = '';
                }
            }

            if (trim($buffer) !== '') {
                yield rtrim($buffer, "; \t\n");
            }
        } finally {
            fclose($handle);
        }
    }

    /** A statement ends at the first `;` that is not inside a single-quoted literal. */
    private function isComplete(string $buffer): bool
    {
        $inString = false;
        $length = strlen($buffer);

        for ($index = 0; $index < $length; $index++) {
            $character = $buffer[$index];

            if ($inString) {
                if ($character === '\\') {
                    $index++;   // the escaped character, whatever it is

                    continue;
                }

                if ($character === "'") {
                    $inString = false;
                }

                continue;
            }

            if ($character === "'") {
                $inString = true;

                continue;
            }

            if ($character === ';') {
                return true;
            }
        }

        return false;
    }

    /**
     * `(…),(…),(…)` into a list of lists of values.
     *
     * @return Generator<int, list<string|null>>
     */
    private function tuples(string $values): Generator
    {
        $length = strlen($values);
        $index = 0;

        while ($index < $length) {
            // Skip to the next `(` that opens a tuple.
            while ($index < $length && $values[$index] !== '(') {
                $index++;
            }

            if ($index >= $length) {
                return;
            }

            $index++;   // past the (
            $row = [];
            $current = '';
            $isString = false;
            $inString = false;

            while ($index < $length) {
                $character = $values[$index];

                if ($inString) {
                    if ($character === '\\') {
                        $current .= $this->unescape($values[$index + 1] ?? '');
                        $index += 2;

                        continue;
                    }

                    if ($character === "'") {
                        // Two quotes in a row is an escaped quote in some
                        // dumps; phpMyAdmin uses a backslash, but handling
                        // both costs one comparison.
                        if (($values[$index + 1] ?? '') === "'") {
                            $current .= "'";
                            $index += 2;

                            continue;
                        }

                        $inString = false;
                        $index++;

                        continue;
                    }

                    $current .= $character;
                    $index++;

                    continue;
                }

                if ($character === "'") {
                    $inString = true;
                    $isString = true;
                    $index++;

                    continue;
                }

                if ($character === ',' || $character === ')') {
                    $row[] = $this->value(trim($current), $isString);
                    $current = '';
                    $isString = false;
                    $index++;

                    if ($character === ')') {
                        break;
                    }

                    continue;
                }

                $current .= $character;
                $index++;
            }

            yield $row;
        }
    }

    private function value(string $raw, bool $wasQuoted): ?string
    {
        if (! $wasQuoted && strcasecmp($raw, 'NULL') === 0) {
            return null;
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            // Recorded, not converted. The whole dump is valid UTF-8 today;
            // if that ever stops being true, a person has to look at the row
            // rather than an algorithm guessing a source charset.
            $this->problems[] = mb_substr(
                (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-8'),
                0,
                120,
            );

            return (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        }

        return $raw;
    }

    private function unescape(string $character): string
    {
        return match ($character) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            '0' => "\0",
            'Z' => "\x1A",
            'b' => "\x08",
            default => $character,   // \' \" \\ and anything else, literally
        };
    }
}
```

- [ ] **Step 6: The two report objects**

`app/Support/Legacy/ManualReviewReport.php` — a collector with one `add(string $table, int $legacyId, string $reason)` and a `lines(): list<string>` that formats `"{$table}#{$legacyId}: {$reason}"`, plus `toMarkdown(): string` grouping by table with a heading per group and a count. `ImportReport` is a `final readonly`-ish container holding `array<string, int> $created`, `array<string, int> $skipped`, the `ManualReviewReport`, and `manualReview(): list<string>` delegating to it — plus `reportPath` once Task 10 writes the file.

The manual-review reasons this task can raise, each one a case where guessing would be worse than asking:

| Reason key | Raised when |
|---|---|
| `legacy.review.manager_widened` | a `conference_managers` row becomes an organization membership that also reaches the *other* edition |
| `legacy.review.orphan_reviewer` | a `conference_reviewers` row has a null `conference_id` or `reviewer_id` (the junk-row shape) |
| `legacy.review.reviewer_never_reviewed` | a reviewer is attached to a conference and wrote no answers |
| `legacy.review.invitation_token_dropped` | an invitation had a live plaintext token, which cannot be carried |
| `legacy.review.question_order_synthesised` | a form whose `order_column` is 0 on every row |
| `legacy.review.question_created_before_form` | a question whose `created_at` predates its form (the hand-copied 2023 rows) |
| `legacy.review.unknown_role` | a `users.role` this mapper has no rule for — including `multi-role`, which has zero rows today and is one INSERT away from having one |
| `legacy.review.encoding` | the reader's `encodingProblems()`, one line each |

- [ ] **Step 7: `ImportLegacy`, part one**

`app/Actions/Legacy/ImportLegacy.php` — `handle(string $dumpPath, string $uploadsPath, string $organizationSlug, bool $dryRun = false): ImportReport`. Structure, in order:

1. **Refuse early.** The organization must exist by slug and be approved; the dump must be readable; the uploads directory must be a directory. Each refusal is a `LegacyImportRefused` carrying a translated sentence (`lang/en/legacy.php`), never a stack trace.
2. **One transaction around everything**, with `$dryRun` implemented as *the same code inside a transaction that is rolled back at the end* rather than as a parallel "count only" path. A dry run that executes a different code path is a dry run that proves nothing; a rolled-back real run proves the constraints, the enum casts and the unique indexes all hold.

   ```php
       return DB::transaction(function () use (...): ImportReport {
           $report = $this->run(...);

           if ($dryRun) {
               // Everything above really ran - every insert, every constraint,
               // every cast - and none of it survives. A "dry run" that takes a
               // different path through the code is a dry run that proves
               // nothing.
               DB::rollBack();
           }

           return $report;
       });
   ```

   **`DB::rollBack()` inside `DB::transaction()` leaves the manager's level counter wrong** in some Laravel versions; the safer spelling is `DB::beginTransaction()` … `DB::rollBack()`/`DB::commit()` by hand for this one method, with a `try/catch` that rolls back and rethrows. Write it that way and say why in a comment.
3. **Users**, from `users`: skip `role = 'admin'`'s *platform* meaning (a legacy admin is an organization **owner** in v2, not `is_platform_admin`); fold the address; `name` from `full_name` or the local part; `password` a `Str::random(64)` hashed; no `email_verified_at`. Adopt an existing `users` row with the same address rather than creating a second — the owner may already have an account.
4. **Organization members**, from `conference_managers` plus the legacy `admin`: owner for the legacy admin, admin for each manager, each one reported as a widening.
5. **Conferences**, from `conferences`: slug from `Str::slug($name).'-'.$deadlineYear`, `status` archived, the six explicit defaults of decision 6, `reference_prefix` from Plan 3's `ReferencePrefix` helper, `submission_counter` left at 0 for Task 10 to raise.
6. **Review forms and questions**, from `evaluation_forms` and `evaluation_questions`: one active form per conference; `type` from the legacy enum (`likert` → `Likert`, `textarea` → `Text`); `scale_min` **1** and `scale_max` from `likert_scale` for a Likert, both null for a text question; `weight` left at the column default; `sort` from `order_column` **unless every row in the form is 0**, in which case from the id sequence, with a manual-review line. **`locked_at` is left null here and set in Task 10, after the answers are written** — `ReviewQuestion::booted()`'s `updating` hook throws `ReviewFormLocked` on a locked form, so a form locked before its questions are final cannot be corrected.
7. **Conference reviewers**, from `conference_reviewers`: skip and report any row with a null id; `status` active; `accepted_at` from the matching `reviewer_invitations` row **by folded email**, or the conference's deadline as a floor.
8. **Reviewer invitations**, from `reviewer_invitations`: `token_hash` a **fresh** hash of a fresh random token that is thrown away, `expires_at` the legacy value (all of them past), `accepted_at` the legacy value, `revoked_at` set for anything that never accepted — so nothing imported is a usable invitation. Report each dropped invitation token **by legacy id only** — `reviewer_invitations#24: a plaintext token was present and was not carried; the invitation is imported expired and revoked`. The token VALUE is never written to the report: the legacy walkthrough records three still-unaccepted invitations whose token column is populated, and the report is a Markdown file that sits on the `cass-storage` volume for as long as nobody deletes it — a longer life than the dump itself gets, since the runbook's **After** step removes `/tmp/legacy.sql`. `ImportLegacyTest`'s "never writes a legacy plaintext token into the manual-review report" case asserts both the fixture's invented tokens and the md5 *shape*.

Every created row calls `LegacyImport::record($table, $legacyId, $model)` and every step begins with `LegacyImport::find($table, $legacyId)`, which is what makes step 2's rollback and a second run agree.

- [ ] **Step 8: The command, the config and the registration**

`app/Console/Commands/ImportLegacyCommand.php`:

```php
    protected $signature = 'cass:import-legacy
        {sql : Path to the legacy mysqldump file}
        {uploads-dir : Path to the legacy uploads directory}
        {--organization-slug= : The existing organization to import into}
        {--dry-run : Run everything and roll it back}';
```

`handle(ImportLegacy $import): int` reads the three, refuses a missing `--organization-slug` with a sentence, calls the action, prints the created/skipped counts as a table (`$this->table()`), prints the manual-review count and the report path, and returns `self::SUCCESS` — **or `self::FAILURE` when the manual-review list is not empty**, because a run nobody reads the report of is a run that imported guesses. A `--dry-run` that produced no manual-review lines still returns `SUCCESS`.

`bootstrap/app.php` — append to `->withCommands([...])`:

```php
        ImportLegacyCommand::class,
```

**Check what else is in that array first.** If `cass:demo-seed` and `cass:demo-reset` exist under `app/Console/Commands` but are not named there (Task 4 Step 1 records this), do **not** add them — that is the demo branch's to fix, and Task 14 Step 4 writes the backlog line.

`config/cass.php`:

```php
    'legacy' => [
        // Where cass:import-legacy writes its manual-review report. Inside
        // storage/app, which is the ONLY part of storage/ on the cass-storage
        // volume (docker-compose.production.yml:52) - storage/logs and
        // storage/framework live in the container layer and vanish on the next
        // deploy, which is not where a file somebody has to read belongs.
        'report_directory' => env('CASS_LEGACY_REPORT_DIR', 'legacy'),
    ],
```

`.env.example`, appended after the Security block Tasks 7 and 8 opened — **this is the file Task 14's self-review claims lists every variable, so it is not optional**:

```bash
# --- Legacy import (Plan 6) ---
# Directory on the `local` disk where cass:import-legacy writes its
# manual-review report. Relative to storage/app, which is the only part of
# storage/ on the cass-storage volume - storage/logs and storage/framework live
# in the container layer and vanish on the next deploy.
CASS_LEGACY_REPORT_DIR=legacy
```

- [ ] **Step 9: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/SqlDumpReaderTest.php tests/Unit/ImportLegacyTest.php > /tmp/t-task-9.log 2>&1; echo "rc=$?"; tail -12 /tmp/t-task-9.log && \
php artisan test > /tmp/all-task-9.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-9.log && \
php artisan list 2>/dev/null | grep "cass:"
```

Expected: both `rc=0`; `baseline + 169`; and `cass:import-legacy` in the command list beside `cass:rescore` and `cass:reviewer-reminders`. `ImportLegacyTest`'s `never writes a legacy plaintext token into the manual-review report` is in that run: it fails if `ManualReviewReport` writes a token **value** rather than a legacy id, which would copy three still-live MD5 invitation tokens onto the `cass-storage` volume — the one file the runbook then tells an operator to `cat`.

Then read the reader against the **real** dump once, without writing anything:

```bash
cd /c/Users/ahmed/Documents/CASS && cat > /tmp/readreal.php <<'PHP'
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

$reader = new App\Support\Legacy\SqlDumpReader('Legacy/dbg1vzqja6lgef.sql');

foreach ($reader->tables() as $table) {
    printf("%-24s %d rows\n", $table, iterator_count($reader->rows($table)));
}

printf("encoding problems: %d\n", count($reader->encodingProblems()));
PHP
php /tmp/readreal.php
```

Expected, exactly (these are the counts fact 27 records, and the reader is wrong if it disagrees with any of them):

```
conferences                2 rows
conference_managers        4 rows
conference_reviewers       7 rows
evaluation_forms           2 rows
evaluation_questions      17 rows
newsletters                0 rows
reviewer_invitations      10 rows
reviews                  599 rows
submissions               24 rows
users                     10 rows
encoding problems: 0
```

**`submissions` printing 21 rather than 24 means the reader stopped at the first `INSERT`** — the real table is split across two statements. **`encoding problems: 0` is the fact 26 claim, executed rather than reasoned**; anything above zero means either the dump changed or `value()` is wrong, and both are worth stopping for.

`/tmp/readreal.php` reads `Legacy/`, which is git-ignored, and writes nothing. Delete it afterwards.

- [ ] **Step 10: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && rm -f /tmp/readreal.php && \
./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
if git status --short | grep -qE "Legacy/|legacy-review|legacy-uploads"; then echo "STOP: private material is staged"; exit 1; fi && \
echo "ok: nothing private staged" && \
php artisan test > /tmp/all-task-9.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(legacy): read the dump, map one tenant's organization, conferences, users and review forms

The dump is UTF-8 bytes inside latin1 columns and is never transcoded: a
conversion step would create the corruption that is currently absent, so a
value that is not valid UTF-8 is reported rather than converted. Idempotency is
one legacy_imports mapping table, not a legacy_id column on eight production
tables. A dry run is the real run inside a rolled-back transaction, so it
proves the constraints rather than a second code path.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: `pint rc=0`, `stan rc=0`, `ok: nothing private staged`, and the suite green.

---
### Task 10: `cass:import-legacy`, part two — abstracts, authors, files, reviews and the report a human reads

The second half of spec 5.10: *"submissions with authors parsed from the legacy text fields, files copied into private storage, and reviews with answers and computed scores."* Plus spec section 15's mitigation, which is the design rule for the whole command: *"Import command normalises encoding and reports rows needing manual review instead of guessing."*

**Seven decisions this task makes. Every one of them is a place the legacy data does not answer the question v2 asks.**

**1. `submission_authors.email` is NOT NULL and legacy stores no author email at all.** There is exactly one address per abstract, `contact_email`, and the byline is a text blob. So: the author whose name best matches the address's local part gets it and is marked corresponding; **every other author gets `legacy-{submission legacy id}-{n}@import.invalid`**, the reserved TLD of RFC 2606, which cannot be routed anywhere by anyone. Each one is a manual-review line. The alternative — relaxing the column to nullable — would be changing a production schema to fit a one-time import of twenty-four rows, and would quietly weaken the constraint for every real submission afterwards. **Nothing is ever emailed to an imported author**: the conferences land `archived`, which takes `/s/{token}` and every public page offline (the owner's recorded decision in the backlog), and no decision exists to send.

**2. The 2023 affiliation swap is handled by one conference-scoped heuristic that reports every row it touches.** Fact 28: for conference 5, `affiliation` holds a person's name in most rows, the full author list in two, and a research question in one; conference 6's eleven rows are clean institutional strings. The rule is deliberately small — **if the value parses as one or more person-shaped names that also appear in `authors`, it is not an affiliation** — and it never *deletes*: a value it cannot place goes into the manual-review report with the row's legacy id and the string itself, and the abstract is imported with no affiliation rather than with a research question in the affiliation field.

**3. `submissions.reference` is minted, and `conferences.submission_counter` is raised to match.** Legacy has no abstract number of any kind. Plan 3's `AllocateReference` is the only thing that mints one, so abstracts are imported **in `submission_date` order** and the counter ends where the last reference left it — which is what keeps a future submission to an un-archived conference from colliding.

**4. `submissions.access_token_hash` is minted and the plaintext is thrown away.** It is `char(64)` unique NOT NULL, so a row cannot exist without one. Nothing needs the plaintext: the conference is archived, `/s/{token}` 404s there, and if an author ever asks, "Resend status link" mints a new one and kills the old — which is what that action already does for every submission in the system (Plan 5's runbook section says so at length).

**5. Files are copied with the same content-addressed layout `StoreSubmissionFile` uses, but not through it.** That action takes an `UploadedFile` and runs the upload-time validation — size, count, extension, sniffed MIME — which is the right gate for a stranger's POST and the wrong one for the platform owner's own archive: a legacy file that exceeds `CASS_MAX_FILE_BYTES` or whose MIME sniffs oddly must be **imported and reported**, not refused. So the import computes the SHA-256, reads the MIME with Plan 3's `App\Support\Files\SniffedMimeType`, writes to the same `{sha256 prefix}/{ulid}.{ext}` path, and records `original_name` as the legacy stem — the real name was thrown away at upload in 2023 and is unrecoverable.

**6. Every imported review is `submitted`, including the two that are incomplete.** Legacy has no review header row at all — no status, no timestamp, no draft concept — and a "review" exists only as the set of `(submission_id, reviewer_id)` answer rows. Two of the 2024 reviews have 8 answers where the form has 9 (fact 28). Marking those `draft` would drop them out of Plan 5's `review_count`, out of the mean and out of the historical record of what the committee was actually given; `ReviewScorer` computes a weighted mean over the answers that exist, so an 8-of-9 review still produces a number. They are imported submitted and **reported**, one line each.

**7. `reviews.submitted_at` is synthetic, and says so.** There is no legacy timestamp. It is set to the abstract's own `submission_date` plus one day — so a review is never dated before the thing it reviews, which a conference-level date could not promise (submission 30 was accepted *after* its conference's deadline, fact 28) — and every imported review gets a manual-review line saying the date is invented.

**Files:**
- Create: `app/Support/Legacy/AuthorList.php`
- Modify: `app/Actions/Legacy/ImportLegacy.php` (steps 9-13), `app/Support/Legacy/ManualReviewReport.php`
- Modify: `app/Actions/Conferences/PurgeConference.php`, `app/Actions/Organizations/PurgeOrganization.php` (sweep `legacy_imports`)
- Modify: `lang/en/legacy.php`, `docs/runbooks/deploy-production.md`
- Test: `tests/Unit/AuthorListTest.php`, `tests/Unit/ImportLegacyTest.php` (appended), `tests/Unit/PurgeConferenceTest.php` (one case appended)

- [ ] **Step 1: Write the failing tests**

`tests/Unit/AuthorListTest.php`
```php
<?php

declare(strict_types=1);

use App\Support\Legacy\AuthorList;

// Pure: a string in, a list of arrays out. No database, no models.

it('splits a byline on each of the five separators the legacy data uses', function (string $byline, array $names) {
    expect(array_column(AuthorList::parse($byline), 'name'))->toBe($names);
})->with([
    'comma' => ['Dr Alia Example, Dr Badr Example', ['Dr Alia Example', 'Dr Badr Example']],
    // 47 occurrences in the real data.
    'crlf' => ["Dr Alia Example\r\nDr Badr Example", ['Dr Alia Example', 'Dr Badr Example']],
    'ampersand' => ['Sarah A & Zainab B & Mohammed C', ['Sarah A', 'Zainab B', 'Mohammed C']],
    'bullet' => ['Dr Alia Example • Dr Badr Example', ['Dr Alia Example', 'Dr Badr Example']],
    'semicolon' => ['Dr Alia Example; Dr Badr Example', ['Dr Alia Example', 'Dr Badr Example']],
    'mixed' => ["Dr Alia Example,\r\nDr Badr Example & Dr Carim Example", ['Dr Alia Example', 'Dr Badr Example', 'Dr Carim Example']],
]);

it('strips the journal markers the legacy bylines carry inside the names', function (string $byline, array $names) {
    expect(array_column(AuthorList::parse($byline), 'name'))->toBe($names);
})->with([
    // Real shapes: superscript affiliation numbers, asterisks, and the literal
    // word "corresponding" pasted in from a manuscript.
    'superscripts' => ['Ibrahim A,1 Reem B,2', ['Ibrahim A', 'Reem B']],
    'asterisk' => ['Ali F. Atwah 1,*, Ema B 2', ['Ali F. Atwah', 'Ema B']],
    'glued digits' => ['Isaq A. AlMughaizel1* , Abdulhameed A. Al-Bunyan1', ['Isaq A. AlMughaizel', 'Abdulhameed A. Al-Bunyan']],
    'the word itself' => ['Ibrahim A, Reem B,corresponding', ['Ibrahim A', 'Reem B']],
]);

it('drops empty fragments and trims endemic trailing whitespace', function () {
    expect(AuthorList::parse("Dr Alia Example ,, \r\n  "))->toHaveCount(1)
        ->and(AuthorList::parse('')->count ?? count(AuthorList::parse('')))->toBe(0);
});

it('marks the presenter from the separate legacy column', function () {
    $authors = AuthorList::parse('Dr Alia Example, Dr Badr Example', presenters: 'Dr Badr Example');

    expect($authors[0]['is_presenter'])->toBeFalse()
        ->and($authors[1]['is_presenter'])->toBeTrue();
});

it('marks a presenter who is not in the author list at all, by adding them', function () {
    // Real rows 12 and 13: presenter_names holds somebody `authors` does not.
    // Dropping them would lose the one person who actually stood up.
    $authors = AuthorList::parse('Dr Alia Example', presenters: 'Dr Kauther Example');

    expect(array_column($authors, 'name'))->toBe(['Dr Alia Example', 'Dr Kauther Example'])
        ->and($authors[1]['is_presenter'])->toBeTrue();
});

it('gives the contact address to the author whose name it looks like, and nobody else', function () {
    $authors = AuthorList::parse(
        'Dr Alia Example, Dr Badr Example',
        contactEmail: 'badr.example@example.org',
    );

    expect($authors[1]['email'])->toBe('badr.example@example.org')
        ->and($authors[1]['is_corresponding'])->toBeTrue()
        ->and($authors[0]['email'])->toBeNull()
        ->and($authors[0]['is_corresponding'])->toBeFalse();
});

it('falls back to the first author when the address matches nobody', function () {
    $authors = AuthorList::parse(
        'Dr Alia Example, Dr Badr Example',
        contactEmail: 'research.office@example.org',
    );

    // Somebody has to be corresponding - it is the only address the abstract
    // has - and the first author is the conventional answer. The import
    // reports every row that took this branch.
    expect($authors[0]['is_corresponding'])->toBeTrue()
        ->and($authors[0]['email'])->toBe('research.office@example.org')
        ->and(AuthorList::matchedContact('Dr Alia Example, Dr Badr Example', 'research.office@example.org'))->toBeFalse();
});

it('decides whether a legacy affiliation is really an affiliation', function (string $affiliation, string $authors, bool $isAffiliation) {
    expect(AuthorList::looksLikeAffiliation($affiliation, $authors))->toBe($isAffiliation);
})->with([
    // Conference 6: clean institutional strings.
    ['Example Central Hospital', 'Dr Dalia Example', true],
    ['Department of Pediatrics, Eastern Health Cluster', 'Dr Dalia Example', true],
    ['None', 'Dr Dalia Example', true],
    // Conference 5: the column holds a person who is also in the byline.
    ['Dr Alia Example', 'Dr Alia Example, Dr Badr Example', false],
    // Rows 12 and 13: the whole author list, in the affiliation column.
    ['Dr Alia Example , Dr Badr Example , Dr Carim Example', 'Dr Alia Example', false],
    // Row 7: a research question. Not a name, not an institution - which is
    // why this returns true and the IMPORT reports it rather than the parser
    // pretending to know.
    ['How does mobilisation affect recovery', 'Dr Alia Example', true],
]);
```

Append these cases to `tests/Unit/ImportLegacyTest.php`. The imports they need beyond Task 9's header — `Hash`, `Submission`, `SubmissionAuthor`, `SubmissionFile`, `ReviewAnswer`, `SubmissionStatus`, `ReviewStatus` — are **already listed in that header**; check them before running, because this application registers no facade aliases (`config/app.php` has no `aliases` key) and an undeclared `Hash::` or `Submission::` is a fatal error, not a failed assertion.

```php
it('imports every abstract with a reference, a token and its conference counter raised', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $conference = Conference::query()->where('slug', 'example-pediatric-symposium-2023')->firstOrFail();

    expect($conference->submissions()->count())->toBe(2)
        // Minted in submission_date order by Plan 3's AllocateReference, and
        // the counter left where the last one ended - or a future submission
        // to this conference collides.
        ->and($conference->submissions()->orderBy('id')->pluck('reference')->all())
            ->toBe([$conference->reference_prefix.'-001', $conference->reference_prefix.'-002'])
        ->and($conference->fresh()?->submission_counter)->toBe(2);

    $submission = $conference->submissions()->orderBy('id')->first();

    expect($submission?->access_token_hash)->toHaveLength(64)
        ->and($submission?->status)->toBe(SubmissionStatus::UnderReview)
        ->and($submission?->word_count)->toBeGreaterThan(0)
        // The browser's clock, which is all legacy has (legacy-review.md:280).
        ->and($submission?->submitted_at?->toDateString())->toBe('2023-11-11');
});

it('parses the authors out of the text columns', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $bulleted = Submission::query()->where('title', 'like', "A child%")->firstOrFail();

    expect($bulleted->authors()->count())->toBe(3)
        ->and($bulleted->authors()->orderBy('sort')->pluck('name')->all())
            ->toBe(['Dr Alia Example', 'Dr Badr Example', 'Dr Carim Example'])
        ->and($bulleted->authors()->where('is_corresponding', true)->count())->toBe(1);
});

it('gives every author without an address a non-routable one, and reports each', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $invented = SubmissionAuthor::query()->where('email', 'like', '%@import.invalid')->get();

    // submission_authors.email is NOT NULL and legacy stores no author email
    // at all. .invalid is RFC 2606's reserved TLD: it cannot resolve, so
    // nothing can ever be delivered to one of these by accident.
    expect($invented)->not->toBeEmpty()
        ->and($invented->first()?->email)->toMatch('/^legacy-\d+-\d+@import\.invalid$/')
        ->and($invented->first()?->is_corresponding)->toBeFalse()
        ->and(implode("\n", $report->manualReview()))->toContain('import.invalid');
});

it('leaves the 2023 affiliation column out when it holds a person, and reports it', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $swapped = Submission::query()->where('title', 'like', 'Bedside ultrasound%')->firstOrFail();
    $clean = Submission::query()->where('title', 'like', 'Sepsis recognition%')->firstOrFail();

    // Conference 5's affiliation column holds a name that is also in the
    // byline, so it is not an affiliation and is not written as one.
    expect($swapped->authors()->pluck('affiliation')->filter()->all())->toBe([])
        // Conference 6 is clean and the value is used.
        ->and($clean->authors()->first()?->affiliation)->toBe('Example Central Hospital');

    expect(implode("\n", $report->manualReview()))->toContain('affiliation');
});

it('copies each file with its digest and reports the ones that are missing and the ones that are orphans', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $files = SubmissionFile::query()->get();

    // Two of the three referenced files exist on disk in the fixture.
    expect($files)->toHaveCount(2)
        ->and($files->first()?->sha256)->toHaveLength(64)
        ->and($files->first()?->mime)->toBe('application/pdf')
        ->and($files->first()?->size)->toBeGreaterThan(0)
        // The path is the same content-addressed shape StoreSubmissionFile
        // writes, so the download route and the purge both work unchanged.
        ->and($files->first()?->path)->toMatch('#^[0-9a-f]{2}/[0-9A-Za-z]{26}\.pdf$#');

    Storage::disk('local')->assertExists((string) $files->first()?->path);

    $lines = implode("\n", $report->manualReview());

    expect($lines)->toContain('1729330644_2837.pdf')   // referenced, not on disk
        ->and($lines)->toContain('1727756783_9881.pdf'); // on disk, referenced by nothing
});

it('builds one review per reviewer per abstract, with the answers typed', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $submission = Submission::query()->where('title', 'like', 'Bedside ultrasound%')->firstOrFail();

    // Legacy has no review header row at all: a "review" is the set of answer
    // rows sharing (submission_id, reviewer_id).
    expect($submission->reviews()->count())->toBe(2)
        ->and($submission->reviews()->first()?->status)->toBe(ReviewStatus::Submitted)
        ->and(ReviewAnswer::query()->count())->toBe(5)
        // Every legacy answer is a digit 1-5 in a text column.
        ->and(ReviewAnswer::query()->whereNotNull('value_int')->count())->toBe(5)
        ->and(ReviewAnswer::query()->whereNotNull('value_text')->count())->toBe(0);

    // Synthetic and reported: there is no legacy timestamp, and a review must
    // not be dated before the abstract it reviews.
    expect($submission->reviews()->first()?->submitted_at?->greaterThan($submission->submitted_at))->toBeTrue();
});

it('imports an incomplete review as submitted, and reports it', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $submission = Submission::query()->where('title', 'like', 'Sepsis recognition%')->firstOrFail();
    $review = $submission->reviews()->first();

    // One answer of the two questions on form 7 - the shape of the two real
    // incomplete 2024 reviews. Marking it draft would drop it out of
    // review_count, out of the mean, and out of the record of what the
    // committee was actually given.
    expect($review?->status)->toBe(ReviewStatus::Submitted)
        ->and($review?->answers()->count())->toBe(1)
        ->and(implode("\n", $report->manualReview()))->toContain('incomplete');
});

it('scores every imported abstract and locks the forms afterwards', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $submission = Submission::query()->where('title', 'like', 'Bedside ultrasound%')->firstOrFail();

    // Recomputed by ComputeSubmissionScore rather than carried: legacy's
    // "Total Score" was an un-normalised SUM across reviewers and questions
    // (legacy-review.md:267) and is not comparable to anything v2 computes.
    // Reviewer 16 gave 4 and 5 -> 87.50; reviewer 17 gave 3 and 2 -> 37.50.
    expect($submission->score)->toBe('62.50')
        ->and($submission->review_count)->toBe(2)
        ->and($submission->score_spread)->not->toBeNull();

    // Locked LAST: ReviewQuestion::booted()'s updating hook throws
    // ReviewFormLocked on a locked form, so a form locked before its questions
    // are final cannot be corrected.
    expect(ReviewForm::query()->whereNull('locked_at')->count())->toBe(0);
});

it('writes the manual-review report to the private disk and names it in the result', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    expect($report->reportPath)->toStartWith('legacy/')
        ->and($report->reportPath)->toEndWith('.md');

    Storage::disk('local')->assertExists($report->reportPath);

    $markdown = (string) Storage::disk('local')->get($report->reportPath);

    // Grouped and counted, because a flat list of ninety lines is a file
    // nobody finishes reading.
    expect($markdown)->toContain('# Legacy import')
        ->and($markdown)->toContain('## submissions')
        ->and($markdown)->toContain('import.invalid');
});

it('writes no report file and no rows in dry-run mode', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society', dryRun: true);

    expect(Submission::query()->count())->toBe(0)
        ->and($report->manualReview())->not->toBeEmpty()
        // The report is the POINT of a dry run, so it is returned - it is just
        // not written to a disk the operator would then have to clean up.
        ->and($report->reportPath)->toBeNull();
});
```

And one case appended to `tests/Unit/PurgeConferenceTest.php`. Add `use App\Models\LegacyImport;` to that file's import block, alphabetically between `App\Models\EmailTemplate` and `App\Models\Organization` — this application registers no facade aliases, so without the import the case is a fatal "Class not found" rather than a failing assertion:

```php
it('sweeps the legacy mapping rows with the conference, and keeps the user mappings', function () {
    $conference = conferenceWithEverything();
    LegacyImport::record('conferences', 5, $conference);
    LegacyImport::record('submissions', 5, $conference->submissions()->firstOrFail());
    LegacyImport::record('review_forms', 5, $conference->reviewForms()->firstOrFail());
    $userMapping = LegacyImport::record('users', 5, $this->admin);

    app(PurgeConference::class)->handle($conference, $this->admin);

    // legacy_imports carries no foreign key - polymorphic, like short_links -
    // so nothing removes these for you, and a re-import would then find a
    // mapping to a row that no longer exists and skip a conference it should
    // have created.
    //
    // `users` is the exception: no purge in this application deletes a User
    // row, so that mapping is still TRUE after the purge and sweeping it would
    // only make a re-import re-adopt the same account by address.
    expect(LegacyImport::query()->where('legacy_table', '!=', 'users')->count())->toBe(0)
        ->and(LegacyImport::query()->whereKey($userMapping->getKey())->exists())->toBeTrue();
});
```

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/AuthorListTest.php tests/Unit/ImportLegacyTest.php tests/Unit/PurgeConferenceTest.php > /tmp/t-task-10.log 2>&1; echo "rc=$?"; tail -25 /tmp/t-task-10.log
```

Expected: `rc=1`, naming `App\Support\Legacy\AuthorList`. **Failing-first gate.**

- [ ] **Step 2: `AuthorList`**

`app/Support/Legacy/AuthorList.php` — pure, static, no database, and every rule carrying the row it was written for:

```php
<?php

declare(strict_types=1);

namespace App\Support\Legacy;

/**
 * One legacy byline into a list of authors.
 *
 * The legacy `submissions` table stores three overlapping text columns and no
 * author rows at all: `authors` (the full byline as one string),
 * `presenter_names` (sometimes a subset, sometimes one person the byline does
 * not contain) and `affiliation` (an institution in 2024, a person's name in
 * most of 2023, the whole author list in two rows, and a research question in
 * one). Every rule below was written for a shape that is actually in the data,
 * and every rule that cannot decide hands the row to the manual-review report
 * rather than guessing.
 */
final class AuthorList
{
    /**
     * The five separators the twenty-four real rows use between them. Order
     * matters only in that the multi-character ones are matched first, which
     * the alternation below does.
     */
    private const SEPARATORS = '/\s*(?:\r\n|\n|•|&|;|,)\s*/u';

    /**
     * Journal-style markers pasted in from a manuscript: a trailing
     * affiliation number, an asterisk, a superscript glued to a surname, and
     * the literal word "corresponding", which appears three times in the real
     * data as though it were a name.
     */
    private const MARKERS = [
        '/\bcorresponding\b/iu' => '',
        '/[0-9*†‡§¶]+/u' => '',
        '/\s{2,}/u' => ' ',
    ];

    /**
     * @return list<array{name: string, email: string|null, affiliation: string|null, is_presenter: bool, is_corresponding: bool, sort: int}>
     */
    public static function parse(
        string $authors,
        string $presenters = '',
        ?string $contactEmail = null,
        ?string $affiliation = null,
    ): array {
        $names = self::names($authors);
        $presenterNames = self::names($presenters);

        // A presenter the byline does not contain is a real shape (two rows),
        // and dropping them would lose the one person who actually stood up.
        foreach ($presenterNames as $presenter) {
            if (! self::contains($names, $presenter)) {
                $names[] = $presenter;
            }
        }

        $correspondingIndex = self::correspondingIndex($names, $contactEmail);

        $parsed = [];

        foreach (array_values($names) as $index => $name) {
            $parsed[] = [
                'name' => $name,
                // Only the corresponding author gets the one address the
                // abstract has; the caller invents a non-routable one for the
                // rest, because the column is NOT NULL.
                'email' => $index === $correspondingIndex ? $contactEmail : null,
                'affiliation' => $affiliation,
                'is_presenter' => self::contains($presenterNames, $name),
                'is_corresponding' => $index === $correspondingIndex,
                'sort' => $index + 1,
            ];
        }

        return $parsed;
    }

    /** Did the contact address actually match a name, or was the first author the fallback? */
    public static function matchedContact(string $authors, ?string $contactEmail): bool
    {
        $names = self::names($authors);

        return $contactEmail !== null && self::indexOfEmailOwner($names, $contactEmail) !== null;
    }

    /**
     * Is this legacy `affiliation` value really an affiliation?
     *
     * The one heuristic, and it is deliberately narrow: a value that parses as
     * one or more names that ALSO appear in the byline is a person, not an
     * institution. Everything else is treated as an affiliation - including
     * row 7's research question, which is why the caller reports every value
     * it keeps as well as every value it drops.
     */
    public static function looksLikeAffiliation(string $affiliation, string $authors): bool
    {
        $value = trim($affiliation);

        if ($value === '') {
            return false;
        }

        $names = self::names($authors);
        $candidates = self::names($value);

        if ($candidates === []) {
            return false;
        }

        foreach ($candidates as $candidate) {
            if (self::contains($names, $candidate)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function names(string $raw): array
    {
        $parts = preg_split(self::SEPARATORS, $raw) ?: [];
        $names = [];

        foreach ($parts as $part) {
            $name = (string) preg_replace(array_keys(self::MARKERS), array_values(self::MARKERS), $part);
            // Trailing whitespace is endemic in the real data, and so are
            // trailing full stops left by a split.
            $name = trim($name, " \t\n\r\0\x0B.,");

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @param list<string> $names */
    private static function contains(array $names, string $needle): bool
    {
        foreach ($names as $name) {
            if (self::fold($name) === self::fold($needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $names */
    private static function correspondingIndex(array $names, ?string $contactEmail): ?int
    {
        if ($names === [] || $contactEmail === null || $contactEmail === '') {
            return null;
        }

        // Somebody has to be corresponding: it is the only address the
        // abstract has. The first author is the conventional answer, and
        // matchedContact() is what tells the importer to report this row.
        return self::indexOfEmailOwner($names, $contactEmail) ?? 0;
    }

    /** @param list<string> $names */
    private static function indexOfEmailOwner(array $names, string $email): ?int
    {
        $local = self::fold((string) strstr($email, '@', true));

        if ($local === '') {
            return null;
        }

        foreach (array_values($names) as $index => $name) {
            $folded = self::fold($name);

            // `badr.example@` against "Dr Badr Example": the local part's
            // pieces all appear in the name. Deliberately not fuzzy - a
            // near-match that is wrong puts a stranger's address on somebody
            // else's abstract.
            $pieces = array_filter(preg_split('/[._-]+/', $local) ?: []);

            if ($pieces === []) {
                continue;
            }

            $hits = 0;

            foreach ($pieces as $piece) {
                if (strlen($piece) > 2 && str_contains($folded, $piece)) {
                    $hits++;
                }
            }

            if ($hits === count($pieces)) {
                return $index;
            }
        }

        return null;
    }

    private static function fold(string $value): string
    {
        // Honorifics are baked into the legacy names ("Dr ", "dr. ", "D. ")
        // and are not part of anybody's identity.
        $value = (string) preg_replace('/^\s*(dr|prof|mr|mrs|ms|miss)\.?\s+/iu', '', trim($value));

        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $value));
    }
}
```

- [ ] **Step 3: `ImportLegacy`, steps 9 to 13**

Appended to the action Task 9 wrote, inside the same transaction, in this order:

9. **Abstracts**, from `submissions`, **ordered by `submission_date`** (a `usort` on the rows, because the dump's own order is by id and the reference sequence has to follow the dates). Per row: resolve the conference through `LegacyImport::find('conferences', …)`; `title`; `abstract` verbatim; `word_count` from Plan 3's `App\Support\Text\WordCounter`; `reference` from `AllocateReference`; `access_token_hash` from a fresh `SubmissionToken` whose plaintext is discarded; `contact_phone`; `status` `UnderReview` when the abstract has answers and `Submitted` when it has none; `submitted_at` from `submission_date`; `presentation_preference` **null** — the legacy form never asked, and conference 6's question 67 ("Do you recommend this abstract for oral presentation") is a *reviewer's* opinion and must not be written into an *author's* preference field.
10. **Authors**, from `AuthorList::parse($row['authors'], $row['presenter_names'], $row['contact_email'], $affiliation)` where `$affiliation` is `$row['affiliation']` only when `AuthorList::looksLikeAffiliation()` says so. Then, per author with a null email, `legacy-{legacyId}-{sort}@import.invalid`, and one manual-review line per invented address, one per dropped affiliation, one per abstract whose contact address matched no name (`AuthorList::matchedContact()` false).
11. **Files**, from `attachment` **split on commas** — the review documents the column as a comma-separated list (`legacy-review.md:279`) even though all twenty-four rows hold one path, and a row with two must not silently lose one. Per path: `basename()`, look in the uploads directory, and when it is missing, report and continue. When it is there: `hash_file('sha256')`, `SniffedMimeType`, `filesize`, a fresh ULID, `Storage::disk('local')->put(substr($sha, 0, 2).'/'.$ulid.'.'.$extension, …)` and a `submission_files` row with `original_name` = the legacy basename. **After all abstracts**, `scandir` the uploads directory and report every file no row referenced.
12. **Reviews and answers**, from `reviews` grouped by `(submission_id, reviewer_id)`: one `reviews` row per pair with `review_form_id` from the submission's conference, `status` `Submitted`, `submitted_at` = the abstract's `submitted_at` plus one day; then one `review_answers` row per legacy row with `value_int` = `(int) $answer` for a Likert question and `value_text` for a text one. Report every pair with fewer answers than its form has questions, and one line saying every review timestamp is synthetic.
13. **Lock and score.** For each conference: `review_forms.locked_at = now()` (**after** every question and every answer exists), then `app(ComputeSubmissionScore::class)->forConference($conference)` — which is the second of the four uses `RescoreConferenceCommand`'s own docblock lists. Then write the report: `ManualReviewReport::toMarkdown()` to `config('cass.legacy.report_directory').'/import-'.now()->format('Ymd-His').'.md'` on the `local` disk, **unless `$dryRun`**, and put the path on the returned `ImportReport`.

- [ ] **Step 4: The purge sweep**

**`PurgeConference::collect()` does not yet produce the id lists this sweep needs**, so it gains three, immediately before its `return`:

```php
        $questions = $this->ids(ReviewQuestion::query()->whereIn('review_form_id', $forms));
        $conferenceReviewers = $this->ids(ConferenceReviewer::query()->where('conference_id', $conferenceId));
        $reviewerInvitations = $this->ids(ReviewerInvitation::query()->where('conference_id', $conferenceId));
```

Return them alongside the existing keys (`'questions'`, `'conferenceReviewers'`, `'reviewerInvitations'`) and widen the `@return` shape on `collect()` and the `@param` shape on `queries()` to match:

```php
     * @return array{submissions: list<int>, reviews: list<int>, forms: list<int>, questions: list<int>, conferenceReviewers: list<int>, reviewerInvitations: list<int>, shortLinks: list<int>, paths: list<string>}
```

Then `PurgeConference::queries()` gains **one** entry, immediately before `short_link_visits`:

```php
            // No foreign key, like short_links and activity_log: the mapping
            // rows would otherwise outlive their targets, and a re-import would
            // find a mapping to a row that no longer exists and skip a
            // conference it should have created. Everything is inside ONE
            // where() closure - a bare orWhere() here escapes the surrounding
            // scope and matches every mapping in the database, which is what
            // the parity test catches.
            //
            // `users` is deliberately NOT swept: no purge in this application
            // deletes a User row (PurgeOrganization ends at
            // organization_members), so the mapping is still true after the
            // purge and deleting it would only make a re-import re-adopt the
            // same account by address for no reason.
            'legacy_imports' => LegacyImport::query()->where(function (Builder $query) use ($conferenceId, $ids): void {
                $query->where(fn (Builder $q) => $q->where('imported_type', (new Conference)->getMorphClass())->where('imported_id', $conferenceId))
                    ->orWhere(fn (Builder $q) => $q->where('imported_type', (new Submission)->getMorphClass())->whereIn('imported_id', $ids['submissions']))
                    ->orWhere(fn (Builder $q) => $q->where('imported_type', (new ReviewForm)->getMorphClass())->whereIn('imported_id', $ids['forms']))
                    ->orWhere(fn (Builder $q) => $q->where('imported_type', (new ReviewQuestion)->getMorphClass())->whereIn('imported_id', $ids['questions']))
                    ->orWhere(fn (Builder $q) => $q->where('imported_type', (new ConferenceReviewer)->getMorphClass())->whereIn('imported_id', $ids['conferenceReviewers']))
                    ->orWhere(fn (Builder $q) => $q->where('imported_type', (new ReviewerInvitation)->getMorphClass())->whereIn('imported_id', $ids['reviewerInvitations']));
            }),
```

`PurgeOrganization` **does** need its own copy — it deletes the conference tree itself rather than calling `PurgeConference` — so add the same three id lists to its collection block (`$questionIds` from `$reviewFormIds`, `$conferenceReviewerIds` and `$reviewerInvitationIds` from `$conferenceIds`) and one delete immediately before `short_link_visits`, identical in shape but with `whereIn('imported_id', $conferenceIds)` for the `Conference` arm and one extra arm for `OrganizationMember` if Task 9 Step 4 records a mapping for it. Its `users` mappings stay, for the same reason.

- [ ] **Step 5: The runbook**

`docs/runbooks/deploy-production.md` — a new `## Importing the legacy conferences` section, after "Demo data" (which the demo branch added) and before "Brand assets":

````markdown
## Importing the legacy conferences

Run **once**, by the owner, after the release that adds the command. It is
idempotent — a second run creates nothing — so a run that fails halfway is
resumed by running it again.

### Before

1. Decide the organization. **There is no create form in the admin panel** —
   `OrganizationResource` is index-plus-view and `OrganizationPolicy::create()`
   answers false for everybody, because organizations are created by the public
   `/register` flow. Two ways to get one:
   - register it at `/register` (this creates a **new** owner account, so use an
     address that has no user row yet — `users.email` is unique and the legacy
     owner's address may already be the platform admin's) and approve it in the
     admin panel; or
   - create it on the host in one shot:

     ```bash
     sudo docker exec -it "$C" php artisan tinker --execute="\
     \$o = App\Models\Organization::query()->create(['name' => '<Name>', 'type' => App\Enums\OrganizationType::Society, 'country' => 'SA', 'purpose' => 'Imported from the legacy platform']);\
     \$o->forceFill(['slug' => '<slug>', 'status' => App\Enums\OrganizationStatus::Approved, 'approved_at' => now()])->save();\
     echo \$o->slug;"
     ```

   Either way the command **adopts** it by `--organization-slug=` and refuses if
   there is none: it does not invent a name, a type or a country. Set the logo,
   colours, type and country in the organizer panel before the import runs —
   that is also the moment somebody looks at the branding.
2. Copy the two inputs into the container. They are not in the image —
   `.dockerignore` excludes `Legacy` and `legacy-review.md` — and they must not
   be left on the host afterwards.

   ```bash
   C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
   sudo docker cp ./dbg1vzqja6lgef.sql "$C":/tmp/legacy.sql
   sudo docker cp ./uploads "$C":/tmp/legacy-uploads
   sudo docker exec "$C" chown -R app:app /tmp/legacy.sql /tmp/legacy-uploads
   ```

   `/tmp` deliberately, not the `cass-storage` volume: the dump carries ten
   bcrypt hashes and three live invitation tokens, and `/tmp` does not survive
   the next deploy.

### The dry run

```bash
sudo docker exec -it "$C" su-exec app php artisan cass:import-legacy \
  /tmp/legacy.sql /tmp/legacy-uploads --organization-slug=<slug> --dry-run
```

Everything runs — every insert, every constraint, every cast — inside a
transaction that is rolled back, so the counts are real and nothing is written.
Read the manual-review list it prints. **It exits 1 when that list is not
empty**, which it always will be: about twenty lines for the twenty-four
abstracts, and every one of them is a place the legacy data did not answer the
question v2 asks.

### The real run

```bash
sudo docker exec -it "$C" su-exec app php artisan cass:import-legacy \
  /tmp/legacy.sql /tmp/legacy-uploads --organization-slug=<slug>
```

Then read the report it names, which is on the `cass-storage` volume under
`storage/app/private/legacy/`:

```bash
sudo docker exec "$C" su-exec app cat storage/app/private/legacy/import-<timestamp>.md
```

### What the report will tell you, and what to do about each

| Line | What it means | What to do |
|---|---|---|
| `… @import.invalid` | An author with no address. Legacy stored one contact address per abstract and no author emails at all. | Nothing, unless somebody asks. The conferences are archived and nothing is ever sent to these. |
| `affiliation …` | The 2023 form labelled that field differently, so the column holds a person's name — or, in one row, a research question. | Open the abstract in the admin panel and correct it if it matters. |
| `contact address matched no author` | The corresponding author is a guess (the first one). | Check the abstract; a shared research-office mailbox is the usual cause. |
| `incomplete review` | A reviewer answered some questions and not others. Imported as submitted, because the committee did receive it. | Nothing. The score is the weighted mean of the answers that exist. |
| `review timestamps are synthetic` | Legacy stored no review date at all. Each one is the abstract's own date plus a day. | Nothing. |
| `file not found` | A row points at a PDF that is not in the uploads directory. | Look for it. If it is gone, the abstract is imported without a file. |
| `orphan file` | A PDF on disk that no row references — the residue of abstracts deleted directly in the legacy database. | Nothing. They are not imported; `submission_files.submission_id` is NOT NULL. |
| `manager … widened` | Legacy scoped a manager to one edition; v2 scopes an organizer to the whole organization. | Check the Members page and remove anybody who should not have both. |
| `invitation token dropped` | A legacy invitation still had a live plaintext token. It is imported expired and revoked, never usable. | Nothing. Invite the person again if they are still reviewing. |

### After

1. The scores are computed by the import itself. If you correct anything by
   hand afterwards, re-run `cass:rescore <CONFERENCE-ULID>` — which is use case
   2 in that command's own list.
2. Send each imported user a password reset. They were imported without a
   usable password on purpose (spec 5.10), and there is no welcome email.
3. Remove the inputs:

   ```bash
   sudo docker exec "$C" rm -rf /tmp/legacy.sql /tmp/legacy-uploads
   ```

4. The two conferences land **archived**, which takes their public pages, their
   short links and every `/s/{token}` offline. That is deliberate: there is no
   decision data in the legacy database to publish, and an archived conference
   is the honest status for a meeting that happened in 2023.
````

- [ ] **Step 6: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/AuthorListTest.php tests/Unit/ImportLegacyTest.php tests/Unit/PurgeConferenceTest.php tests/Feature/Console/ > /tmp/t-task-10.log 2>&1; echo "rc=$?"; tail -15 /tmp/t-task-10.log && \
php artisan test > /tmp/all-task-10.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-10.log
```

Expected: both `rc=0`; `baseline + 200`.

Then the one rehearsal that matters, against the **real** dump, in a transaction that is rolled back:

```bash
cd /c/Users/ahmed/Documents/CASS && docker compose -f docker-compose.dev.yml up -d && sleep 15 && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php artisan migrate:fresh --force > /dev/null 2>&1 && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php artisan tinker --execute='App\Models\Organization::factory()->approved()->create(["name" => "Legacy Owner", "slug" => "legacy-owner"]);' && \
mkdir -p /tmp/legacy-uploads && \
unzip -o -j Legacy/CASS.zip 'uploads/*' -d /tmp/legacy-uploads > /dev/null && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php artisan cass:import-legacy Legacy/dbg1vzqja6lgef.sql /tmp/legacy-uploads --organization-slug=legacy-owner --dry-run
```

Expected: 2 conferences, 10 users, 2 review forms, 17 questions, 24 abstracts, 24 files (3 orphans reported), 599 answers across the review pairs, and a manual-review list in the twenties. **Exit code 1**, because the list is not empty — that is the command working, not failing.

**This is the only place in the plan that touches `Legacy/`, and it writes nothing to the repository.** `/tmp/legacy-uploads` is outside the repo; delete it afterwards. If the numbers disagree with fact 27's counts, the mapper is wrong and this is where to find out, not on the production host.

- [ ] **Step 7: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && rm -rf /tmp/legacy-uploads && \
./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
if git status --short | grep -qE "Legacy/|legacy-review|legacy-uploads"; then echo "STOP: private material is staged"; exit 1; fi && \
echo "ok: nothing private staged" && \
php artisan test > /tmp/all-task-10.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(legacy): abstracts, authors, files and reviews, with a report of everything it refused to guess

The 2023 affiliation column holds a person's name in most rows and a research
question in one, so the parser applies one narrow rule and reports every row it
touched. Authors with no address get a .invalid one, because the column is NOT
NULL and legacy stored none. Scores are recomputed rather than carried: the
legacy total was an un-normalised sum across reviewers and questions.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: `pint rc=0`, `stan rc=0`, `ok: nothing private staged`, and the suite green.

---

### Task 11: Operations — digests, Dependabot, nightly backups, a health command and the memory measurement

Four backlog items under "Security and platform", all assigned to Plan 6, plus spec section 8's backups paragraph and section 11's *"base images pinned by digest"*.

**Five decisions this task makes.**

**1. Base images are pinned to the **manifest-list** digest, not the platform digest.** This is the one way to get digest pinning wrong and not notice. `docker inspect --format='{{index .RepoDigests 0}}' node:24-alpine` returns the digest of the image **for the architecture the daemon pulled**. The Docker daemon on this machine is `linux/arm64` (`docker version --format '{{.Server.Arch}}'` prints `arm64`), so the wrong pin produced here is the **arm64 platform digest** — which builds fine locally *and* in the arm64 `image` job, and fails only in the amd64 `smoke` job. A local `docker build` therefore proves nothing about the pin; `docker buildx imagetools inspect <tag> --format '{{.Manifest.Digest}}'` returning the multi-architecture index digest is the whole guard. Every pin below uses that, Step 2's command is written so the wrong one cannot be produced by accident, and Step 7 inspects each reference's media type rather than trusting a build.

**2. GitHub Actions are pinned by commit SHA, which closes the Node 24 item as a side effect.** The backlog asks to *"move to action versions that run on Node 24 … when the deprecation lands"*. Moving from `@v4` to `@v5` fixes it until the next deprecation; pinning by SHA fixes the larger problem — a mutable tag on a third-party action is an arbitrary-code-execution hole in a pipeline that holds no secrets today and will hold a deploy token the moment anybody wants one. Dependabot's `github-actions` ecosystem updates a SHA pin and rewrites the trailing `# vX` comment, so the readability cost is one comment per line and the maintenance cost is a pull request.

**3. The backup script runs on the host, not in a container, and is committed anyway.** It needs `docker exec` into the `mysql` container and a path on the host filesystem, so it cannot run inside the app image. It is committed at `docker/backup.sh` so it is reviewed, versioned and diffable like everything else, and the runbook's step is "copy it to the host and add the cron line". Spec section 8 asks for *"nightly `mysqldump` to the data volume with 14-day rotation"*: 14 days by `find -mtime`, gzipped, to `/srv/backups/cass`, with the existing off-host NAS sync unchanged. **`/srv/backups/cass` is a deviation from the spec's "to the data volume", it is deliberate, and Task 14 Step 7 records it as deviation 13** rather than letting the next reader find an unexplained difference in the one area whose failure mode is unrecoverable.

**4. There is no `cass:backup-verify` command, and the runbook has a monthly procedure instead.** A command inside the app container cannot see `/srv/backups` — it is a host path, deliberately, so a compromised app cannot delete the backups. Verification is therefore a host-side procedure: restore the newest dump into a scratch database and count rows against the live one. Writing a command that could only check the half it can reach would be worse than a documented ten-minute drill, because it would *look* like verification.

**5. `cass:health` answers the four questions `/up` deliberately does not.** `/up` is Laravel's health endpoint and exists for Docker and Coolify: it answers "is PHP serving". It cannot answer whether the queue worker is alive, whether the scheduler is running, whether the private disk is writable or whether migrations are pending — and every one of those fails silently. The scheduler question needs one new thing: a heartbeat, because Laravel tracks no "last run" anywhere. One `Schedule::call()` writing a cache key every five minutes is the cheapest honest answer, and Task 14's `schedule:list` expectation counts it.

**Files:**
- Modify: `Dockerfile`, `docker-compose.production.yml`, `docker-compose.dev.yml`, `.github/workflows/ci.yml`
- Create: `.github/dependabot.yml`, `docker/backup.sh`
- Create: `app/Console/Commands/HealthCommand.php`
- Modify: `bootstrap/app.php`, `routes/console.php`, `docs/runbooks/deploy-production.md`
- Test: `tests/Unit/HealthCommandTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/HealthCommandTest.php`
```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Cache::put('cass:scheduler-heartbeat', now()->toIso8601String(), 3600);
});

it('passes when everything it checks is healthy', function () {
    artisan('cass:health')
        ->expectsOutputToContain('database')
        ->expectsOutputToContain('queue')
        ->expectsOutputToContain('scheduler')
        ->expectsOutputToContain('private disk')
        ->assertExitCode(0);
});

it('fails when the scheduler heartbeat is stale', function () {
    // The one thing nothing else notices. schedule:work dying under
    // supervisord means no reviewer reminders and no pruning, silently, for
    // as long as nobody looks.
    Cache::put('cass:scheduler-heartbeat', now()->subHour()->toIso8601String(), 3600);

    artisan('cass:health')
        ->expectsOutputToContain('scheduler')
        ->assertExitCode(1);
});

it('fails when the heartbeat has never been written', function () {
    Cache::forget('cass:scheduler-heartbeat');

    artisan('cass:health')->assertExitCode(1);
});

it('fails when a migration is pending', function () {
    // The host rule is that migrations are never run at boot, so "the image
    // deployed and nobody ran migrate" is a real and recurring state - and
    // every page that touches the new column 500s until somebody does.
    DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

    artisan('cass:health')
        ->expectsOutputToContain('migrations')
        ->assertExitCode(1);
});

it('fails when the private disk cannot be written', function () {
    // Not a fake: the real disk, pointed at a directory that does not exist.
    // A full or unmounted cass-storage volume is how an abstract upload starts
    // failing at four in the morning before a deadline.
    config()->set('filesystems.disks.local.root', '/no/such/place');

    artisan('cass:health')
        ->expectsOutputToContain('private disk')
        ->assertExitCode(1);
})->skip('Enable once the check is written; Storage::fake in beforeEach has to be undone for this case.');

it('warns rather than fails on a queue with a backlog', function () {
    // A backlog is a worker that is slow or a burst that is large; a backlog
    // whose oldest job is hours old is a worker that is dead. Only the second
    // is a failure, because the first is what a decision-email run looks like.
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'available_at' => now()->subMinutes(2)->getTimestamp(),
        'created_at' => now()->subMinutes(2)->getTimestamp(),
    ]);

    artisan('cass:health')->assertExitCode(0);

    DB::table('jobs')->update([
        'available_at' => now()->subHours(3)->getTimestamp(),
        'created_at' => now()->subHours(3)->getTimestamp(),
    ]);

    artisan('cass:health')
        ->expectsOutputToContain('queue')
        ->assertExitCode(1);
});
```

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/HealthCommandTest.php > /tmp/t-task-11.log 2>&1; echo "rc=$?"; tail -20 /tmp/t-task-11.log
```

Expected: `rc=1`, naming `cass:health`. **Failing-first gate.**

- [ ] **Step 2: Pin the base images by digest**

```bash
cd /c/Users/ahmed/Documents/CASS && for IMAGE in node:24-alpine composer:2 php:8.4-fpm-alpine mysql:8.4 axllent/mailpit:latest; do
  printf '%-28s %s\n' "$IMAGE" "$(docker buildx imagetools inspect "$IMAGE" --format '{{.Manifest.Digest}}')"
done
```

**`buildx imagetools inspect`, not `docker inspect`.** The second returns the digest of the image for the architecture you pulled — amd64 here — and pinning that makes the `linux/arm64` production build fail with a manifest error. The first returns the multi-architecture index digest, which resolves on both. If `buildx` is unavailable, `docker manifest inspect <tag> -v | jq -r '.Descriptor.digest'` gives the same value.

`Dockerfile` — three lines, each keeping its tag as a human-readable comment:

```dockerfile
FROM node:24-alpine@sha256:<digest> AS assets
...
FROM composer:2@sha256:<digest> AS vendor
...
FROM php:8.4-fpm-alpine@sha256:<digest> AS runtime
```

**Keep the tag in the `FROM` line, not only in a comment**: `node:24-alpine@sha256:…` is valid and is what Dependabot updates in place. A bare `FROM node@sha256:…` works too and tells a reader nothing about what it is.

Add one comment block above the first `FROM`:

```dockerfile
# Base images are pinned by DIGEST (spec section 11). The digest is the
# multi-architecture index digest from `docker buildx imagetools inspect`, not
# the platform digest from `docker inspect`: this image is built for
# linux/arm64 in production and linux/amd64 in the CI smoke job, and a platform
# digest resolves on exactly one of them.
#
# .github/dependabot.yml raises a weekly pull request when any of these moves.
# Do not "unpin to get the security fix" - let Dependabot open the PR, so the
# change is reviewed and the digest stays recorded.
```

`docker-compose.production.yml:69` and `docker-compose.dev.yml:3`/`:20` get the same treatment for `mysql:8.4` and `axllent/mailpit:latest`. **`latest` becomes a digest with a `# latest as of <date>` comment** — a floating `latest` in a development compose file is how two developers end up with different Mailpits and one of them cannot reproduce a bug.

- [ ] **Step 3: Dependabot**

`.github/dependabot.yml` — the file that does not exist today (fact 34):

```yaml
# Dependabot, five ecosystems, weekly, grouped.
#
# Grouped on purpose: an ungrouped run opens eleven pull requests every Monday
# for a single-maintainer repository, which is how Dependabot gets switched off.
# One PR per ecosystem is reviewable in the time somebody actually has.
version: 2

updates:
  # The Dockerfile's three base images. `directories: [/, /docker]` would be
  # wrong: docker/ holds entrypoint.sh, nginx.conf, php.ini, supervisord.conf
  # and backup.sh, and no Dockerfile at all.
  - package-ecosystem: docker
    directory: /
    schedule:
      interval: weekly
      day: monday
      time: "06:00"
      timezone: Asia/Riyadh
    open-pull-requests-limit: 3
    groups:
      docker:
        patterns: ["*"]
    commit-message:
      prefix: "chore(docker)"

  # mysql:8.4 in BOTH compose files and axllent/mailpit in the dev one. This is
  # a separate ecosystem from `docker`, which reads Dockerfiles only: with the
  # `docker` block alone the compose digests pinned in Step 2 would never move
  # again, which is worse than not pinning them - the production database image
  # would silently stop receiving security updates.
  - package-ecosystem: docker-compose
    directory: /
    schedule:
      interval: weekly
      day: monday
      time: "06:00"
      timezone: Asia/Riyadh
    open-pull-requests-limit: 2
    groups:
      compose:
        patterns: ["*"]
    commit-message:
      prefix: "chore(compose)"

  - package-ecosystem: composer
    directory: /
    schedule:
      interval: weekly
      day: monday
      time: "06:00"
      timezone: Asia/Riyadh
    open-pull-requests-limit: 3
    groups:
      # Laravel, Filament and Livewire move together and their tests are the
      # slowest; keeping them out of the small-change group means a red suite
      # names the framework rather than a linter.
      framework:
        patterns: ["laravel/*", "filament/*", "livewire/*"]
      dev:
        dependency-type: development
      production:
        dependency-type: production
        exclude-patterns: ["laravel/*", "filament/*", "livewire/*"]
    commit-message:
      prefix: "chore(composer)"

  - package-ecosystem: npm
    directory: /
    schedule:
      interval: weekly
      day: monday
      time: "06:00"
      timezone: Asia/Riyadh
    open-pull-requests-limit: 2
    groups:
      npm:
        patterns: ["*"]
    commit-message:
      prefix: "chore(npm)"

  # The reason the workflow pins actions by SHA below: a mutable tag on a
  # third-party action is arbitrary code in the pipeline, and this is what
  # keeps a pinned SHA current.
  - package-ecosystem: github-actions
    directory: /
    schedule:
      interval: weekly
      day: monday
      time: "06:00"
      timezone: Asia/Riyadh
    open-pull-requests-limit: 2
    groups:
      actions:
        patterns: ["*"]
    commit-message:
      prefix: "chore(actions)"
```

Then pin the twelve `uses:` refs. Resolve each SHA:

```bash
cd /c/Users/ahmed/Documents/CASS && for REF in actions/checkout@v5 actions/setup-node@v5 actions/upload-artifact@v5 shivammathur/setup-php@v2 docker/setup-qemu-action@v3 docker/setup-buildx-action@v3 docker/build-push-action@v6; do
  OWNER_REPO="${REF%@*}"; TAG="${REF#*@}"
  SHA=$(gh api "repos/$OWNER_REPO/git/ref/tags/$TAG" --jq '.object.sha' 2>/dev/null || echo "RESOLVE-BY-HAND")
  printf '%-40s %s # %s\n' "$OWNER_REPO@$SHA" "" "$TAG"
done
```

**`v5` for `actions/checkout`, `actions/setup-node` and `actions/upload-artifact`, and that is what closes the Node 24 backlog item** — those majors run on Node 24. `shivammathur/setup-php` and the three `docker/*` actions stay on their current majors; bump them only if `gh api` says the tag exists. **If a `v5` tag does not resolve, keep `v4` and leave the backlog item open with one line saying which action is still behind** — a plan that guesses a version number is a CI failure at the worst moment.

A tag ref may point at a tag object rather than a commit; if `.object.type` is `tag`, dereference with `gh api repos/$OWNER_REPO/git/tags/$SHA --jq '.object.sha'`.

**Verify the file after the first push**, because a rejected ecosystem is silent in the repository and only visible on GitHub: open **Insights → Dependency graph → Dependabot** and confirm **five** entries are listed with no config error. If GitHub rejects the `docker-compose` ecosystem for this repository, Task 14 Step 4 keeps the backlog line rewritten rather than deleted — see there.

- [ ] **Step 4: The backup script and its cron line**

`docker/backup.sh`
```sh
#!/bin/sh
# Nightly database backup (spec section 8: "nightly mysqldump to the data
# volume with 14-day rotation").
#
# Runs on the HOST, not in a container: it docker-execs into the mysql
# container and writes to a host path, deliberately, so that a compromised app
# container cannot reach or delete the backups. It is committed here so it is
# reviewed and versioned like everything else; the runbook's step is to copy it
# to /usr/local/bin/cass-backup and add the cron line.
#
# Usage: cass-backup [destination]   (default /srv/backups/cass)
set -eu

# 0077: the dump is a complete copy of every author's name, address, phone
# number and abstract, every reviewer's comments and every user's password
# hash. Without this it lands 0644 in a 0755 directory and any local account
# on this host can read it - which would make the whole "keep the backups off
# the app container" argument above pointless.
umask 0077

DEST="${1:-/srv/backups/cass}"
KEEP_DAYS="${CASS_BACKUP_KEEP_DAYS:-14}"
STAMP="$(date +%F-%H%M)"

CONTAINER="$(docker ps --filter label=com.docker.compose.service=mysql --format '{{.Names}}' | grep -i cass | head -1)"

if [ -z "$CONTAINER" ]; then
    echo "[cass-backup] no cass mysql container is running" >&2
    exit 1
fi

mkdir -p "$DEST"
# Set every run, so a chmod somebody did by hand cannot quietly loosen it.
chmod 700 "$DEST"

# MYSQL_PWD from the container's own environment, so the password is never an
# argv the host's process list can show. --single-transaction so InnoDB is
# consistent without locking the site during a deadline.
#
# --no-tablespaces: without it mysqldump queries INFORMATION_SCHEMA.FILES for
# CREATE TABLESPACE, which needs the GLOBAL `PROCESS` privilege (MySQL 8.0.21
# and later). The `cass` user the image creates from MYSQL_USER holds ALL
# PRIVILEGES ON cass.* and nothing global, so without this flag every run dies
# with "Access denied; you need (at least one of) the PROCESS privilege(s)"
# and there is no backup at all.
# Not piped into gzip: POSIX sh has no `pipefail`, so `set -e` cannot see a
# mysqldump that failed mid-stream - the gzip succeeds and the run looks clean.
if ! docker exec "$CONTAINER" sh -c '
    MYSQL_PWD="$MYSQL_PASSWORD" mysqldump \
        --single-transaction \
        --quick \
        --no-tablespaces \
        --default-character-set=utf8mb4 \
        --routines \
        --events \
        -h 127.0.0.1 -u"$MYSQL_USER" "$MYSQL_DATABASE"
' > "$DEST/cass-$STAMP.sql.partial"; then
    echo "[cass-backup] mysqldump failed" >&2
    rm -f "$DEST/cass-$STAMP.sql.partial"
    exit 1
fi

# A dump that died mid-stream still gzips to well over 4 KB and would rotate a
# good backup away fourteen days later. mysqldump writes this line last.
if ! tail -5 "$DEST/cass-$STAMP.sql.partial" | grep -q '^-- Dump completed'; then
    echo "[cass-backup] dump is truncated - refusing it" >&2
    rm -f "$DEST/cass-$STAMP.sql.partial"
    exit 1
fi

# Compressed only once it is whole, so a run interrupted at 03:07 never leaves
# a truncated file that looks like a backup and restores as half a database.
gzip -9 -c "$DEST/cass-$STAMP.sql.partial" > "$DEST/cass-$STAMP.sql.gz"
rm -f "$DEST/cass-$STAMP.sql.partial"
chmod 600 "$DEST/cass-$STAMP.sql.gz"

SIZE="$(stat -c %s "$DEST/cass-$STAMP.sql.gz")"

# An empty-ish gzip is a dump that failed and still exited 0 somewhere in the
# pipe. Refuse it rather than rotating a good backup away in favour of it.
if [ "$SIZE" -lt 4096 ]; then
    echo "[cass-backup] dump is only ${SIZE} bytes - refusing it" >&2
    rm -f "$DEST/cass-$STAMP.sql.gz"
    exit 1
fi

find "$DEST" -name 'cass-*.sql.gz' -type f -mtime "+${KEEP_DAYS}" -delete
find "$DEST" -name '*.partial' -type f -mtime +1 -delete
# $DEST needs room for fourteen compressed dumps AND one uncompressed one.

echo "[cass-backup] $DEST/cass-$STAMP.sql.gz (${SIZE} bytes), keeping ${KEEP_DAYS} days"
```

`Dockerfile` — one line beside the other `docker/` copies, so the mode is not an accident of the host filesystem (`.dockerignore` does not exclude `docker/`, so it would be copied by `COPY . .` anyway — fact 45):

```dockerfile
COPY --chmod=755 docker/backup.sh /usr/local/bin/cass-backup.sh
```

It is not *run* in the container; it is there so an operator on the host can `docker cp` it out of the running image rather than hunting for the repository.

The runbook's **Backups** section (`:647-650`) is replaced entirely — Task 13 writes it, and this step's job is only to make sure the script it describes exists.

- [ ] **Step 5: `cass:health` and the scheduler heartbeat**

`routes/console.php` has no namespace and imports four symbols today (`SendReviewerRemindersCommand`, `Inspiring`, `Artisan`, `Schedule`). Add two, in Pint's alphabetical order:

```php
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
```

`\Cache` would in fact resolve without its import — `config/app.php` declares no `aliases` key, so `Facade::defaultAliases()` registers the global alias — but `\CarbonImmutable` has no alias and the closure would fatal with `Class "CarbonImmutable" not found` the first time the scheduler fires it, five minutes after deploy, where nothing is watching. Larastan catches it at Step 8; importing both is what keeps Pint and the facade-alias question out of it.

Then one entry appended:

```php
// The scheduler has no "last run" anywhere in Laravel, so cass:health cannot
// answer "is schedule:work alive" without one. Five minutes, a one-hour TTL:
// a heartbeat older than fifteen minutes is a scheduler that has been dead
// long enough to have missed something, and in production the cache store is
// the database (CACHE_STORE=database), so this is one small write.
Schedule::call(static function (): void {
    Cache::put('cass:scheduler-heartbeat', CarbonImmutable::now()->toIso8601String(), 3600);
})->everyFiveMinutes()->name('cass-scheduler-heartbeat')->withoutOverlapping();
```

**`->name()` before `->withoutOverlapping()` is required for a closure event** and optional for a command event — Plan 4's own comment on the reminder schedule records the same fact from the other side.

`app/Console/Commands/HealthCommand.php` — six checks, each returning a row and a boolean, printed as a table and summed into the exit code:

```php
    protected $signature = 'cass:health {--json : Machine-readable output}';

    protected $description = 'Check the things /up deliberately does not: the queue, the scheduler, the private disk and pending migrations';
```

| Check | Healthy when | Why `/up` cannot answer it |
|---|---|---|
| `database` | a `select 1` returns | `/up` does not touch the database |
| `migrations` | `migrate:status` reports none pending | migrations are never run at boot (the host rule), so "deployed but not migrated" is a normal state that 500s every page touching a new column |
| `queue` | the oldest available job is under 15 minutes old | a dead `queue:work` under supervisord means no email is ever sent, and nothing else notices |
| `scheduler` | the heartbeat is under 15 minutes old | a dead `schedule:work` means no reviewer reminders and no pruning |
| `private disk` | a probe file writes, reads back and deletes | a full or unmounted `cass-storage` volume fails an author's upload before a deadline |
| `mail` | `MAIL_MAILER` is not `log` or `array` in production, and `MAIL_HOST` is set | a misconfigured mailer queues perfectly and delivers nothing |

Each check is a small private method returning `array{name: string, ok: bool, detail: string}`; `handle()` runs them, renders `$this->table(['Check', 'Status', 'Detail'], …)`, and returns `self::FAILURE` if any `ok` is false. `--json` prints `json_encode($rows)` instead, so a host cron can grep it.

`bootstrap/app.php` — `HealthCommand::class` appended to `->withCommands([...])`.

- [ ] **Step 6: The memory measurement, documented rather than guessed**

Spec section 10: *"These are starting values, to be measured under load and adjusted."* The backlog repeats it. This step writes the measurement into the runbook and **changes no number**, because a number changed without a measurement is the same guess with more confidence.

The arithmetic worth writing down, from fact 37: `pm.max_children = 4` against `memory_limit=256M` is a worst case of 1 GiB against a 768 MiB container limit. That is not a bug — a real request uses a fraction of the cap, and `pm.max_requests = 500` recycles a worker before it drifts — but it does mean the container's limit, not php-fpm's, is what would kill a runaway, and it would kill it by OOM rather than by a PHP fatal. The two measurements that decide whether to change anything:

```bash
# 1. What a worker actually uses, under the heaviest thing this app does.
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec "$C" su-exec app php artisan tinker --execute='
$before = memory_get_usage(true);
$conference = App\Models\Conference::query()->whereNotNull("published_at")->firstOrFail();
$pdf = app(App\Actions\Conferences\GenerateConferencePoster::class)->handle($conference, App\Enums\PosterSize::A3);
printf("poster: %.1f MiB peak\n", memory_get_peak_usage(true) / 1048576);'

# 2. What the containers use over a day, at the peak. The names are not fixed -
#    neither compose file sets container_name and Coolify generates them - so
#    resolve both the way the rest of this runbook does. ($C from measurement 1
#    is the same container as $APP if the block runs in one shell.)
APP=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
DB=$(sudo docker ps --filter label=com.docker.compose.service=mysql --format '{{.Names}}' | grep -i cass | head -1)
sudo docker stats --no-stream "$APP" "$DB"
```

The poster render is the heaviest single request this application has (dompdf, a 1200 px QR PNG, TTF metrics — `phpunit.xml`'s own comment says it is the reason the suite needs 512M). If its peak is comfortably under 256 MiB and `docker stats` shows the app container's steady state well under 768 MiB, **change nothing** and record the numbers in the runbook. If the poster is near the cap, raise `memory_limit` and lower `pm.max_children` to 3 in the same commit, because those two numbers only mean anything together.

- [ ] **Step 7: Run the tests, then the whole suite, then build the image**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/HealthCommandTest.php > /tmp/t-task-11.log 2>&1; echo "rc=$?"; tail -10 /tmp/t-task-11.log && \
php artisan test > /tmp/all-task-11.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-11.log && \
php artisan schedule:list && \
sh -n docker/backup.sh && echo "backup.sh parses"
```

Expected: every `rc=0`; `baseline + 206`; **four** scheduled entries (`queue:prune-failed`, `model:prune`, `cass:reviewer-reminders` and the new heartbeat closure); and `backup.sh parses`.

**A local `docker build` is NOT the gate on Step 2, and believing it is would be the whole mistake.** The daemon on this machine is `arm64`, so a *platform* digest pinned by accident builds here perfectly and fails only in the amd64 `smoke` job. Inspect the references instead, then build both architectures explicitly:

```bash
# Every pin must be an INDEX (multi-arch), not a platform manifest. Inspect the
# reference exactly as the file writes it - image:tag@sha256:... - because the
# pins span five different repositories.
cd /c/Users/ahmed/Documents/CASS && \
for REF in $(grep -ohE '(FROM |image: )[a-z0-9./_-]+:[a-zA-Z0-9._-]+@sha256:[0-9a-f]{64}' Dockerfile docker-compose.production.yml docker-compose.dev.yml | sed -E 's/^(FROM |image: )//'); do
  printf '%-64s ' "$REF"
  docker buildx imagetools inspect "$REF" --raw 2>/dev/null \
    | grep -qE '"mediaType": ?"application/vnd\.(oci\.image\.index\.v1|docker\.distribution\.manifest\.list\.v2)\+json"' \
    && echo INDEX || echo 'NOT AN INDEX - re-resolve with buildx imagetools'
done

# And build the architecture this machine is NOT: only an explicit amd64 build
# exercises what CI's smoke job does.
docker buildx build --platform linux/amd64 -t cass:digest-check-amd64 . > /tmp/build-amd64.log 2>&1; echo "amd64 rc=$?"
docker buildx build --platform linux/arm64 -t cass:digest-check-arm64 . > /tmp/build-arm64.log 2>&1; echo "arm64 rc=$?"
```

Expected: every line `INDEX`, and both `rc=0`. A platform digest fails the matching build with `no match for platform in manifest` — which is exactly the failure the manifest-list rule exists to prevent, and it is far better to see it here than in CI.

- [ ] **Step 8: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && docker rmi cass:digest-check-amd64 cass:digest-check-arm64 > /dev/null 2>&1; \
./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?" && \
php artisan test > /tmp/all-task-11.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "chore(ops): pin every base image and action by digest, back up nightly, and answer what /up cannot

The digests are the multi-architecture index digests, not the platform ones:
this image builds for arm64 in production and amd64 in the smoke job, and a
platform digest resolves on exactly one of them. cass:health checks the queue,
the scheduler, the private disk and pending migrations - the four things that
fail silently - and the scheduler check needed a heartbeat, because Laravel
records no last run anywhere.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---

### Task 12: The Plans 1-2 language sweep, and the public polish

Two backlog items, both assigned to Plan 6:

> Spec section 10 requires every string to live in language files so Arabic can be added without code changes. Plans 1 and 2 hardcode English in the public Blade views, the countdown script and the poster template; extract them to `lang/en/*.php` before any Arabic work.

and the whole "Public site polish (Plan 6, launch polish)" section. Plans 3, 4 and 5 converted their own files completely; the boundary is clean and this task finishes the job.

**Six decisions this task makes.**

**1. The hardcoded-English sweep gets a lower word threshold for this task's files, or it passes with half the site in English.** `LanguageCoverageTest`'s existing cases flag any line with `str_word_count($line) >= 3` (fact 38). Every string this task is here to extract that *matters most* is shorter than that: "About", "Contact", "Terms", "Privacy", "Organizer login", "Call for abstracts", "What to prepare", "Submit abstract", "Total scans", "Deadline", "Tracks", "Venue". A `$plan6Sources` case copied verbatim from the Plan 5 one would go green with the navigation bar, both footers and half the conference page untranslated. **The Plan 6 case uses `>= 1`**, with a small explicit allow-list for the things that are genuinely not prose — `CASS`, `01`–`04`, `KB`, `MB`, `PDF`, `·`, `–`.

**2. Eight strings already exist and are reused rather than duplicated** (fact 39). `submission.window.upcoming` / `.closed` / `.not_configured` match `submit-cta.blade.php:28`/`:34`/`:40` word for word; `submission.buttons.submit` matches `:14`; `submission.fields.honeypot` matches `contact-form.blade.php:25` and `register-organization.blade.php:70`; `members.invite.password_help`, `.password_confirmation` and `.name` match `register-organization.blade.php:22`/`:26`/`:9`. Copying any of them into a new file would be two strings to keep in step through a translation — and a translator who changes one and not the other produces a page that disagrees with itself.

**3. `countdown.js` does not get a key per word; it gets whole phrases from `data-` attributes.** `__()` cannot reach a `.js` module, and the script's English is built by inline ternaries (`days === 1 ? '' : 's'`) that have no Arabic equivalent — Arabic has six plural forms. So `submit-cta.blade.php` passes four finished phrases on the `<time data-countdown>` element (`data-countdown-days`, `-hours`, `-minutes`, `-passed`), each already a `:count`-substituted sentence for the singular and the plural, and the script chooses between them instead of assembling words. **And it gains a string it does not have today**: fact 40 records that when the deadline passes the badge is simply removed — there is no "Deadline passed" copy anywhere — so `data-countdown-passed` is new copy, written here, not an extraction.

**4. Both public layouts gain `dir`, and the direction lives in the language file.** Both already set `lang="{{ str_replace('_', '-', app()->getLocale()) }}"`; neither sets a direction (fact 42), so an `ar` locale would render left-to-right and "Arabic is a copy of `lang/en`" would be false in one attribute. `'dir' => 'ltr'` is a key in `lang/en/public.php`, so the Arabic copy sets `rtl` and nothing in any view branches on a locale name.

**5. The `og:image` backlog item is closed with the logo, not the poster, and says so.** Task 3 already added `og:image` from the organization's logo. The poster would be a better share card and is behind an authenticated, rate-limited, dompdf-rendering route (`conference-assets.poster`, 10/min/user) that a social crawler cannot reach — so serving it would mean a second public route rendering a PDF-to-PNG on demand, which is a feature, not a polish item. Task 14 rewrites the backlog line to say exactly that rather than deleting it.

**6. The pagination item is re-deferred, unchanged, because its trigger has not fired.** *"Add the pagination views to the Tailwind `@source` list when the first paginated public page appears."* No public page paginates (fact 43) and this plan adds none. Adding `@source` for views nothing renders would be a change that cannot be tested.

**Files:**
- Create: `lang/en/public.php`, `lang/en/conference.php`, `lang/en/poster.php`
- Modify: `lang/en/submission.php` (a `countdown` group), `lang/en/mail.php` (a `contact` group — Task 8 opened it)
- Modify: `resources/views/public/{landing,about,privacy,terms,conference}.blade.php`, `public/partials/submit-cta.blade.php`
- Modify: `resources/views/components/layouts/{public,conference}.blade.php`
- Modify: `resources/views/livewire/public/{contact-form,register-organization}.blade.php`
- Modify: `resources/views/pdf/conference-poster.blade.php`
- Modify: `resources/views/filament/organizer/pages/dashboard.blade.php`, `.../resources/conferences/pages/short-link.blade.php`
- Modify: `resources/js/countdown.js` (**not** `resources/css/app.css` — Step 5 explains why it needs no change)
- Create: `public/images/illustrations/hero-researcher.webp`
- Modify: `tests/Feature/LanguageCoverageTest.php`
- Test: the two new cases in that file, plus `tests/Feature/Public/LandingPageTest.php` (appended)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/LanguageCoverageTest.php`, in the same shape as the three cases already there:

```php
/**
 * Every file Plans 1 and 2 left in English, which spec section 10 says must
 * live in a language file before any Arabic work. The list includes two
 * ORGANIZER views (the dashboard banners and the short-link page) that no
 * existing backlog item mentions and that no previous sweep covered - they are
 * the same vintage and the same problem.
 */
$plan6Sources = [
    'resources/views/components/layouts/public.blade.php',
    'resources/views/components/layouts/conference.blade.php',
    'resources/views/public/landing.blade.php',
    'resources/views/public/about.blade.php',
    'resources/views/public/privacy.blade.php',
    'resources/views/public/terms.blade.php',
    'resources/views/public/conference.blade.php',
    'resources/views/public/partials/submit-cta.blade.php',
    'resources/views/livewire/public/contact-form.blade.php',
    'resources/views/livewire/public/register-organization.blade.php',
    'resources/views/pdf/conference-poster.blade.php',
    'resources/views/emails/contact-message.blade.php',
    'resources/views/filament/organizer/pages/dashboard.blade.php',
    'resources/views/filament/organizer/resources/conferences/pages/short-link.blade.php',
];

// The key-resolution case below reads every file in that list. The
// visible-English case after it reads every file EXCEPT the one mail view:
// resources/views/emails/contact-message.blade.php is a <x-mail::message>
// MARKDOWN document, and Blade::compileString() + strip_tags() leave its
// syntax behind as the literal lines `#` and `** ** &lt; &gt;` (measured,
// not guessed). No language key can remove those, and an allow-list entry
// would only hide them. Task 8 moved that view's three English strings
// into lang/en/mail.php, so the key-resolution case is what proves it.
$plan6Views = array_values(array_diff($plan6Sources, [
    'resources/views/emails/contact-message.blade.php',
]));

it('resolves every translation key plans 1 and 2 now use', function () use ($plan6Sources) {
    $missing = [];

    foreach ($plan6Sources as $relative) {
        $path = base_path($relative);

        expect(file_exists($path))->toBeTrue("Expected {$relative} to exist.");

        // `public`, `conference` and `poster` are this task's three new files;
        // the rest of the alternation is what the Plan 5 case already allows,
        // because eight of these strings are REUSED from submission.* and
        // members.* rather than duplicated (Plan 6 fact 39).
        preg_match_all(
            '/(?:__|@lang|trans)\(\s*[\'"]((?:public|conference|poster|submission|mail|members|reviewer|decisions|admin|domain|legacy)\.[a-z0-9_.]+)[\'"]/',
            (string) file_get_contents($path),
            $matches,
        );

        foreach (array_unique($matches[1]) as $key) {
            if (! Lang::has($key)) {
                $missing[] = "{$key} (used in {$relative})";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('leaves no visible english at all in the plans 1 and 2 pages', function () use ($plan6Views) {
    // The threshold is ONE word, not three.
    //
    // Every existing case in this file uses `str_word_count($line) >= 3`, and
    // that is why this task exists at all: "About", "Contact", "Terms",
    // "Privacy", "Organizer login", "Call for abstracts", "What to prepare",
    // "Submit abstract", "Total scans", "Deadline", "Venue" and "Tracks" are
    // every one of them two words or fewer. A case copied from the Plan 5 one
    // would pass with the navigation bar, both footers and half the conference
    // page still in English.
    //
    // The allow-list is what is genuinely not prose: the brand, the four step
    // numerals on the landing page, the two file-size units, the file type and
    // the two typographic separators.
    $allowed = ['CASS', '01', '02', '03', '04', 'KB', 'MB', 'PDF', '·', '–', '—'];
    $offenders = [];

    foreach ($plan6Views as $relative) {
        $compiled = Blade::compileString((string) file_get_contents(base_path($relative)));

        $stripped = (string) preg_replace([
            '/<\?php.*?\?>/s',
            '/<\?php.*$/s',
            '/<script\b[^>]*>.*?<\/script>/s',
            '/<style\b[^>]*>.*?<\/style>/s',
        ], ' ', $compiled);

        preg_match_all(
            '/\b(?:placeholder|title|alt|aria-label)\s*=\s*"([^"]*)"|\b(?:placeholder|title|alt|aria-label)\s*=\s*\'([^\']*)\'/i',
            $stripped,
            $attributes,
        );

        $text = strip_tags($stripped)."\n".implode("\n", [...$attributes[1], ...$attributes[2]]);

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');

            if ($line === '' || in_array($line, $allowed, true)) {
                continue;
            }

            $offenders[] = "{$relative}: {$line}";
        }
    }

    expect($offenders)->toBe([]);
});

it('carries the countdown phrases as data attributes rather than words in a script', function () {
    // __() cannot reach a .js module, and the script's English is built by
    // inline ternaries (days === 1 ? '' : 's') that have no Arabic analogue -
    // Arabic has six plural forms. So the view passes finished phrases and the
    // script chooses between them.
    $source = (string) file_get_contents(base_path('resources/js/countdown.js'));

    expect($source)->not->toContain("' day'")
        ->and($source)->not->toContain("'days'")
        ->and($source)->not->toContain("' left'")
        ->and($source)->toContain('dataset');

    foreach (['countdown.days', 'countdown.hours', 'countdown.minutes', 'countdown.passed'] as $key) {
        expect(Lang::has('submission.'.$key))->toBeTrue($key);
    }
});

it('gives both public layouts a direction that a translation can flip', function (string $view) {
    $compiled = Blade::compileString((string) file_get_contents(base_path($view)));

    // Both already set lang=; neither set dir=, so an `ar` locale would render
    // left to right and "Arabic is a copy of lang/en" would be false in one
    // attribute.
    expect($compiled)->toContain('dir=')
        ->and(Lang::get('public.dir'))->toBe('ltr');
})->with([
    'resources/views/components/layouts/public.blade.php',
    'resources/views/components/layouts/conference.blade.php',
]);
```

Append to `tests/Feature/Public/LandingPageTest.php`:

```php
it('gives every illustration an intrinsic size and serves the hero as webp', function () {
    $response = get('/')->assertOk();
    $html = (string) $response->getContent();

    // Five of the six site images had no width/height (Plan 6 fact 41), which
    // is five separate layout shifts on the slowest connection the site has.
    preg_match_all('/<img\b[^>]*>/i', $html, $images);

    foreach ($images[0] as $tag) {
        expect($tag)->toMatch('/\bwidth="\d+"/', $tag)
            ->and($tag)->toMatch('/\bheight="\d+"/', $tag);
    }

    // The hero is 158 KB of PNG at 1600x1051. WebP at the size it is displayed
    // is the single biggest byte on this page.
    expect($html)->toContain('hero-researcher.webp');
});

it('points the organizer login at the panel route rather than a hardcoded path', function () {
    $response = get('/')->assertOk();

    // Three views hardcoded url('/org/login'). The panel owns that path, and
    // a panel whose ->path() changes would leave three dead links nothing
    // tests.
    expect((string) $response->getContent())->toContain(route('filament.organizer.auth.login'))
        ->and((string) $response->getContent())->not->toContain('"/org/login"');
});
```

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/LanguageCoverageTest.php tests/Feature/Public/LandingPageTest.php > /tmp/t-task-12.log 2>&1; echo "rc=$?"; tail -30 /tmp/t-task-12.log
```

Expected: `rc=1`, with the second case listing roughly **110 offenders**. **Read that list — it is the work order for Steps 2 to 5.** Failing-first gate.

- [ ] **Step 2: The three new language files**

`lang/en/public.php` — the platform's own pages: the two layouts' navigation and footers, the landing page's three sections and four steps, about, privacy, terms, the contact form and the registration form. Groups in the order a visitor meets them: `meta`, `nav`, `footer`, `landing`, `about`, `privacy`, `terms`, `contact`, `register`. Plus, at the top level and not in a group:

```php
    /*
     * The document direction. A key rather than a locale check in a Blade
     * view, so that lang/ar/public.php is a copy of this file with one word
     * changed and no view branches on a language name - which is exactly what
     * spec section 10 asks for.
     */
    'dir' => 'ltr',
```

`lang/en/conference.php` — the public conference page: `call`, `dates`, `venue`, `deadline`, `terms`, `tracks`, `prepare` (with `:count`, `:max` and `:types` placeholders for the three `<li>`s at `conference.blade.php:85-87`), `contact_organizers`, and a `preview` group. **`preview.badge` reuses nothing**: `submission.preview.body` is close but carries no `:status`, and the conference page's version prints the status word. Two keys that differ by one placeholder are two keys.

`lang/en/poster.php` — three strings: `submit`, `deadline`, `scan`.

`lang/en/submission.php` gains one group:

```php
    /*
     * The deadline countdown. Whole phrases rather than words, because
     * resources/js/countdown.js cannot call __() and because English's
     * `days === 1 ? '' : 's'` has no Arabic analogue - Arabic has six plural
     * forms, and a translator needs a sentence to work with.
     *
     * `passed` is NEW COPY, not an extraction: today the badge is simply
     * removed when the deadline passes and there is no message at all.
     */
    'countdown' => [
        'days' => ':days days, :hours hours left',
        'days_one' => '1 day, :hours hours left',
        'hours' => ':hours hours, :minutes minutes left',
        'hours_one' => '1 hour, :minutes minutes left',
        'minutes' => ':minutes minutes left',
        'minutes_one' => '1 minute left',
        'passed' => 'The deadline has passed',
    ],
```

- [ ] **Step 3: The views**

Fourteen files, mechanically. Three rules:

1. **Reuse before you create.** Before adding a key, grep for the sentence: `grep -rn "Submissions are closed" lang/` finds `submission.window.closed`. The eight reuses of fact 39 are listed there; there may be more.
2. **A sentence with markup in the middle is one key with placeholders, not three keys.** `conference.blade.php:85` is *"Abstract of up to `<span>500</span>` words."* — that is `__('conference.prepare.words', ['count' => …])` with the `<span>` moved outside the string, or `{!! __(...) !!}` with the span inside it and the value escaped by the translator's `:count`. Prefer the first. `about.blade.php:8` is the hard one — one sentence split across two `<a>` tags — and it is `{!! __('public.about.contact', ['email' => $mailto, 'form' => $formLink]) !!}` with both links built in the view and `e()`-escaped.
3. **The two layouts get `dir`** on the `<html>` element, beside the `lang` that is already there:

```blade
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ __('public.dir') }}">
```

While in `public.blade.php`, do the two polish items that live on the same lines:

- `url('/org/login')` at `:23` becomes `route('filament.organizer.auth.login')`. Same at `landing.blade.php:10` and `register-organization.blade.php:85`. **Check the route name resolves first** — `php artisan route:list --except-vendor | grep organizer.auth.login` — because `route()` on an unknown name throws at render time, on the landing page, for every visitor.
- Add `<meta name="description" content="{{ __('public.meta.description') }}">`, which this layout has never had (fact 42).

- [ ] **Step 4: The countdown script**

`resources/views/public/partials/submit-cta.blade.php:18-22` — the `<time>` element carries the phrases:

```blade
                <time datetime="{{ $conference->submission_deadline->toIso8601String() }}"
                      data-countdown
                      data-deadline="{{ $conference->submission_deadline->toIso8601String() }}"
                      data-countdown-days="{{ __('submission.countdown.days', ['days' => ':days', 'hours' => ':hours']) }}"
                      data-countdown-days-one="{{ __('submission.countdown.days_one', ['hours' => ':hours']) }}"
                      data-countdown-hours="{{ __('submission.countdown.hours', ['hours' => ':hours', 'minutes' => ':minutes']) }}"
                      data-countdown-hours-one="{{ __('submission.countdown.hours_one', ['minutes' => ':minutes']) }}"
                      data-countdown-minutes="{{ __('submission.countdown.minutes', ['minutes' => ':minutes']) }}"
                      data-countdown-minutes-one="{{ __('submission.countdown.minutes_one') }}"
                      data-countdown-passed="{{ __('submission.countdown.passed') }}">
```

The `':days'` arguments look strange and are deliberate: `__()` is asked to render the *template* with its own placeholders intact, so the script substitutes the numbers client-side. If that reads badly, the honest alternative is `Lang::get(...)` with no replacements at all — `{{ Lang::get('submission.countdown.days') }}` — which is the same string and says what it means. **Take the second.**

`resources/js/countdown.js` — `remaining()` returns a shape rather than a sentence, and one new function substitutes:

```js
// Whole phrases from data- attributes, chosen by magnitude and by count, and
// substituted here. The strings are not in this file on purpose: __() cannot
// reach a module, and `days === 1 ? '' : 's'` is an English rule that Arabic -
// with six plural forms - cannot use.
const phrase = (element, key, replacements) => {
    const template = element.dataset[key] ?? '';

    return Object.entries(replacements).reduce(
        (text, [name, value]) => text.replaceAll(`:${name}`, String(value)),
        template,
    );
};
```

and the deadline-passed branch stops removing the badge and prints `data-countdown-passed` instead — the one behaviour change in this task, and the reason `passed` is new copy rather than an extraction.

- [ ] **Step 5: The images**

```bash
cd /c/Users/ahmed/Documents/CASS && for F in public/images/illustrations/*.png public/images/icons/*.svg; do
  printf '%-52s %s\n' "$F" "$(php -r 'printf("%dx%d", ...array_slice(getimagesize($argv[1]) ?: [0,0], 0, 2));' "$F" 2>/dev/null || echo 'svg')"
done
```

Add the printed `width`/`height` to `landing.blade.php:24`, `:29`, `:34` (the three icons, 40×40 to match `h-10 w-10`), `landing.blade.php:44` and `about.blade.php:10`. `landing.blade.php:15` already has them.

The hero becomes WebP. There is **no `resources/images/`** (fact 41) — these are served straight from `public/` by `asset()` — so the WebP is a committed file, produced the way `docs/brand/build-icons.mjs` produces the brand rasters: from a scratch directory outside the repository, with the tool not in `package.json`.

```bash
mkdir -p /tmp/cass-webp && cd /tmp/cass-webp && npm init -y > /dev/null && npm i sharp > /dev/null && \
node -e "
const sharp = require('sharp');
const root = process.argv[1];
sharp(root + '/public/images/illustrations/hero-researcher.png')
  .resize({ width: 1200 })
  .webp({ quality: 82 })
  .toFile(root + '/public/images/illustrations/hero-researcher.webp')
  .then(i => console.log(i.width + 'x' + i.height + ' ' + i.size + ' bytes'));
" /c/Users/ahmed/Documents/CASS
```

Then `landing.blade.php:15` becomes a `<picture>` with the WebP first and the PNG as the fallback, keeping the existing `width`/`height` and `loading="eager"` on the `<img>`. **Keep the PNG**: the poster template and any mail client that cannot take WebP still resolve it, and it is already committed. Add one line to the runbook's "Brand assets" section naming the command, so the next person can regenerate it.

`public/images/icons/badge.svg` is referenced by nothing (fact 41). **Leave it and record it in the backlog** rather than deleting an asset in a task about strings — a deleted file is a `git rm` somebody has to notice in a diff full of translations.

`resources/css/app.css` — no change. Its two `@source` roots already cover `resources/views`, and this task adds no view outside it. **Do** record the live bug fact 44 names: the Dockerfile's `assets` stage copies only `resources` and `public` (`:10-11`), so `@source '../../app/Livewire'` scans nothing when the production image builds its CSS. That is a Tailwind class used only inside a Livewire PHP file silently missing in production. It belongs to the organizer-theme backlog item, which is where the stage order is discussed; Task 14 adds the sentence there.

- [ ] **Step 6: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/LanguageCoverageTest.php tests/Feature/Public/ tests/Feature/Organizer/ConferencesOverviewWidgetTest.php tests/Feature/PosterFontsTest.php > /tmp/t-task-12.log 2>&1; echo "rc=$?"; tail -15 /tmp/t-task-12.log && \
npm run build > /tmp/build.log 2>&1; echo "build rc=$?" && \
php artisan test > /tmp/all-task-12.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-12.log
```

Expected: every `rc=0`; `baseline + 214`.

**`PosterFontsTest` is in that run because the poster template is in the list.** It renders the PDF for real, and a `{{ __('poster.deadline') }}` that resolves to the key string would show up there as a PDF with `poster.deadline` printed on it — which no assertion would catch, so read one:

```bash
cd /c/Users/ahmed/Documents/CASS && php -r "
\$a = require 'lang/en/public.php'; \$b = require 'lang/en/conference.php'; \$c = require 'lang/en/poster.php';
printf(\"public: %d entries\nconference: %d entries\nposter: %d entries\n\",
  count(\$a, COUNT_RECURSIVE), count(\$b, COUNT_RECURSIVE), count(\$c, COUNT_RECURSIVE));
"
```

Expected: roughly 70, 25 and 4. A `poster` count of 3 means a group is missing; the three keys plus the group key is 4.

- [ ] **Step 7: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?" && \
php artisan test > /tmp/all-task-12.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "i18n: every string Plans 1 and 2 hardcoded, and the polish that lives on the same lines

The sweep's word threshold is one, not three: every string that matters most
here - About, Contact, Terms, Organizer login, Call for abstracts, Submit
abstract - is two words or fewer, and a case copied from Plan 5's would have
passed with the navigation bar still in English. The countdown gets whole
phrases from data attributes, because a module cannot call __() and English's
one-or-many rule is not Arabic's.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---

### Task 13: `docs/launch-checklist.md` and the runbook

Spec section 12's last line: *"**Before launch**: security review, production-readiness audit, and a manual walk-through of all flows on the deployed site."* Three of those are things a person does, not things a test asserts, so this task writes the list they work through — and finishes the runbook sections the previous twelve tasks left owing.

No application code. No new tests. The gate is that the suite stays green and that every command quoted in either document is one the plan has actually run.

**Three decisions this task makes.**

**1. The checklist is a committed file, not a section of the runbook.** The runbook is what somebody reads at two in the morning with a broken deploy; the checklist is what somebody works through once, in order, with time. Mixing them means the launch items are read every release and the release items are skipped at launch. `docs/launch-checklist.md` has checkboxes because it is meant to be copied into an issue and ticked.

**2. Every item is either a command with an expected output, or a question with a decidable answer.** "Review security" is not an item. "`curl -sI https://cass.towardpcc.com/ | grep -i content-security-policy` prints an enforcing header" is. The eight owner decisions Plans 3, 4 and 5 left open are on the list as **questions**, each with the one-line change that implements either answer, because a launch that ships them unanswered ships whichever answer a plan author happened to pick.

**3. The custom-domain runbook section documents the Coolify API call but the application never makes it.** Spec section 15: *"Custom-domain TLS needs a manual Coolify step — documented runbook; admin notification on verification; v2 may automate through the Coolify API."* An application that can add its own domains needs a Coolify API token in its environment, and that token can rewrite the whole deployment — a much larger blast radius than the problem. The runbook gives the exact call so the manual step is thirty seconds.

**Files:**
- Create: `docs/launch-checklist.md`
- Modify: `docs/runbooks/deploy-production.md`
- Modify: `README.md` (one line pointing at the checklist)

- [ ] **Step 1: `docs/launch-checklist.md`**

```markdown
# CASS launch checklist

Spec section 12: *"Before launch: security review, production-readiness audit,
and a manual walk-through of all flows on the deployed site."* This is that
list. Copy it into an issue and tick it.

Nothing here is automated on purpose. Everything that could be a test is one;
what is left is either a judgement, an owner decision, or something only a
person with a browser and the production host can see.

---

## 1. Owner decisions, before anything else

Eight questions Plans 3, 4 and 5 recorded and deliberately did not answer.
Each has a one-line change either way. Leaving one unanswered ships whichever
answer a plan author happened to choose.

- [ ] **Turnstile fails open when Cloudflare is unreachable.** A connection
      error is logged at `warning` and the submission is allowed; an explicit
      `success: false` or a 5xx is still a refusal. The reasoning: an outage
      would otherwise refuse every abstract in the last hour before a deadline,
      which is when it would cost most, while the honeypot, the four-second
      minimum fill time and the 5/min/IP throttle keep running.
      **To flip it:** `return false;` in place of the `return true;` in the
      `catch (ConnectionException …)` branch of `App\Support\Turnstile::verify()`,
      and invert one case in `tests/Unit/TurnstileTest.php`.
- [ ] **Any organization member can withdraw an abstract and resend its status
      link.** Spec section 4 gives "submit / edit / withdraw" to the author
      alone, but `SubmissionPolicy::withdraw()` and `resendLink()` mirror
      `view()`, which is every member down to a plain `member`. A withdrawal is
      irreversible and a resent link kills the one the author is holding. Both
      are logged with the actor.
      **To narrow:** both policy methods to `canManageOrganization()`.
- [ ] **Any organization member can remove a reviewer and invite anyone.**
      `ReviewerInvitationPolicy` and `ConferenceReviewerPolicy` mirror spec
      section 4's "invite reviewers, assign, decide" row, which is every
      member. Bounded by `CASS_INVITATION_SEND_LIMIT` (200/hour/org) and
      `ReviewerList::MAX_ENTRIES` per paste.
      **To narrow:** both to `canManageOrganization()`.
- [ ] **A submitted review freezes at the review deadline, but a draft can
      still be finished.** The strict reading freezes everything — but
      `reviewer_overdue`, a template this platform ships, tells a reviewer
      after the deadline to "complete them as soon as you can", which the
      strict reading makes impossible.
      **To make it strict:** one clause in `SubmitReview::blockers()`, and
      invert one case in `tests/Unit/SubmitReviewTest.php`.
- [ ] **Sending decision letters rotates every notified author's status link.**
      The token is stored hashed and the plaintext exists only in an emailed
      link, so a working `{{status_link}}` in a second email means a new token,
      which kills the first. Every decided author gets a letter, so every
      decided author gets a live link.
      **If unacceptable:** a second longer-lived column on `submissions`, or a
      `submission_tokens` table (which is also what "share this with my
      co-author" would want).
- [ ] **An organizer can unassign a reviewer who has already submitted a
      review.** Spec 5.5 says assignments can be changed "until the review is
      submitted"; Plan 4 allows it after. The submitted review survives — only
      the assignment row goes, and the ranking averages `reviews`, not
      `review_assignments` — but the coverage summary then describes a reviewer
      who is gone.
      **To make it strict:** one clause in `AssignReviewers::blockers()`
      refusing a removal whose reviewer has a submitted review, plus a case in
      `tests/Unit/AssignReviewersTest.php`.
- [ ] **Only an owner or admin may send decision letters, which narrows spec
      section 4's "invite reviewers, assign, decide" row.**
      `SubmissionPolicy::decide()` follows the spec (every member);
      `ConferencePolicy::sendDecisions()` deliberately does not, because one
      click emails every author and cannot be un-sent.
      **To widen:** make `sendDecisions()` mirror `decide()`.
- [ ] **Resending the same decision overwrites the stored letter.** A changed
      decision appends a new `submission_decisions` row; a plain resend
      re-renders over `letter_subject`, `letter_markdown` and `notified_at`, so
      the superseded text is lost (the per-send history survives in
      `email_logs`).
      **To keep every send:** one more column, or one row per send.

## 2. Owner actions on services this repository does not control

- [ ] **Turnstile keys.** Create a widget for `cass.towardpcc.com` at
      Cloudflare, set `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` in
      Coolify, redeploy. Confirm the widget renders on
      `/c/{org}/{conference}/submit` — with no keys it is silently absent and
      the form is protected only by the honeypot and the throttle.
- [ ] **Two-factor authentication on the platform admin account.** All three
      panels have `AppAuthentication::make()->recoverable()` enabled. Enrol at
      `/admin` → Profile, and **store the recovery codes somewhere that is not
      this platform**.
- [ ] **SPF and DKIM for the sending domain.** Send one real email from the
      platform (register a throwaway organization) and check the received
      headers show `spf=pass` and `dkim=pass`. Spec section 15 names
      deliverability from a personal mailbox as a risk; this is the check.
      Note `.env.example:56` still ships `MAIL_FROM_ADDRESS="cass@towardpicu.com"`
      while `CASS_CONTACT_EMAIL` is `cass@towardpcc.com` — **decide which
      domain sends**, and make SPF and DKIM match it.
- [ ] **Cloudflare: Always Use HTTPS = on.** Spec section 9 gives HSTS to
      Cloudflare and the application deliberately sends no
      `Strict-Transport-Security` of its own.
- [ ] **Cloudflare: HSTS on**, `max-age` 6 months, include subdomains off
      (custom domains are other people's names).
- [ ] **Cloudflare: WAF managed rules on**, Bot Fight Mode on. Then submit one
      real abstract to confirm neither blocks the Livewire upload endpoint.
- [ ] **Cloudflare: Rocket Loader OFF** (it is off by default — confirm, do not
      assume). It rewrites every `<script>` in the HTML it proxies and
      re-executes it from its own loader, dropping the per-request nonce that
      Livewire's config script, Filament's published inline scripts and the
      Turnstile callback all carry. With Rocket Loader on and
      `CASS_CSP_REPORT_ONLY=false`, the panels and the submission form have no
      JavaScript at all — and nothing in CI can see it, because no CI job goes
      through Cloudflare. Check:
      `curl -s https://cass.towardpcc.com/org/login | grep -c 'text/rocketloader'` → **0**.
- [ ] **Cloudflare: Email Address Obfuscation — confirmed harmless.** Its
      decoder is served from `/cdn-cgi/scripts/…` on our own origin, so
      `script-src 'self'` allows it. Re-check after any change to `script-src`.
- [ ] **Cloudflare cache rules:** cache `/build/*` and `/storage/*`
      aggressively (both are content-addressed or immutable), and **bypass**
      everything else. A cached `/s/{token}` or a cached panel page is a data
      leak between visitors.
- [ ] **OCI security list: restrict port 22 to known addresses.** The backlog
      records that the origin IP and SSH details existed in early commits of
      the public repository. Ports 80 and 443 stay open to Cloudflare's ranges
      only.
- [ ] **Confirm `/srv/backups/cass` is on a filesystem with room** for fourteen
      compressed dumps **plus one uncompressed one** (the script dumps to disk
      first so a truncated `mysqldump` cannot hide inside a pipe), and that the
      NAS sync includes it **and** the weekly `cass-storage` tarball.

## 3. Production readiness, on the deployed site

Each line is a command and the answer it must give.

- [ ] `curl -sI https://cass.towardpcc.com/up` → `200`.
- [ ] `curl -sI https://cass.towardpcc.com/ | grep -i content-security-policy`
      → an **enforcing** header, not `-Report-Only`. Deploy with
      `CASS_CSP_REPORT_ONLY=true` first, walk section 4 with the browser
      console open, then set it false and redeploy.
- [ ] `curl -sI https://cass.towardpcc.com/ | grep -iE 'x-frame-options|referrer-policy|permissions-policy|cross-origin-opener|x-content-type'`
      → five headers.
- [ ] `curl -sI https://cass.towardpcc.com/ | grep -i strict-transport-security`
      → present, and served by Cloudflare rather than by the app.
- [ ] `docker exec <app> su-exec app php artisan cass:health` → every row OK,
      exit 0.
- [ ] `docker exec <app> su-exec app php artisan migrate:status` → nothing
      Pending.
- [ ] `docker exec <app> su-exec app php artisan about` → `APP_ENV=production`,
      `APP_DEBUG=false`, config **cached**, routes **cached**, events cached.
- [ ] `docker exec <app> supervisorctl status` → four programs RUNNING.
- [ ] Run `docker/backup.sh` by hand once. A `.sql.gz` appears and the printed
      size is plausible for the live database — not the 20-byte gzip of an
      empty stream; then `gunzip -c` it and confirm the last line is
      `-- Dump completed`.
- [ ] `ls -ld /srv/backups/cass` → `drwx------`, and `ls -l` shows every dump
      `-rw-------`. The script sets both every run; a dump readable by any
      local account is a copy of every author's address and every password
      hash.
- [ ] Restore that dump into a scratch database and count rows against
      production (the procedure is in the runbook under **Backups**). **A
      backup nobody has restored is not a backup.**
- [ ] `APP=$(docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)`,
      `DB=$(docker ps --filter label=com.docker.compose.service=mysql --format '{{.Names}}' | grep -i cass | head -1)`,
      then `docker stats --no-stream "$APP" "$DB"` → the app container's steady
      state well under 768 MiB and MySQL's under 512 MiB. Record both in the
      runbook. (The names are Coolify's, not `cass-app`/`cass-mysql`; neither
      compose file sets `container_name`.)
- [ ] Time the public conference page and the submit page five times each
      (the loop is in the runbook's "Every release" step 5) → a 0.300 s median.
- [ ] Time `RankedSubmissions::query()` once against a conference with a few
      hundred abstracts (the `tinker` snippet is in the runbook) → far under
      one second.
- [ ] `docker exec <app> su-exec app php artisan queue:failed` → empty.

## 4. The manual walk-through

On the deployed site, in a browser, with devtools open on the console
throughout. **Every step in order, in one session** — several of them only
break when they follow each other.

- [ ] Landing, About, Contact, Privacy, Terms all render, all styled, no
      console errors, and no untranslated key strings (`public.nav.about` and
      the like) anywhere on the page.
- [ ] Register an organization. The verification email arrives; the link
      verifies; the organizer panel opens with the pending banner.
- [ ] Approve it as the platform admin. The approval email arrives, branded,
      with a working link.
- [ ] Set the organization's logo and colours. The contrast warning fires for a
      pale colour and refuses it.
- [ ] Create a conference, add a track, a custom field and a review question,
      and publish it. The short link and the QR appear.
- [ ] Download the QR SVG, the QR PNG and both poster sizes. Open the A3 PDF
      and check the fonts are the real IBM Plex, not a fallback.
- [ ] Open the public conference page **from a phone, by scanning the poster's
      QR code**. The countdown shows and counts down.
- [ ] Submit an abstract as an author, with a real PDF, from the phone. The
      confirmation email arrives; `/s/{token}` opens; edit and re-save works.
- [ ] Withdraw it, then submit another. The status page shows the right thing
      each time.
- [ ] Invite a reviewer to a second address you control. The invitation email
      arrives; the accept link creates an account; `/review` opens.
- [ ] Submit a review. The abstract moves to under review; the organizer's
      ranking page shows a score.
- [ ] Close submissions, start reviewing, apply a decision, send the decision
      emails. The letter arrives; `/s/{token}` shows it; the **old** status
      link from the confirmation email is now dead — which is expected, and is
      owner decision 5 above.
- [ ] Export the ranking as CSV and as XLSX. Open the XLSX in a real
      spreadsheet and confirm a title beginning `=` is inert.
- [ ] Mark the conference decided, then archive it. The public page 404s.
- [ ] Claim a custom domain on a real second-level domain you control. Publish
      the TXT record, verify, receive the admin email, add the host in Coolify,
      wait for the certificate, and open `https://<that domain>/<slug>`. Check
      that `/about` and `/register` 404 there.
- [ ] As the platform admin, open an organization, a conference, a submission,
      a review and the email log. Every screen is read-only; the submission's
      file link downloads.
- [ ] Purge a **test** conference. The counts in the modal match what was
      there; nothing else is affected.
- [ ] Sign out of all three panels and confirm each login page is styled and
      each rejects a wrong password five times and then throttles.

## 5. Data

- [ ] Import the two legacy conferences (runbook: **Importing the legacy
      conferences**). Dry run first, read the report, then the real run, then
      read the report again.
- [ ] Send a password reset to each imported user.
- [ ] Reset the demo tenant (`cass:demo-reset`) so nothing demo-shaped is
      visible on the live site, **or** decide deliberately to keep it and say
      so here.
- [ ] Confirm no `@import.invalid` address ever receives anything: the imported
      conferences are archived, so nothing can be sent.

## 6. Sign-off

- [ ] Every box above is ticked or has a written reason beside it.
- [ ] The eight owner decisions in section 1 are answered in
      `docs/superpowers/plans/backlog.md`, not only in somebody's head.
- [ ] `CASS_CSP_REPORT_ONLY=false` and the site has been walked once with it
      that way.
```

- [ ] **Step 2: The runbook's custom-domain section**

`docs/runbooks/deploy-production.md:639-641` is two lines today (*"Not yet supported: trusted hosts are pinned to APP_URL until custom domains ship in Plan 6."*). Replace the whole section:

````markdown
## Custom domain for an organization

An organizer claims a domain on their profile page, publishes two DNS records
and presses **Verify**. The application checks the TXT record, marks the domain
verified, adds it to the trusted-host list within a minute, and emails every
platform admin. **The certificate is a manual step, and this is it.**

### What the organizer does

Two records in the DNS panel for their own domain:

| Type | Name | Value |
|---|---|---|
| TXT | `_cass-verify.abstracts.example.org` | the 64-character token on their profile page |
| CNAME | `abstracts.example.org` | `cass.towardpcc.com` |

Only the **TXT** record is checked. The CNAME is what makes traffic arrive, and
it is deliberately not verified: this platform sits behind Cloudflare with the
record proxied, and a proxied CNAME is flattened into A records by Cloudflare's
authoritative servers — so a public resolver asked for a CNAME gets nothing.
Requiring it would refuse exactly the setup this runbook recommends.

The token is stored and displayed in plaintext, on purpose. It is published in
public DNS by design, it authorises nothing, and the organizer has to be able
to read it again tomorrow when their DNS panel has eaten the record. Do not
"fix" it into a hash: there is no version of this feature in which the token is
hashed and the screen works.

### What you do when the email arrives

Add the host to the Coolify resource so Traefik requests a certificate.

**In the UI:** Coolify → the CASS resource → Domains → add
`https://abstracts.example.org:8080` **beside** the existing
`https://cass.towardpcc.com:8080`, comma-separated. Redeploy is not needed;
Traefik picks up the label change.

**Through the API**, if you prefer:

```bash
# The resource UUID is in the Coolify URL for the application.
curl -X PATCH "https://<coolify-host>/api/v1/applications/<uuid>" \
  -H "Authorization: Bearer $COOLIFY_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"domains":"https://cass.towardpcc.com:8080,https://abstracts.example.org:8080"}'
```

`domains` is the **whole** list, not an addition — a PATCH with one domain
removes the others and takes the platform offline. Read the current value first:

```bash
curl -s "https://<coolify-host>/api/v1/applications/<uuid>" \
  -H "Authorization: Bearer $COOLIFY_TOKEN" | jq -r '.domains'
```

The application never makes this call. It would need a Coolify token in its
environment, and that token can rewrite the whole deployment — a far larger
blast radius than the problem. Spec section 15 records the same reasoning and
leaves automation to v2.

### Checking it worked

```bash
# Traefik has a certificate and the host resolves to a conference page.
curl -sI https://abstracts.example.org/ | head -3
curl -s https://abstracts.example.org/<conference-slug> | grep -o '<title>[^<]*'
# And the platform's own pages are NOT served there.
curl -s -o /dev/null -w '%{http_code}\n' https://abstracts.example.org/register   # 404
curl -s -o /dev/null -w '%{http_code}\n' https://abstracts.example.org/admin      # 404
```

A verified domain serves exactly four things: `/` (a redirect to the
organization's single public conference, or a 404 when there is not exactly
one), `/{conference-slug}`, `/{conference-slug}/submit`, and `/livewire-{hash}/*`
plus `/up`. **Livewire's endpoint prefix is derived from `APP_KEY`** —
`'/livewire-'.substr(hash('sha256', config('app.key').'livewire-endpoint'), 0, 8)`,
`EndpointResolver::prefix()` — so it is **not** `/livewire`, and it **moves if
`APP_KEY` is rotated**; `php artisan route:list --path=livewire` prints the
current one. `/s/{token}`, `/files/{ulid}` and `/q/{code}` stay on
`cass.towardpcc.com` — a status token is a bearer credential, a file URL is a
signature bound to its host, and a short code is printed on posters that
outlive a domain registration. **So an author who submits on a custom domain
ends up on the platform host**, because that is where their status page lives:
`ResolveCustomDomain` pins `URL::forceRootUrl()` to `APP_URL` for the whole
request, so every `route()` on a custom-domain page — the footer's Privacy and
Terms links included — is minted on the platform host rather than on the
organizer's.

**One local consequence.** `ResolveCustomDomain` 404s any host that is neither
`APP_URL`'s host nor a verified domain, and `php artisan serve` answers on
`127.0.0.1:8000`. Open `http://localhost:8000` locally, not `http://127.0.0.1:8000`.

### When an organizer removes a domain

The application clears all three columns and the name becomes claimable again
immediately. **Nothing tells Coolify**: remove the host from the resource when
you next touch it. Leaving it is harmless — a host with no verified
organization behind it gets a 404 from the application — but it leaves Traefik
renewing a certificate for a name nobody uses.
````

- [ ] **Step 3: The runbook's remaining sections**

**Backups** (`:647-650`) — replaced:

````markdown
## Backups

Spec section 8: nightly `mysqldump`, 14-day rotation, then the existing off-host
sync to the NAS.

The script is committed at `docker/backup.sh` and runs on the **host**, not in a
container: it needs `docker exec` into the mysql container and a host path,
deliberately, so a compromised app container can neither read nor delete the
backups.

### Installing it

```bash
sudo docker cp <app-container>:/usr/local/bin/cass-backup.sh /usr/local/bin/cass-backup
sudo chmod 755 /usr/local/bin/cass-backup
sudo mkdir -p /srv/backups/cass
sudo /usr/local/bin/cass-backup            # once, by hand, and read the output
```

Then the cron line (`sudo crontab -e`):

```cron
# CASS database backup, nightly at 03:17 local. Not on the hour: every other
# cron on this host is, and MySQL does not need the company.
17 3 * * * /usr/local/bin/cass-backup >> /var/log/cass-backup.log 2>&1
```

It writes `/srv/backups/cass/cass-<date>-<time>.sql.gz`, keeps 14 days, refuses
a dump under 4 KB rather than rotating a good backup away for a broken one, and
writes through a `.partial` name so an interrupted run never leaves a truncated
file that looks whole.

### Verifying one — monthly, and not optional

**A backup nobody has restored is not a backup.** There is no
`cass:backup-verify` command and there cannot usefully be one: a command inside
the app container cannot see `/srv/backups`, which is the point of putting them
there.

```bash
NEWEST=$(ls -1t /srv/backups/cass/cass-*.sql.gz | head -1)
C=$(sudo docker ps --filter label=com.docker.compose.service=mysql --format '{{.Names}}' | grep -i cass | head -1)

sudo docker exec "$C" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE IF EXISTS cass_restore_check; CREATE DATABASE cass_restore_check;"'
gunzip -c "$NEWEST" | sudo docker exec -i "$C" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot cass_restore_check'

# Row counts, live against restored. They will differ by whatever happened
# since the dump; they must not differ by an order of magnitude or be zero.
for T in organizations conferences submissions reviews email_logs; do
  sudo docker exec "$C" sh -c "MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysql -uroot -N -e \
    'SELECT \"$T\", (SELECT COUNT(*) FROM cass.$T), (SELECT COUNT(*) FROM cass_restore_check.$T);'"
done

sudo docker exec "$C" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE cass_restore_check;"'
```

### What is NOT backed up by this

`storage/app` — the `cass-storage` volume, which holds every uploaded abstract
PDF and every organization logo. The database backup restores rows that point
at objects that are gone. The NAS sync must include it, or restore is half a
restore.

**Resolve the volume by inspecting the running container, never by name.**
Compose prefixes named volumes with the project name and Coolify adds its own
identifier, so a bare `-v cass-storage:/data` silently creates a new, empty
volume and archives nothing — producing a forty-five-byte `.tar.gz` that looks
like a backup of every uploaded abstract:

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
VOL=$(sudo docker inspect "$C" --format '{{range .Mounts}}{{if eq .Destination "/var/www/html/storage/app"}}{{.Name}}{{end}}{{end}}')
test -n "$VOL" || { echo "no storage volume found on $C"; exit 1; }

sudo docker run --rm -v "$VOL":/data:ro -v /srv/backups/cass:/out alpine \
  tar czf "/out/cass-storage-$(date +%F).tar.gz" -C /data .

# An archive of an empty (i.e. wrong) volume is a few dozen bytes.
test "$(stat -c %s "/srv/backups/cass/cass-storage-$(date +%F).tar.gz")" -gt 10240
find /srv/backups/cass -name 'cass-storage-*.tar.gz' -mtime +56 -delete
```

Weekly is enough — the objects are content-addressed and immutable, so a
week-old archive plus the newest database dump loses only files uploaded in
between. Put the block above in `/usr/local/bin/cass-storage-backup` and add
the cron line beside the nightly one:

```cron
# CASS uploaded files, Sundays at 03:47 - after the nightly database dump.
47 3 * * 0 /usr/local/bin/cass-storage-backup >> /var/log/cass-backup.log 2>&1
```

The directory is `0700` and each dump is `0600`; the script sets both every
run. Check with `ls -ld /srv/backups/cass` and `ls -l /srv/backups/cass | head`.
````

**Secret rotation** (`:652-654`) — one paragraph appended, which Task 8 made true:

> **Drain the queue before rotating `APP_KEY`.** Queued mail payloads are
> encrypted with it (`TemplatedMail implements ShouldBeEncrypted`), so a job
> written under the old key cannot be run under the new one.
> `php artisan queue:size` should read 0 and `queue:failed` should be empty or
> already retried before the redeploy. Rotating it also invalidates every
> session, every encrypted two-factor secret, and every unverified custom
> domain's TXT record if the token is ever derived rather than stored.

**Trusted proxies** (`:656-658`) — one paragraph appended:

> Trusted **hosts** are no longer pinned to `APP_URL` alone. The closure in
> `bootstrap/app.php` returns the `APP_URL` host plus every verified
> `organizations.custom_domain`, cached for `CASS_DOMAIN_CACHE_SECONDS` (60).
> A database failure inside that lookup returns an empty list and reports —
> so the platform's own host keeps working and custom domains answer 400,
> which is the correct direction to fail. An unknown `Host` still gets a 400
> before any route runs, which is why both healthchecks send an explicit one.

**One-time setup** (`:8-23`) — two items appended to the numbered list:

> 8. Install the backup cron (see **Backups**) and run it once by hand.
> 9. Work through `docs/launch-checklist.md` before announcing the address.

A new **## Content-Security-Policy** section after "Trusted proxies":

````markdown
## Content-Security-Policy

Every HTML response carries one, with a per-request nonce. `CASS_CSP_REPORT_ONLY=true`
sends `Content-Security-Policy-Report-Only` instead.

**Deploy with it true.** Walk `docs/launch-checklist.md` section 4 with the
browser console open — a violation appears there as *"Refused to …"* — then set
it false and redeploy. There is no report endpoint on purpose: a public POST
that any browser on the internet can fill is a spam sink for a platform with
one operator.

Two directives look loose and are argued rather than assumed:

- `script-src` carries `'unsafe-eval'` because Alpine's expression evaluator is
  `new Function()`, Livewire bundles Alpine, and Filament's views are written
  in a dialect Livewire's CSP-safe build cannot parse. The nonce is what stops
  an *injected* script; `'unsafe-eval'` lets already-loaded trusted code
  compile strings the application itself wrote.
- `style-src-attr` carries `'unsafe-inline'` because a nonce never applies to a
  style *attribute*, and the organization's branding variables and the brand
  lock-up on all three panels are attributes.

**Three** Filament views are published verbatim into `resources/views/vendor/`
with the nonce added — `filament::assets`, `filament-panels::components.layout.base`
and `filament-panels::livewire.sidebar` — because Filament 5.8.1 has no nonce
support anywhere. `tests/Feature/Security/PublishedFilamentViewsTest.php` stores
the SHA-256 of each vendor original, so **a Filament upgrade turns the suite
red**, and the fix is to re-publish the three files, re-add the nonce
attributes, and update the three hashes in the same commit. Never update a hash
on its own.

**Rocket Loader must stay off on this Cloudflare zone**; it and a nonce-based
policy cannot both be true. It rewrites every proxied `<script>` and re-executes
it from its own loader without the nonce, and no CI job can see it because no CI
job goes through Cloudflare.
````

A new **## Health** section:

````markdown
## Health

`/up` answers "is PHP serving" and is what Docker and Coolify watch. It cannot
answer the four things that fail silently:

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec "$C" su-exec app php artisan cass:health
```

| Row | Red means |
|---|---|
| `migrations` | the image deployed and nobody ran `migrate --force`. Every page touching a new column is 500ing. |
| `queue` | `queue:work` is dead under supervisord. Nothing has been emailed since it died, and nothing else notices. |
| `scheduler` | `schedule:work` is dead. No reviewer reminders, no pruning. The check reads a heartbeat the scheduler writes every five minutes. |
| `private disk` | the `cass-storage` volume is full or unmounted. The next abstract upload fails. |
| `mail` | `MAIL_MAILER` is `log` or `array` in production, or `MAIL_HOST` is unset. Mail queues perfectly and delivers nothing. |

`--json` for a cron. It exits 1 if any row is red, so
`cass:health --json || mail -s 'CASS unhealthy' you@example.org` is a monitor.
````

A new **## Erasing one person's data** section, which Task 4's decision 3 owes:

````markdown
## Erasing one person's data

The hard purge deletes a tenant's rows and **deliberately does not touch
`activity_log`**: the record of who did what — including the purge — is the
last thing a purge should erase.

That is right for an audit trail and insufficient for an erasure request, so
this is the manual part. `activity_log.properties` carries invitee email
addresses, decision notes and member roles, and `causer_id` names the actor.

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)

# 1. What is there. Read it before deleting any of it.
sudo docker exec "$C" su-exec app php artisan tinker --execute='
$email = "person@example.org";
$user = App\Models\User::query()->where("email", $email)->first();
printf("user: %s\n", $user?->id ?? "none");
printf("as causer: %d\n", Spatie\Activitylog\Models\Activity::query()->where("causer_id", $user?->id)->count());
printf("in properties: %d\n", Spatie\Activitylog\Models\Activity::query()->where("properties", "like", "%".$email."%")->count());
printf("as author: %d\n", App\Models\SubmissionAuthor::query()->where("email", $email)->count());
printf("in email_logs: %d\n", App\Models\EmailLog::query()->where("to_email", $email)->count());'

# 2. Then decide, per row, what goes and what is anonymised. There is no
#    command for this on purpose: an erasure that also deletes the evidence of
#    a decision an author is disputing is not a service to anybody.
```

`email_logs` prunes itself after `CASS_EMAIL_LOG_RETENTION_DAYS` (365), so that
half resolves on its own within a year.
````

- [ ] **Step 4: Check every command in both documents**

```bash
cd /c/Users/ahmed/Documents/CASS && \
grep -c "^## " docs/runbooks/deploy-production.md && \
grep -n "^## " docs/runbooks/deploy-production.md && \
grep -c "^- \[ \]" docs/launch-checklist.md && \
php artisan list 2>/dev/null | grep -E "cass:" && \
sh -n docker/backup.sh && echo "backup.sh parses"
```

Expected: the runbook's heading list now including **Custom domain for an organization**, **Content-Security-Policy**, **Health**, **Erasing one person's data**, **Importing the legacy conferences** (Task 10) and **Demo data** (the demo branch); the checklist with roughly seventy boxes; four `cass:` commands; and the script parsing.

**Every `php artisan` invocation quoted in either document must be a command that exists.** The `grep -E "cass:"` above is the check for this plan's four; for the rest:

```bash
cd /c/Users/ahmed/Documents/CASS && \
grep -ohE "artisan [a-z:|-]+" docs/runbooks/deploy-production.md docs/launch-checklist.md | sort -u | sed 's/artisan //' | while read -r CMD; do
  php artisan list --raw 2>/dev/null | grep -q "^${CMD} " && printf 'ok      %s\n' "$CMD" || printf 'MISSING %s\n' "$CMD"
done
```

Expected: no `MISSING`. A `--execute` snippet will show up here as its first word (`tinker`), which is correct.

- [ ] **Step 5: `README.md` and commit**

One line in `README.md`, beside the existing pointers: *"Before the site is announced, work through `docs/launch-checklist.md`."*

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint --test > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?" && \
php artisan test > /tmp/all-task-13.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "docs: the launch checklist, and the runbook sections twelve tasks left owing

Every checklist item is a command with an expected output or a question with a
decidable answer, because "review security" is not an item. The eight owner
questions Plans 3-5 recorded are on it as questions, each with the one-line
change either way, so that launching does not silently ship whichever answer a
plan author picked.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`. This task writes no PHP, so Larastan and Pint can only be red if an earlier task left something behind — which is exactly why they run here too, in the same shape as the other thirteen commit steps. The suite is unchanged by this task; running it is the guard against a stray edit.

---

### Task 14: MySQL, the backlog, the final gate and the pull request

**Files:**
- Modify: `docs/superpowers/plans/backlog.md`
- No application code changes.

- [ ] **Step 1: Run the whole suite against MySQL, not only SQLite**

Seven things in this plan behave differently on MySQL, and they are why this run is the gate rather than a formality:

1. **`organizations.custom_domain` is a real UNIQUE index only here.** `ClaimCustomDomain`'s pre-check is what turns a duplicate into a sentence, and `tests/Unit/VerifyCustomDomainTest.php`'s "refuses a domain another organization already claimed" is the case that would otherwise surface as a `QueryException` with a MySQL error string in an organizer's face.
2. **The purge's deletion order is only *enforced* here.** Eleven `RESTRICT` foreign keys are real constraints on InnoDB; SQLite enforces them too (`config/database.php:42`), but MySQL's `ON DELETE RESTRICT` and the order of the statements inside one transaction are what production actually runs. `PurgeConferenceTest` and `tests/Feature/Console/DemoResetTest.php` are the assertions.
3. **`legacy_imports`' `unique(legacy_table, legacy_id)` is what makes the import idempotent**, and the "a second run creates nothing" case proves it here rather than proving the control flow.
4. **`jobs.payload` is a `longtext` and the encryption test reads it back.** On SQLite the queue table is the same shape but the driver is not, and `tests/Feature/Security/QueuedMailPayloadTest.php` is asserting what production writes.
5. **`email_logs` pruning is a mass delete**, and `MassPrunable` issues one statement per chunk; a `where created_at <` against a MySQL `timestamp` column is where a timezone mistake would show.
6. **`Storage::disk('local')` is faked in every purge and import test**, so the *disk* half is driver-independent — but the **order** is not: the objects are deleted after the transaction commits, and a MySQL transaction that rolls back where SQLite's did not would leave the files. That is why the whole suite runs here and not just the new files.
7. **The CSP tests read headers off real responses**, which is driver-independent — but `tests/Feature/Admin/` builds cross-tenant fixtures through `withoutTenant()` and Filament's tenancy observer, and a `HasOneThrough` mistake in the admin `SubmissionResource` would surface as a constraint error only here.

```bash
cd /c/Users/ahmed/Documents/CASS && docker compose -f docker-compose.dev.yml up -d && sleep 15 && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php artisan test > /tmp/mysql.log 2>&1; echo "mysql rc=$?"; tail -4 /tmp/mysql.log
```

Expected: `mysql rc=0` and the same count as the SQLite run.

- [ ] **Step 2: Prove the three claims that were reasoned rather than executed**

Write `/tmp/plan6checks.php`:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Step 1's `php artisan test` uses RefreshDatabase, so the schema survives the
// run but NO ROWS DO. Build exactly what this needs, then remove it again.

// --- 1. The unique index really refuses a second claim -------------------
$first = App\Models\Organization::factory()->approved()->create();
$second = App\Models\Organization::factory()->approved()->create();

$first->forceFill(['custom_domain' => 'dup.example.org', 'custom_domain_token' => str_repeat('a', 64)])->save();

try {
    $second->forceFill(['custom_domain' => 'dup.example.org', 'custom_domain_token' => str_repeat('b', 64)])->save();
    echo "unique index: NOT ENFORCED\n";
} catch (Throwable $exception) {
    echo 'unique index: enforced ('.$exception::class.")\n";
}

// --- 2. The RESTRICT keys really refuse a bare forceDelete ---------------
$conference = App\Models\Conference::factory()->for($first)->create();
App\Models\Submission::factory()->for($conference)->submitted()->create();

try {
    $conference->forceDelete();
    echo "restrict: NOT ENFORCED - a stray forceDelete would wipe a tenant\n";
} catch (Throwable $exception) {
    echo 'restrict: enforced ('.$exception::class.")\n";
}

// ...and that the purge gets past them.
$admin = App\Models\User::factory()->platformAdmin()->create();
$counts = app(App\Actions\Conferences\PurgeConference::class)->handle($conference->fresh(), $admin);
printf("purge: %d rows, conference gone: %s\n",
    array_sum(array_filter($counts, 'is_int')),
    App\Models\Conference::withTrashed()->whereKey($conference->getKey())->exists() ? 'NO' : 'yes',
);

// --- 3. The mapping table's unique index is the idempotency --------------
App\Models\LegacyImport::record('conferences', 99, $first);
App\Models\LegacyImport::record('conferences', 99, $second);
printf("legacy_imports rows for legacy id 99: %d (must be 1)\n",
    App\Models\LegacyImport::query()->where('legacy_id', 99)->count(),
);

// Leave the database as this script found it.
App\Models\LegacyImport::query()->delete();
app(App\Actions\Organizations\PurgeOrganization::class)->handle($first->fresh(), $admin);
app(App\Actions\Organizations\PurgeOrganization::class)->handle($second->fresh(), $admin);
$admin->forceDelete();
```

```bash
cd /c/Users/ahmed/Documents/CASS && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php /tmp/plan6checks.php
```

Expected exactly:

```
unique index: enforced (Illuminate\Database\QueryException)
restrict: enforced (Illuminate\Database\QueryException)
purge: <a number above 3> rows, conference gone: yes
legacy_imports rows for legacy id 99: 1 (must be 1)
```

(`php -` does not work on this machine, which is why this is a file.)

- [ ] **Step 3: Confirm the routes, the commands and the schedule this plan added**

```bash
cd /c/Users/ahmed/Documents/CASS && \
php artisan route:list --except-vendor | grep -E "custom-domain|admin/(submissions|reviews)|landing" && \
php artisan list 2>/dev/null | grep -E "cass:" && \
php artisan schedule:list && \
ls .github/dependabot.yml docker/backup.sh docs/launch-checklist.md \
   resources/views/vendor/filament/assets.blade.php \
   resources/views/vendor/filament-panels/components/layout/base.blade.php \
   resources/views/vendor/filament-panels/livewire/sidebar.blade.php && \
grep -c 'package-ecosystem' .github/dependabot.yml && \
grep -q 'package-ecosystem: docker-compose' .github/dependabot.yml && echo 'compose ecosystem present' && \
for V in $(grep -ohE 'CASS_[A-Z_]+' config/cass.php | sort -u); do \
  grep -q "^$V=" .env.example || echo "MISSING from .env.example: $V"; \
done; \
grep -q 'CASS_CSP_REPORT_ONLY' docker-compose.production.yml || echo 'MISSING from docker-compose.production.yml: CASS_CSP_REPORT_ONLY'
```

Expected:

- **three** `custom-domain.*` routes with no host constraint — `custom-domain.home`, `custom-domain.conference.show`, `custom-domain.conference.submit` — and `landing` **with** a host constraint (Task 3 Step 4's `->domain()` — without it the custom-domain `/` never matches);
- **two** admin submission routes and **two** admin review routes, and no third of either;
- **four** `cass:` commands — `cass:health`, `cass:import-legacy`, `cass:rescore`, `cass:reviewer-reminders` — plus the demo branch's two if it has merged;
- **four** scheduled entries: `queue:prune-failed`, `model:prune`, `cass:reviewer-reminders` and the heartbeat closure. **`cass:import-legacy` and `cass:health` must NOT be in that list** — one runs once and the other is for a person at a terminal;
- all six files present — including **all three** published Filament views;
- `5` ecosystems in `.github/dependabot.yml` and `compose ecosystem present`, or the `mysql:8.4` pin is a pin nothing maintains;
- **no output** from the `.env.example` loop and no `MISSING from docker-compose.production.yml` line. A `CASS_*` variable the config reads but `.env.example` does not list makes Task 14 Step 7's spec-section-9 claim false, and `CASS_CSP_REPORT_ONLY` that is not in the compose file cannot be set on the live host at all.

Then the CI smoke job, which Tasks 7 and 11 both edited:

```bash
cd /c/Users/ahmed/Documents/CASS && \
grep -c "sha256:" Dockerfile docker-compose.production.yml docker-compose.dev.yml && \
grep -c "uses:" .github/workflows/ci.yml && \
grep -cE "uses: [a-z-]+/[a-z-]+@[0-9a-f]{40}" .github/workflows/ci.yml
```

Expected: 3, 1 and 2 digest pins; and the last two numbers **equal** — every `uses:` pinned by SHA. If they differ, an action was missed and Task 11 Step 3's loop is where to find it.

- [ ] **Step 4: Update `docs/superpowers/plans/backlog.md`**

**Delete these entries; each one is done, and the task that did it is named here so a reviewer can check rather than trust.**

Under `## Security and platform (Plan 6, launch hardening)`:
- the Content-Security-Policy line — Task 7, **replaced** by the entry below rather than deleted outright;
- "Pin Docker base images by digest (`node`, `composer`, `php:8.4-fpm-alpine`, `mysql:8.4`) and add Dependabot for the docker ecosystem" — Task 11, **only if** Task 11 Step 3's verification showed GitHub accepting the `docker-compose` entry. If it did not, keep the line rewritten as: *"Docker base images are pinned by digest and Dependabot watches the Dockerfile. The two compose digests (`mysql:8.4`, `axllent/mailpit`) are **not** watched — GitHub rejected the `docker-compose` ecosystem for this repository — so they are bumped by hand at each release."*;
- "Trusted hosts are pinned to `APP_URL`…" — Task 3;
- "Queue the Filament password-reset notification" — **already true before this plan started.** `vendor/filament/filament/src/Auth/Notifications/ResetPassword.php:9` is `implements ShouldQueue`, wired at `RequestPasswordReset.php:84-85`. The item described Laravel's base `VerifyEmail`, which `QueuedVerifyEmail` had already replaced. Delete it with that sentence in the commit message;
- "GitHub Actions: move to action versions that run on Node 24" — Task 11, **if** `v5` resolved for all three `actions/*`. If one did not, keep the line and name it.

Under `## Organizer features (Plan 2 onwards)`:
- "Platform-admin hard purge of a conference (spec 3)…" — Task 4;
- "Spec section 10 requires every string to live in language files…" — Task 12;
- "Admin `OrganizationResource` has the route-key mismatch described in fact 16…" — **stale before this plan started.** The resource sets no `$recordRouteKeyName` at all and carries a comment at `:27-34` explaining why it must not. Delete it, saying so.

Under `## Public site polish (Plan 6, launch polish)`:
- "Add `width`/`height` … serve the hero as WebP" — Task 12;
- "Replace hardcoded `/org/login` URLs" — Task 12;
- "Contact mailable: render the message as plain escaped text" — Task 8.

Under `## Submissions (deferred by Plan 3)`:
- "**`organization_approved` / `organization_rejected` are platform-wide**" — Task 8;
- "**`email_logs` is never pruned**" — Task 8;
- "**The platform admin cannot read a submission (spec section 4).**" — Task 5;
- "**The platform-admin hard purge (Plan 6) now has files to delete.**" — Task 4;
- "**Content-Security-Policy (Plan 6) has three new things to allow**" — Task 7, and all three are in the policy.

Under `## Reviewing (deferred by Plan 4)`:
- "**A platform-admin view of reviews, reviewers and assignments.**" — Task 6;
- "**A platform-admin view of organization members.**" — Task 6;
- "**The hard purge (Plan 6) has four more tables to delete.**" — Task 4;
- "**Nothing in any panel prints a review's *content*.**" — Task 6;
- the two "**`email_logs` still grows for ever**" repetitions — Task 8.

Under `## Decisions (deferred by Plan 5)`:
- "**The hard purge (Plan 6) has one more table to delete.**" — Task 4;
- "**A platform-admin view of decisions.**" — Task 5;
- the third "**`email_logs` still grows for ever**" — Task 8.

**Rewrite these five, because Plan 6 closed part of each and left a named remainder:**

```markdown
- Content-Security-Policy: shipped in Plan 6 with a per-request nonce on every HTML response, and **two directives are still loose on purpose**. `script-src` carries `'unsafe-eval'` because Alpine's evaluator is `new Function()`, Livewire bundles Alpine, and Filament's views are written in a dialect Livewire's CSP-safe build (`livewire.csp.min.js`, `config('livewire.csp_safe')`) cannot parse — dropping it means Filament shipping views the restricted parser can evaluate, which is upstream work. `style-src-attr` carries `'unsafe-inline'` because a nonce never reaches a style *attribute*, and about forty-five of them are inline — including the organization branding variables and the brand lock-up on all three panels; that one gets stricter for free the day the organizer theme lands. Also: **three** Filament views are published verbatim into `resources/views/vendor/` with the nonce added — `filament::assets`, `filament-panels::components.layout.base` and `filament-panels::livewire.sidebar` (whose one bare inline `<script>` renders on every authenticated panel page) — because Filament 5.8.1 has no nonce support anywhere, and `tests/Feature/Security/PublishedFilamentViewsTest.php` pins the vendor originals' digests — so **a Filament upgrade is a red suite and a re-publish**, which is the cost of the strategy and is meant to be visible.
- **Measure real memory use under load and adjust the compose limits.** Plan 6 wrote the measurement into the runbook and changed no number, because a number changed without a measurement is the same guess with more confidence. The arithmetic to keep in mind: `pm.max_children = 4` against `memory_limit=256M` is a 1 GiB worst case inside a 768 MiB container, so the container's limit is what would kill a runaway and it would do it by OOM rather than by a PHP fatal. Take the two readings at launch (`docs/launch-checklist.md` section 3) and write them here.
- **Restrict port 22 on the OCI security list to known IPs.** Not code, and not something this repository can do. It is item 3 of `docs/launch-checklist.md` section 2 and stays here until that box is ticked.
- **`User::roleIn()` and `canAccessTenant()` query on every call.** Plan 6 deliberately did **not** memoise them. Nothing it added calls either per row — every admin policy answers on `is_platform_admin`, which is a column on the authenticated user, and the admin resources are not tenant-scoped — and a per-instance memo would be stale for the rest of a request in which membership changed, which `RemoveMember` and `ChangeMemberRole` can both cause. The trigger is unchanged: use the loaded relation once a table shows the N+1 in profiling.
- **Conference `og:image`.** Half done in Plan 6: the conference layout emits `og:image` from the organization's logo, plus `og:title`, `og:description`, `og:url` and a canonical link that follows a verified custom domain. The **poster** half is not polish and was not built: `conference-assets.poster` is an authenticated, 10/min-throttled dompdf render that a social crawler cannot reach, so serving it would mean a second public route rendering a PDF to an image on demand — a feature, with a cache and a rate limit of its own.
```

**And rewrite the demo branch's own entry**, which named four things Plan 6 owed the purge:

```markdown
- **`organizations.is_demo` — the hard purge is now exposed to the platform admin.** Plan 6 Task 4 did all four things this entry asked for: a purge action on both admin resources behind a typed-slug confirmation (not a `ForceDeleteAction` — Filament's own calls `$record->forceDelete()` and hits four `RESTRICT` keys); a count-per-table preview rendered *before* the delete from `preview()`, which runs the same query list with `count()` instead of `delete()`; a conference-scoped `App\Actions\Conferences\PurgeConference`, which now **owns the order** and which `PurgeOrganization` calls per conference rather than repeating (the demo command's own tests pass unedited, which is the proof that refactor changed nothing); and the `activity_log` decision. **Decided: the purge does not touch `activity_log`.** The audit trail of who did what — including the purge, which writes its own entry there — is the last thing a purge should erase, and a GDPR erasure request is a *person*-shaped problem rather than a tenant-shaped one: deleting every row whose subject was purged still leaves every row where that person was the causer elsewhere, and a half-erasure that looks complete is worse than a documented manual step. The runbook's **Erasing one person's data** section is that step. The purge also does not gate on `is_demo`, as this entry required.
```

**Then append the new section.** Every item is either a deviation this plan made on purpose, a thing it found and did not fix, or a question it is handing to a person:

```markdown
## Launch (deferred by Plan 6)

- **Open question for the owner — eight of them, and they are all on the checklist.** `docs/launch-checklist.md` section 1 collects the questions Plans 3, 4 and 5 recorded: Turnstile failing open, whether a plain member may withdraw an abstract or mint a status link, whether a plain member may remove a reviewer, what the review deadline freezes, whether sending decision letters may rotate an author's status link, whether an organizer may unassign a reviewer who has already submitted (`backlog.md:62`'s explicit deviation from spec 5.5's "until the review is submitted"), whether only an owner or admin may send decision letters (`ConferencePolicy::sendDecisions()` narrows spec section 4's row), and whether a resend may overwrite the stored decision letter. Each has the one-line change either way beside it. **They are not answered here on purpose** — a plan author picking an answer is exactly the failure the checklist exists to prevent. Record the answers in this file when they are made.
- **Deviation from spec section 9: `organizations.custom_domain_token` is stored as plaintext.** Every other token in this application is a SHA-256 hash because it is a bearer credential arriving *from* a client. This one is a challenge nonce **published in public DNS by design**: it authorises nothing, it never appears in a request, and the organizer has to be shown it again tomorrow when their DNS panel has eaten the record — which a hash makes impossible without minting a new token and invalidating the record they already published. Argued in `ClaimCustomDomain`'s docblock and in the runbook, so that nobody later "fixes" it and breaks the screen.
- **Verification checks the TXT record and not the CNAME.** A proxied CNAME is flattened into A records by Cloudflare's authoritative servers, so requiring it would refuse exactly the setup the runbook recommends and pass only the unproxied one that leaks the origin IP. The consequence: a domain can verify and still not resolve, and the organizer learns that from Traefik rather than from us.
- **Nothing re-verifies a verified domain, ever.** If an organizer deletes the TXT record the domain keeps working, because by then Traefik holds a certificate and the host is in the trusted-host list. A nightly re-check that could take a live conference offline because a DNS provider had a bad minute is a worse failure than a stale record. Re-verification is the **Verify** button.
- **The Coolify domain step is manual, and the runbook has the exact API call.** Automating it means a Coolify API token in the application's environment, and that token can rewrite the whole deployment. Spec section 15 records the same reasoning and leaves it to v2.
- **Imported legacy authors carry `legacy-{id}-{n}@import.invalid` addresses.** `submission_authors.email` is NOT NULL and the legacy database stores no author email at all — one `contact_email` per abstract and a text blob for the byline. `.invalid` is RFC 2606's reserved TLD, so nothing can ever be delivered to one, and the imported conferences are archived so nothing tries. Every one is a line in the import's manual-review report.
- **Legacy passwords are not carried, although they would have worked.** All ten rows hold real `$2y$10$` bcrypt hashes. Spec 5.10 says otherwise and the reason is stronger than the instruction: `legacy-review.md:276` records that `users.reset_token` doubled as the manager-invitation token, so a password on that platform was never the only way in.
- **`cass:demo-seed` and `cass:demo-reset` may not be registered.** `bootstrap/app.php`'s `->withCommands([...])` names classes and does **not** scan `app/Console/Commands` (Plan 4 fact 2), so a command in that directory that is not in the array is not registered. Plan 6 Task 1 Step 1 checks and records the answer; if they are missing, it is one line in that array and belongs to whoever owns the demo commands.
- **The production image builds its CSS without `app/Livewire` in scope.** `resources/css/app.css` has two `@source` roots, `../views` and `../../app/Livewire`, and the Dockerfile's `assets` stage copies only `resources` and `public` (`Dockerfile:10-11`). So a Tailwind class used **only** inside a Livewire PHP file is silently absent in production and present locally. It belongs with the organizer-theme item, which is where the stage order is already discussed, and the fix is the same `COPY` reordering.
- **`public/images/icons/badge.svg` is referenced by nothing.** Found during Plan 6's language sweep and deliberately left: deleting an asset inside a commit full of translations is a `git rm` nobody notices in the diff.
- **`.env.example:56` sends from `towardpicu.com` while everything else is `towardpcc.com`.** `MAIL_FROM_ADDRESS="cass@towardpicu.com"` against `CASS_CONTACT_EMAIL=cass@towardpcc.com`. One of them is wrong, SPF and DKIM have to match whichever survives, and it is on the launch checklist as an owner decision rather than guessed here.
- **There is no CSP report endpoint.** A public POST accepting arbitrary JSON from any browser on the internet is a spam sink and a storage cost for a platform with one operator. `CASS_CSP_REPORT_ONLY=true` plus a person with devtools open is the rollout, and the checklist says so.
- **Livewire's temporary uploads still linger.** Raised by Plan 3: `FileUploadController::_finishUpload()` sweeps files older than 24 hours only when the *next* upload arrives, so a quiet period after a busy one leaves abandoned files in `storage/app/private/livewire-tmp` inside the `cass-storage` volume. Plan 6 did not add a scheduled sweep — but `cass:health`'s private-disk check now makes a filling volume visible before an author's upload fails, which is the symptom that mattered.
- **A failed *notification* still stays at `queued` in `email_logs`.** Plan 6 moved `organization_approved` and `organization_rejected` onto `SendTemplatedEmail`, whose `TemplatedMail::failed()` marks the row failed — so two of the five are fixed. `NewSubmissionNotice`, `MemberInvitation` and `QueuedVerifyEmail` are still `Illuminate\Notifications\Notification`, which has no `failed()` hook. Fixing the rest properly is a `JobFailed` listener that can correlate a failed job back to a log row.
- **The pagination views are still not in the Tailwind `@source` list**, because no public page paginates and this plan added none. The trigger is unchanged: the first paginated public page.
- **The language sweep still stops at the file boundary.** Plan 6 finished the Plans 1-2 public views, the countdown script and the poster template, so every Blade file a visitor can reach is translated. Still hardcoded English, and still outside every `$planNSources` list: every enum's `getLabel()`, `App\Support\Scoring\RankingRows::headers()` and Plan 3's `ExportSubmissionsCsv::HEADERS`, the columns of the four admin tables, and the `blockers()` sentences in `PublishConference` (the newer actions use `lang/` keys; that one predates the convention). Extract them together before any Arabic work. `Decision::getLabel()` is author-facing — it is `{{decision}}`'s value in every decision letter — so it moves with the bilingual-templates item of spec section 14 rather than with the panel strings.
- **Three Filament views are frozen against an upgrade** (see the rewritten CSP entry). The day Filament ships nonce support, all three published copies are deleted and the drift test goes with them.
- **Spec section 4's two platform-admin write cells have no screen.** "Manage organization members" and "Manage organization profile, branding, domain" are ticked for the platform admin in the spec table. Plan 6 added read-only relation managers and a read-only `OrganizationResource`; `OrganizationPolicy::update()` already answers true for a platform admin, so the missing half is an admin edit page plus role-change and remove-member actions, each with an activity entry and an organizer-forbidden test. Until then the support path is the console.
- **The nightly dump lives on the host (`/srv/backups/cass`), not on the data volume spec section 8 names.** Deliberate — see Task 11's decisions 3 and 4. The consequence to keep in view: the NAS sync must cover that host path *and* the weekly `cass-storage` tarball, or a restore is half a restore.
```

- [ ] **Step 5: The final gate**

```bash
cd /c/Users/ahmed/Documents/CASS && \
./vendor/bin/pint --test > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?" && \
php artisan test > /tmp/sqlite.log 2>&1; echo "sqlite rc=$?"; tail -3 /tmp/sqlite.log && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php artisan test > /tmp/mysql.log 2>&1; echo "mysql rc=$?"; tail -3 /tmp/mysql.log && \
npm run build > /tmp/build.log 2>&1; echo "build rc=$?" && \
./vendor/bin/pest --configuration=phpunit.browser.xml > /tmp/browser.log 2>&1; echo "browser rc=$?"; tail -3 /tmp/browser.log && \
docker buildx build --platform linux/amd64 -t cass:plan6-amd64 . > /tmp/image-amd64.log 2>&1; echo "image amd64 rc=$?"; tail -3 /tmp/image-amd64.log && \
docker buildx build --platform linux/arm64 -t cass:plan6-arm64 . > /tmp/image-arm64.log 2>&1; echo "image arm64 rc=$?"; tail -3 /tmp/image-arm64.log && \
if git status --short | grep -qE "Legacy/|legacy-review|legacy-uploads|assets/envato"; then echo "STOP: private material is staged"; exit 1; fi && \
echo "ok: nothing private staged"
```

Expected: every `rc=0`, and `ok: nothing private staged`.

**`npm run build` is not optional here**: Task 12 changed a public view and added a `<picture>`, and the browser suite serves the application in-process — a stale `public/build` makes every browser case a 500 that looks like a CSP failure. **Both `buildx` platforms** are the gate on Task 11's digests: the daemon on this machine is `arm64`, so a plain `docker build` would pass with an arm64 *platform* digest pinned by accident and the amd64 `smoke` job would be the first thing to notice.

- [ ] **Step 6: Commit the backlog and open the pull request**

Both suites gate this commit too, with `&&` rather than `;`, so the last commit of the plan cannot land on a red tree even if Step 5's output was skimmed.

```bash
cd /c/Users/ahmed/Documents/CASS && \
php artisan test > /tmp/sqlite.log 2>&1 && echo "sqlite rc=0" && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php artisan test > /tmp/mysql.log 2>&1 && echo "mysql rc=0" && \
git add -A && git commit -q -m "docs: record what plan 6 closed and what it deferred

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && \
git push -u origin plan-6-launch && \
gh pr create --fill --title "Plan 6: custom domains, the legacy import, the hard purge, the admin read-only panel, CSP and launch" --body "$(cat <<'BODY'
Implements spec 5.8 (custom domains), 5.10 (legacy import), the hard purge of
section 3, the platform-admin cells of section 4 that had policies and no
screens, **the whole of section 9**, the backups of section 8, the language-file
requirement of section 10 for everything Plans 1-2 hardcoded, the digest pinning
of section 11, and the "Before launch" line of section 12.

## Custom domains

- An organizer saves a domain on their profile, is shown one TXT record and one
  CNAME target, and presses **Verify**. DNS is an interface with one real
  implementation and one fake — the first container binding in this codebase,
  because `dns_get_record()` is a global function and nothing else can
  substitute it.
- A verified host serves exactly four things: the conference page, the submit
  page, Livewire's endpoints and `/up`. Every platform path 404s there, because
  a registration form on somebody else's domain is a phishing surface with our
  name on it.
- The conference is resolved **through** the organization, never by implicit
  binding: `(conference_id, slug)` is unique per organization and not globally,
  so an implicit `{conference:slug}` would serve another society's meeting.
- `TrustHosts` now returns the verified domains, cached, with a database
  failure widening nothing.

## The legacy import

- `cass:import-legacy {sql} {uploads-dir} --organization-slug= [--dry-run]`.
  Idempotent by one `legacy_imports` mapping table rather than a `legacy_id`
  column on eight production tables.
- The dump is UTF-8 bytes inside latin1 columns and is **never transcoded** — a
  conversion would create the corruption that is currently absent.
- Every place the legacy data does not answer v2's question is **reported, not
  guessed**: the 2023 affiliation column that holds a person's name, authors
  with no email address, incomplete reviews, synthetic review timestamps,
  missing files, orphan files, widened manager scope, dropped invitation
  tokens. The report is a Markdown file on the private disk and the command
  exits 1 while it is non-empty.
- Scores are recomputed with `ComputeSubmissionScore`, not carried: the legacy
  "Total Score" was an un-normalised sum across reviewers and questions.
- A dry run is the real run inside a rolled-back transaction, so it proves the
  constraints rather than a second code path.

## The hard purge

- `PurgeConference` owns the deletion order — eleven `RESTRICT` keys and four
  tables with no key at all — and `PurgeOrganization` calls it per conference
  rather than repeating it. The demo command's own tests pass unedited, which
  is the proof that refactor changed no behaviour.
- Both are behind a typed-slug confirmation on the admin resources, with a
  count-per-table preview shown **before** anything is deleted.
- `review_questions` go through the query builder because the model's
  `deleting` hook throws on a locked form even after every answer is gone.
- File objects are deleted **after** the transaction commits, never inside it.
- The audit log is deliberately not erased; the runbook carries the erasure
  query for a person-shaped request.

## The admin panel

- Read-only screens for submissions (authors, signed file links, decision
  history), reviews **with their answers** — the first screen anywhere in this
  application that prints what a reviewer wrote — reviewers, assignments,
  members and invitations. Plus global search over organizations and
  conferences.
- The refusal to write is in the resources, not the policies, because Laravel
  returns a non-null `before()` result without ever calling the ability.
- The organizer gets the same reviews section on their own submission view,
  gated on the conference being past `open` and on the review being submitted.

## Security (section 9, finished)

- Content-Security-Policy with a per-request nonce on every HTML response.
  `Vite::useCspNonce()` covers `@vite` and all of Livewire; Filament 5.8.1 has
  no nonce support, so three of its views are published verbatim with the nonce
  added and a drift test pins the originals' digests.
- `TemplatedMail` and `ContactMessage` are `ShouldBeEncrypted`: a rendered
  email carries the author's 64-character status token, and `failed_jobs` kept
  it in plaintext for 720 hours.
- Panel login is keyed on **email and the Cloudflare client address**. It was
  keyed on `request()->ip()` alone, which behind Cloudflare is one bucket for
  the internet.
- `email_logs` is `MassPrunable` with a 365-day retention; `model:prune`
  already ran nightly.
- `organization_approved` and `organization_rejected` move onto
  `SendTemplatedEmail`, so their copy lives once and a transport failure is
  visible in the log.

## Operations

- Every base image and every GitHub action pinned by digest, with Dependabot
  across five ecosystems (including docker-compose, without which the two
  compose digests would never move again), weekly and grouped.
- `docker/backup.sh`: nightly `mysqldump`, gzip, 14-day rotation, a refusal to
  rotate a good backup away for a truncated one, and a monthly restore drill in
  the runbook.
- `cass:health` answers the four things `/up` cannot — pending migrations, a
  dead queue worker, a dead scheduler, an unwritable private disk — and the
  scheduler check needed a heartbeat, because Laravel records no last run.

## Language and polish

- Every string Plans 1 and 2 hardcoded is in `lang/en`, checked by a sweep
  whose word threshold is **one**, because everything that mattered here was
  two words or fewer.
- The countdown gets whole phrases from `data-` attributes, since a module
  cannot call `__()` and English's one-or-many rule is not Arabic's.
- Both layouts gain `dir`, from the language file, so Arabic really is a copy.

## Verification

- `php artisan test` green on SQLite and on MySQL 8.4.
- Pint and Larastan level 6 clean.
- The browser suite green, including four new CSP cases that probe the policy
  by appending an un-nonced inline script and asserting it did not run.
- `docker buildx build` green for **both** linux/amd64 and linux/arm64 from the
  pinned digests - a platform digest passes on one and fails on the other.

## Before this is deployed

`docs/launch-checklist.md`. Its first section is **eight owner decisions** that
Plans 3, 4 and 5 recorded and that this plan deliberately did not answer.
BODY
)"
```

- [ ] **Step 7: Self-review against the spec**

Read each row and confirm it against the code, not against this plan's prose.

**Section 5.8 — Branding and custom domain** (the domain half; branding shipped in Plan 2)

| Sentence | Where |
|---|---|
| "organizer enters `abstracts.example.org`" | Task 2: the `claimCustomDomain` action's modal, validated by `DomainName::problem()` |
| "The app shows a TXT record `_cass-verify` with a token and a CNAME target `cass.towardpcc.com`" | Task 2: `custom-domain-records.blade.php`, from `DomainName::txtRecordName()` and `cnameTarget()` |
| "'Verify' performs the DNS lookup" | Task 1: `VerifyCustomDomain` over `DnsResolver` |
| "on success the domain is marked verified" | Task 1: the only writer of `custom_domain_verified_at` |
| "and the platform admin is notified to add the host to the Coolify resource so Traefik issues the certificate (documented runbook)" | Task 1: `CustomDomainVerified` to every `is_platform_admin`; Task 13: the runbook section with the exact `PATCH` |
| "Once live, `https://abstracts.example.org/{conference-slug}` serves the conference without the `/c/{org}` prefix" | Task 3: the route group, `RequireCustomDomain`, `Conference::publicUrl()` |

**Section 5.10 — Legacy import**

| Sentence | Where |
|---|---|
| "Console command `cass:import-legacy {sql} {uploads-dir}`" | Task 9: plus `--organization-slug=` and `--dry-run` |
| "creates one organization for the legacy owner" | Task 9: **adopts** one by slug and refuses if absent — the legacy schema has no organization to copy, and inventing a name, a type and a country is the owner's decision |
| "two conferences" | Task 9: slugs disambiguated by the deadline's year, because the two legacy names are byte-identical |
| "users (without passwords; they receive a reset link on first login)" | Task 9 |
| "reviewer memberships, review forms and questions" | Task 9: including `scale_min = 1`, without which every imported answer scores nothing |
| "submissions with authors parsed from the legacy text fields" | Task 10: `AuthorList`, five separators, journal markers stripped |
| "files copied into private storage" | Task 10: the same content-addressed path `StoreSubmissionFile` writes, but not through it |
| "and reviews with answers and computed scores" | Task 10: one header per `(submission, reviewer)` pair, then `ComputeSubmissionScore::forConference()` |
| "Idempotent by legacy id" | Task 9: `unique(legacy_table, legacy_id)` |
| section 15's "reports rows needing manual review instead of guessing" | Task 10: the Markdown report, and a non-zero exit while it is non-empty |

**Section 3 — "hard purge is a platform-admin action that cascades in application code"**: Task 4, and `OrganizationPolicy::forceDelete()` answers false for **everybody** so Filament's own action can never appear.

**Section 4 — the platform-admin column, with one gap named.** Read screens: submissions and files (Task 5), decisions (Task 5), reviews and their answers (Task 6), reviewers and assignments (Task 6), members and invitations (Task 6). Read-only in every case, with the refusal in the resource because `before()` short-circuits the ability. **Two cells in that column are WRITE cells and are not delivered**: "Manage organization members" and "Manage organization profile, branding, domain". The policies already answer (`OrganizationMemberPolicy::before()`, and this plan's `OrganizationPolicy::update()`), and there is still no screen behind either — a platform admin who has to change an owner does it in `tinker`. Deferred deliberately: every admin resource in this plan is index-plus-view, and a write screen needs its own actions, its own activity entries and its own cross-role negatives. Recorded under `## Launch (deferred by Plan 6)`.

**Section 8 — backups**: Task 11's `docker/backup.sh` and Task 13's runbook section, including the restore drill and the separate `cass-storage` archive that the database dump does not cover. The destination is a **host** path rather than the data volume the spec names — deviation 13 below.

**Section 9 — finished, line by line**: CSRF (Livewire and Filament, unchanged); the four rate limits (three met by Plans 1-4, **login fixed in Task 8**, with an inventory test); tokens hashed — **with one argued exception, the domain token, recorded in the backlog**; signed expiring file URLs (unchanged, and the admin screen mints them through the policy); policies on every model plus the first `OrganizationPolicy`; passwords (unchanged); CSP (Task 7); no secret in the repository and `.env.example` listing every variable (Tasks 1, 7, 8, 9 — **proved** by Step 3's `CASS_*` loop rather than asserted, because `CASS_LEGACY_REPORT_DIR` was the one Task 9 nearly left out); audit log for domain changes and purges (Tasks 1 and 4); error pages (unchanged).

**Section 10**: every string Plans 1-2 hardcoded now lives in `lang/en`, with `dir` in the language file — and **what is still hardcoded is listed in the backlog by name** rather than implied. The memory numbers are measured at launch and unchanged here.

**Section 11**: three base images and five compose/action image references pinned by **index** digest, verified by media type rather than by a build that would pass on this machine's architecture either way; Dependabot over docker, **docker-compose**, composer, npm and github-actions — the compose entry is what keeps the `mysql:8.4` pin from freezing for ever.

**Section 12 — "Before launch"**: `docs/launch-checklist.md`.

**Explicit deviations from the brief and from the spec, each argued where it is made**

1. **`custom_domain_token` is plaintext** (Task 1) — a challenge nonce published in public DNS is not a bearer credential, and a hash cannot be shown twice.
2. **The CNAME is not checked** (Task 1) — a proxied CNAME cannot be checked from outside, and requiring it would refuse the recommended setup.
3. **`script-src 'unsafe-eval'` and `style-src-attr 'unsafe-inline'`** (Task 7) — Alpine's evaluator and forty-five inline style attributes, both named, both with the condition that would remove them.
4. **Three Filament views are published and frozen** (Task 7) — `filament::assets`, `filament-panels::components.layout.base` and `filament-panels::livewire.sidebar`; the only way to nonce tags a framework emits without a hook, with a drift test as the alarm.
5. **The purge does not erase `activity_log`** (Task 4) — and the runbook carries the person-shaped erasure query instead.
6. **`email_logs` rows are deleted with their tenant** (Task 4, following the demo branch's existing choice) — all three keys are `nullOnDelete`, so keeping them means keeping them anonymised.
7. **Imported authors get `.invalid` addresses** (Task 10) — the column is NOT NULL and legacy stores none.
8. **Imported reviews are all `submitted`, including two incomplete ones** (Task 10) — a draft would drop them out of the historical record of what the committee was given.
9. **`GET /` on a custom domain redirects only when there is exactly one public conference** (Task 3) — picking "the newest" would silently send visitors to the wrong meeting.
10. **`User::roleIn()` is not memoised** (Task 8) — a correctness risk traded for an unmeasured gain, with the original trigger unchanged.
11. **The landing route gains a host constraint** (Task 3) — the only way two `/` routes can coexist in registration order.
12. **The import's dry run is a rolled-back real run** (Task 9) — a dry run that takes a different path proves nothing.
13. **Backups are written to a host path, not to the data volume** (Task 11) — spec section 8 says "to the data volume". `/srv/backups/cass` is outside every container on purpose: the script needs `docker exec` into the mysql container, and a compromised app container must be able to neither read nor delete the backups (which is also why there is no `cass:backup-verify` command). The `cass-storage` volume is archived separately and weekly (runbook, **What is NOT backed up by this**), because the database dump alone restores rows pointing at objects that are gone.

- [ ] **Step 8: Type and rule consistency check**

One last pass for the mismatches Larastan cannot see:

```bash
cd /c/Users/ahmed/Documents/CASS && \
echo "--- who writes the three custom-domain columns ---" && \
grep -rn "'custom_domain'\s*=>\|'custom_domain_token'\s*=>\|'custom_domain_verified_at'\s*=>" app/ --include=*.php && \
echo "--- who reads the verified-host list ---" && \
grep -rn "CustomDomains::" app/ bootstrap/ --include=*.php && \
echo "--- who deletes rows in a purge ---" && \
grep -rn "->delete()\|->forceDelete()" app/Actions/Conferences/PurgeConference.php app/Actions/Organizations/PurgeOrganization.php && \
echo "--- who sets a CSP header ---" && \
grep -rn "Content-Security-Policy" app/ docker/ --include=*.php --include=*.conf && \
echo "--- who calls the nonce ---" && \
grep -rn "cspNonce\|useCspNonce" app/ resources/ --include=*.php --include=*.blade.php && \
echo "--- who records a legacy mapping ---" && \
grep -rn "LegacyImport::" app/ --include=*.php
```

What each list must show:

- **the three columns**: `ClaimCustomDomain` (all three), `VerifyCustomDomain` (`custom_domain_verified_at` only), `ReleaseCustomDomain` (all three back to null). **Any other writer is a path around the activity log and around the cache invalidation**, and either one makes a verified domain that nothing serves. The tests' `forceFill` calls are outside `app/` and do not appear.
- **`CustomDomains::`**: `verifiedHosts()` from `bootstrap/app.php` and from `organizationFor()`; `organizationFor()` from `ResolveCustomDomain`; `forget()` from `VerifyCustomDomain`, `ReleaseCustomDomain` and `PurgeOrganization`. A fourth reader of `verifiedHosts()` means a second definition of "which hosts are ours".
- **the purge deletes**: every line inside `PurgeConference::queries()` plus the two explicit `forceDelete()`s for the soft-deleting tables, and in `PurgeOrganization` only the organization-level block. **A `->delete()` on `submissions` or `conferences` without `withTrashed()->forceDelete()` is the bug this check exists to find** — it soft-deletes rows that are already soft-deleted and leaves every `RESTRICT` above it pointless.
- **CSP headers**: exactly three — `ContentSecurityPolicy` (the app-wide one), `SubmissionFileController:61` (`default-src 'none'; sandbox`), `docker/nginx.conf:59` (`/storage/`). The middleware must **not** appear to overwrite either of the other two; its `applies()` guard is what keeps that true.
- **the nonce**: `ContentSecurityPolicy::handle()` calls `useCspNonce()` exactly **once**, and `cspNonce()` is read by the three published Filament views, by `submission-form.blade.php`'s one inline script and by `brand/logo.blade.php`'s one inline `<style>` element. A second `useCspNonce()` anywhere means a page whose header and whose tags carry different nonces — which is a panel with no JavaScript at all, and no test would catch it because both halves would be individually valid.
- **`LegacyImport::`**: `find()` and `record()` from `ImportLegacy` only, plus the purge's sweep. Anything else is a second idempotency rule.

A hit outside those shapes is the bug this check exists to find.

