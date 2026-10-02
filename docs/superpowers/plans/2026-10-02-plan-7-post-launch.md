# CASS v2 Plan 7: The Panel Theme, Platform-Admin Writes, Failed-Notification Logging, Reviewer Affiliation and the Backlog's Language Sweep

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Status: DRAFT, reviewed 2026-10-02.** Written task by task, with every task prototyped in a throwaway worktree off `main` (`45d2d8e`) and its full suite run green there. The adversarial review (CLAUDE.md, "Pipeline per plan") ran on 2026-10-02, including an executor that ran the tasks in order on SQLite and MySQL 8.4, and its editor's changes are applied in this text. The critic's verdict was **ready**: no blocker or major finding remains, and its six minor gaps are applied too. One thing waits on the owner before Task 1 runs: the one-line change to CLAUDE.md's test command (Task 1, Step 8).

**Goal:** CASS is live, and this plan closes the post-launch half of `docs/superpowers/plans/backlog.md` that needs no decision from the owner. When it is done, the three Filament panels share one Vite-built theme, so a utility class in a panel view finally does something, and the production image compiles byte-for-byte the CSS a checkout does. The platform admin can do the two things spec section 4 gives them and no screen offered: edit an organization's profile, branding and custom domain, and manage its members (change a role, remove a member, withdraw an invitation). A notification email that fails for good is marked `failed` in the email log instead of sitting at `queued`, every queued notification's payload is encrypted, abandoned Livewire uploads are swept every hour, and a hard purge takes the organization's logo with it. A reviewer can correct their own affiliation, the one input to spec 5.5's conflict rule. And every string the backlog's language entry names (enum labels, both export heading rows, three admin tables, the publishing blockers) lives in `lang/en`, with tests that prove the English did not change by a byte.

**Architecture:** Single Laravel application, three Filament panels, unchanged. The seams this plan adds, each with one owner:

- **The panel theme** is one file, `resources/css/filament/theme.css`, written from Filament's stub by hand rather than by `make:filament-theme`, because that command also runs `npm install tailwindcss@latest` (Task 1). All three panels link it with `->viteTheme()`. The public stylesheet switches to `source(none)` and names its sources, so automatic detection can no longer make a checkout and the image compile different CSS. The Dockerfile builds `vendor` before `assets` so the theme can import `vendor/filament`, and `tests/Unit/DockerAssetsStageTest.php` fails if the stage order or the copies ever drift.
- **`App\Actions\Organizations\UpdateOrganizationProfile`** is the one writer of an organization's profile and branding. The organizer's own profile page and the new admin `EditOrganization` page both call it, through one shared schema, `App\Filament\Schemas\OrganizationProfileForm`. A replaced, cleared or purged logo is deleted from the `branding` disk after the database write commits, by one class. The 16 MP limit is one constant, shared by the upload rule and `GenerateConferencePoster` (Task 3).
- **`User::authorityIn()`** lets the platform admin pass the member-management guards as an owner would. The admin relation managers call the existing `ChangeMemberRole`, `RemoveMember` and `RevokeInvitation` actions; none of their guards is weakened, and none is copied (Task 4).
- **`App\Notifications\SendQueuedNotificationsWithLog`** is bound in place of Laravel's `SendQueuedNotifications`. Every queued notification, Filament's own included, travels in it. Its `failed()` marks the email-log row, and its payload is encrypted. The row is found by the notification's own UUID, re-encoded as the row's existing `ulid` (`EmailLog::ulidForNotification()`), so this needs **no migration and opens no deploy window** (Task 5).
- **`App\Actions\Submissions\SweepTemporaryUploads`** plus the hourly `cass:sweep-uploads` command delete Livewire temporary uploads older than Livewire's own 24 hours, and touch nothing outside that directory (Task 5).
- **`App\Actions\Reviewers\UpdateReviewerAffiliation`**, behind a new `ConferenceReviewerPolicy::updateAffiliation()` ability, is the reviewer's own write. The UI is a section on the reviewer panel's profile page (Task 6).
- **The language sweep** adds `lang/en/{enums,export,organizer}.php`, a `$plan7Sources` list in `tests/Feature/LanguageCoverageTest.php` that reads PHP by its tokens, a keyless-locale runtime check for what static reading cannot see, and English pins written against the old code that pass on both sides of the change (Task 2).

**Tech Stack:** PHP 8.4, Laravel 13.32.0, Filament 5.8.1, Livewire 4.4.4, Tailwind 4 through `@tailwindcss/vite` (the public pages and now the panels), Pest 5.1.4 with the Laravel, Livewire and Browser (Playwright) plugins, Larastan 3.11 at level 6, Pint 1.32, spatie/laravel-activitylog 5.1.1, MySQL 8.4 in CI and production, SQLite in-memory locally. Versions are from `composer.lock` at `45d2d8e`.

**Spec:** `docs/superpowers/specs/2026-09-10-cass-v2-design.md`. Section 4's platform-admin column ("Manage organization members", "Manage organization profile, branding, domain"); section 5.5 (the affiliation conflict rule); section 9 (tokens stored hashed, which the encrypted notification payload serves); section 10 (every string in a language file). Every task quotes the backlog entry it closes.

**Read this before you trust a single symbol below.**

1. **A hotfix lands on `main` before this plan starts.** Task 5's writer found that `QueuedVerifyEmail` had signed a route this application never defined, so every registration's verification email had thrown `RouteNotFoundException` in the worker since Plan 1. It shipped on its own as `fe7f949` ("fix(auth): link the registration verification email to the organizer panel"), which adds `tests/Feature/Mail/VerificationMailTest.php` (2 tests). Task 5 carries a note saying so, between its Parts A and B. If that commit has not merged when you start, merge it first. The baseline Task 1 Step 1 records will then be **1216 passed, 1 skipped**, not the 1214 the worktrees measured. Every count below is "baseline + k", so nothing else changes.
2. **Dependabot's framework-group pull request is due Monday 2026-10-05** (Laravel 13.34, Filament 5.9, Livewire 4.4.7). PR #21 unblocked it, and it fails by design until the three nonce-published Filament views are re-published (backlog, "Dependencies"). **Implement this plan on the versions it was written against, then take that upgrade.** If the upgrade has merged first, re-verify every fact that cites a line in `vendor/filament` or `vendor/livewire` before you trust it. Those are Task 1's theme stub and `viteTheme()` facts, Task 3's form and `FileUpload` facts, Task 5's `SendQueuedNotifications` and `FileUploadConfiguration` facts, and Task 6's `EditProfile` drift pin.
3. **Each task's facts are numbered `N.k` and live at the top of that task.** They were read in a Linux container at `45d2d8e` (PHP 8.4.26, Node 22, Composer 2.10.3), where each task was implemented in its own worktree exactly as written and its whole suite run green. The worktrees started from the same commit, so **no task was prototyped on top of the previous one** — with one exception: Task 4's prototype figures start from Task 3's (1235), so it was measured on top of Task 3's worktree. The order-dependent seams (shared lines in `lang/en/admin.php`, `$plan7Sources`, the three panel providers) are written as anchored edits for that reason. If an anchor is not where a step says it is, read the file and adjust the anchor. Never edit the code to match this document.

**Environment facts for every command below**

- Repo root: `C:\Users\ahmed\Documents\CASS` (Git Bash path `/c/Users/ahmed/Documents/CASS`). All commands are Git Bash.
- Composer: `php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar <args>`. Do **not** use the `composer.bat` wrapper: it passes through cmd.exe and silently strips `^` from version constraints. **This plan installs nothing.**
- `ext-sockets` must be on (CLAUDE.md), or `composer install` fails the platform check.
- Node 24 and npm, as CI uses. **From Task 1 on, run `npm run build` before `php artisan test`.** Every panel page asks Vite for the theme, and a manifest without it is a 500. CI already builds before it tests. **Stop `npm run dev` (which `composer run dev` starts) before `php artisan test`:** with `public/hot` present the panels link the dev server, not `public/build`, and `PanelThemeTest` says so.
- **Baseline:** branch `plan-7-post-launch`, created from `main` in Task 1 Step 1. "Baseline" means the passing count `php artisan test` reports on the fresh branch, written down in that step and never hardcoded.
- **Verify by exit code**, never by piped output: `php artisan test --compact --filter='…' > /tmp/t-task-N.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-N.log`. A per-task log name means a stale log cannot pass for a fresh one.
- Test counts in "Expected" lines were counted while writing. **`rc=0` is the gate.** A count off by a few is not a failure; a count off by dozens means a file did not run.
- Commit after every task, with the session's Co-Authored-By trailer. **No task may commit with a failing test.**

**Packages added by this plan: none.** No task changes `composer.json`, `composer.lock`, `package.json` or `package-lock.json`. Task 1 builds the theme with the `tailwindcss` and `@tailwindcss/vite` already installed, and deliberately does not run `make:filament-theme`.

**What this plan does NOT build (kept honest):**

- **The Filament 5.9 upgrade** and the sweep of deprecated table-testing helpers (backlog, "Reviewing"). Both belong to the Dependabot pull request that moves Filament (read-me note 2).
- **The rest of the application's English.** Task 2 converts exactly what the backlog names and measures what is left: about 340 visible literals in about 40 PHP files, 46 of them on pages visitors and authors see. Its replacement backlog entry says which to do first. `Decision::getLabel()` stays English on purpose and moves with spec section 14's bilingual templates.
- **Removing `style-src-attr 'unsafe-inline'`.** The backlog promised the theme would tighten it "for free". It does not, and cannot: Filament writes a `style` attribute on every panel page. Task 7 rewrites that backlog clause and records the 69 inline styles still in panel views as a new entry to convert when a task next touches each view.
- **Anything that needs the owner** (next list) or a spec section 14 feature: reviewer bidding and declared conflicts, certificates, expertise matching, per-abstract QR codes, per-track quotas, Arabic.
- **The items whose triggers have not fired:** memoising `roleIn()`/`canAccessTenant()`, the grouped SQL for `dailyVisitCounts()`, the pagination views in the Tailwind `@source` list, a streamed XLSX export, a dedicated performance runner for the ranking's wall clock.

**Owner questions this plan raises and does not answer.** Each one ships today's behaviour, and Task 7 records it in the backlog as an open question.

1. **May a platform admin rename an organization's slug?** It is the `{organization}` in every `/c/…` URL and every printed poster's QR code, so Task 3 keeps it read-only on both pages.
2. **Should the platform admin be able to invite members, or resend an invitation?** Task 4 does not offer it: `AcceptInvitation` refuses an invitation whose inviter holds no role in the organization, so an admin-made link would fail when the invitee clicks it.
3. **Should an organization's owners be emailed when a platform admin edits their organization or its members?** Nothing is sent today. Every change is in the activity log, with the admin as causer.
4. **When a reviewer's new affiliation creates a conflict on an abstract already assigned to them, should the organizer be told, or the assignment flagged?** Task 6 unassigns nothing, and its modal tells the reviewer to tell the organizers.
5. **Should organizers, not only the reviewer, be able to edit an active reviewer's affiliation?** Task 6 keeps it own-row only.
6. **Should `cass:health` warn before the private volume fills, and at what free-space threshold?** Task 5 keeps `cass:health` as it is: it checks only that the volume can be written. The new hourly `cass:sweep-uploads` removes day-old temporary uploads, which is the largest avoidable growth, but nothing measures free space.

---

## File structure created or changed by this plan

Every path this plan creates, modifies or deletes, from the task sections. Where several tasks touch one file, the later ones edit it in place, anchored as each step says.

```
.github/workflows/ci.yml
    modified, Task 1: smoke job: theme linked under the nonce, served by nginx, contains .dark\:bg-amber-950
CLAUDE.md
    modified, Task 1: the Tests command: `npm run build` first, `npm run dev` stopped
Dockerfile
    modified, Task 1: vendor stage moved above assets; assets copies app/Filament, app/Livewire and --from=vendor vendor/filament...
app/Actions/Conferences/GenerateConferencePoster.php
    modified, Task 3: MAX_LOGO_PIXELS = 16_000_000 replaces the literal at :81; logoDataUri() docblock note
app/Actions/Conferences/PublishConference.php
    modified, Task 2: blockers() -> __('organizer.publish.errors.*'), :status placeholder with mb_strtolower
app/Actions/Organizations/ChangeMemberRole.php
    modified, Task 4: blockers() asks $actor->authorityIn() (one line + comment)
app/Actions/Organizations/DeleteOrganizationLogo.php
    created, Task 3: deletes a logo from the branding disk only when its path is one Filament writes (isCanonical()) and no organization row (trashed included) points at it; shared...
app/Actions/Organizations/PurgeOrganization.php
    modified, Task 3: DeleteOrganizationLogo injected; handle() reads logo_path before the transaction and releases it after the...
app/Actions/Organizations/RemoveMember.php
    modified, Task 4: blockers() asks $actor->authorityIn() (one line + comment)
app/Actions/Organizations/UpdateOrganizationProfile.php
    created, Task 3: the one writer of profile + branding; logs organization.profile_updated; releases a replaced/cleared logo v...
app/Actions/Reviewers/UpdateReviewerAffiliation.php
    created, Task 6: the write: asks ConferenceReviewerPolicy::updateAffiliation, trims, caps at 255, logs reviewer.affiliation_...
app/Actions/Submissions/ExportSubmissionsCsv.php
    modified, Task 2: private const HEADERS -> private static headers() over export.headings.*; yes/no -> export.answers.*
app/Actions/Submissions/SweepTemporaryUploads.php
    created, Task 5: deletes files >24h old under FileUploadConfiguration::path() on FileUploadConfiguration::storage()
app/Console/Commands/SweepTemporaryUploadsCommand.php
    created, Task 5: cass:sweep-uploads, delegates to the action
app/Enums/ConferenceStatus.php
    modified, Task 2: getLabel() arms -> __('enums.conference_status.*')
app/Enums/CustomFieldType.php
    modified, Task 2: getLabel() arms -> __('enums.custom_field_type.*')
app/Enums/Decision.php
    modified, Task 2: getLabel() docblock only: why it is the one label left in English
app/Enums/EmailLogStatus.php
    modified, Task 2: getLabel() arms -> __('enums.email_log_status.*')
app/Enums/EmailTemplateKey.php
    modified, Task 2: getLabel() arms -> __('enums.email_template_key.*'); sampleValues() untouched
app/Enums/InvitationStatus.php
    modified, Task 2: getLabel() arms -> __('enums.invitation_status.*')
app/Enums/OrganizationRole.php
    modified, Task 2: getLabel() ucfirst() -> match over __('enums.organization_role.*')
app/Enums/OrganizationStatus.php
    modified, Task 2: getLabel() ucfirst() -> match over __('enums.organization_status.*')
app/Enums/OrganizationType.php
    modified, Task 2: getLabel() arms -> __('enums.organization_type.*')
app/Enums/PosterSize.php
    modified, Task 2: getLabel() arms -> __('enums.poster_size.*')
app/Enums/PresentationPreference.php
    modified, Task 2: getLabel() arms -> __('enums.presentation_preference.*')
app/Enums/ReminderThreshold.php
    modified, Task 2: getLabel() arms -> __('enums.reminder_threshold.*')
app/Enums/ReviewMode.php
    modified, Task 2: getLabel() arms -> __('enums.review_mode.*')
app/Enums/ReviewQuestionType.php
    modified, Task 2: getLabel() arms -> __('enums.review_question_type.*')
app/Enums/ReviewStatus.php
    modified, Task 2: getLabel() arms -> __('enums.review_status.*')
app/Enums/ReviewerStatus.php
    modified, Task 2: getLabel() arms -> __('enums.reviewer_status.*')
app/Enums/SubmissionStatus.php
    modified, Task 2: getLabel() arms -> __('enums.submission_status.*')
app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php
    modified, Tasks 2, 3: every column/filter label and the placeholder -> admin.conferences.* (Task 2); the conference name escaped in the purge notification (Task 3)
app/Filament/Admin/Resources/EmailLogs/Tables/EmailLogsTable.php
    modified, Task 2: every column/filter label, placeholder, empty state -> admin.email_log.*
app/Filament/Admin/Resources/Organizations/OrganizationResource.php
    modified, Tasks 3, 4: two imports, form(), 'edit' page in getPages()
app/Filament/Admin/Resources/Organizations/Pages/EditOrganization.php
    created, Task 3: admin EditRecord page; handleRecordUpdate -> UpdateOrganizationProfile with the admin as actor
app/Filament/Admin/Resources/Organizations/Pages/ViewOrganization.php
    modified, Task 3: EditAction header action
app/Filament/Admin/Resources/Organizations/RelationManagers/InvitationsRelationManager.php
    modified, Task 4: revoke record action via RevokeInvitation, docblock
app/Filament/Admin/Resources/Organizations/RelationManagers/MembersRelationManager.php
    modified, Task 4: changeRole + remove record actions (authorize on OrganizationResource::canAccess()), refuse() notification,...
app/Filament/Admin/Resources/Organizations/Tables/OrganizationsTable.php
    modified, Tasks 2, 3: every column/filter label + approve/reject action strings -> admin.organizations.*, the name escaped with e() in all three notifications; recordActions/purgeActi...
app/Filament/Organizer/Pages/Members.php
    modified, Task 4: canAccess() docblock only (the admin cell now has a screen)
app/Filament/Organizer/Pages/Tenancy/EditOrganizationProfile.php
    modified, Task 3: whole file - form() renders OrganizationProfileForm with Filament::getTenant() as record; handleRecordUpdat...
app/Filament/Reviewer/Pages/EditProfile.php
    created, Task 6: reviewer panel profile page: Filament's form + 2FA, plus an embedded table of active conferences with a per...
app/Filament/Schemas/OrganizationProfileForm.php
    created, Task 3: the organizer's profile form moved out verbatim, shared by both panels; $record instead of Filament::getTen...
app/Listeners/RecordOutgoingEmail.php
    modified, Task 5: sending(): key a notification's row by ulidForNotification(), reuse an existing row on any later try; class...
app/Models/EmailLog.php
    modified, Task 5: import Symfony Ulid; static ulidForNotification(): a notification's UUID re-encoded as the row ULID (null i...
app/Models/User.php
    modified, Task 4: authorityIn() after roleIn() - Owner for a platform admin, roleIn() otherwise
app/Notifications/SendQueuedNotificationsWithLog.php
    created, Task 5: subclass of Illuminate SendQueuedNotifications: failed() marks the email_logs row (guarded: queued, mail ch...
app/Policies/ConferenceReviewerPolicy.php
    modified, Task 6: new updateAffiliation() ability (own row, active), anchored before delete()
app/Providers/AppServiceProvider.php
    modified, Task 5: register(): second binding SendQueuedNotifications -> SendQueuedNotificationsWithLog, 2 imports, DnsResolve...
app/Providers/Filament/AdminPanelProvider.php
    modified, Task 1: ->viteTheme('resources/css/filament/theme.css') after ->path('admin')
app/Providers/Filament/OrganizerPanelProvider.php
    modified, Task 1: ->viteTheme('resources/css/filament/theme.css') after ->path('org')
app/Providers/Filament/ReviewerPanelProvider.php
    modified, Tasks 1, 6: ->viteTheme('resources/css/filament/theme.css') after ->path('review')
app/Support/Scoring/RankingRows.php
    modified, Task 2: headers() -> __('export.headings.*')
docs/runbooks/deploy-production.md
    modified, Tasks 1, 5: Every release step 4 names the theme, not app.css (Task 1); Email triage (failed row + two paragraphs); Rollback (notifications queued under Plan 7); Invitations and their tokens (encrypted-payload bullet names th...
docs/superpowers/plans/backlog.md
    modified, Task 7: the thirteen entries Tasks 1-6 close deleted, both language entries replaced by one, the CSP clause rewritten, a sentence appended to both pagination entries and to the Filament-bump entry, the inline-styles entry, and the Plan 7 open-questions section
lang/en/admin.php
    modified, Tasks 2, 3, 4: three groups (conferences, organizations, email_log) inserted before 'submissions'; no existing line changes
lang/en/enums.php
    created, Task 2: every enum label but Decision's, one group per enum, keyed by stored value
lang/en/export.php
    created, Task 2: shared heading words for the submission CSV and both ranking files, plus yes/no answers
lang/en/organizer.php
    created, Task 2: publish.errors: the eight PublishConference::blockers() sentences
lang/en/reviewer.php
    modified, Task 6: errors.affiliation_not_yours/_removed/_too_long; new `affiliation` group after `switch`
public/images/icons/badge.svg
    DELETED, Task 5: DELETED (git rm), in its own "assets:" commit
resources/css/app.css
    modified, Task 1: @import 'tailwindcss' source(none) with a comment, and @source '../js'
resources/css/filament/theme.css
    created, Task 1: the one Vite-built theme for all three panels: Filament's theme.css + @source app/Filament and resources/vi...
resources/views/brand/logo.blade.php
    modified, Task 1: comment only: why the lock-up stays inline (two stylesheets, two meanings of dark:)
resources/views/filament/admin/partials/purge-counts.blade.php
    modified, Task 3: the <li> line maps 'branding files' to admin.purge.logo
resources/views/filament/organizer/pages/dashboard.blade.php
    modified, Task 1: welcome card's two inline styles become font-semibold / mt-1 text-sm; stale comment replaced
resources/views/filament/organizer/resources/conferences/pages/short-link.blade.php
    modified, Task 1: comment only: the theme exists now; bar heights are data
routes/console.php
    modified, Task 5: import + Schedule::command(SweepTemporaryUploadsCommand::class)->hourly() above the heartbeat block
tests/Browser/CspTest.php
    modified, Task 1: one case appended: on /org/login a bg-amber-50 element computes a background under the enforced CSP (theme...
tests/Feature/Admin/AdminReadOnlyTest.php
    modified, Task 4: the "no write" dataset case names each manager's writes (changeRole, remove; revoke)
tests/Feature/Admin/AdminTableLanguageTest.php
    created, Task 2: keyless-locale check of all 10 admin tables, English pins of the 3 converted ones + approve/reject, the escaped name in the approve notification, organiz...
tests/Feature/Admin/EditOrganizationTest.php
    created, Task 3: 14 cases (edit, activity causer, contrast, logo replace/clear/fail/outer rollback, 16 MP boundary, another spelling of another organization's path, cross-te...
tests/Feature/Admin/MembersManagementTest.php
    created, Task 4: 9 cases (change role, ownership hand-over, remove, last-owner refusal as notification, guards for a platfor...
tests/Feature/Admin/PurgeLogoTest.php
    created, Task 3: 8 cases (modal + delete + cross-tenant, run/preview parity, rollback keeps then retry deletes, shared path, another spelling of a path, a path that climbs out of the disk...
tests/Feature/Console/SweepTemporaryUploadsTest.php
    created, Task 5: 3 tests: age cutoff incl. .json sidecar, nothing outside livewire-tmp, hourly schedule
tests/Feature/ExtractedEnglishTest.php
    created, Task 2: pins: 67 enum labels, both export heading rows (streamed CSV), the 8th blocker - written against old code,...
tests/Feature/LanguageCoverageTest.php
    modified, Tasks 2, 3, 4, 6: six imports; appends $plan7Sources, $plan7NotProse, the Blade and PHP-token sweeps, and 4 cases (keys, visi...
tests/Feature/Mail/FailedNotificationLogTest.php
    created, Task 5: 7 tests: sync failure, 3-try encrypted invitation via queue:work, retry-then-sent, queue:retry reuses the r...
tests/Feature/Organizer/OrganizationProfileTest.php
    modified, Task 3: Activity import + 2 cases (logo cleanup with organizer causer, 16 MP refusal)
tests/Feature/PanelThemeTest.php
    created, Task 1: 9 cases: viteTheme on 3 panels, nonced theme link on 3 logins, dashboard classes compiled, welcome card res...
tests/Feature/PublicImagesTest.php
    created, Task 5: 1 test: every file under public/images is named somewhere in resources/ or app/
tests/Feature/Reviewer/ReviewerAffiliationTest.php
    created, Task 6: 10 cases: profile page wiring + CSP nonces, vendor content() drift pin, listing, prefill + edit, max rule,...
tests/Feature/Security/QueuedMailPayloadTest.php
    modified, Task 5: imports; SendQueuedNotificationsWithLog added to the ShouldBeEncrypted dataset; new case: queued Filament r...
tests/Pest.php
    modified, Task 3: blankPng() helper appended after scoredReview()
tests/Unit/DockerAssetsStageTest.php
    created, Task 1: 4 cases: vendor stage first, vendor/filament from vendor stage, every @source/@import copied before npm run...
tests/Unit/UpdateReviewerAffiliationTest.php
    created, Task 6: 10 cases: own edit + log, clear, no-op, other reviewer, organizer, removed, cross-org removed row, 255/256,...
vite.config.js
    modified, Task 1: resources/css/filament/theme.css added to the input array
```


---

## Facts verified before writing this plan

Every task carries its own numbered facts (`1.1` … `6.k`) at its top, each with the `path:line` its writer opened at `45d2d8e` on 2026-10-02. The facts every task shares:

1. **Versions** are the ones in **Tech Stack** above, read from `composer.lock` and `phpstan.neon` (`level: 6`) at `45d2d8e`.
2. **The baseline on `main` at `45d2d8e`** was 1214 passed, 1 skipped (`php artisan test`, SQLite in-memory, PHP 8.4.26). With the hotfix `fe7f949` it is 1216 passed, 1 skipped. The one skipped test is the opt-in wall-clock case in `tests/Feature/Organizer/ConferenceRankingPerformanceTest.php` (backlog, "Decisions").
3. **CI** runs, in order: Pint, Larastan, the SQLite suite, then the MySQL suite in the `test` job, after `npm run build`. The `browser` job runs `./vendor/bin/pest --configuration=phpunit.browser.xml` after `npx playwright install --with-deps chromium` and `npm run build`. The `image` job builds the arm64 image, and `smoke` runs it (`.github/workflows/ci.yml`).
4. **Production runs migrations by hand,** after the new container is healthy (CLAUDE.md; `docs/runbooks/deploy-production.md`, "Every release", step 3). **This plan adds no migration.** Task 5's first draft added a column and with it a window in which every notification failed until someone ran `migrate`; its final design keys the row by the existing `ulid` instead.

---

### Task 1: Baseline, and the panel theme

The branch, the baseline every later "Expected" line is measured from, and two backlog items that are one change. From `docs/superpowers/plans/backlog.md`, "Organizer features (Plan 2 onwards)":

> Organizer panel theme: `php artisan make:filament-theme organizer`, `->viteTheme(...)`, add the file to the `vite.config.js` input, and move the Dockerfile's `vendor` stage above the `assets` stage so `vendor/filament` can be copied in (the theme's `@import` reaches into it, and `.dockerignore` excludes `vendor`). Until then Tailwind utility classes in panel Blade views do nothing — Plan 1's dashboard banners are already unstyled, and Plan 2 uses inline styles and `<x-filament::section>` instead.

and from "Launch (deferred by Plan 6)":

> **The production image builds its CSS without `app/Livewire` in scope.** `resources/css/app.css` has two `@source` roots, `../views` and `../../app/Livewire`, and the Dockerfile's `assets` stage copies only `resources` and `public` (`Dockerfile:19-20`). So a Tailwind class used **only** inside a Livewire PHP file is silently absent in production and present locally. It belongs with the organizer-theme item, which is where the stage order is already discussed, and the fix is the same `COPY` reordering.

The Content-Security-Policy entry in "Security and platform" also makes a promise about this task:

> `style-src-attr` carries `'unsafe-inline'` because a nonce never reaches a style *attribute*, and about forty-five of them are inline — including the organization branding variables and the brand lock-up on all three panels; that one gets stricter for free the day the organizer theme lands.

Decision 6 says what the theme actually does to that directive: nothing. The header is byte-for-byte the same after this task, and the sentence is rewritten in Task 7 rather than left standing.

No migration, no action, no language key, no package. One new stylesheet, one changed stylesheet, three provider lines, one Dockerfile stage order, one view restyled, two comments corrected, five smoke-job lines, and two documents the theme makes stale: one sentence of the runbook's "Every release" and CLAUDE.md's test command.

**Facts this task relies on.** Read on `main` at `45d2d8e`, against `vendor/` as `composer.lock` installs it, on 2026-10-02.

1.1 **Versions.** `composer.lock`: `filament/filament` `v5.8.1` (`:1277-1278`), `laravel/framework` `v13.32.0` (`:2327-2328`), `livewire/livewire` `v4.4.4` (`:3481-3482`). `package-lock.json:2461-2462`: `tailwindcss` `4.3.3`; `node_modules` also holds `@tailwindcss/vite` 4.3.3, `vite` 7.3.6 and `laravel-vite-plugin` 2.1.0. `package.json` lists `tailwindcss` and `@tailwindcss/vite` as `^4.0.0` dev dependencies, which `npm ci` installs in the image's `assets` stage (`Dockerfile:18`).

1.2 **`make:filament-theme` does four things, one of which this task does not want.** `vendor/filament/filament/src/Commands/MakeThemeCommand.php`: the theme path is `resources/css/filament/{$panelId}/theme.css` (`:96`); `installDependencies()` (`:164-175`) runs `npm install tailwindcss@latest @tailwindcss/vite --save-dev` (`:171`) unconditionally; `createThemeSourceFiles()` (`:177-209`) fills the stub; `registerInViteConfig()` (`:231-303`) appends to the `input` array; `registerInPanelProvider()` (`:305-352`) edits **only** the named panel's provider, skips any provider that already contains `viteTheme(` (`:319`), and otherwise inserts `->viteTheme('...')` on the line after `->path(...)` (`:331-341`). It then asks interactively whether to compile (`:128`).

1.3 **The stub is three lines.** `vendor/filament/filament/stubs/ThemeCss.stub:1-4`: `@import '{{ filamentThemeCssPath }}';` and two `@source` lines, `'../../../../app/Filament/{{ classDirectory }}**/*'` and `'../../../../resources/views/filament/{{ viewDirectory }}**/*'`. For the organizer panel the placeholders resolve to `Organizer/` and `organizer/` (`MakeThemeCommand.php:187-194`).

1.4 **Filament's own theme turns off automatic source detection, and everything it imports lives in `vendor/filament`.** `vendor/filament/filament/resources/css/theme.css:1-2` is `@import 'tailwindcss' source(none);` and `@import './index.css';`. `index.css:1-8` imports the seven sibling packages by relative path (`../../../support/resources/css/index.css` …), `:27` declares `@variant dark (&:where(.dark, .dark *));`, and `vendor/filament/support/resources/css/index.css:5` imports `../../dist/index.css`, which ships in the package (`vendor/filament/support/dist/index.css`). `vendor/filament` is 125 MB on disk.

1.5 **A Vite theme replaces Filament's default stylesheet; it is not added to it.** `vendor/filament/filament/src/Panel/Concerns/HasTheme.php`: `viteTheme()` (`:27-33`) stores the path; `getTheme()` (`:50-71`) returns `app(Vite::class)($viteTheme, $this->viteThemeBuildDirectory)` wrapped as a `Theme` when one is set, and `getDefaultTheme()` — `FilamentAsset::getTheme('app')`, i.e. `public/css/filament/filament/app.css` — only when it is not (`:56-58`, `:73-76`). `vendor/filament/support/src/Assets/Css.php:38-53` returns that HTML untouched when it already contains `<link`.

1.6 **The theme link carries the CSP nonce with no change to any published view.** `resources/views/vendor/filament-panels/components/layout/base.blade.php:83` renders `{{ filament()->getTheme()->getHtml() }}` and is not one of the lines Plan 6 edited. `vendor/laravel/framework/src/Illuminate/Foundation/Vite.php:799-808` builds every stylesheet tag with `'nonce' => $this->nonce ?? false`; `app/Http/Middleware/ContentSecurityPolicy.php:89` calls `Vite::useCspNonce()` before `$next($request)`. Livewire adds `data-navigate-track="reload"` to every Vite style tag (`vendor/livewire/livewire/src/Features/SupportNavigate/SupportNavigate.php:29-31`), the attribute Filament's default `<link>` carried. `style-src` is `'self'` plus the nonce (`ContentSecurityPolicy.php:71`, `:83`).

1.7 **After `->viteTheme()`, a panel page needs a build that contains the theme.** `Vite.php:1056` throws `Unable to locate file in Vite manifest: {$file}.` The CI `test` job runs `npm run build` (`.github/workflows/ci.yml:52`) before both suites (`:58-68`).

1.8 **Filament's precompiled stylesheet has no general utilities.** `public/css/filament/filament/app.css` (published by `php artisan filament:assets`, `Dockerfile:66`) contains zero rules for `.rounded-xl`, `.bg-amber-50`, `.border-amber-300`, `.font-semibold`, `.mt-1`, `.text-sm`, `.p-4`, `.flex` or `.grid` (`grep -c` on each: 0). `resources/views/filament/organizer/pages/dashboard.blade.php:3` and `:8` are the Plan 1 banners, written in exactly those utilities; `:16-20` is a comment saying why the welcome card at `:22-23` uses `style="font-weight:600"` and `style="margin-top:0.25rem;font-size:0.875rem"` instead.

1.9 **The three panels differ in nothing a compiled stylesheet holds.** `->id()`/`->path()`: `AdminPanelProvider.php:32-33`, `OrganizerPanelProvider.php:36-37`, `ReviewerPanelProvider.php:45-46`. All three set the same `->colors(['primary' => Color::hex('#176BB8')])` (`Admin :48-50`, `Organizer :55-57`, `Reviewer :62-64`) and `->brandLogoHeight('2.25rem')`; none calls `->theme()`, `->viteTheme()` or `->spa()`. Colours reach the page at run time as CSS variables in `resources/views/vendor/filament/assets.blade.php:22-28`. `PanelSwitch::toReviewer()` / `toOrganizer()` (`OrganizerPanelProvider.php:70`, `ReviewerPanelProvider.php:79`) move one user between two of them.

1.10 **The image's stages today.** `Dockerfile:15-21` is the `assets` stage — `COPY package*.json vite.config.js ./`, `npm ci`, `COPY resources`, `COPY public`, `npm run build`. `:23-28` is the `vendor` stage, after it. `:45-46` copy `/build/vendor` and `/build/public/build` into `runtime`. `:6-14` is the digest-pinning comment. `.dockerignore:5` excludes `vendor`, `:26` excludes `.gitignore`, and nothing there excludes `app`.

1.11 **The public stylesheet uses automatic detection.** `resources/css/app.css:1` is `@import 'tailwindcss';` (no `source(...)`), `:9-10` the two `@source` lines. `vite.config.js:8` is `input: ['resources/css/app.css', 'resources/js/app.js'],`.

1.12 **Measured: one commit already builds two different public stylesheets.** Main's `app.css`, built three ways in the prototype: a checkout (`npm run build`) **52,350 bytes**; the image's own `assets` stage (`docker build --target assets` of `main`) **49,880 bytes**; the same files plus `app/Livewire` **50,214 bytes**. Automatic detection starts at the project root, so a checkout scans the whole repository and the stage scans only what it copied — the SVGs in `public/` and `resources/brand/` included, whose `transform=` attributes are where production's `.transform` rule comes from. The Livewire difference is exactly three rules, `.container`, `.static` and `.visible` — words in comments and `static fn` (`app/Livewire/Public/SubmissionForm.php:802`) — and **no class any view uses**: `app/Livewire` contains no utility class string at all (`grep` for `bg-|text-|border-|rounded` in `app/Livewire`: no hits), and neither does `app/Filament`. The backlog bug is real and latent. And the reordered stage *without* decision 3's fix makes production's `app.css` **59,570 bytes**: 105 extra rules auto-detected out of `vendor/filament`.

1.13 **A script is a Tailwind source.** `resources/js/countdown.js:66` is `badge.className = 'ml-2 font-medium text-[var(--org-accent)]';`. Automatic detection has been scanning `resources/js`; an explicit list has to name it.

1.14 **BuildKit refuses the old stage order, and a theme build without `vendor/filament` fails loudly.** Measured: a Dockerfile whose `assets` stage says `COPY --from=vendor` before the `vendor` stage is defined fails with `cannot copy from stage "vendor", it needs to be defined before current stage "assets"`; a context `COPY vendor/filament` under `.dockerignore`'s `vendor` fails with `"/vendor/filament": not found`; and `vite build` with the theme but no `vendor/filament` fails with `[@tailwindcss/vite:generate:build] Can't resolve '../../../vendor/filament/filament/resources/css/theme.css'`. A **missing `@source` directory**, by contrast, builds green and scans nothing (fact 1.12's 49,880 bytes).

1.15 **`style-src-attr 'unsafe-inline'` is required by code the theme does not touch.** `ContentSecurityPolicy.php:72` sets it and `tests/Feature/Public/SecurityHeadersTest.php:40` pins it. On every panel page Filament itself writes `style="height: 2.25rem"` round the brand logo (`vendor/filament/filament/resources/views/components/logo.blade.php:17`, `:26`). On every authenticated page with navigation and no collapsible sidebar — all three panels — `vendor/filament/filament/resources/views/components/layout/index.blade.php:101-103` puts `x-bind:style="'display: flex; opacity:1;'"` on `.fi-main-ctn`, Alpine applies a string style with `el.setAttribute("style", value)` (`vendor/livewire/livewire/dist/livewire.esm.js:3817-3819`), and until it does the element is `opacity-0` (`vendor/filament/filament/resources/css/components/layout.css:23-29`). The public conference layout carries the organization's branding variables as an attribute (`resources/views/components/layouts/conference.blade.php:36`), and the short-link sparkline's bar heights are data (`resources/views/filament/organizer/resources/conferences/pages/short-link.blade.php:50`). CASS's own panel views hold 71 `style="` attributes today.

1.16 **What else the theme makes stale.** `resources/views/brand/logo.blade.php:4-8` says the panels "compile their CSS from Filament's own sources", and `:18`, `:22`, `:23` are the lock-up's inline styles, shared with the public header. `short-link.blade.php:31-39` says "this panel has no custom theme (`->viteTheme()`)".

1.17 **What CI proves today.** The `image` job (`ci.yml:130-143`) builds arm64 and runs nothing. The `smoke` job (`:145-265`) builds amd64, runs it, and already fetches `/org/login` headers and body in one request and extracts the nonce (`:257-263`); `:194` curls Filament's default `app.css`, which `filament:assets` keeps publishing.

**Decisions this task makes.**

**1. One theme for all three panels, at `resources/css/filament/theme.css`.** Not organizer-only: the trap the backlog describes is in every panel — the reviewer views carry 18 inline styles (`reviewer/pages/dashboard.blade.php` 7, `review-submission.blade.php` 11) and the admin purge preview 3 — and an organizer-only theme would leave two panels where a utility class still renders as nothing, with no error. Not three themes: the compiled file is 621.76 KB (64.27 KB gzipped) and almost all of it is Filament's own CSS, so three per-panel themes are three near-identical files, three cache entries, and a second download every time `PanelSwitch` moves a user from `/org` to `/review`. Nothing in the compiled file is panel-specific: colours are run-time CSS variables (fact 1.9) and the `@source` lines below cover all three panels' views and classes. The path has no panel id because the file belongs to none, which puts it one level above the stub's and makes its relative paths `../../../` rather than `../../../../`.

**2. The theme file is written by hand from the stub, and nobody runs `make:filament-theme`.** The command runs `npm install tailwindcss@latest @tailwindcss/vite --save-dev` unconditionally (fact 1.2), which rewrites `package.json`'s ranges and `package-lock.json` in a commit about a stylesheet; it registers one panel, not three; and it stops to ask a question. The file below is the stub's three lines with the panel segments dropped. If somebody does run the command later, `registerInPanelProvider()` sees `viteTheme(` in all three providers and leaves them alone (fact 1.2, `:319`). The `->viteTheme()` line goes where the command would have put it — straight after `->path(...)` — so the providers look the way Filament's own tooling expects.

**3. The public stylesheet gets `source(none)` and names its sources, `../js` included.** Fact 1.12 is the reason: automatic detection makes the checkout and the image compile different files from the same commit, and moving `vendor/filament` into the stage — which this task must do — would widen the gap to 105 rules in production only. With `@import 'tailwindcss' source(none);` and `@source` lines for `../views`, `../js` (fact 1.13) and `../../app/Livewire`, the prototype's checkout and its `docker build --target assets` produced **byte-identical** `app-*.css` and `theme-*.css` (sha256 `1b7120d9a98ab5aa…` and `8fcc2d1ce3b25841…`). Against what production serves today, the explicit list drops four rules — `.transform` (from the SVG `transform=` attributes in `public/` and `resources/brand/`) and `.h-24`, `.items-end`, `.invisible` (from the short-link comment Step 8 rewrites) — none of which any view, script or component uses (grep), and adds the three of fact 1.12. It also means the owner's local `docker build`, whose context carries the git-ignored `public/js/filament/*`, builds the same file CI does.

**4. The Dockerfile builds `vendor` first and copies exactly what the stylesheets read.** `COPY --from` can only name an earlier stage (fact 1.14). The `assets` stage copies `app/Filament` and `app/Livewire` from the context and `vendor/filament` — the whole package tree, not a hand-picked list of its CSS files, so a Filament upgrade that adds an import still builds — from the `vendor` stage. Those 125 MB live in the `assets` stage only; `runtime` still copies nothing from it but `/build/public/build` (fact 1.10). The new `COPY` lines go after `RUN npm ci`, so the `npm ci` layer is still reused when only PHP changes, and BuildKit can still run `npm ci` alongside `composer install`, because nothing before that line depends on `vendor`. **`.dockerignore` does not change:** `vendor` must stay out of the context (it comes from the stage) and `app` was never excluded. Digest pins and the comment above them are untouched; the new comment sits above the moved stage, in the file's voice.

**5. The regression gate is text in the suite plus five lines in the smoke job.** `tests/Unit/DockerAssetsStageTest.php` reads `Dockerfile`, `.dockerignore`, `vite.config.js` and every stylesheet in its `input`, and fails if the stage order flips, if `vendor/filament` stops coming from the `vendor` stage, if any `@source` root or relative `@import` is not copied before `npm run build`, or if any stylesheet goes back to automatic detection (which would make the third check blind). It needs no Docker, so it runs on every `php artisan test`. The smoke job, which already builds and runs the image (fact 1.17), gains five lines: the login page links `theme-*.css` under the response's nonce, nginx serves it, and it contains `.dark\:bg-amber-950` — a utility exactly one view uses, the organizer dashboard. A stage that loses `vendor/filament` fails at `npm run build` (fact 1.14); one whose theme stops reaching `resources/views/filament` builds green and fails at the `grep`. **Docker here:** the prototype for this task ran the real `Dockerfile`'s `assets` stage with BuildKit, substituting the pinned node base with a copy that trusts the sandbox's TLS proxy and supplying `vendor/filament` as a named build context instead of running composer; the full image and the smoke job were not run, so CI's `smoke` job is the gate. Step 11 gives the owner the same `--target assets` check on the development machine.

**6. CSP: nothing new needs a nonce, and `style-src-attr 'unsafe-inline'` stays.** What changes: the theme arrives as a Vite `<link>` that carries the request nonce through `Vite::useCspNonce()` (fact 1.6) and would load under `'self'` anyway; no published Filament view changes, so `PublishedFilamentViewsTest` stays green on the same three hashes; no `<style>` or `<script>` is added anywhere. Two of CASS's own style attributes go (the welcome card), and every future panel view has a utility class to reach for instead of another attribute. A new case in `tests/Browser/CspTest.php` proves it in a real browser with the policy enforced: on `/org/login` an element given `bg-amber-50` computes a background colour, which it cannot if `style-src` refused the theme or the panel were still on Filament's precompiled stylesheet (the case fails on `main` with `Expecting 'rgba(0, 0, 0, 0)' not to be 'rgba(0, 0, 0, 0)'`). What does not change: the header, byte for byte, and `SecurityHeadersTest.php:40`. The backlog's "gets stricter for free" was wrong for four reasons none of which a stylesheet can reach (fact 1.15): Filament's own `style="height: 2.25rem"` on the brand logo; Filament's `x-bind:style` string, which Alpine applies with `setAttribute('style', …)` to the element that holds every authenticated page's content and which is invisible until it does — so dropping `'unsafe-inline'` would blank all three panels; the organization's branding variables on the public conference pages; and attribute values that are data, such as the sparkline. Task 7 rewrites that clause; the exact sentence is in this task's backlog notes.

**7. Restyle one view: the organizer dashboard. Correct two comments. Leave the rest.** The Plan 1 banners need no edit at all — their classes start working — and the welcome card's two inline styles become `font-semibold` and `mt-1 text-sm`. The brand lock-up keeps its inline styles: it is rendered by *both* stylesheets, and they disagree about `dark:` (the panel theme's is Filament's `.dark` class, fact 1.4; the public one's is the operating system's preference), so its comment is rewritten to say that instead of something no longer true. The short-link sparkline keeps its inline styles because its bar heights are data; its comment is rewritten too. The other 69 panel style attributes — the ranking summary, the custom-domain records, the reviewer views, the assignments and purge previews — are converted when a task next touches those views, not in a task about the build; Task 7 records that in the backlog.

**8. From this commit on, `php artisan test` needs `npm run build` to have run after it.** Every panel page asks Vite for the theme, and a manifest without it is a 500 (fact 1.7). CI already builds first. Every later task in this plan starts from a tree where Step 10 below has been run; anyone who pulls this branch onto a stale `public/build` sees `Unable to locate file in Vite manifest: resources/css/filament/theme.css.` and runs `npm run build` once. `PanelThemeTest`'s manifest case fails with that instruction in its message rather than a stack trace. **Stop `npm run dev` before `php artisan test`; with `public/hot` present the panels link the dev server** (`vendor/laravel/framework/src/Illuminate/Foundation/Vite.php:389-395`, `:1235`), and `PanelThemeTest`'s link case says so in its first line rather than as a bare regex mismatch. Because this is now a standing rule for every session, Step 8 writes both halves into CLAUDE.md's test command, where `composer run dev` (which starts `npm run dev`) is documented.

**Files:**
- Create: `resources/css/filament/theme.css`
- Modify: `resources/css/app.css` (`source(none)` and `@source '../js'`), `vite.config.js` (the theme in `input`)
- Modify: `app/Providers/Filament/AdminPanelProvider.php`, `OrganizerPanelProvider.php`, `ReviewerPanelProvider.php` (one `->viteTheme()` line each)
- Modify: `Dockerfile` (stage order and three `COPY` lines), `.github/workflows/ci.yml` (five smoke-job lines)
- Modify: `resources/views/filament/organizer/pages/dashboard.blade.php`; comments only in `resources/views/brand/logo.blade.php` and `resources/views/filament/organizer/resources/conferences/pages/short-link.blade.php`
- Modify: `docs/runbooks/deploy-production.md` (one sentence of "Every release" step 4), `CLAUDE.md` (the Tests command)
- Unchanged, on purpose: `.dockerignore` (decision 4), every view under `resources/views/vendor/` (decision 6)
- Test: `tests/Feature/PanelThemeTest.php`, `tests/Unit/DockerAssetsStageTest.php`, and one case appended to `tests/Browser/CspTest.php`

- [ ] **Step 1: Branch, record the baseline, and verify every symbol this plan calls**

```bash
cd /c/Users/ahmed/Documents/CASS && git checkout main && git pull --ff-only && \
git checkout -b plan-7-post-launch && \
php artisan test > /tmp/t-baseline.log 2>&1; echo "baseline rc=$?"; tail -3 /tmp/t-baseline.log && \
./vendor/bin/pint --test > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?" && \
php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar show filament/filament livewire/livewire laravel/framework | grep -E "^(name|versions)" && \
node -p "require('./node_modules/tailwindcss/package.json').version"
```

Expected: `baseline rc=0`, `pint rc=0`, `stan rc=0`, filament `v5.8.1`, livewire `v4.4.4`, laravel `v13.32.0`, tailwindcss `4.3.3`. On `main` at `45d2d8e` the suite was **1214 passed, 1 skipped**, and with the verification-email hotfix `fe7f949` it is **1216 passed, 1 skipped** (read-me note 1) — but **write the real passing-test count from `tail -3 /tmp/t-baseline.log` into your notes before changing a single line**: every "Expected" line in this plan is `baseline + N` measured from that number. If any `rc` is non-zero, stop: `main` is not green and this branch has the wrong parent.

Then the symbol check. Write `/tmp/plan7symbols.sh`:

```bash
#!/usr/bin/env bash
# Every name Plan 7 relies on. A MISSING line is not a blocker - it means read
# the code on main and adjust this plan's call site to it.
cd "${1:-/c/Users/ahmed/Documents/CASS}"
missing=0
check () { # $1 = pattern, $2 = path
  if grep -rqn -- "$1" "$2" 2>/dev/null; then
    printf 'ok      %s\n' "$1"
  else
    printf 'MISSING %-52s (looked in %s)\n' "$1" "$2"; missing=$((missing+1))
  fi
}
# --- Task 1: the panel theme and the assets stage
check "^@import 'tailwindcss';$"                       resources/css/app.css
check "^@source '../../app/Livewire';$"                resources/css/app.css
check "input: \['resources/css/app.css', 'resources/js/app.js'\],"  vite.config.js
check ' AS assets$'                                    Dockerfile
check ' AS vendor$'                                    Dockerfile
check '^COPY public ./public$'                         Dockerfile
check '^vendor$'                                       .dockerignore
check "->path('admin')"                                app/Providers/Filament/AdminPanelProvider.php
check "->path('org')"                                  app/Providers/Filament/OrganizerPanelProvider.php
check "->path('review')"                               app/Providers/Filament/ReviewerPanelProvider.php
check 'filament()->getTheme()->getHtml()'              resources/views/vendor/filament-panels/components/layout/base.blade.php
check "'style-src-attr' => \[\"'unsafe-inline'\"\]"    app/Http/Middleware/ContentSecurityPolicy.php
check '$nonce = Vite::useCspNonce();'                  app/Http/Middleware/ContentSecurityPolicy.php
check '<p style="font-weight:600">'                    resources/views/filament/organizer/pages/dashboard.blade.php
check 'bg-amber-50 p-4'                                resources/views/filament/organizer/pages/dashboard.blade.php
check 'no custom theme (`->viteTheme()`)'              resources/views/filament/organizer/resources/conferences/pages/short-link.blade.php
check 'the panels compile their CSS from Filament'     resources/views/brand/logo.blade.php
check "badge.className = 'ml-2"                        resources/js/countdown.js
check 'grep -q "nonce=\\"$NONCE\\"" /tmp/login'        .github/workflows/ci.yml
check '/css/filament/filament/app.css` and `/vendor/livewire/livewire.min.js` should both return 200' docs/runbooks/deploy-production.md
check '^- Tests: `php artisan test` (SQLite in-memory)'  CLAUDE.md
# --- Task 1: vendor (Filament 5.8.1, Livewire 4.4.4)
check "@import 'tailwindcss' source(none);"            vendor/filament/filament/resources/css/theme.css
check '@source '"'"'../../../../app/Filament/'          vendor/filament/filament/stubs/ThemeCss.stub
check 'public function viteTheme('                     vendor/filament/filament/src/Panel/Concerns/HasTheme.php
check "'tailwindcss@latest', '@tailwindcss/vite'"      vendor/filament/filament/src/Commands/MakeThemeCommand.php
check 'Vite::useStyleTagAttributes('                   vendor/livewire/livewire/src/Features/SupportNavigate/SupportNavigate.php
# --- Task 2: the language sweep
check 'public function getLabel(): string'             app/Enums/ConferenceStatus.php
check 'private const HEADERS = \['                    app/Actions/Submissions/ExportSubmissionsCsv.php
check 'public static function headers('                app/Support/Scoring/RankingRows.php
check 'public function blockers('                      app/Actions/Conferences/PublishConference.php
check '$plan6Sources = \['                            tests/Feature/LanguageCoverageTest.php
# --- Tasks 3-4: platform-admin writes
check 'class EditOrganizationProfile'                  app/Filament/Organizer/Pages/Tenancy/EditOrganizationProfile.php
check 'function logoDataUri('                          app/Actions/Conferences/GenerateConferencePoster.php
check 'public function update(User $user'              app/Policies/OrganizationPolicy.php
check "Storage::disk('local')->delete(\$path);"        app/Actions/Organizations/PurgeOrganization.php
check 'public function blockers('                      app/Actions/Organizations/ChangeMemberRole.php
check 'public function blockers('                      app/Actions/Organizations/RemoveMember.php
check 'class RevokeInvitation'                         app/Actions/Organizations/RevokeInvitation.php
check 'public function roleIn('                        app/Models/User.php
check 'class MembersRelationManager'                   app/Filament/Admin/Resources/Organizations/RelationManagers/MembersRelationManager.php
# --- Task 5: notifications, the sweep, the icon
check 'class RecordOutgoingEmail'                      app/Listeners/RecordOutgoingEmail.php
check "\$table->ulid('ulid')->unique();"               database/migrations/2026_09_11_001500_create_email_logs_table.php
check 'function verificationUrl('                      app/Notifications/QueuedVerifyEmail.php
check 'class SendQueuedNotifications'                  vendor/laravel/framework/src/Illuminate/Notifications/SendQueuedNotifications.php
check 'class FileUploadConfiguration'                  vendor/livewire/livewire/src/Features/SupportFileUploads/FileUploadConfiguration.php
check 'Schedule::'                                     routes/console.php
check '<svg'                                           public/images/icons/badge.svg
check 'redeploy the previous successful build'         docs/runbooks/deploy-production.md
# --- Task 6: reviewer affiliation
check '->profile()'                                    app/Providers/Filament/ReviewerPanelProvider.php
check 'class ConferenceReviewerPolicy'                 app/Policies/ConferenceReviewerPolicy.php
check 'class AutoAssignReviewers'                      app/Actions/Reviews/AutoAssignReviewers.php
check "'affiliation'"                                  database/migrations
printf '\n%d missing\n' "$missing"
exit "$(( missing > 0 ))"
```

```bash
cd /c/Users/ahmed/Documents/CASS && bash /tmp/plan7symbols.sh > /tmp/plan7symbols.log 2>&1; echo "symbols rc=$?"; tail -3 /tmp/plan7symbols.log
```

Expected: `symbols rc=0` and `0 missing`.

- [ ] **Step 2: Write the failing tests**

`tests/Feature/PanelThemeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Until Plan 7 every panel loaded only Filament's precompiled stylesheet,
 * which holds fi-* component classes and no general utilities - so the Plan 1
 * dashboard banners' `rounded-xl border bg-amber-50 p-4` rendered as plain
 * text, and every later panel view fell back to inline styles. One theme, built
 * by Vite from Filament's own sources plus the panels' views, serves all three.
 */
it('gives all three panels the one vite-built theme', function (string $panel) {
    expect(Filament::getPanel($panel)->getViteTheme())->toBe('resources/css/filament/theme.css');
})->with(['admin', 'organizer', 'reviewer']);

it('links the compiled theme under the request nonce instead of the precompiled stylesheet', function (string $url) {
    expect(is_file(public_path('hot')))->toBeFalse('public/hot exists: stop `npm run dev` (composer run dev) before running the suite - the panels then link the dev server, not public/build.');

    $response = get($url)->assertOk();

    preg_match("/'nonce-([A-Za-z0-9]{40})'/", (string) $response->headers->get('Content-Security-Policy'), $matches);
    $nonce = $matches[1] ?? '';
    $html = (string) $response->getContent();

    // style-src is 'self' plus the nonce, so a same-origin <link> would load
    // either way; the nonce is Vite::useCspNonce() reaching the tag for free,
    // and asserting it pins that the theme came through Laravel's Vite.
    expect($nonce)->not->toBe('')
        ->and($html)->toMatch('#<link rel="stylesheet" href="[^"]*/build/assets/theme-[A-Za-z0-9_-]+\.css" nonce="'.$nonce.'"#')
        // Filament's default theme is replaced, not added to: two copies of
        // Filament's CSS on one page would be 600 KB loaded twice.
        ->and($html)->not->toContain('css/filament/filament/app.css');
})->with(['/admin/login', '/org/login', '/review/login']);

it('compiles every utility class the organizer dashboard uses into the theme', function () {
    // Read the build itself: this is what fails when the theme's @source
    // stops reaching resources/views/filament - the page would still render,
    // just unstyled, and no other test would notice.
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $entry = $manifest['resources/css/filament/theme.css']['file'] ?? null;

    expect($entry)->not->toBeNull('The theme is not in public/build/manifest.json - run `npm run build`.');

    $css = (string) file_get_contents(public_path('build/'.$entry));
    $view = (string) file_get_contents(resource_path('views/filament/organizer/pages/dashboard.blade.php'));

    preg_match_all('/\bclass="([^"]+)"/', $view, $attributes);
    $classes = array_unique(preg_split('/\s+/', trim(implode(' ', $attributes[1]))) ?: []);
    $missing = [];

    foreach ($classes as $class) {
        // The selector as Tailwind writes it: `dark:bg-amber-950` is
        // `.dark\:bg-amber-950`, followed by a brace, a pseudo or a comma.
        $selector = '.'.addcslashes($class, ':/.[]%');

        if (! preg_match('/'.preg_quote($selector, '/').'(?=[{:,\s)])/', $css)) {
            $missing[] = $class;
        }
    }

    expect($classes)->not->toBe([])
        ->and($missing)->toBe([]);
});

it('styles the dashboard welcome card with theme classes rather than style attributes', function () {
    $organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $owner = User::factory()->create();
    $organization->addMember($owner, OrganizationRole::Owner);

    actingAs($owner)->get("/org/{$organization->slug}")
        ->assertOk()
        ->assertSee('<p class="font-semibold">', escape: false)
        ->assertDontSee('style="font-weight:600"', escape: false);

    // The view itself carries no style attribute at all any more: the three
    // states (pending, suspended, welcome) are all utilities now.
    expect((string) file_get_contents(resource_path('views/filament/organizer/pages/dashboard.blade.php')))
        ->not->toContain('style=');
});

it('does not render one organization\'s dashboard for a member of another', function () {
    $mine = Organization::factory()->approved()->create();
    $theirs = Organization::factory()->create(['name' => 'Beta Society']);
    $user = User::factory()->create();
    $mine->addMember($user, OrganizationRole::Owner);

    // Theirs is pending, so its dashboard is the amber banner. A 404, not the
    // banner with another organization's name in it.
    actingAs($user)->get("/org/{$theirs->slug}")
        ->assertNotFound()
        ->assertDontSee('Beta Society');
});
```

`tests/Unit/DockerAssetsStageTest.php` — in `tests/Unit` because it touches no database (`tests/Pest.php:22` gives `Unit` the application without `RefreshDatabase`):

```php
<?php

declare(strict_types=1);

/**
 * The production image compiles its stylesheets in the Dockerfile's `assets`
 * stage, which sees only what that stage COPYs. Tailwind does not fail when a
 * directory named by an @source line is missing - it scans nothing there and
 * builds green - so a stylesheet that reads a directory the stage never copied
 * ships without every class used only in it, in production and nowhere else.
 * Plan 6 found app/Livewire in exactly that state (backlog, "The production
 * image builds its CSS without app/Livewire in scope").
 *
 * These cases read the Dockerfile and the stylesheets as text, so they run in
 * the ordinary suite with no Docker. The CI `smoke` job is what proves the
 * image itself.
 */

/**
 * Every stage of the Dockerfile in order, as its name => its instructions,
 * with `\` continuations joined and comments dropped.
 *
 * @return array<string, list<string>>
 */
function dockerfileStages(): array
{
    $source = (string) preg_replace('/\\\\\R\s*/', ' ', (string) file_get_contents(base_path('Dockerfile')));
    $stages = [];
    $current = null;

    foreach (preg_split('/\R/', $source) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (preg_match('/^FROM\s+\S+\s+AS\s+(\S+)$/i', $line, $match)) {
            $current = $match[1];
            $stages[$current] = [];

            continue;
        }

        if ($current !== null) {
            $stages[$current][] = $line;
        }
    }

    return $stages;
}

/** `resources/css/../../app/Livewire` => `app/Livewire`. */
function repositoryPath(string $path): string
{
    $segments = [];

    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..') {
            array_pop($segments);

            continue;
        }

        $segments[] = $segment;
    }

    return implode('/', $segments);
}

/**
 * The stylesheets in vite.config.js's `input` - the files `npm run build`
 * compiles.
 *
 * @return list<string>
 */
function viteStylesheets(): array
{
    preg_match('/\binput\s*:\s*\[([^\]]*)\]/', (string) file_get_contents(base_path('vite.config.js')), $input);
    preg_match_all("/['\"]([^'\"]+\.css)['\"]/", $input[1] ?? '', $stylesheets);

    return $stylesheets[1];
}

/**
 * What the assets stage has under its WORKDIR when `npm run build` runs: one
 * entry per single-source COPY before that RUN, as destination => origin
 * ('context', or the stage it was copied from).
 *
 * @return array<string, string>
 */
function assetsStageInputs(): array
{
    $inputs = [];

    foreach (dockerfileStages()['assets'] ?? [] as $instruction) {
        if (preg_match('/^RUN\s+npm run build\b/', $instruction)) {
            break;
        }

        if (! preg_match('/^COPY\s+(.+)$/i', $instruction, $match)) {
            continue;
        }

        $origin = 'context';
        $paths = [];

        foreach (preg_split('/\s+/', $match[1]) ?: [] as $word) {
            if (preg_match('/^--from=(\S+)$/', $word, $from)) {
                $origin = $from[1];
            } elseif (! str_starts_with($word, '--')) {
                $paths[] = $word;
            }
        }

        // `COPY package*.json vite.config.js ./` drops files into the WORKDIR
        // and reads no directory a stylesheet could; only `COPY <one> <dest>`
        // puts a tree on a path.
        if (count($paths) === 2) {
            $inputs[repositoryPath($paths[1])] = $origin;
        }
    }

    return $inputs;
}

/**
 * Every repository path a compiled stylesheet reads outside node_modules: the
 * relative @import targets and the @source roots, resolved against the
 * stylesheet's own directory.
 *
 * @return array<string, string> path => the stylesheet that reads it
 */
function stylesheetReads(): array
{
    $reads = [];

    foreach (viteStylesheets() as $stylesheet) {
        preg_match_all("/@(?:import|source)\s+['\"](\.[^'\"]+)['\"]/", (string) file_get_contents(base_path($stylesheet)), $paths);

        foreach ($paths[1] as $path) {
            // Up to the first glob: '../../../app/Filament/**/*' reads the
            // directory app/Filament.
            $path = (string) preg_replace('#/?[*{].*$#', '', $path);

            $reads[repositoryPath(dirname($stylesheet).'/'.$path)] = $stylesheet;
        }
    }

    return $reads;
}

it('builds the vendor stage before the assets stage', function () {
    $order = array_keys(dockerfileStages());

    // COPY --from=<name> can only name an EARLIER stage. BuildKit refuses the
    // other order outright: 'cannot copy from stage "vendor", it needs to be
    // defined before current stage "assets"'.
    expect($order)->toContain('vendor')
        ->and($order)->toContain('assets')
        ->and(array_search('vendor', $order, true))->toBeLessThan(array_search('assets', $order, true));
});

it('takes vendor/filament into the assets stage from the vendor stage', function () {
    // Not from the context: .dockerignore excludes vendor, so a context COPY
    // of it fails with '"/vendor/filament": not found' - and the panel theme
    // @imports Filament's own CSS out of it.
    expect(assetsStageInputs())->toHaveKey('vendor/filament')
        ->and(assetsStageInputs()['vendor/filament'] ?? null)->toBe('vendor')
        ->and((string) file_get_contents(base_path('.dockerignore')))->toMatch('/^vendor$/m');
});

it('copies every directory a stylesheet reads into the assets stage before it builds', function () {
    $inputs = assetsStageInputs();
    $reads = stylesheetReads();
    $missing = [];

    expect($reads)->not->toBe([]);

    foreach ($reads as $path => $stylesheet) {
        $covered = false;

        foreach (array_keys($inputs) as $copied) {
            if ($path === $copied || str_starts_with($path, $copied.'/')) {
                $covered = true;

                break;
            }
        }

        if (! $covered) {
            $missing[] = "{$path} (read by {$stylesheet})";
        }
    }

    expect($missing)->toBe([]);
});

it('scans only the sources a stylesheet names, so a checkout and the image build the same file', function () {
    // Without source(none), Tailwind's automatic detection scans from the
    // project root: the whole repository on a checkout, and only what the
    // assets stage copied in the image - so the case above would be blind to
    // every directory nobody wrote an @source line for. The panel theme gets
    // source(none) from Filament's own theme.css, which it @imports.
    $automatic = [];

    foreach (viteStylesheets() as $stylesheet) {
        $css = (string) file_get_contents(base_path($stylesheet));

        preg_match_all("/@import\s+['\"](\.[^'\"]+\.css)['\"]/", $css, $imports);

        foreach ($imports[1] as $import) {
            // "\n" first: a stylesheet saved without a final newline must not
            // glue the import's first line onto its own last one.
            $css .= "\n".(string) file_get_contents(base_path(repositoryPath(dirname($stylesheet).'/'.$import)));
        }

        // ^ with /m: a line that starts with @import, never the docblock's
        // ` * ... @import 'tailwindcss' source(none)` prose.
        if (! preg_match("/^@import\s+['\"]tailwindcss['\"]\s+source\(none\)/m", $css)) {
            $automatic[] = $stylesheet;
        }
    }

    expect(viteStylesheets())->not->toBe([])
        ->and($automatic)->toBe([]);
});
```

The docblock says "a directory named by an @source line" rather than starting a line with `@source`: Pint's `phpdoc_separation` reads a line-initial `@source` as a PHPDoc tag and splits the paragraph.

Append to `tests/Browser/CspTest.php`, after its last case (`renders a panel login with filament own inline scripts intact`). It is in the browser suite rather than `PanelThemeTest` because only a browser applies the policy: a feature test can see the `<link>` and its nonce, but not whether the stylesheet was allowed to load.

```php

it('applies a panel theme utility on a panel page under the policy', function () {
    // Plan 7 Task 1: the panels load resources/css/filament/theme.css as a
    // Vite <link> carrying the request nonce. If style-src refused it, or the
    // panel were still on Filament's precompiled stylesheet - which has no
    // general utilities - this class would compute to nothing. bg-amber-50 is
    // in the theme because the organizer dashboard's pending banner uses it.
    $background = visit('/org/login')->assertSee('CASS')->script(<<<'JS'
(() => {
    const probe = document.createElement('div');
    probe.className = 'bg-amber-50';
    document.body.appendChild(probe);

    return getComputedStyle(probe).backgroundColor;
})()
JS);

    expect($background)->not->toBe('rgba(0, 0, 0, 0)');
})->group('browser');
```

- [ ] **Step 3: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact tests/Feature/PanelThemeTest.php tests/Unit/DockerAssetsStageTest.php > /tmp/t-task-1.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-1.log
```

Expected: `rc=1`, **12 failed, 1 passed**. Read the reasons, because each is one step below:

- `gives all three panels…` ×3 — `Failed asserting that null is identical to 'resources/css/filament/theme.css'` (Step 5).
- `links the compiled theme…` ×3 — the login page has no `theme-*.css` link and does contain `css/filament/filament/app.css` (Steps 4-5).
- `compiles every utility class…` — ``The theme is not in public/build/manifest.json - run `npm run build`.`` (Steps 4 and 10).
- `styles the dashboard welcome card…` — `<p class="font-semibold">` is not on the page (Step 8).
- `builds the vendor stage before…` — `Failed asserting that 1 is less than 0` (Step 7).
- `takes vendor/filament into…` — `Failed asserting that an array has the key [vendor/filament]` (Step 7).
- `copies every directory…` — `+ 0 => 'app/Livewire (read by resources/css/app.css)'`: **the backlog bug, stated by the suite** (Step 7).
- `scans only the sources…` — `+ 0 => 'resources/css/app.css'` (Step 6).

The one that passes is the cross-tenant case, which guards behaviour that already exists.

Then the browser case, which needs Playwright's Chromium (`npx playwright install chromium` once) and runs against the `public/build` already on disk:

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pest --configuration=phpunit.browser.xml > /tmp/t-browser-task-1.log 2>&1; echo "browser rc=$?"; tail -5 /tmp/t-browser-task-1.log
```

Expected: `browser rc=1`, **1 failed, 4 passed** — `applies a panel theme utility…` with `Expecting 'rgba(0, 0, 0, 0)' not to be 'rgba(0, 0, 0, 0)'`: Filament's precompiled stylesheet has no `.bg-amber-50` (fact 1.8). Failing-first gate.

- [ ] **Step 4: The theme, and the Vite input**

Create `resources/css/filament/theme.css` (decisions 1 and 2):

```css
/*
 * The one theme for all three panels (admin, organizer, reviewer), each of
 * which registers it with ->viteTheme('resources/css/filament/theme.css').
 *
 * Written from vendor/filament/filament/stubs/ThemeCss.stub by hand rather
 * than by `php artisan make:filament-theme`, which also runs
 * `npm install tailwindcss@latest @tailwindcss/vite --save-dev` and would move
 * package.json in a commit about a stylesheet.
 *
 * Filament's theme.css imports Tailwind with source(none), so
 * nothing is scanned automatically: the two @source lines are the whole of
 * where a panel utility class can come from. A class written anywhere else -
 * resources/views/brand, a public view, app/Livewire - is not in this file.
 *
 * The Dockerfile's assets stage copies vendor/filament from the vendor stage
 * and app/Filament from the context for these three lines;
 * tests/Unit/DockerAssetsStageTest.php fails if it stops.
 */
@import '../../../vendor/filament/filament/resources/css/theme.css';

@source '../../../app/Filament/**/*';
@source '../../../resources/views/filament/**/*';
```

`vite.config.js:8`:

```js
            input: ['resources/css/app.css', 'resources/js/app.js'],
```

becomes

```js
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/theme.css'],
```

- [ ] **Step 5: Register the theme on the three panels**

One line in each provider, directly after `->path(...)` — where `make:filament-theme` itself would put it (fact 1.2). The same path in all three (decision 1).

`app/Providers/Filament/AdminPanelProvider.php`:

```php
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
```

becomes

```php
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/theme.css')
            ->login(Login::class)
```

`app/Providers/Filament/OrganizerPanelProvider.php`:

```php
            ->id('organizer')
            ->path('org')
            ->login(Login::class)
```

becomes

```php
            ->id('organizer')
            ->path('org')
            ->viteTheme('resources/css/filament/theme.css')
            ->login(Login::class)
```

`app/Providers/Filament/ReviewerPanelProvider.php`:

```php
            ->id('reviewer')
            ->path('review')
            ->login(Login::class)
```

becomes

```php
            ->id('reviewer')
            ->path('review')
            ->viteTheme('resources/css/filament/theme.css')
            ->login(Login::class)
```

**Do not request a panel page between this step and Step 10.** Until `npm run build` has written the theme into `public/build/manifest.json`, every panel page is a 500 (fact 1.7, decision 8).

- [ ] **Step 6: The public stylesheet names its sources**

`resources/css/app.css:1`:

```css
@import 'tailwindcss';
```

becomes

```css
/*
 * source(none): Tailwind scans the @source lines below and nothing else.
 * Its automatic detection starts from the build's working directory and
 * honours .gitignore, so a checkout scanned the whole repository while the
 * image's assets stage scanned whatever that stage had copied - one commit,
 * two different stylesheets, and a class used only in an unscanned directory
 * missing from production alone. Named roots build the same file in both
 * places; tests/Unit/DockerAssetsStageTest.php keeps the stage copying them.
 */
@import 'tailwindcss' source(none);
```

and `resources/css/app.css:9-10`:

```css
@source '../views';
@source '../../app/Livewire';
```

becomes

```css
@source '../views';
@source '../js';
@source '../../app/Livewire';
```

`../js` because `countdown.js:66` sets classes from a script (fact 1.13). Nothing else in the file changes.

- [ ] **Step 7: The Dockerfile — `vendor` first, and an `assets` stage that sees what it builds**

`Dockerfile:15-28`:

```dockerfile
FROM node:24-alpine@sha256:50c8e8ca1d27439048670df5883f32d57cf81cff6233222c893fd0d9884cbd81 AS assets
WORKDIR /build
COPY package*.json vite.config.js ./
RUN npm ci
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM composer:2@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
# intl and gd are installed in the runtime stage; the platform check is waived only here.
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress \
    --ignore-platform-req=ext-intl --ignore-platform-req=ext-gd
```

becomes

```dockerfile
FROM composer:2@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
# intl and gd are installed in the runtime stage; the platform check is waived only here.
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress \
    --ignore-platform-req=ext-intl --ignore-platform-req=ext-gd

# After `vendor`, because COPY --from names an EARLIER stage: the panel theme
# (resources/css/filament/theme.css) @imports Filament's own CSS out of
# vendor/filament, and .dockerignore keeps vendor out of the build context.
# Every directory a stylesheet @sources is copied in too - Tailwind scans a
# missing one as empty and builds green, so a class used only there would be
# absent in production and present everywhere else: app/Livewire for
# resources/css/app.css, app/Filament for the theme.
# tests/Unit/DockerAssetsStageTest.php holds this list to the @source lines.
# npm ci runs before any of it, so its layer survives a PHP-only change.
FROM node:24-alpine@sha256:50c8e8ca1d27439048670df5883f32d57cf81cff6233222c893fd0d9884cbd81 AS assets
WORKDIR /build
COPY package*.json vite.config.js ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY app/Filament ./app/Filament
COPY app/Livewire ./app/Livewire
COPY --from=vendor /build/vendor/filament ./vendor/filament
RUN npm run build
```

The digest-pinning comment at `:6-14` stays where it is, above the first `FROM`, which is now `vendor`. The `runtime` stage does not change: it still copies `/build/vendor` and `/build/public/build` (`:45-46` before this edit), and still runs `php artisan filament:assets`, which publishes Filament's JavaScript and fonts as well as the default `app.css` the smoke job curls (fact 1.17). `.dockerignore` does not change (decision 4).

- [ ] **Step 8: The dashboard, and the two comments and two documents the theme makes stale**

`resources/views/filament/organizer/pages/dashboard.blade.php:16-26` — the banners above it are untouched, because their classes now work (decision 7):

```blade
        {{-- <x-filament::section>, not Tailwind utilities: a panel loads only
             Filament's precompiled CSS, which has no general utilities, so
             `rounded-xl border bg-white p-4` renders as nothing (the existing
             pending and suspended banners above have the same problem - see
             the backlog item about an organizer panel theme). --}}
        <x-filament::section>
            <p style="font-weight:600">{{ __('public.dashboard.welcome_title', ['organization' => $this->getOrganization()->name]) }}</p>
            <p style="margin-top:0.25rem;font-size:0.875rem">
                {{ __('public.dashboard.welcome_body') }}
            </p>
        </x-filament::section>
```

becomes

```blade
        {{-- Utilities work in the panels since Plan 7: every panel loads
             resources/css/filament/theme.css, whose @source reaches this
             directory. The two banners above were written that way in Plan 1
             and rendered unstyled until then. --}}
        <x-filament::section>
            <p class="font-semibold">{{ __('public.dashboard.welcome_title', ['organization' => $this->getOrganization()->name]) }}</p>
            <p class="mt-1 text-sm">
                {{ __('public.dashboard.welcome_body') }}
            </p>
        </x-filament::section>
```

`resources/views/brand/logo.blade.php:4-8` — comment only; the markup and the nonced `<style>` stay exactly as they are:

```blade
    Everything is inline-styled on purpose. This view is handed to Filament as
    the panels' brand logo, and the panels compile their CSS from Filament's own
    sources - `@source '../views'` in resources/css/app.css only reaches the
    public site's stylesheet, so a Tailwind class written here would render
    unstyled inside /admin and /org.
```

becomes

```blade
    Everything is inline-styled on purpose. This one view is styled by two
    stylesheets: the public layout's (resources/css/app.css) and, as the
    brand logo, the panels' (resources/css/filament/theme.css, whose @source
    stops at resources/views/filament). The two also disagree about `dark:` -
    the panels' is Filament's `.dark` class, the public site's is the
    operating system's preference - so a utility here would mean different
    things on the two sides. An inline style means the same thing in both.
```

`resources/views/filament/organizer/resources/conferences/pages/short-link.blade.php:31-39` — comment only:

```blade
        {{--
            Inline styles, not Tailwind utilities: a Filament panel loads only
            Filament's precompiled CSS, which contains theme variables and
            fi-* component classes and no general utilities, and this panel has
            no custom theme (`->viteTheme()`). `flex`, `h-24`, `items-end` and
            `bg-primary-500` would all be no-ops here, so the spec 5.7
            sparkline would render as invisible full-width blocks.
            `--primary-500` is a real colour value Filament emits on the page.
        --}}
```

becomes

```blade
        {{--
            Inline styles. They were the only option until Plan 7 gave the
            panels a theme (resources/css/filament/theme.css); utilities work
            here now, but each bar's height is data, which only a style
            attribute can carry, so the sparkline stays as it is.
            `--primary-500` is a real colour value Filament emits on the page.
        --}}
```

All three are Blade comments, which `Blade::compileString()` removes, so `LanguageCoverageTest`'s Plan 6 cases (the dashboard and the short-link page are both in `$plan6Sources`) see no new text.

`docs/runbooks/deploy-production.md`, "Every release" step 4 — the standing check still names the stylesheet the panels stop loading (`PanelThemeTest` asserts it is absent), so an operator chasing an unstyled panel would see it return 200 and move on. In that step's paragraph, replace:

```markdown
so `/css/filament/filament/app.css` and `/vendor/livewire/livewire.min.js` should both return 200.
```

with:

```markdown
so `/vendor/livewire/livewire.min.js` should return 200, and so must the `/build/assets/theme-<hash>.css` that the page source links: since Plan 7 that is the panels' only stylesheet. `/css/filament/filament/app.css` is still published, but no panel loads it.
```

The rest of the step, the Cloudflare purge list included, stays as it is.

**CLAUDE.md is the owner's session guide: the orchestrator confirms this one-line change with the owner before Task 1 runs.** If the owner has not confirmed it, skip the CLAUDE.md block below, drop `CLAUDE.md` from this task's `git add` list, and leave the rule in decision 8 and the pull-request body.

`CLAUDE.md`, under **Commands** — the test command, because decision 8's rule now holds for every session, not only this plan's. Replace the line:

```markdown
- Tests: `php artisan test` (SQLite in-memory). MySQL suite: `docker compose -f docker-compose.dev.yml up -d` then `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass php artisan test`.
```

with:

```markdown
- Tests: `npm run build` first, then `php artisan test` (SQLite in-memory). Public pages render `@vite`, and since Plan 7 every panel page asks the manifest for `resources/css/filament/theme.css`, so a missing or stale `public/build` fails with "Unable to locate file in Vite manifest". Stop `npm run dev` first: with `public/hot` present the panels link the dev server instead. MySQL suite: `docker compose -f docker-compose.dev.yml up -d` then `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass php artisan test`.
```

Nothing else in either file changes.

- [ ] **Step 9: Five lines in the smoke job**

`.github/workflows/ci.yml`, at the end of the `smoke` job's `Assertions` step (decision 5). Anchor — the last three lines of that step today:

```yaml
          # Every inline script and style in that page carries the same nonce
          # the header does - a mismatch is a panel with no JavaScript at all.
          grep -q "nonce=\"$NONCE\"" /tmp/login
```

becomes

```yaml
          # Every inline script and style in that page carries the same nonce
          # the header does - a mismatch is a panel with no JavaScript at all.
          grep -q "nonce=\"$NONCE\"" /tmp/login
          # The panel theme, built in the image's assets stage: the login page
          # links it under the same nonce, nginx serves it, and it carries a
          # utility only a panel view uses (the organizer dashboard's dark-mode
          # banner). An assets stage without vendor/filament fails the build
          # before this line; one whose theme stopped reaching the panel views
          # builds green and fails here.
          THEME=$(grep -oE '/build/assets/theme-[A-Za-z0-9_-]+\.css' /tmp/login | head -1)
          test -n "$THEME"
          grep -qE "href=\"[^\"]*$THEME\" nonce=\"$NONCE\"" /tmp/login
          curl -sf -o /tmp/theme.css -H 'Host: localhost' "http://127.0.0.1:8080$THEME"
          grep -qF '.dark\:bg-amber-950' /tmp/theme.css
```

These lines were run, under `sh -e`, against the prototype served by `php -S` with the built assets: all passed, `THEME=/build/assets/theme-CRKH5aRV.css`.

- [ ] **Step 10: Build, then run the tests**

```bash
cd /c/Users/ahmed/Documents/CASS && npm run build > /tmp/build-task-1.log 2>&1; echo "build rc=$?"; grep -E "theme-|app-.*css" /tmp/build-task-1.log && \
php artisan test --compact tests/Feature/PanelThemeTest.php tests/Unit/DockerAssetsStageTest.php > /tmp/t-task-1.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-1.log
```

Expected: `build rc=0`, two lines like `public/build/assets/app-Cd8xzj_N.css  49.65 kB` and `public/build/assets/theme-CRKH5aRV.css  621.76 kB` (the hashes are content hashes: different bytes, different names), then `rc=0`, **13 passed**.

Then the tests most likely to notice a stylesheet change, because they render panels and read the nonce:

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact tests/Feature/Public/SecurityHeadersTest.php tests/Feature/Security/PublishedFilamentViewsTest.php tests/Feature/BrandAssetsTest.php tests/Feature/Organizer/PanelAccessTest.php tests/Feature/LanguageCoverageTest.php > /tmp/t-task-1b.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-1b.log
```

Expected: `rc=0`, **55 passed** — the same five files and the same count as on the baseline, in particular `SecurityHeadersTest`'s `style-src-attr 'unsafe-inline'` case and `PublishedFilamentViewsTest`'s three hashes, which this task must not move (decision 6).

And the browser suite, against the build just made:

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pest --configuration=phpunit.browser.xml > /tmp/t-browser-task-1.log 2>&1; echo "browser rc=$?"; tail -5 /tmp/t-browser-task-1.log
```

Expected: `browser rc=0`, **5 passed** — the four that were there, including `renders a panel login with filament own inline scripts intact`, and the new one: the theme loads under the enforced policy and its utilities apply.

- [ ] **Step 11: Prove the image builds the same stylesheets as the checkout**

Docker 29 is on the development machine, so check the `assets` stage directly rather than waiting for CI. The comparison is by content hash only, so it does not depend on how `sha256sum` prints a filename on Windows; `MSYS_NO_PATHCONV=1` stops Git Bash rewriting the in-container paths.

```bash
cd /c/Users/ahmed/Documents/CASS && docker build --target assets -t cass:assets . > /tmp/docker-assets.log 2>&1; echo "docker rc=$?" && \
MSYS_NO_PATHCONV=1 docker run --rm cass:assets sh -c 'cd /build/public/build/assets && sha256sum app-*.css theme-*.css | cut -d" " -f1' > /tmp/image-css.txt && \
(cd public/build/assets && sha256sum app-*.css theme-*.css | cut -d' ' -f1) > /tmp/local-css.txt && \
diff /tmp/local-css.txt /tmp/image-css.txt > /dev/null; echo "same-css rc=$?" && \
MSYS_NO_PATHCONV=1 docker run --rm cass:assets sh -c "grep -c 'position:static' /build/public/build/assets/app-*.css; grep -c 'dark\\\\:bg-amber-950' /build/public/build/assets/theme-*.css"
```

Expected: `docker rc=0`, `same-css rc=0`, then `1` and `1`. The first `1` is `.static` — one of the three rules only `app/Livewire` produces (fact 1.12), so `app/Livewire` is in the image's scope; the second is a utility only the organizer dashboard uses, so the theme reached `resources/views/filament`. In the prototype both hashes matched (`1b7120d9a98ab5aa…`, `8fcc2d1ce3b25841…`). **`same-css rc=1` is a finding, not noise**: diff the two rule sets (`tr '}' '\n'` on each file, `sort -u`, `comm -3`) before going further — it means the stage and the checkout scan different trees again. If Docker Desktop is not running, skip this step; the CI `smoke` job (Step 9) is the gate either way.

- [ ] **Step 12: Pint, Larastan, the whole suite, and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint --test > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?" && \
php artisan test > /tmp/all-task-1.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-1.log
```

Expected: `pint rc=0`, `stan rc=0`, `all rc=0`, **baseline + 13** (1229 passed, 1 skipped, on the 1216 baseline that includes the hotfix). The prototype, on `45d2d8e` without the hotfix, measured 1227 on 1214. The browser case is the fourteenth test this task adds and is not in that count: `tests/Browser` is outside `phpunit.xml`, and CI's `browser` job runs it.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test > /tmp/all-task-1.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add resources/css/filament/theme.css resources/css/app.css vite.config.js \
  app/Providers/Filament/AdminPanelProvider.php app/Providers/Filament/OrganizerPanelProvider.php app/Providers/Filament/ReviewerPanelProvider.php \
  Dockerfile .github/workflows/ci.yml resources/views/filament/organizer/pages/dashboard.blade.php resources/views/brand/logo.blade.php \
  resources/views/filament/organizer/resources/conferences/pages/short-link.blade.php docs/runbooks/deploy-production.md CLAUDE.md \
  tests/Feature/PanelThemeTest.php tests/Unit/DockerAssetsStageTest.php tests/Browser/CspTest.php && \
git commit -q -F - <<'EOF' && git log --oneline -1 && git status --short --untracked-files=no
feat(panels): one Vite-built theme for all three panels, and an assets stage that sees what it builds

Tailwind utilities now work in panel views: admin, organizer and reviewer
load resources/css/filament/theme.css instead of Filament's precompiled
stylesheet, so the Plan 1 dashboard banners render as they were written.
The Dockerfile builds the vendor stage first so the assets stage can copy
vendor/filament, and copies app/Filament and app/Livewire beside it. The
public stylesheet now scans only the sources it names, so a checkout and
the image compile byte-identical CSS. The CSP header does not change.
CLAUDE.md's test command now builds first, and the runbook's release
check names the theme instead of Filament's precompiled app.css.

<the session's Co-Authored-By trailer>
EOF
```

Replace the last line of the message with the session's Co-Authored-By trailer before running it. Expected: `all rc=0 - the suite gates this commit`, one log line, and nothing from `git status --short --untracked-files=no`. The paths are named rather than `-A`, so an untracked file on the machine (`.claude/settings.local.json`, a per-task plan split) cannot ride along; if `git status` lists a tracked file, this task changed something its **Files** list does not name — stop and find out why.

---


### Task 2: The backlog's language sweep

Two backlog entries, the same item written twice — once when Plan 5 found it and once when Plan 6 narrowed it. The Decisions section:

> **The language sweep still stops at the file boundary.** `LanguageCoverageTest` proves the fourteen Plan 5 source files, but `App\Enums\Decision::getLabel()` (every enum from Plans 1-5 is the same), `App\Support\Scoring\RankingRows::headers()` and the columns of `app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php` are hardcoded English and no existing backlog item mentions any of them — the ones that are there cover the Plans 1-2 Blade views, the countdown script and the poster template. Extract enums, export headings and the admin tables together, with those views, before any Arabic work. `Decision::getLabel()` is an author-facing string (it is `{{decision}}`'s value in every decision letter), so it moves with the bilingual-templates item of spec section 14 rather than with the panel strings.

and the Launch section, which is the current list:

> **The language sweep still stops at the file boundary.** Plan 6 finished the Plans 1-2 public views, the countdown script and the poster template, so every Blade file a visitor can reach is translated. Still hardcoded English, and still outside every `$planNSources` list: every enum's `getLabel()`, `App\Support\Scoring\RankingRows::headers()` and Plan 3's `ExportSubmissionsCsv::HEADERS`, the columns of the four admin tables, and the `blockers()` sentences in `PublishConference` (the newer actions use `lang/` keys; that one predates the convention). Extract them together before any Arabic work. `Decision::getLabel()` is author-facing — it is `{{decision}}`'s value in every decision letter — so it moves with the bilingual-templates item of spec section 14 rather than with the panel strings.

This task closes both, **except `Decision::getLabel()`**, which stays English, is pinned by a test as the one exception, and stays in the backlog with the bilingual templates. It also creates `$plan7Sources` in `tests/Feature/LanguageCoverageTest.php`, which Tasks 3 to 6 append to.

**Read this before you trust the title.** This is the last sweep of *the backlog's list*. It is not the last English in the application: fact 2.16 measures what is left with this task's own sweep — about 340 visible literals in about 40 PHP files that no backlog line names, nine of those files on the public site. Decision 11 says why they are not converted here and gives Task 7 the backlog entry that replaces the two above.

**Facts this task relies on.** Every one was read on 2026-10-02 against `main` at `45d2d8e`.

2.1. **Installed versions, from `composer.lock`:** `filament/filament` v5.8.1 (`:1277-1278`), `laravel/framework` v13.32.0 (`:2327-2328`), `livewire/livewire` v4.4.4 (`:3481-3482`), `pestphp/pest` v5.1.4 (`:11098-11099`).

2.2. **The "file boundary" is a `.blade.php` test, four times over.** Every visible-English case in `tests/Feature/LanguageCoverageTest.php` reads Blade only: Plan 3's skips any other file at `:106`, Plan 4's at `:247`, Plan 5's at `:332` (`if (! str_ends_with($relative, '.blade.php')) { continue; }`), and Plan 6's reads `$plan6Views` (`:413-415`), which is Blade by construction. The one case that reads `app/` at all (`:524-577`) derives its namespaces from `lang/en` (`:536-542`) and checks that every `__()` key **resolves** — it never looks for English that is not in a `__()` call. So a PHP class in a `$planNSources` list has its keys checked and its hardcoded English ignored, and no case anywhere reads a PHP file for English — which is how seventeen enums, two export headings and three admin tables have passed every sweep. The file is 577 lines; its imports are `:5-9`.

2.3. **Seventeen enums implement Filament's `HasLabel`, and all seventeen spell their label out.** Fifteen use a `match` with literal arms — `ConferenceStatus.php:19-29`, `CustomFieldType.php:17-26`, `Decision.php:45-53`, `EmailLogStatus.php:16-23`, `EmailTemplateKey.php:32-48`, `InvitationStatus.php:22-30`, `OrganizationType.php:17-26`, `PosterSize.php:14-20`, `PresentationPreference.php:20-27`, `ReminderThreshold.php:25-33`, `ReviewMode.php:14-20`, `ReviewQuestionType.php:16-24`, `ReviewStatus.php:15-21`, `ReviewerStatus.php:15-21`, `SubmissionStatus.php:27-38` — and two return `ucfirst($this->value)`: `OrganizationRole.php:15-18` and `OrganizationStatus.php:16-19`. Without `Decision` that is **67 cases**. Two enums have no label at all: `DemoStage` (`DemoStage.php:19`, `enum DemoStage: string`) and `SubmissionWindow` (`SubmissionWindow.php:7`).

2.4. **`Decision::getLabel()` is `{{decision}}` in every decision letter** (`app/Actions/Decisions/SendOneDecisionEmail.php:322`, `'decision' => $decision->getLabel()`), and its docblock (`Decision.php:36-44`) justifies the English by saying *"for the same reason every other enum in this codebase is not"* translated. After this task that sentence is false, so the docblock is rewritten; the method is not.

2.5. **Where a label reaches a person, and that no label is ever stored or compared.** Public pages: `resources/views/livewire/public/submission-status.blade.php:26` and `:124`, `resources/views/public/conference.blade.php:9`, `resources/views/livewire/public/register-organization.blade.php:48`, `app/Livewire/Public/SubmissionForm.php:814`. Mail: `app/Notifications/MemberInvitation.php:63`, `app/Notifications/OrganizationRegistered.php:32`. Both exports: `app/Support/Scoring/RankingRows.php:55` and `:59-60`, `app/Actions/Submissions/ExportSubmissionsCsv.php:86` and `:94`. Inside sentences: the newer actions pass it as a placeholder — `'status' => mb_strtolower($conference->status->getLabel())` at `StartReviewing.php:35`, `MarkDecided.php:51`, `ApplyDecision.php:69` — and five older ones concatenate it: `PublishConference.php:58`, `CloseSubmissions.php:22`, `SubmitAbstract.php:263`, `UpdateSubmission.php:28`, `WithdrawSubmission.php:45`. `grep -rn "getLabel()" app config routes database` finds no write to a column and no comparison against a label; the one array key built from a label is `SeedDemo.php:664`, and that is `Decision`'s.

2.6. **The two export headings.** `RankingRows::headers()` (`app/Support/Scoring/RankingRows.php:27-37`) returns fourteen literals and is the heading row of both ranking files (`ExportRankingCsv.php:47`, `ExportRankingXlsx.php:52`). `ExportSubmissionsCsv` has `private const HEADERS` with sixteen (`ExportSubmissionsCsv.php:29-33`), used once (`:49`), and its `extraAnswers()` prints a checkbox answer as the bare words `'yes'` / `'no'` (`:125`). A class constant's initialiser cannot call a function, so `__()` cannot go in `HEADERS`. Nine headings appear in both files: Reference, Title, Status, Track, Presentation preference, Corresponding author, Corresponding email, All authors, Submitted at.

2.7. **`PublishConference::blockers()`** (`app/Actions/Conferences/PublishConference.php:25-62`) builds eight sentences (`:32`, `:36`, `:40`, `:43`, `:47`, `:52`, `:56`, `:58`); the last is `'A conference that is '.strtolower($conference->status->getLabel()).' cannot be opened for submissions.'`. They are shown in the publish refusal (`app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php:54-60`, `->body(implode(' ', $blockers))`) and on the conference page's checklist (`.../Schemas/ConferenceInfolist.php:36-40`, `:93-100`). Seven are asserted word for word by `tests/Unit/PublishConferenceTest.php:118-170` and `tests/Feature/Organizer/ConferenceTransitionsTest.php:75` and `:217-218`; **the eighth (`:58`) is asserted nowhere.** The newer lifecycle actions keep their blockers beside their feature: `lang/en/reviewer.php:258-271` (`start.errors`, with `wrong_status` taking `:status`) and `lang/en/decisions.php:127-139` (`mark.errors`, the same shape).

2.8. **There are ten admin tables, not four, and three of them are in English.** Five list tables — `app/Filament/Admin/Resources/*/Tables/*.php`: `ConferencesTable`, `EmailLogsTable`, `OrganizationsTable`, `ReviewsTable`, `SubmissionsTable` — and five relation managers: `Conferences/RelationManagers/ReviewersRelationManager.php`, `Organizations/RelationManagers/{InvitationsRelationManager,MembersRelationManager}.php`, `Submissions/RelationManagers/{AssignmentsRelationManager,ReviewsRelationManager}.php`. Plan 6 wrote seven of them with `admin.*` keys on every column and filter (`SubmissionsTable.php:25-103`, `ReviewsTable.php:24-81`, `ReviewersRelationManager.php:34-43`, `InvitationsRelationManager.php:36-47`, `MembersRelationManager.php:38-54`, `AssignmentsRelationManager.php:31-41`, `ReviewsRelationManager.php:37-52`). The three Plans 1-2 wrote are hardcoded: `ConferencesTable.php:33-69`, `EmailLogsTable.php:21-46`, and `OrganizationsTable.php:33-41` plus its approve and reject actions at `:51-85`. **Eight of their columns and all three of their status filters have no `->label()` at all**: `ConferencesTable.php:33` (`name`), `:36` (`status`), `:69` (filter); `EmailLogsTable.php:23` (`subject`), `:24` (`status`), `:36` (filter); `OrganizationsTable.php:33` (`name`), `:34` (`type`), `:35` (`country`), `:37` (`status`), `:41` (filter).

2.9. **A column or filter with no label prints one Filament made up, and no literal holds it.** `vendor/filament/tables/src/Columns/Concerns/HasLabel.php:28-38`: `$this->evaluate($this->label) ?? (string) str($this->getName())->beforeLast('.')->afterLast('.')->kebab()->replace(['-', '_'], ' ')->ucfirst()` — so `organization.name` prints "Organization" and `name` prints "Name". Filters do the same with `->before('.')` (`Filters/Concerns/HasLabel.php:28-37`). `TrashedFilter` labels itself from Filament's own translations (`Filters/TrashedFilter.php:19`), and a table with no empty-state heading prints `__('filament-tables::table.empty.heading', ['model' => …])` (`Table/Concerns/HasEmptyState.php:114-119`). The getters a test reads them through: `Table::getColumns()` (`Table/Concerns/HasColumns.php:112`), `getFilters()` (`HasFilters.php:174`), `getHeading()` (`HasHeader.php:43`), and `Column::getPlaceholder()` via `use HasPlaceholder` (`Columns/Column.php:56`, `vendor/filament/support/src/Concerns/HasPlaceholder.php:19`).

2.10. **Laravel hands back the key on a miss, after trying the locale and the fallback.** `vendor/laravel/framework/src/Illuminate/Translation/Translator.php:171` (`$locales = $fallback ? $this->localeArray($locale) : [$locale];`), `:189` (`return $this->makeReplacements($line ?: $key, $replace);`), `:493-497` (`localeArray()` is `[$locale ?: $this->locale, $this->fallback]`). So with the locale and the fallback both set to a name that has no `lang/` directory, every `__('x.y')` returns `'x.y'` — and anything that still returns English was never looked up.

2.11. **`lang/en/admin.php` today** has `purge` (`:11`), `submissions` (`:27`), `reviews` (`:64`), `assignments` (`:92`), `reviewers` (`:101`), `members` (`:113`), `invitations` (`:124`) and `search` (`:136`). There is no group for conferences, organizations or the email log. `admin.members` already exists (Task 4 adds to it); `admin.organization` does not (Task 3 creates it).

2.12. **What the existing suite pins, and what it does not.** Two tests assert an enum's English directly: `tests/Unit/ConferenceStatusTest.php:13` and `tests/Feature/Organizer/ConferencesOverviewWidgetTest.php:36` ("Open for submissions"). Every other label assertion compares against `->getLabel()` itself (e.g. `tests/Feature/Admin/AdminReadOnlyTest.php:148`, `tests/Feature/Organizer/MembersPageTest.php:52`, `tests/Feature/Public/SubmissionStatusPageTest.php:514-523`) and would follow any change silently. **No test asserts an export's heading row** — `tests/Feature/Organizer/ConferenceRankingExportTest.php:53-68` and `tests/Feature/Organizer/SubmissionResourceTest.php:264-289`, `:450-466` check data cells only — and none asserts the approve or reject notification text: `tests/Feature/Admin/OrganizationApprovalTest.php:63-64` and `:89-90` call `assertNotified()` with no argument.

2.13. **`EmailTemplateKey::sampleValues()` (`EmailTemplateKey.php:114-131`) is preview data, not interface.** A person's name, an abstract title, a conference and a society name, two URLs, the deadline as `SubmitAbstract.php:248` really formats it (`->format('j F Y, H:i')`, which is not localised), `Decision::AcceptedOral`'s label, and a sample rejection reason.

2.14. **`DemoStage`'s values are a command-line contract.** `cass:demo-seed {--stage=reviewing : … open, reviewing or decided}` (`app/Console/Commands/DemoSeedCommand.php:37`), parsed with `DemoStage::tryFrom()` (`:45-49`) and printed raw (`:107`). `SubmissionWindow` is turned into `submission.window.*` keys by the views that branch on it (`resources/views/public/partials/submit-cta.blade.php:16`, `:50`, `:59`).

2.15. **Test tooling this task uses.** Pest calls a `Closure` dataset argument, bound to the test, whenever the matching parameter is not typed `Closure`, `callable` or `mixed` (`vendor/pestphp/pest/src/Concerns/Testable.php:432-447`) — so a dataset row can create its owner record after `beforeEach()` has run. Filament's table-action helpers: `mountTableAction` (`vendor/filament/tables/src/Testing/TestsActions.php:22`), `setTableActionData` (`:44`), `callMountedTableAction` (`:75`), `assertTableActionHasLabel` (`:230`); and `assertMountedActionModalSee` (`vendor/filament/actions/src/Testing/TestsActions.php:512`), which reads the mounted modal's own HTML — a plain `assertSee()` does not see a modal. `OrganizationApprovalTest` fakes mail in its `beforeEach()` (`:29`).

2.16. **What is still English after this task, measured.** Run over every file in `app/`, this task's own token sweep (Step 1, `$plan7PhpProse`) reports, once the files above are converted:
   - **The organizer panel: 222 literals in 16 files** under `app/Filament/Organizer/` — `ConferenceForm` (33), `ConferenceEmailTemplates` (37), `ConferenceInfolist` (24), `ConferenceStatusActions` (19), `SubmissionInfolist` (18), `SubmissionActions` (14), `ReviewQuestionsRelationManager` (14), `SubmissionsTable` (12), `EditOrganizationProfile` (12), `ConferencesOverview` (11), `CustomFieldsRelationManager` (10), the organizer `ConferencesTable` (8), `TracksRelationManager` (5), `ConferenceShortLink` (3), `ListConferences` and `ConferenceAssignments` (1 each).
   - **What a visitor or author reads: 46 literals in 9 files.** `SubmitAbstract.php:53-111` and `:262-296`, `UpdateSubmission`, `WithdrawSubmission`, `SaveSubmissionDraft`, `SendSubmissionStatusLink` and `app/Exceptions/SubmissionFileRejected.php` reach the public form and status page through `SubmissionForm.php:330`, `:775` and `SubmissionStatus.php:104`; the page titles and throttle messages are `ContactForm.php:16`, `:42`, `RegisterOrganization.php:19`, `:74` and `AcceptInvitation.php:36`. Plan 6's "every Blade file a visitor can reach is translated" is true of Blade files and not of these.
   - **The rest of the admin panel: 37.** `ConferenceResource::infolist()` (`ConferenceResource.php:58-86`), `EmailLogInfolist`, `OrganizationInfolist`, `EmailLogResource.php:37` (`$navigationLabel = 'Email log'`) and the brand names `AdminPanelProvider.php:40` and `ReviewerPanelProvider.php:54` — plus the plural model labels Filament makes up from class names, and the purge modal, which prints raw table names (`resources/views/filament/admin/partials/purge-counts.blade.php:9`, `str_replace('_', ' ', $table)`).
   - **Organizer-facing sentences in actions: 15** — `CreateDefaultReviewForm`'s nine default questions and its form title (10; content seeded into each new conference rather than interface) and five one-line refusals (`ArchiveConference`, `CloseSubmissions`, `GenerateConferenceQr`, `ReviewFormLocked`, `ReviewQuestionInUse`).
   - **Two notifications not on the template system: 16** — `NewSubmissionNotice.php:44-62` and `OrganizationRegistered.php:30-38`.
   - Not counted above: about 290 in console commands, the demo seeder and the legacy importer, which an operator reads in a terminal; four exception messages a person never sees (`ConferenceNotPublishable`, `SaveEmailTemplate`, `SendOneDecisionEmail`, `StoreSubmissionFile`); and the sweep's only non-English hits anywhere in `app/` — SQL fragments such as `'decision, count(*) as aggregate'` (`Conference.php`, `SendDecisionEmails.php`), a `rel` value (`RichText.php`), an inline SVG (`InitialsAvatarProvider.php`) and PDF bytes (`DemoPdf.php`).

2.17. **The approve and reject notifications interpolate the organization's name unescaped** (`OrganizationsTable.php:65`, `"{$record->name} approved"`, and `:83`), while `ConferenceStatusActions.php:71-79` escapes an organizer-supplied conference name with `e()` because Filament renders a notification title through `Str::sanitizeHtml()`, which keeps `style` and `class`. The purge notification in the same file passes `'name' => $name` unescaped too (`:135-139`).

**Decisions this task makes.**

1. **Enum labels live in one new file, `lang/en/enums.php`, keyed `enums.<class in snake_case>.<stored value>`, and every `match` arm spells its key out.** The key is predictable from the database row a translator or a support person is looking at, and from the class name, which is what lets the runtime case in Step 1 derive the expected key for every case of every enum rather than listing them. The arms stay a `match` with no default, so a new case is still an `UnhandledMatchError` rather than a silent blank. The shorter `__('enums.conference_status.'.$this->value)` is rejected: both key-resolution cases skip a key that ends in a dot (`LanguageCoverageTest.php:556-563`), so a concatenated key is one nothing checks statically — which is exactly the gap this task is closing. The two `ucfirst()` enums become matches like the rest, and must still print "Owner", "Admin", "Member", "Pending", "Approved", "Suspended" (Step 1 pins them).

2. **`Decision::getLabel()` stays English, and a test pins it as the one exception.** The backlog's reason holds (fact 2.4): a letter's `{{decision}}` has to follow the language the *letter* is written in, which is the bilingual-templates item of spec section 14, not the language of the panel that sent it. Translating it through the panel's locale would put an Arabic phrase into an English letter whenever the organizer who sent it was using the panel in Arabic. The enum case in Step 1 asserts `Decision::AcceptedOral->getLabel()` is still `'Accepted for oral presentation'` under a keyless locale, so the day somebody translates it they meet a failing test that names the reason. The docblock is rewritten because its old justification ("every other enum is not translated either") stops being true in this commit.

3. **`DemoStage` and `SubmissionWindow` get no label.** Neither is ever shown as prose: `DemoStage`'s values are what an operator types after `--stage=` and are parsed back with `tryFrom()` (fact 2.14) — translating them would break the command — and `SubmissionWindow` is already rendered through `submission.window.*` keys by the views that switch on it. A `getLabel()` nobody calls is a string a translator would translate for nothing.

4. **One `export.headings` group serves both exports, and the column order stays in the code.** Nine headings appear in both files (fact 2.6); one key each means a translation cannot leave the submission list and the ranking disagreeing about what "Submitted at" is called. The order is the file format and has to match each `row()` method, so it stays in `headers()`. `ExportSubmissionsCsv::HEADERS` becomes `private static function headers(): array`, private like the constant it replaces, because a constant cannot call `__()`. The checkbox answers become `export.answers.yes` / `.no`, still lower-case — they are values inside a sentence-like cell (`needs_projector: yes`), not headings.

5. **The publishing blockers go in a new `lang/en/organizer.php`, under `publish.errors`, with the `:status` placeholder the newer actions already use.** `lang/en/conference.php` is the *public* conference page by its own header, and `reviewer.php` / `decisions.php` are other features; the conference lifecycle Plan 2 wrote has no file, and the rest of Plan 2's organizer screens (fact 2.16) belong in the same one when they are swept. The eighth sentence becomes `__('organizer.publish.errors.wrong_status', ['status' => mb_strtolower(...)])` — the exact shape of `reviewer.start.errors.wrong_status` (fact 2.7). `mb_strtolower()` replaces `strtolower()`: every English label is ASCII, so the output is identical, and a translation with a non-ASCII capital is lower-cased correctly. "A conference that is decisions sent cannot be opened…" is today's awkward output and is kept, and pinned: this task moves words, it does not improve them.

6. **I convert three of the ten admin tables — the three that were never converted — as whole files.** The backlog's "four" is a miscount (fact 2.8): seven tables already use keys, and the runtime case in Step 1 proves it for all ten. `ConferencesTable`, `EmailLogsTable` and `OrganizationsTable` get an explicit `->label(__(...))` on every column and filter, including the eight columns and three filters that had none — Filament's made-up "Name", "Status", "Type", "Country", "Subject" become keys holding the same word. `OrganizationsTable`'s approve and reject actions convert too: the sweep reads files, not methods, and they are the busiest English on that screen. The keys go in three new groups — `admin.conferences`, `admin.organizations`, `admin.email_log` — inserted before `submissions` so no line another task anchors on moves. `admin.organization` (singular, Task 3's edit screen) and `admin.members` (Task 4) are not touched. The organization's name reaches the notification through `:name`, escaped with e() (fact 2.17). That changes nothing visible for `&` — `A & B` and `e('A & B')` both sanitise to `A &amp; B` — and stops `<…>` from rendering as markup: an anonymous registrant chooses the name (`app/Livewire/Public/RegisterOrganization.php:52`), and `sanitizeHtml()` keeps `style` and `class`, so a name can be a full-viewport link on the platform admin's screen. A test written against the old code and shown failing first pins it (Step 1). Task 3 escapes the same name in the purge notification.

7. **`$plan7Sources` gets the two cases Plan 6's list has — and the visible-English one reads PHP by its tokens, because key resolution alone proves nothing here.** The app-wide case already resolves every key in `app/` (fact 2.2), so a key-only case over these files would add nothing — and it would have passed on `main` with every enum still spelled out: before this task, the twenty-two PHP files hold 16 keys, all of them Plan 6's purge action. The case that proves the sweep is the one that looks for English *outside* `__()`. `Blade::compileString()` has nothing to say about a class, so the PHP branch reads `token_get_all()` string tokens — comments and docblocks are never string tokens — and calls a literal English when it is a capitalised word on its own (`'Draft'`, `'Platform-wide'`), when it has a space and a capitalised word or a whole word between spaces (`'Letters sent'`, `'A4 poster (210 x 297 mm)'`), or, for a fragment of an interpolated string, when a word touches a space (`"{$record->name} approved"`). An identifier passes even with capitals in it — `'reviewAssignments'`, `'Content-Type'`, `'X-CASS-Log'`, `'Y-m-d-His'`, `'App\Filament\Admin'`, `'j M Y, H:i'`, `'text/csv; charset=UTF-8'` — and two sentence-shaped identifiers are skipped by position: an array key and a subscript (`$counts['private files']`). Run over all of `app/`, those rules flag English and almost nothing else — the exceptions are SQL fragments, one `rel` value, one inline SVG and some PDF bytes (fact 2.16), none of them in a file anybody would list here. The eight sample values of fact 2.13 are excused **by exact text** in `$plan7NotProse`, and an excuse whose text has left the file fails the case too, so the list cannot rot. Before this task the case lists **125 offenders**; after it, none. A Blade file appended by a later task gets Plan 6's sweep unchanged, at Plan 6's one-word threshold.

8. **The static sweep's blind spots are closed by running the code under a keyless locale.** The token rules cannot see a single lower-case word (`'yes'`), a label Filament makes up from a column name (fact 2.9), or the two `ucfirst()` enums — none of those is a literal. So three runtime cases set the locale and the fallback to `xx`, which has no `lang/` directory (fact 2.10), and check that what comes back is a key: every case of every `HasLabel` enum except `Decision`, discovered from `app/Enums/*.php` so an enum a later task adds is swept the day it is written; both export heading rows and the yes/no inside a streamed CSV cell; and every column label, placeholder, filter label, heading and empty state of **all ten** admin tables, mounted as Livewire components. Filament's own words come back as `filament-tables::…` keys and pass — Filament ships its own translations.

9. **"Byte for byte unchanged" is proven by pins written against the old code, which pass on both sides of the change.** `tests/Feature/ExtractedEnglishTest.php` pins every one of the 67 enum labels, both heading rows (the submission list read off the real streamed file, BOM included) and the eighth blocker for the three statuses that reach it; `AdminTableLanguageTest.php` pins every column, placeholder and filter word of the three converted tables and the approve and reject actions' label, modal text and notification. Their literals are copied from the code as it was, never from `lang/en` — a pin that read its expectation from the language file would pass whatever the file said. **These pins, and the guard cases that pin behaviour which already exists, are the deliberate exceptions to "a new test is shown failing first"**: a test that had to fail before the change could not also prove the change altered nothing, and a guard has no code that makes it pass. The guard cases are `AdminTableLanguageTest`'s five `still forbids an organizer` rows, the seven rows of `looks every heading of every admin table up` for the tables Plan 6 already keyed (fact 2.8), `PanelThemeTest`'s cross-tenant case (Task 1), and `ReviewerAffiliationTest`'s `content()` drift pin and 403 case (Task 6); each is named again at its own failing-first step. Step 2 runs them green on the old code, which is the proof they captured it; the seven coverage cases and the escaping case (decision 6) are the failing-first gate.

10. **The admin purge modal joins the list, so the Blade branch runs from day one.** `filament/admin/partials/purge-counts.blade.php` is rendered by two of the three tables and its own words have been keys since Plan 6; listing it means the Blade half of the visible-English case reads a real file in this task rather than first running on a later task's view. What it prints that is *not* a literal — raw table names (fact 2.16) — is beyond any literal sweep and goes to the backlog.

11. **The residue of fact 2.16 is recorded, not converted.** About 340 literals in about 40 files is a plan-sized extraction, most of it in the organizer panel's Plan 2 forms, and the brief for this task is the backlog's list. Task 7's backlog step replaces the two entries quoted above with this one, which this task's numbers support:

    > **The language sweep now covers every file the backlog named; it does not cover the application.** Plan 7 converted every enum label but `Decision`'s, both export headings, the publishing blockers and the three admin tables Plans 1-2 wrote, and `LanguageCoverageTest` now reads PHP for English as well as Blade (`$plan7PhpProse`). Run over all of `app/`, that sweep still finds: the organizer panel's Plan 2-3 classes (210 literals in 15 files under `app/Filament/Organizer/`; Plan 7 Task 3 converted `EditOrganizationProfile`); **what a visitor or author reads** — `SubmitAbstract`, `UpdateSubmission`, `WithdrawSubmission`, `SaveSubmissionDraft`, `SendSubmissionStatusLink`, `SubmissionFileRejected`, and the titles and throttle messages of `ContactForm`, `RegisterOrganization` and `AcceptInvitation` (46 literals in 9 files, reaching the public form through `SubmissionForm` and `SubmissionStatus`); the admin infolists, `EmailLogResource`'s navigation label, the panel brand names and the purge modal's raw table names (37); five organizer refusals and `CreateDefaultReviewForm`'s nine default questions (15); and the two notifications not on the template system, `NewSubmissionNotice` and `OrganizationRegistered` (16). Do the visitor-facing nine first — they are on the public site, where Arabic is promised. `Decision::getLabel()` still moves with the bilingual templates of spec section 14, and `LanguageCoverageTest` pins it until then.

**Files:**
- Create: `lang/en/enums.php`, `lang/en/export.php`, `lang/en/organizer.php`
- Create: `tests/Feature/ExtractedEnglishTest.php`, `tests/Feature/Admin/AdminTableLanguageTest.php`
- Modify: `lang/en/admin.php` — three groups inserted before `'submissions' => [`; no existing line changes
- Modify: `app/Enums/{ConferenceStatus,CustomFieldType,EmailLogStatus,EmailTemplateKey,InvitationStatus,OrganizationRole,OrganizationStatus,OrganizationType,PosterSize,PresentationPreference,ReminderThreshold,ReviewMode,ReviewQuestionType,ReviewStatus,ReviewerStatus,SubmissionStatus}.php` — `getLabel()` only
- Modify: `app/Enums/Decision.php` — the `getLabel()` docblock only
- Modify: `app/Support/Scoring/RankingRows.php`, `app/Actions/Submissions/ExportSubmissionsCsv.php`, `app/Actions/Conferences/PublishConference.php`
- Modify: `app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php`, `.../EmailLogs/Tables/EmailLogsTable.php`, `.../Organizations/Tables/OrganizationsTable.php` — column, filter, empty-state and approve/reject lines; no `recordActions`, `purgeAction()` or `confirmationRule()` line changes
- Modify: `tests/Feature/LanguageCoverageTest.php` — six imports, then `$plan7Sources` and its cases appended at the end
- Test: the three test files above; `tests/Unit/PublishConferenceTest.php`, `tests/Feature/Organizer/ConferenceTransitionsTest.php`, `tests/Feature/Organizer/ConferenceRankingExportTest.php`, `tests/Feature/Organizer/SubmissionResourceTest.php` and `tests/Feature/Admin/` run **unedited**

- [ ] **Step 1: Write the failing tests, and the pins that must not fail**

`tests/Feature/LanguageCoverageTest.php` — the imports (`:5-9`). Old:

```php
use App\Enums\EmailTemplateKey;
use App\Enums\InvitationStatus;
use App\Enums\ReminderThreshold;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Lang;
```

New:

```php
use App\Actions\Submissions\ExportSubmissionsCsv;
use App\Enums\Decision;
use App\Enums\EmailTemplateKey;
use App\Enums\InvitationStatus;
use App\Enums\ReminderThreshold;
use App\Models\Submission;
use App\Support\Scoring\RankingRows;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
```

Then append to the end of the file, after the closing `});` of `it('resolves every translation key the php classes use, not only the ones in the view lists', …)`:

```php
/**
 * Plan 7: the PHP classes every earlier sweep stopped at. The backlog named
 * them - every enum's getLabel(), both export headings, the publishing
 * checklist and the admin tables Plans 1 and 2 wrote - and none of them is a
 * Blade view, which is why no $planNSources case above ever read a line of
 * them: every visible-English case in this file skips a file that does not
 * end in .blade.php.
 *
 * NOT here, on purpose:
 * - app/Enums/Decision.php. Its label is `{{decision}}`'s value in every
 *   decision letter, so it follows the letter's language rather than the
 *   panel's and moves with spec section 14's bilingual templates (backlog).
 *   The enum case below pins it as the one label still spelled out.
 * - app/Enums/DemoStage.php and app/Enums/SubmissionWindow.php. Neither has
 *   a label: DemoStage's values are the `--stage=` arguments an operator
 *   types, and the public views turn SubmissionWindow into submission.window.*
 *   keys themselves.
 *
 * Tasks 3 to 6 append their own files to the end of this list.
 */
$plan7Sources = [
    'app/Enums/ConferenceStatus.php',
    'app/Enums/CustomFieldType.php',
    'app/Enums/EmailLogStatus.php',
    'app/Enums/EmailTemplateKey.php',
    'app/Enums/InvitationStatus.php',
    'app/Enums/OrganizationRole.php',
    'app/Enums/OrganizationStatus.php',
    'app/Enums/OrganizationType.php',
    'app/Enums/PosterSize.php',
    'app/Enums/PresentationPreference.php',
    'app/Enums/ReminderThreshold.php',
    'app/Enums/ReviewMode.php',
    'app/Enums/ReviewQuestionType.php',
    'app/Enums/ReviewStatus.php',
    'app/Enums/ReviewerStatus.php',
    'app/Enums/SubmissionStatus.php',
    'app/Support/Scoring/RankingRows.php',
    'app/Actions/Submissions/ExportSubmissionsCsv.php',
    'app/Actions/Conferences/PublishConference.php',
    'app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php',
    'app/Filament/Admin/Resources/EmailLogs/Tables/EmailLogsTable.php',
    'app/Filament/Admin/Resources/Organizations/Tables/OrganizationsTable.php',
    // The purge modal both admin tables render. Its own words have been keys
    // since Plan 6; it is here so this list's Blade branch has a file to read
    // from day one rather than first running on a later task's view.
    'resources/views/filament/admin/partials/purge-counts.blade.php',
];

/**
 * Literals in the files above that read as English and are not interface.
 * Each entry is exact text, never a pattern, so a new hardcoded label in the
 * same file is still caught.
 */
$plan7NotProse = [
    // EmailTemplateKey::sampleValues() is the template editor's preview DATA:
    // a person's name, an abstract title, a conference and a society name -
    // things somebody types, which no translation changes - plus the two
    // values that mirror what the application really substitutes. `deadline`
    // is how SubmitAbstract formats it (`->format('j F Y, H:i')`, which is not
    // localised), and `decision` is Decision::getLabel(), which stays English
    // until the bilingual templates (see above). `reason` is what an admin
    // types into the reject box.
    'app/Enums/EmailTemplateKey.php' => [
        'Dr Sara Al-Harbi',
        'Dr Omar Khan',
        'Early mobilisation after cardiac surgery',
        'Gulf Pediatric Critical Care 2026',
        'Gulf Pediatric Society',
        '3 November 2026, 23:59 (Asia/Riyadh)',
        'Accepted for oral presentation',
        'The society could not be verified from the details given.',
    ],
];

/**
 * The visible text of a Blade view: the Plan 6 sweep, unchanged - compile,
 * drop the PHP and the script/style bodies, then strip_tags(), plus the four
 * attributes a reader or a screen reader is given.
 *
 * @return list<string>
 */
$plan7BladeText = static function (string $blade): array {
    $stripped = (string) preg_replace([
        '/<\?php.*?\?>/s',
        '/<\?php.*$/s',
        '/<script\b[^>]*>.*?<\/script>/s',
        '/<style\b[^>]*>.*?<\/style>/s',
    ], ' ', Blade::compileString($blade));

    preg_match_all(
        '/\b(?:placeholder|title|alt|aria-label)\s*=\s*"([^"]*)"|\b(?:placeholder|title|alt|aria-label)\s*=\s*\'([^\']*)\'/i',
        $stripped,
        $attributes,
    );

    $text = strip_tags($stripped)."\n".implode("\n", [...$attributes[1], ...$attributes[2]]);
    $lines = [];

    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');

        if ($line !== '') {
            $lines[] = $line;
        }
    }

    return $lines;
};

/**
 * The string literals of a PHP file that read as English.
 *
 * Blade::compileString() has nothing to say about a class, so this reads
 * PHP's own tokens. Comments and docblocks are never string tokens, and a key
 * inside __('...') never reads as English, so neither needs handling. What is
 * left is the shape of the literal itself:
 *
 * - a capitalised word on its own reads as English: 'Draft', 'Owner',
 *   'Platform-wide', 'Decision:'. An identifier does not, even with capitals in
 *   it: 'reviewAssignments', 'Content-Type', 'X-CASS-Log', 'Y-m-d-His',
 *   'App\Filament\Admin', 'CASS';
 * - a literal with a space in it reads as English when it holds a capitalised
 *   word, or a word of two or more letters standing between spaces: 'Letters
 *   sent', 'open for submissions', 'A4 poster (210 x 297 mm)'. A date format
 *   does not ('j M Y, H:i'), and nor does a MIME type ('text/csv;
 *   charset=UTF-8');
 * - a fragment of an interpolated "..." string reads as English when a word
 *   touches a space, because "{$record->name} approved" is a sentence whose
 *   only literal word is lower-case, while "livewire-tmp/{$file}" is a path.
 *
 * Two places a sentence-shaped literal is still an identifier are skipped by
 * position, not by list: an array key ('Content-Type' => ...,
 * ['submissions as decided_count' => fn ...]) and a subscript
 * ($counts['private files']). Anything else that reads as English and is not
 * interface - an SQL fragment, a rel="" value - goes in $plan7NotProse, by
 * exact text and with a reason, so a reviewer sees every exception.
 *
 * The blind spot, stated rather than hidden: ONE lower-case word on its own
 * ('yes') is an identifier to these rules. The runtime cases - the enum and
 * export cases below, and tests/Feature/Admin/AdminTableLanguageTest.php -
 * are what cover that shape, and the labels Filament makes up from a column
 * name, which no literal holds at all.
 *
 * @return list<string> "line: text"
 */
$plan7PhpProse = static function (string $php): array {
    $tokens = array_values(array_filter(
        token_get_all($php),
        static fn (array|string $token): bool => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $found = [];

    foreach ($tokens as $i => $token) {
        if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }

        $previous = $tokens[$i - 1] ?? null;
        $beforePrevious = $tokens[$i - 2] ?? null;
        $next = $tokens[$i + 1] ?? null;

        if (is_array($next) && $next[0] === T_DOUBLE_ARROW) {
            continue;
        }

        if ($previous === '[' && $next === ']' && is_array($beforePrevious) && $beforePrevious[0] === T_VARIABLE) {
            continue;
        }

        if ($token[0] === T_ENCAPSED_AND_WHITESPACE) {
            $text = $token[1];
            $prose = preg_match('/\s\p{L}{2,}|\p{L}{2,}\s/u', $text) === 1;
        } else {
            $text = substr($token[1], 1, -1);
            $prose = preg_match('/\s/u', $text) === 1
                ? preg_match('/(?<!\p{L})\p{Lu}\p{Ll}+|(?<!\S)\p{L}{2,}(?!\S)/u', $text) === 1
                : preg_match('/^\p{Lu}\p{Ll}+(?:-\p{Ll}+)*\p{P}?$/u', $text) === 1;
        }

        if ($prose) {
            $found[] = $token[2].': '.$text;
        }
    }

    return $found;
};

it('resolves every translation key plan 7 uses', function () use ($plan7Sources) {
    // The namespaces come from lang/en itself, as in the app-wide case above,
    // so a later task that opens a new language file is checked without
    // anybody remembering to widen a pattern. A key ending in a dot is built by
    // concatenation and is skipped, as there.
    $namespaces = collect(glob(lang_path('en/*.php')) ?: [])
        ->map(fn (string $path): string => basename($path, '.php'))
        ->implode('|');

    $missing = [];
    $checked = 0;

    foreach ($plan7Sources as $relative) {
        $path = base_path($relative);

        expect(file_exists($path))->toBeTrue("Expected {$relative} to exist.");

        preg_match_all(
            '/(?:__|@lang|trans)\(\s*[\'"]((?:'.$namespaces.')\.[a-z0-9_.]+)[\'"]/',
            (string) file_get_contents($path),
            $matches,
        );

        foreach (array_unique($matches[1]) as $key) {
            if (str_ends_with($key, '.')) {
                continue;
            }

            $checked++;

            if (! Lang::has($key)) {
                $missing[] = "{$key} (used in {$relative})";
            }
        }
    }

    // 163 today: 67 enum labels, 32 export keys, 8 blockers, 52 keys on the
    // three admin tables (16 of them the Plan 6 purge action's) and 4 in the
    // purge modal. Later tasks only add to the list.
    expect($checked)->toBeGreaterThanOrEqual(163)
        ->and($missing)->toBe([]);
});

it('leaves no visible english in the files plan 7 swept', function () use ($plan7Sources, $plan7NotProse, $plan7BladeText, $plan7PhpProse) {
    // Blade views get the Plan 6 sweep at its Plan 6 threshold - ONE word,
    // with the same allow-list of things that are not prose. PHP files get
    // the token sweep above. A later task may append either.
    $allowed = ['CASS', 'KB', 'MB', 'PDF', '·', '–', '—'];
    $offenders = [];

    foreach ($plan7Sources as $relative) {
        $source = (string) file_get_contents(base_path($relative));

        if (str_ends_with($relative, '.blade.php')) {
            foreach ($plan7BladeText($source) as $line) {
                if (! in_array($line, $allowed, true)) {
                    $offenders[] = "{$relative}: {$line}";
                }
            }

            continue;
        }

        $excused = $plan7NotProse[$relative] ?? [];
        $seen = [];

        foreach ($plan7PhpProse($source) as $found) {
            [, $text] = explode(': ', $found, 2);

            if (in_array($text, $excused, true)) {
                $seen[] = $text;
            } else {
                $offenders[] = "{$relative}:{$found}";
            }
        }

        // An exception nobody needs any more is an exception nobody reviews:
        // an entry whose text has left the file is reported too.
        foreach (array_diff($excused, $seen) as $stale) {
            $offenders[] = "{$relative}: '{$stale}' is excused in \$plan7NotProse but no longer in the file";
        }
    }

    expect($offenders)->toBe([]);
});

it('looks every enum label up rather than spelling it, except the decision', function () {
    // A locale with no language file and no fallback, so __() hands back the
    // key it was given. A label that comes back as a key was looked up; one
    // that comes back as English was spelled out in the enum. Derived from
    // the directory rather than the list above, so an enum a later task adds
    // is inside the sweep on the day it is written.
    app()->setLocale('xx');
    app('translator')->setFallback('xx');

    $spelled = [];
    $checked = 0;

    foreach (glob(app_path('Enums/*.php')) ?: [] as $path) {
        $enum = 'App\\Enums\\'.basename($path, '.php');

        if (! is_a($enum, HasLabel::class, true) || $enum === Decision::class) {
            continue;
        }

        foreach ($enum::cases() as $case) {
            $checked++;
            $key = 'enums.'.Str::snake(class_basename($enum)).'.'.$case->value;

            if ($case->getLabel() !== $key) {
                $spelled[] = "{$enum}::{$case->name} is '{$case->getLabel()}', not {$key}";
            }
        }
    }

    // The exception, pinned so that translating it is a decision somebody
    // makes on purpose: a decision letter's `{{decision}}` has to follow the
    // LETTER's language, which is the bilingual-templates item of spec
    // section 14, not this sweep.
    expect($checked)->toBeGreaterThanOrEqual(67)
        ->and($spelled)->toBe([])
        ->and(Decision::AcceptedOral->getLabel())->toBe('Accepted for oral presentation');
});

it('looks both export heading rows up, and the yes and no inside a cell', function () {
    // The same keyless locale as the enum case. The ranking headings are a
    // plain list; the submission list is read off the streamed file, because
    // its yes/no is a single lower-case word inside a data cell - the one
    // shape the token sweep above cannot see.
    app()->setLocale('xx');
    app('translator')->setFallback('xx');

    // Keys in MySQL's order: a json column hands an object back shorter key
    // first, where SQLite keeps the order written, and the cell follows it.
    $submission = Submission::factory()->submitted()->create([
        'custom_field_values' => ['first_time' => false, 'needs_projector' => true],
    ]);

    $response = app(ExportSubmissionsCsv::class)
        ->handle(Submission::query()->whereKey($submission->getKey()), 'submissions.csv');

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    $rows = array_map(
        static fn (string $line): array => str_getcsv($line, escape: ''),
        preg_split('/\r?\n/', trim(substr($csv, 3))) ?: [],
    );

    $spelled = static fn (array $headings): array => array_values(array_filter(
        $headings,
        static fn (?string $heading): bool => preg_match('/^export\.headings\.[a-z_]+$/', (string) $heading) !== 1,
    ));

    expect($spelled(RankingRows::headers()))->toBe([])
        ->and($spelled($rows[0]))->toBe([])
        ->and($rows[1][13])->toBe('first_time: export.answers.no; needs_projector: export.answers.yes');
});
```

Create `tests/Feature/Admin/AdminTableLanguageTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Admin\Resources\Conferences\Pages\ListConferences;
use App\Filament\Admin\Resources\Conferences\Pages\ViewConference;
use App\Filament\Admin\Resources\Conferences\RelationManagers\ReviewersRelationManager;
use App\Filament\Admin\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Filament\Admin\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Admin\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Admin\Resources\Organizations\RelationManagers\InvitationsRelationManager;
use App\Filament\Admin\Resources\Organizations\RelationManagers\MembersRelationManager;
use App\Filament\Admin\Resources\Reviews\Pages\ListReviews;
use App\Filament\Admin\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Admin\Resources\Submissions\RelationManagers\AssignmentsRelationManager;
use App\Filament\Admin\Resources\Submissions\RelationManagers\ReviewsRelationManager;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/**
 * The admin panel's ten tables: five resource lists and five relation
 * managers. Plan 6 wrote seven of them with lang/ keys; the three Plans 1 and 2
 * wrote - conferences, organizations and the email log - spelled their
 * headings out, and eight of their columns and all three status filters had no
 * label at all, so Filament made one up from the name ("Name", "Status",
 * "Type", "Country", "Subject").
 * A made-up label is English that no literal holds, which is why the static
 * sweep in tests/Feature/LanguageCoverageTest.php cannot see it and this file
 * mounts the tables instead.
 */
beforeEach(function () {
    $this->admin = User::factory()->platformAdmin()->create();
    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();
});

/**
 * Every word an admin table prints around its rows: column labels and
 * placeholders, filter labels, the heading and the empty state. Keyed by kind
 * and name, so a column and a filter that share a name cannot overwrite each
 * other.
 *
 * @param  class-string  $component
 * @param  array<string, mixed>  $parameters
 * @return array<string, string>
 */
function adminTableWords(string $component, array $parameters = []): array
{
    $table = livewire($component, $parameters)->instance()->getTable();
    $words = [];

    foreach ($table->getColumns() as $name => $column) {
        $words["column {$name}"] = (string) $column->getLabel();

        if (filled($placeholder = $column->getPlaceholder())) {
            $words["placeholder {$name}"] = (string) $placeholder;
        }
    }

    foreach ($table->getFilters() as $name => $filter) {
        $words["filter {$name}"] = (string) $filter->getLabel();
    }

    if (filled($heading = $table->getHeading())) {
        $words['heading'] = (string) $heading;
    }

    $words['empty state'] = (string) $table->getEmptyStateHeading();

    return $words;
}

dataset('admin tables', [
    'conferences' => [ListConferences::class, []],
    'organizations' => [ListOrganizations::class, []],
    'email log' => [ListEmailLogs::class, []],
    'abstracts' => [ListSubmissions::class, []],
    'reviews' => [ListReviews::class, []],
    'conference reviewers' => [ReviewersRelationManager::class, fn (): array => [
        'ownerRecord' => Conference::factory()->create(),
        'pageClass' => ViewConference::class,
    ]],
    'organization members' => [MembersRelationManager::class, fn (): array => [
        'ownerRecord' => Organization::factory()->create(),
        'pageClass' => ViewOrganization::class,
    ]],
    'organization invitations' => [InvitationsRelationManager::class, fn (): array => [
        'ownerRecord' => Organization::factory()->create(),
        'pageClass' => ViewOrganization::class,
    ]],
    'abstract reviews' => [ReviewsRelationManager::class, fn (): array => [
        'ownerRecord' => Submission::factory()->create(),
        'pageClass' => ViewSubmission::class,
    ]],
    'abstract assignments' => [AssignmentsRelationManager::class, fn (): array => [
        'ownerRecord' => Submission::factory()->create(),
        'pageClass' => ViewSubmission::class,
    ]],
]);

it('looks every heading of every admin table up in a language file', function (string $component, array $parameters) {
    // A locale with no language file and no fallback, so __() hands back the
    // key it was given: a word that comes back as a key was looked up, one that
    // comes back as English was spelled out or made up. Filament's own words
    // come back as `filament-tables::…` keys and pass - Filament ships its own
    // translations. A word with no letters at all ("-") is not language.
    app()->setLocale('xx');
    app('translator')->setFallback('xx');

    $english = array_filter(
        adminTableWords($component, $parameters),
        static fn (string $word): bool => preg_match('/\p{L}/u', $word) === 1
            && preg_match('/^[a-z0-9_-]+(::[a-z0-9_-]+)?(\.[a-z0-9_]+)+$/', $word) !== 1,
    );

    expect($english)->toBe([]);
})->with('admin tables');

it('prints the same english headings on the three tables plans 1 and 2 wrote', function (string $component, array $expected) {
    // Byte for byte what these tables printed before the sweep, including the
    // labels Filament made up from a column name - "Name", "Status", "Type",
    // "Country", "Subject" - which are explicit keys now and must read the
    // same.
    // Left out: Filament's own TrashedFilter, and the empty state of the two
    // tables that never set one - both are Filament's words, not this table's.
    $words = adminTableWords($component);
    unset($words['filter trashed']);

    if (! array_key_exists('empty state', $expected)) {
        unset($words['empty state']);
    }

    expect($words)->toBe($expected);
})->with([
    'conferences' => [ListConferences::class, [
        'column name' => 'Name',
        'column organization.name' => 'Organization',
        'column status' => 'Status',
        'column submissions_count' => 'Abstracts',
        'column decided_count' => 'Decided',
        'column notified_count' => 'Letters sent',
        'column submission_deadline' => 'Deadline',
        'placeholder submission_deadline' => 'Not set',
        'column created_at' => 'Created',
        'filter status' => 'Status',
    ]],
    'organizations' => [ListOrganizations::class, [
        'column name' => 'Name',
        'column type' => 'Type',
        'column country' => 'Country',
        'column owners.email' => 'Owner',
        'column status' => 'Status',
        'column created_at' => 'Registered',
        'filter status' => 'Status',
    ]],
    'email log' => [ListEmailLogs::class, [
        'column created_at' => 'Queued',
        'column to_email' => 'To',
        'column subject' => 'Subject',
        'column status' => 'Status',
        'column template_key' => 'Template',
        'placeholder template_key' => '-',
        'column mailable' => 'Sent by',
        'column organization.name' => 'Organization',
        'placeholder organization.name' => 'Platform',
        'column sent_at' => 'Sent',
        'placeholder sent_at' => '-',
        'filter status' => 'Status',
        'filter organization_id' => 'Organization',
        'empty state' => 'Nothing sent yet',
    ]],
]);

it('says the same english when an organization is approved or rejected', function () {
    // The two review actions live in OrganizationsTable beside its columns,
    // and ViewOrganization's header reuses them. Their notifications carried
    // the name by interpolation ("{$record->name} approved") and carry it
    // through a :name placeholder now; the sentence must not move.
    Mail::fake();

    $first = Organization::factory()->create(['name' => 'Gulf Pediatric Society']);
    $second = Organization::factory()->create(['name' => 'Coastal Paediatric Society']);

    livewire(ListOrganizations::class)
        ->assertTableActionHasLabel('approve', 'Approve', $first)
        ->assertTableActionHasLabel('reject', 'Reject', $first)
        ->mountTableAction('approve', $first)
        ->assertMountedActionModalSee([
            'Approve this organization?',
            'The owner will be emailed and can publish conferences immediately.',
        ])
        ->callMountedTableAction()
        ->assertNotified('Gulf Pediatric Society approved');

    livewire(ListOrganizations::class)
        ->mountTableAction('reject', $second)
        ->assertMountedActionModalSee('Reason sent to the owner')
        ->setTableActionData(['reason' => 'We could not verify this society.'])
        ->callMountedTableAction()
        ->assertNotified('Coastal Paediatric Society rejected');
});

it('escapes an organization name in the approve notification', function () {
    // Not a pin: this one fails on the old code, which interpolated the name
    // raw. An anonymous registrant chooses the name, and Filament renders a
    // notification title through sanitizeHtml(), which keeps style and class.
    // ConferenceStatusActions escapes a conference name the same way.
    Mail::fake();

    $organization = Organization::factory()->create(['name' => 'A <b style="x">B</b>']);

    livewire(ListOrganizations::class)
        ->callTableAction('approve', $organization)
        ->assertNotified('A &lt;b style=&quot;x&quot;&gt;B&lt;/b&gt; approved');
});

it('still forbids an organizer every admin list', function (string $component) {
    // The negative case every panel test carries. The admin panel has no
    // tenant to cross, so the boundary is the platform-admin flag: an
    // organization owner - who can see the same conferences in their own
    // panel - mounts none of these.
    $owner = User::factory()->create();
    Organization::factory()->approved()->create()->addMember($owner, OrganizationRole::Owner);
    actingAs($owner);

    livewire($component)->assertForbidden();
})->with([
    'conferences' => ListConferences::class,
    'organizations' => ListOrganizations::class,
    'email log' => ListEmailLogs::class,
    'abstracts' => ListSubmissions::class,
    'reviews' => ListReviews::class,
]);
```

Create `tests/Feature/ExtractedEnglishTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Conferences\PublishConference;
use App\Actions\Submissions\ExportSubmissionsCsv;
use App\Enums\ConferenceStatus;
use App\Enums\CustomFieldType;
use App\Enums\EmailLogStatus;
use App\Enums\EmailTemplateKey;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\PosterSize;
use App\Enums\PresentationPreference;
use App\Enums\ReminderThreshold;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Enums\ReviewQuestionType;
use App\Enums\ReviewStatus;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Support\Scoring\RankingRows;

/**
 * Plan 7 Task 2 moved every string below into lang/en without rewording one,
 * and these tests are the proof. They were written against the code BEFORE
 * the move and passed there, and they pass after it because the English is
 * byte for byte the same. That is why none of them failed first: a test that
 * had to fail before the change could not also prove the change altered
 * nothing.
 *
 * The literals are copied from the code as it was, never from lang/en - a
 * test that read its expectation from the language file would pass whatever
 * the file said.
 */
it('reads every enum label in the same english as before the sweep', function (string $enum, array $labels) {
    $actual = [];

    foreach ($enum::cases() as $case) {
        $actual[$case->value] = $case->getLabel();
    }

    expect($actual)->toBe($labels);
})->with([
    'conference status' => [ConferenceStatus::class, [
        'draft' => 'Draft',
        'open' => 'Open for submissions',
        'closed' => 'Submissions closed',
        'reviewing' => 'Under review',
        'decided' => 'Decisions sent',
        'archived' => 'Archived',
    ]],
    'custom field type' => [CustomFieldType::class, [
        'text' => 'Single line of text',
        'textarea' => 'Paragraph',
        'select' => 'Choose one from a list',
        'checkbox' => 'Yes / no checkbox',
        'number' => 'Number',
    ]],
    'email log status' => [EmailLogStatus::class, [
        'queued' => 'Queued',
        'sent' => 'Sent',
        'failed' => 'Failed',
    ]],
    'email template key' => [EmailTemplateKey::class, [
        'submission_received' => 'Abstract received',
        'submission_draft_saved' => 'Draft saved',
        'reviewer_invitation' => 'Reviewer invitation',
        'reviewer_reminder' => 'Reviewer reminder',
        'reviewer_overdue' => 'Reviewer overdue',
        'decision_accepted_oral' => 'Decision: accepted for oral presentation',
        'decision_accepted_poster' => 'Decision: accepted for poster',
        'decision_waitlisted' => 'Decision: waitlisted',
        'decision_rejected' => 'Decision: not accepted',
        'organization_approved' => 'Organization approved',
        'organization_rejected' => 'Organization rejected',
        'organization_suspended' => 'Organization suspended',
    ]],
    'invitation status' => [InvitationStatus::class, [
        'pending' => 'Invited',
        'accepted' => 'Accepted',
        'expired' => 'Expired',
        'revoked' => 'Withdrawn',
    ]],
    // These two were ucfirst($this->value). The lookup has to give the same
    // three words, not a better phrasing of them.
    'organization role' => [OrganizationRole::class, [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'member' => 'Member',
    ]],
    'organization status' => [OrganizationStatus::class, [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'suspended' => 'Suspended',
    ]],
    'organization type' => [OrganizationType::class, [
        'society' => 'Scientific society or association',
        'hospital' => 'Hospital or health cluster',
        'university' => 'University or college',
        'company' => 'Company or agency',
        'other' => 'Other',
    ]],
    'poster size' => [PosterSize::class, [
        'a4' => 'A4 poster (210 x 297 mm)',
        'a3' => 'A3 poster (297 x 420 mm)',
    ]],
    'presentation preference' => [PresentationPreference::class, [
        'oral' => 'Oral presentation',
        'poster' => 'Poster',
        'either' => 'Either is fine',
    ]],
    'reminder threshold' => [ReminderThreshold::class, [
        'days_7' => '7 days before the deadline',
        'days_3' => '3 days before the deadline',
        'days_1' => '1 day before the deadline',
        'overdue' => 'After the deadline',
    ]],
    'review mode' => [ReviewMode::class, [
        'open_pool' => 'Open pool - every reviewer sees every abstract',
        'assigned' => 'Assigned - each abstract goes to named reviewers',
    ]],
    'review question type' => [ReviewQuestionType::class, [
        'likert' => 'Rating scale',
        'text' => 'Free text comment',
        'boolean' => 'Yes / no',
        'select' => 'Choose one from a list',
    ]],
    'review status' => [ReviewStatus::class, [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
    ]],
    'reviewer status' => [ReviewerStatus::class, [
        'active' => 'Active',
        'removed' => 'Removed',
    ]],
    'submission status' => [SubmissionStatus::class, [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'withdrawn' => 'Withdrawn',
        'under_review' => 'Under review',
        'accepted' => 'Accepted',
        'rejected' => 'Not accepted',
        'waitlisted' => 'Waitlisted',
    ]],
]);

it('writes the ranking export headings in the same english as before the sweep', function () {
    // One list for the CSV and the XLSX alike (ExportRankingCsv and
    // ExportRankingXlsx both call this), so pinning it pins both files.
    expect(RankingRows::headers())->toBe([
        'Reference', 'Title', 'Track', 'Presentation preference',
        'Score', 'Spread', 'Reviews',
        'Status', 'Decision', 'Decision letter sent',
        'Corresponding author', 'Corresponding email', 'All authors',
        'Submitted at',
    ]);
});

it('writes the submission list export in the same english as before the sweep', function () {
    // The streamed file itself, not a constant: the heading row is what an
    // organizer's spreadsheet shows, and the yes/no of a checkbox answer is
    // the one lower-case English word inside a data cell. Keys in MySQL's
    // order: a json column hands an object back shorter key first, where
    // SQLite keeps the order written, and the cell follows it.
    $conference = Conference::factory()->create();
    $submission = Submission::factory()->for($conference)->submitted()->create([
        'custom_field_values' => ['first_time' => false, 'needs_projector' => true],
    ]);

    $response = app(ExportSubmissionsCsv::class)
        ->handle(Submission::query()->whereKey($submission->getKey()), 'submissions.csv');

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    $rows = array_map(
        static fn (string $line): array => str_getcsv($line, escape: ''),
        preg_split('/\r?\n/', trim(substr($csv, 3))) ?: [],
    );

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($rows[0])->toBe([
            'Reference', 'Conference', 'Title', 'Status', 'Track', 'Presentation preference',
            'Corresponding author', 'Corresponding email', 'All authors', 'Affiliations',
            'Contact phone', 'Word count', 'Files', 'Extra answers', 'Submitted at', 'Last edited at',
        ])
        ->and($rows[1][13])->toBe('first_time: no; needs_projector: yes');
});

it('says why a conference cannot be opened in the same english as before the sweep', function (ConferenceStatus $status, string $sentence) {
    // The eighth blocker, and the only one tests/Unit/PublishConferenceTest.php
    // never asserted: the other seven are pinned there, word for word, and
    // must keep passing unedited. The awkward "that is decisions sent" is
    // today's output, kept on purpose - this task moves words, it does not
    // improve them.
    $conference = Conference::factory()
        ->for(Organization::factory()->approved())
        ->withSubmissionWindow()
        ->create(['status' => $status]);

    expect(app(PublishConference::class)->blockers($conference))->toContain($sentence);
})->with([
    'open' => [ConferenceStatus::Open, 'A conference that is open for submissions cannot be opened for submissions.'],
    'reviewing' => [ConferenceStatus::Reviewing, 'A conference that is under review cannot be opened for submissions.'],
    'decided' => [ConferenceStatus::Decided, 'A conference that is decisions sent cannot be opened for submissions.'],
]);
```

- [ ] **Step 2: Run them against the untouched code**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact tests/Feature/LanguageCoverageTest.php tests/Feature/ExtractedEnglishTest.php tests/Feature/Admin/AdminTableLanguageTest.php > /tmp/t-task-2.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-2.log
```

Expected: **`rc=1`, `8 failed, 52 passed`.** The eight, and why each fails:

- `resolves every translation key plan 7 uses` — `Failed asserting that 20 is equal to 163 or is greater than 163`: the listed files hold twenty keys today, all of them Plan 6's purge action and modal.
- `leaves no visible english in the files plan 7 swept` — **125 offenders**, from `app/Enums/ConferenceStatus.php:22: Draft` to `app/Filament/Admin/Resources/Organizations/Tables/OrganizationsTable.php:83:  rejected`. **Read the list: it is the work order for Steps 3 to 6**, and nothing in it may be an identifier — if one is, the scanner is wrong, not the file.
- `looks every enum label up rather than spelling it, except the decision` — 67 lines of `App\Enums\X::Case is 'English', not enums.x.case`.
- `looks both export heading rows up, and the yes and no inside a cell` — the fourteen ranking headings come back as English.
- `looks every heading of every admin table up in a language file` — three of its ten rows: `conferences`, `organizations`, `email log`. **The other seven rows pass**, which is fact 2.8 proven: Plan 6's tables were already keyed.
- `escapes an organization name in the approve notification` — `A notification was not sent`: no notification has the escaped title, because today's is the raw `A <b style="x">B</b> approved` (Step 6).

The 52 that pass include all 21 cases of `ExtractedEnglishTest`, the three table pins, the approve/reject pin and the five forbidden cases. **They pass on purpose** (decision 9): green here is the proof that the pins captured the English as it is, before a word of it moves. If any of them fails at this step, its expected literal was mistyped — fix the test, never the code.

- [ ] **Step 3: The language files**

Create `lang/en/enums.php`:

```php
<?php

declare(strict_types=1);

/*
 * The label of every enum case a person reads - in a badge, a filter, a select
 * or a spreadsheet cell. One group per enum, named after the class in
 * snake_case, keyed by the case's stored value, so the key is predictable
 * from the database row and tests/Feature/LanguageCoverageTest.php can derive
 * it. Spec section 10: Arabic is a copy of this file.
 *
 * Decision is NOT here. Its label is `{{decision}}`'s value in every decision
 * letter, so it has to follow the letter's language rather than the panel's;
 * it moves with the bilingual templates of spec section 14 (backlog).
 */

return [

    'conference_status' => [
        'draft' => 'Draft',
        'open' => 'Open for submissions',
        'closed' => 'Submissions closed',
        'reviewing' => 'Under review',
        'decided' => 'Decisions sent',
        'archived' => 'Archived',
    ],

    'custom_field_type' => [
        'text' => 'Single line of text',
        'textarea' => 'Paragraph',
        'select' => 'Choose one from a list',
        'checkbox' => 'Yes / no checkbox',
        'number' => 'Number',
    ],

    'email_log_status' => [
        'queued' => 'Queued',
        'sent' => 'Sent',
        'failed' => 'Failed',
    ],

    'email_template_key' => [
        'submission_received' => 'Abstract received',
        'submission_draft_saved' => 'Draft saved',
        'reviewer_invitation' => 'Reviewer invitation',
        'reviewer_reminder' => 'Reviewer reminder',
        'reviewer_overdue' => 'Reviewer overdue',
        'decision_accepted_oral' => 'Decision: accepted for oral presentation',
        'decision_accepted_poster' => 'Decision: accepted for poster',
        'decision_waitlisted' => 'Decision: waitlisted',
        'decision_rejected' => 'Decision: not accepted',
        'organization_approved' => 'Organization approved',
        'organization_rejected' => 'Organization rejected',
        'organization_suspended' => 'Organization suspended',
    ],

    'invitation_status' => [
        'pending' => 'Invited',
        'accepted' => 'Accepted',
        'expired' => 'Expired',
        'revoked' => 'Withdrawn',
    ],

    'organization_role' => [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'member' => 'Member',
    ],

    'organization_status' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'suspended' => 'Suspended',
    ],

    'organization_type' => [
        'society' => 'Scientific society or association',
        'hospital' => 'Hospital or health cluster',
        'university' => 'University or college',
        'company' => 'Company or agency',
        'other' => 'Other',
    ],

    'poster_size' => [
        'a4' => 'A4 poster (210 x 297 mm)',
        'a3' => 'A3 poster (297 x 420 mm)',
    ],

    'presentation_preference' => [
        'oral' => 'Oral presentation',
        'poster' => 'Poster',
        'either' => 'Either is fine',
    ],

    'reminder_threshold' => [
        'days_7' => '7 days before the deadline',
        'days_3' => '3 days before the deadline',
        'days_1' => '1 day before the deadline',
        'overdue' => 'After the deadline',
    ],

    'review_mode' => [
        'open_pool' => 'Open pool - every reviewer sees every abstract',
        'assigned' => 'Assigned - each abstract goes to named reviewers',
    ],

    'review_question_type' => [
        'likert' => 'Rating scale',
        'text' => 'Free text comment',
        'boolean' => 'Yes / no',
        'select' => 'Choose one from a list',
    ],

    'review_status' => [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
    ],

    'reviewer_status' => [
        'active' => 'Active',
        'removed' => 'Removed',
    ],

    'submission_status' => [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'withdrawn' => 'Withdrawn',
        'under_review' => 'Under review',
        'accepted' => 'Accepted',
        'rejected' => 'Not accepted',
        'waitlisted' => 'Waitlisted',
    ],

];
```

Create `lang/en/export.php`:

```php
<?php

declare(strict_types=1);

/*
 * The spreadsheets an organizer downloads: the submission list (CSV,
 * ExportSubmissionsCsv) and the ranking (CSV and XLSX, both through
 * RankingRows::headers()). Spec section 10: Arabic is a copy of this file.
 *
 * One heading per column, shared by both files wherever they print the same
 * column, so a translation cannot leave the two exports disagreeing about what
 * "Submitted at" is called. Only the words are here - the ORDER of the columns
 * is the code's, because it is the file format.
 */

return [

    'headings' => [
        'reference' => 'Reference',
        'conference' => 'Conference',
        'title' => 'Title',
        'status' => 'Status',
        'track' => 'Track',
        'presentation_preference' => 'Presentation preference',
        'score' => 'Score',
        'spread' => 'Spread',
        'reviews' => 'Reviews',
        'decision' => 'Decision',
        'decision_letter_sent' => 'Decision letter sent',
        'corresponding_author' => 'Corresponding author',
        'corresponding_email' => 'Corresponding email',
        'all_authors' => 'All authors',
        'affiliations' => 'Affiliations',
        'contact_phone' => 'Contact phone',
        'word_count' => 'Word count',
        'files' => 'Files',
        'extra_answers' => 'Extra answers',
        'submitted_at' => 'Submitted at',
        'last_edited_at' => 'Last edited at',
    ],

    // A checkbox answer inside the "Extra answers" cell of the submission list.
    // Lower-case on purpose: it is a value in a sentence-like cell
    // ("needs_projector: yes"), not a heading.
    'answers' => [
        'yes' => 'yes',
        'no' => 'no',
    ],

];
```

Create `lang/en/organizer.php`:

```php
<?php

declare(strict_types=1);

/*
 * The organizer panel's conference lifecycle, which Plan 2 wrote in PHP before
 * the language-file convention existed. Spec section 10: Arabic is a copy of
 * this file.
 *
 * Plan 7 opens it with the publishing checklist - PublishConference::blockers(),
 * shown on the conference page and in the refusal when Publish is clicked. The
 * newer lifecycle actions keep theirs beside their feature
 * (reviewer.start.errors, decisions.mark.errors); this is the same shape for
 * the oldest one. The rest of Plan 2's organizer screens land here when they
 * are swept (backlog).
 */

return [

    'publish' => [
        'errors' => [
            'pending' => 'Your organization is still waiting for platform approval. You can publish as soon as it is approved.',
            'suspended' => 'This organization is suspended, so its conferences cannot be published.',
            'no_window' => 'Set both a submission opening date and a submission deadline.',
            'deadline_past' => 'The submission deadline is in the past. Choose a future date and time.',
            'deadline_order' => 'The submission deadline must come after the submission opening date.',
            'no_questions' => 'The review form has no questions yet. Add at least one before publishing.',
            'archived' => 'An archived conference cannot be published again.',
            // :status is the conference status label, lower-cased by the
            // caller, exactly as reviewer.start.errors.wrong_status takes it.
            'wrong_status' => 'A conference that is :status cannot be opened for submissions.',
        ],
    ],

];
```

`lang/en/admin.php` — insert three groups **immediately before** the existing `submissions` group. Anchor, unchanged (`:27-28`):

```php
    'submissions' => [
        'title' => 'Abstracts',
```

Insert above it, after the blank line that follows the `purge` group's closing `],`:

```php
    // The three tables Plans 1 and 2 wrote before this file existed. Eight of
    // their columns and all three status filters had no label at all and
    // printed one Filament made up from the column name ("Name", "Status",
    // "Type", "Country", "Subject"); those are keys now too, with the same
    // words. `organizations` is the list and its two review actions.
    'conferences' => [
        'columns' => [
            'name' => 'Name',
            'organization' => 'Organization',
            'status' => 'Status',
            'abstracts' => 'Abstracts',
            'decided' => 'Decided',
            'notified' => 'Letters sent',
            'deadline' => 'Deadline',
            'created' => 'Created',
        ],
        'filters' => [
            'status' => 'Status',
        ],
        'not_set' => 'Not set',
    ],

    'organizations' => [
        'columns' => [
            'name' => 'Name',
            'type' => 'Type',
            'country' => 'Country',
            'owner' => 'Owner',
            'status' => 'Status',
            'registered' => 'Registered',
        ],
        'filters' => [
            'status' => 'Status',
        ],
        'approve' => [
            'action' => 'Approve',
            'heading' => 'Approve this organization?',
            'description' => 'The owner will be emailed and can publish conferences immediately.',
            'done' => ':name approved',
        ],
        'reject' => [
            'action' => 'Reject',
            'reason' => 'Reason sent to the owner',
            'done' => ':name rejected',
        ],
    ],

    'email_log' => [
        'columns' => [
            'queued' => 'Queued',
            'to' => 'To',
            'subject' => 'Subject',
            'status' => 'Status',
            'template' => 'Template',
            'sent_by' => 'Sent by',
            'organization' => 'Organization',
            'sent' => 'Sent',
        ],
        'filters' => [
            'status' => 'Status',
            'organization' => 'Organization',
        ],
        // An email with no organization: a password reset, a registration
        // notice to the platform admin.
        'platform' => 'Platform',
        'empty' => 'Nothing sent yet',
    ],

```

`admin.conferences`, `admin.organizations` and `admin.email_log` follow the file's own convention of one group per screen with `columns` and `filters` inside it. Do **not** add `admin.organization` (Task 3) and do not touch `admin.members` (Task 4).

- [ ] **Step 4: The enums**

In each of the sixteen files below, replace the whole `getLabel()` method — the line range given is where it sits on `main` — with the method shown. Every arm keeps its case and its order; only the string becomes a key, and each key is `enums.` + the class in snake_case + `.` + that case's backing value (decision 1). The two `ucfirst()` enums become matches like the others.

`app/Enums/ConferenceStatus.php:19-29`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('enums.conference_status.draft'),
            self::Open => __('enums.conference_status.open'),
            self::Closed => __('enums.conference_status.closed'),
            self::Reviewing => __('enums.conference_status.reviewing'),
            self::Decided => __('enums.conference_status.decided'),
            self::Archived => __('enums.conference_status.archived'),
        };
    }
```

`app/Enums/CustomFieldType.php:17-26`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::Text => __('enums.custom_field_type.text'),
            self::Textarea => __('enums.custom_field_type.textarea'),
            self::Select => __('enums.custom_field_type.select'),
            self::Checkbox => __('enums.custom_field_type.checkbox'),
            self::Number => __('enums.custom_field_type.number'),
        };
    }
```

`app/Enums/EmailLogStatus.php:16-23`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => __('enums.email_log_status.queued'),
            self::Sent => __('enums.email_log_status.sent'),
            self::Failed => __('enums.email_log_status.failed'),
        };
    }
```

`app/Enums/EmailTemplateKey.php:32-48`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::SubmissionReceived => __('enums.email_template_key.submission_received'),
            self::SubmissionDraftSaved => __('enums.email_template_key.submission_draft_saved'),
            self::ReviewerInvitation => __('enums.email_template_key.reviewer_invitation'),
            self::ReviewerReminder => __('enums.email_template_key.reviewer_reminder'),
            self::ReviewerOverdue => __('enums.email_template_key.reviewer_overdue'),
            self::DecisionAcceptedOral => __('enums.email_template_key.decision_accepted_oral'),
            self::DecisionAcceptedPoster => __('enums.email_template_key.decision_accepted_poster'),
            self::DecisionWaitlisted => __('enums.email_template_key.decision_waitlisted'),
            self::DecisionRejected => __('enums.email_template_key.decision_rejected'),
            self::OrganizationApproved => __('enums.email_template_key.organization_approved'),
            self::OrganizationRejected => __('enums.email_template_key.organization_rejected'),
            self::OrganizationSuspended => __('enums.email_template_key.organization_suspended'),
        };
    }
```

`app/Enums/InvitationStatus.php:22-30`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('enums.invitation_status.pending'),
            self::Accepted => __('enums.invitation_status.accepted'),
            self::Expired => __('enums.invitation_status.expired'),
            self::Revoked => __('enums.invitation_status.revoked'),
        };
    }
```

`app/Enums/OrganizationRole.php:15-18`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => __('enums.organization_role.owner'),
            self::Admin => __('enums.organization_role.admin'),
            self::Member => __('enums.organization_role.member'),
        };
    }
```

`app/Enums/OrganizationStatus.php:16-19`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('enums.organization_status.pending'),
            self::Approved => __('enums.organization_status.approved'),
            self::Suspended => __('enums.organization_status.suspended'),
        };
    }
```

`app/Enums/OrganizationType.php:17-26`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::Society => __('enums.organization_type.society'),
            self::Hospital => __('enums.organization_type.hospital'),
            self::University => __('enums.organization_type.university'),
            self::Company => __('enums.organization_type.company'),
            self::Other => __('enums.organization_type.other'),
        };
    }
```

`app/Enums/PosterSize.php:14-20`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::A4 => __('enums.poster_size.a4'),
            self::A3 => __('enums.poster_size.a3'),
        };
    }
```

`app/Enums/PresentationPreference.php:20-27`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::Oral => __('enums.presentation_preference.oral'),
            self::Poster => __('enums.presentation_preference.poster'),
            self::Either => __('enums.presentation_preference.either'),
        };
    }
```

`app/Enums/ReminderThreshold.php:25-33`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::Days7 => __('enums.reminder_threshold.days_7'),
            self::Days3 => __('enums.reminder_threshold.days_3'),
            self::Days1 => __('enums.reminder_threshold.days_1'),
            self::Overdue => __('enums.reminder_threshold.overdue'),
        };
    }
```

`app/Enums/ReviewMode.php:14-20`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::OpenPool => __('enums.review_mode.open_pool'),
            self::Assigned => __('enums.review_mode.assigned'),
        };
    }
```

`app/Enums/ReviewQuestionType.php:16-24`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::Likert => __('enums.review_question_type.likert'),
            self::Text => __('enums.review_question_type.text'),
            self::Boolean => __('enums.review_question_type.boolean'),
            self::Select => __('enums.review_question_type.select'),
        };
    }
```

`app/Enums/ReviewStatus.php:15-21`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('enums.review_status.draft'),
            self::Submitted => __('enums.review_status.submitted'),
        };
    }
```

`app/Enums/ReviewerStatus.php:15-21`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('enums.reviewer_status.active'),
            self::Removed => __('enums.reviewer_status.removed'),
        };
    }
```

`app/Enums/SubmissionStatus.php:27-38`

```php
    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('enums.submission_status.draft'),
            self::Submitted => __('enums.submission_status.submitted'),
            self::Withdrawn => __('enums.submission_status.withdrawn'),
            self::UnderReview => __('enums.submission_status.under_review'),
            self::Accepted => __('enums.submission_status.accepted'),
            self::Rejected => __('enums.submission_status.rejected'),
            self::Waitlisted => __('enums.submission_status.waitlisted'),
        };
    }
```

`app/Enums/Decision.php` — the docblock above `getLabel()` (`:36-44`) only; the method is unchanged. Old:

```php
    /**
     * The label is what `{{decision}}` expands to in the decision email, so it
     * is a sentence fragment an author reads ("has been **accepted for oral
     * presentation**"), not a panel word. It is deliberately NOT translated
     * through __() here, for the same reason every other enum in this codebase
     * is not: the language sweep of spec section 10 is a single backlog item
     * covering all of them, and half-translating one enum is worse than
     * translating none.
     */
```

New:

```php
    /**
     * The label is what `{{decision}}` expands to in the decision email, so it
     * is a sentence fragment an author reads ("has been **accepted for oral
     * presentation**"), not a panel word. It is the one enum label NOT looked
     * up in lang/en/enums.php, deliberately: a letter's `{{decision}}` has to
     * follow the language the LETTER is written in, not the language of
     * whoever's panel sent it, and that is the bilingual-templates item of
     * spec section 14 (backlog). tests/Feature/LanguageCoverageTest.php pins
     * this exception, so translating it is a decision rather than a tidy-up.
     */
```

`DemoStage.php` and `SubmissionWindow.php` are not touched (decision 3).

- [ ] **Step 5: The export headings and the publishing blockers**

`app/Support/Scoring/RankingRows.php:27-37`. Old:

```php
    /** @return list<string> */
    public static function headers(): array
    {
        return [
            'Reference', 'Title', 'Track', 'Presentation preference',
            'Score', 'Spread', 'Reviews',
            'Status', 'Decision', 'Decision letter sent',
            'Corresponding author', 'Corresponding email', 'All authors',
            'Submitted at',
        ];
    }
```

New:

```php
    /**
     * The words are lang/en/export.php's, shared with the submission-list
     * export wherever the two print the same column; the order is this
     * method's, because it is the file format and must match row() below.
     *
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            __('export.headings.reference'),
            __('export.headings.title'),
            __('export.headings.track'),
            __('export.headings.presentation_preference'),
            __('export.headings.score'),
            __('export.headings.spread'),
            __('export.headings.reviews'),
            __('export.headings.status'),
            __('export.headings.decision'),
            __('export.headings.decision_letter_sent'),
            __('export.headings.corresponding_author'),
            __('export.headings.corresponding_email'),
            __('export.headings.all_authors'),
            __('export.headings.submitted_at'),
        ];
    }
```

`app/Actions/Submissions/ExportSubmissionsCsv.php` — three edits. The constant (`:29-33`). Old:

```php
    private const HEADERS = [
        'Reference', 'Conference', 'Title', 'Status', 'Track', 'Presentation preference',
        'Corresponding author', 'Corresponding email', 'All authors', 'Affiliations',
        'Contact phone', 'Word count', 'Files', 'Extra answers', 'Submitted at', 'Last edited at',
    ];
```

New:

```php
    /**
     * The heading row. A method rather than the constant it was, because a
     * constant cannot call __(): the words are lang/en/export.php's, shared
     * with RankingRows::headers() wherever the two files print the same
     * column, and the order is this method's because it is the file format
     * and must match row() below.
     *
     * @return list<string>
     */
    private static function headers(): array
    {
        return [
            __('export.headings.reference'),
            __('export.headings.conference'),
            __('export.headings.title'),
            __('export.headings.status'),
            __('export.headings.track'),
            __('export.headings.presentation_preference'),
            __('export.headings.corresponding_author'),
            __('export.headings.corresponding_email'),
            __('export.headings.all_authors'),
            __('export.headings.affiliations'),
            __('export.headings.contact_phone'),
            __('export.headings.word_count'),
            __('export.headings.files'),
            __('export.headings.extra_answers'),
            __('export.headings.submitted_at'),
            __('export.headings.last_edited_at'),
        ];
    }
```

Its one use (`:49`). Old: `            $writer->addRow(Row::fromValues(self::HEADERS));` — new: `            $writer->addRow(Row::fromValues(self::headers()));`

The checkbox answer in `extraAnswers()` (`:125`). Old: `                is_bool($value) => $value ? 'yes' : 'no',` — new: `                is_bool($value) => $value ? __('export.answers.yes') : __('export.answers.no'),`

`app/Actions/Conferences/PublishConference.php:31-59` — the eight sentences. Old:

```php
        if ($organization->status === OrganizationStatus::Pending) {
            $reasons[] = 'Your organization is still waiting for platform approval. You can publish as soon as it is approved.';
        }

        if ($organization->status === OrganizationStatus::Suspended) {
            $reasons[] = 'This organization is suspended, so its conferences cannot be published.';
        }

        if ($conference->submission_opens_at === null || $conference->submission_deadline === null) {
            $reasons[] = 'Set both a submission opening date and a submission deadline.';
        } else {
            if ($conference->submission_deadline->isPast()) {
                $reasons[] = 'The submission deadline is in the past. Choose a future date and time.';
            }

            if ($conference->submission_deadline->lessThanOrEqualTo($conference->submission_opens_at)) {
                $reasons[] = 'The submission deadline must come after the submission opening date.';
            }
        }

        if (($conference->reviewForm()->first()?->questions()->count() ?? 0) === 0) {
            $reasons[] = 'The review form has no questions yet. Add at least one before publishing.';
        }

        if ($conference->status === ConferenceStatus::Archived) {
            $reasons[] = 'An archived conference cannot be published again.';
        } elseif (! $conference->status->canTransitionTo(ConferenceStatus::Open)) {
            $reasons[] = 'A conference that is '.strtolower($conference->status->getLabel()).' cannot be opened for submissions.';
        }
```

New:

```php
        if ($organization->status === OrganizationStatus::Pending) {
            $reasons[] = __('organizer.publish.errors.pending');
        }

        if ($organization->status === OrganizationStatus::Suspended) {
            $reasons[] = __('organizer.publish.errors.suspended');
        }

        if ($conference->submission_opens_at === null || $conference->submission_deadline === null) {
            $reasons[] = __('organizer.publish.errors.no_window');
        } else {
            if ($conference->submission_deadline->isPast()) {
                $reasons[] = __('organizer.publish.errors.deadline_past');
            }

            if ($conference->submission_deadline->lessThanOrEqualTo($conference->submission_opens_at)) {
                $reasons[] = __('organizer.publish.errors.deadline_order');
            }
        }

        if (($conference->reviewForm()->first()?->questions()->count() ?? 0) === 0) {
            $reasons[] = __('organizer.publish.errors.no_questions');
        }

        if ($conference->status === ConferenceStatus::Archived) {
            $reasons[] = __('organizer.publish.errors.archived');
        } elseif (! $conference->status->canTransitionTo(ConferenceStatus::Open)) {
            // mb_strtolower() and a placeholder, the shape StartReviewing and
            // MarkDecided already use for the same sentence.
            $reasons[] = __('organizer.publish.errors.wrong_status', [
                'status' => mb_strtolower($conference->status->getLabel()),
            ]);
        }
```

The docblock above `blockers()` (*"Every failure is a sentence the organizer can act on, not a validation code"*) stays true and stays.

- [ ] **Step 6: The three admin tables**

Every hunk below either adds `->label(__('…'))` to a column or filter that had none, or swaps a literal for a key. Nothing else on these lines moves, and no `recordActions`, `purgeAction()` or `confirmationRule()` line is touched — Tasks 3 and 4 anchor on those.

`app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php:33-39`. Old:

```php
                TextColumn::make('name')->searchable()->sortable()
                    ->description(fn (Conference $record): string => $record->slug),
                TextColumn::make('organization.name')->label('Organization')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('submissions_count')
                    ->counts('submissions')
                    ->label('Abstracts')
```

New:

```php
                TextColumn::make('name')->label(__('admin.conferences.columns.name'))->searchable()->sortable()
                    ->description(fn (Conference $record): string => $record->slug),
                TextColumn::make('organization.name')->label(__('admin.conferences.columns.organization'))->searchable()->sortable(),
                TextColumn::make('status')->label(__('admin.conferences.columns.status'))->badge()->sortable(),
                TextColumn::make('submissions_count')
                    ->counts('submissions')
                    ->label(__('admin.conferences.columns.abstracts'))
```

`:52` — `                    ->label('Decided')` becomes `                    ->label(__('admin.conferences.columns.decided'))`.
`:58` — `                    ->label('Letters sent')` becomes `                    ->label(__('admin.conferences.columns.notified'))`.

`:62-69`. Old:

```php
                TextColumn::make('submission_deadline')->label('Deadline')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->description(fn (Conference $record): string => $record->timezone)
                    ->placeholder('Not set'),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ConferenceStatus::class)->multiple(),
```

New:

```php
                TextColumn::make('submission_deadline')->label(__('admin.conferences.columns.deadline'))->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->description(fn (Conference $record): string => $record->timezone)
                    ->placeholder(__('admin.conferences.not_set')),
                TextColumn::make('created_at')->label(__('admin.conferences.columns.created'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.conferences.filters.status'))->options(ConferenceStatus::class)->multiple(),
```

`app/Filament/Admin/Resources/EmailLogs/Tables/EmailLogsTable.php:21-25`. Old:

```php
                TextColumn::make('created_at')->label('Queued')->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('to_email')->label('To')->searchable()->copyable(),
                TextColumn::make('subject')->limit(60)->wrap()->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('template_key')->label('Template')->placeholder('-')->toggleable(),
```

New:

```php
                TextColumn::make('created_at')->label(__('admin.email_log.columns.queued'))->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('to_email')->label(__('admin.email_log.columns.to'))->searchable()->copyable(),
                TextColumn::make('subject')->label(__('admin.email_log.columns.subject'))->limit(60)->wrap()->searchable(),
                TextColumn::make('status')->label(__('admin.email_log.columns.status'))->badge()->sortable(),
                TextColumn::make('template_key')->label(__('admin.email_log.columns.template'))->placeholder('-')->toggleable(),
```

`:28` — `                TextColumn::make('mailable')->label('Sent by')` becomes `                TextColumn::make('mailable')->label(__('admin.email_log.columns.sent_by'))`.

`:32-38`. Old:

```php
                TextColumn::make('organization.name')->label('Organization')->placeholder('Platform')->toggleable(),
                TextColumn::make('sent_at')->label('Sent')->dateTime('j M Y, H:i')->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(EmailLogStatus::class)->multiple(),
                SelectFilter::make('organization_id')
                    ->label('Organization')
```

New:

```php
                TextColumn::make('organization.name')->label(__('admin.email_log.columns.organization'))->placeholder(__('admin.email_log.platform'))->toggleable(),
                TextColumn::make('sent_at')->label(__('admin.email_log.columns.sent'))->dateTime('j M Y, H:i')->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.email_log.filters.status'))->options(EmailLogStatus::class)->multiple(),
                SelectFilter::make('organization_id')
                    ->label(__('admin.email_log.filters.organization'))
```

`:46` — `            ->emptyStateHeading('Nothing sent yet');` becomes `            ->emptyStateHeading(__('admin.email_log.empty'));`. The `'-'` placeholders stay: a dash is not language.

`app/Filament/Admin/Resources/Organizations/Tables/OrganizationsTable.php:33-41`. Old:

```php
                TextColumn::make('name')->searchable()->sortable()->description(fn (Organization $record): string => $record->slug),
                TextColumn::make('type')->badge(),
                TextColumn::make('country')->formatStateUsing(fn (string $state): string => config('cass.countries')[$state] ?? $state),
                TextColumn::make('owners.email')->label('Owner')->listWithLineBreaks(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('created_at')->label('Registered')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(OrganizationStatus::class)->default(OrganizationStatus::Pending->value),
```

New:

```php
                TextColumn::make('name')->label(__('admin.organizations.columns.name'))->searchable()->sortable()->description(fn (Organization $record): string => $record->slug),
                TextColumn::make('type')->label(__('admin.organizations.columns.type'))->badge(),
                TextColumn::make('country')->label(__('admin.organizations.columns.country'))->formatStateUsing(fn (string $state): string => config('cass.countries')[$state] ?? $state),
                TextColumn::make('owners.email')->label(__('admin.organizations.columns.owner'))->listWithLineBreaks(),
                TextColumn::make('status')->label(__('admin.organizations.columns.status'))->badge()->sortable(),
                TextColumn::make('created_at')->label(__('admin.organizations.columns.registered'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.organizations.filters.status'))->options(OrganizationStatus::class)->default(OrganizationStatus::Pending->value),
```

The approve action (`:54`, `:58-59`, `:65`). Old:

```php
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve this organization?')
            ->modalDescription('The owner will be emailed and can publish conferences immediately.')
```

New:

```php
            ->label(__('admin.organizations.approve.action'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('admin.organizations.approve.heading'))
            ->modalDescription(__('admin.organizations.approve.description'))
```

and `                Notification::make()->success()->title("{$record->name} approved")->send();` becomes `                Notification::make()->success()->title(__('admin.organizations.approve.done', ['name' => e((string) $record->name)]))->send();`

The reject action (`:72`, `:77`, `:83`): `            ->label('Reject')` becomes `            ->label(__('admin.organizations.reject.action'))`; in the `Textarea::make('reason')` line, `->label('Reason sent to the owner')` becomes `->label(__('admin.organizations.reject.reason'))` and the rest of the line is unchanged; and `                Notification::make()->warning()->title("{$record->name} rejected")->send();` becomes `                Notification::make()->warning()->title(__('admin.organizations.reject.done', ['name' => e((string) $record->name)]))->send();`

The name is escaped with e(), as `ConferenceStatusActions` escapes a conference name: Filament renders the title through `sanitizeHtml()`, which keeps `style` and `class` (decision 6, fact 2.17). Every pinned English name passes through e() unchanged.

- [ ] **Step 7: Run the tests, then every suite that pins the old English**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact tests/Feature/LanguageCoverageTest.php tests/Feature/ExtractedEnglishTest.php tests/Feature/Admin/AdminTableLanguageTest.php > /tmp/t-task-2.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-2.log
```

Expected: `rc=0`, **60 passed** — the 52 from Step 2 still green and the eight that failed now passing. If `leaves no visible english…` still lists a line, that literal was missed in Steps 4-6; if `prints the same english headings…` or `ExtractedEnglishTest` fails, a key's English differs from the literal it replaced by a character — fix the language file, never the pin.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact tests/Unit/PublishConferenceTest.php tests/Feature/Organizer/ConferenceTransitionsTest.php tests/Feature/Organizer/ConferenceRankingExportTest.php tests/Feature/Organizer/SubmissionResourceTest.php tests/Feature/Admin/ > /tmp/t-task-2b.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-2b.log
```

Expected: `rc=0`. These run **unedited** (fact 2.12): the seven blocker sentences, the publish refusal's body, both exports' data cells, the CSV formula guard and every admin screen. A failure here is an English word that moved.

- [ ] **Step 8: Pint and Larastan**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint --test > /tmp/pint-task-2.log 2>&1; echo "pint rc=$?"; tail -5 /tmp/pint-task-2.log && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan-task-2.log 2>&1; echo "stan rc=$?"; tail -5 /tmp/stan-task-2.log
```

Expected: both `rc=0`. Larastan types `__('…')` as a *benevolent* `array|string` union (`vendor/larastan/larastan/src/ReturnTypes/DoubleUnderscoreHelperReturnTypeExtension.php:37-39`), which level 6 accepts wherever a `string` is declared — so `getLabel(): string`, the `list<string>` returns and `$reasons[] = __(…)` need no cast, exactly as the newer actions (`StartReviewing`, `MarkDecided`) already do without one.

- [ ] **Step 9: The whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test > /tmp/all-task-2.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-2.log
```

Expected: `rc=0`, **Task 1's count + 45**, that is **baseline + 58**, passed, 1 skipped (4 new cases in `LanguageCoverageTest`, 21 in `ExtractedEnglishTest`, 20 in `AdminTableLanguageTest`). The prototype ran on bare `45d2d8e`, without Task 1 and before the review added the escaping case, and went from 1214 to 1258.

- [ ] **Step 10: Commit**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test > /tmp/all-task-2.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add lang/en/enums.php lang/en/export.php lang/en/organizer.php lang/en/admin.php app/Enums app/Support/Scoring/RankingRows.php \
  app/Actions/Submissions/ExportSubmissionsCsv.php app/Actions/Conferences/PublishConference.php \
  app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php app/Filament/Admin/Resources/EmailLogs/Tables/EmailLogsTable.php \
  app/Filament/Admin/Resources/Organizations/Tables/OrganizationsTable.php \
  tests/Feature/LanguageCoverageTest.php tests/Feature/ExtractedEnglishTest.php tests/Feature/Admin/AdminTableLanguageTest.php && \
git commit -q -F - <<'MSG' && git log --oneline -1
i18n: every enum label, both export headings, the publishing blockers and the last three admin tables

Every earlier language sweep skipped any file that was not a Blade view, so
sixteen enums, two spreadsheet heading rows, PublishConference's checklist and
three admin tables passed them all in English. LanguageCoverageTest now reads
PHP for English by its tokens, and three runtime cases under a keyless locale
catch what no literal holds: the two ucfirst() enums, a bare 'yes', and the
labels Filament made up from a column name. Decision::getLabel() stays English
and is pinned - it is {{decision}} in every letter and moves with spec section
14's bilingual templates.

The English is byte for byte what it was: ExtractedEnglishTest and the table
pins were written against the old code and pass on both sides. The approve
and reject notifications now escape the organization's name, which an
anonymous registrant chooses, as ConferenceStatusActions already does.

<the session's Co-Authored-By trailer>
MSG
```

Expected: `all rc=0` and one new commit. The message's last line is a placeholder: put the session's Co-Authored-By trailer there before running the command. `git add` names its files rather than `-A`, so a stray file from another task cannot ride along — `git status --short --untracked-files=no` afterwards must print nothing.

---


### Task 3: The platform admin edits an organization — profile, branding and custom domain

This task closes the profile half of one `Launch` backlog entry (Task 4 closes the members half) and two `Organizer features` entries:

> **Spec section 4's two platform-admin write cells have no screen.** "Manage organization members" and "Manage organization profile, branding, domain" are ticked for the platform admin in the spec table. Plan 6 added read-only relation managers and a read-only `OrganizationResource`; `OrganizationPolicy::update()` already answers true for a platform admin, so the missing half is an admin edit page plus role-change and remove-member actions, each with an activity entry and an organizer-forbidden test. Until then the support path is the console.

> Delete replaced logos from the `branding` disk (`FileUpload::deleteUploadedFileUsing` or a cleanup job).

> Reject or downscale organization logos above 16 MP at upload (`EditOrganizationProfile`), so `GenerateConferencePoster::logoDataUri()` never has to drop one silently.

The organizer already edits all of this on their tenant profile page. This task moves that page's form into one class both panels render, puts the write behind an action both pages call, adds an `EditRecord` page to the admin's `OrganizationResource`, and makes the shared write delete the logo it replaced and refuse the logo the poster would drop.

It also closes a gap that no backlog entry names, found while writing it: the hard purge deletes an organization's private files and never its logo, so a purged organization's logo stays public at `/storage/branding/logos/…`. The purge now releases it through the same rule as a replaced logo.

**Facts this task relies on.**

3.1. **Installed versions** (`composer.lock`): `filament/filament` v5.8.1 (`:1277-1278`), `laravel/framework` v13.32.0 (`:2327-2328`), `livewire/livewire` v4.4.4 (`:3481-3482`), `spatie/laravel-activitylog` 5.1.1 (`:5519-5520`).

3.2. **The spec cell.** `docs/superpowers/specs/2026-09-10-cass-v2-design.md:84` — `| Manage organization profile, branding, domain | ✔ | ✔ | ✔ | | | |`: platform admin, org owner, org admin.

3.3. **The organizer page writes the row through Filament, not through an action, and logs nothing for it.** `app/Filament/Organizer/Pages/Tenancy/EditOrganizationProfile.php` has no `handleRecordUpdate()`. Its parent's `save()` (`vendor/filament/filament/src/Pages/Tenancy/EditTenantProfile.php:112-149`) calls `$this->handleRecordUpdate($this->tenant, $data)` (`:127`), which is `$record->update($data)` (`:154-159`). The model's own `LogsActivity` logs only `name`, `slug`, `custom_domain` and `custom_domain_verified_at` (`app/Models/Organization.php:171-177`), so a colour or logo change leaves no audit trail.

3.4. **Everything in that form reads the organization from `Filament::getTenant()`**, through `static::tenant()` (`EditOrganizationProfile.php:130-135`): the status badge (`:137-159`), `canManage()` (`:161-172`, `roleIn()->canManageOrganization()`), the three domain actions (`:193-317`) and the records partial's `viewData` (`:100-102`). The admin panel has no tenant. The domain actions sit inside `Actions::make([...])->key('custom_domain_actions')` (`:103-107`), and `tests/Feature/Organizer/CustomDomainTest.php:28-31` reaches them through a **global** helper `domainAction()` — a second test file declaring a function of that name is a fatal redeclare.

3.5. **A schema action gets the schema's record from Filament, and the two candidate records on the organizer page are different instances.** `InteractsWithRecord::getRecord()` falls back to `$this->getSchemaComponent()?->getRecord()` (`vendor/filament/actions/src/Concerns/InteractsWithRecord.php:129-131`); a schema's record is the model handed to `->model()` (`vendor/filament/schemas/src/Concerns/BelongsToModel.php:90-107`). `EditTenantProfile::mount()` copies `Filament::getTenant()` into `$this->tenant` (`:67-74`) and `defaultForm()` hands the schema `$this->tenant` (`:184-190`). `ViewComponent::viewData()` accepts a closure and evaluates it with injection (`vendor/filament/support/src/Components/ViewComponent.php:83-88`, `:122-128`). **Measured in the prototype:** with the page's `$this->tenant` as the record, five of the twelve `CustomDomainTest` cases fail, because Livewire rehydrates `$this->tenant` as a new instance between calls and those cases re-mount the page and read the panel's tenant instance (for example `:117-134`); with `Filament::getTenant()` as the record all twelve pass unedited.

3.6. **`OrganizationPolicy::update()` already answers both questions** (`app/Policies/OrganizationPolicy.php:52-55`): `is_platform_admin`, or `roleIn()->canManageOrganization()`. The class has no `before()` (`:14-23`).

3.7. **The admin resource and its two walls.** `OrganizationResource::canAccess()` is `is_platform_admin` (`app/Filament/Admin/Resources/Organizations/OrganizationResource.php:56-62`); `getPages()` registers `index` and `view` only (`:90-96`); `ViewOrganization::getHeaderActions()` is approve, reject, purge (`app/Filament/Admin/Resources/Organizations/Pages/ViewOrganization.php:15-22`). Every resource page aborts 403 unless `canAccess()` on mount **and** hydrate (`vendor/filament/filament/src/Resources/Pages/Concerns/CanAuthorizeResourceAccess.php:7-24`, used at `vendor/filament/filament/src/Resources/Pages/Page.php:40`); `EditRecord` also aborts unless `canEdit($record)` (`vendor/filament/filament/src/Resources/Pages/EditRecord.php:98-101`) — the policy's `update()`, which says yes to the organization's own owner. `EditRecord::save()` calls `handleRecordUpdate($this->getRecord(), $data)` (`:176`), by default `$record->update($data)` (`:281-286`).

3.8. **Both `save()` methods open a transaction only if the panel opts in, and none does.** `beginDatabaseTransaction()` (`EditTenantProfile.php:115`, `EditRecord.php:164`) is a no-op unless `hasDatabaseTransactions()`, which defaults to `false` (`vendor/filament/filament/src/Panel/Concerns/HasDatabaseTransactions.php:9`); `grep -rn databaseTransactions app/Providers` returns nothing. `DB::afterCommit()` runs its callback at once when no transaction is open and after the outermost commit otherwise (`vendor/laravel/framework/src/Illuminate/Database/DatabaseTransactionsManager.php:213-220`); under `RefreshDatabase` the test's wrapping transaction is skipped for that purpose (`vendor/laravel/framework/src/Illuminate/Foundation/Testing/DatabaseTransactionsManager.php:32-52`).

3.9. **Nothing deletes from the `branding` disk, and Filament's hook would delete at the wrong moment.** The disk is local, `storage/app/public/branding` (`config/filesystems.php:52-58`); its only writers and readers are the `FileUpload` (`EditOrganizationProfile.php:68`), `GenerateConferencePoster` (`app/Actions/Conferences/GenerateConferencePoster.php:69`) and two `url()` calls in views. `deleteUploadedFileUsing` defaults to `null` (`vendor/filament/forms/src/Components/BaseFileUpload.php:79`) and is run by `deleteUploadedFile()` (`:789-808`) — the Livewire endpoint the browser calls when the file is removed in the picker, **before** Save and whether or not Save ever happens. The order this codebase uses for files is row first, object after the commit (`app/Actions/Submissions/DeleteSubmissionFile.php:11-37`).

3.10. **File-path tampering protection is off by default.** `$shouldPreventFilePathTampering = false` (`BaseFileUpload.php:71`); when on, a rule refuses any stored path that is not one of the record's original paths (`:728-749`, `getOriginalFilePaths()` at `:1060-1074` reads `$record->getOriginal('logo_path')`).

3.11. **A `FileUpload` rule is run against each upload on its own.** `BaseFileUpload::getValidationRules()` validates `["{$name}.*" => ['file', ...$fileRules]]` over the field's `TemporaryUploadedFile`s (`:752-771`), so a custom rule receives the upload, not the field's array. Livewire's `TemporaryUploadedFile::dimensions()` streams the file off its disk and returns `getimagesize()` (`vendor/livewire/livewire/src/Features/SupportFileUploads/TemporaryUploadedFile.php:127-132`). A closure rule has to be wrapped in a zero-argument closure, the shape `contrastRule()` already uses and explains (`EditOrganizationProfile.php:112-127`).

3.12. **The line the poster drops a logo at.** `GenerateConferencePoster::logoDataUri()` returns `null` when `($size[0] * $size[1]) > 16_000_000` (`GenerateConferencePoster.php:81`): exactly 16,000,000 pixels is kept, 16,000,001 is dropped, silently. Its docblock says the upload rules have "no dimension limit" (`:46-60`). The upload field is `->image()->maxSize(2048)->acceptedFileTypes(['image/png', 'image/jpeg'])` (`EditOrganizationProfile.php:68-72`).

3.13. **The profile columns are fillable and the domain columns are not.** `$fillable` (`Organization.php:30-33`) holds `name, type, country, website, contact_email, publish_contact_email, purpose, logo_path, primary_color, accent_color`; `Model::preventSilentlyDiscardingAttributes()` is on outside production (`app/Providers/AppServiceProvider.php:41`).

3.14. **The domain actions log with whatever actor they are handed.** `ClaimCustomDomain.php:75-79`, `VerifyCustomDomain.php:83-87` and `ReleaseCustomDomain.php:35-39` each call `activity()->causedBy($actor)`. Handing them the admin makes the admin the causer; nothing in them asks for a membership.

3.15. **How a logo replacement looks in a Livewire test.** `fillForm()` sets an `UploadedFile` through Livewire (`vendor/filament/forms/src/Testing/TestsForms.php:55-61`). On a field that already holds a stored path the state becomes the old path *plus* the upload — measured: `["<uuid>" => "logos/old.png", 0 => TemporaryUploadedFile]` — and a single-file field saves the first. The browser's picker removes the stored file before it uploads, which in a test is `fillForm(['logo_path' => []])` first. (`null` instead leaves a bare `TemporaryUploadedFile` as the state, and `BaseFileUpload`'s `array`-typed rule closure at `:752` throws a `TypeError`.) The factory leaves the colours to their database defaults, so a partially filled form needs `refresh()` first (`tests/Feature/Organizer/OrganizationProfileTest.php:77-79`). `UploadedFile::fake()->image()` draws with GD — at 4001 × 4000 a 64 MB truecolor buffer — so the 16 MP test writes the PNG bytes itself.

3.16. **The page's strings are hardcoded English today**: `EditOrganizationProfile.php:48` (`'Organization profile'`), `:54`, `:57`, `:62`, `:64-65`, `:67-68`, `:71`, `:75`, `:78`, `:124`. The last case of `tests/Feature/LanguageCoverageTest.php` (`:524-577`) resolves every `__()` key under `app/`.

3.17. **How admin tests boot.** `User::factory()->platformAdmin()`, then `Filament::setCurrentPanel('admin'); Filament::bootCurrentPanel();` (`tests/Feature/Admin/OrganizationApprovalTest.php:30-33`); a non-admin's GET of an admin page is 403 (`:161-164`).

3.18. **The hard purge never touches the `branding` disk.** `PurgeOrganization::handle()` deletes rows in one transaction and only after it the private objects — `Storage::disk('local')->delete($path)` (`app/Actions/Organizations/PurgeOrganization.php:138-143`), reported as `'private files'` (`:145`); its docblock gives the reason for that order (`:44-51`). `PurgeConference` does the same for one conference (`app/Actions/Conferences/PurgeConference.php:81-96`), and an organization outlives a conference purge. Neither class reads `logo_path`, so a purged organization's logo stays on a disk that is public by URL (fact 3.9) — the URL every email the organization sent carries (`resources/views/mail/templated.blade.php:27`) and every conference page's `og:image` named (`resources/views/components/layouts/conference.blade.php:25`).

3.19. **The demo reset is the same purge.** `PurgeDemoOrganization::handle()` calls `PurgeOrganization::handle()` (`app/Actions/Demo/PurgeDemoOrganization.php:54`), and `cass:demo-reset` prints every key of the result as a table row (`app/Console/Commands/DemoResetCommand.php:67-75`). The seeder never writes `logo_path` (`grep -rn logo_path app/Actions/Demo` prints nothing), and `tests/Feature/Console/DemoResetTest.php` fakes only the `local` disk (`:32`) and never sets a logo — so a purge that reaches for `branding` only when a logo exists leaves that test exactly as it is.

3.20. **The preview and the run must report the same keys, and the admin action reads them by name.** `tests/Unit/PurgeConferenceTest.php:285-310` asserts that every key `PurgeOrganization::handle()` returns is in `preview()` with the same number. `OrganizationsTable::purgeAction()` shows `preview()` in its modal and, after the run, takes `'private files'` out of the result as the file count and sums everything else as rows (`app/Filament/Admin/Resources/Organizations/Tables/OrganizationsTable.php:131-139`); the modal prints `'private files'` as `admin.purge.files` and any other key as a table name (`resources/views/filament/admin/partials/purge-counts.blade.php:9`). A new key that neither knows would print as a raw name and be counted as a row. Task 2 changes no line of `purgeAction()` (its file list says so), and no other task touches the modal view.

3.21. **The `branding` disk deletes the normalised path, and a path that climbs out of the disk throws.** `League\Flysystem\Filesystem::delete()` hands the adapter `normalizePath($location)` (`vendor/league/flysystem/src/Filesystem.php:84-87`), and `WhitespacePathNormalizer::normalizePath()` (`vendor/league/flysystem/src/WhitespacePathNormalizer.php:22`) folds `logos/./beta.png`, `logos//beta.png`, `./logos/beta.png` and `logos/x/../beta.png` to `logos/beta.png` and throws `PathTraversalDetected` for `logos/../../x` (`:41`). `Illuminate\Filesystem\FilesystemAdapter::delete()` catches only `UnableToDeleteFile` (`vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemAdapter.php:600-621`). Measured with a local adapter: `delete('logos/./beta.png')` returned `true` and removed `logos/beta.png`; `delete('logos/../../x')` threw. Filament itself only ever stores `logos/` + `Str::ulid()` + `.` + the client's extension (`BaseFileUpload.php:136-138`, and `->directory('logos')` at `EditOrganizationProfile.php:68`). Before this task nothing guarded the field (fact 3.10), so a row written by a hand-edited request can hold any string.

**Decisions this task makes.**

1. **One form and one write for both panels: `App\Filament\Schemas\OrganizationProfileForm` and `App\Actions\Organizations\UpdateOrganizationProfile`.** The organizer page writes the row with Filament's `$record->update($data)` (fact 3.3), so reusing "the action" first means there has to be one. A second form on the admin page would be three hundred copied lines — the contrast rule, the domain checks, the claim/verify/release wiring and its error-bag workaround — that drift the first time one copy is fixed. The form moves verbatim except for where it reads the organization (decision 2), the two new logo rules (decisions 5 and 6) and its strings (decision 8), and `OrganizationProfileTest` and `CustomDomainTest` pass **unedited**: that is the proof the move changed nothing. `app/Filament/Schemas/` is new; `app/Filament/Auth/Login.php` is the precedent for a cross-panel class outside any one panel's folder.

2. **Every closure in the form takes Filament's injected `$record`, and the organizer page hands its schema `Filament::getTenant()` as that record.** `Filament::getTenant()` is null in the admin panel (fact 3.4); the schema's record is the thing both pages have (fact 3.5). On the organizer page the record is set to the panel's tenant instance rather than left at `$this->tenant`: the same row, but the panel's instance is the one the domain actions wrote to before the move, and leaving `$this->tenant` there fails five existing domain cases (fact 3.5). Write permission inside the form is `Gate::allows('update', $record)` — `OrganizationPolicy::update()` (fact 3.6), which is the page's old `canManage()` plus the platform admin. Every action body re-checks it, because an action is a Livewire endpoint a client can call by name.

3. **The admin page is an `EditRecord` on the existing `OrganizationResource`, opened from the view page's header.** It is behind the two walls Filament already has (fact 3.7): `canAccess()` (platform admin) on every mount and hydrate, then `canEdit()` (`OrganizationPolicy::update()`). The second alone admits the organization's own owner, and the test mounts the page as that owner to prove the first holds. No new policy method: `update()` already says what spec section 4 says. The list's row actions are not touched — that table is the approval queue, filtered to pending by default, and Task 2 rewrites its column lines. **The slug stays read-only on both pages**: it is the `{organization}` in every public `/c/{organization}/{conference}` URL (`routes/web.php:57`) and in every such link already shared, and whether a platform admin may rename it is the owner's question (report).

4. **A replaced or cleared logo is deleted by `UpdateOrganizationProfile`, after the commit, and only once no row points at it.** Not `deleteUploadedFileUsing`: Filament runs it when the file is removed in the picker, before Save and whether or not Save happens (fact 3.9) — cancel the edit and the row points at a deleted file on every public page and in every email. Not a cleanup job: nothing else knows which file was replaced, and a sweep of "files no row references" is a second deletion path to keep correct. **`DB::afterCommit()`** rather than `DeleteSubmissionFile`'s line after `DB::transaction()`, because both callers are Filament `save()` methods that put the whole write inside a transaction of their own the day a panel turns on `->databaseTransactions()` (fact 3.8): the delete then waits for *that* commit and a rollback discards it; today it runs as the action's own commit returns. The existence check reads the database, so a save that failed keeps its file, and so does a path that another organization's row also holds; a path in any spelling Filament does not write is never deleted at all (decision 5). A test drives each case. The rule — delete only once no row, trashed or not, points at the file — is one small class, `DeleteOrganizationLogo`, because the purge needs it too (decision 9).

5. **`->preventFilePathTampering()` goes on the logo field.** Filament leaves it off (fact 3.10). Before this task a hand-edited Livewire payload that set `logo_path` to another organization's file — whose name is in the `<img>` on that organization's public pages — only borrowed a logo. With decision 4 the *next* replacement would delete it. The form now refuses any stored path that is not the record's own. For rows written before this release, decision 4's database check catches an exact copy, `DeleteOrganizationLogo::isCanonical()` refuses any other spelling — the disk normalises `logos/./x.png` to `logos/x.png` before it deletes, and throws on a path that climbs out of it (fact 3.21) — and a pre-deploy audit in the pull request proves neither kind exists in production. `isCanonical()` is `logos/` + letters and digits + an optional extension: every path Filament writes, and fail-closed, so an unusual client extension only leaves bytes behind.

6. **Above 16 MP is refused at validation, on the shared field, against one constant: `GenerateConferencePoster::MAX_LOGO_PIXELS`.** The poster's literal (fact 3.12) becomes the constant and the form's rule reads it, so the two cannot disagree; both compare with `>`, and the tests pin both sides of the line — 4000 × 4000 is accepted, 4001 × 4000 refused with a message that names the image's size and the limit. **Refused, not downscaled**: downscaling means decoding the image with GD inside a php-fpm worker — the cost the poster's guard exists to avoid — and an organizer told "this is 6000 × 3500, the limit is 16 megapixels" can fix it in any image editor. The rule reads the header only (`TemporaryUploadedFile::dimensions()`, fact 3.11). The poster keeps its own check for logos stored before this release, and its docblock says so. The help text now states the limit too.

7. **One `organization.profile_updated` activity entry per save that changed something, the actor as causer, the changed attribute names as properties.** Profile and branding edits log nothing today (fact 3.3). Names, not values: the row holds the values, the model's automatic entry already records an old and new name, and a contact address does not need a second copy in the audit log. The three domain actions already log with whoever they are handed (fact 3.14), so the admin page hands them the admin and they need no change. **Nobody is emailed when a platform admin edits an organization or its members** (Task 4's writes included) — no admin write tells the organization anything today except approve and reject (`ApproveOrganization`, `RejectOrganization`; the purge sends nothing, and Verify mails the platform admins), and whether an owner should be told is the owner's question (report).

8. **The form's strings move to `lang/en/admin.php` under `admin.organization.*` — including the ones the organizer page had hardcoded.** `OrganizationProfileForm` is a new file on the `$plan7Sources` list, and the English it inherits (fact 3.16) would otherwise be new hardcoded English in a second panel. One form, one set of keys; the group is `admin.organization` because the admin page is what made the form shared, and Task 2 leaves that group to this task. The English is unchanged except the logo help text (decision 6).

9. **The hard purge releases the organization's logo through the same `DeleteOrganizationLogo`, in the disk pass it already runs after its commit.** The logo is the one file a purge leaves behind, and the public one (fact 3.18). It goes on the same side of the commit as the private objects and for the same reason; and with the purge's own discipline — a line after its `DB::transaction()` — rather than decision 4's `afterCommit()`, because one method with two disk disciplines is harder to read than a class with one, and the purge's callers (the admin action, `cass:demo-reset`) open no transaction around it. The shared rule means a path that another organization's row also holds survives the purge exactly as it survives a replacement, and the path is read from the row before the transaction, not from the caller's instance. `PurgeConference` is untouched: the organization outlives it, and a test pins that. `cass:demo-reset` goes through `PurgeOrganization` (fact 3.19), so it releases a demo logo too; `DemoResetTest` passes unedited because the seeded demo has none, and a new case covers a demo organization that has one.

10. **The logo is its own key, `branding files` (0 or 1), in both `handle()` and `preview()`; the modal names it and the notification counts it as a file.** `preview()` has to report it — the parity test fails otherwise (fact 3.20) — and it belongs there: the modal is the list of what is about to be destroyed, and a public file is the item on it an admin is least likely to think of. It is not folded into `private files`: that key names the disk it deleted from. The modal reads "1 logo file on the public branding disk"; the notification counts it among the `:files files`, not the rows; `cass:demo-reset`'s table gains a `branding files` row.

**Files:**
- Create: `app/Actions/Organizations/UpdateOrganizationProfile.php`, `app/Actions/Organizations/DeleteOrganizationLogo.php`, `app/Filament/Schemas/OrganizationProfileForm.php`, `app/Filament/Admin/Resources/Organizations/Pages/EditOrganization.php`
- Modify: `app/Filament/Organizer/Pages/Tenancy/EditOrganizationProfile.php` (the form moves out; the page delegates)
- Modify: `app/Filament/Admin/Resources/Organizations/OrganizationResource.php` (`form()`, the `edit` page), `app/Filament/Admin/Resources/Organizations/Pages/ViewOrganization.php` (an Edit header action)
- Modify: `app/Actions/Conferences/GenerateConferencePoster.php` (`MAX_LOGO_PIXELS`)
- Modify: `app/Actions/Organizations/PurgeOrganization.php` (the logo in `handle()` and `preview()`), `app/Filament/Admin/Resources/Organizations/Tables/OrganizationsTable.php` (three lines of `purgeAction()`: the file count and the escaped name; nothing else), `app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php` (one line of `purgeAction()`: the escaped conference name), `resources/views/filament/admin/partials/purge-counts.blade.php` (the `<li>` line)
- Modify: `lang/en/admin.php` (an `organization` group; `purge.logo`)
- Modify: `tests/Pest.php` (`blankPng()`), `tests/Feature/LanguageCoverageTest.php` (three paths on `$plan7Sources`)
- Test: `tests/Feature/Admin/EditOrganizationTest.php` (new, 14 cases), `tests/Feature/Organizer/OrganizationProfileTest.php` (2 cases appended), `tests/Feature/Admin/PurgeLogoTest.php` (new, 8 cases)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Pest.php`, after `scoredReview()`:

Replace:

```php
        'value_int' => $value, 'value_text' => null, 'value_bool' => null, 'choice_key' => null,
    ])->save();

    return $review;
}
```

with:

```php
        'value_int' => $value, 'value_text' => null, 'value_bool' => null, 'choice_key' => null,
    ])->save();

    return $review;
}

/**
 * A well-formed PNG of any size that costs almost nothing to build or upload:
 * 8-bit greyscale, every pixel black, so the deflated image data is a few
 * kilobytes even at sixteen megapixels. GD is never involved -
 * imagecreatetruecolor(4001, 4000) is a 64 MB buffer, and
 * UploadedFile::fake()->image() would build exactly that - while getimagesize()
 * reads the size from the IHDR chunk alone. Here rather than in a test file
 * because both logo tests call it (see scoredReview() above for why).
 */
function blankPng(int $width, int $height): string
{
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    // Each row is one filter byte (0, "none") followed by one byte per pixel.
    $rows = str_repeat("\0".str_repeat("\0", $width), $height);

    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
        .$chunk('IDAT', (string) gzcompress($rows, 9))
        .$chunk('IEND', '');
}
```

Create `tests/Feature/Admin/EditOrganizationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Organizations\UpdateOrganizationProfile;
use App\Contracts\DnsResolver;
use App\Enums\OrganizationRole;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Filament\Admin\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Admin\Resources\Organizations\Pages\ViewOrganization;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CustomDomainVerified;
use App\Support\Domains\FakeDnsResolver;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

use Spatie\Activitylog\Models\Activity;

/**
 * Spec section 4's "Manage organization profile, branding, domain" cell for the
 * platform admin. The form is the organizer's own (App\Filament\Schemas\
 * OrganizationProfileForm), so these cases are about who may use it from the
 * admin panel and what it does to the row, the disk and the audit log - not a
 * second copy of the organizer page's field-by-field tests.
 */
beforeEach(function () {
    Storage::fake('branding');
    Notification::fake();

    $this->dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $this->dns);
    config()->set('cass.domains.cname_target', 'cass.towardpcc.com');

    $this->admin = User::factory()->platformAdmin()->create();
    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->owner = User::factory()->create();
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
});

/** The domain actions live inside the form schema, keyed exactly as on the organizer page. */
function adminDomainAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('custom_domain_actions');
}

it('registers an edit page and links to it from the view page', function () {
    expect(array_keys(OrganizationResource::getPages()))->toBe(['index', 'view', 'edit']);

    livewire(ViewOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->assertActionVisible('edit');

    get(OrganizationResource::getUrl('edit', ['record' => $this->organization], panel: 'admin'))
        ->assertOk()
        ->assertSee('Alpha Society')
        ->assertSee(__('domain.section.heading'));
});

it('saves the profile, the colours and a logo, and logs the admin as the causer', function () {
    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->fillForm([
            'name' => 'Alpha Pediatric Society',
            'primary_color' => '#0F4C8A',
            'accent_color' => '#0B5FA5',
            'logo_path' => UploadedFile::fake()->image('logo.png', 400, 200),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->organization->refresh();

    expect($this->organization->name)->toBe('Alpha Pediatric Society')
        ->and($this->organization->primary_color)->toBe('#0F4C8A')
        ->and($this->organization->logo_path)->not->toBeNull();

    Storage::disk('branding')->assertExists((string) $this->organization->logo_path);

    $entry = Activity::query()->where('description', 'organization.profile_updated')->sole();

    expect($entry->causer_id)->toBe($this->admin->getKey())
        ->and($entry->subject_id)->toBe($this->organization->getKey())
        ->and($entry->properties->get('changed'))->toContain('name', 'primary_color', 'logo_path');
});

it('still refuses a colour that fails contrast, from the admin page too', function () {
    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->fillForm(['primary_color' => '#BFE0F7'])
        ->call('save')
        ->assertHasFormErrors(['primary_color']);
});

it('deletes the replaced logo from the branding disk once the row is saved', function () {
    Storage::disk('branding')->put('logos/old.png', 'old bytes');
    $this->organization->forceFill(['logo_path' => 'logos/old.png'])->save();

    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        // The browser's file picker removes the stored file before it uploads
        // the new one. Filling the field alone would append the upload beside
        // the old path, and a single-file field saves the first.
        ->fillForm(['logo_path' => []])
        ->fillForm(['logo_path' => UploadedFile::fake()->image('new.png', 400, 200)])
        ->call('save')
        ->assertHasNoFormErrors();

    $new = (string) $this->organization->refresh()->logo_path;

    expect($new)->not->toBe('logos/old.png');
    Storage::disk('branding')->assertMissing('logos/old.png');
    Storage::disk('branding')->assertExists($new);
});

it('deletes the logo file when the logo is cleared', function () {
    Storage::disk('branding')->put('logos/old.png', 'old bytes');
    $this->organization->forceFill(['logo_path' => 'logos/old.png'])->save();

    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->fillForm(['logo_path' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->organization->refresh()->logo_path)->toBeNull();
    Storage::disk('branding')->assertMissing('logos/old.png');
});

it('keeps the old logo file when the save fails', function () {
    Storage::disk('branding')->put('logos/old.png', 'old bytes');
    $this->organization->forceFill(['logo_path' => 'logos/old.png'])->save();

    // A write that fails: the row keeps pointing at the old file, so the old
    // file must still be there. Deleting before the save is the order this
    // action exists to avoid.
    Organization::updating(function (): void {
        throw new RuntimeException('The database refused the write.');
    });

    expect(fn () => app(UpdateOrganizationProfile::class)->handle($this->organization, ['logo_path' => null], $this->admin))
        ->toThrow(RuntimeException::class);

    expect(Organization::query()->whereKey($this->organization->getKey())->value('logo_path'))->toBe('logos/old.png');
    Storage::disk('branding')->assertExists('logos/old.png');
});

it('waits for an outer transaction to commit, and keeps the file when it rolls back', function () {
    Storage::disk('branding')->put('logos/old.png', 'old bytes');
    $this->organization->forceFill(['logo_path' => 'logos/old.png'])->save();

    // What either save() becomes the day a panel turns on
    // ->databaseTransactions(): the action's own transaction is then nested,
    // and its commit is not the one that matters.
    try {
        DB::transaction(function (): void {
            app(UpdateOrganizationProfile::class)->handle($this->organization, ['logo_path' => null], $this->admin);

            Storage::disk('branding')->assertExists('logos/old.png');

            throw new RuntimeException('The page failed after the action returned.');
        });
    } catch (RuntimeException) {
        // The rollback is the point.
    }

    expect(Organization::query()->whereKey($this->organization->getKey())->value('logo_path'))->toBe('logos/old.png');
    Storage::disk('branding')->assertExists('logos/old.png');
});

it('refuses a logo above 16 megapixels and accepts one at exactly 16', function () {
    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->fillForm(['logo_path' => UploadedFile::fake()->createWithContent('huge.png', blankPng(4001, 4000))])
        ->call('save')
        ->assertHasFormErrors(['logo_path']);

    expect($this->organization->refresh()->logo_path)->toBeNull();

    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->fillForm(['logo_path' => UploadedFile::fake()->createWithContent('edge.png', blankPng(4000, 4000))])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->organization->refresh()->logo_path)->not->toBeNull();
});

it('edits one organization and leaves another untouched', function () {
    $other = Organization::factory()->approved()->create([
        'name' => 'Beta Society',
        'primary_color' => '#123456',
    ]);
    Storage::disk('branding')->put('logos/beta.png', 'beta bytes');
    $other->forceFill(['logo_path' => 'logos/beta.png'])->save();
    $before = $other->fresh()?->getAttributes();

    Storage::disk('branding')->put('logos/alpha.png', 'alpha bytes');
    $this->organization->forceFill(['logo_path' => 'logos/alpha.png'])->save();

    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->fillForm(['logo_path' => []])
        ->fillForm([
            'name' => 'Alpha Renamed',
            'primary_color' => '#0F4C8A',
            'logo_path' => UploadedFile::fake()->image('new.png', 400, 200),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($other->fresh()?->getAttributes())->toBe($before);
    Storage::disk('branding')->assertExists('logos/beta.png');
    Storage::disk('branding')->assertMissing('logos/alpha.png');
});

it('refuses a logo path that belongs to another organization, so the cleanup can never reach it', function () {
    $other = Organization::factory()->approved()->create();
    Storage::disk('branding')->put('logos/beta.png', 'beta bytes');
    $other->forceFill(['logo_path' => 'logos/beta.png'])->save();

    // A hand-edited Livewire payload naming another organization's file.
    // FileUpload::preventFilePathTampering() refuses any stored path that is
    // not the record's own, so the row can never come to point at it - and so
    // the next replacement can never delete it.
    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->set('data.logo_path', ['tampered' => 'logos/beta.png'])
        ->call('save')
        ->assertHasFormErrors(['logo_path']);

    expect($this->organization->refresh()->logo_path)->toBeNull();
    Storage::disk('branding')->assertExists('logos/beta.png');
});

it('never deletes a logo file another organization still points at', function () {
    // Rows written before this plan were never checked for tampering, so the
    // action re-checks at delete time rather than trusting the form alone.
    Storage::disk('branding')->put('logos/shared.png', 'bytes');
    $other = Organization::factory()->approved()->create();
    $other->forceFill(['logo_path' => 'logos/shared.png'])->save();
    $this->organization->forceFill(['logo_path' => 'logos/shared.png'])->save();

    app(UpdateOrganizationProfile::class)->handle($this->organization, ['logo_path' => null], $this->admin);

    expect($this->organization->fresh()?->logo_path)->toBeNull();
    Storage::disk('branding')->assertExists('logos/shared.png');
});

it('never deletes another organization\'s logo through another spelling of its path', function () {
    // The disk normalises a path before it deletes, so 'logos/./beta.png'
    // would remove 'logos/beta.png' - a file the exact-string row check above
    // never matches. Only a row written before this release can hold such a
    // spelling; DeleteOrganizationLogo::isCanonical() refuses every path
    // Filament would not have written.
    Storage::disk('branding')->put('logos/beta.png', 'beta bytes');
    $other = Organization::factory()->approved()->create();
    $other->forceFill(['logo_path' => 'logos/beta.png'])->save();
    $this->organization->forceFill(['logo_path' => 'logos/./beta.png'])->save();

    app(UpdateOrganizationProfile::class)->handle($this->organization, ['logo_path' => null], $this->admin);

    expect($this->organization->fresh()?->logo_path)->toBeNull();
    Storage::disk('branding')->assertExists('logos/beta.png');
});

it('claims, verifies and releases a custom domain for the organization, naming the admin in the log', function () {
    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->callAction(adminDomainAction('claimCustomDomain'), ['domain' => 'abstracts.example.org'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $this->organization->refresh();
    expect($this->organization->custom_domain)->toBe('abstracts.example.org');

    $this->dns->set('_cass-verify.abstracts.example.org', [(string) $this->organization->custom_domain_token]);

    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->callAction(adminDomainAction('verifyCustomDomain'))
        ->assertHasNoActionErrors();

    expect($this->organization->fresh()?->hasVerifiedCustomDomain())->toBeTrue();
    Notification::assertSentTo($this->admin, CustomDomainVerified::class);

    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->callAction(adminDomainAction('releaseCustomDomain'))
        ->assertNotified();

    expect($this->organization->fresh()?->custom_domain)->toBeNull();

    $causers = Activity::query()
        ->whereIn('description', [
            'organization.custom_domain_claimed',
            'organization.custom_domain_verified',
            'organization.custom_domain_released',
        ])
        ->pluck('causer_id')
        ->unique()
        ->values()
        ->all();

    expect($causers)->toBe([$this->admin->getKey()]);
});

it('refuses the edit page to an organization owner', function () {
    actingAs($this->owner)
        ->get(OrganizationResource::getUrl('edit', ['record' => $this->organization], panel: 'admin'))
        ->assertForbidden();

    // Mounted directly, past the panel middleware: OrganizationPolicy::update()
    // says yes to this owner for their own organization, so it is the
    // resource's canAccess() that has to answer no.
    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])->assertForbidden();

    expect($this->organization->fresh()?->name)->toBe('Alpha Society');
});
```

In `tests/Feature/Organizer/OrganizationProfileTest.php`, add the import (Pint puts it after the function imports, as in `MembersPageTest.php`):

Replace:

```php
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
```

with:

```php
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
```

and append the two cases at the end of the file:

Replace:

```php
    expect($this->org->contact_email)->toBe('abstracts@alpha.example.org')
        ->and($this->org->publish_contact_email)->toBeTrue();
});
```

with:

```php
    expect($this->org->contact_email)->toBe('abstracts@alpha.example.org')
        ->and($this->org->publish_contact_email)->toBeTrue();
});

it('deletes the replaced logo from the branding disk and logs the organizer as the causer', function () {
    Storage::disk('branding')->put('logos/old.png', 'old bytes');
    // refresh(): the factory leaves the colours to their database defaults,
    // and the form below is filled partially (see the contact-address case).
    $this->org->forceFill(['logo_path' => 'logos/old.png'])->save();
    $this->org->refresh();

    livewire(EditOrganizationProfile::class)
        // The browser's file picker removes the stored file before it uploads
        // the new one; filling the field alone appends beside the old path.
        ->fillForm(['logo_path' => []])
        ->fillForm(['logo_path' => UploadedFile::fake()->image('new.png', 400, 200)])
        ->call('save')
        ->assertHasNoFormErrors();

    $new = (string) $this->org->refresh()->logo_path;

    expect($new)->not->toBe('logos/old.png');
    Storage::disk('branding')->assertMissing('logos/old.png');
    Storage::disk('branding')->assertExists($new);

    $entry = Activity::query()->where('description', 'organization.profile_updated')->sole();

    expect($entry->causer_id)->toBe($this->user->getKey())
        ->and($entry->properties->get('changed'))->toBe(['logo_path']);
});

it('refuses a logo above 16 megapixels at upload, with the reason', function () {
    // 4001 x 4000 is 16,004,000 pixels: one row over the line that
    // GenerateConferencePoster::logoDataUri() silently drops a logo at.
    $this->org->refresh();

    livewire(EditOrganizationProfile::class)
        ->fillForm(['logo_path' => UploadedFile::fake()->createWithContent('huge.png', blankPng(4001, 4000))])
        ->call('save')
        ->assertHasFormErrors(['logo_path'])
        ->assertSee(__('admin.organization.logo_too_large', ['width' => '4,001', 'height' => '4,000', 'max' => 16]));

    expect($this->org->refresh()->logo_path)->toBeNull();
});
```

- [ ] **Step 2: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact --filter='EditOrganizationTest|OrganizationProfileTest' > /tmp/t-task-3.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-3.log
```

Expected: `rc=2`, **16 failed, 6 passed** (the six passing are `OrganizationProfileTest`'s existing cases). The admin cases fail with `ComponentNotFoundException: Unable to find component: [App\Filament\Admin\Resources\Organizations\Pages\EditOrganization]`, `RouteNotFoundException: Route [filament.admin.resources.organizations.edit] not defined`, `BindingResolutionException: Target class [App\Actions\Organizations\UpdateOrganizationProfile] does not exist` and, for the first case, the `getPages()` keys. The two organizer cases fail on behaviour, not on a missing class: `Found unexpected file or directory at path [logos/old.png]` and `Component has no errors` — today's page keeps the old file and accepts the 16 MP logo.

- [ ] **Step 3: One constant for the 16 MP line**

`app/Actions/Conferences/GenerateConferencePoster.php` — three edits. The constant:

Replace:

```php
class GenerateConferencePoster
{
    public function __construct(private readonly GenerateConferenceQr $qr) {}
```

with:

```php
class GenerateConferencePoster
{
    /**
     * The largest logo, in pixels, that logoDataUri() will decode. The upload
     * form refuses anything bigger (App\Filament\Schemas\OrganizationProfileForm),
     * so the two can only disagree if one of them stops reading this constant.
     */
    public const MAX_LOGO_PIXELS = 16_000_000;

    public function __construct(private readonly GenerateConferenceQr $qr) {}
```

The docblock of `logoDataUri()`, so it stops saying the upload has no limit:

Replace:

```php
     * So the logo is normalised here instead: refused above 16 MP, scaled to
     * the height the template uses, flattened onto white and handed over as
     * JPEG, which dompdf embeds with addJpegFromFile and never decodes.
     */
```

with:

```php
     * So the logo is normalised here instead: refused above 16 MP, scaled to
     * the height the template uses, flattened onto white and handed over as
     * JPEG, which dompdf embeds with addJpegFromFile and never decodes.
     *
     * Since Plan 7 the upload form refuses a logo above MAX_LOGO_PIXELS too, so
     * the silent drop below only ever meets a logo stored before that.
     */
```

And the comparison at `:81`:

Replace:

```php
        if ($size === false || ($size[0] * $size[1]) > 16_000_000) {
```

with:

```php
        if ($size === false || ($size[0] * $size[1]) > self::MAX_LOGO_PIXELS) {
```

- [ ] **Step 4: The write, and the one rule for a logo nobody points at**

Create `app/Actions/Organizations/DeleteOrganizationLogo.php` — the rule this step's write and Step 11's purge both call:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Removes a logo file from the public `branding` disk once no organization row
 * points at it - the one rule both writers that stop pointing at a logo share:
 * UpdateOrganizationProfile when a logo is replaced or cleared, and
 * PurgeOrganization when the organization itself goes.
 *
 * Call it AFTER the commit that stopped pointing at the file, never inside it:
 * DeleteSubmissionFile's order, for its reason. A row pointing at a deleted
 * file is a broken image on every public page and in every email, while a file
 * no row points at is only bytes - and no rollback can put a deleted file back.
 *
 * "No row points at it" is read from the database, trashed rows included,
 * rather than trusted from the caller: that one query is what keeps a file
 * another organization's row also holds (an exact copy of another
 * organization's path, written by a hand-edited request before the profile
 * form refused them; any other spelling of a path is refused by isCanonical(),
 * because the disk normalises `logos/./x.png` to `logos/x.png` before it
 * deletes), and what keeps the file when the write that should have released
 * it never committed.
 */
class DeleteOrganizationLogo
{
    /**
     * True when the file was deleted; false when a row still points at it, or
     * when the path is not one Filament writes and is left alone.
     */
    public function handle(string $path): bool
    {
        if (! self::isCanonical($path) || $this->stillUsed($path)) {
            return false;
        }

        // The bool is deliberately unchecked, exactly as in
        // DeleteSubmissionFile: no row points at the file any more, so there is
        // nothing left to refuse for.
        Storage::disk('branding')->delete($path);

        return true;
    }

    /**
     * Whether the path is spelled exactly as the profile form's FileUpload
     * stores one: 'logos/' + a ULID + '.' + the client's extension. The
     * branding disk normalises a path before it deletes - 'logos/./beta.png'
     * is 'logos/beta.png' to it - and throws on one that climbs out of the
     * disk, so a row holding any other spelling could reach a file the
     * exact-string row check never matches. Fail-closed: a path this refuses
     * only leaves bytes behind.
     */
    public static function isCanonical(string $path): bool
    {
        return preg_match('#^logos/[A-Za-z0-9]+(\.[A-Za-z0-9]*)?\z#', $path) === 1;
    }

    /**
     * Whether any organization row other than $except points at the file.
     * PurgeOrganization::preview() asks it about a row that still exists and is
     * about to go; handle() asks it once that row has gone or moved on.
     */
    public function stillUsed(string $path, ?Organization $except = null): bool
    {
        return Organization::withTrashed()
            ->where('logo_path', $path)
            ->when($except !== null, static fn (Builder $query): Builder => $query->whereKeyNot($except?->getKey()))
            ->exists();
    }
}
```

Create `app/Actions/Organizations/UpdateOrganizationProfile.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * THE writer of an organization's profile and branding: the organizer's tenant
 * profile page and the platform admin's edit page both save through here.
 *
 * The custom-domain columns are not in ATTRIBUTES and are not writable here:
 * ClaimCustomDomain, VerifyCustomDomain and ReleaseCustomDomain own them. Nor
 * are `slug`, `status` or `purpose` - the slug is in every public URL, the
 * status has ApproveOrganization and RejectOrganization, and the purpose is
 * what the organization wrote when it registered.
 */
class UpdateOrganizationProfile
{
    /** @var list<string> */
    public const ATTRIBUTES = [
        'name', 'type', 'country', 'website', 'contact_email', 'publish_contact_email',
        'logo_path', 'primary_color', 'accent_color',
    ];

    public function __construct(private readonly DeleteOrganizationLogo $logos) {}

    /** @param  array<string, mixed>  $data */
    public function handle(Organization $organization, array $data, User $actor): Organization
    {
        $previousLogo = $organization->logo_path;

        DB::transaction(function () use ($organization, $data, $actor, $previousLogo): void {
            $organization->fill(Arr::only($data, self::ATTRIBUTES));

            $changed = array_keys($organization->getDirty());

            $organization->save();

            if ($previousLogo !== null && $previousLogo !== $organization->logo_path) {
                // afterCommit() rather than a line after this closure, because
                // both callers are Filament save() methods that open a
                // transaction of their own the day a panel turns on
                // ->databaseTransactions(): this then waits for THAT commit,
                // and a rollback discards it. DeleteOrganizationLogo holds the
                // rest of the rule.
                DB::afterCommit(fn (): bool => $this->logos->handle($previousLogo));
            }

            if ($changed === []) {
                return;
            }

            // Names, not values: the row holds the values, the model's own
            // LogsActivity entry already carries the old and new name, and a
            // contact address does not need a second copy in the audit log.
            activity()
                ->performedOn($organization)
                ->causedBy($actor)
                ->withProperties(['changed' => $changed])
                ->log('organization.profile_updated');
        });

        return $organization;
    }
}
```

- [ ] **Step 5: The shared form**

Create `app/Filament/Schemas/OrganizationProfileForm.php`. Everything below the `Branding` section is `EditOrganizationProfile`'s code moved verbatim, with `static::tenant()` replaced by the injected `$record` and `canManage()` by the policy (decision 2):

```php
<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Actions\Conferences\GenerateConferencePoster;
use App\Actions\Organizations\ClaimCustomDomain;
use App\Actions\Organizations\ReleaseCustomDomain;
use App\Actions\Organizations\VerifyCustomDomain;
use App\Enums\OrganizationType;
use App\Exceptions\CustomDomainRefused;
use App\Models\Organization;
use App\Models\User;
use App\Support\Branding\Contrast;
use App\Support\Domains\DomainName;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\BasePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Spec section 4's "Manage organization profile, branding, domain", as ONE form
 * for the two places that may do it: the organizer's tenant profile page and
 * the platform admin's edit page. Moved here from EditOrganizationProfile
 * unchanged in behaviour - tests/Feature/Organizer/OrganizationProfileTest.php
 * and CustomDomainTest.php pass unedited, which is the proof.
 *
 * Every closure takes the organization as Filament's injected `$record`, which
 * is the schema's model on both pages (the tenant on one, the edited record on
 * the other), rather than Filament::getTenant(), which is null in the admin
 * panel. Every write is OrganizationPolicy::update(), which already answers
 * both questions: a manager of this organization, or a platform admin.
 */
class OrganizationProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.organization.form.details'))->columns(2)->components([
                TextInput::make('name')->label(__('admin.organization.form.name'))
                    ->required()->minLength(3)->maxLength(120),
                TextInput::make('slug')->label(__('admin.organization.form.slug'))
                    ->disabled()->dehydrated(false)
                    ->helperText(__('admin.organization.form.slug_help')),
                Select::make('type')->label(__('admin.organization.form.type'))
                    ->options(OrganizationType::class)->required(),
                Select::make('country')->label(__('admin.organization.form.country'))
                    ->options(config('cass.countries'))->searchable()->required(),
                TextInput::make('website')->label(__('admin.organization.form.website'))
                    ->url()->maxLength(255),
                TextInput::make('contact_email')->label(__('admin.organization.form.contact_email'))
                    ->email()->maxLength(255)
                    ->helperText(__('admin.organization.form.contact_email_help')),
                Toggle::make('publish_contact_email')
                    ->label(__('admin.organization.form.publish_contact_email'))
                    ->helperText(__('admin.organization.form.publish_contact_email_help')),
            ]),
            Section::make(__('admin.organization.form.branding'))->columns(2)->components([
                FileUpload::make('logo_path')->label(__('admin.organization.form.logo'))
                    ->disk('branding')->directory('logos')
                    ->image()->imagePreviewHeight('80')->maxSize(2048)
                    ->acceptedFileTypes(['image/png', 'image/jpeg'])
                    // A stored path in the request must be this record's own.
                    // Off by default in Filament 5.8.1, and it matters now:
                    // UpdateOrganizationProfile deletes the file a row stops
                    // pointing at, so a path copied from another organization's
                    // public page would otherwise become a way to delete its
                    // logo on the next replacement.
                    ->preventFilePathTampering()
                    ->rule(static::logoPixelRule())
                    ->helperText(__('admin.organization.form.logo_help', [
                        'max' => intdiv(GenerateConferencePoster::MAX_LOGO_PIXELS, 1_000_000),
                    ]))
                    ->columnSpanFull(),
                ColorPicker::make('primary_color')->label(__('admin.organization.form.primary_color'))
                    ->required()->regex('/^#[0-9A-Fa-f]{6}$/')
                    ->rule(static::contrastRule())
                    ->helperText(__('admin.organization.form.primary_color_help')),
                ColorPicker::make('accent_color')->label(__('admin.organization.form.accent_color'))
                    ->required()->regex('/^#[0-9A-Fa-f]{6}$/')
                    ->rule(static::contrastRule())
                    ->helperText(__('admin.organization.form.accent_color_help')),
            ]),
            Section::make(__('domain.section.heading'))
                ->description(__('domain.section.description', ['platform' => DomainName::platformHost()]))
                ->columns(1)
                ->components([
                    // Read-only: every write goes through one of the three
                    // actions below, because the page's save() hands the form
                    // state to UpdateOrganizationProfile and none of the three
                    // columns is fillable (Plan 6 Task 2 decision 1).
                    // An infolist TextEntry, NOT Filament\Schemas\Components\Text:
                    // that component has badge() and color() but no label() - it
                    // does not use HasLabel, so ->label() falls through
                    // Macroable::__call and throws BadMethodCallException, which
                    // would make the page a 500 for everybody. An entry is never
                    // dehydrated, so save() still sees no `custom_domain_status`
                    // key.
                    TextEntry::make('custom_domain_status')
                        ->label(__('domain.fields.status'))
                        ->state(fn (?Organization $record): string => static::stateLabel($record))
                        ->badge()
                        ->color(fn (?Organization $record): string => static::stateColor($record)),
                    View::make('filament.organizer.partials.custom-domain-records')
                        ->viewData(fn (?Organization $record): array => ['organization' => $record])
                        ->visible(fn (?Organization $record): bool => $record?->custom_domain !== null),
                    Actions::make([
                        static::claimAction(),
                        static::verifyAction(),
                        static::releaseAction(),
                    ])->key('custom_domain_actions'),
                ]),
        ]);
    }

    protected static function contrastRule(): Closure
    {
        // Filament evaluates a closure passed to `rule()` by injecting named
        // dependencies it recognises (e.g. $get, $record) before using the
        // return value as the actual Laravel validation rule. A raw
        // `function (string $attribute, mixed $value, Closure $fail)`
        // closure has an unresolvable `$attribute` parameter from Filament's
        // point of view, so it must be nested inside a zero-argument
        // closure that Filament can evaluate for free, which then returns
        // the real Illuminate-style rule closure for the validator to call.
        return static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) && ! Contrast::passesAA($value, '#FFFFFF')) {
                $fail(__('admin.organization.form.contrast'));
            }
        };
    }

    /**
     * The 2 MB cap says nothing about pixels: a flat-colour 6000 x 3500 PNG
     * fits inside it, and GenerateConferencePoster::logoDataUri() then drops
     * the logo from every poster without a word. Refused here instead, at the
     * same line, read from the same constant.
     *
     * Filament runs file rules against each TemporaryUploadedFile on its own
     * (BaseFileUpload::getValidationRules()), so `$value` is the upload, not
     * the field's array. dimensions() streams it off whatever disk Livewire
     * stored it on; getimagesize() reads the header and decodes nothing.
     */
    protected static function logoPixelRule(): Closure
    {
        return static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $size = $value instanceof TemporaryUploadedFile
                ? $value->dimensions()
                : @getimagesize((string) $value->getRealPath());

            if (! is_array($size) || $size[0] * $size[1] <= GenerateConferencePoster::MAX_LOGO_PIXELS) {
                return;
            }

            $fail(__('admin.organization.logo_too_large', [
                'width' => number_format($size[0]),
                'height' => number_format($size[1]),
                'max' => intdiv(GenerateConferencePoster::MAX_LOGO_PIXELS, 1_000_000),
            ]));
        };
    }

    protected static function stateLabel(?Organization $organization): string
    {
        if ($organization?->custom_domain === null) {
            return __('domain.state.none');
        }

        return $organization->hasVerifiedCustomDomain()
            ? __('domain.state.verified').' — '.$organization->custom_domain
            : __('domain.state.pending').' — '.$organization->custom_domain;
    }

    protected static function stateColor(?Organization $organization): string
    {
        return match (true) {
            $organization?->custom_domain === null => 'gray',
            $organization->hasVerifiedCustomDomain() => 'success',
            default => 'warning',
        };
    }

    /**
     * Each page's canView()/canEdit() already answers this, but an action is a
     * Livewire endpoint a client can call by name, and "the page would have
     * 404ed" is not an authorization check on that call.
     */
    protected static function canManage(?Organization $organization): bool
    {
        return $organization instanceof Organization && Gate::allows('update', $organization);
    }

    /**
     * The error-bag key one field of the *mounted action's* modal is watching.
     *
     * Filament re-throws a ValidationException raised inside an action body
     * unchanged (Actions\Concerns\InteractsWithActions::callMountedAction:384),
     * and the modal's fields live under the mounted action schema's state path
     * - `mountedActions.0.data` for a top-level action. A message keyed plain
     * `domain` therefore reaches no field at all and the modal shows nothing.
     * Read off the live schema rather than hardcoded, because the nesting index
     * moves the moment an action is opened from inside another one.
     */
    protected static function actionFieldKey(BasePage $livewire, string $field): string
    {
        $schemaName = $livewire->getMountedActionSchemaName();
        $statePath = $schemaName === null ? null : $livewire->getSchema($schemaName)?->getStatePath();

        return filled($statePath) ? $statePath.'.'.$field : $field;
    }

    protected static function claimAction(): Action
    {
        return Action::make('claimCustomDomain')
            ->label(__('domain.actions.claim'))
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->visible(fn (?Organization $record): bool => static::canManage($record))
            ->schema([
                TextInput::make('domain')
                    ->label(__('domain.fields.domain'))
                    ->helperText(__('domain.fields.domain_help'))
                    ->required()
                    ->maxLength(DomainName::MAX_LENGTH)
                    ->rule(static::domainRule()),
            ])
            ->fillForm(fn (?Organization $record): array => ['domain' => $record?->custom_domain])
            ->action(function (array $data, ?Organization $record, BasePage $livewire): void {
                $user = auth()->user();

                if (! static::canManage($record) || ! $record instanceof Organization || ! $user instanceof User) {
                    return;
                }

                try {
                    app(ClaimCustomDomain::class)->handle($record, (string) $data['domain'], $user);
                } catch (CustomDomainRefused $exception) {
                    // The uniqueness check lives in the action, because the
                    // action is also what the console and any later caller
                    // use. Surfacing it on the field rather than as a
                    // notification is what keeps the modal open with the value
                    // still in it.
                    throw ValidationException::withMessages([
                        static::actionFieldKey($livewire, 'domain') => $exception->getMessage(),
                    ]);
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
            ->visible(fn (?Organization $record): bool => static::canManage($record) && $record?->custom_domain !== null)
            ->action(function (?Organization $record): void {
                $user = auth()->user();

                if (! static::canManage($record) || ! $record instanceof Organization || ! $user instanceof User) {
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
                        // Not domain.errors.lookup_failed: "we could not reach
                        // the DNS servers" tells an organizer who simply
                        // clicked too fast to go and edit a record that is
                        // already correct.
                        ->title(__('domain.errors.throttled_title'))
                        ->body(__('domain.errors.throttled', ['seconds' => RateLimiter::availableIn($key)]))
                        ->send();

                    return;
                }

                RateLimiter::hit($key, 60);

                try {
                    app(VerifyCustomDomain::class)->handle($record, $user);
                } catch (CustomDomainRefused $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title(__('domain.notices.verified_title'))
                    ->body(__('domain.notices.verified_body', [
                        'domain' => (string) $record->custom_domain,
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
            ->visible(fn (?Organization $record): bool => static::canManage($record) && $record?->custom_domain !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (?Organization $record): string => __('domain.actions.release_confirm_heading', [
                'domain' => (string) $record?->custom_domain,
            ]))
            ->modalDescription(fn (?Organization $record): string => __('domain.actions.release_confirm_body', [
                'domain' => (string) $record?->custom_domain,
                'platform' => DomainName::platformHost(),
            ]))
            ->action(function (?Organization $record): void {
                $user = auth()->user();

                if (! static::canManage($record) || ! $record instanceof Organization || ! $user instanceof User) {
                    return;
                }

                $domain = (string) $record->custom_domain;

                app(ReleaseCustomDomain::class)->handle($record, $user);

                Notification::make()->success()->title(__('domain.notices.released', ['domain' => $domain]))->send();
            });
    }

    /**
     * The double-closure shape this class already uses for contrastRule(), and
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
}
```

- [ ] **Step 6: The organizer page delegates**

Replace the whole of `app/Filament/Organizer/Pages/Tenancy/EditOrganizationProfile.php` (no other task touches it; everything removed now lives in Step 5's class):

```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Pages\Tenancy;

use App\Actions\Organizations\UpdateOrganizationProfile;
use App\Filament\Schemas\OrganizationProfileForm;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * The organizer's half of spec section 4's "Manage organization profile,
 * branding, domain". The form is App\Filament\Schemas\OrganizationProfileForm,
 * which the platform admin's EditOrganization page renders too; the write is
 * App\Actions\Organizations\UpdateOrganizationProfile, which both call.
 */
class EditOrganizationProfile extends EditTenantProfile
{
    public static function canView(Model $tenant): bool
    {
        $user = auth()->user();

        return $user instanceof User && $tenant instanceof Organization
            && ($user->roleIn($tenant)?->canManageOrganization() ?? false);
    }

    public static function getLabel(): string
    {
        return __('admin.organization.profile_title');
    }

    /**
     * The record every closure in the form is handed is the panel's tenant,
     * Filament::getTenant(), not the page's own `$this->tenant` that the
     * parent's defaultForm() sets: they are the same row, and the panel's
     * instance is the one the domain actions wrote to before the form moved -
     * so it is still the one the rest of the request (and every existing test)
     * sees change.
     */
    public function form(Schema $schema): Schema
    {
        return OrganizationProfileForm::configure($schema->model(Filament::getTenant()));
    }

    /**
     * Not the parent's `$record->update($data)`: the action is what writes the
     * activity entry and deletes a replaced logo from the branding disk once
     * the row no longer points at it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Organization $record */
        /** @var User $user */
        $user = auth()->user();

        return app(UpdateOrganizationProfile::class)->handle($record, $data, $user);
    }
}
```

- [ ] **Step 7: The admin edit page**

Create `app/Filament/Admin/Resources/Organizations/Pages/EditOrganization.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Organizations\Pages;

use App\Actions\Organizations\UpdateOrganizationProfile;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Spec section 4's platform-admin cell for "Manage organization profile,
 * branding, domain": the organizer's own form (OrganizationResource::form()
 * is App\Filament\Schemas\OrganizationProfileForm) and the organizer's own
 * write, with the admin as the actor.
 *
 * Access is two checks, both Filament's: the resource's canAccess()
 * (is_platform_admin) on every mount and hydrate, then EditRecord's canEdit(),
 * which is OrganizationPolicy::update(). The second alone would admit the
 * organization's own owner, so the first is the one that keeps this page in
 * the admin panel.
 */
class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * Not the parent's `$record->update($data)`: the action is what writes the
     * activity entry with the admin as causer and deletes a replaced logo from
     * the branding disk once the row no longer points at it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Organization $record */
        /** @var User $admin */
        $admin = auth()->user();

        return app(UpdateOrganizationProfile::class)->handle($record, $data, $admin);
    }
}
```

`app/Filament/Admin/Resources/Organizations/OrganizationResource.php` — two imports, a `form()` beside `infolist()`, and the route. The imports:

Replace:

```php
use App\Filament\Admin\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Admin\Resources\Organizations\Pages\ViewOrganization;
```

with:

```php
use App\Filament\Admin\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Admin\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Admin\Resources\Organizations\Pages\ViewOrganization;
```

Replace:

```php
use App\Filament\Admin\Resources\Organizations\Tables\OrganizationsTable;
use App\Models\Organization;
```

with:

```php
use App\Filament\Admin\Resources\Organizations\Tables\OrganizationsTable;
use App\Filament\Schemas\OrganizationProfileForm;
use App\Models\Organization;
```

The form:

Replace:

```php
    public static function infolist(Schema $schema): Schema
    {
        return OrganizationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
```

with:

```php
    public static function infolist(Schema $schema): Schema
    {
        return OrganizationInfolist::configure($schema);
    }

    /**
     * The organizer's own profile form, not a second one: one set of rules
     * (contrast, the 16 MP logo limit, the domain checks) for both panels.
     * ViewOrganization keeps the infolist above.
     */
    public static function form(Schema $schema): Schema
    {
        return OrganizationProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
```

The page:

Replace:

```php
            'view' => ViewOrganization::route('/{record}'),
        ];
```

with:

```php
            'view' => ViewOrganization::route('/{record}'),
            'edit' => EditOrganization::route('/{record}/edit'),
        ];
```

`app/Filament/Admin/Resources/Organizations/Pages/ViewOrganization.php` — Filament's `EditAction` links to the edit page and asks `canEdit()` itself:

Replace:

```php
use App\Filament\Admin\Resources\Organizations\Tables\OrganizationsTable;
use Filament\Resources\Pages\ViewRecord;
```

with:

```php
use App\Filament\Admin\Resources\Organizations\Tables\OrganizationsTable;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
```

Replace:

```php
        return [
            OrganizationsTable::approveAction(),
```

with:

```php
        return [
            EditAction::make(),
            OrganizationsTable::approveAction(),
```

- [ ] **Step 8: The strings**

`lang/en/admin.php` — a new group immediately before the existing `'members' => [` group (anchored on that group's first two lines, which no other task changes):

Replace:

```php
    'members' => [
        'title' => 'Members',
```

with:

```php
    // The organization profile form (App\Filament\Schemas\OrganizationProfileForm).
    // The organizer's tenant profile page renders it as well as the admin's
    // edit page: one form, so one set of strings, here because the admin page
    // is what made it shared.
    'organization' => [
        'profile_title' => 'Organization profile',
        'logo_too_large' => 'This image is :width × :height pixels. A logo can be at most :max megapixels — resize it and upload it again.',
        'form' => [
            'details' => 'Details',
            'name' => 'Name',
            'slug' => 'Slug',
            'slug_help' => 'Used in your public URLs. Contact us if it needs to change.',
            'type' => 'Type',
            'country' => 'Country',
            'website' => 'Website',
            'contact_email' => 'Contact email',
            'contact_email_help' => 'How we reach you about your conferences. Use a shared inbox, not a personal address.',
            'publish_contact_email' => 'Show this address on public conference pages',
            'publish_contact_email_help' => 'Off by default. Anyone - including a scraper - can read an address printed on a public page.',
            'branding' => 'Branding',
            'logo' => 'Logo',
            'logo_help' => 'PNG or JPEG, at most 2 MB and :max megapixels. A transparent background works best.',
            'primary_color' => 'Primary color',
            'primary_color_help' => 'Buttons and headings on your public pages. Must be readable on white.',
            'accent_color' => 'Accent color',
            'accent_color_help' => 'Links and highlights.',
            'contrast' => 'This colour is too light to read on a white background. Choose a darker shade.',
        ],
    ],

    'members' => [
        'title' => 'Members',
```

Append these three paths to `$plan7Sources` in `tests/Feature/LanguageCoverageTest.php` (Task 2 created it):

```php
    'app/Filament/Schemas/OrganizationProfileForm.php',
    'app/Filament/Organizer/Pages/Tenancy/EditOrganizationProfile.php',
    'app/Filament/Admin/Resources/Organizations/Pages/EditOrganization.php',
```

- [ ] **Step 9: Write the failing purge tests**

Create `tests/Feature/Admin/PurgeLogoTest.php`. `tests/Feature/Console/DemoResetTest.php` is **not** edited (fact 3.19); the demo path is the last case here:

```php
<?php

declare(strict_types=1);

use App\Actions\Conferences\PurgeConference;
use App\Actions\Organizations\DeleteOrganizationLogo;
use App\Actions\Organizations\PurgeOrganization;
use App\Filament\Admin\Resources\Organizations\Pages\ListOrganizations;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/**
 * The hard purge and the public `branding` disk. PurgeOrganization deleted the
 * private-disk objects behind an organization's abstracts but never its logo,
 * which stayed reachable at /storage/branding/logos/... for anybody holding the
 * URL - and every email the organization ever sent carries that URL.
 */
beforeEach(function () {
    Storage::fake('branding');
    Storage::fake('local');

    $this->admin = User::factory()->platformAdmin()->create();
    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    Storage::disk('branding')->put('logos/alpha.png', 'alpha bytes');
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->organization->forceFill(['logo_path' => 'logos/alpha.png'])->save();
});

it('shows the logo in the purge modal and deletes it with the organization, and nobody else\'s', function () {
    Storage::disk('branding')->put('logos/beta.png', 'beta bytes');
    $other = Organization::factory()->approved()->create();
    $other->forceFill(['logo_path' => 'logos/beta.png'])->save();

    livewire(ListOrganizations::class)
        ->removeTableFilter('status')
        ->mountTableAction('purge', $this->organization)
        ->assertMountedActionModalSeeHtml('<strong>1</strong> '.__('admin.purge.logo'))
        ->setTableActionData(['confirmation' => (string) $this->organization->slug])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        // One row (the organization; it has nothing else) and one file (the
        // logo): the notification counts a logo as a file, not as a row.
        ->assertNotified(__('admin.purge.done', ['name' => 'Alpha Society', 'rows' => '1', 'files' => '1']));

    expect(Organization::withTrashed()->whereKey($this->organization->getKey())->exists())->toBeFalse()
        ->and($other->fresh()?->logo_path)->toBe('logos/beta.png');

    Storage::disk('branding')->assertMissing('logos/alpha.png');
    Storage::disk('branding')->assertExists('logos/beta.png');
});

it('reports the logo in the run and the preview alike', function () {
    $preview = app(PurgeOrganization::class)->preview($this->organization);
    $counts = app(PurgeOrganization::class)->handle($this->organization, $this->admin);

    expect($preview['branding files'])->toBe(1)
        ->and($counts['branding files'])->toBe(1)
        ->and($counts['private files'])->toBe(0);
});

it('keeps the logo when the purge does not commit, and deletes it when the retry does', function () {
    // The last statement of the purge's transaction fails. Everything rolls
    // back, so the row still points at its logo, and the file has to be there.
    $refuse = true;

    DB::listen(function (QueryExecuted $query) use (&$refuse): void {
        if ($refuse && preg_match('/^delete from [`"]organizations[`"]/i', $query->sql) === 1) {
            throw new RuntimeException('The database refused the delete.');
        }
    });

    expect(fn () => app(PurgeOrganization::class)->handle($this->organization, $this->admin))
        ->toThrow(RuntimeException::class);

    expect(Organization::query()->whereKey($this->organization->getKey())->value('logo_path'))->toBe('logos/alpha.png');
    Storage::disk('branding')->assertExists('logos/alpha.png');

    // The operator retries and the database lets it through this time.
    $refuse = false;
    app(PurgeOrganization::class)->handle($this->organization, $this->admin);

    Storage::disk('branding')->assertMissing('logos/alpha.png');
});

it('keeps a logo file another organization still points at, and previews it as kept', function () {
    // A path two rows share can only come from a hand-edited request before
    // the profile form refused one (Task 3 decision 5). The purge must not
    // take the other organization's logo with it.
    $other = Organization::factory()->approved()->create();
    $other->forceFill(['logo_path' => 'logos/alpha.png'])->save();

    expect(app(PurgeOrganization::class)->preview($this->organization)['branding files'])->toBe(0);

    $counts = app(PurgeOrganization::class)->handle($this->organization, $this->admin);

    expect($counts['branding files'])->toBe(0);
    Storage::disk('branding')->assertExists('logos/alpha.png');
});

it('keeps another organization\'s logo when the purged row spells its path another way', function () {
    // The disk would delete 'logos/./beta.png' as 'logos/beta.png', which the
    // exact-string row check never matches. DeleteOrganizationLogo refuses
    // any spelling Filament does not write (Task 3 decision 5).
    Storage::disk('branding')->put('logos/beta.png', 'beta bytes');
    $other = Organization::factory()->approved()->create();
    $other->forceFill(['logo_path' => 'logos/beta.png'])->save();
    $this->organization->forceFill(['logo_path' => 'logos/./beta.png'])->save();

    expect(app(PurgeOrganization::class)->preview($this->organization)['branding files'])->toBe(0);

    $counts = app(PurgeOrganization::class)->handle($this->organization, $this->admin);

    expect($counts['branding files'])->toBe(0);
    Storage::disk('branding')->assertExists('logos/beta.png');
});

it('reports nothing and throws nothing for a logo path that climbs out of the disk', function () {
    // The disk throws PathTraversalDetected for this path, after the purge's
    // commit, and nothing catches it. DeleteOrganizationLogo never hands it to
    // the disk, and the preview predicts the same zero.
    $this->organization->forceFill(['logo_path' => 'logos/../../x'])->save();

    // \z, not $: PCRE's $ also matches before a final newline, and the disk
    // throws CorruptedPathDetected on a control character.
    expect(DeleteOrganizationLogo::isCanonical("logos/x.png\n"))->toBeFalse();

    expect(app(PurgeOrganization::class)->preview($this->organization)['branding files'])->toBe(0);

    $counts = app(PurgeOrganization::class)->handle($this->organization, $this->admin);

    expect($counts['branding files'])->toBe(0)
        ->and(Organization::withTrashed()->whereKey($this->organization->getKey())->exists())->toBeFalse();
});

it('leaves the logo alone when one conference is purged, and takes it with the organization', function () {
    // PurgeConference is the other purge, and the organization outlives it.
    $conference = Conference::factory()->for($this->organization)->create();

    app(PurgeConference::class)->handle($conference, $this->admin);

    expect($this->organization->fresh()?->logo_path)->toBe('logos/alpha.png');
    Storage::disk('branding')->assertExists('logos/alpha.png');

    app(PurgeOrganization::class)->handle($this->organization, $this->admin);

    Storage::disk('branding')->assertMissing('logos/alpha.png');
});

it('deletes the demo organization logo on cass:demo-reset', function () {
    // cass:demo-reset is PurgeDemoOrganization, which is PurgeOrganization
    // narrowed to the is_demo row - so the logo goes the same way. The seeder
    // never sets one; an owner trying the branding screen on the demo would.
    Storage::disk('branding')->put('logos/demo.png', 'demo bytes');
    $demo = Organization::factory()->approved()->create(['slug' => 'demo-society']);
    $demo->forceFill(['is_demo' => true, 'logo_path' => 'logos/demo.png'])->save();

    $this->artisan('cass:demo-reset', ['--confirm' => true])
        ->expectsOutputToContain('branding files')
        ->assertSuccessful();

    expect(Organization::withTrashed()->whereKey($demo->getKey())->exists())->toBeFalse();
    Storage::disk('branding')->assertMissing('logos/demo.png');
});
```

- [ ] **Step 10: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact --filter='PurgeLogoTest' > /tmp/t-task-3.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-3.log
```

Expected: `rc=2`, **8 failed**. Four on `Undefined array key "branding files"` (the parity case, the shared-path case and the two path-spelling cases); the modal case on its `<strong>1</strong> admin.purge.logo` line (neither the key nor the string exists yet); the demo case on `Output does not contain "branding files"`; and the rollback and conference cases on their second half — `Found unexpected file or directory at path [logos/alpha.png]` once the organization itself is purged. Their first halves (the logo survives a rolled-back purge, and a conference purge) already pass on today's code; the second halves are what make them fail first.

- [ ] **Step 11: The purge releases the logo**

`app/Actions/Organizations/PurgeOrganization.php` — five edits. The docblock's disk paragraph:

Replace:

```php
 * nothing will ever reference it again. That is also why PurgeConference has
 * two entry points: rows() deletes rows and *returns* the paths, so this class
 * can do one disk pass after its own transaction commits.
```

with:

```php
 * nothing will ever reference it again. That is also why PurgeConference has
 * two entry points: rows() deletes rows and *returns* the paths, so this class
 * can do one disk pass after its own transaction commits. The organization's
 * logo on the public `branding` disk goes in the same pass, through
 * DeleteOrganizationLogo - the rule the profile form's logo replacement uses -
 * and is reported as `branding files` beside `private files`.
```

The constructor (the container builds both callers, `OrganizationsTable::purgeAction()` and `PurgeDemoOrganization`; nothing calls `new`):

Replace:

```php
    public function __construct(private readonly PurgeConference $conferences) {}
```

with:

```php
    public function __construct(
        private readonly PurgeConference $conferences,
        private readonly DeleteOrganizationLogo $logos,
    ) {}
```

In `handle()`, the path, read before anything is deleted:

Replace:

```php
        $conferences = Conference::withTrashed()->where('organization_id', $organizationId)->get();

        /** @var list<string> $paths */
        $paths = [];
```

with:

```php
        $conferences = Conference::withTrashed()->where('organization_id', $organizationId)->get();

        // Read from the row, not the instance the caller holds: the purge
        // removes what the database says, and the file goes after the commit.
        $logo = Organization::withTrashed()->whereKey($organizationId)->value('logo_path');

        /** @var list<string> $paths */
        $paths = [];
```

and the file, in the disk pass after the commit:

Replace:

```php
        $result['private files'] = count($paths);
```

with:

```php
        $result['private files'] = count($paths);

        // The same pass, the same side of the commit. DeleteOrganizationLogo
        // keeps a file another organization's row still points at, and never
        // hands the disk a path Filament would not have written, so the count
        // is what was actually released - and what preview() predicts.
        $result['branding files'] = is_string($logo) && $this->logos->handle($logo) ? 1 : 0;
```

In `preview()`, the same answer before anything happens:

Replace:

```php
        $counts['private files'] = $files;

        return $counts;
```

with:

```php
        $counts['private files'] = $files;

        // What handle() will release: a logo spelled as Filament writes one,
        // which no other organization's row points at. Read from the row, as
        // handle() reads it.
        $logo = Organization::withTrashed()->whereKey($organizationId)->value('logo_path');
        $counts['branding files'] = is_string($logo) && DeleteOrganizationLogo::isCanonical($logo) && ! $this->logos->stillUsed($logo, except: $organization) ? 1 : 0;

        return $counts;
```

`DeleteOrganizationLogo` is in the same namespace as `PurgeOrganization`, so no import is needed.

- [ ] **Step 12: The modal names the logo, and the notification counts it as a file**

`resources/views/filament/admin/partials/purge-counts.blade.php` — the one `<li>` line:

Replace:

```blade
            <li><strong>{{ number_format($count) }}</strong> {{ $table === 'private files' ? __('admin.purge.files') : str_replace('_', ' ', $table) }}</li>
```

with:

```blade
            <li><strong>{{ number_format($count) }}</strong> {{ match ($table) {
                'private files' => __('admin.purge.files'),
                'branding files' => __('admin.purge.logo'),
                default => str_replace('_', ' ', $table),
            } }}</li>
```

`app/Filament/Admin/Resources/Organizations/Tables/OrganizationsTable.php` — three lines inside `purgeAction()`'s `->action()` closure, which Task 2 does not touch: the file count, and the organization's name, escaped as Task 2 escapes it in the approve and reject notifications (Task 2, decision 6). `PurgeLogoTest`'s modal case pins the title with `Alpha Society`, which e() leaves as it is.

Replace:

```php
                $files = $counts['private files'] ?? 0;
                unset($counts['private files']);

                Notification::make()->success()->title(__('admin.purge.done', [
                    'name' => $name,
```

with:

```php
                // Both disks are files, not rows: the private objects behind
                // the abstracts and the logo on the public branding disk.
                $files = ($counts['private files'] ?? 0) + ($counts['branding files'] ?? 0);
                unset($counts['private files'], $counts['branding files']);

                // Escaped: an anonymous registrant chooses the name, and the
                // title is rendered through sanitizeHtml(), which keeps style.
                Notification::make()->success()->title(__('admin.purge.done', [
                    'name' => e($name),
```

`app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php` — one line inside `purgeAction()`'s `->action()` closure, which Task 2 does not touch. The conference name an organizer typed goes into the same `sanitizeHtml()`-rendered title, so it is escaped the same way:

```php
                    'name' => $name,
```

becomes:

```php
                    'name' => e($name),
```

`lang/en/admin.php` — one key in the existing `purge` group, after `files` (a line no other task changes):

Replace:

```php
        'files' => 'uploaded files',
```

with:

```php
        'files' => 'uploaded files',
        'logo' => 'logo file on the public branding disk',
```

- [ ] **Step 13: Run the task's tests, the existing organizer and purge suites unedited, and the language sweep**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact --filter='EditOrganizationTest|OrganizationProfileTest|Organizer\\CustomDomainTest|PurgeLogoTest|PurgeTest|PurgeConferenceTest|DemoResetTest|LanguageCoverageTest' > /tmp/t-task-3.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-3.log
```

Expected: `rc=0`. `CustomDomainTest` (12 cases) and the six pre-existing `OrganizationProfileTest` cases are in this run **without a single edited line** — decision 1's proof that the form moved without changing behaviour — and so are `PurgeTest`, `PurgeConferenceTest` (whose parity case now compares `branding files` too) and `DemoResetTest`, decision 9's. If a `CustomDomainTest` case fails with `ActionNotResolvableException` or a stale status badge, the organizer page's `form()` is not passing `Filament::getTenant()` to the schema (fact 3.5).

- [ ] **Step 14: Pint and Larastan**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint --test > /tmp/pint-task-3.log 2>&1; echo "pint rc=$?"; \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan-task-3.log 2>&1; echo "stan rc=$?"; tail -5 /tmp/stan-task-3.log
```

Expected: `pint rc=0`, `stan rc=0`. If Pint fails, run `./vendor/bin/pint` and re-run `--test`; the only rewrite the prototype needed was the `Spatie\…\Activity` import's position in the two test files, which the code above already has.

- [ ] **Step 15: The whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test > /tmp/all-task-3.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-3.log
```

Expected: `all rc=0`, and **24 more passing tests than Task 2's final run** (the prototype on `45d2d8e`, before the review added the three path-spelling cases: 1214 → 1235 passed, 1 skipped, plus the two `$plan7Sources` cases it had to stand in for Task 2).

- [ ] **Step 16: Commit**

The suite gates the commit in the same command, and the paths are named rather than `-A` (Task 2, Step 10). The message's last line is a placeholder: put the session's Co-Authored-By trailer there before running it.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test > /tmp/all-task-3.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add app/Actions/Organizations/UpdateOrganizationProfile.php app/Actions/Organizations/DeleteOrganizationLogo.php \
  app/Filament/Schemas/OrganizationProfileForm.php app/Filament/Admin/Resources/Organizations/Pages/EditOrganization.php \
  app/Filament/Organizer/Pages/Tenancy/EditOrganizationProfile.php app/Filament/Admin/Resources/Organizations/OrganizationResource.php \
  app/Filament/Admin/Resources/Organizations/Pages/ViewOrganization.php app/Actions/Conferences/GenerateConferencePoster.php \
  app/Actions/Organizations/PurgeOrganization.php app/Filament/Admin/Resources/Organizations/Tables/OrganizationsTable.php \
  app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php \
  resources/views/filament/admin/partials/purge-counts.blade.php lang/en/admin.php tests/Pest.php tests/Feature/LanguageCoverageTest.php \
  tests/Feature/Admin/EditOrganizationTest.php tests/Feature/Organizer/OrganizationProfileTest.php tests/Feature/Admin/PurgeLogoTest.php && \
git commit -q -F - <<'EOF' && git log --oneline -1 && git status --short --untracked-files=no
feat(admin): the platform admin edits an organization's profile, branding and domain

One form for both panels (App\Filament\Schemas\OrganizationProfileForm) and
one write (UpdateOrganizationProfile), so the contrast rule, the domain
checks and the new logo rules cannot drift between the organizer's page and
the admin's. The write logs organization.profile_updated with the actor as
causer, and deletes a replaced or cleared logo from the branding disk after
the commit, once no row points at it; the field now refuses a stored path
that is not the record's own. Logos above GenerateConferencePoster's
16-megapixel line are refused at upload instead of vanishing from posters.

The hard purge now releases the organization's logo from the public
branding disk too, through the same rule and after its own commit, and
reports it as "branding files" in the run, the preview and the modal;
cass:demo-reset inherits it. A purge used to leave the logo reachable at its
/storage/branding URL for ever. Neither path deletes a logo whose stored
path is not spelled as Filament writes one, and the purge notification
escapes the organization's name.

<the session's Co-Authored-By trailer>
EOF
```

Expected: `all rc=0 - the suite gates this commit`, one log line, and nothing from `git status --short --untracked-files=no`.

---

### Task 4: The platform admin manages members — change a role, remove a member, withdraw an invitation

This task closes the members half of the `Launch` backlog entry Task 3 opened:

> **Spec section 4's two platform-admin write cells have no screen.** "Manage organization members" and "Manage organization profile, branding, domain" are ticked for the platform admin in the spec table. Plan 6 added read-only relation managers and a read-only `OrganizationResource`; `OrganizationPolicy::update()` already answers true for a platform admin, so the missing half is an admin edit page plus role-change and remove-member actions, each with an activity entry and an organizer-forbidden test. Until then the support path is the console.

After Task 3 the profile half is closed; after this task the entry is closed and Task 7 removes it. Both writes go through the actions the organizer's Members page already calls, with every guard they carry, and the third — withdrawing a pending invitation — through `RevokeInvitation`, for the reason decision 3 gives.

**Facts this task relies on.**

4.1. **The spec cell.** `docs/superpowers/specs/2026-09-10-cass-v2-design.md:85` — `| Manage organization members | ✔ | ✔ | ✔ | | | |`: platform admin, org owner, org admin, and the platform admin's tick is the owner's.

4.2. **Both admin managers are read-only by class today.** `MembersRelationManager` (`app/Filament/Admin/Resources/Organizations/RelationManagers/MembersRelationManager.php`): docblock `:13-23`, `isReadOnly()` true (`:30-33`), empty `headerActions`/`toolbarActions`/`recordActions` (`:56-58`). `InvitationsRelationManager`: docblock `:12-21`, `isReadOnly()` `:28-31`, empty arrays `:49-51`. `OrganizationResource::getRelations()`'s docblock says "Both managers are read-only by class" (`OrganizationResource.php:74-81`), and the organizer `Members` page's says the admin cell "has no screen behind it" (`app/Filament/Organizer/Pages/Members.php:57-66`).

4.3. **What `isReadOnly()` does and does not refuse, and what a relation manager does not check.** `RelationManager::getDefaultActionAuthorizationResponse()` denies Filament's built-in Create, Edit, Delete, Attach, Detach, Associate, Dissociate, Replicate, Restore, ForceDelete and Import actions when read-only, and answers `null` for any other action (`vendor/filament/filament/src/Resources/RelationManagers/RelationManager.php:345-371`, `default => null` at `:369`). `mount()` authorizes nothing (`:120-123`), and the hydrate check (`vendor/filament/filament/src/Resources/RelationManagers/Concerns/CanAuthorizeAccess.php:7-10`) asks `canViewForRecord()` (`:287-305`), which for a manager with no related resource is the related model's `viewAny` — and `User` has no policy at all (`app/Policies/` holds none, and nothing calls `Gate::before()`), so for the members manager it is no wall. A table action called with a record key outside the relationship's query throws `ActionNotResolvableException("Record [{key}] no longer exists.")` before any closure runs (`vendor/filament/actions/src/Concerns/InteractsWithActions.php:705-711`).

4.4. **The two member actions derive the actor's power from membership, and carry every guard this task must keep.** `ChangeMemberRole::blockers()`: `$actorRole = $actor->roleIn($organization)` (`app/Actions/Organizations/ChangeMemberRole.php:20`); `not_allowed` when that is null or not a manager (`:27-29`), `own_role` (`:31-36`), `owner_grants_owner` (`:38-40`), `owner_only_changes_owner` (`:42-44`), `last_owner` (`:46-49`). `handle()` re-counts owners under `lockForUpdate()` inside the transaction (`:74-78`), withdraws the demoted member's invitations (`:95-105`) and logs `organization.role_changed` with `causedBy($actor)` (`:108-112`). `RemoveMember::blockers()` is the same shape (`app/Actions/Organizations/RemoveMember.php:20`, `:27-29`, `:31-33` `own_membership`, `:35-37`, `:39-41`); `handle()` re-counts under lock (`:68-70`), withdraws the member's organization and reviewer invitations (`:72-98`) and logs `organization.member_removed` (`:103-107`). So a platform admin who is not a member is refused with `not_allowed` today, and could never grant or change an owner.

4.5. **`User`.** `roleIn()` is a membership lookup (`app/Models/User.php:95-100`); `is_platform_admin` is cast to boolean (`:45`); `canAccessPanel('admin')` is `is_platform_admin && hasVerifiedEmail()` (`:102-105`).

4.6. **The membership policy says yes to an owner and to a platform admin.** `OrganizationMemberPolicy::before()` returns `true` for `is_platform_admin` (`app/Policies/OrganizationMemberPolicy.php:26-29`); `update()` is "a manager of the pivot's organization" (`:62-68`). So that policy cannot tell the admin panel from the organizer panel.

4.7. **The organizer's own screen treats invitations as part of managing members.** One table of members and open invitations with Change role, Remove, Resend and Withdraw side by side (`app/Filament/Organizer/Pages/Members.php:105-111`); every `MemberChangeRefused` becomes `refuse()` — a persistent danger notification whose body is the escaped reasons (`:316`, `:345`, `:548-558`). `OrganizationInvitationPolicy`'s docblock: "An invitee's address and the role they were granted are as much "manage members" as the buttons are." (`app/Policies/OrganizationInvitationPolicy.php:20-22`).

4.8. **`RevokeInvitation` has no actor guard and logs its causer.** It stamps `revoked_at` once and logs `organization.invitation_revoked` with `causedBy($actor)` (`app/Actions/Organizations/RevokeInvitation.php:19-27`).

4.9. **An invitation is dead if its inviter holds no manager role at accept time.** `AcceptInvitation::blockers()`: `$inviterRole = $invitation->inviter?->roleIn($organization)` (`app/Actions/Invitations/AcceptInvitation.php:76`), and `inviter_gone` when it is null, not a manager, or not an owner for an owner invitation (`:78-82`).

4.10. **`OrganizationResource::canAccess()` is `is_platform_admin`** (`OrganizationResource.php:56-62`).

4.11. **A test pins today's "no write at all".** `tests/Feature/Admin/AdminReadOnlyTest.php:156-177` asserts `array_diff($names, ['view']) === []` for all five relation managers, the two organization ones among them (`:175-176`); `:145-153` shows how the managers are mounted (`ownerRecord`, `pageClass`).

4.12. **Reusable strings.** `lang/en/members.php`: `fields.role` (`:85`), `actions` (`:95-111`), `notices` (`:114-123`, `refused` at `:122`). `actions.remove_description` ends "you can invite them again later" (`:103`).

**Decisions this task makes.**

1. **A platform admin acts with an owner's authority over members — one new method, `User::authorityIn()`, which `ChangeMemberRole::blockers()` and `RemoveMember::blockers()` ask instead of `roleIn()` for the actor.** Today a non-member platform admin is refused outright and could never touch an owner (fact 4.4), while spec section 4 gives the platform admin the owner's tick (fact 4.1). Owner authority is what the support cases need — the only owner has left and somebody else has to be made an owner, or an owner's account has to go. **Every other guard stays and applies to the admin too**: the own-row rules (an `is()` comparison, so they bite whether or not the admin is also a member), the last-owner rule — both the plain count and the `lockForUpdate()` re-count inside the transaction — and the withdrawal of a demoted or removed member's invitations. `roleIn()` keeps meaning membership everywhere else (panels, policies, `AcceptInvitation`). One side effect, and it is the same rule: a platform admin who is also a plain member of an organization already sees Change role on the organizer page (fact 4.6) and was refused by the action; now the action agrees with the button.

2. **The admin actions authorize on `OrganizationResource::canAccess()`, not on the membership policy.** That policy says yes to the organization's own managers (fact 4.6), and the action classes would let an owner act on their own organization — right on their page, wrong in the admin panel. A relation manager authorizes nothing when mounted and, for the members manager, nothing useful on hydrate (fact 4.3), so each action's `authorize()` is the wall behind the panel's 403, and the owner test mounts the managers directly to prove it.

3. **Withdrawing a pending invitation belongs here; inviting and resending do not.** Spec section 4 says "Manage organization members", and the organizer's own screen and policy both count pending invitations as part of the team (fact 4.7). Withdraw is `RevokeInvitation` — no actor guard, logs its causer (fact 4.8) — and it only ever removes access: the support case is a link sent to the wrong address while the owner is unreachable. **Invite and Resend would mint an invitation whose inviter is the platform admin**, and `AcceptInvitation::blockers()` refuses any invitation whose inviter has no manager role in the organization at accept time (fact 4.9) — so the link would be dead on arrival. Making it live means teaching `AcceptInvitation` that a platform admin may vouch for a member of any organization, which is a product decision (report), not this task.

4. **A refusal is the organizer page's notification, and Filament's own write actions stay refused.** `MemberChangeRefused` is caught into the same persistent danger notification, with every reason (fact 4.7); nothing an admin can click reaches a 500. `isReadOnly()` stays `true` — it is what refuses Create, Edit, Attach and Detach, which would write the pivot directly — and the three writes are custom actions, which it does not cover (fact 4.3). `AdminReadOnlyTest`'s dataset gains a column naming each manager's writes, so a fourth is a red test, not a surprise.

5. **Strings: `members.*` for buttons, headings and notices; two new `admin.members.*` descriptions.** The organizer's Remove description promises "you can invite them again later" (fact 4.12), which the reader here cannot (decision 3); and Change role gains a description that says whose authority the admin is using and that the last owner stays.

6. **Change role and Remove are hidden on the admin's own row**, as on the organizer page; the action classes refuse it anyway (`own_role`, `own_membership`), and a test pins the refusal with the admin as a member.

**Files:**
- Modify: `app/Models/User.php` (`authorityIn()`)
- Modify: `app/Actions/Organizations/ChangeMemberRole.php`, `app/Actions/Organizations/RemoveMember.php` (one line each)
- Modify: `app/Filament/Admin/Resources/Organizations/RelationManagers/MembersRelationManager.php` (Change role, Remove)
- Modify: `app/Filament/Admin/Resources/Organizations/RelationManagers/InvitationsRelationManager.php` (Withdraw)
- Modify: `app/Filament/Admin/Resources/Organizations/OrganizationResource.php`, `app/Filament/Organizer/Pages/Members.php` (docblocks that fact 4.2 shows are now wrong)
- Modify: `lang/en/admin.php` (two keys in `members`)
- Modify: `tests/Feature/Admin/AdminReadOnlyTest.php` (the dataset names the writes), `tests/Feature/LanguageCoverageTest.php` (two paths on `$plan7Sources`)
- Test: `tests/Feature/Admin/MembersManagementTest.php` (new, 9 cases)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Admin/MembersManagementTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Organizations\ChangeMemberRole;
use App\Actions\Organizations\RemoveMember;
use App\Enums\OrganizationRole;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Filament\Admin\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Admin\Resources\Organizations\RelationManagers\InvitationsRelationManager;
use App\Filament\Admin\Resources\Organizations\RelationManagers\MembersRelationManager;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

use Spatie\Activitylog\Models\Activity;

/**
 * Spec section 4's platform-admin cell for "Manage organization members": the
 * two writes on MembersRelationManager and the one on InvitationsRelationManager,
 * each through the action class the organizer's Members page already calls,
 * with every guard that action already carries.
 */
beforeEach(function () {
    Notification::fake();

    $this->platformAdmin = User::factory()->platformAdmin()->create();
    actingAs($this->platformAdmin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->owner = User::factory()->create(['name' => 'Dr Owner']);
    $this->member = User::factory()->create(['name' => 'Dr Member']);
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
    $this->organization->addMember($this->member, OrganizationRole::Member);
});

function adminMembersManager(Organization $organization): Testable
{
    return livewire(MembersRelationManager::class, [
        'ownerRecord' => $organization,
        'pageClass' => ViewOrganization::class,
    ]);
}

function adminInvitationsManager(Organization $organization): Testable
{
    return livewire(InvitationsRelationManager::class, [
        'ownerRecord' => $organization,
        'pageClass' => ViewOrganization::class,
    ]);
}

it('changes a member role and logs the platform admin as the causer', function () {
    adminMembersManager($this->organization)
        ->callTableAction('changeRole', $this->member, data: ['role' => OrganizationRole::Admin->value])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('members.notices.role_changed'));

    expect($this->member->roleIn($this->organization))->toBe(OrganizationRole::Admin);

    $entry = Activity::query()->where('description', 'organization.role_changed')->sole();

    expect($entry->causer_id)->toBe($this->platformAdmin->getKey())
        ->and($entry->subject_id)->toBe($this->organization->getKey());
});

it('hands ownership over: a new owner first, then the old one steps down', function () {
    // The support case this screen exists for. Only an owner may grant the
    // owner role, and a platform admin who is not a member acts with an
    // owner's authority - so the admin can do it without joining.
    adminMembersManager($this->organization)
        ->callTableAction('changeRole', $this->member, data: ['role' => OrganizationRole::Owner->value])
        ->assertHasNoTableActionErrors();

    adminMembersManager($this->organization)
        ->callTableAction('changeRole', $this->owner, data: ['role' => OrganizationRole::Member->value])
        ->assertHasNoTableActionErrors();

    expect($this->member->roleIn($this->organization))->toBe(OrganizationRole::Owner)
        ->and($this->owner->roleIn($this->organization))->toBe(OrganizationRole::Member)
        ->and($this->platformAdmin->roleIn($this->organization))->toBeNull();
});

it('removes a member, keeps their account, and logs the platform admin as the causer', function () {
    adminMembersManager($this->organization)
        ->callTableAction('remove', $this->member)
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('members.notices.removed'));

    expect($this->member->roleIn($this->organization))->toBeNull()
        ->and(User::query()->whereKey($this->member->getKey())->exists())->toBeTrue();

    expect(Activity::query()->where('description', 'organization.member_removed')->sole()->causer_id)
        ->toBe($this->platformAdmin->getKey());
});

it('will not leave an organization without an owner, and says so instead of failing', function () {
    adminMembersManager($this->organization)
        ->callTableAction('changeRole', $this->owner, data: ['role' => OrganizationRole::Admin->value])
        ->assertNotified(__('members.notices.refused'));

    adminMembersManager($this->organization)
        ->callTableAction('remove', $this->owner)
        ->assertNotified(__('members.notices.refused'));

    expect($this->owner->roleIn($this->organization))->toBe(OrganizationRole::Owner)
        ->and(Activity::query()->whereIn('description', ['organization.role_changed', 'organization.member_removed'])->count())->toBe(0);
});

it('keeps every guard but the membership one for a platform admin', function () {
    $change = app(ChangeMemberRole::class);
    $remove = app(RemoveMember::class);

    // Not a member, and still allowed - including the owner-only moves.
    expect($change->blockers($this->organization, $this->member, OrganizationRole::Owner, $this->platformAdmin))->toBe([])
        ->and($remove->blockers($this->organization, $this->member, $this->platformAdmin))->toBe([])
        // The last owner is still the last owner.
        ->and($change->blockers($this->organization, $this->owner, OrganizationRole::Member, $this->platformAdmin))
        ->toBe([__('members.errors.last_owner')])
        ->and($remove->blockers($this->organization, $this->owner, $this->platformAdmin))
        ->toBe([__('members.errors.last_owner')]);

    // And a platform admin who IS a member still cannot edit their own row.
    $this->organization->addMember($this->platformAdmin, OrganizationRole::Admin);

    expect($change->blockers($this->organization, $this->platformAdmin, OrganizationRole::Owner, $this->platformAdmin))
        ->toContain(__('members.errors.own_role'))
        ->and($remove->blockers($this->organization, $this->platformAdmin, $this->platformAdmin))
        ->toContain(__('members.errors.own_membership'));
});

it('cannot reach a member of another organization through this one', function () {
    $other = Organization::factory()->approved()->create();
    $stranger = User::factory()->create(['name' => 'Somebody Else']);
    $other->addMember($stranger, OrganizationRole::Member);

    // The control: the action is there for this organization's own member,
    // so the refusal below is about the boundary and not a missing button.
    adminMembersManager($this->organization)
        ->assertTableActionVisible('changeRole', $this->member)
        ->assertCanNotSeeTableRecords([$stranger]);

    // The row actions themselves. The manager resolves a record only through
    // $organization->members(), so a foreign key is no record at all: Filament
    // refuses to mount the action before any closure runs. And
    // ChangeMemberRole::blockers() refuses a target with no role here as the
    // second wall.
    expect(fn () => adminMembersManager($this->organization)
        ->callTableAction('changeRole', $stranger, data: ['role' => OrganizationRole::Owner->value]))
        ->toThrow(ActionNotResolvableException::class)
        ->and(fn () => adminMembersManager($this->organization)->callTableAction('remove', $stranger))
        ->toThrow(ActionNotResolvableException::class);

    expect($stranger->roleIn($other))->toBe(OrganizationRole::Member)
        ->and($stranger->roleIn($this->organization))->toBeNull()
        ->and(app(ChangeMemberRole::class)->blockers($this->organization, $stranger, OrganizationRole::Owner, $this->platformAdmin))
        ->toContain(__('members.errors.not_a_member'));
});

it('withdraws a pending invitation and logs the platform admin as the causer', function () {
    $pending = OrganizationInvitation::factory()->for($this->organization)->create(['email' => 'pending@example.org']);
    $accepted = OrganizationInvitation::factory()->for($this->organization)->create([
        'email' => 'joined@example.org',
        'accepted_at' => now(),
    ]);

    adminInvitationsManager($this->organization)
        ->assertTableActionHidden('revoke', $accepted)
        ->callTableAction('revoke', $pending)
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('members.notices.revoked'));

    expect($pending->fresh()?->revoked_at)->not->toBeNull()
        ->and(Activity::query()->where('description', 'organization.invitation_revoked')->sole()->causer_id)
        ->toBe($this->platformAdmin->getKey());
});

it('cannot withdraw another organization invitation through this one', function () {
    $theirs = OrganizationInvitation::factory()
        ->for(Organization::factory()->approved())
        ->create(['email' => 'theirs@example.org']);

    $ours = OrganizationInvitation::factory()->for($this->organization)->create(['email' => 'ours@example.org']);

    adminInvitationsManager($this->organization)
        ->assertTableActionVisible('revoke', $ours)
        ->assertCanNotSeeTableRecords([$theirs]);

    expect(fn () => adminInvitationsManager($this->organization)->callTableAction('revoke', $theirs))
        ->toThrow(ActionNotResolvableException::class);

    expect($theirs->fresh()?->revoked_at)->toBeNull();
});

it('gives an organization owner neither the page nor the actions', function () {
    $invitation = OrganizationInvitation::factory()->for($this->organization)->create();

    actingAs($this->owner)
        ->get(OrganizationResource::getUrl('view', ['record' => $this->organization], panel: 'admin'))
        ->assertForbidden();

    // Mounted directly, past the panel middleware. ChangeMemberRole and
    // RemoveMember would say yes to this owner - it is their organization - so
    // each action's authorize() is what keeps them in the admin panel.
    adminMembersManager($this->organization)
        ->assertTableActionHidden('changeRole', $this->member)
        ->assertTableActionHidden('remove', $this->member);

    adminInvitationsManager($this->organization)->assertTableActionHidden('revoke', $invitation);

    expect($this->member->roleIn($this->organization))->toBe(OrganizationRole::Member)
        ->and($invitation->fresh()?->revoked_at)->toBeNull();
});
```

In `tests/Feature/Admin/AdminReadOnlyTest.php`, the dataset case names each manager's writes and checks both that nothing else writes and that the named ones exist:

Replace:

```php
it('offers nothing that writes on any of the five relation managers or the review list', function (string $manager, string $owner, string $page) {
```

with:

```php
it('offers no write on the five relation managers but the named ones that go through an action class', function (string $manager, string $owner, string $page, array $writes) {
```

Replace:

```php
    // RelationManager adds CreateAction, EditAction and DeleteAction by
    // default, and every one of these parents' policies answers true for a
    // platform admin through before() - so the refusal is the class, not the
    // policy.
    expect(array_diff($names, ['view']))->toBe([]);
})->with([
    [ReviewsRelationManager::class, 'submission', ViewSubmission::class],
    [AssignmentsRelationManager::class, 'submission', ViewSubmission::class],
    [ReviewersRelationManager::class, 'conference', ViewConference::class],
    [MembersRelationManager::class, 'organization', ViewOrganization::class],
    [InvitationsRelationManager::class, 'organization', ViewOrganization::class],
]);
```

with:

```php
    // RelationManager adds CreateAction, EditAction and DeleteAction by
    // default, and every one of these parents' policies answers true for a
    // platform admin through before() - so the refusal is the class, not the
    // policy. The writes that do exist are named here one by one: each is a
    // custom Action calling an app/Actions class (Plan 7 Task 4), never a
    // Filament built-in that would write the row directly.
    expect(array_values(array_diff($names, ['view', ...$writes])))->toBe([])
        ->and(array_values(array_intersect($writes, $names)))->toBe($writes);
})->with([
    [ReviewsRelationManager::class, 'submission', ViewSubmission::class, []],
    [AssignmentsRelationManager::class, 'submission', ViewSubmission::class, []],
    [ReviewersRelationManager::class, 'conference', ViewConference::class, []],
    [MembersRelationManager::class, 'organization', ViewOrganization::class, ['changeRole', 'remove']],
    [InvitationsRelationManager::class, 'organization', ViewOrganization::class, ['revoke']],
]);
```

- [ ] **Step 2: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact --filter='MembersManagementTest|AdminReadOnlyTest' > /tmp/t-task-4.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-4.log
```

Expected: `rc=2`, **11 failed, 12 passed**: all nine new cases (eight with `ActionNotResolvableException: Action [changeRole] not found on table.` — or `[remove]`, `[revoke]` — and `keeps every guard but the membership one` on `Failed asserting that two arrays are identical`, because today's blockers answer `not_allowed` for a platform admin), plus the two organization rows of the rewritten dataset case. The two cross-tenant cases fail too, on their positive control — the action has to exist before "it cannot reach another organization" means anything.

- [ ] **Step 3: The authority, in one place**

`app/Models/User.php`, directly after `roleIn()`:

Replace:

```php
        return $member?->pivot->role;
    }

    public function canAccessPanel(Panel $panel): bool
```

with:

```php
        return $member?->pivot->role;
    }

    /**
     * The role this user ACTS WITH when managing an organization's members:
     * their own membership role, or an owner's for a platform admin, member or
     * not. Spec section 4 ticks "Manage organization members" for the platform
     * admin exactly as for the owner. roleIn() stays the membership question -
     * panels, policies and the self-edit guards keep asking it - and only
     * ChangeMemberRole and RemoveMember ask this one.
     */
    public function authorityIn(Organization $organization): ?OrganizationRole
    {
        return $this->is_platform_admin ? OrganizationRole::Owner : $this->roleIn($organization);
    }

    public function canAccessPanel(Panel $panel): bool
```

`app/Actions/Organizations/ChangeMemberRole.php` and `app/Actions/Organizations/RemoveMember.php` — the same one-line change in each `blockers()`. Nothing else in either file changes:

Replace:

```php
        $reasons = [];
        $actorRole = $actor->roleIn($organization);
```

with:

```php
        $reasons = [];
        // authorityIn(), not roleIn(): a platform admin manages any
        // organization's members with an owner's authority without joining it
        // (Plan 7 Task 4). Every guard below still applies to them.
        $actorRole = $actor->authorityIn($organization);
```

Replace:

```php
        $reasons = [];
        $actorRole = $actor->roleIn($organization);
```

with:

```php
        $reasons = [];
        // authorityIn(), not roleIn(): a platform admin manages any
        // organization's members with an owner's authority without joining it
        // (Plan 7 Task 4). Every guard below still applies to them.
        $actorRole = $actor->authorityIn($organization);
```

- [ ] **Step 4: Change role and Remove on the members manager**

`app/Filament/Admin/Resources/Organizations/RelationManagers/MembersRelationManager.php` — three edits; the column lines are not touched. The imports:

Replace:

```php
use App\Enums\OrganizationRole;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
```

with:

```php
use App\Actions\Organizations\ChangeMemberRole;
use App\Actions\Organizations\RemoveMember;
use App\Enums\OrganizationRole;
use App\Exceptions\MemberChangeRefused;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
```

The class docblock:

Replace:

```php
/**
 * Spec section 4's platform-admin cell for "Manage organization members" had no
 * screen at all: canAccessPanel('organizer') is organizations()->exists(), so a
 * platform admin who is not a member cannot open the organizer panel's Members
 * page for anybody.
 *
 * Read-only by class. `members` is a BelongsToMany of User through the
 * OrganizationMember pivot, so the interesting columns are on `pivot.*` and the
 * `role` cast lives on the pivot model rather than on User - which is why the
 * badge is formatted from the raw pivot value.
 */
```

with:

```php
/**
 * Spec section 4's platform-admin cell for "Manage organization members". The
 * organizer panel is membership-gated (canAccessPanel('organizer') is
 * organizations()->exists()), so a platform admin who is not a member cannot
 * open the organizer panel's Members page for anybody; this is that page's
 * admin twin.
 *
 * `members` is a BelongsToMany of User through the OrganizationMember pivot,
 * so the interesting columns are on `pivot.*` and the `role` cast lives on the
 * pivot model rather than on User - which is why the badge is formatted from
 * the raw pivot value.
 *
 * isReadOnly() stays true: it is what refuses Filament's own Create, Edit,
 * Attach and Detach actions, which would write the pivot directly. The two
 * writes here are custom actions - RelationManager::getDefaultActionAuthorizationResponse()
 * answers null for those - that call ChangeMemberRole and RemoveMember with
 * the platform admin as the actor, so the last-owner, own-row and
 * invitation-withdrawal rules are the organizer page's, not a copy of them.
 */
```

The row actions and the methods behind them:

Replace:

```php
            ->headerActions([])
            ->toolbarActions([])
            ->recordActions([]);
    }
}
```

with:

```php
            ->headerActions([])
            ->toolbarActions([])
            ->recordActions([
                $this->changeRoleAction(),
                $this->removeAction(),
            ]);
    }

    private function changeRoleAction(): Action
    {
        return Action::make('changeRole')
            ->label(__('members.actions.change_role'))
            ->icon(Heroicon::OutlinedPencilSquare)
            // The resource's own gate, is_platform_admin. Not the pivot policy:
            // OrganizationMemberPolicy::update() says yes to this
            // organization's owners too, which is right on their own page and
            // wrong on this one.
            ->authorize(fn (): bool => OrganizationResource::canAccess())
            ->visible(fn (User $record): bool => ! $record->is(auth()->user()))
            ->modalHeading(fn (User $record): string => __('members.actions.change_role_heading', ['name' => (string) $record->name]))
            ->modalDescription(__('admin.members.change_role_description'))
            ->fillForm(fn (User $record): array => ['role' => $record->roleIn($this->organization())?->value])
            ->schema([
                // A plain options array rather than ->options(OrganizationRole::class):
                // an enum-backed Select casts its state, and the organizer
                // page's comment on the same field says why a string both ways
                // is the safer shape. Every role, the owner's included, because
                // the platform admin acts with an owner's authority.
                Select::make('role')
                    ->label(__('members.fields.role'))
                    ->options(fn (): array => collect(OrganizationRole::cases())
                        ->mapWithKeys(fn (OrganizationRole $role): array => [$role->value => $role->getLabel()])
                        ->all())
                    ->required(),
            ])
            ->action(function (User $record, array $data, ChangeMemberRole $change): void {
                try {
                    $change->handle($this->organization(), $record, OrganizationRole::from((string) $data['role']), $this->actor());
                } catch (MemberChangeRefused $exception) {
                    $this->refuse($exception);

                    return;
                }

                Notification::make()->success()->title(__('members.notices.role_changed'))->send();
            });
    }

    private function removeAction(): Action
    {
        return Action::make('remove')
            ->label(__('members.actions.remove'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->authorize(fn (): bool => OrganizationResource::canAccess())
            ->visible(fn (User $record): bool => ! $record->is(auth()->user()))
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('members.actions.remove_heading', ['name' => (string) $record->name]))
            ->modalDescription(__('admin.members.remove_description'))
            ->action(function (User $record, RemoveMember $remove): void {
                try {
                    $remove->handle($this->organization(), $record, $this->actor());
                } catch (MemberChangeRefused $exception) {
                    $this->refuse($exception);

                    return;
                }

                Notification::make()->success()->title(__('members.notices.removed'))->send();
            });
    }

    private function organization(): Organization
    {
        /** @var Organization $organization */
        $organization = $this->getOwnerRecord();

        return $organization;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * The organizer page's refusal, word for word: a danger notification that
     * stays until it is read, listing every blocker. The reasons are escaped
     * because a notification body is rendered as HTML.
     */
    private function refuse(MemberChangeRefused $exception): void
    {
        Notification::make()->danger()
            ->title(__('members.notices.refused'))
            ->body(e($exception->getMessage()))
            ->persistent()
            ->send();
    }
}
```

- [ ] **Step 5: Withdraw on the invitations manager**

`app/Filament/Admin/Resources/Organizations/RelationManagers/InvitationsRelationManager.php` — the imports:

Replace:

```php
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
```

with:

```php
use App\Actions\Organizations\RevokeInvitation;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
```

the first paragraph of the docblock:

Replace:

```php
/**
 * Who has been invited into this organization and what became of each
 * invitation. Read-only by class: OrganizationInvitationPolicy::before()
 * answers true for a platform admin without the ability ever being called, so
 * the empty arrays are the refusal.
 *
```

with:

```php
/**
 * Who has been invited into this organization and what became of each
 * invitation. Read-only for Filament's own actions: OrganizationInvitationPolicy::before()
 * answers true for a platform admin without the ability ever being called, so
 * isReadOnly() and the empty header and toolbar arrays are the refusal.
 *
 * The one write is Withdraw, through RevokeInvitation - the organizer Members
 * page's own action. Not Invite and not Resend: an invitation is its
 * inviter's authority exercised later, and AcceptInvitation::blockers()
 * refuses one whose inviter holds no manager role in the organization at
 * accept time - which a platform admin who is not a member never does. An
 * admin-minted link would be dead on arrival (Plan 7 Task 4 decision 3).
 *
```

and the row action:

Replace:

```php
            ->headerActions([])
            ->toolbarActions([])
            ->recordActions([]);
    }
}
```

with:

```php
            ->headerActions([])
            ->toolbarActions([])
            ->recordActions([
                $this->revokeAction(),
            ]);
    }

    private function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('members.actions.revoke'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            // The resource's own gate, is_platform_admin, for the same reason
            // as MembersRelationManager: the invitation policy says yes to this
            // organization's managers as well.
            ->authorize(fn (): bool => OrganizationResource::canAccess())
            ->visible(fn (OrganizationInvitation $record): bool => $record->accepted_at === null && $record->revoked_at === null)
            ->requiresConfirmation()
            ->modalHeading(__('members.actions.revoke_heading'))
            ->modalDescription(__('members.actions.revoke_description'))
            ->action(function (OrganizationInvitation $record, RevokeInvitation $revoke): void {
                /** @var User $admin */
                $admin = auth()->user();

                $revoke->handle($record, $admin);

                Notification::make()->success()->title(__('members.notices.revoked'))->send();
            });
    }
}
```

- [ ] **Step 6: The two docblocks that are now wrong**

`app/Filament/Admin/Resources/Organizations/OrganizationResource.php`:

Replace:

```php
     * who is not a member of an organization could not see who is in it. Both
     * managers are read-only by class.
```

with:

```php
     * who is not a member of an organization could not see who is in it. Both
     * refuse Filament's own write actions (isReadOnly()); the three writes they
     * do offer - change a role, remove a member, withdraw an invitation - are
     * custom actions over ChangeMemberRole, RemoveMember and RevokeInvitation.
```

`app/Filament/Organizer/Pages/Members.php`:

Replace:

```php
     * "Manage organization members" has no screen behind it - exactly as Plan 3
     * left submissions. Plan 6 adds the read-only admin resources; it is in the
     * backlog and in the "does NOT build" list, not a surprise.
```

with:

```php
     * "Manage organization members" is not served here. It is served in the
     * admin panel, by OrganizationResource's MembersRelationManager and
     * InvitationsRelationManager (Plan 7 Task 4), through the same actions this
     * page calls.
```

- [ ] **Step 7: The strings**

`lang/en/admin.php` — two keys at the end of the existing `members` group, after its `columns` array (anchored on that array's last line, which no other task changes):

Replace:

```php
            'since' => 'Member since',
        ],
    ],
```

with:

```php
            'since' => 'Member since',
        ],
        // The admin's Change role and Remove reuse members.actions.* for the
        // buttons and headings; these two descriptions differ because the
        // reader does: a platform admin acts with an owner's authority, and
        // cannot invite anybody back.
        'change_role_description' => 'You act with an owner\'s authority here. An organization always keeps at least one owner, so make somebody else an owner before you change the last one.',
        'remove_description' => 'They lose access to this organization straight away, and every invitation they sent is withdrawn. Their CASS account and anything they created stay as they are, and an owner or admin of the organization can invite them again.',
    ],
```

Append these two paths to `$plan7Sources` in `tests/Feature/LanguageCoverageTest.php`:

```php
    'app/Filament/Admin/Resources/Organizations/RelationManagers/MembersRelationManager.php',
    'app/Filament/Admin/Resources/Organizations/RelationManagers/InvitationsRelationManager.php',
```

- [ ] **Step 8: Run the task's tests and every suite that exercises these two actions**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact --filter='MembersManagementTest|AdminReadOnlyTest|MembersPageTest|AcceptInvitationTest|LanguageCoverageTest' > /tmp/t-task-4.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-4.log
```

Expected: `rc=0`. `MembersPageTest` (19 cases) and `AcceptInvitationTest` (23) are in this run unedited: they are the organizer's last-owner, own-row, cross-tenant and invitation-withdrawal rules, and decision 1 must not have moved any of them.

- [ ] **Step 9: Pint and Larastan**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint --test > /tmp/pint-task-4.log 2>&1; echo "pint rc=$?"; \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan-task-4.log 2>&1; echo "stan rc=$?"; tail -5 /tmp/stan-task-4.log
```

Expected: `pint rc=0`, `stan rc=0`.

- [ ] **Step 10: The whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test > /tmp/all-task-4.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-4.log
```

Expected: `all rc=0`, and **9 more passing tests than Step 15 of Task 3** (the prototype, built on Task 3's prototype rather than bare `45d2d8e`: 1235 → 1244, plus the two stand-in `$plan7Sources` cases).

- [ ] **Step 11: Commit**

The suite gates the commit in the same command, and the paths are named rather than `-A` (Task 2, Step 10). The message's last line is a placeholder: put the session's Co-Authored-By trailer there before running it.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test > /tmp/all-task-4.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add app/Models/User.php app/Actions/Organizations/ChangeMemberRole.php app/Actions/Organizations/RemoveMember.php \
  app/Filament/Admin/Resources/Organizations/RelationManagers/MembersRelationManager.php \
  app/Filament/Admin/Resources/Organizations/RelationManagers/InvitationsRelationManager.php \
  app/Filament/Admin/Resources/Organizations/OrganizationResource.php app/Filament/Organizer/Pages/Members.php lang/en/admin.php \
  tests/Feature/Admin/AdminReadOnlyTest.php tests/Feature/LanguageCoverageTest.php tests/Feature/Admin/MembersManagementTest.php && \
git commit -q -F - <<'EOF' && git log --oneline -1 && git status --short --untracked-files=no
feat(admin): the platform admin changes a role, removes a member, withdraws an invitation

Spec section 4 gives the platform admin the owner's tick on "Manage
organization members", so ChangeMemberRole and RemoveMember now ask
User::authorityIn(), which is an owner's role for a platform admin and
roleIn() for everybody else. Every other guard is unchanged and applies to
the admin too: no own row, never fewer than one owner (re-counted under
lock), and a removed or demoted member's invitations are withdrawn. The
admin actions authorize on is_platform_admin, because the membership
policy says yes to an organization's own owners. Invite and Resend are not
offered: AcceptInvitation refuses an invitation whose inviter holds no role
in the organization, so an admin-minted link would be dead on arrival.

<the session's Co-Authored-By trailer>
EOF
```

Expected: `all rc=0 - the suite gates this commit`, one log line, and nothing from `git status --short --untracked-files=no`.


### Task 5: Failed notifications are marked failed and encrypted, abandoned uploads are swept, and the orphan icon goes

Six backlog bullets, which are three items each recorded twice — once by the plan that found it and once more by Plan 6's launch section. From **Submissions (deferred by Plan 3)**:

> **A failed *notification* stays at `queued` in `email_logs`.** `Illuminate\Mail\SendQueuedMailable::failed()` calls `$mailable->failed()`, so `TemplatedMail` marks itself failed; `Illuminate\Notifications\Notification` has no equivalent hook, so a transport error on `OrganizationApproved` or `NewSubmissionNotice` leaves the row where it was. Documented in the runbook's email triage table. Fixing it properly means a `JobFailed` listener that can correlate a failed job back to a log row.

> **Livewire temporary uploads linger.** `config/livewire.php` (Task 7) caps the endpoint at 10 MB and `CASS_UPLOAD_RATE_LIMIT` requests a minute, and leaves `cleanup` on — but that cleanup is *opportunistic*: `FileUploadController::_finishUpload()` sweeps files older than 24 hours only when the **next** upload arrives, so a quiet period after a busy one leaves abandoned files in `storage/app/private/livewire-tmp` inside the `cass-storage` volume. Add a scheduled sweep if the volume grows between deadlines.

From **Public site polish (Plan 6, launch polish)**:

> `public/images/icons/badge.svg` (4,098 bytes) is referenced by nothing. It is the fourth of a set of three the landing page uses, so it is probably a section that was cut rather than a mistake. Either give it a home or `git rm` it — deliberately, in a commit that is about assets, not buried in one about translations.

From **Launch (deferred by Plan 6)**:

> **`public/images/icons/badge.svg` is referenced by nothing.** Found during Plan 6's language sweep and deliberately left: deleting an asset inside a commit full of translations is a `git rm` nobody notices in the diff.

> **Livewire's temporary uploads still linger.** Raised by Plan 3: `FileUploadController::_finishUpload()` sweeps files older than 24 hours only when the *next* upload arrives, so a quiet period after a busy one leaves abandoned files in `storage/app/private/livewire-tmp` inside the `cass-storage` volume. Plan 6 did not add a scheduled sweep — but `cass:health`'s private-disk check now makes a filling volume visible before an author's upload fails, which is the symptom that mattered.

> **A failed *notification* still stays at `queued` in `email_logs`.** Plan 6 moved `organization_approved` and `organization_rejected` onto `SendTemplatedEmail`, whose `TemplatedMail::failed()` marks the row failed — so two of the five are fixed. `NewSubmissionNotice`, `MemberInvitation` and `QueuedVerifyEmail` are still `Illuminate\Notifications\Notification`, which has no `failed()` hook. Fixing the rest properly is a `JobFailed` listener that can correlate a failed job back to a log row.

Two premises in those entries are wrong, and the task is built on the corrected versions: the queued notification job **does** have a `failed()` hook (fact 5.4), and the Livewire cleanup is not in `FileUploadController` (fact 5.13). Two things the entries do not name come with the first item, because they live in the same job: Filament's queued password reset writes its plaintext token into `jobs.payload` (fact 5.6), so the job that marks failures also encrypts every notification (decision 6); and `QueuedVerifyEmail` had never rendered at all (fact 5.12) — that one is already fixed, by the hotfix that ships before Plan 7, and this task has nothing to do for it.

Four commits, in this order: failed notifications (Steps 1-8), encrypted notification payloads (Steps 9-13), the upload sweep (Steps 14-19), the icon (Steps 20-23). **No migration**: decision 2 keys a notification's row by its existing `ulid` column, so there is no window between deploy and `migrate` in which anything degrades.

**Facts this task relies on.**

5.1. **Installed versions** (`composer.lock`): `laravel/framework` v13.32.0 (`:2327-2328`), `livewire/livewire` v4.4.4 (`:3481-3482`), `filament/filament` v5.8.1 (`:1277-1278`), `symfony/uid` v8.1.5 (`:8205-8206`).

5.2. **A notification's `email_logs` row is written while it is being sent, never before.** `RecordOutgoingEmail::sending()` writes a row for every message that has no `X-CASS-Log` header (`app/Listeners/RecordOutgoingEmail.php:40-61`) and `sent()` flips it to `sent`, guarded on `queued` (`:63-79`). `Mailer::send()` dispatches `MessageSending` through `shouldSendMessage()` and only then calls the transport (`vendor/laravel/framework/src/Illuminate/Mail/Mailer.php:331-332`, event at `:609`), and `MessageSent` only after the transport returned (`:337`). So a transport that throws leaves the row at `queued`, which the listener's docblock records as a known gap (`RecordOutgoingEmail.php:23-28`), and a notification that throws *before* the mailer (in `toMail()`) has no row at all. `TemplatedMail` is different: `SendTemplatedEmail` writes its row before queueing and the mailable carries `X-CASS-Log` (`app/Mail/TemplatedMail.php:79-82`), and `TemplatedMail::failed()` (`:103-110`) updates that row by ULID, guarded on `queued`, with the error trimmed to 2,000 characters.

5.3. **Seven queued notifications reach `email_logs` this way, and two of them are Filament's.** `app/Notifications/` holds five, every one `ShouldQueue` with `via()` returning `['mail']` and none with a `failed()` method: `CustomDomainVerified` (`:21`, `:28-31`), `MemberInvitation` (`:36`, `:48-51`), `NewSubmissionNotice` (`:25`, `:32-35`), `OrganizationRegistered` (`:15`, `:22-25`) and `QueuedVerifyEmail` (`:11-14` on `45d2d8e`). The other two are vendor classes the application cannot edit: `Filament\Auth\Notifications\ResetPassword` (`vendor/filament/filament/src/Auth/Notifications/ResetPassword.php:9`, `implements ShouldQueue`), resolved from the container and sent at `vendor/filament/filament/src/Auth/Pages/PasswordReset/RequestPasswordReset.php:84-87`, and `Filament\Auth\Notifications\VerifyEmail` (`vendor/filament/filament/src/Auth/Notifications/VerifyEmail.php:9`), sent by the panel's "Resend" button at `vendor/filament/filament/src/Auth/Pages/EmailVerification/EmailVerificationPrompt.php:55-59`. All three panels call `->passwordReset()` (`app/Providers/Filament/AdminPanelProvider.php:35`, `OrganizerPanelProvider.php:39`, `ReviewerPanelProvider.php:48`).

5.4. **The queued notification job has a `failed()` hook, and it runs once, after the last try.** `vendor/laravel/framework/src/Illuminate/Notifications/SendQueuedNotifications.php:158-163`:

   ```php
   public function failed($e)
   {
       if (method_exists($this->notification, 'failed')) {
           $this->notification->failed($e);
       }
   }
   ```

   The worker reaches it only on a final failure: `Worker::handleJobException()` releases the job on any other attempt (`vendor/laravel/framework/src/Illuminate/Queue/Worker.php:630-669`, `release()` at `:660`); `markJobAsFailedIfWillExceedMaxAttempts()` calls `failJob()` when `attempts() >= maxTries` (`:711-722`), which calls `$job->fail($e)` (`:787-790`); `Job::fail()` calls `$this->failed($e)` (`vendor/laravel/framework/src/Illuminate/Queue/Jobs/Job.php:219`) and dispatches `JobFailed` in a `finally` after it (`:221`); `Job::failed()` hands the payload to `CallQueuedHandler::failed()` (`:247-256`), which rebuilds the command and calls `$command->failed($e)` (`vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php:414-436`). The `sync` connection takes the same path on the first throw and then rethrows to the caller (`SyncQueue::handleException()`, `vendor/laravel/framework/src/Illuminate/Queue/SyncQueue.php:315-322`).

5.5. **How Laravel decides to encrypt a queued notification, and who decrypts it.** `SendQueuedNotifications` declares `public $shouldBeEncrypted = false;` (`SendQueuedNotifications.php:73`) and its constructor sets it from the **notification**: `$this->shouldBeEncrypted = $notification instanceof ShouldBeEncrypted;` (`:111`). `Queue::createObjectPayload()` encrypts the serialized job when `jobShouldBeEncrypted($job)` is true (`vendor/laravel/framework/src/Illuminate/Queue/Queue.php:196-198`), and `jobShouldBeEncrypted()` returns true for `$job instanceof ShouldBeEncrypted` **before** it looks at the property (`:293-300`, the `instanceof` at `:295`); `commandName` stays plain `get_class($job)` (`:209`). Those are the only places in `vendor/laravel/framework/src` that read the flag for a notification. Three readers decrypt: `CallQueuedHandler::getCommand()` — **protected** — for `call()` and `failed()` (`CallQueuedHandler.php:113-123`, `failed()` calls it at `:416`), and `RetryCommand::getInstanceFromPayload()` for `queue:retry` (`vendor/laravel/framework/src/Illuminate/Queue/Console/RetryCommand.php:230-241`). A `JobFailed` listener gets the raw `Job` and would have to repeat `getCommand()` itself, including the `SerializesModels` restore that re-queries every model in the notification and throws if one has been deleted since. Today only `MemberInvitation` implements the interface (`app/Notifications/MemberInvitation.php:36`).

5.6. **Filament's password reset queues its token in plaintext.** `Filament\Auth\Notifications\ResetPassword` extends Laravel's `ResetPassword`, which holds the token in `public $token;` (`vendor/laravel/framework/src/Illuminate/Auth/Notifications/ResetPassword.php:16`), and adds `public string $url;` (`vendor/filament/filament/src/Auth/Notifications/ResetPassword.php:13`) — the reset link, token included — which `RequestPasswordReset` fills before it queues (`RequestPasswordReset.php:84-87`). Neither class implements `ShouldBeEncrypted`, so by fact 5.5 the job is a readable serialization in `jobs.payload` (production's queue is the database) and, after a final failure, in `failed_jobs.payload`, which `routes/console.php:16` keeps for 720 hours. Measured in the prototype: the payload of a queued Filament reset contains the 64-character token verbatim. Nothing reads a notification payload in plaintext: `cass:health` reads only `available_at` from `jobs` (`app/Console/Commands/HealthCommand.php:152-156`); `tests/Unit/HealthCommandTest.php:128` inserts a `'{}'` payload that nothing decodes; `tests/Feature/Security/QueuedMailPayloadTest.php` asserts payloads are *not* plaintext; the runbook's triage uses `queue:failed` and `queue:retry`, which show metadata and decrypt respectively (fact 5.5). The runbook names the encrypted classes twice — "Invitations and their tokens" (`docs/runbooks/deploy-production.md:365-374`) and "Secret rotation" (`:1176`, drain the queue before rotating `APP_KEY`).

5.7. **`NotificationFailed` fires on every attempt, not on the last.** `NotificationSender::sendToNotifiable()` catches whatever the channel throws, dispatches `NotificationFailed` and rethrows (`vendor/laravel/framework/src/Illuminate/Notifications/NotificationSender.php:169-184`) — on try one of three as much as on try three.

5.8. **Laravel builds the job through the container.** `NotificationSender::queueNotification()` dispatches `$this->manager->getContainer()->make(SendQueuedNotifications::class, ['notifiables' => $notifiable, 'notification' => $notification, 'channels' => [$channel]])` (`NotificationSender.php:284-289`), one job per notifiable per channel (`:229-232`). A class bound in its place receives those parameters: `Container::getClosure()` passes them to `resolve($concrete, $parameters)` (`vendor/laravel/framework/src/Illuminate/Container/Container.php:404-415`). `AppServiceProvider::register()` holds one binding today, `DnsResolver` (`app/Providers/AppServiceProvider.php:27-34`).

5.9. **Every notification already carries an id that both ends can see, and it is a UUID.** `queueNotification()` mints `(string) Str::uuid()` per notifiable (`NotificationSender.php:230`) and sets it on each channel's clone before dispatch if unset (`:233-237`) — so one `NewSubmissionNotice` sent to two members is two jobs with two ids, and the channels of one notifiable share one id. The worker's `sendNow()` keeps it (`if (! $notification->id)`, `:157-159`). `MailChannel::additionalMessageData()` puts it in the message data as `__laravel_notification_id` beside `__laravel_notification` (`vendor/laravel/framework/src/Illuminate/Notifications/Channels/MailChannel.php:153-163`, the id at `:156`), merged at `:68` and handed to `MessageSending` with the rest of `$data` (`Mailer.php:609`). `RecordOutgoingEmail::source()` already reads `__laravel_notification` out of that array (`RecordOutgoingEmail.php:114-119`).

5.10. **A notification is tried three times, and each try writes a new row today.** `docker/supervisord.conf:57` runs `queue:work --sleep=3 --tries=3 …`. Every try calls `toMail()` and the mailer afresh (`MailChannel.php:55`, `:66-70`), so every try is a new Symfony message with no `X-CASS-Log` and `sending()` inserts again. Measured in the prototype on `45d2d8e`: a notification the transport refused three times leaves **three** `queued` rows; one refused once and delivered on the second try leaves a `queued` row beside a `sent` one — a delivered email that the email log reports as stuck.

5.11. **`email_logs.ulid` can hold a notification's id, and nothing reads a ULID's timestamp.** `database/migrations/2026_09_11_001500_create_email_logs_table.php:19` is `$table->ulid('ulid')->unique()` — `char(26)` on MySQL — and no other column identifies a notification (`:13-38`); `mailable` + `to_email` is not a key, because two abstracts submitted a minute apart put two `NewSubmissionNotice` rows for one member in flight at once. The model mints the value only if none was set (`$log->ulid ??= (string) Str::ulid();`, `app/Models/EmailLog.php:42-44`). A UUID and a ULID are both 128 bits: `Ulid::fromRfc4122()` takes the 36-character UUID form (`vendor/symfony/uid/AbstractUid.php:79-86`, handled at `vendor/symfony/uid/Ulid.php:97-98`) and its string is the 26-character base-32 form `Str::ulid()` produces; in the prototype, 2,000 converted v4 UUIDs all passed `Str::isUlid()`. Nothing orders by or decodes an email-log ULID: `grep -rn "orderBy('ulid'" app` finds nothing, the admin table sorts on `created_at` (`app/Filament/Admin/Resources/EmailLogs/Tables/EmailLogsTable.php:19`), and the infolist only prints it as "Correlation id" (`app/Filament/Admin/Resources/EmailLogs/Schemas/EmailLogInfolist.php:26`).

5.12. **`QueuedVerifyEmail` had never rendered — fixed before Plan 7.** On `45d2d8e` it extends Laravel's `VerifyEmail` and overrides nothing (`app/Notifications/QueuedVerifyEmail.php:11-14`). `VerifyEmail::verificationUrl()` signs the route `verification.verify` unless `createUrlUsing()` was called (`vendor/laravel/framework/src/Illuminate/Auth/Notifications/VerifyEmail.php:77-91`, the name at `:84`); nothing calls it, and `php artisan route:list --name=verif` lists only the panels' `filament.{organizer,reviewer}.auth.email-verification.*`. So `(new QueuedVerifyEmail)->toMail($user)` threw `RouteNotFoundException: Route [verification.verify] not defined.`, in the worker, for every registration since Plan 1 (`app/Models/User.php:131-134`, `app/Actions/Organizations/RegisterOrganization.php:49`); `tests/Feature/Public/RegisterOrganizationTest.php:18` fakes notifications, so no test had rendered it. The hotfix that ships before Plan 7 overrides `verificationUrl()` with the organizer panel's `getVerifyEmailUrl()` and adds `tests/Feature/Mail/VerificationMailTest.php`; it is on `main` when this task starts. It is recorded here because it is the one notification failure this task's design can never see (decision 5).

5.13. **Livewire's cleanup lives in a component trait, not in the controller.** `FileUploadController::handle()` validates, stores and returns paths, and cleans nothing (`vendor/livewire/livewire/src/Features/SupportFileUploads/FileUploadController.php:27-54`). The sweep is `WithFileUploads::_finishUpload()` calling `cleanupOldUploads()` when `cleanup` is on (`WithFileUploads.php:28-32`): it returns early on S3 (`:131`), lists `FileUploadConfiguration::path()` on `FileUploadConfiguration::storage()` (`:133-135`), and deletes every file whose `lastModified()` is older than `now()->subDay()` (`:140-143`), after an `exists()` check whose comment says that on busy sites another request may already have deleted part of the listing (`:136-138`). `cleanupOldUploads()` is `protected` on a trait only Livewire components use.

5.14. **Where Livewire writes.** `config/livewire.php:22` `'disk' => null`, `:32` `'directory' => null`, `:48` `'cleanup' => true`. `FileUploadConfiguration::disk()` is that disk or `filesystems.default` — and `tmp-for-tests` whenever `runningUnitTests()` (`vendor/livewire/livewire/src/Features/SupportFileUploads/FileUploadConfiguration.php:30-37`); `storage()` fakes that test disk only the first time in a process (`:10-28`); `directory()` defaults to `livewire-tmp` (`:63-66`); `path()` joins them (`:81-88`). `filesystems.default` is `local` (`config/filesystems.php:18`), rooted at `storage/app/private` (`:35-37`). Every upload is two files: the upload and a `.json` sidecar written beside it (`FileUploadConfiguration.php:128-143`).

5.15. **Every upload leaves its temporary copy behind, submitted or not.** `StoreSubmissionFile` copies the bytes onto the private disk with `writeStream()` (`app/Actions/Submissions/StoreSubmissionFile.php:109`); `SubmissionForm::storeUploads()` then only unsets the entry (`app/Livewire/Public/SubmissionForm.php:755-786`), as `removeUpload()` does (`:250-255`). Nothing in `app/` deletes a temporary upload.

5.16. **The scheduler and its tests.** `routes/console.php:16-54`: `queue:prune-failed` and `model:prune` daily, `cass:reviewer-reminders` hourly with `withoutOverlapping(55)`, the heartbeat closure every five minutes. A schedule entry is tested by finding it in `app(Schedule::class)->events()` (`tests/Unit/HealthCommandTest.php:110-111`). `SendReviewerRemindersCommand` is the shape for a scheduled command: it decides nothing and delegates to an action (`app/Console/Commands/SendReviewerRemindersCommand.php:13-28`).

5.17. **`cass:health`'s private-disk probe never touches the temporary directory.** It writes `health-check-{random}.tmp` at the root of the `local` disk, reads it back and deletes it, all in one call (`app/Console/Commands/HealthCommand.php:221-243`). It answers "can the volume be written", not "how full is it", and it does not look in `livewire-tmp`.

5.18. **`badge.svg` is referenced by nothing, and is a licensed file.** `public/images/icons/` holds `badge.svg` (4,098 bytes), `podium.svg`, `presentation.svg` and `team.svg`; `resources/views/public/landing.blade.php:31`, `:36` and `:41` use the other three. `git grep -n "badge.svg"` finds it only under `docs/`, and searching `resources/`, `app/`, `config/`, `lang/` and `routes/` for the relative path of each file under `public/images/` finds every one except `images/icons/badge.svg` (`tests/Feature/BrandAssetsTest.php:24-32` lists `public/brand` files only). `public/images/README.md:1`: "Illustrations and icons are licensed through Envato Elements for the CASS project and may not be reused elsewhere." The file has been in the tree since `bc27aa6` (Plan 1).

5.19. **The test environment and the deploy order.** `phpunit.xml:36-37` pins `MAIL_MAILER=array` and `QUEUE_CONNECTION=sync`; `Mail::fake()` and `Notification::fake()` short-circuit before `MessageSending` (`tests/Feature/Mail/EmailLogPipelineTest.php:20-24`); a test may switch to the `database` queue and read `jobs` (`QueuedMailPayloadTest.php:30`), whose connection has `after_commit` on (`config/queue.php:56`). Larastan is level 6 over `app`, `config`, `database` and `routes` only (`phpstan.neon`). In production a release's migrations run by hand once the new container is healthy (`docs/runbooks/deploy-production.md`, "Every release" step 3; `CLAUDE.md`: migrations are never run at container boot), so new code always runs for a while against the old schema.

**Decisions this task makes.**

1. **Every queued notification travels in one container-bound subclass of `SendQueuedNotifications` whose `failed()` marks the row — not a `JobFailed` listener, not a `failed()` on each notification, and not a move onto `TemplatedMail`.** The backlog asks for a `JobFailed` listener because it believed there was no hook; there is one (fact 5.4), on the job, reached once after the last try, with the command already decrypted and unserialized. A `JobFailed` listener would re-implement the protected `getCommand()` — decrypt, unserialize, restore every model — to read the same id (fact 5.5), and after decision 6 it would have to do so for every notification, not one. A `NotificationFailed` listener would fire on try one of three (fact 5.7), mark a row failed that try two then delivers, and `sent()`'s `queued` guard would leave it reading `failed`. A `failed()` on each notification works — the job forwards to it — but reaches only the five classes in `app/Notifications`: Filament's `ResetPassword` and `VerifyEmail` (fact 5.3) would each need a subclass and a binding of their own, and a password reset is the one email a support conversation is most likely to be about. Moving onto `TemplatedMail` is wrong twice: `NewSubmissionNotice` and `MemberInvitation` are deliberately not templates (their own docblocks say why), and Filament's two cannot move. The subclass is one class and one binding (fact 5.8), it covers all seven, and it covers the next notification anybody writes without anyone remembering to.

2. **A notification's row is keyed by the notification's own id, written as the row's `ulid` — no new column, so no migration and no deploy window.** The id is the only value present at both ends — in `MessageSending`'s data, where the row is written, and on the notification the final `failed()` receives — and it is per recipient, which is what "marks the right row and only that row" needs (fact 5.9). It is a UUID, the `ulid` column holds 128 bits as 26 characters, and `Ulid::fromRfc4122()` converts one to the other losslessly (fact 5.11); `EmailLog::ulidForNotification()` is that one line, and returns null for anything that is not a UUID, which then gets an ordinary random row exactly as today. The obvious alternative, a nullable `notification_id` column, was prototyped first and dropped: production runs new code against the old schema until somebody runs `migrate` (fact 5.19), and an insert naming a missing column throws inside `MessageSending`, so **every** notification — password resets included — would fail until then. Making that degrade means a `Schema::hasColumn()` probe per message (cached per worker, it goes stale until the worker restarts) or catching a `QueryException` (which hides every other insert failure); keying by the existing column means there is nothing to degrade. The cost is that a notification row's ULID no longer encodes when it was written — its leading bits are random — and nothing reads them (fact 5.11). The `X-CASS-Log` header on the delivered message then carries the notification's own random id, which says nothing about volume.

3. **Any later try finds the row the first try wrote and leaves it as it is.** Today every try inserts (fact 5.10), so a transient SMTP refusal followed by a delivery leaves a `queued` row nobody will ever flip — the exact false alarm the triage section tells support to chase. Because the key is unique, reuse is not optional: a second insert under the same ULID would throw inside `MessageSending` and fail the very retry that was meant to work, and the test for `queue:retry` pins that. So `sending()` stamps the existing row's ULID as `X-CASS-Log` and returns, and `sent()` flips it only from `queued`: a message delivered on its second try reads `sent`; a notification that failed for good and is then delivered by `queue:retry` keeps reading `failed`, with the first try's error — which is how a retried templated email has always read (runbook, "Sending decision emails"). One email, one row, one rule for both kinds.

4. **`failed()` is guarded three ways: on `queued`, on the `mail` channel, and on an id that is not a UUID.** On `queued` for the reason `TemplatedMail::failed()` gives (fact 5.2): a job that throws after the transport accepted the message must not report a delivered email as failed. On the `mail` channel because Laravel queues one job per channel with the *same* id (fact 5.9): a failed `database`-channel job must not mark the mail row. On a non-UUID id because `ulidForNotification()` returns null for it and the method then does nothing — there is no `where('…', null)` that could match every other row.

5. **A notification that throws before it renders still gets no row.** There is no message, so there is no subject to write and the recipient would have to be re-derived per notifiable type. It is in `failed_jobs` with its exception, which is where the runbook already sends support, and the triage paragraph now says this is the one failure with no row. `QueuedVerifyEmail` was exactly this case for its whole life (fact 5.12) — the hotfix, not a synthetic row, was the right answer to it.

6. **The same job encrypts every queued notification — it implements `ShouldBeEncrypted` — in a commit of its own.** Laravel asks only the *notification* whether to encrypt (fact 5.5), and Filament's `ResetPassword` does not say yes, so the reset token sits readable in `jobs.payload` and, after a failure, in `failed_jobs.payload` for 720 hours (fact 5.6). The token expires after an hour, but CLAUDE.md's rule is that a token is not stored in the clear, and a stored queue payload is storage. `Queue::jobShouldBeEncrypted()` checks `$job instanceof ShouldBeEncrypted` before it reads the property the parent constructor sets from the notification (fact 5.5), so declaring the interface on the subclass encrypts every notification's job, whatever the notification declares; overriding the constructor to force the property would do the same in more lines and would depend on running after the parent's assignment. Subclassing Filament's `ResetPassword` and binding it instead would fix one class of the seven. Nothing reads a notification payload in plaintext (fact 5.6), the worker and `queue:retry` both decrypt (fact 5.5), and the full suite — which pushes every notification through the `sync` queue and therefore through `createPayload()` — passes with it on. `MemberInvitation` keeps its own declaration, so its token never depends on a binding. The consequence is operational and already in the runbook for templated mail: rotating `APP_KEY` now strands queued notifications too, so the "Secret rotation" drain covers them, and the "Invitations and their tokens" bullet names the job. Its own commit, because "every queued notification is now ciphertext" is a security change that should be revertible — and reviewable — apart from the logging.

7. **The sweep asks Livewire's `FileUploadConfiguration` where to look rather than reading `config/livewire.php`.** `cleanupOldUploads()` is protected on a component trait (fact 5.13), so the sweep has to be its own code — but it reads the same `storage()` and `path()` the trait reads (fact 5.14). Re-deriving them from config would duplicate the `?:` fallbacks to `filesystems.default` and `livewire-tmp`, and under test would point at `local` while Livewire writes to `tmp-for-tests`. One class answers "where are the temporary uploads" for both.

8. **The same 24-hour age as Livewire, hourly, no `withoutOverlapping()`, a console command over an action, and nothing outside the directory.** Twenty-four hours is Livewire's own limit (fact 5.13), so an author halfway through a form keeps an attachment exactly as long as today — today's sweep is global, triggered by *anyone's* next upload. Hourly bounds a temporary copy's life at 25 hours for the price of one directory listing; daily would allow 48. That matters more than the backlog says, because **every** upload leaves its copy, not only abandoned ones (fact 5.15): during a quiet week after a deadline the volume holds every abstract of the rush twice. No `withoutOverlapping()`: two runs deleting one file is a `false` from the second, not an error, and a mutex row in `cache_locks` is a moving part with a failure mode of its own. A command over an action — the `SendReviewerRemindersCommand` shape (fact 5.16) — so the owner can run `cass:sweep-uploads` by hand when `cass:health` reports the disk full. A `FilesystemException` between the listing and the stat is skipped, for the race Livewire's own comment describes; no test stages that race, because it takes two processes. A year-old file **outside** `livewire-tmp` on the same disk is a test case, because in production that disk is where every stored abstract lives.

9. **`cass:health` is unchanged.** Its probe file sits at the disk root and is deleted inside the same call (fact 5.17), so the sweep and the probe can never see each other. The probe answers "can the volume be written"; the sweep removes the largest avoidable reason for "no". A free-space threshold would be a number nobody has chosen, and choosing it is the owner's.

10. **`badge.svg` is deleted, in a commit about assets, with a test that keeps `public/images` honest.** Giving it a home would mean a fourth feature card on the landing page — new copy and a layout change, which is a product decision and not a backlog fix; the file is one `git checkout bc27aa6 -- public/images/icons/badge.svg` away if the owner wants that card. Until then it is a licensed file (fact 5.18) served to anyone who asks for it, for nothing. `PublicImagesTest` fails today naming exactly `images/icons/badge.svg`, which is the failing-first test the deletion needs, and it fails again the next time an image outlives its last reference. A plain substring search, because every reference in this application is a literal `asset('images/…')`.

11. **No new user-facing string, so nothing is appended to `$plan7Sources`.** The only new text is a console line and exception messages, which every existing `cass:` command writes in English (`SendReviewerRemindersCommand.php:70-76`).

**Files:**
- Create: `app/Notifications/SendQueuedNotificationsWithLog.php` (Part A; Part B adds `implements ShouldBeEncrypted`)
- Modify: `app/Models/EmailLog.php` (a static `ulidForNotification()`)
- Modify: `app/Providers/AppServiceProvider.php` (one binding in `register()`)
- Modify: `app/Listeners/RecordOutgoingEmail.php` (`sending()` and the class docblock)
- Create: `app/Actions/Submissions/SweepTemporaryUploads.php`
- Create: `app/Console/Commands/SweepTemporaryUploadsCommand.php`
- Modify: `routes/console.php` (one schedule entry)
- Modify: `docs/runbooks/deploy-production.md` ("Author uploads and private storage", "Email triage", "Rollback", "Invitations and their tokens", the `private disk` row of "Health", "Secret rotation")
- Delete: `public/images/icons/badge.svg`
- Test: `tests/Feature/Mail/FailedNotificationLogTest.php`, `tests/Feature/Console/SweepTemporaryUploadsTest.php`, `tests/Feature/PublicImagesTest.php` (new); `tests/Feature/Security/QueuedMailPayloadTest.php` (one dataset row, one new case)
- Not here: `app/Notifications/QueuedVerifyEmail.php` and `tests/Feature/Mail/VerificationMailTest.php` — the pre-Plan-7 hotfix (fact 5.12).

**Part A — failed notifications (Steps 1-8, one commit).**

- [ ] **Step 1: Write the failing tests for failed notifications**

Create `tests/Feature/Mail/FailedNotificationLogTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\EmailLogStatus;
use App\Enums\OrganizationRole;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\MemberInvitation;
use App\Notifications\NewSubmissionNotice;
use App\Notifications\OrganizationRegistered;
use App\Notifications\SendQueuedNotificationsWithLog;
use App\Support\Tokens\InvitationToken;
use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

// No Mail::fake() and no Notification::fake() here, for the reason
// EmailLogPipelineTest gives: both fakes short-circuit the mailer before
// MessageSending fires, and MessageSending is what writes a notification's row.
// Instead the default mailer is swapped for a real Symfony transport that
// refuses whichever recipients the test names - the same exception an SMTP
// server that is down or rejecting the login throws.

/**
 * @param  Closure(string): bool  $refuses  given each recipient address, true to throw
 */
function refuseMailTo(Closure $refuses): void
{
    Mail::extend('refusing', fn () => new class($refuses) extends AbstractTransport
    {
        public function __construct(private Closure $refuses)
        {
            parent::__construct();
        }

        protected function doSend(SentMessage $message): void
        {
            foreach ($message->getEnvelope()->getRecipients() as $recipient) {
                if (($this->refuses)($recipient->getAddress())) {
                    throw new TransportException('Connection could not be established with host "smtp.example.org:587"');
                }
            }
        }

        public function __toString(): string
        {
            return 'refusing://';
        }
    });

    config()->set('mail.mailers.refusing', ['transport' => 'refusing']);
    config()->set('mail.default', 'refusing');
}

/**
 * What supervisord runs in production (docker/supervisord.conf: --tries=3),
 * until the queue is empty. --memory is raised because the worker stops itself
 * when the PROCESS passes the limit, and this process is the whole test suite;
 * --timeout=0 because a worker that times a job out kills its own process.
 */
function workTheQueue(): void
{
    expect(Artisan::call('queue:work', [
        '--stop-when-empty' => true,
        '--tries' => 3,
        '--sleep' => 0,
        '--timeout' => 0,
        '--memory' => 2048,
    ]))->toBe(0);
}

it('marks a notification row failed when its queued job fails', function () {
    refuseMailTo(fn (string $address): bool => true);

    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->published()->create();
    $submission = Submission::factory()->for($conference)->submitted()->create();
    $member = User::factory()->create(['email' => 'member@example.org']);
    $organization->addMember($member, OrganizationRole::Member);

    // QUEUE_CONNECTION=sync (phpunit.xml): SyncQueue::handleException() calls
    // $job->fail() - the same path the worker takes on a final attempt - and
    // then rethrows to the caller.
    expect(fn () => $member->notify(new NewSubmissionNotice($submission)))
        ->toThrow(TransportException::class);

    $log = EmailLog::query()->sole();

    expect($log->mailable)->toBe(NewSubmissionNotice::class)
        ->and($log->status)->toBe(EmailLogStatus::Failed)
        ->and($log->error)->toContain('smtp.example.org')
        ->and($log->sent_at)->toBeNull();
});

it('keeps one row through three attempts and marks it failed after the last, through an encrypted payload', function () {
    $attempts = 0;
    refuseMailTo(function (string $address) use (&$attempts): bool {
        $attempts++;

        return true;
    });
    config()->set('queue.default', 'database');

    $organization = Organization::factory()->approved()->create();

    Notification::route('mail', 'invited@example.org')->notify(new MemberInvitation(
        $organization,
        OrganizationRole::Admin,
        InvitationToken::generate(),
        'Dr Owner',
    ));

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

    // The container binding is what puts this class on the job, and Plan 6's
    // encryption still holds: SendQueuedNotifications::__construct() copies
    // ShouldBeEncrypted off the notification and the subclass inherits it.
    expect($payload['data']['commandName'])->toBe(SendQueuedNotificationsWithLog::class)
        ->and($payload['data']['command'])->not->toStartWith('O:');

    workTheQueue();

    // One row, not three. Every attempt renders a new Symfony message and
    // fires MessageSending again, and until the row was keyed by the
    // notification's id each attempt wrote a row of its own.
    $log = EmailLog::query()->sole();

    expect($attempts)->toBe(3)
        ->and($log->to_email)->toBe('invited@example.org')
        ->and($log->status)->toBe(EmailLogStatus::Failed)
        ->and($log->error)->toContain('smtp.example.org')
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('reports a notification that succeeds on a retry as sent, with no row left behind at queued', function () {
    $attempts = 0;
    refuseMailTo(function (string $address) use (&$attempts): bool {
        return ++$attempts === 1;
    });
    config()->set('queue.default', 'database');

    $admin = User::factory()->platformAdmin()->create(['email' => 'admin@example.org']);
    $owner = User::factory()->create();
    $organization = Organization::factory()->create();

    $admin->notify(new OrganizationRegistered($organization, $owner));

    workTheQueue();

    $log = EmailLog::query()->sole();

    expect($attempts)->toBe(2)
        ->and($log->status)->toBe(EmailLogStatus::Sent)
        ->and($log->sent_at)->not->toBeNull()
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('reuses the row when a failed notification is retried from failed_jobs', function () {
    $refusing = true;
    refuseMailTo(function (string $address) use (&$refusing): bool {
        return $refusing;
    });
    config()->set('queue.default', 'database');

    $admin = User::factory()->platformAdmin()->create(['email' => 'admin@example.org']);
    $admin->notify(new OrganizationRegistered(Organization::factory()->create(), User::factory()->create()));

    workTheQueue();

    expect(EmailLog::query()->sole()->status)->toBe(EmailLogStatus::Failed);

    // The transport is fixed and support runs the runbook's
    // `queue:retry all`. The retried job carries the same notification id,
    // so it must find the failed row rather than insert a second one with
    // the same ULID - which the unique key would turn into an exception
    // inside MessageSending, failing the very retry that was meant to work.
    $refusing = false;
    expect(Artisan::call('queue:retry', ['id' => ['all']]))->toBe(0);

    workTheQueue();

    // Still one row, and still `failed` with the first try's error: sent()
    // flips only a `queued` row, which is how a retried templated email has
    // always read too (runbook, "Sending decision emails").
    expect(EmailLog::query()->sole())
        ->status->toBe(EmailLogStatus::Failed)
        ->error->toContain('smtp.example.org')
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('marks only the row of the recipient whose message failed', function () {
    refuseMailTo(fn (string $address): bool => $address === 'broken@example.org');
    config()->set('queue.default', 'database');

    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->published()->create();
    $submission = Submission::factory()->for($conference)->submitted()->create();
    $broken = User::factory()->create(['email' => 'broken@example.org']);
    $fine = User::factory()->create(['email' => 'fine@example.org']);
    $organization->addMember($broken, OrganizationRole::Owner);
    $organization->addMember($fine, OrganizationRole::Member);

    // ONE notification object to two members - SubmitAbstract's shape.
    // NotificationSender::queueNotification() gives each notifiable its own
    // id, and that id is the whole correlation.
    Notification::send(collect([$broken, $fine]), new NewSubmissionNotice($submission));

    workTheQueue();

    $rows = EmailLog::query()->orderBy('to_email')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->to_email)->toBe('broken@example.org')
        ->and($rows[0]->status)->toBe(EmailLogStatus::Failed)
        ->and($rows[1]->to_email)->toBe('fine@example.org')
        ->and($rows[1]->status)->toBe(EmailLogStatus::Sent)
        ->and($rows[1]->error)->toBeNull();
});

it('marks a failed filament password reset too, a notification this application does not own', function () {
    refuseMailTo(fn (string $address): bool => true);

    $user = User::factory()->create(['email' => 'forgot@example.org']);

    // Exactly what RequestPasswordReset does (vendor/filament/filament/src/
    // Auth/Pages/PasswordReset/RequestPasswordReset.php): resolve the class
    // from the container, set the url, notify.
    $notification = app(ResetPassword::class, ['token' => Str::random(64)]);
    $notification->url = 'https://cass.test/org/password-reset/reset?token=x';

    expect(fn () => $user->notify($notification))->toThrow(TransportException::class);

    expect(EmailLog::query()->sole())
        ->mailable->toBe(ResetPassword::class)
        ->status->toBe(EmailLogStatus::Failed);
});

it('leaves the mail row alone when another channel of the same notification fails', function () {
    $user = User::factory()->create();
    $notification = new NewSubmissionNotice(Submission::factory()->submitted()->create());
    $notification->id = (string) Str::uuid();

    $log = EmailLog::factory()->create(['ulid' => EmailLog::ulidForNotification($notification->id)]);

    // NotificationSender::queueNotification() queues one job per channel, all
    // with the same id. Only a failed MAIL job may touch the mail row.
    (new SendQueuedNotificationsWithLog($user, $notification, ['database']))
        ->failed(new RuntimeException('the database channel failed'));

    expect($log->refresh()->status)->toBe(EmailLogStatus::Queued);
});
```

- [ ] **Step 2: Run them and watch all seven fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact --filter='FailedNotificationLogTest' > /tmp/t-task-5.log 2>&1; echo "rc=$?"; tail -60 /tmp/t-task-5.log
```

Expected: `rc` non-zero, `7 failed`, one for each reason:

- `marks a notification row failed when its queued job fails` — `Failed asserting that two variables reference the same object`: the row is still `Queued`.
- `keeps one row through three attempts…` — `Failed asserting that two strings are identical`: `commandName` is `Illuminate\Notifications\SendQueuedNotifications`.
- `reports a notification that succeeds on a retry…` — `MultipleRecordsFoundException`: the first try's row is still `queued` beside the `sent` one (fact 5.10).
- `reuses the row when a failed notification is retried from failed_jobs` — `MultipleRecordsFoundException` at its first `sole()`: three rows for three tries.
- `marks only the row of the recipient whose message failed` — `Failed asserting that actual size 4 matches expected size 2`: three rows for the refused member, one for the other.
- `marks a failed filament password reset too…` — the row is still `Queued`.
- `leaves the mail row alone…` — `BadMethodCallException`: `EmailLog::ulidForNotification()` does not exist yet.

- [ ] **Step 3: The key — a notification's id as a row ULID**

In `app/Models/EmailLog.php`, add after `use Illuminate\Support\Str;`:

```php
use Symfony\Component\Uid\Ulid;
```

and directly after the `getRouteKeyName()` method, which ends

```php
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
```

add:

```php
    /**
     * The `ulid` of the row that records one notification: the notification's
     * own id - a UUID Laravel mints per recipient before it queues anything
     * (NotificationSender::queueNotification()) - with the same 128 bits
     * written the way this column writes them. RecordOutgoingEmail reads that
     * id from the message data and SendQueuedNotificationsWithLog::failed()
     * from the job, so both ends find the row without a column of their own,
     * and a notification's second and third tries find the row its first one
     * wrote.
     *
     * Such a ULID's leading 48 bits are random rather than a timestamp.
     * Nothing reads them: the admin panel sorts on created_at.
     *
     * Null for anything that is not a UUID - a Mailable, a raw send, or a
     * notification that chose an id of its own - which then gets an ordinary
     * random row exactly as before.
     */
    public static function ulidForNotification(mixed $notificationId): ?string
    {
        return is_string($notificationId) && Str::isUuid($notificationId)
            ? (string) Ulid::fromRfc4122($notificationId)
            : null;
    }
```

- [ ] **Step 4: The job, and the binding that puts every queued notification in it**

Create `app/Notifications/SendQueuedNotificationsWithLog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\EmailLogStatus;
use App\Models\EmailLog;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Str;
use Throwable;

/**
 * The job every queued notification travels in - this application's own and
 * Filament's password-reset and verification mail alike - because
 * AppServiceProvider binds it in place of Laravel's, and
 * NotificationSender::queueNotification() resolves the job from the container.
 *
 * It adds one thing: on the FINAL failure it marks the notification's
 * `email_logs` row failed, the way TemplatedMail::failed() marks a templated
 * one. The worker calls failed() once, after the last try, through
 * CallQueuedHandler::failed(), which has already decrypted and unserialized the
 * job. An intermediate attempt never reaches this method; the worker releases
 * the job instead, and RecordOutgoingEmail reuses the row.
 *
 * The row is found by EmailLog::ulidForNotification(): the notification's own
 * id, one per recipient, set before the job is queued and serialized with it.
 * Guarded on `queued` for the reason TemplatedMail::failed() gives, and on the
 * mail channel because Laravel queues one job per channel with the SAME id - a
 * failed database-channel job must not mark a mail that was delivered or is
 * still being retried.
 *
 * Do not rename or move this class without draining the queue first: a job
 * already in `jobs` or `failed_jobs` names it in its payload.
 */
class SendQueuedNotificationsWithLog extends SendQueuedNotifications
{
    /**
     * @param  Throwable|null  $e
     */
    public function failed($e): void
    {
        parent::failed($e);

        $ulid = EmailLog::ulidForNotification($this->notification->id);

        if ($ulid === null || ! in_array('mail', (array) $this->channels, true)) {
            return;
        }

        EmailLog::query()
            ->where('ulid', $ulid)
            ->where('status', EmailLogStatus::Queued->value)
            ->update([
                'status' => EmailLogStatus::Failed->value,
                'error' => Str::limit((string) $e?->getMessage(), 2000, ''),
                'updated_at' => now(),
            ]);
    }
}
```

`$e` stays untyped, as the parent declares it: a `Throwable` type on the parameter would narrow the parent's signature, which PHP refuses. `Job::fail()` may pass `null` (`Job.php:182`), hence `?->`.

In `app/Providers/AppServiceProvider.php`, add two imports in alphabetical position — after `use App\Listeners\RecordOutgoingEmail;`:

```php
use App\Notifications\SendQueuedNotificationsWithLog;
```

and after `use Illuminate\Mail\Markdown;`:

```php
use Illuminate\Notifications\SendQueuedNotifications;
```

Then replace the body of `register()`:

```php
        // The first container binding in this application, and the reason is
        // narrow: DnsResolver wraps dns_get_record(), a global function, which
        // no test can substitute any other way. Everything else in app/ is
        // resolved by autowiring and faked with $this->mock().
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
```

with:

```php
        // The first container binding in this application, and the reason is
        // narrow: DnsResolver wraps dns_get_record(), a global function, which
        // no test can substitute any other way. Apart from the notification
        // job below, everything else in app/ is resolved by autowiring and
        // faked with $this->mock().
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);

        // The second, and as narrow. NotificationSender::queueNotification()
        // builds every queued notification's job through the container
        // (vendor/.../Illuminate/Notifications/NotificationSender.php:285), so
        // this one line gives every queued notification - including
        // Filament's password-reset and verification mail, which this
        // application cannot edit - a failed() that marks its email_logs row.
        $this->app->bind(SendQueuedNotifications::class, SendQueuedNotificationsWithLog::class);
```

- [ ] **Step 5: The listener keys a notification's row by its id, and every later try reuses it**

In `app/Listeners/RecordOutgoingEmail.php`, replace the last paragraph of the class docblock:

```php
 * Failures: a templated send is marked failed by TemplatedMail::failed(). A
 * *notification* has no such hook, so a transport failure leaves its row at
 * `queued`. That is a known, documented gap (runbook, "Email triage"), not an
 * oversight: a row stuck at `queued` with no `sent_at` is exactly the signal
 * the admin panel's status filter exists to surface.
 */
```

with:

```php
 * Failures: a templated send is marked failed by TemplatedMail::failed(), and
 * a queued notification by SendQueuedNotificationsWithLog::failed(). Both find
 * a row they did not write by its `ulid`, which for a notification is the
 * notification's own id (EmailLog::ulidForNotification()). The worker tries a
 * job three times (docker/supervisord.conf) and every try renders a new
 * message and fires MessageSending again, so a notification whose row already
 * exists reuses it: one email, one row, whatever happened to it on the way.
 */
```

In `sending()`, replace:

```php
        // SendTemplatedEmail already wrote the row and knows far more about it
        // than this listener could reconstruct.
        if ($headers->has(self::LOG_HEADER)) {
            return;
        }

        $log = new EmailLog;
        $log->forceFill([
            ...$this->context($event->message),
```

with:

```php
        // SendTemplatedEmail already wrote the row and knows far more about it
        // than this listener could reconstruct.
        if ($headers->has(self::LOG_HEADER)) {
            return;
        }

        $ulid = EmailLog::ulidForNotification($event->data['__laravel_notification_id'] ?? null);

        // A second or third try of a notification, or a `queue:retry` of one
        // that failed for good: the row is the first try's, and is left as it
        // is. sent() flips it only from `queued` - so a retried failure keeps
        // reading `failed`, exactly as a retried templated email does.
        if ($ulid !== null && EmailLog::query()->where('ulid', $ulid)->exists()) {
            $headers->addTextHeader(self::LOG_HEADER, $ulid);

            return;
        }

        $log = new EmailLog;
        $log->forceFill([
            // Null for anything but a notification; the model's creating hook
            // then mints a random ULID, as it always has.
            'ulid' => $ulid,
            ...$this->context($event->message),
```

- [ ] **Step 6: The runbook — the triage table, and what a rollback strands**

`docs/runbooks/deploy-production.md`, **Email triage**: replace the `failed` row of the status table and the paragraph under the table —

```markdown
| `failed` | The queued job threw, and `error` holds the exception message. Only templated mail (`App\Mail\TemplatedMail`) can reach this state: it has a `failed()` hook. |
| `queued` | The row was written and the job has not reported back. A few seconds is normal. Hours is not. |

A row **stuck at `queued`** is either a stopped queue worker or a *notification*
that failed: `Illuminate\Notifications\Notification` has no per-message failure
hook, so a transport error on one leaves its row where it was. Check both:
```

— with:

```markdown
| `failed` | The queued job threw on its last try (the worker makes three), and `error` holds the exception message. Templated mail is marked by `TemplatedMail::failed()`; every queued notification — Filament's password-reset and verification mail included — by `App\Notifications\SendQueuedNotificationsWithLog::failed()`. |
| `queued` | The row was written and the job has not reported back. A few seconds is normal. Hours is not. |

One templated email or notification is one row, however many tries it takes.
A notification's row is keyed by the notification itself, so its second and
third tries — and a later `queue:retry` — find the row the first try wrote: a
message that went through on its second try reads `sent`, not `queued` beside
a `sent`. A row that has gone `failed` keeps reading `failed` after a
`queue:retry` delivers it, exactly as a retried templated email does (see
"Sending decision emails"); judge a retry by the worker and the mailbox.

A row **stuck at `queued`** is a stopped queue worker, with three exceptions.
`App\Mail\ContactMessage`, the public contact form, has no `failed()` hook:
each of its tries writes its own `queued` row, a final failure marks none of
them, and the job is in `queue:failed`. A notification first tried before the
Plan 7 release keeps that try's `queued` row whatever its later tries do,
because rows were not keyed by the notification then, and a notification that
failed for good before the release stays `queued` for good; judge those by
`queue:failed` (which keeps a failure for 720 hours) and the mailbox. And a
notification that throws before it is rendered has **no row at all** — a bug
in the code, not an outage — and is only in `queue:failed`. Check both:
```

The three commands under it and everything after them stay as they are. **Every release** needs no Plan 7 paragraph for this task: it adds no migration.

**Rollback** — from this commit on, every queued notification names `App\Notifications\SendQueuedNotificationsWithLog` in its payload, which an older image cannot unserialize (`vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php:135-136` throws `Job is incomplete class`, and `failed()` returns early for it at `:426-428`). The section today is the single paragraph that starts "Coolify -> Deployments -> redeploy the previous successful build." Append, after that paragraph:

```markdown
Rolling back to an image older than Plan 7: every queued notification travels
in `App\Notifications\SendQueuedNotificationsWithLog`, which an older image
does not have. Any notification still in `jobs` then fails three times with
"Job is incomplete class" and lands in `failed_jobs`, and its email-log row
stays `queued`; a job that failed under Plan 7 and sits in `failed_jobs`
cannot be retried there either. If you can, wait until
`queue:monitor database:default` prints `[0] OK` (the drain under "Secret
rotation") before rolling back; otherwise `queue:retry` those jobs by id once
you have rolled forward again.
```

This belongs to Part A, not Part B: the class name enters the payload with the binding, and Part B's encryption is separately revertible.

- [ ] **Step 7: Run the tests**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact --filter='FailedNotificationLogTest|EmailLogPipelineTest|TemplatedMailTest|QueuedMailPayloadTest|EmailLogsTest|VerificationMailTest' > /tmp/t-task-5.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-5.log
```

Expected: `rc=0`. The other files are in the run because they pin what must not change: one row per templated send, the listener's context headers, the encrypted invitation payload, the admin email log (which opens a row by its ULID) and the hotfix's verification mail.

- [ ] **Step 8: Pint, Larastan, the full suite, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint --test > /tmp/pint-task-5.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan-task-5.log 2>&1; echo "stan rc=$?" && \
php artisan test > /tmp/all-task-5.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-5.log
```

Expected: all three `rc=0`; Task 4's final passed count + 7. If Pint reports a file, run `./vendor/bin/pint` on it and re-run this step. Then commit, with the session's Co-Authored-By trailer as the last paragraph of the message:

```bash
cd /c/Users/ahmed/Documents/CASS && git add app/Models/EmailLog.php app/Notifications/SendQueuedNotificationsWithLog.php \
  app/Providers/AppServiceProvider.php app/Listeners/RecordOutgoingEmail.php \
  docs/runbooks/deploy-production.md tests/Feature/Mail/FailedNotificationLogTest.php && \
git commit -q -m "fix(mail): mark a failed notification failed in email_logs" -m "Every queued notification now travels in SendQueuedNotificationsWithLog,
bound in place of Laravel's job, whose failed() marks the row. The row is
keyed by the notification's own id, re-encoded as its ULID - the one value
that both MessageSending and the final failure can see - so a retry finds
the row its first try wrote and there is no migration. It covers Filament's
password-reset and verification mail too, which no hook on the application's
own notifications could reach." -m "<the session's Co-Authored-By trailer>" && git log --oneline -1
```

**The registration verification email — nothing to do here.** Fact 5.12's bug, and the `QueuedVerifyEmail::verificationUrl()` override with `tests/Feature/Mail/VerificationMailTest.php` that fixes it, shipped as a hotfix before Plan 7 and are on `main` when this task starts. Step 7 runs that test so a regression would show here; nothing in this task edits either file.

**Part B — every queued notification is encrypted (Steps 9-13, one commit).**

- [ ] **Step 9: Write the failing tests**

In `tests/Feature/Security/QueuedMailPayloadTest.php`, replace the import block:

```php
use App\Actions\Mail\SendTemplatedEmail;
use App\Actions\Organizations\InviteMember;
use App\Enums\EmailTemplateKey;
use App\Enums\OrganizationRole;
use App\Mail\ContactMessage;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\MemberInvitation;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\DB;
```

with:

```php
use App\Actions\Mail\SendTemplatedEmail;
use App\Actions\Organizations\InviteMember;
use App\Enums\EmailLogStatus;
use App\Enums\EmailTemplateKey;
use App\Enums\OrganizationRole;
use App\Mail\ContactMessage;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\MemberInvitation;
use App\Notifications\SendQueuedNotificationsWithLog;
use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
```

add the job to the first case's dataset — replace:

```php
})->with([TemplatedMail::class, ContactMessage::class, MemberInvitation::class]);
```

with:

```php
})->with([TemplatedMail::class, ContactMessage::class, MemberInvitation::class, SendQueuedNotificationsWithLog::class]);
```

and append at the end of the file:

```php
it('encrypts every queued notification, so a password-reset token cannot be read out of the jobs table either', function () {
    config()->set('queue.default', 'database');

    $user = User::factory()->create(['email' => 'forgot@example.org']);
    $token = str_repeat('r', 64);

    // Exactly what Filament's RequestPasswordReset does. ResetPassword is
    // Filament's class, queued, with the plaintext token in a public property
    // and in the URL - and it does not implement ShouldBeEncrypted, so
    // without the job doing it the token sat in jobs.payload and, after a
    // final failure, in failed_jobs.payload for 720 hours.
    $notification = app(ResetPassword::class, ['token' => $token]);
    $notification->url = url('/org/password-reset/reset?token='.$token);

    $user->notify($notification);

    $payload = (string) DB::table('jobs')->value('payload');
    $decoded = json_decode($payload, true);

    expect($payload)->not->toBe('')
        ->and($payload)->not->toContain($token)
        ->and($decoded['data']['commandName'])->toBe(SendQueuedNotificationsWithLog::class)
        ->and($decoded['data']['command'])->not->toStartWith('O:');

    // And the worker still decrypts it and delivers the link.
    expect(Artisan::call('queue:work', [
        '--stop-when-empty' => true,
        '--sleep' => 0,
        '--timeout' => 0,
        '--memory' => 2048,
    ]))->toBe(0);

    $delivered = Mail::mailer('array')->getSymfonyTransport()->messages();

    expect($delivered)->toHaveCount(1)
        ->and((string) $delivered->first()?->getOriginalMessage()->getTextBody())->toContain($token)
        ->and(EmailLog::query()->sole()->status)->toBe(EmailLogStatus::Sent)
        ->and(DB::table('jobs')->count())->toBe(0);
});
```

- [ ] **Step 10: Run them and watch both fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact --filter='QueuedMailPayloadTest' > /tmp/t-task-5.log 2>&1; echo "rc=$?"; tail -30 /tmp/t-task-5.log
```

Expected: `rc` non-zero, `2 failed, 5 passed`: the dataset row `SendQueuedNotificationsWithLog` with `Failed asserting that false is true`, and the new case with `Expecting '{"uuid":…}' not to contain 'rrrr…'` — the reset token, verbatim in `jobs.payload` (fact 5.6).

- [ ] **Step 11: Declare the interface on the job**

In `app/Notifications/SendQueuedNotificationsWithLog.php`, add after `use App\Models\EmailLog;`:

```php
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
```

and replace the end of the class docblock and the class line:

```php
 * Do not rename or move this class without draining the queue first: a job
 * already in `jobs` or `failed_jobs` names it in its payload.
 */
class SendQueuedNotificationsWithLog extends SendQueuedNotifications
{
```

with:

```php
 * Do not rename or move this class without draining the queue first: a job
 * already in `jobs` or `failed_jobs` names it in its payload. Rotating APP_KEY
 * needs the same drain, now for notifications as well as templated mail.
 */
class SendQueuedNotificationsWithLog extends SendQueuedNotifications implements ShouldBeEncrypted
{
```

- [ ] **Step 12: The runbook — the two places that list what is encrypted**

`docs/runbooks/deploy-production.md`, **Invitations and their tokens**: replace

```markdown
- **The queued job payload carrying a token is encrypted.** `TemplatedMail`,
  `ContactMessage` and `MemberInvitation` all implement `ShouldBeEncrypted`, so
  the `command` blob in `jobs.payload` — and in `failed_jobs.payload`, which
```

with:

```markdown
- **The queued job payload carrying a token is encrypted.** `TemplatedMail`,
  `ContactMessage` and `MemberInvitation` all implement `ShouldBeEncrypted`, and
  so does `SendQueuedNotificationsWithLog`, the job every queued notification
  travels in (Filament's password reset, whose token is in its URL, included),
  so the `command` blob in `jobs.payload` — and in `failed_jobs.payload`, which
```

**Secret rotation**: replace

```markdown
Drain the queue before rotating `APP_KEY`: queued mail payloads are encrypted with it (`TemplatedMail implements ShouldBeEncrypted`), and a job written under the old key cannot be run under the new one.
```

with:

```markdown
Drain the queue before rotating `APP_KEY`: queued mail payloads are encrypted with it (`TemplatedMail` and `SendQueuedNotificationsWithLog` implement `ShouldBeEncrypted`, so that is every templated email and every notification), and a job written under the old key cannot be run under the new one.
```

The rest of that paragraph is unchanged.

- [ ] **Step 13: Run, gate, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact --filter='QueuedMailPayloadTest|FailedNotificationLogTest|EmailLogPipelineTest|VerificationMailTest' > /tmp/t-task-5.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-5.log && \
./vendor/bin/pint --test > /tmp/pint-task-5.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan-task-5.log 2>&1; echo "stan rc=$?" && \
php artisan test > /tmp/all-task-5.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-5.log
```

Expected: every `rc=0`; Task 4's count + 9. The full suite is the real check here: every notification in it now goes through `encrypt()` on the way into the `sync` queue and `decrypt()` on the way out. Commit, with the session's Co-Authored-By trailer:

```bash
cd /c/Users/ahmed/Documents/CASS && git add app/Notifications/SendQueuedNotificationsWithLog.php docs/runbooks/deploy-production.md \
  tests/Feature/Security/QueuedMailPayloadTest.php && \
git commit -q -m "security: encrypt every queued notification's payload" -m "Laravel encrypts a notification's job only when the notification itself
implements ShouldBeEncrypted, and Filament's ResetPassword does not, so its
reset token sat readable in jobs.payload and, after a failure, in
failed_jobs.payload for 720 hours. SendQueuedNotificationsWithLog - the job
every queued notification travels in - now implements the interface, which
Queue::jobShouldBeEncrypted() checks before anything the notification says." -m "<the session's Co-Authored-By trailer>" && git log --oneline -1
```

**Part C — the upload sweep (Steps 14-19, one commit).**

- [ ] **Step 14: Write the failing tests**

Create `tests/Feature/Console/SweepTemporaryUploadsTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

use function Pest\Laravel\artisan;

beforeEach(function () {
    // Under test FileUploadConfiguration::disk() is `tmp-for-tests`, not
    // `local`, and Livewire fakes it once per PROCESS
    // (FileUploadConfiguration::storage()) - so a file left by one test would
    // still be there in the next. A fresh fake per test, and a frozen clock so
    // "a day old" means the same instant to the test and to the sweep.
    Storage::fake(FileUploadConfiguration::disk());
    $this->freezeTime();
});

/**
 * One temporary upload as FileUploadConfiguration::storeTemporaryFile() leaves
 * it - the file and its `.json` sidecar - last modified $minutes ago.
 */
function temporaryUpload(string $name, int $minutes): void
{
    $disk = Storage::disk(FileUploadConfiguration::disk());

    foreach ([$name, $name.'.json'] as $file) {
        $path = FileUploadConfiguration::path($file);
        $disk->put($path, 'x');
        touch($disk->path($path), now()->subMinutes($minutes)->getTimestamp());
    }
}

it('deletes temporary uploads more than a day old and keeps the younger ones', function () {
    temporaryUpload('abandoned.pdf', 24 * 60 + 1);
    temporaryUpload('in-progress.pdf', 24 * 60 - 1);

    artisan('cass:sweep-uploads')
        ->expectsOutputToContain('Deleted 2 temporary upload file(s)')
        ->assertExitCode(0);

    expect(Storage::disk(FileUploadConfiguration::disk())->allFiles(FileUploadConfiguration::path()))
        ->toEqualCanonicalizing([
            FileUploadConfiguration::path('in-progress.pdf'),
            FileUploadConfiguration::path('in-progress.pdf.json'),
        ]);
});

it('never touches a file outside the temporary upload directory', function () {
    // In production the temporary directory and every stored abstract share
    // one disk (`local`, storage/app/private), so the sweep must be scoped to
    // the directory and not to the disk. A year-old submission file is the
    // case that would hurt.
    $disk = Storage::disk(FileUploadConfiguration::disk());
    $disk->put('3f/01JBX5Q4ZQ7K8M2N3P4R5S6T7V.pdf', 'x');
    touch($disk->path('3f/01JBX5Q4ZQ7K8M2N3P4R5S6T7V.pdf'), now()->subYear()->getTimestamp());

    artisan('cass:sweep-uploads')->assertExitCode(0);

    expect($disk->exists('3f/01JBX5Q4ZQ7K8M2N3P4R5S6T7V.pdf'))->toBeTrue();
});

it('is scheduled every hour', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'cass:sweep-uploads'));

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('0 * * * *');
});
```

- [ ] **Step 15: Run them and watch all three fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact --filter='SweepTemporaryUploadsTest' > /tmp/t-task-5.log 2>&1; echo "rc=$?"; tail -30 /tmp/t-task-5.log
```

Expected: `rc` non-zero, `3 failed`: two `CommandNotFoundException: The command "cass:sweep-uploads" does not exist.` and `is scheduled every hour` with `Expecting null not to be null`.

- [ ] **Step 16: The action and the command**

Create `app/Actions/Submissions/SweepTemporaryUploads.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use Carbon\CarbonImmutable;
use League\Flysystem\FilesystemException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/**
 * Livewire's own cleanup of its temporary upload directory, on a clock instead
 * of on the next upload.
 *
 * Every upload lands in storage/app/private/livewire-tmp first, and nothing
 * takes it out again: StoreSubmissionFile COPIES the bytes onto the private
 * disk with writeStream(), so a submitted abstract leaves its temporary copy
 * behind exactly as an abandoned one does. WithFileUploads::_finishUpload()
 * runs cleanupOldUploads() only when the NEXT upload finishes, so after a
 * deadline rush all of it stays inside the cass-storage volume until somebody
 * uploads something again. This deletes exactly what that method would: every
 * file under FileUploadConfiguration::path() on
 * FileUploadConfiguration::storage(), last modified more than a day ago. The
 * same disk, the same directory and the same age, read from the same class, so
 * the two cannot drift apart however config/livewire.php changes.
 */
class SweepTemporaryUploads
{
    /** WithFileUploads::cleanupOldUploads() uses now()->subDay(). */
    public const MAX_AGE_HOURS = 24;

    /**
     * @return int the number of files deleted - an upload is two, the file and
     *             its `.json` sidecar
     */
    public function handle(): int
    {
        $disk = FileUploadConfiguration::storage();
        $cutoff = CarbonImmutable::now()->subHours(self::MAX_AGE_HOURS)->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles(FileUploadConfiguration::path()) as $path) {
            try {
                if ($disk->lastModified($path) >= $cutoff) {
                    continue;
                }
            } catch (FilesystemException) {
                // Gone between the listing and the stat: Livewire's own
                // cleanup, running inside an author's upload request, got to
                // it first. Its source says the same race happens to it.
                continue;
            }

            if ($disk->delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
```

Create `app/Console/Commands/SweepTemporaryUploadsCommand.php` (discovered like every class in that directory; nothing to register):

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Submissions\SweepTemporaryUploads;
use Illuminate\Console\Command;

/**
 * Hourly (routes/console.php). Also the command to run by hand when
 * `cass:health` reports the private disk full: the abandoned uploads are the
 * one thing on that volume that is always safe to delete.
 */
class SweepTemporaryUploadsCommand extends Command
{
    protected $signature = 'cass:sweep-uploads';

    protected $description = 'Delete Livewire temporary uploads more than a day old, which Livewire itself only sweeps when the next upload arrives.';

    public function handle(SweepTemporaryUploads $sweep): int
    {
        $this->info(sprintf(
            'Deleted %d temporary upload file(s) older than %d hours.',
            $sweep->handle(),
            SweepTemporaryUploads::MAX_AGE_HOURS,
        ));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 17: Schedule it**

In `routes/console.php`, after `use App\Console\Commands\SendReviewerRemindersCommand;` add:

```php
use App\Console\Commands\SweepTemporaryUploadsCommand;
```

and directly above the heartbeat block's first comment line, `// The scheduler has no "last run" anywhere in Laravel, so cass:health cannot`, insert:

```php
// Livewire sweeps its temporary upload directory only when the next upload
// finishes, so a quiet week after a deadline keeps every abandoned PDF. Hourly,
// so nothing outlives Livewire's own one-day limit by more than an hour; the
// run is one directory listing. No withoutOverlapping(): two runs deleting the
// same file is a `false` from the second, not an error.
Schedule::command(SweepTemporaryUploadsCommand::class)->hourly();

```

- [ ] **Step 18: The runbook — where uploads wait, and what to run when the disk is full**

`docs/runbooks/deploy-production.md`, **Author uploads and private storage**: after the paragraph that ends

```markdown
and a row with no file is a broken link, so the two have to be restored from the
same moment.
```

insert:

````markdown

Every upload lands first in `storage/app/private/livewire-tmp`, and submitting
the form *copies* it into place, so every file an author ever attached —
submitted or abandoned — leaves a temporary copy behind. Livewire deletes the
ones more than a day old only when the *next* upload finishes, so
`cass:sweep-uploads` does the same thing every hour from the scheduler. It
touches nothing outside that directory. If `cass:health` reports the private
disk full, it is the first thing to run:

```bash
C=$(cass_container app)
sudo docker exec "$C" su-exec app php artisan cass:sweep-uploads
```

An attachment left in an open form for more than a day is gone when the form is
submitted — as it already was whenever another author's upload finished in the
meantime, which is when Livewire sweeps.
````

**Health**: in the table, replace the `private disk` row

```markdown
| `private disk` | the `cass-storage` volume is full or unmounted. The next abstract upload fails. The check writes, reads back and deletes a probe file. |
```

with:

```markdown
| `private disk` | the `cass-storage` volume is full or unmounted. The next abstract upload fails. The check writes, reads back and deletes a probe file. `cass:sweep-uploads` frees what temporary uploads hold (see "Author uploads and private storage"). |
```

- [ ] **Step 19: Run, gate, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact --filter='SweepTemporaryUploadsTest|HealthCommandTest|UploadConfigTest' > /tmp/t-task-5.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-5.log && \
./vendor/bin/pint --test > /tmp/pint-task-5.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan-task-5.log 2>&1; echo "stan rc=$?" && \
php artisan test > /tmp/all-task-5.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-5.log && \
CACHE_STORE=array php artisan schedule:list > /tmp/schedule-task-5.log 2>&1; echo "schedule rc=$?"; grep -n "sweep-uploads" /tmp/schedule-task-5.log
```

Expected: every `rc=0`; Task 4's count + 12; `schedule:list` prints `0   * * * *  php artisan cass:sweep-uploads` beside the reviewer reminders. (`CACHE_STORE=array` because `schedule:list` asks the cache about `withoutOverlapping()` mutexes, and a local `.env` may point the cache at a database that is not running.) Commit, with the session's Co-Authored-By trailer:

```bash
cd /c/Users/ahmed/Documents/CASS && git add app/Actions/Submissions/SweepTemporaryUploads.php app/Console/Commands/SweepTemporaryUploadsCommand.php \
  routes/console.php docs/runbooks/deploy-production.md tests/Feature/Console/SweepTemporaryUploadsTest.php && \
git commit -q -m "feat(uploads): sweep Livewire's temporary uploads every hour" -m "Livewire deletes day-old temporary uploads only when the next upload
finishes, and every upload - submitted or abandoned - leaves its copy in
livewire-tmp, because StoreSubmissionFile copies rather than moves.
cass:sweep-uploads applies Livewire's own rule, on Livewire's own disk and
directory, from the scheduler." -m "<the session's Co-Authored-By trailer>" && git log --oneline -1
```

**Part D — the orphan icon (Steps 20-23, one commit about assets).**

- [ ] **Step 20: Write the failing test**

Create `tests/Feature/PublicImagesTest.php`:

```php
<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * public/images is served to anyone who asks, and everything in it is licensed
 * for this project only (public/images/README.md). An image that no view,
 * stylesheet, script or class names is a licensed file published for nothing -
 * and badge.svg sat there from Plan 1 to Plan 7 because no test asked.
 *
 * A plain substring search, and deliberately so: every reference in this
 * application is a literal `asset('images/...')`, and an image that is ever
 * named only by a computed path should have to say so here.
 */
it('ships only images that something in resources or app refers to', function () {
    $sources = '';

    foreach ((new Finder)->files()->in([resource_path(), app_path()]) as $file) {
        $sources .= $file->getContents();
    }

    $unreferenced = [];

    foreach ((new Finder)->files()->in(public_path('images'))->notName('README.md') as $image) {
        $relative = 'images/'.str_replace('\\', '/', $image->getRelativePathname());

        if (! str_contains($sources, $relative)) {
            $unreferenced[] = $relative;
        }
    }

    expect($unreferenced)->toBe([]);
});
```

- [ ] **Step 21: Run it and watch it name the file**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact --filter='PublicImagesTest' > /tmp/t-task-5.log 2>&1; echo "rc=$?"; tail -20 /tmp/t-task-5.log
```

Expected: `rc` non-zero, `1 failed`, and the diff lists exactly one entry, `'images/icons/badge.svg'`. Any second entry is an image Tasks 1-4 added without a reference — stop and find it before deleting anything.

- [ ] **Step 22: Delete it**

```bash
cd /c/Users/ahmed/Documents/CASS && git rm -q public/images/icons/badge.svg && git status --short public/images
```

Expected: `D  public/images/icons/badge.svg`.

- [ ] **Step 23: Run, gate, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact --filter='PublicImagesTest|BrandAssetsTest|LandingPageTest' > /tmp/t-task-5.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-5.log && \
./vendor/bin/pint --test > /tmp/pint-task-5.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan-task-5.log 2>&1; echo "stan rc=$?" && \
php artisan test > /tmp/all-task-5.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-5.log
```

Expected: every `rc=0`; Task 4's count + 13. Commit, with the session's Co-Authored-By trailer:

```bash
cd /c/Users/ahmed/Documents/CASS && git add tests/Feature/PublicImagesTest.php && \
git commit -q -m "assets: remove badge.svg, which nothing references" -m "The fourth icon of a set of three, unused since Plan 1 and served to anyone
who asked for it. It is one git checkout bc27aa6 away if the landing page
ever grows a fourth feature card. PublicImagesTest now fails on any image
under public/images that nothing in resources/ or app/ names." -m "<the session's Co-Authored-By trailer>" && git log --oneline -1
```


### Task 6: A reviewer edits their own affiliation

One backlog item, from the "Reviewing (deferred by Plan 4)" section:

> **A reviewer cannot edit their own affiliation.** `conference_reviewers.affiliation` is typed by the organizer when inviting and copied on accept; it is the only input to spec 5.5's affiliation conflict rule, and a reviewer who moves institution cannot correct it. The reviewer panel's `->profile()` page is where that field belongs, per conference.

Spec 5.5's rule is the reason the value matters: auto-assign picks reviewers "skipping reviewers whose affiliation matches any author affiliation (case-insensitive) or whose email domain matches an author's email domain". After this task the reviewer panel's profile page lists every conference the person actively reviews, each with its own affiliation and a **Change** action. The write goes through one new action with its own policy check, logs an activity entry with the reviewer as causer, and the next auto-assign reads the new value. Nothing already assigned moves.

**Facts this task relies on.** Every one was read on `main` at `45d2d8e` on 2026-10-02.

6.1. **Installed versions, from `composer.lock`:** `filament/filament` v5.8.1 (`:1277-1278`), `laravel/framework` v13.32.0 (`:2327-2328`), `livewire/livewire` v4.4.4 (`:3481-3482`), `spatie/laravel-activitylog` 5.1.1 (`:5519-5520`).

6.2. **The column exists and needs no migration.** `database/migrations/2026_09_12_000300_create_conference_reviewers_table.php:21` is `$table->string('affiliation')->nullable();`, which is Laravel's default `VARCHAR(255)`. `:28` is `unique(['conference_id', 'user_id'])`: one row per person per conference. So "per conference" means one row each, not a list.

6.3. **One path writes the value today, and exactly one decision reads it.**
- The path is invitation, then acceptance. `cass:demo-seed` goes through the same `InviteReviewer` (`app/Actions/Demo/SeedDemo.php:468-474`), and the legacy import writes only *author* affiliations (`app/Actions/Legacy/ImportLegacy.php:783-835`, onto `SubmissionAuthor`).
- The organizer's invite form declares the field as `TextInput::make('affiliation')->label(__('reviewer.fields.affiliation'))->maxLength(255)->helperText(__('reviewer.fields.affiliation_help'))` (`app/Filament/Organizer/Resources/Conferences/Pages/ConferenceReviewers.php:171-172`). `InviteReviewer::handle()` stores it on the invitation **untrimmed**: `'affiliation' => $affiliation ?? $invitation->affiliation` (`app/Actions/Reviewers/InviteReviewer.php:115`).
- `ReviewerInvitation::grantTo()` (`app/Models/ReviewerInvitation.php:112-137`) copies it onto the row on accept: `'affiliation' => $this->affiliation ?? $reviewer->affiliation` (`:131`).
- The only reader that decides anything is `AutoAssignReviewers::conflicts()` (`app/Actions/Reviews/AutoAssignReviewers.php:194-222`). It reads `$reviewer->affiliation` at `:198` and compares it with each author's at `:216`, both through `normalise()` (`:237-243`), which lower-cases, turns punctuation into spaces and collapses whitespace. `grep -rn "conflicts(" app` returns `:86` and `:194` and nothing else.
- `AssignReviewers::blockers()` (`app/Actions/Reviews/AssignReviewers.php:29-78`), the manual path, has no conflict check at all.
- The organizer sees the value in the Reviewers table (`ConferenceReviewers.php:81`, `:124`).

6.4. **Auto-assign never takes an assignment away.** `plan()` takes active reviewers only (`AutoAssignReviewers.php:41-45`) and rejects conflicting ones (`:86`). `apply()` saves the union of existing and planned assignments (`:154-162`). A changed affiliation can therefore keep a reviewer off a new abstract, but it cannot take them off one they already have.

6.5. **A re-invitation carries the row's current value.** The organizer's `reinvite` action passes `$reviewer->affiliation` into `InviteReviewer::handle()` (`ConferenceReviewers.php:283-289`). A reviewer's correction therefore survives being removed and invited again.

6.6. **The policy has no ability for the reviewer to change their own row.** `ConferenceReviewerPolicy` (`app/Policies/ConferenceReviewerPolicy.php`):
- `before()` returns `true` for a platform admin (`:14-17`).
- `view()` allows the row's own user or any member of the organization (`:35-44`).
- `update()`, commented *"Remove reviewer" is an update*, allows **any** role in the organization (`:52-58`).
- `delete`, `restore` and `forceDelete` all return `false` (`:60-88`).

6.7. **The model.** `ConferenceReviewer` has `$guarded = ['*']` (`app/Models/ConferenceReviewer.php:28`), so writes go through `forceFill()`. It also has `conference()` (`:53-57`), `isActive()` (`:71-74`) and `scopeActive()` (`:80-83`), and it has **no** `organization()` relation. `grep -n "function organization" app/Models/*.php` lists only `Conference`, `EmailLog`, `OrganizationInvitation`, `OrganizationMember` and `Submission`. `Conference` soft-deletes (`app/Models/Conference.php:38`), and `activeReviewers()` is `reviewers()` filtered on status (`:220-223`).

6.8. **The reviewer panel as built.**
- `app/Providers/Filament/ReviewerPanelProvider.php:50` is a bare `->profile()`, and `:8` imports `Dashboard`.
- The panel has no tenancy (`:27-39`) and discovers `app/Filament/Reviewer/Pages` (`:66`).
- `User::canAccessPanel()`'s reviewer arm is `isActiveReviewer()` (`app/Models/User.php:113`, method `:84-93`). Someone removed from every conference therefore gets a 403 on every `/review` URL, the profile page included. Someone removed from one conference but active in another still gets in.
- The dashboard lists conferences through `Conference::query()->whereHas('activeReviewers', …)` (`app/Filament/Reviewer/Pages/Dashboard.php:55-60`): active rows, non-trashed conferences.
- The dashboard is a Blade view (`resources/views/filament/reviewer/pages/dashboard.blade.php`). Task 1 owns the panel views it restyles.

6.9. **How Filament's profile page is replaced, and what it renders.**
- `Panel::profile(?string $page = EditProfile::class, bool $isSimple = true)` (`vendor/filament/filament/src/Panel/Concerns/HasAuth.php:269-274`).
- `Filament\Auth\Pages\EditProfile` sets `protected static bool $isDiscovered = false;` (`vendor/filament/filament/src/Auth/Pages/EditProfile.php:61`). Page discovery queues the Livewire component, then skips page registration for such a class (`vendor/filament/filament/src/Panel/Concerns/HasComponents.php:539-551`), so a subclass can live in the discovered `Pages` directory.
- The page body is `content()` (`EditProfile.php:508-515`). It renders the form component (`:517-529`) and then the two-factor section (`:531-549`).
- The user menu always gets a `profile` item linking to the page (`vendor/filament/filament/src/Panel/Concerns/HasUserMenu.php:131-137`), with the page's own label (`:162-169`).
- A simple page is `Width::Large` unless configured otherwise (`vendor/filament/filament/resources/views/components/layout/simple.blade.php:16`). The prototype's `/review/profile` renders `fi-simple-main fi-width-lg`.

6.10. **A table can sit inside a page's schema.** `Filament\Schemas\Components\EmbeddedTable::render()` renders the host Livewire component's own table and throws a `LogicException` unless the component implements `HasTable` (`vendor/filament/schemas/src/Components/EmbeddedTable.php:27-36`).

6.11. **A table action can only reach rows in the table's own query.**
- `HasRecords::resolveTableRecord()` resolves a record key through that query: `$query->find($key)` (`vendor/filament/tables/src/Concerns/HasRecords.php:184-205`).
- When nothing resolves, `InteractsWithActions` throws `ActionNotResolvableException("Record [{$key}] no longer exists.")` (`vendor/filament/actions/src/Concerns/InteractsWithActions.php:706-711`). The test harness surfaces that exception. In a real request `callMountedAction()` catches it and unmounts (`:150-161`).
- Table records are keyed by `getKey()` (`HasRecords.php:249-258`), the integer id. That id is never a credential, because it only resolves through the query.

6.12. **A table with no model label is labelled in English, whatever the locale.** The table element carries `aria-label="{{ $pluralModelLabel }}"` (`vendor/filament/tables/resources/views/index.blade.php:1081`). `Table::getPluralModelLabel()` falls back to a label derived from the model class (`vendor/filament/tables/src/Table/Concerns/HasRecords.php:171-181`). Measured on the prototype before the labels were set, it rendered `aria-label="conference reviewers"`.

6.13. **Nothing between the browser and PHP trims this input.**
- Livewire switches off `TrimStrings` and `ConvertEmptyStringsToNull` for its own requests (`vendor/livewire/livewire/src/Mechanisms/HandleRequests/HandleRequests.php:81-90`).
- Filament turns exactly `''` into `null` when it dehydrates state (`vendor/filament/schemas/src/Components/Concerns/HasState.php:294-298`). It does nothing else to the value.
- `maxLength(255)` becomes the `max:255` rule (`vendor/filament/forms/src/Components/Concerns/CanBeLengthConstrained.php:69-73`).
- So `"   KFSH "` reaches PHP exactly as typed, and so does a value of three spaces.

6.14. **House patterns this task copies.**
- A refusal is `MemberChangeRefused(list<string> $reasons)` (`app/Exceptions/MemberChangeRefused.php:9-16`), and reviewer actions use it too (`InviteReviewer.php:66-70`).
- The own-row-only precedent is `SetSubmissionNotifications::handle()` (`app/Actions/Organizations/SetSubmissionNotifications.php:19-32`).
- Activity: `RemoveConferenceReviewer` logs `reviewer.removed` with `performedOn($reviewer)`, `causedBy($actor)` and `conference_id` in the properties (`app/Actions/Reviewers/RemoveConferenceReviewer.php:42-50`). `ChangeMemberRole` records `from` and `to` (`app/Actions/Organizations/ChangeMemberRole.php:108-112`).
- `grep -rn "Gate::" app/Actions` returns **zero hits**: today every action trusts its caller to have asked.

6.15. **Strings already in `lang/en/reviewer.php`:**
- `fields.affiliation` is `'Affiliation'` (`:38`).
- `fields.affiliation_help` is addressed to an organizer: *"Used to keep a reviewer away from…"* (`:39`).
- `notices.refused` is `'Nothing changed'` (`:72`).
- `queue.columns.conference` is `'Conference'` (`:118`).
- The `errors` group ends at `'detail_text'` (`:102`), and the `switch` group is `:174-177`.
- `$plan7Sources` in `tests/Feature/LanguageCoverageTest.php` is created by Task 2.

6.16. **Test harness.**
- `tests/Feature` gets `RefreshDatabase` from `tests/Pest.php:18-20`, and `tests/Unit` does not (`:22`). A unit file that touches the database says `uses(RefreshDatabase::class)` itself, as `tests/Unit/AutoAssignReviewersTest.php:18` does.
- That file also declares the global functions `makeReviewers()` (`:30`) and `makeSubmissions()` (`:46`). A second declaration of either name anywhere in the suite is a fatal error on the full run.
- `bootReviewerPanel()` is at `tests/Pest.php:68-73`, and `ConferenceReviewerFactory::removed()` at `database/factories/ConferenceReviewerFactory.php:33-39`.
- Plan 6's CSP has `script-src 'self' …` (`app/Http/Middleware/ContentSecurityPolicy.php:70`), so a `<script src>` from this origin needs no nonce, and every inline tag does.

**Decisions this task makes.**

**1. The editor is a section on the reviewer panel's own profile page. It is not a page of its own, and not the dashboard.**
- The backlog names this page.
- Filament already links it from the user menu (fact 6.9).
- It is the one screen in the panel that is about the person rather than the work.
- The dashboard would have been the other natural place, since it already lists the same conferences. But it is a Blade view that Task 1 may restyle (fact 6.8). This page is schema in a PHP class and touches no shared view.
- A separate "Affiliations" page would be a navigation item for one text field per conference.

Only the reviewer panel's profile changes: the organizer and admin panels keep Filament's page, because neither has a conference row to edit. The page keeps the simple layout (fact 6.9). Moving one panel's profile into the sidebar layout is a visual change, and visual changes belong to Task 1. The two columns wrap instead.

**2. Each row of an embedded table gets its own "Change" action. There is no second form of text inputs.**
- The table resolves a row only through its own query (fact 6.11). Another reviewer's row, or this reviewer's removed one, is therefore not something a forged key can reach.
- One save is one policy check, one activity entry and one notification.
- A second form would put a second Save button under the profile form's own. It would also hold Livewire state keyed by row, which the client controls, and that state would have to be filtered by hand.
- The tests use the same table helpers as every other panel test in the suite.

**3. A new ability, `updateAffiliation`: the reviewer's own row, while it is active.** `update()` admits every member of the organization (fact 6.6), because it means "remove reviewer". Reusing it would let any organizer rewrite a reviewer's institution, which is a product change rather than this backlog item. A platform admin passes through `before()`, as on every policy in this codebase that has one. No screen offers them this, because the table lists only the viewer's own rows. If code ever called it on their behalf, they would be the logged causer, so the log stays true. **Whether organizers should be able to edit an active reviewer's affiliation is an owner question, and it is recorded rather than built.**

**4. The action asks the policy itself, and the page does not call `Gate::authorize()` first.** This is the first action in `app/Actions` to call the Gate (fact 6.14). It does so because this rule *is* a policy question (ownership and status, nothing else), and asking it inside the action means a later caller cannot skip it. The page still uses `Gate::allows()` in `visible()`. A refusal can genuinely happen: the organizer removes the reviewer while their modal is open. It arrives as the house's "Nothing changed" notification with a reason, not as a 403 modal.

**5. Trim, treat empty as null, allow at most 255 characters.** These are the invite form's rules plus the trim that nothing upstream does (fact 6.13).
- `UpdateReviewerAffiliation::MAX_LENGTH` is `255`, and both the form field and the action read it.
- The action checks the length after trimming.
- There is no further normalisation: the conflict rule already folds case, punctuation and spaces when it compares (fact 6.3), and the organizer reads the value back exactly as typed.
- An unchanged value writes nothing and logs nothing.
- `InviteReviewer` does not trim either, and this task leaves it alone. It is outside this backlog item, and the conflict rule makes stray spaces harmless there.

**6. Existing assignments are not touched, and the modal says so.** Today nothing unassigns a reviewer when their data changes (fact 6.4), and that stays true. The modal tells the reviewer: *"Changing it does not take you off abstracts already assigned to you. If you now share an institution with the authors of one of them, tell the organizers."* That is the remedy the backlog's declared-conflicts item already names. **Whether the organizer should be told when a reviewer's change creates a conflict on an abstract already assigned to them is a product decision, recorded as an open question.** A test pins the current behaviour.

**7. Every active conference is listed, whatever its state, except deleted ones.** An affiliation is a fact about the person, not about the review window, and the organizer sees it on the Reviewers page in every state. Gating the edit on conference status would leave a stale value on display with no way to fix it. The table lists exactly what the dashboard lists (fact 6.8): `whereHas('conference')` carries Conference's soft-delete scope.

**8. No migration and no new relation.** The column exists (fact 6.2). `ConferenceReviewer` reaches its organization through `conference`, as `ReviewAssignment` and `Track` do (fact 6.7). This task creates no model and changes none, and the new ability never walks to the organization. A `HasOneThrough` that nothing reads would be untested code.

**Files:**
- Create: `app/Actions/Reviewers/UpdateReviewerAffiliation.php`
- Create: `app/Filament/Reviewer/Pages/EditProfile.php`
- Modify: `app/Policies/ConferenceReviewerPolicy.php`: one ability, `updateAffiliation()`.
- Modify: `app/Providers/Filament/ReviewerPanelProvider.php`: one import, and the `->profile()` line. The `->viteTheme()` line Task 1 added is not touched.
- Modify: `lang/en/reviewer.php`: three `errors.affiliation_*` keys and one `affiliation` group.
- Modify: `tests/Feature/LanguageCoverageTest.php`: two paths appended to `$plan7Sources`.
- Test: `tests/Unit/UpdateReviewerAffiliationTest.php` (10 cases) and `tests/Feature/Reviewer/ReviewerAffiliationTest.php` (10 cases).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/UpdateReviewerAffiliationTest.php`. The action's rules come first, and then the two things the backlog item exists for: the conflict check reads the new value, and nothing already assigned moves.

```php
<?php

declare(strict_types=1);

use App\Actions\Reviewers\UpdateReviewerAffiliation;
use App\Actions\Reviews\AutoAssignReviewers;
use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Exceptions\MemberChangeRefused;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->conference = Conference::factory()->for($this->organization)->create([
        'status' => ConferenceStatus::Closed,
        'review_mode' => ReviewMode::Assigned,
        'reviewers_per_submission' => 1,
    ]);
    // An explicit address on a domain no author uses, so the only conflict
    // the auto-assign cases below can find is the affiliation one.
    $this->reviewer = User::factory()->create(['email' => 'omar@reviewers.example']);
    $this->row = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => $this->reviewer->id,
        'affiliation' => 'Old Hospital',
    ]);
});

/**
 * One submitted abstract by one author at $affiliation. A distinct name from
 * AutoAssignReviewersTest's makeSubmissions(): Pest loads every test file into
 * one process, so a second global function of the same name is a fatal error
 * on the full run.
 */
function affiliationSubmission(Conference $conference, string $affiliation): Submission
{
    $submission = Submission::factory()->for($conference)->submitted()->create();
    SubmissionAuthor::factory()->for($submission)->corresponding()->create([
        'email' => 'author@authors.example',
        'affiliation' => $affiliation,
        'sort' => 1,
    ]);

    return $submission->refresh();
}

it('changes the reviewer\'s own affiliation for one conference, trimmed, and logs it with them as causer', function () {
    // The same person reviewing a second conference: the value is per
    // conference, so that row must not move.
    $elsewhere = ConferenceReviewer::factory()->create([
        'user_id' => $this->reviewer->id,
        'affiliation' => 'Old Hospital',
    ]);

    app(UpdateReviewerAffiliation::class)->handle($this->row, '  New Hospital  ', $this->reviewer);

    expect($this->row->fresh()?->affiliation)->toBe('New Hospital')
        ->and($elsewhere->fresh()?->affiliation)->toBe('Old Hospital');

    $entry = Activity::query()->where('description', 'reviewer.affiliation_changed')->sole();

    expect($entry->causer_id)->toBe($this->reviewer->id)
        ->and($entry->subject_type)->toBe(ConferenceReviewer::class)
        ->and($entry->subject_id)->toBe($this->row->id)
        // toEqual, not toBe: activity_log.properties is a json column, and
        // MySQL hands an object back shorter key first.
        ->and($entry->properties->all())->toEqual([
            'conference_id' => $this->conference->id,
            'from' => 'Old Hospital',
            'to' => 'New Hospital',
        ]);
});

it('clears the affiliation when the reviewer empties it', function () {
    app(UpdateReviewerAffiliation::class)->handle($this->row, '   ', $this->reviewer);

    expect($this->row->fresh()?->affiliation)->toBeNull()
        ->and(Activity::query()->where('description', 'reviewer.affiliation_changed')->sole()->properties->all())
        ->toMatchArray(['from' => 'Old Hospital', 'to' => null]);
});

it('writes and logs nothing when the trimmed value is the one already stored', function () {
    app(UpdateReviewerAffiliation::class)->handle($this->row, ' Old Hospital ', $this->reviewer);

    expect(Activity::query()->where('description', 'reviewer.affiliation_changed')->count())->toBe(0);
});

it('refuses another reviewer\'s row in the same conference', function () {
    $theirs = ConferenceReviewer::factory()->for($this->conference)->create(['affiliation' => 'Their Hospital']);

    expect(fn () => app(UpdateReviewerAffiliation::class)->handle($theirs, 'Hijacked', $this->reviewer))
        ->toThrow(MemberChangeRefused::class, __('reviewer.errors.affiliation_not_yours'));

    expect($theirs->fresh()?->affiliation)->toBe('Their Hospital')
        ->and(Activity::query()->where('description', 'reviewer.affiliation_changed')->count())->toBe(0);
});

it('refuses an organizer of the conference, whom the policy\'s update ability does admit', function () {
    // ConferenceReviewerPolicy::update() is "remove reviewer" and answers true
    // for every member of the organization. The new ability must not be that
    // one: the affiliation is the reviewer's own statement about themselves.
    $organizer = User::factory()->create();
    $this->organization->addMember($organizer, OrganizationRole::Owner);

    expect($organizer->can('update', $this->row))->toBeTrue()
        ->and(fn () => app(UpdateReviewerAffiliation::class)->handle($this->row, 'Organizer Typed', $organizer))
        ->toThrow(MemberChangeRefused::class, __('reviewer.errors.affiliation_not_yours'));

    expect($this->row->fresh()?->affiliation)->toBe('Old Hospital');
});

it('refuses a reviewer who has been removed from that conference, even while they review another', function () {
    $this->row->forceFill(['status' => ReviewerStatus::Removed, 'removed_at' => now()])->save();
    ConferenceReviewer::factory()->create(['user_id' => $this->reviewer->id]);

    expect($this->reviewer->isActiveReviewer())->toBeTrue()
        ->and(fn () => app(UpdateReviewerAffiliation::class)->handle($this->row, 'New Hospital', $this->reviewer))
        ->toThrow(MemberChangeRefused::class, __('reviewer.errors.affiliation_removed'));

    expect($this->row->fresh()?->affiliation)->toBe('Old Hospital');
});

it('refuses their own row in another organization\'s conference they were removed from', function () {
    $theirOrganization = Organization::factory()->approved()->create();
    $theirConference = Conference::factory()->for($theirOrganization)->closed()->create();
    $removedThere = ConferenceReviewer::factory()->for($theirConference)->removed()->create([
        'user_id' => $this->reviewer->id,
        'affiliation' => 'Old Hospital',
    ]);

    expect(fn () => app(UpdateReviewerAffiliation::class)->handle($removedThere, 'New Hospital', $this->reviewer))
        ->toThrow(MemberChangeRefused::class, __('reviewer.errors.affiliation_removed'));

    expect($removedThere->fresh()?->affiliation)->toBe('Old Hospital');
});

it('takes 255 characters and refuses 256, the limit the organizer\'s invite form uses', function () {
    expect(fn () => app(UpdateReviewerAffiliation::class)->handle($this->row, str_repeat('a', 256), $this->reviewer))
        ->toThrow(MemberChangeRefused::class, __('reviewer.errors.affiliation_too_long', ['max' => 255]));

    expect($this->row->fresh()?->affiliation)->toBe('Old Hospital');

    app(UpdateReviewerAffiliation::class)->handle($this->row, str_repeat('a', 255), $this->reviewer);

    expect($this->row->fresh()?->affiliation)->toBe(str_repeat('a', 255));
});

it('is the value the auto-assign conflict check reads', function () {
    $submission = affiliationSubmission($this->conference, 'King Faisal Specialist Hospital');

    // Before: no shared institution, so the one reviewer is planned.
    $before = app(AutoAssignReviewers::class)->plan($this->conference);

    expect($before->rows)->toHaveCount(1)
        ->and($before->rows[0]['add'])->toBe([$this->reviewer->id]);

    // After: the same institution, typed the way people type it - lower case,
    // a trailing full stop. AutoAssignReviewers::normalise() makes those equal.
    app(UpdateReviewerAffiliation::class)->handle($this->row, 'king faisal specialist hospital.', $this->reviewer);

    $after = app(AutoAssignReviewers::class)->plan($this->conference);

    $fresh = ConferenceReviewer::query()->with('user')->findOrFail($this->row->id);

    expect(AutoAssignReviewers::conflicts($fresh, $submission))->toBeTrue()
        ->and($after->rows)->toBe([])
        ->and($after->shortfalls)->toHaveCount(1);
});

it('leaves an existing assignment where it is when the new affiliation conflicts with it', function () {
    $submission = affiliationSubmission($this->conference, 'King Faisal Specialist Hospital');
    ReviewAssignment::factory()->create([
        'submission_id' => $submission->id,
        'reviewer_user_id' => $this->reviewer->id,
    ]);

    app(UpdateReviewerAffiliation::class)->handle($this->row, 'King Faisal Specialist Hospital', $this->reviewer);

    // Current behaviour, kept on purpose: nothing is unassigned automatically.
    // Whether the organizer should be told is an owner decision this task
    // records rather than builds.
    expect(ReviewAssignment::query()
        ->where('submission_id', $submission->id)
        ->where('reviewer_user_id', $this->reviewer->id)
        ->exists())->toBeTrue()
        ->and(app(AutoAssignReviewers::class)->plan($this->conference)->rows)->toBe([]);
});
```

Create `tests/Feature/Reviewer/ReviewerAffiliationTest.php`. The panel cases come first, then the cross-tenant negative (the reviewer's own removed row in **another organization's** conference), then the two removal cases.

```php
<?php

declare(strict_types=1);

use App\Enums\ReviewerStatus;
use App\Filament\Reviewer\Pages\EditProfile;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->conference = Conference::factory()->for($this->organization)->closed()->create(['name' => 'Alpha Annual Meeting']);
    $this->second = Conference::factory()->for($this->organization)->closed()->create(['name' => 'Alpha Winter School']);

    $this->reviewer = User::factory()->create(['name' => 'Dr Omar Khan']);
    $this->row = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => $this->reviewer->id,
        'affiliation' => 'Old Hospital',
    ]);
    $this->secondRow = ConferenceReviewer::factory()->for($this->second)->create([
        'user_id' => $this->reviewer->id,
        'affiliation' => 'Old Hospital',
    ]);

    actingAs($this->reviewer);
    bootReviewerPanel();
});

it('is the reviewer panel\'s profile page and keeps Filament\'s own profile form and two-factor section', function () {
    expect(Filament::getPanel('reviewer')->getProfilePage())->toBe(EditProfile::class)
        // The other two panels keep Filament's page: the affiliation is a
        // reviewer's statement about a conference, and neither panel has one.
        ->and(Filament::getPanel('organizer')->getProfilePage())->not->toBe(EditProfile::class);

    // A real request, so the page is rendered through the panel's middleware
    // and layout, not only as a component.
    $response = get('/review/profile')
        ->assertOk()
        ->assertSee(__('filament-panels::auth/pages/edit-profile.form.name.label'))
        ->assertSee(__('filament-panels::auth/pages/edit-profile.multi_factor_authentication.label'))
        ->assertSee(__('reviewer.affiliation.heading'))
        ->assertSee('Alpha Annual Meeting')
        // Filament's fallback aria-label for the table, derived from the
        // model's class name - English that no language file could reach.
        ->assertDontSee('conference reviewers');

    // A new panel page under Plan 6's CSP: every INLINE <script> and <style>
    // must carry the request's nonce. A <script src> is same-origin and is
    // allowed by script-src 'self' (app/Http/Middleware/ContentSecurityPolicy.php:70).
    preg_match_all('/<(?:script|style)\b(?![^>]*\b(?:nonce|src)=)[^>]*>/i', (string) $response->getContent(), $unnonced);

    expect($unnonced[0])->toBe([]);
});

it('restates exactly what Filament\'s own content() renders, so an upgrade that changes it is a red test', function () {
    // EditProfile::content() lists the parent's two components by hand and
    // appends the affiliations. If Filament adds a third, the override would
    // silently drop it. When this fails: read the new vendor method, restate
    // it in app/Filament/Reviewer/Pages/EditProfile.php::content(), then
    // update the string below in the same commit.
    $method = new ReflectionMethod(BaseEditProfile::class, 'content');
    $lines = array_slice(
        file((string) $method->getFileName()) ?: [],
        (int) $method->getStartLine() - 1,
        (int) $method->getEndLine() - (int) $method->getStartLine() + 1,
    );

    expect((string) preg_replace('/\s+/', '', implode('', $lines)))->toBe(
        'publicfunctioncontent(Schema$schema):Schema{return$schema->components(['
        .'$this->getFormContentComponent(),'
        .'...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),'
        .']);}'
    );
});

it('lists one row per conference the reviewer is active in, with its organization', function () {
    livewire(EditProfile::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->row, $this->secondRow])
        ->assertSee('Alpha Society')
        ->assertSee('Old Hospital');
});

it('prefills the current value and changes it for one conference only', function () {
    livewire(EditProfile::class)
        ->mountTableAction('editAffiliation', $this->row)
        ->assertTableActionDataSet(['affiliation' => 'Old Hospital']);

    // A fresh component: calling on the one above would nest the second
    // mount inside the first and look for a modal action of the same name.
    livewire(EditProfile::class)
        ->callTableAction('editAffiliation', $this->row, data: ['affiliation' => '  New Hospital  '])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('reviewer.affiliation.saved'));

    expect($this->row->fresh()?->affiliation)->toBe('New Hospital')
        ->and($this->secondRow->fresh()?->affiliation)->toBe('Old Hospital')
        ->and(Activity::query()->where('description', 'reviewer.affiliation_changed')->sole()->causer_id)
        ->toBe($this->reviewer->id);
});

it('refuses more than 255 characters in the form, as the organizer\'s invite form does', function () {
    livewire(EditProfile::class)
        ->callTableAction('editAffiliation', $this->row, data: ['affiliation' => str_repeat('a', 256)])
        ->assertHasTableActionErrors(['affiliation' => 'max']);

    expect($this->row->fresh()?->affiliation)->toBe('Old Hospital');
});

it('neither lists nor changes another reviewer\'s row in the same conference', function () {
    $theirs = ConferenceReviewer::factory()->for($this->conference)->create(['affiliation' => 'Their Hospital']);

    livewire(EditProfile::class)->assertCanNotSeeTableRecords([$theirs]);

    // A forged key: the table resolves it through its own query
    // (vendor/filament/tables/src/Concerns/HasRecords.php:194-205), finds
    // nothing, and Filament refuses to run the action at all
    // (vendor/filament/actions/src/Concerns/InteractsWithActions.php:706-711).
    // The exact class, not Throwable: a TypeError swallowed here would be a
    // bug passing as a refusal.
    expect(fn () => livewire(EditProfile::class)
        ->callTableAction('editAffiliation', $theirs, data: ['affiliation' => 'Hijacked']))
        ->toThrow(ActionNotResolvableException::class, 'no longer exists');

    expect($theirs->fresh()?->affiliation)->toBe('Their Hospital');
});

it('neither lists nor changes the reviewer\'s own removed row in another organization\'s conference', function () {
    $theirOrganization = Organization::factory()->approved()->create(['name' => 'Beta Society']);
    $theirConference = Conference::factory()->for($theirOrganization)->closed()->create(['name' => 'Beta Congress']);
    $removedThere = ConferenceReviewer::factory()->for($theirConference)->removed()->create([
        'user_id' => $this->reviewer->id,
        'affiliation' => 'Old Hospital',
    ]);

    livewire(EditProfile::class)
        ->assertCanNotSeeTableRecords([$removedThere])
        ->assertDontSee('Beta Congress')
        ->assertDontSee('Beta Society');

    expect(fn () => livewire(EditProfile::class)
        ->callTableAction('editAffiliation', $removedThere, data: ['affiliation' => 'New Hospital']))
        ->toThrow(ActionNotResolvableException::class, 'no longer exists');

    expect($removedThere->fresh()?->affiliation)->toBe('Old Hospital');
});

it('does not list a conference that has been deleted', function () {
    $this->second->delete();

    livewire(EditProfile::class)
        ->assertCanSeeTableRecords([$this->row])
        ->assertCanNotSeeTableRecords([$this->secondRow]);
});

it('drops a conference from the list the moment the organizer removes the reviewer from it', function () {
    $this->row->forceFill(['status' => ReviewerStatus::Removed, 'removed_at' => now()])->save();

    livewire(EditProfile::class)
        ->assertCanSeeTableRecords([$this->secondRow])
        ->assertCanNotSeeTableRecords([$this->row]);

    expect(fn () => livewire(EditProfile::class)
        ->callTableAction('editAffiliation', $this->row, data: ['affiliation' => 'New Hospital']))
        ->toThrow(ActionNotResolvableException::class, 'no longer exists');

    expect($this->row->fresh()?->affiliation)->toBe('Old Hospital');
});

it('keeps a reviewer removed from every conference off the page altogether', function () {
    ConferenceReviewer::query()->where('user_id', $this->reviewer->id)
        ->update(['status' => ReviewerStatus::Removed->value, 'removed_at' => now()]);

    // User::canAccessPanel('reviewer') is isActiveReviewer(), so the panel's
    // Authenticate middleware answers before the page is built.
    actingAs($this->reviewer->fresh())->get('/review/profile')->assertForbidden();
});
```

Append these two paths to `$plan7Sources` in `tests/Feature/LanguageCoverageTest.php` (Task 2 created it), after its last entry. Both are PHP classes, and every string they print goes through `__('reviewer.*')`:

```php
    // Task 6: the reviewer's own affiliation.
    'app/Actions/Reviewers/UpdateReviewerAffiliation.php',
    'app/Filament/Reviewer/Pages/EditProfile.php',
```

- [ ] **Step 2: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact --filter='ReviewerAffiliationTest|LanguageCoverageTest' > /tmp/t-task-6.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-6.log
```

The filter matches both new files, because `ReviewerAffiliationTest` is a substring of `UpdateReviewerAffiliationTest`, and it matches the language cases. Expected: `rc=2`, **20 failed, 19 passed**.
- Five unit cases error with `Target class [App\Actions\Reviewers\UpdateReviewerAffiliation] does not exist.`
- The other five unit cases fail because that same error is not the `MemberChangeRefused` they expect.
- Seven panel cases error with `ComponentNotFoundException`.
- The first panel case fails with `Failed asserting that two strings are identical`: `'Filament\Auth\Pages\EditProfile'` where `'App\Filament\Reviewer\Pages\EditProfile'` is expected.
- The `$plan7Sources` key case fails on `Expected app/Actions/Reviewers/UpdateReviewerAffiliation.php to exist.`
- The `$plan7Sources` visible-English case, `leaves no visible english in the files plan 7 swept`, errors with `ErrorException: file_get_contents(…/app/Actions/Reviewers/UpdateReviewerAffiliation.php): Failed to open stream: No such file or directory`.

**Two of the new cases already pass, and that is correct:**
- The drift pin reads only the vendor `content()` method.
- The 403 case pins the panel gate this task relies on (fact 6.8). It is a guard, not new behaviour.

This is the failing-first gate.

- [ ] **Step 3: The policy ability**

`app/Policies/ConferenceReviewerPolicy.php`, anchored on the `delete()` method:

```php
    public function delete(User $user, ConferenceReviewer $reviewer): bool
    {
        return false;
    }
```

becomes

```php
    /**
     * The reviewer's own affiliation for this conference - the one input to
     * spec 5.5's affiliation conflict rule. Deliberately NOT update() above:
     * that is "remove reviewer" and admits every member of the organization,
     * while this is a reviewer's statement about themselves. Only while they
     * are active: a removed reviewer is assigned nothing, so the value decides
     * nothing until a re-invitation, which carries an affiliation of its own.
     */
    public function updateAffiliation(User $user, ConferenceReviewer $reviewer): bool
    {
        return $reviewer->user_id === $user->getKey() && $reviewer->isActive();
    }

    public function delete(User $user, ConferenceReviewer $reviewer): bool
    {
        return false;
    }
```

- [ ] **Step 4: The action**

Create `app/Actions/Reviewers/UpdateReviewerAffiliation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Reviewers;

use App\Exceptions\MemberChangeRefused;
use App\Models\ConferenceReviewer;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * A reviewer corrects their own `conference_reviewers.affiliation` - the value
 * an organizer typed when inviting them, copied on accept by
 * ReviewerInvitation::grantTo(), and the only reviewer-side input to spec 5.5's
 * affiliation conflict rule (AutoAssignReviewers::conflicts()).
 *
 * Per conference, because that is where the column lives: two organizations
 * may know the same person by two different institutions, and each conflict
 * check reads its own row.
 *
 * Existing assignments are left exactly as they are. AutoAssignReviewers never
 * removes an assignment, and AssignReviewers never checks conflicts at all, so
 * an affiliation that now matches an author's on an abstract already assigned
 * changes nothing until the organizer acts - which is today's behaviour for a
 * conflict the organizer missed, and stays so until the owner decides
 * otherwise.
 */
class UpdateReviewerAffiliation
{
    /**
     * `conference_reviewers.affiliation` is a VARCHAR(255), and the organizer's
     * invite form caps the same field at 255 (ConferenceReviewers' `invite`
     * action). One limit for one column, whoever types it.
     */
    public const MAX_LENGTH = 255;

    /** @return list<string> empty when the change may be made */
    public function blockers(ConferenceReviewer $reviewer, ?string $affiliation, User $actor): array
    {
        $reasons = [];

        // The policy is the rule - the actor's own row, while they are active
        // - and it is asked here rather than only in the page, so a second
        // caller cannot forget it. When it says no, say which half failed: a
        // reviewer removed while the modal was open deserves a true sentence.
        if (Gate::forUser($actor)->denies('updateAffiliation', $reviewer)) {
            $reasons[] = $reviewer->user_id === $actor->getKey()
                ? __('reviewer.errors.affiliation_removed')
                : __('reviewer.errors.affiliation_not_yours');
        }

        if (mb_strlen((string) self::normalise($affiliation)) > self::MAX_LENGTH) {
            $reasons[] = __('reviewer.errors.affiliation_too_long', ['max' => self::MAX_LENGTH]);
        }

        return $reasons;
    }

    public function handle(ConferenceReviewer $reviewer, ?string $affiliation, User $actor): ConferenceReviewer
    {
        $reasons = $this->blockers($reviewer, $affiliation, $actor);

        if ($reasons !== []) {
            throw new MemberChangeRefused($reasons);
        }

        $from = $reviewer->affiliation;
        $to = self::normalise($affiliation);

        // A save that changes nothing writes nothing and logs nothing, so the
        // activity log answers "when did this reviewer's institution change"
        // rather than "when did they press Save".
        if ($from === $to) {
            return $reviewer;
        }

        $reviewer->forceFill(['affiliation' => $to])->save();

        activity()
            ->performedOn($reviewer)
            ->causedBy($actor)
            ->withProperties([
                'conference_id' => $reviewer->conference_id,
                'from' => $from,
                'to' => $to,
            ])
            ->log('reviewer.affiliation_changed');

        return $reviewer;
    }

    /**
     * Trimmed, and empty means "none". Nothing cleverer: the conflict rule
     * already folds case, punctuation and runs of whitespace when it compares
     * (AutoAssignReviewers::normalise()), and the organizer reads this value
     * back on the Reviewers page exactly as the reviewer typed it.
     */
    public static function normalise(?string $affiliation): ?string
    {
        $value = trim((string) $affiliation);

        return $value === '' ? null : $value;
    }
}
```

- [ ] **Step 5: The strings**

`lang/en/reviewer.php`, two edits.

The `errors` group gains three keys at its end:

```php
        'detail_text' => 'Write something, or ask the organizers to make this question optional.',
    ],
```

becomes

```php
        'detail_text' => 'Write something, or ask the organizers to make this question optional.',
        'affiliation_not_yours' => 'You can only change your own affiliation.',
        'affiliation_removed' => 'You no longer review for this conference, so its affiliation cannot be changed.',
        'affiliation_too_long' => 'An affiliation can be at most :max characters.',
    ],
```

A new `affiliation` group goes straight after the `switch` group:

```php
    'switch' => [
        'to_reviewer' => 'Switch to reviewing',
        'to_organizer' => 'Switch to organizing',
    ],
```

becomes

```php
    'switch' => [
        'to_reviewer' => 'Switch to reviewing',
        'to_organizer' => 'Switch to organizing',
    ],

    // The reviewer's own affiliation, per conference, on the reviewer panel's
    // profile page. Three strings are REUSED rather than copied: the field
    // label is `fields.affiliation`, the column heading is
    // `queue.columns.conference` and a refusal's title is `notices.refused`.
    // `fields.affiliation_help` is not reused: it speaks to an organizer about
    // "a reviewer".
    'affiliation' => [
        'heading' => 'Your affiliation for each conference',
        'description' => 'Organizers use it to keep abstracts from your own institution away from you, so keep it current. Each conference has its own, because each organization may know you by a different one.',
        'model' => 'affiliation',
        'model_plural' => 'affiliations',
        'none' => 'None given',
        'edit' => 'Change',
        'edit_heading' => 'Your affiliation for :conference',
        'edit_description' => 'Changing it does not take you off abstracts already assigned to you. If you now share an institution with the authors of one of them, tell the organizers.',
        'help' => 'Your institution, as you would write it on an abstract. Leave it empty if you have none.',
        'saved' => 'Affiliation saved',
        'empty_heading' => 'No conferences',
        'empty_body' => 'When you accept an invitation to review, the conference appears here.',
    ],
```

**Reuse before you create** (Plan 6 Task 12's rule). The field label is `reviewer.fields.affiliation`, the column heading is `reviewer.queue.columns.conference`, and a refusal's title is `reviewer.notices.refused`; all three already exist (fact 6.15). `reviewer.fields.affiliation_help` is **not** reused, because it talks to an organizer about "a reviewer".

- [ ] **Step 6: The profile page**

Create `app/Filament/Reviewer/Pages/EditProfile.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Pages;

use App\Actions\Reviewers\UpdateReviewerAffiliation;
use App\Exceptions\MemberChangeRefused;
use App\Models\ConferenceReviewer;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

/**
 * The reviewer panel's `->profile()` page: Filament's own (name, email,
 * password, two-factor) plus one section listing every conference this person
 * actively reviews, each with its own affiliation and a "Change" action.
 *
 * A table rather than a second form: one save per conference is one policy
 * check, one activity entry and one sentence of feedback, and the record key
 * resolves only through this table's own query - so another reviewer's row, or
 * this reviewer's removed one, is not a row this page can reach.
 *
 * Not discovered as a page of its own: the parent sets `$isDiscovered = false`
 * (vendor/filament/filament/src/Auth/Pages/EditProfile.php:61), so living in
 * the discovered Pages directory registers its Livewire component and nothing
 * else. ReviewerPanelProvider names it in `->profile()`.
 */
class EditProfile extends BaseEditProfile implements HasTable
{
    use InteractsWithTable;

    /**
     * The parent's two components, in the parent's order
     * (vendor/filament/filament/src/Auth/Pages/EditProfile.php:508-515), and
     * then the affiliations. Restated rather than read back from the parent's
     * schema: Schema::getComponents() configures what it returns, and handing
     * configured components back to components() configures them twice.
     * ReviewerAffiliationTest pins the parent method's source, so a Filament
     * upgrade that adds a third component is a red test, not a section that
     * quietly never renders on this one panel.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
                ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
                $this->getAffiliationsContentComponent(),
            ]);
    }

    public function getAffiliationsContentComponent(): Component
    {
        return Section::make(__('reviewer.affiliation.heading'))
            ->description(__('reviewer.affiliation.description'))
            ->compact()
            ->schema([
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->affiliationsQuery())
            // Without these Filament derives the table's aria-label from the
            // model's class name: "conference reviewers", in English, in
            // every locale.
            ->modelLabel(__('reviewer.affiliation.model'))
            ->pluralModelLabel(__('reviewer.affiliation.model_plural'))
            ->columns([
                TextColumn::make('conference.name')
                    ->label(__('reviewer.queue.columns.conference'))
                    ->weight('semibold')
                    // The simple profile layout is 32rem wide; a long
                    // conference name wraps rather than scrolling the table.
                    ->wrap()
                    // `->`, not `?->`, on the left of `??`: the same isset-mode
                    // shape InviteReviewer::placeholderValues() uses, because
                    // Organization soft-deletes while its conferences survive.
                    ->description(fn (ConferenceReviewer $record): string => (string) ($record->conference->organization->name ?? '')),
                TextColumn::make('affiliation')
                    ->label(__('reviewer.fields.affiliation'))
                    ->placeholder(__('reviewer.affiliation.none'))
                    ->wrap(),
            ])
            ->recordActions([
                $this->editAffiliationAction(),
            ])
            ->emptyStateHeading(__('reviewer.affiliation.empty_heading'))
            ->emptyStateDescription(__('reviewer.affiliation.empty_body'))
            ->paginated(false);
    }

    /**
     * This person's ACTIVE rows, in conferences that still exist - the same
     * set the dashboard lists (Dashboard::getConferences() walks
     * `activeReviewers` from a non-trashed Conference). `whereHas('conference')`
     * is what drops a soft-deleted conference: the relation subquery carries
     * Conference's SoftDeletes scope.
     *
     * @return Builder<ConferenceReviewer>
     */
    private function affiliationsQuery(): Builder
    {
        return ConferenceReviewer::query()
            ->where('user_id', $this->reviewer()->getKey())
            ->active()
            ->whereHas('conference')
            ->with('conference.organization')
            ->orderBy('id');
    }

    private function editAffiliationAction(): Action
    {
        return Action::make('editAffiliation')
            ->label(__('reviewer.affiliation.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalHeading(fn (ConferenceReviewer $record): string => __('reviewer.affiliation.edit_heading', [
                'conference' => (string) ($record->conference->name ?? ''),
            ]))
            ->modalDescription(__('reviewer.affiliation.edit_description'))
            ->fillForm(fn (ConferenceReviewer $record): array => ['affiliation' => $record->affiliation])
            ->schema([
                // The organizer's invite form declares the same field the same
                // way (ConferenceReviewers::getHeaderActions(), `invite`).
                TextInput::make('affiliation')
                    ->label(__('reviewer.fields.affiliation'))
                    ->maxLength(UpdateReviewerAffiliation::MAX_LENGTH)
                    ->helperText(__('reviewer.affiliation.help')),
            ])
            ->visible(fn (ConferenceReviewer $record): bool => Gate::allows('updateAffiliation', $record))
            ->action(function (ConferenceReviewer $record, array $data, UpdateReviewerAffiliation $update): void {
                // No Gate::authorize() here: the action asks the policy itself,
                // and a refusal is a sentence, not a 403 modal.
                try {
                    $update->handle(
                        $record,
                        $data['affiliation'] === null ? null : (string) $data['affiliation'],
                        $this->reviewer(),
                    );
                } catch (MemberChangeRefused $exception) {
                    Notification::make()->danger()
                        ->title(__('reviewer.notices.refused'))
                        ->body(e($exception->getMessage()))
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->success()->title(__('reviewer.affiliation.saved'))->send();
            });
    }

    private function reviewer(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
```

- [ ] **Step 7: Name it in the panel**

`app/Providers/Filament/ReviewerPanelProvider.php`, two edits. Neither touches the `->viteTheme()` line Task 1 added.

```php
use App\Filament\Reviewer\Pages\Dashboard;
```

becomes

```php
use App\Filament\Reviewer\Pages\Dashboard;
use App\Filament\Reviewer\Pages\EditProfile;
```

and

```php
            ->profile()
```

becomes

```php
            // Filament's profile page plus the reviewer's own affiliation per
            // conference (Plan 7 Task 6). The organizer and admin panels keep
            // Filament's page unchanged.
            ->profile(EditProfile::class)
```

- [ ] **Step 8: Run the tests again**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact --filter='ReviewerAffiliationTest|LanguageCoverageTest' > /tmp/t-task-6.log 2>&1; echo "rc=$?"; tail -5 /tmp/t-task-6.log
```

Expected: `rc=0`. The twenty new cases pass, and every `LanguageCoverageTest` case passes with them.

- [ ] **Step 9: Pint and Larastan**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint --test > /tmp/pint-task-6.log 2>&1; echo "pint rc=$?"; tail -5 /tmp/pint-task-6.log && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan-task-6.log 2>&1; echo "stan rc=$?"; tail -5 /tmp/stan-task-6.log
```

Expected: both `rc=0`.
- If Pint reports `ordered_imports` on the panel test, the file was retyped rather than copied. Pint's Laravel preset puts the `use function` block **before** `use Spatie\…`, exactly as `tests/Feature/Organizer/ConferenceReviewersTest.php:27-31` has it.
- Larastan does not analyse `tests` (Plan 6 fact 1).

- [ ] **Step 10: The whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact > /tmp/all-task-6.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all-task-6.log
```

Expected: `rc=0`, and the count Task 5 ended on **plus 20**. On the prototype, built on bare `main` with a stand-in for Task 2's `$plan7Sources` case, the count was 1235 passed, 1 skipped: 1214 on `main`, plus these 20, plus the one stand-in case.

- [ ] **Step 11: Commit**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test --compact > /tmp/all-task-6.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add app/Actions/Reviewers/UpdateReviewerAffiliation.php app/Filament/Reviewer/Pages/EditProfile.php \
  app/Policies/ConferenceReviewerPolicy.php app/Providers/Filament/ReviewerPanelProvider.php lang/en/reviewer.php \
  tests/Unit/UpdateReviewerAffiliationTest.php tests/Feature/Reviewer/ReviewerAffiliationTest.php \
  tests/Feature/LanguageCoverageTest.php && git status --short --untracked-files=no
```

Expected: `all rc=0 - the suite gates this commit`, then eight staged paths: four `A` and four `M`. Commit them with the session's Co-Authored-By trailer and this message:

```text
feat(reviewers): a reviewer corrects their own affiliation, per conference

The reviewer panel's profile page gains one section: every conference the
person actively reviews, each with its own affiliation and a Change action.
The write goes through UpdateReviewerAffiliation, which asks a new
ConferenceReviewerPolicy::updateAffiliation ability - the reviewer's own row,
while active; not update(), which admits every organizer - trims, caps at the
invite form's 255 and logs reviewer.affiliation_changed with the reviewer as
causer. Auto-assign reads the new value on its next plan; assignments already
made are left exactly where they are.
```

---


### Task 7: MySQL, the browser suite, the backlog, the final gate and the pull request

No application code. This task proves on the production driver what Tasks 1-6 proved on SQLite, runs the one browser case this plan adds, records what the plan closed and what it raised in `docs/superpowers/plans/backlog.md`, and opens the pull request.

**Files:**
- Modify: `docs/superpowers/plans/backlog.md`
- No application code changes.

- [ ] **Step 1: Run the whole suite against MySQL, not only SQLite**

Six things in this plan behave differently on MySQL, and they are why this run is the gate rather than a formality:

1. **Task 5 writes a notification's id into `email_logs.ulid`, which is `char(26)` on MySQL** (`$table->ulid('ulid')->unique()`). A raw notification UUID is 36 characters, and MySQL in strict mode refuses it where SQLite stores it silently. `EmailLog::ulidForNotification()` re-encodes the UUID as a 26-character ULID, and this run is the only place on the owner's machine that proves it. Task 5's final design was not run on MySQL while it was being written (the prototype container lost its Docker daemon after its first design passed on MySQL 8.4); the plan's review then ran it there, and `FailedNotificationLogTest` and `QueuedMailPayloadTest` passed on MySQL 8.4.
2. **Task 6's 255-character limit is enforced by the column only here.** `conference_reviewers.affiliation` is `varchar(255)`. MySQL in strict mode refuses a 256th character, where SQLite stores it silently. `UpdateReviewerAffiliation` refuses it first, with a sentence, and the 255/256 boundary case in `tests/Unit/UpdateReviewerAffiliationTest.php` proves the action answers before the database would.
3. **Task 3's logo deletion runs after the commit** (`DB::afterCommit`), and its rollback case proves a failed write leaves the old file in place. The callback semantics do not depend on the driver, but InnoDB is what production runs, so this is where a transaction that commits on one driver and not the other would show.
4. **Task 4 sends a platform admin through `ChangeMemberRole::handle()` and `RemoveMember::handle()`, which re-count owners under `lockForUpdate()` inside their transactions** (`ChangeMemberRole.php:76`, `RemoveMember.php:68`; the pivot is `organization_members`). SQLite's grammar compiles the lock clause to nothing and InnoDB runs it, so the hand-over, last-owner and remove cases run the production locking query only here. The hand-over is two separate role changes, each its own one-row transaction, and this suite is sequential on one connection: it proves the query, not concurrency, and no lock-order deadlock is claimed.
5. **Task 5's encrypted notification payload is read back from `jobs.payload`,** a `longtext`. The "the reset token is not in the queue" case asserts what MySQL actually stores.
6. **MySQL's `json` columns reorder object keys** (shorter key first), where SQLite keeps the order written. Any test that compares a decoded `json` attribute with `toBe()` on an associative array, or prints one in key order, must not depend on the order written. The plan's review found two that did, and both are fixed above: the submission export's extra-answers cell (Task 2's `ExtractedEnglishTest` and `LanguageCoverageTest` write `first_time` before `needs_projector`) and `activity_log.properties` (Task 6's `UpdateReviewerAffiliationTest` compares with `toEqual()`).

```bash
cd /c/Users/ahmed/Documents/CASS && docker compose -f docker-compose.dev.yml up -d && sleep 15 && npm run build > /tmp/build-task-7.log 2>&1 && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php artisan test > /tmp/mysql-task-7.log 2>&1; echo "mysql rc=$?"; tail -4 /tmp/mysql-task-7.log
```

Expected: `mysql rc=0` and the same passed count as Task 6 Step 10's SQLite run (Step 4 below repeats it). A failure here that SQLite never showed is one of the six differences above; read it before touching the test.

- [ ] **Step 2: Run the browser suite**

Task 1 appended one case to `tests/Browser/CspTest.php`: on `/org/login`, a `bg-amber-50` element computes a background under the **enforced** CSP. That proves the theme loads under the nonce and its utilities apply. It needs Playwright's Chromium once per machine.

```bash
cd /c/Users/ahmed/Documents/CASS && npx playwright install chromium > /tmp/pw-task-7.log 2>&1; echo "playwright rc=$?" && \
./vendor/bin/pest --configuration=phpunit.browser.xml > /tmp/browser-task-7.log 2>&1; echo "browser rc=$?"; tail -5 /tmp/browser-task-7.log
```

Expected: `browser rc=0`. CI's `browser` job runs the same command.

- [ ] **Step 3: Confirm the schedule, the routes and the build this plan added**

```bash
cd /c/Users/ahmed/Documents/CASS && CACHE_STORE=array php artisan schedule:list 2>&1 | grep -E 'cass:sweep-uploads' ; \
php artisan route:list --path=admin/organizations 2>/dev/null | grep -c edit ; \
grep -c '"src": "resources/css/filament/theme.css"' public/build/manifest.json ; \
test -e public/images/icons/badge.svg && echo "badge.svg STILL THERE" || echo "badge.svg gone"
```

Expected: one `cass:sweep-uploads` line with an hourly expression (`0 * * * *`); `1` (the admin `edit` route); `1` (the theme is in the manifest: Vite writes each entry's path twice, as its key and as its `src`, so the grep counts the `src` line); `badge.svg gone`. `CACHE_STORE=array` for the reason Task 5 Step 19 gives: `schedule:list` asks the cache about `withoutOverlapping()` mutexes, and a local `.env` may point the cache at a database that is not running.

- [ ] **Step 4: The final gate, on SQLite**

```bash
cd /c/Users/ahmed/Documents/CASS && npm run build > /tmp/build-final.log 2>&1; echo "build rc=$?" && \
./vendor/bin/pint --test > /tmp/pint-final.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan-final.log 2>&1; echo "phpstan rc=$?" && \
php artisan test > /tmp/t-final.log 2>&1; echo "tests rc=$?"; tail -4 /tmp/t-final.log
```

Expected: every `rc=0`, and **baseline + 124 passed, 1 skipped** (1340 on a 1216 baseline). Per task: Task 1 +13, Task 2 +45, Tasks 3-4 +33, Task 5 +13, Task 6 +20. Task 1's fourteenth test is the browser case in Step 2.

- [ ] **Step 5: Update `docs/superpowers/plans/backlog.md`**

**Delete these entries.** Each one is done, and the task that did it is named here so a reviewer can check rather than trust:

- "Delete replaced logos from the `branding` disk" (Organizer features): Task 3, Steps 4 and 11. Replaced, cleared and purged logos all go through one class.
- "Organizer panel theme: `php artisan make:filament-theme organizer`, …" (Organizer features): Task 1. One theme serves all three panels.
- "Reject or downscale organization logos above 16 MP at upload" (Organizer features): Task 3. Rejected, not downscaled (Task 3, decision 6).
- "`public/images/icons/badge.svg` (4,098 bytes) is referenced by nothing." (Public site polish), and its Launch-section copy, "**`public/images/icons/badge.svg` is referenced by nothing.**": Task 5, Part D, deleted in its own commit.
- "**A failed *notification* stays at `queued` in `email_logs`.**" (Submissions), and its Launch-section copy, "**A failed *notification* still stays at `queued` in `email_logs`.**": Task 5, Part A.
- "**Livewire temporary uploads linger.**" (Submissions), and its Launch-section copy, "**Livewire's temporary uploads still linger.**": Task 5, Part C.
- "**Spec section 10 is now half done.**" (Submissions): **stale before this plan started.** Plan 6 Task 12 swept every view it names, and the replacement language entry below covers the rest.
- "**A reviewer cannot edit their own affiliation.**" (Reviewing): Task 6.
- "**The production image builds its CSS without `app/Livewire` in scope.**" (Launch): Task 1. The `assets` stage copies `app/Filament`, `app/Livewire` and `vendor/filament`, and `tests/Unit/DockerAssetsStageTest.php` plus five lines in CI's smoke job guard it.
- "**Spec section 4's two platform-admin write cells have no screen.**" (Launch): Tasks 3 and 4.

**Replace both language entries** ("**The language sweep still stops at the file boundary.**" in Decisions, and the same title in Launch) **with this one,** in the Launch section:

> - **The language sweep now covers every file the backlog named; it does not cover the application.** Plan 7 converted every enum label but `Decision`'s, both export headings, the publishing blockers and the three admin tables Plans 1-2 wrote, and `LanguageCoverageTest` now reads PHP for English as well as Blade (`$plan7PhpProse`). Run over all of `app/`, that sweep still finds: the organizer panel's Plan 2-3 classes (210 literals in 15 files under `app/Filament/Organizer/`; Plan 7 Task 3 converted `EditOrganizationProfile`); **what a visitor or author reads** — `SubmitAbstract`, `UpdateSubmission`, `WithdrawSubmission`, `SaveSubmissionDraft`, `SendSubmissionStatusLink`, `SubmissionFileRejected`, and the titles and throttle messages of `ContactForm`, `RegisterOrganization` and `AcceptInvitation` (46 literals in 9 files, reaching the public form through `SubmissionForm` and `SubmissionStatus`); the admin infolists, `EmailLogResource`'s navigation label, the panel brand names and the purge modal's raw table names (37); five organizer refusals and `CreateDefaultReviewForm`'s nine default questions (15); and the two notifications not on the template system, `NewSubmissionNotice` and `OrganizationRegistered` (16). Do the visitor-facing nine first — they are on the public site, where Arabic is promised. `Decision::getLabel()` still moves with the bilingual templates of spec section 14, and `LanguageCoverageTest` pins it until then.

**Rewrite one clause of the Content-Security-Policy entry** (Security and platform, first bullet). Replace:

> `style-src-attr` carries `'unsafe-inline'` because a nonce never reaches a style *attribute*, and about forty-five of them are inline — including the organization branding variables and the brand lock-up on all three panels; that one gets stricter for free the day the organizer theme lands.

with:

> `style-src-attr` carries `'unsafe-inline'` because a nonce never reaches a style *attribute*. The panel theme (Plan 7) did not make it stricter, and cannot: Filament itself writes a `style` attribute round the brand logo on every panel page, and its layout's `x-bind:style`, which Alpine applies with `setAttribute('style', …)`, is what makes the content of every authenticated panel page visible. The organization's branding variables on the public conference pages, and data-valued attributes such as the short-link sparkline's bar heights, are attributes too. What the theme changed is that a panel view no longer needs an inline style for layout.

**Append one sentence to both pagination entries** ("Add the pagination views to the Tailwind `@source` list…" in Public site polish, and "**The pagination views are still not in the Tailwind `@source` list**" in Launch):

> Since Plan 7, `resources/css/app.css` imports Tailwind with `source(none)`, so its `@source` list is the only thing Tailwind scans. The instruction matters more than it did, not less.

**Append to the Dependencies entry "The next Filament bump is red by design."** — header note 2 sends the Filament 5.9 pull request to Task 6's drift pin, and this entry is what that pull request's author reads:

> Since Plan 7 it can also fail `tests/Feature/Reviewer/ReviewerAffiliationTest.php`'s `content()` drift pin, if Filament changed `EditProfile::content()`. Restate the method in `app/Filament/Reviewer/Pages/EditProfile.php` and update the pinned string in the same commit.

**Add these entries.** The first goes in Organizer features:

> - **Panel views still carry 69 inline `style` attributes.** Plan 7 gave the panels a theme, so utilities work there now, but it restyled only the organizer dashboard, on purpose. Remaining: conference-ranking (14), custom-domain-records (14), short-link (11), assignments (6), reviewers (1), email-templates (1), members (1), reviewer dashboard (7), review-submission (11), admin purge-counts (3). Convert each one when a task next touches its view. Two stay inline whatever happens: the brand lock-up, which both stylesheets render and which disagree about `dark:`, and the sparkline's data-valued bar heights.

Then a new section at the end of the file:

> ## Open questions for the owner (raised by Plan 7)
>
> Each one shipped today's behaviour. Record the answer here when it is made.
>
> - **May a platform admin rename an organization's slug?** It is read-only on both the organizer's and the admin's profile page, because it is the `{organization}` in every `/c/…` URL and in every printed poster's QR code.
> - **Should the platform admin be able to invite members or resend an invitation?** Not offered: `AcceptInvitation` refuses an invitation whose inviter holds no role in the organization, so an admin-made link would fail when the invitee clicks it.
> - **Should an organization's owners be emailed when a platform admin edits their organization or its members?** Nothing is sent. Every change is in the activity log, with the admin as causer.
> - **When a reviewer's new affiliation creates a conflict on an abstract already assigned to them, should the organizer be told or the assignment flagged?** Nothing is unassigned, and the reviewer's modal asks them to tell the organizers.
> - **Should organizers be able to edit an active reviewer's affiliation?** It is own-row only (`ConferenceReviewerPolicy::updateAffiliation()`).
> - **Should `cass:health` warn before the private volume fills, and at what free-space threshold?** Nothing measures free space today. `cass:health` only checks that the volume can be written, and `cass:sweep-uploads` (Plan 7) removes day-old temporary uploads hourly.

```bash
cd /c/Users/ahmed/Documents/CASS && grep -c 'Open questions for the owner (raised by Plan 7)' docs/superpowers/plans/backlog.md; \
grep -cE 'Organizer panel theme: `php artisan make:filament-theme|badge.svg` \(4,098|A reviewer cannot edit their own affiliation|have no screen' docs/superpowers/plans/backlog.md
```

Expected: `1`, then `0`.

Commit, with the session's Co-Authored-By trailer in place of the second `-m`'s placeholder:

```bash
cd /c/Users/ahmed/Documents/CASS && git add docs/superpowers/plans/backlog.md && \
git commit -q -m "docs: record what plan 7 closed, what it found and what it asks the owner" -m "<the session's Co-Authored-By trailer>" && git log --oneline -1
```

- [ ] **Step 6: Push and open the pull request**

```bash
cd /c/Users/ahmed/Documents/CASS && git push -u origin plan-7-post-launch && \
gh pr create --base main --head plan-7-post-launch --title "Plan 7: the panel theme, platform-admin writes, failed-notification logging, reviewer affiliation and the backlog's language sweep" --body-file /tmp/pr-plan-7.md
```

Write `/tmp/pr-plan-7.md` first. Its last line is a placeholder: put the session's pull-request attribution there, as every commit's last line carries its trailer.

````markdown
## The panel theme (Task 1)
One Vite-built theme for all three panels (`resources/css/filament/theme.css`, `->viteTheme()`), written from Filament's stub without running `make:filament-theme`. The public stylesheet uses `source(none)`, so a checkout and the image compile the same CSS. The Dockerfile builds `vendor` before `assets`, and `tests/Unit/DockerAssetsStageTest.php` plus the smoke job guard it. From this release on, `npm run build` must run before `php artisan test`, with `npm run dev` stopped; CLAUDE.md's test command and the runbook's "Every release" check now say so.

## Platform-admin writes (Tasks 3 and 4)
The admin can edit an organization's profile, branding and custom domain through the same action and form as the organizer (`UpdateOrganizationProfile`, `OrganizationProfileForm`), and can change a member's role, remove a member and withdraw an invitation through the existing actions, with every guard kept. Replaced, cleared and purged logos are deleted from the `branding` disk after the write commits, never while another organization's row holds the path and never when the stored path is not spelled as Filament writes one. Logos above 16 MP are refused at upload.

## Notifications and uploads (Task 5)
Every queued notification travels in `SendQueuedNotificationsWithLog`: a final failure marks its `email_logs` row `failed`, and the payload is encrypted. `cass:sweep-uploads` runs hourly. `badge.svg` is gone.

## Reviewer affiliation (Task 6)
A reviewer corrects their own affiliation, per conference, from their profile page (`UpdateReviewerAffiliation`, `ConferenceReviewerPolicy::updateAffiliation()`). Existing assignments are untouched.

## The backlog's language sweep (Task 2)
Enum labels (all but `Decision`'s), both export heading rows, three admin tables and the publishing blockers move to `lang/en`. English pins written against the old code pass on both sides. `LanguageCoverageTest` now reads PHP. The approve, reject and purge notifications now escape the organization's name, and the admin conference purge escapes the conference name (Task 3), as `ConferenceStatusActions` already did.

## Verification
SQLite and MySQL suites, the browser suite, Pint and Larastan, all `rc=0` (Task 7, Steps 1-4). Every new test that drives a change was shown failing first. The English pins and the guard cases that pin existing behaviour pass on both sides by design (Task 2, decision 9).

## Before this is deployed
1. **No migration.** This release changes no schema, so there is no window between the container going live and `migrate`.
2. **Jobs already in the queue keep working.** Anything queued before the deploy was serialized as Laravel's own `SendQueuedNotifications`, unencrypted. The new worker reads both shapes. Only jobs queued after the deploy are encrypted and marked on failure. A notification first tried before the deploy keeps that try's `queued` row even when the new worker delivers or fails it, and so does every notification that failed before the release. Judge those by `queue:failed` and the mailbox, not by the email log.
3. **After the deploy:** `php artisan schedule:list` in the app container shows `cass:sweep-uploads` hourly, and CI's smoke job has already proved the theme is served under the nonce.
4. **The verification-email hotfix (`fe7f949`) is not in this pull request.** It shipped before Plan 7. If any registration's verification email is still in `failed_jobs` from before it (failed jobs are pruned after 720 hours), list them with `C=$(cass_container app); sudo docker exec "$C" su-exec app php artisan queue:failed`, where the class column reads `App\Notifications\QueuedVerifyEmail`, and retry those rows by id: `sudo docker exec "$C" su-exec app php artisan queue:retry <uuid> [<uuid> ...]`. `queue:retry` with no id retries nothing, and `queue:retry all` re-sends every other failed job too.
5. **Audit logo paths before deploying.** On the production host, with the `cass_container` helper from the top of `docs/runbooks/deploy-production.md`:

   ```bash
   C=$(cass_container mysql)
   sudo docker exec -i "$C" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot cass' <<'SQL'
   SELECT id, slug, logo_path FROM organizations WHERE logo_path IS NOT NULL AND (logo_path NOT REGEXP '^logos/[A-Za-z0-9]+(\\.[A-Za-z0-9]*)?$' OR logo_path REGEXP '[[:cntrl:]]');
   SELECT logo_path, COUNT(*) FROM organizations WHERE logo_path IS NOT NULL GROUP BY logo_path HAVING COUNT(*) > 1;
   SQL
   ```

   The first query must return no rows: null the `logo_path` of any row it returns, by hand, before deploying. The second is for information: the code keeps a file while any row holds its path. If a path is shared, null it only on the organization that did not upload it; its activity log shows which one did. From this release a replaced, cleared or purged logo is deleted from the public disk, and the code never hands the disk a path spelled any other way (Task 3, decision 5).
6. **Rolling back past this release strands queued notifications.** Every queued notification now travels in `App\Notifications\SendQueuedNotificationsWithLog`, which an older image does not have: any notification still in `jobs` fails three times with "Job is incomplete class" and lands in `failed_jobs`, its email-log row stays `queued`, and a job that failed under Plan 7 cannot be retried on the old image either. If you can, wait until `queue:monitor database:default` prints `[0] OK` before rolling back; otherwise `queue:retry` those jobs by id once you have rolled forward again. The runbook's "Rollback" section says the same.

<the session's pull-request attribution>
````

Expected: the pull request URL. CI runs `test`, `scripts`, `browser`, `image` and `smoke`; this plan expects all five green.

