# CASS v2 Plan 4: Members, Reviewers, the Reviewer Panel, Reviews, Assignment and Reminders

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An organization stops being one person. An owner invites colleagues by email and role; the invitee follows a hashed-token link at `/invite/{token}`, signs in or creates an account in four fields, and lands in the organizer panel. The same link, the same page and the same token machinery carry reviewer invitations: an organizer invites reviewers to one conference by name and email, singly or as a pasted list, and each accepted reviewer gains a third Filament panel at `/review` with its own login. There a reviewer sees their conferences and progress, a queue that is either every submitted abstract (open pool) or their own assignments, and a review page that shows the abstract and its files with the authors hidden when the conference is blind — and the conference's own review form, which they save as a draft and then submit. The first submitted review locks the form so questions may only be appended, and moves the abstract to `under_review`. In assigned mode the organizer assigns reviewers by hand or runs a balanced auto-assign that skips conflicts and shows the plan before saving. The scheduler reminds reviewers with outstanding work at 7, 3 and 1 days before the review deadline and once after it, each threshold at most once per reviewer per conference, and the organizer can send one by hand no more than twice a day. Finally, `Closed -> Reviewing` becomes a real transition with a blocker list, and both panels carry a link to the other for anyone who is both an organizer and a reviewer.

**Architecture:** Single Laravel app, now three Filament panels. Every use case is an `app/Actions` class with a documented `handle()` signature; the panels and the one public Livewire component validate and delegate. Two invitation tables — `organization_invitations` and `reviewer_invitations` — share one PHP interface (`App\Contracts\Invitation`), one token shape (32 random bytes, hex, stored as SHA-256), one public route and one accept action, but keep their own real foreign keys and their own payloads. Reviewer visibility is decided in exactly one place, `App\Support\Reviews\ReviewerScope`, which the queue resource, the review page, the file policy and the reminder command all call, so "assigned or pool only" cannot mean four different things. Blind review is decided in exactly one place too, `Conference::hidesAuthorsFrom()`, and it reaches the download route through the signature itself: a reviewer's file link carries `blind=1` *inside* the signed URL, so the `Content-Disposition` name cannot be un-blinded by editing the query string.

**Tech Stack:** PHP 8.4, Laravel 13.31, Filament 5.8.1, Livewire 4.4.4, Carbon 3.13.2, Tailwind 4.3 (Vite, public pages only), Pest 5.1.4 with the Laravel, Livewire and Browser plugins, Larastan level 6, Pint (strict types), spatie/laravel-activitylog 5.1, openspout/openspout 4.32, MySQL 8.4 in CI and production, SQLite in-memory locally.

**Spec:** `docs/superpowers/specs/2026-09-10-cass-v2-design.md` sections 5.4 (reviewer invitation and review) and 5.5 (assignment); the "Manage organization members" row of section 4, deferred from Plans 1 and 2 (`docs/superpowers/plans/backlog.md`); the `/review/...` and `/invite/{token}` rows of section 6 and the "switch link in the panel header" sentence below that table; the reviewer permission cells of section 4 ("View submissions and files — assigned or pool only", "Submit reviews"); the three reviewer template keys of 5.9 (`reviewer_invitation`, `reviewer_reminder`, `reviewer_overdue`), whose defaults and editor already exist from Plan 3 and which this plan *sends*; the review-form lock rule of section 3; the `Closed -> Reviewing` transition; and sections 8 (indexes and foreign keys), 9 (tokens, rate limits, policies, audit log), 10 (language files, timezones) and 12 (unit, feature and panel tests) as they apply.

**Environment facts for every command below**

- Repo root: `C:\Users\ahmed\Documents\CASS` (Git Bash path `/c/Users/ahmed/Documents/CASS`). All commands are Git Bash.
- Composer: run `php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar <args>`. Do **not** use the `composer.bat` wrapper: it passes through cmd.exe and silently strips `^` from version constraints. **This plan installs nothing**, so the only composer command below is the `show` in Task 1 Step 1.
- PHP 8.4.23 at `C:\Users\ahmed\AppData\Local\php84\php.exe`. **No `sodium`, no `exif`** — tokens are `bin2hex(random_bytes(32))` hashed with `hash('sha256', …)`, never `sodium_*`. `sockets` was enabled by Plan 3 Task 13 (line 944 of `php.ini`); leave it on or `composer install` fails its platform check.
- `php -` (reading a script from stdin) does not work on this machine. Every ad-hoc PHP snippet below is written to a file first and run as `php <file>`.
- Node 24.15.0, npm 11.12.1, Docker 29 (daemon running), git, `gh` (logged in as `ahmedsk2`).
- **Baseline before this plan:** branch `plan-4-reviewers`, created from `main` after Plan 3 merges. **"Baseline" throughout this plan means one thing: the number of passing tests `php artisan test` reports at the end of Plan 3, on `main`, before a single line of Plan 4 exists.** That number is **not** hardcoded anywhere below, because Plan 3 was still being finished while this was drafted — **Task 1 Step 1 runs the suite on the freshly branched tree and records the real number**, and every "Expected: `baseline + N`" line below is measured from what Step 1 wrote down, not from any figure in this paragraph. As a sanity check only, `grep -c` over `it(`/`test(` on the Plan 3 tree gives roughly 442 blocks, and `php artisan test` reported 450 at the end of Plan 3's own Task 14; if Step 1 prints something wildly different from that, the branch has the wrong parent.
- Commit after every task with the trailer `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`. **No task may commit with a failing test.**
- **Verify test runs by exit code**, never by reading piped output: `php artisan test > /tmp/t.log 2>&1; echo "rc=$?"; tail -5 /tmp/t.log`.
- **The test *counts* in every "Expected" line below are approximate.** They were computed by counting `it()` blocks and dataset rows while writing the plan, and a dataset that grows by one case moves them. The `rc=0` in the same line is the gate; a count that is off by a few is not a failure, a count that is off by *dozens* means a file did not run.

**Packages added by this plan**

**None.** Everything here is Laravel, Filament and Livewire that is already installed:

| Thing that might look like it needs a package | What is used instead | Why |
|---|---|---|
| A third Filament panel | `Filament\PanelProvider` + one line in `bootstrap/providers.php` | Panels are a first-class Filament concept; `filament/filament` v5.8.1 is already a direct dependency. |
| Invitation tokens | `App\Support\Tokens\InvitationToken` (18 lines) | Identical in shape to Plan 3's `SubmissionToken`, which is already the house pattern and already tested. A package would add a migration, a model and a notification this app does not want, and would not be hashed the way spec section 9 requires. |
| Team / membership management | `organization_members` (Plan 1) + one page | The pivot, its roles and `notify_on_submission` already exist and are already used by Plan 3's `SubmitAbstract::notifiableMembers()`. |
| Scheduled reminders | `Illuminate\Support\Facades\Schedule` in `routes/console.php` + one Artisan command | `schedule:work` already runs under supervisord (`docker/supervisord.conf:38-46`). |
| "Balanced" assignment | 70 lines in `App\Actions\Reviews\AutoAssignReviewers` | The rule is spelled out in spec 5.5 and is a sort plus a greedy pass; a solver package would make a deterministic test harder, not easier. |

**What this plan does NOT build (kept honest):**

- **Scoring, ranking and decisions** (spec 5.6) stay **Plan 5**. Plan 4 stores answers and nothing else: no normalisation, no weighted mean, no spread, no ranking table, no `SubmissionDecision`, and none of the four `decision_*` template keys are sent. The schema carries everything Plan 5 needs — `review_questions.weight`, `scale_min`, `scale_max` and `options[].score` already exist from Plan 2, and `review_answers` stores a typed value per question — so Plan 5 adds `App\Support\Scoring` and reads, it does not migrate.
- **`Reviewing -> Decided`** is Plan 5. Plan 4 drives `Closed -> Reviewing` and stops there. `ConferenceStatus::Decided` and `SubmissionStatus::Accepted/Rejected/Waitlisted` stay untouched.
- **The decision letter on `/s/{token}`** is Plan 5. The author status page still renders its neutral block for `under_review`, which is the status Plan 4 starts writing — so that block finally has a real reason to exist, and Task 7 adds the test that proves an author sees something sane the moment a review lands.
- **Reviewer bidding and declared conflicts of interest** are spec section 14 (v2 backlog). Plan 4's conflicts are computed from data the app already holds (author emails, author affiliations, reviewer email, reviewer affiliation); a reviewer cannot declare one, and an organizer cannot record one by hand beyond simply not assigning.
- **Reviewer expertise tags, reviewer-side "decline this abstract", and per-conference reviewer profiles** are not built. A reviewer who cannot review tells the organizer, who removes them — which is what `reviewer_reminder`'s platform default already tells them to do.
- **Certificates** (presenter or reviewer) are spec section 14.
- **A second active review form per conference** is spec section 14. `Conference::reviewForm()` is still `hasOne(...)->where('is_active', true)` and `Review::review_form_id` records which form a review was answered against, which is what makes a later multi-form world migratable.
- **A platform-admin view of reviews or reviewers.** `ReviewPolicy::before()` and friends answer `true` for `is_platform_admin`, but there is no admin screen behind them, exactly as Plan 3 left submissions. **Plan 6** adds the read-only admin resources — and has to refuse deletion *in `before()` or in the resource*, because Laravel returns a non-null `before()` result without ever calling the ability (`Gate::resolvePolicyCallback`), so the explicit `deleteAny(): false` on all five policies does not beat it.
- **A platform-admin view of organization members.** `OrganizationMemberPolicy::before()` and `OrganizationInvitationPolicy::before()` answer `true` for `is_platform_admin`, but the Members page lives in the membership-gated organizer panel (`canAccessPanel('organizer')` needs `organizations()->exists()`), so spec section 4's platform-admin cell for "Manage organization members" has no screen behind it either. **Plan 6**, alongside the read-only admin resources.
- **Custom domains** (5.8), **legacy import** (5.10) and **launch hardening** (CSP, image digest pinning, the hard purge — which now also has to delete reviews, answers and assignments) stay **Plan 6**.
- **An organizer panel theme.** Still the Plan 2 backlog item: Tailwind utility classes do nothing inside a Filament panel, so every new panel view here uses `<x-filament::section>` and inline styles, and the new reviewer panel is deliberately built the same way rather than shipping a theme only it would use.
- **Queue/worker changes.** Every email this plan sends goes through Plan 3's `SendTemplatedEmail` or a queued `Notification`, both of which the existing worker and the existing `email_logs` listener already handle.

---

## File structure created or changed by this plan

```
app/
  Actions/
    Conferences/
      StartReviewing.php                 # blockers() + handle(), Closed -> Reviewing
    Invitations/
      AcceptInvitation.php               # the one accept path for both invitation kinds
    Organizations/
      ChangeMemberRole.php
      InviteMember.php                   # invite, re-invite and resend are one method
      RemoveMember.php
      RevokeInvitation.php
      SetSubmissionNotifications.php     # the notify_on_submission toggle
    Reviewers/
      InviteReviewer.php                 # one invitation + reviewer_invitation email;
                                         #   resend is the same method, by design
      InviteReviewerList.php             # pasted list -> per-line outcome report
      RemoveConferenceReviewer.php
      RevokeReviewerInvitation.php
    Reviews/
      AssignReviewers.php                # set the reviewer set of one submission
      AutoAssignReviewers.php            # plan() + apply(), balanced, conflict-aware
      ReopenReview.php
      SaveReviewDraft.php
      SendReviewerReminders.php          # threshold selection + sending, for one conference
      SubmitReview.php                   # validates, locks the form, moves to under_review
                                         #   (no UnassignReviewer: deselecting in the
                                         #   assign action is the one unassign path)
  Console/
    Commands/
      SendReviewerRemindersCommand.php   # cass:reviewer-reminders, hourly
  Contracts/
    Invitation.php                       # what /invite/{token} needs of an invitation
  Enums/
    InvitationStatus.php                 # pending|accepted|expired|revoked (derived, not stored)
    ReminderThreshold.php                # days_7|days_3|days_1|overdue
    ReviewStatus.php                     # draft|submitted
    ReviewerStatus.php                   # active|removed
  Exceptions/
    InvitationNotAcceptable.php
    MemberChangeRefused.php
    ReviewNotAcceptable.php
  Filament/
    Organizer/
      Pages/
        Members.php                      # members + pending invitations, array-backed
      Resources/Conferences/
        ConferenceResource.php           (modified: two new pages)
        Pages/ConferenceAssignments.php  # per-submission reviewer pickers + auto-assign
        Pages/ConferenceReviewers.php    # reviewers + pending invitations
        RelationManagers/CustomFieldsRelationManager.php
                                         (modified: the hide_from_reviewers toggle)
        Schemas/ConferenceInfolist.php   (modified: review progress section)
        Tables/ConferenceStatusActions.php (modified: reviewers, assignments, remindReviewers,
                                            startReviewing)
    Reviewer/
      Pages/Dashboard.php                # conferences + progress
      Resources/Submissions/
        Pages/ListSubmissions.php        # the queue
        Pages/ReviewSubmission.php       # abstract + files + the review form
        SubmissionResource.php
        Tables/QueueTable.php
  Livewire/Public/
    AcceptInvitation.php                 # /invite/{token}
  Models/
    Conference.php                       (modified: reviewer relations, progress, blind rule,
                                          acceptsReviewWrites())
    ConferenceReviewer.php
    CustomField.php                      (modified: hide_from_reviewers)
    Organization.php                     (modified: invitations())
    OrganizationInvitation.php
    OrganizationMember.php               (modified: organization() and user())
    Review.php
    ReviewAnswer.php
    ReviewAssignment.php
    ReviewForm.php                       (modified: reviews(), lockIfUnlocked())
    ReviewQuestion.php                   (modified: optionKey(), optionScore())
    ReviewerInvitation.php
    ReviewerReminder.php
    Submission.php                       (modified: reviews(), reviewAssignments())
    SubmissionFile.php                   (modified: blind name + blind signed URL)
    User.php                             (modified: canAccessPanel('reviewer'), reviewer relations)
  Notifications/
    MemberInvitation.php                 # plain queued notification, not a spec 5.9 key
  Policies/
    ConferenceReviewerPolicy.php
    OrganizationInvitationPolicy.php
    OrganizationMemberPolicy.php         # who may change a role or remove a member
    ReviewAssignmentPolicy.php
    ReviewPolicy.php
    ReviewerInvitationPolicy.php
    SubmissionFilePolicy.php             (modified: the reviewer case)
    SubmissionPolicy.php                 (modified: the reviewer case)
  Providers/
    AppServiceProvider.php               (modified: two rate limiters)
    Filament/ReviewerPanelProvider.php
    Filament/OrganizerPanelProvider.php  (modified: the switch link)
  Support/
    Invitations/InvitationLookup.php     # plaintext token -> Invitation, across both tables
    Panels/PanelSwitch.php               # the one definition of each cross-panel link
    Reviews/AssignmentPlan.php           # readonly result of AutoAssignReviewers::plan()
    Reviews/ReminderSchedule.php         # which threshold is due for a conference, and when
    Reviews/ReviewFormSchema.php         # ReviewQuestion rows -> Filament components + rules
    Reviews/ReviewerList.php             # "Name <email>" lines -> parsed entries + errors
    Reviews/ReviewerScope.php            # THE definition of what a reviewer may see
    Tokens/InvitationToken.php
bootstrap/
  app.php                                (modified: ->withCommands([...]))
  providers.php                          (modified: ReviewerPanelProvider)
config/
  cass.php                               (modified: invitation, review and reminder knobs)
database/
  factories/{ConferenceReviewerFactory,OrganizationInvitationFactory,ReviewAnswerFactory,
             ReviewAssignmentFactory,ReviewFactory,ReviewerInvitationFactory,
             ReviewerReminderFactory}.php
  migrations/
    2026_09_12_000100_create_organization_invitations_table.php
    2026_09_12_000200_create_reviewer_invitations_table.php
    2026_09_12_000300_create_conference_reviewers_table.php
    2026_09_12_000400_create_review_assignments_table.php
    2026_09_12_000500_create_reviews_table.php
    2026_09_12_000600_create_review_answers_table.php
    2026_09_12_000700_create_reviewer_reminders_table.php
    2026_09_12_000800_add_reviewer_reminded_at_to_conferences_table.php
    2026_09_12_000900_add_hide_from_reviewers_to_custom_fields_table.php
lang/
  en/members.php                         # the members page and the whole /invite/{token} page
  en/reviewer.php                        # the reviewer panel's own views
resources/
  views/
    filament/organizer/pages/members.blade.php
    filament/organizer/resources/conferences/pages/assignments.blade.php
    filament/organizer/resources/conferences/pages/reviewers.blade.php
    filament/reviewer/pages/dashboard.blade.php
    filament/reviewer/resources/submissions/pages/review-submission.blade.php
    livewire/public/accept-invitation.blade.php
routes/
  console.php                            (modified: the hourly reminder run)
  web.php                                (modified: /invite/{token})
app/Http/Controllers/Public/SubmissionFileController.php (modified: the blind file name)
.env.example                             (modified)
.github/workflows/ci.yml                 (modified: two smoke assertions for the third panel)
docs/runbooks/deploy-production.md       (modified: reviewers, reminders, invitation tokens)
docs/superpowers/plans/backlog.md        (modified)
tests/
  Feature/
    Mail/EmailLogPipelineTest.php        (modified: the on-demand member invitation)
    Mail/TemplatedMailTest.php           (modified: the /invite/{64} subject redaction)
    Organizer/ChildPolicyBulkAbilitiesTest.php (modified: the five new policies)
    Organizer/ConferenceAssignmentsTest.php
    Organizer/ConferenceReviewersTest.php
    Organizer/ConferenceTransitionsTest.php    (modified: the Reviewing case)
    Organizer/MembersPageTest.php
    Organizer/ReviewQuestionsRelationManagerTest.php (modified: a real lock)
    Public/AcceptInvitationTest.php
    Reviewer/PanelAccessTest.php
    Reviewer/ReviewQueueTest.php
    Reviewer/ReviewSubmissionTest.php
    Reviewer/ReviewerDashboardTest.php
    ReviewerRemindersTest.php
    LanguageCoverageTest.php             (modified: the Plan 4 sources)
  Unit/
    AutoAssignReviewersTest.php
    InvitationModelTest.php
    InvitationTokenTest.php
    ReminderScheduleTest.php
    ReviewModelsTest.php
    ReviewerListTest.php
    ReviewerScopeTest.php
    StartReviewingTest.php
    SubmitReviewTest.php
  Pest.php                               (modified: bootReviewerPanel())
```

Files this plan *modifies* that are easy to miss in the tree above: `app/Models/ReviewForm.php` and `app/Models/ReviewQuestion.php` (Task 1 and Task 7), `tests/Pest.php` (Task 5), `app/Providers/AppServiceProvider.php` (Tasks 2 and 3), and `app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php`, which grows in Tasks 4, 8, 10 and 11 — four separate edits to one file, in that order.

---

## Facts verified in `vendor`, in `php -m` and in the repository before writing this plan

Read these before you doubt a name or a claim below. Every one was checked on this machine on 2026-09-11. Plan 3's facts 12, 17, 22, 23 and 24 still hold and are restated at the end.

1. **Installed versions, from `composer.lock`:** `laravel/framework` v13.31.0 (:2329), `filament/filament` v5.8.1 (:1279) with every `filament/*` sibling at the same version, `livewire/livewire` v4.4.4 (:3479), `pestphp/pest` v5.1.4 (:11096), `pestphp/pest-plugin-livewire` v5.0.0 (:11520), `pestphp/pest-plugin-laravel` v5.0.1 (:11443), `pestphp/pest-plugin-browser` v5.0.1 (:11359), `larastan/larastan` v3.11.0 (:10619), `laravel/pint` v1.32.0 (:10788), `spatie/laravel-activitylog` **5.1.1** (:5517), `openspout/openspout` v4.32.0 (:4208), `nesbot/carbon` **3.13.2** (:3728). `phpstan.neon` (there is no `.dist`) is level 6 over `app`, `config`, `database`, `routes`.

2. **`app/Console/Commands` does not exist, and a command class put there is NOT discovered.** `bootstrap/app.php` has no `->withCommands()`; `withRouting(commands: routes/console.php)` calls `withCommands([$path])` with that *file* (`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php:179-181`), and `withCommands()` (`:334-352`) partitions its argument into class-strings, files and directories — so only `routes/console.php` is registered as a command-route path and `$commandPaths` stays empty (`Illuminate/Foundation/Console/Kernel.php:78`, discovery at `:528-537`). **Task 10 therefore adds `->withCommands([SendReviewerRemindersCommand::class])` to `bootstrap/app.php` explicitly**, naming the class rather than the directory, so the registration is greppable and a second command in that folder has to be declared on purpose.

3. **`Schedule::call(...)->withoutOverlapping()` and `->onOneServer()` throw `LogicException` unless `->name('…')` was called first** (`Illuminate/Console/Scheduling/CallbackEvent.php:138-152` and `:156-169`; the mutex name is built from `sha1($this->description ?? '')` at `:186`). A `Schedule::command()` event has no such constraint. Task 10 schedules a *command*, so this is a trap avoided rather than one walked into — do not "simplify" it into a closure.

4. **Carbon 3.13.2's `diffInDays` is signed by default and returns a float:** `public function diffInDays($date = null, bool $absolute = false, bool $utc = false): float` (`vendor/nesbot/carbon/src/Carbon/Traits/Difference.php:254`). Reading the body (`:255-277`), `$a->diffInDays($b)` is **positive when `$b` is after `$a`**. Carbon 2 defaulted `$absolute` to true, so `App\Support\Reviews\ReminderSchedule` passes `absolute: false` explicitly and rounds to an int, and `tests/Unit/ReminderScheduleTest.php` pins the value at every threshold boundary.

5. **spatie/laravel-activitylog is v5, not v4.** The fluent logger is `Spatie\Activitylog\Support\ActivityLogger` (`vendor/spatie/laravel-activitylog/src/Support/ActivityLogger.php:17`), reached through `activity(BackedEnum|string|null $logName = null)` (`src/helpers.php:7`); `performedOn()` :46, `causedBy()` :58, `event()` :91, `withProperties()` :112, `log(string $description): ?ActivityContract` :166. **There is no `batch_uuid` column and no batch system in v5** (`UPGRADING.md:16`, `:52-54`), and there is an `attribute_changes` JSON column that v4 did not have — confirmed against this repo's own `database/migrations/2026_09_10_043926_create_activity_log_table.php`. Every activity line in this plan is `activity()->performedOn($model)->causedBy($actor)->withProperties([...])->log('some.event')`, matching `PublishConference::handle()` (`app/Actions/Conferences/PublishConference.php:82`).

6. **`markEmailAsVerified()` is a `forceFill` plus a `save`.** `Illuminate\Auth\MustVerifyEmail::markEmailAsVerified()` (`vendor/laravel/framework/src/Illuminate/Auth/MustVerifyEmail.php:24`) is `return $this->forceFill(['email_verified_at' => $this->freshTimestamp()])->save();`. `hasVerifiedEmail()` at `:14` is `! is_null($this->email_verified_at)`. So Task 2 can mark an invitee verified without touching `$fillable` and without sending a `VerifyEmail` notification — which is exactly what accepting an emailed token proves.

7. **Filament's email-verification middleware is Laravel's, by alias.** `Panel\Concerns\HasAuth::$emailVerifiedMiddlewareName = 'verified'` (`vendor/filament/filament/src/Panel/Concerns/HasAuth.php:26`) and `getEmailVerifiedMiddleware()` (`:367-370`) builds `verified:{route}`; `'verified'` maps to `Illuminate\Auth\Middleware\EnsureEmailIsVerified` (`Illuminate/Foundation/Configuration/Middleware.php:818`). The prompt route name is `filament.{panel}.auth.email-verification.prompt` (`HasAuth.php:345-348` plus `Panel\Concerns\HasRoutes::generateRouteName()` at `:109-118`, which returns `filament.{id}.{name}`). So the reviewer panel's prompt is `filament.reviewer.auth.email-verification.prompt` and its login is `filament.reviewer.auth.login` (`vendor/filament/filament/routes/web.php:36-39`).

8. **Panel builder methods used by `ReviewerPanelProvider`, all confirmed in Filament 5.8.1:** `id(string)` (`Panel/Concerns/HasId.php:11`), `path(string)` (`HasRoutes.php:45`), `login(...)` (`HasAuth.php:205`), `passwordReset(...)` (`HasAuth.php:223`), `emailVerification(..., bool|Closure $isRequired = true)` (`HasAuth.php:110`), `profile(?string $page = EditProfile::class, bool $isSimple = true)` (`HasAuth.php:269`), `multiFactorAuthentication(array|MultiFactorAuthenticationProvider|Closure $providers, ..., bool|Closure $isRequired = false)` (`HasAuth.php:666`), `brandName()` (`HasBrandName.php:12`), `brandLogo()` / `brandLogoHeight()` (`HasBrandLogo.php:16`, `:23`), `colors(array|Closure)` (`HasColors.php:17`), `discoverResources(string $in, string $for)` (`HasComponents.php:349`), `discoverPages()` (`:259`), `pages(array)` (`:128`), `discoverWidgets()` (`:386`), `widgets(array)` (`:228`), `middleware(array, bool $isPersistent = false)` (`HasMiddleware.php:33`), `authMiddleware(array, bool $isPersistent = false)` (`HasMiddleware.php:50`), `userMenuItems(array)` (`HasUserMenu.php:36`), `renderHook(string $name, Closure $hook, string|array|null $scopes = null)` (`HasRenderHooks.php:18`).

9. **The panel switch link is a user-menu item, and it is built from `Filament\Actions\Action`, not `MenuItem`.** `Panel::userMenuItems()`'s docblock (`vendor/filament/filament/src/Panel/Concerns/HasUserMenu.php:33-35`) accepts `Action|Closure|MenuItem`; every entry is normalised to an `Action` in `getUserMenuItemGroups()` (`:99`), and `MenuItem::toAction()` (`vendor/filament/filament/src/Navigation/MenuItem.php:166-179`) derives the action **name** from the label — a label-derived name is a name that changes when the wording changes, and this plan puts both switch links behind one shared builder that tests address by name. So `App\Support\Panels\PanelSwitch` returns `Filament\Actions\Action::make('switch-to-reviewer')->label(...)->icon(...)->url(...)->visible(...)`. `Action::make(?string $name = null)` is `vendor/filament/actions/src/Action.php:146`; `url(string|Closure|null $url, bool|Closure|null $shouldOpenInNewTab = null)` is `actions/src/Concerns/CanOpenUrl.php:22`.

10. **A cross-panel URL can be null, and the link must survive that.** `Panel::getUrl(?Model $tenant = null): ?string` (`Panel/Concerns/HasRoutes.php:170-198`) resolves the tenant from the *current* user when the target panel has tenancy (`:174-176`, through `FilamentManager::getUserDefaultTenant()` at `FilamentManager.php:590`), and returns `null` through `getRedirectUrl()` (`:200`, `:214-218`) when there is no tenant and no registration page. A reviewer with no organization therefore gets `null` from `Filament::getPanel('organizer')->getUrl()`. `PanelSwitch` treats a null URL as "hide the link" rather than rendering a dead one. `Filament::getPanel(?string $id = null, bool $isStrict = true): ?Panel` is `FilamentManager.php:372`.

11. **`PanelsRenderHook` constants exist but are not used here.** `TOPBAR_START = 'panels::topbar.start'` (`vendor/filament/filament/src/View/PanelsRenderHook.php:163`) and `TOPBAR_END` (`:157`) are really rendered (`vendor/filament/filament/resources/views/livewire/topbar.blade.php:26` and `:288`), and `USER_MENU_BEFORE` (`:167`) is rendered at `resources/views/components/user-menu.blade.php:43`. There is **no** `USER_MENU_START`/`USER_MENU_END` and **no** `SIDEBAR_END`. A render hook would need a Blade view and a scope; `userMenuItems()` needs neither and is what the switch link uses. The constants are recorded here so a later plan that wants a *visible* topbar button knows the two that are real.

12. **A Filament 5 page has no `Filament\Forms\Form` and does not use `HasForms`.** `vendor/filament/forms/src/` contains only `FormsComponent.php`, `FormsServiceProvider.php` and `helpers.php` — **`Filament\Forms\Form` does not exist**, and neither do `Filament\Tables\Actions\Action`, `Filament\Forms\Components\Section`, `Filament\Forms\Components\Grid`, `Filament\Forms\Get` or `Filament\Forms\Set`. `Filament\Forms\Contracts\HasForms` is now `interface HasForms extends HasSchemas {}` and `Filament\Forms\Concerns\InteractsWithForms` is a deprecated shim over `InteractsWithSchemas`. `Filament\Pages\BasePage` already `implements HasSchemas` and `use InteractsWithSchemas` (`vendor/filament/filament/src/Pages/BasePage.php:21,25`), so a page declares `public function form(Schema $schema): Schema` and nothing else. The repo's own `EditOrganizationProfile` (`app/Filament/Organizer/Pages/Tenancy/EditOrganizationProfile.php:37`) is the working example.

13. **A custom page's view property is `protected string $view` — not static, not nullable.** `BasePage.php:35`, with `Page` defaulting it to `filament-panels::pages.page` (`vendor/filament/filament/src/Pages/Page.php:81`). The navigation icon is `protected static string|BackedEnum|null $navigationIcon = null` (`Page.php:61`), and `getHeaderActions(): array` is **`protected`** (`Pages/Concerns/InteractsWithHeaderActions.php:55`). All four match what `ConferenceEmailTemplates` and `ConferenceShortLink` already do.

14. **`Action::schema()`, never `Action::form()`.** `Filament\Actions\Concerns\HasSchema::schema(array|Closure|null $schema): static` is `vendor/filament/actions/src/Concerns/HasSchema.php:26`; `form()` still exists at `:128` but is marked `@deprecated Use schema() instead` at `:124` and merely delegates. `fillForm()` and the `array $data` argument of the action closure are unchanged (`Filament\Actions\Concerns\HasData`). `ConferenceEmailTemplates::editAction()` already uses `->schema(...)`.

15. **Array-backed tables: `Table::records(?Closure $dataSource): static`** (`vendor/filament/tables/src/Table/Concerns/HasRecords.php:40`). `Filament\Tables\Concerns\HasRecords::getTableRecords()` (`vendor/filament/tables/src/Concerns/HasRecords.php:95`) takes the non-query branch, evaluates the closure with named arguments (`columnSearches`, `filters`, `page`, `recordsPerPage`, `search`, `sort`, `sortColumn`, `sortDirection`, `:103-112`), accepts an array, a `Collection` or a paginator, and keys each array row by `Filament\Support\ArrayRecord::getKeyName()` — `__key` (`vendor/filament/support/src/ArrayRecord.php:7`) — **filling it from the collection key when absent** (`:132`). `getTableRecordKey()` (`:249`) throws `LogicException` with the message "Record arrays must have a unique [key] entry for identification." (`:252`) if it is still missing. Row actions receive `array $record`. Bulk actions on such a table additionally need `resolveSelectedRecordsUsing(?Closure)` (`Table/Concerns/HasRecords.php:46`) — this plan uses no bulk action on an array table, precisely to avoid that. This is Plan 3 fact 10, re-verified, and it is what makes the Members, Reviewers and Assignments pages possible.

16. **Table builder names in v5:** `columns(array)` (`Table/Concerns/HasColumns.php:41`), `filters(array, ...)` (`HasFilters.php:88`), **`recordActions(array|ActionGroup, ...)`** (`HasRecordActions.php:32`; the v3 `actions()` survives at `:142`), `headerActions(array|ActionGroup, ...)` (`HasHeaderActions.php:32`), **`toolbarActions(array|ActionGroup)`** (`HasToolbarActions.php:20`; the v3 `bulkActions()` survives at `HasBulkActions.php:37`), `defaultSort(string|Closure|null, string|Closure|null $direction = 'asc')` (`CanSortRecords.php:23`), `query(Builder|Closure|null)` (`HasQuery.php:28`). The existing organizer tables already use `recordActions()`/`headerActions()`.

17. **Every FQCN this plan imports from Filament exists.** Verified by file and by the `class` declaration inside: `Filament\Actions\Action` (`actions/src/Action.php:51`), `Filament\Actions\BulkAction` (`actions/src/BulkAction.php:5`), `Filament\Actions\CreateAction` (:20), `Filament\Actions\DeleteAction` (:11), `Filament\Tables\Columns\TextColumn` (`tables/src/Columns/TextColumn.php:35`), `IconColumn` (:28), `Filament\Tables\Filters\SelectFilter` (`tables/src/Filters/SelectFilter.php:15`), `Filament\Forms\Components\Select` (`forms/src/Components/Select.php:49`, with `multiple()` :517, `searchable()` :504, `preload()` in `Concerns/CanBePreloaded.php:11`, `options()` in `Concerns/HasOptions.php:20`), `CheckboxList` (:31), `Textarea` (:15), `TextInput` (:18), `Radio` (:12), `Filament\Schemas\Components\Section` (`schemas/src/Components/Section.php:42`, `make()` :93), `Filament\Schemas\Components\Grid` (:10, `make()` :27), `Filament\Schemas\Components\Utilities\Get` (:10) and `Set` (:7), `Filament\Schemas\Schema` (`schemas/src/Schema.php:26`), `Filament\Resources\RelationManagers\RelationManager` (`filament/src/Resources/RelationManagers/RelationManager.php:54`), `Filament\Resources\Pages\Page` (`Resources/Pages/Page.php:38`), `Filament\Resources\Pages\Concerns\InteractsWithRecord`, `Filament\Infolists\Components\{TextEntry,IconEntry,RepeatableEntry}`.

18. **Heroicon cases used below, all confirmed in `vendor/filament/support/src/Icons/Heroicon.php`:** `OutlinedUserGroup` (:641), `OutlinedUserPlus` (:643), `OutlinedUserMinus` (:642), `OutlinedUsers` (:645), `OutlinedClipboardDocumentCheck` (:455), `OutlinedClipboardDocumentList` (:456), `OutlinedScale` (:607), `OutlinedStar` (:626), `OutlinedQueueList` (:599), `OutlinedArrowsRightLeft` (:391), `OutlinedBellAlert` (:409), `OutlinedPaperAirplane` (:574), `OutlinedSparkles` (:619), `OutlinedChartBar` (:433), `OutlinedAcademicCap` (:334), `OutlinedCheckCircle` (:443), `OutlinedClock` (:459), `OutlinedLockOpen` (:556), `OutlinedLockClosed` (:555), `OutlinedPencilSquare` (:578), `OutlinedXMark` (:657), `OutlinedTrash` (:635), `OutlinedPlus` (:591), plus the ones Plans 2 and 3 already use (`OutlinedEnvelope`, `OutlinedEnvelopeOpen`, `OutlinedArrowPath`, `OutlinedNoSymbol`, `OutlinedInbox`, `OutlinedDocumentText`, `OutlinedArrowDownTray`, `OutlinedCalendarDays`, `OutlinedListBullet`).

19. **Extra parameters on a signed URL are part of the signature.** `UrlGenerator::signedRoute()` (`vendor/laravel/framework/src/Illuminate/Routing/UrlGenerator.php:366-387`) sorts every parameter, HMACs the whole generated URL, and appends `signature`; `hasValidSignature()` (`:434`) recomputes over the request's full query minus `signature`. So `URL::temporarySignedRoute('files.download', …, ['ulid' => …, 'blind' => 1])` produces a link whose `blind=1` cannot be removed or flipped by the recipient — which is what lets Task 6 rename a blind reviewer's download without adding a second route or a session check. `signature` and `expires` are reserved parameter names (`:397-410`).

20. **`Notification::route('mail', $address)->notify(...)` is how a member invitation reaches someone with no account.** `Illuminate\Support\Facades\Notification::route($channel, $route)` (`Support/Facades/Notification.php:86`) returns an `AnonymousNotifiable` (`Illuminate/Notifications/AnonymousNotifiable.php:8`, `route()` :26, `notify()` :43). `NotificationFake::assertSentOnDemand($notification, $callback = null)` (`Support/Testing/Fakes/NotificationFake.php:52`) is the matching assertion. There is no existing `Notification::route(` anywhere in `app/` or `tests/`, so Task 3 is the first use.

21. **`organization_members.notify_on_submission` already exists** (boolean, default `true`, `database/migrations/2026_09_10_000300_create_organization_members_table.php:18`), is cast on `App\Models\OrganizationMember`, is in the `withPivot([...])` list of both `Organization::members()` and `User::organizations()`, and is read by `SubmitAbstract::notifiableMembers()` (`app/Actions/Submissions/SubmitAbstract.php:198-208`). Plan 4 adds the *toggle*, not the column.

22. **`withPivotValue()` also constrains the query.** `BelongsToMany::withPivotValue($column, $value = null)` (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Relations/BelongsToMany.php:513`, body at `:529`) records the default for new pivot rows **and** applies `wherePivot($column, '=', $value)`. That dual behaviour is why `Organization::owners()` works, and why `ChangeMemberRole` in Task 3 uses `updateExistingPivot()` (`Relations/Concerns/InteractsWithPivotTable.php:267`) rather than a second `withPivotValue` relation.

23. **The scheduler already runs in production.** `docker/supervisord.conf:38-46` runs `php artisan schedule:work` as `app` with `autorestart=true` and `startretries=1000`. Nothing in Docker, compose or the image changes for Task 10 — only `routes/console.php` and `bootstrap/app.php`.

24. **`/files/{ulid}` performs no policy check and must not start.** `App\Http\Controllers\Public\SubmissionFileController::__invoke()` checks only that the file exists, that its submission is not soft-deleted and that the object is on disk; the signature is the capability (its own docblock says so). Task 6 adds the reviewer case to `SubmissionFilePolicy`, which governs where a signed URL may be **minted**, and adds the blind rename to the controller — it does **not** add an auth check there, because the author case has no session at all.

25. **Plan 3 fact 17, still load-bearing:** a *missing* policy, or a policy missing the one method Filament asks for, means **ALLOW**, not 403 (`vendor/filament/filament/src/helpers.php:60-93`, reached through `Filament\get_authorization_response()`). Every ability Filament may call is spelled out on all five new policies, including `deleteAny`, `restoreAny` and `forceDeleteAny`, and `tests/Feature/Organizer/ChildPolicyBulkAbilitiesTest.php` — which calls through that same helper — grows a case per policy.

26. **Plan 3 fact 12, still load-bearing:** `Model::preventSilentlyDiscardingAttributes()` is on outside production (`app/Providers/AppServiceProvider.php:35`), so every `$fillable` below is exact and anything a form sends that is not listed **throws** instead of being dropped. Every status, timestamp and foreign key in this plan is written with `forceFill()` inside an action.

27. **Plan 2 fact 14 / Plan 3 fact 22, still load-bearing:** `Select::options(SomeEnum::class)` registers an `EnumStateCast`, so inside a Filament schema `$get('status')` hands back the **enum case**, never the backing string. The reviewer's review form is the opposite case and is built from `ReviewQuestion` rows rather than enums, so its `Radio`/`Select` options are plain arrays and its state is plain scalars — which is what `App\Support\Reviews\ReviewFormSchema` guarantees in one place.

28. **Plan 2 fact 16 / Plan 3 fact 23, still load-bearing:** Filament builds record URLs from `$model->getRouteKey()` and resolves them with the resource's `$recordRouteKeyName`. `Submission::getRouteKeyName()` is `ulid` and neither submission resource sets `$recordRouteKeyName`, so the two agree in both panels. The one new public route asks for what it wants explicitly: a bare `{token}` on `/invite/...`.

29. **Plan 3 fact 24, still load-bearing:** `Markdown::withSecuredEncoding()` is on, and `RenderEmailTemplate` escapes each placeholder value with its own `VALUE_ESCAPES` before substitution. Task 4's `{{reviewer_name}}` comes from a name an organizer typed into a textarea, so it goes through the same path as `{{author_name}}` and needs nothing new.

30. **Testing helpers used below, all present at the versions above:** `Mail::fake()` / `assertQueued()` (`Support/Testing/Fakes/MailFake.php:206`), `Notification::fake()` / `assertSentTo()` (:67) / `assertSentOnDemand()` (:52) / `assertNothingSent()` (:187), `Carbon::setTestNow()` (`vendor/nesbot/carbon/src/Carbon/Traits/Test.php:50`), `$this->travelTo()` and `$this->freezeTime()` (`Illuminate/Foundation/Testing/Concerns/InteractsWithTime.php:61`, `:18`), and Pest's `livewire()` from `Pest\Livewire` (`vendor/pestphp/pest-plugin-livewire/src/Autoload.php:17`), which every test file imports as `use function Pest\Livewire\livewire;`.

31. **How a Filament 5 page hosts an editable form — the exact shape Task 7 copies.** `Filament\Schemas\Concerns\InteractsWithSchemas::cacheSchema()` (`vendor/filament/schemas/src/Concerns/InteractsWithSchemas.php:349`) resolves a schema **by name**: it reflects the method of that name, and when parameter 0 is typed `Schema` it builds one with `makeSchema()` (`:447`), applies a `default{Ucfirst}` hook if the class has one (`:449-451`), and then passes it to the method (`:453`). `ResolvesDynamicLivewireProperties::__get()` (`vendor/filament/schemas/src/Concerns/ResolvesDynamicLivewireProperties.php:16-39`) is what makes `$this->form` resolve to that Schema in a Blade view. So a page that edits state declares exactly four things, which is what `Filament\Auth\Pages\EditProfile` does:
    - `public ?array $data = [];` (`vendor/filament/filament/src/Auth/Pages/EditProfile.php:59`), with `/** @property-read Schema $form */` on the class (`:46-48`) so Larastan understands `$this->form`;
    - `mount()` calling `$this->form->fill($state)` (`:95-98`, `:111-122`);
    - `public function defaultForm(Schema $schema): Schema` carrying `->statePath('data')` and `->model(...)` (`:412-419`);
    - `public function form(Schema $schema): Schema` carrying only the components (`:421-431`);
    and `save()` starts with `$data = $this->form->getState();` (`:192`), which validates and dehydrates in one call (`getState()` calls `validate()` internally, `vendor/filament/schemas/src/Concerns/HasState.php:450-451`). **Note the namespace:** it is `Filament\Auth\Pages\EditProfile`; `Filament\Pages\Auth\EditProfile` does not exist in 5.8.1.

32. **`<x-filament-panels::form>` does not exist**, and no Filament panel view contains a `wire:submit`. A `<form wire:submit="…">` element is emitted in PHP by `Filament\Schemas\Components\Form::toEmbeddedHtml()` (`vendor/filament/schemas/src/Components/Form.php:90-114`), configured with `livewireSubmitHandler(string|Closure|null)` (`:58-63`). **This plan does not use it.** The review page renders `{{ $this->form }}` inside `<x-filament::section>` — which renders the fields and no submit button — and puts "Save draft", "Submit review" and "Reopen" in `getHeaderActions()`, where they are ordinary `Filament\Actions\Action`s with a closure body. That is one mechanism instead of three (`Form` + `EmbeddedSchema` + `Actions`), it matches how `ConferenceShortLink` and `ConferenceEmailTemplates` already build pages, and it gives the tests a name to call (`callAction('submitReview')`) instead of a form submit to simulate.

33. **`fillForm()` and `assertHasFormErrors()` resolve the schema name, they do not hardcode `'form'`.** `fillForm(array|Closure $state = [], ?string $form = null)` and `assertHasFormErrors(array $keys = [], ?string $form = null)` (`vendor/filament/forms/src/Testing/TestsForms.php:23` and `:84`) fall back to the mounted action's schema when one is mounted, otherwise to `getDefaultTestingSchemaName()`, which `Filament\Pages\Page` overrides to return `'form'` when a `form` schema exists and `'content'` otherwise (`vendor/filament/filament/src/Pages/Page.php:467-470`). The error keys are prefixed with the schema's state path for you (`TestsForms.php:103-111`), so a test passes bare names like `answers.01J…`. Only the **deprecated** `assertFormSet()` / `assertFormExists()` hardcode `'form'`.

34. **The table testing helpers this repo already uses are deprecated aliases, and they still work.** `callTableAction`, `mountTableAction`, `assertTableActionVisible/Hidden`, `assertHasTableActionErrors`, `assertHasNoTableActionErrors`, `assertTableActionExists`, `callTableBulkAction` and `setTableActionData` are all present in `vendor/filament/tables/src/Testing/TestsActions.php` and `TestsBulkActions.php`, each marked `@deprecated` in `vendor/filament/tables/.stubs.php` in favour of the unified `callAction()` / `mountAction()` / `assertAction*()` family plus `Filament\Actions\Testing\TestAction`. `assertCanSeeTableRecords`, `assertCanNotSeeTableRecords`, `assertCountTableRecords`, `assertCanRenderTableColumn`, `searchTable`, `filterTable`, `sortTable`, `selectTableRecords` and `assertTableHeaderActionsExistInOrder` are **not** deprecated. **This plan keeps using the table-specific names**, because `tests/Feature/Organizer/ConferenceRelationManagersTest.php`, `ReviewQuestionsRelationManagerTest.php` and `SubmissionResourceTest.php` all use them and a half-migrated suite is worse than a consistently deprecated one. Task 13 adds a backlog entry for the one-time sweep.

35. **`assertTableEmptyStateHeading()` does not exist** in 5.8.1 — the only empty-state helper is `assertTableEmptyStateActionsExistInOrder()` (`vendor/filament/tables/.stubs.php:126`). Every "the empty page says the right thing" assertion below is a plain Livewire `assertSee()`. Likewise `assertInfolistExists()` does not exist (use `assertSchemaExists('infolist')`, `vendor/filament/schemas/src/Testing/TestsSchemas.php:145`), and `assertSeeTextInOrder()` is Laravel's, not Filament's.

36. **An editable table column over *array* records silently does nothing without `updateStateUsing()`.** `Filament\Tables\Columns\Concerns\CanUpdateState::updateState()` (`vendor/filament/tables/src/Columns/Concerns/CanUpdateState.php:45`) returns the `updateStateUsing` result when one is set (`:53-61`) and otherwise falls through to `if (! ($record instanceof Model)) { return null; }` at `:82-84` — no save, no error, no `afterStateUpdated`. Both `ToggleColumn` and `TextInputColumn` also carry an in-source warning that inline editing **bypasses model policies** and honours only `disabled()` (`ToggleColumn.php:24-26`). That is why the Members page's `notify_on_submission` control in Task 3 is an **Action**, not a `ToggleColumn`: the page is array-backed, and an action goes through `Gate::authorize()` like every other write in this codebase.

37. **`Filament\Schemas\Components\Split` does not exist** (the only `Split` in vendor is `Filament\Tables\Columns\Layout\Split`). `Tabs`, `Fieldset`, `Text`, `Flex`, `Actions`, `Callout`, `EmbeddedSchema`, `Form`, `Grid`, `Group`, `Section` and `Wizard` all do. This plan uses only `Section` and `Grid`.

38. **`Resource::getUrl()`'s first parameter is nullable, not `'index'`.** `public static function getUrl(?string $name = null, array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string` (`vendor/filament/filament/src/Resources/Resource/Concerns/CanGenerateUrls.php:16`); a blank `$name` delegates to `getIndexUrl()` (`:67-69`). `Page::getUrl()` has no `$name` at all (`vendor/filament/filament/src/Pages/Page.php:91`). Both accept `panel:` and `tenant:` as named arguments, which is how `NewSubmissionNotice` already builds an organizer URL from outside the panel and how `PanelSwitch` builds the reviewer link from inside the organizer panel.

39. **`discoverResources()` on a directory that does not exist is a no-op, not an error.** `Panel::discoverComponents(string $baseClass, array &$register, ?string $directory, ?string $namespace)` (`vendor/filament/filament/src/Panel/Concerns/HasComponents.php:501`) returns early when the directory is blank **or** absent (`:503-511`) before it ever builds a `Finder`. That is what lets Task 5 register `ReviewerPanelProvider` with `discoverResources(in: app_path('Filament/Reviewer/Resources'), …)` in the same commit that has no such directory yet, and Task 6 create it without touching the provider again.

40. **Filament's tenancy global scope is inert outside the panel that registered it.** `BelongsToTenant::registerTenancyModelGlobalScope()` (`vendor/filament/filament/src/Resources/Resource/Concerns/BelongsToTenant.php:127`) adds a scope whose body returns immediately when `Filament::getCurrentPanel() !== $panel` (`:144-146`) **or** when there is no tenant (`:148-152`). So the `Conference` global scope the organizer panel's `ConferenceResource` installs does nothing inside the reviewer panel, and `App\Support\Reviews\ReviewerScope`'s `whereHas('conference', …)` sees every conference the rule allows. This is load-bearing for Task 6 and for the reminder command in Task 10, which runs on the console with no panel at all.

41. **`Repeater::fake()` returns an undo closure** and is inherited from `Filament\Forms\Components\Concerns\CanGenerateUuids::fake()` (`vendor/filament/forms/src/Components/Concerns/CanGenerateUuids.php:32-37`), which is why `ReviewQuestionsRelationManagerTest.php:70` writes `$undo = Repeater::fake();` and calls `$undo()` in a `finally`. Only `Repeater` and `Builder` have it — there is no `Select::fake()`.

---
### Task 1: Schema, enums, models, factories and policies

Seven new tables, four enums, one interface, seven models, seven factories and five policies. No user interface, no email, no token generation — those are Tasks 2 onwards. The whole of Plan 4's data model lands here so that every later task adds behaviour rather than a migration.

**Three decisions this task makes, with the reasoning, because every later task depends on them.**

**1. Two invitation tables, not one polymorphic `invitations` table.** Spec section 3 says "Every foreign key is a real InnoDB constraint", and a polymorphic `invitable_type` / `invitable_id` pair cannot carry one — a revoked organization would leave reviewer invitations pointing at nothing, and the platform-admin purge of Plan 6 would have to know about a table the database does not connect to anything. The payloads differ too: an organization invitation carries an `OrganizationRole` and nothing else, a reviewer invitation carries the name and affiliation an organizer typed before any account existed. What the two genuinely share is the *token shape*, the *accept page* and the *accept action*, and those are shared in PHP: both models implement `App\Contracts\Invitation`, both are hashed by `App\Support\Tokens\InvitationToken`, and `App\Support\Invitations\InvitationLookup` resolves a plaintext token across both. A token collision across the two tables is a 2^-256 event; the lookup still has a fixed order (organization first) so the behaviour is defined rather than lucky.

**2. The invitation status is derived, not stored.** The brief for this plan lists `status pending/accepted/expired/revoked` as a column. It is an enum and an accessor here, and the columns are `accepted_at`, `accepted_by` and `revoked_at`. `expired` is a function of the clock: a stored `status` would be wrong for exactly as long as nothing ran to update it, and the only thing that could keep it right is a scheduled job whose entire purpose would be to agree with `expires_at`. `App\Enums\InvitationStatus` still exists — the pages badge it, the accept page branches on it — it simply has no column. The cost is that a table cannot `where('status', …)` in SQL; both invitation lists are array-backed pages over a handful of rows (fact 15), so nothing needs to.

**3. There is no unique index on `(organization_id, email)` or `(conference_id, email)`.** A revoked invitation keeps its row, so a unique index would make "invite, revoke, invite again" fail at the database with a duplicate-key error instead of succeeding. Uniqueness of the *live* invitation is enforced in `InviteMember` and `InviteReviewer` (Tasks 3 and 4), which **update the existing pending row** — new token, new expiry — rather than inserting a second. That also makes "resend" and "invite again" the same code path, which is what an organizer expects: clicking Invite twice must not put two live links in someone's inbox. MySQL 8 has no partial unique index, so the alternative would be a generated column, which is a lot of schema to say something one `firstOrNew` already says.

**Foreign-key delete behaviour, decided per table:**

| Column | On delete | Why |
|---|---|---|
| `organization_invitations.organization_id` | cascade | Bookkeeping with no life of its own, exactly like `organization_members`. |
| `reviewer_invitations.conference_id` | cascade | Same. A hard-deleted conference has no invitations worth keeping, and `submissions.conference_id` is still `RESTRICT`, so a stray `forceDelete()` is still refused. |
| `conference_reviewers.{conference_id,user_id}` | cascade | Membership, not evidence. |
| `review_assignments.{submission_id,reviewer_user_id}` | cascade | An assignment is a work item; the review it produced is the record. |
| `reviews.submission_id` | **restrict** | A review is evidence of what the committee was told. Plan 6's purge must delete it on purpose, in application code, like everything else under a conference. |
| `reviews.reviewer_user_id` | **restrict** | Deleting a user must not silently erase their reviews. There is no user-deletion path in the app today; this is what keeps it that way by accident-proofing. |
| `reviews.review_form_id` | **restrict** | The form is what the review answers. |
| `review_answers.review_id` | cascade | An answer has no meaning without its review. |
| `review_answers.review_question_id` | **restrict** | This is the database-level twin of `review_forms.locked_at`: once a question has an answer, no code path can hard-delete it. |
| `reviewer_reminders.{conference_id,user_id}` | cascade | Idempotency bookkeeping. |

**Files:**
- Create: nine migrations under `database/migrations/`
- Create: `app/Enums/{InvitationStatus,ReminderThreshold,ReviewStatus,ReviewerStatus}.php`
- Create: `app/Contracts/Invitation.php`
- Create: `app/Models/{ConferenceReviewer,OrganizationInvitation,Review,ReviewAnswer,ReviewAssignment,ReviewerInvitation,ReviewerReminder}.php`
- Create: `database/factories/{ConferenceReviewerFactory,OrganizationInvitationFactory,ReviewAnswerFactory,ReviewAssignmentFactory,ReviewFactory,ReviewerInvitationFactory,ReviewerReminderFactory}.php`
- Create: `app/Policies/{ConferenceReviewerPolicy,OrganizationInvitationPolicy,ReviewAssignmentPolicy,ReviewPolicy,ReviewerInvitationPolicy}.php`
- Create: `lang/en/members.php`, `lang/en/reviewer.php` (two stub keys each in Step 11, because `OrganizationInvitation::invitationHeadline()` and `ReviewerInvitation::invitationHeadline()` already read from them; both files grow in Tasks 2-11 and are swept in Task 12)
- Modify: `app/Models/{Conference,CustomField,Organization,ReviewForm,ReviewQuestion,Submission,User}.php`, `app/Filament/Organizer/Resources/Conferences/RelationManagers/CustomFieldsRelationManager.php` (the `hide_from_reviewers` toggle), `config/cass.php`
- Test: `tests/Unit/InvitationModelTest.php`, `tests/Unit/ReviewModelsTest.php`, `tests/Feature/Organizer/ChildPolicyBulkAbilitiesTest.php` (modified)

- [ ] **Step 1: Record the baseline before touching anything**

```bash
cd /c/Users/ahmed/Documents/CASS && git checkout main && git pull --ff-only && \
git checkout -b plan-4-reviewers && \
php artisan test > /tmp/baseline.log 2>&1; echo "baseline rc=$?"; tail -3 /tmp/baseline.log && \
./vendor/bin/pint --test > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?" && \
php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar show filament/filament livewire/livewire laravel/framework | grep -E "^(name|versions)"
```

Expected: `baseline rc=0`, `pint rc=0`, `stan rc=0`. **Write the passing-test count from `tail -3 /tmp/baseline.log` into your notes — every "Expected" line in this plan is `baseline + N`.** If any of the three is non-zero, stop: Plan 3 is not finished and this branch has the wrong parent.

- [ ] **Step 2: Write the failing tests**

`tests/Unit/InvitationModelTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewerStatus;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ReviewerInvitation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('derives pending, expired, accepted and revoked from the timestamps', function () {
    $invitation = OrganizationInvitation::factory()->create();

    expect($invitation->invitationStatus())->toBe(InvitationStatus::Pending);

    Carbon::setTestNow($invitation->expires_at->copy()->addSecond());
    expect($invitation->invitationStatus())->toBe(InvitationStatus::Expired);
    Carbon::setTestNow();

    $accepted = OrganizationInvitation::factory()->create();
    $accepted->forceFill(['accepted_at' => now()])->save();
    expect($accepted->invitationStatus())->toBe(InvitationStatus::Accepted);

    // Revoked wins over accepted and over expired: an organizer who withdraws
    // an invitation must see that answer whatever the clock says.
    $revoked = OrganizationInvitation::factory()->create();
    $revoked->forceFill(['revoked_at' => now()])->save();
    expect($revoked->invitationStatus())->toBe(InvitationStatus::Revoked)
        ->and($revoked->invitationStatus()->isOpen())->toBeFalse()
        ->and(InvitationStatus::Pending->isOpen())->toBeTrue();
});

it('expires fourteen days out by default and says so in both tables', function () {
    Carbon::setTestNow('2026-09-11 08:00:00');

    expect(OrganizationInvitation::factory()->create()->expires_at->toDateString())->toBe('2026-09-25')
        ->and(ReviewerInvitation::factory()->create()->expires_at->toDateString())->toBe('2026-09-25')
        ->and((int) config('cass.invitations.expiry_days'))->toBe(14);

    Carbon::setTestNow();
});

it('refuses mass assignment on both invitation tables', function () {
    // Every column is written by an action with forceFill(). A fillable
    // token_hash or role is a privilege escalation with a form field attached.
    expect(fn () => new OrganizationInvitation(['role' => OrganizationRole::Owner->value]))
        ->toThrow(MassAssignmentException::class)
        ->and(fn () => new ReviewerInvitation(['email' => 'x@example.org']))
        ->toThrow(MassAssignmentException::class);
});

it('grants organization membership and keeps an existing role', function () {
    $organization = Organization::factory()->approved()->create();
    $user = User::factory()->create();

    OrganizationInvitation::factory()->for($organization)->create(['role' => OrganizationRole::Admin])
        ->grantTo($user);

    expect($user->roleIn($organization))->toBe(OrganizationRole::Admin);

    // A second invitation at a lower role must not demote a sitting admin:
    // addMember() is syncWithoutDetaching, sync() updates the pivot of an id it
    // already holds, and a role change is ChangeMemberRole's job with its own
    // rules - so grantTo() returns early for anyone who is already a member.
    OrganizationInvitation::factory()->for($organization)->create(['role' => OrganizationRole::Member])
        ->grantTo($user);

    expect($user->fresh()?->roleIn($organization))->toBe(OrganizationRole::Admin);

    // And the escalation in the other direction, which is the one that matters:
    // a stale Owner invitation must not promote a sitting admin either, or a
    // link minted weeks ago becomes a route around ChangeMemberRole's
    // owner-grants-owner rule.
    OrganizationInvitation::factory()->for($organization)->create(['role' => OrganizationRole::Owner])
        ->grantTo($user);

    expect($user->fresh()?->roleIn($organization))->toBe(OrganizationRole::Admin);
});

it('grants a conference reviewership and reactivates a removed one', function () {
    $conference = Conference::factory()->create();
    $user = User::factory()->create();

    ReviewerInvitation::factory()->for($conference)->create(['affiliation' => 'KFSH'])->grantTo($user);

    $reviewer = ConferenceReviewer::query()->firstOrFail();

    expect($reviewer->conference_id)->toBe($conference->id)
        ->and($reviewer->user_id)->toBe($user->id)
        ->and($reviewer->status)->toBe(ReviewerStatus::Active)
        ->and($reviewer->affiliation)->toBe('KFSH')
        ->and($reviewer->accepted_at)->not->toBeNull();

    $reviewer->forceFill(['status' => ReviewerStatus::Removed, 'removed_at' => now()])->save();

    ReviewerInvitation::factory()->for($conference)->create()->grantTo($user);

    expect(ConferenceReviewer::query()->count())->toBe(1)
        ->and($reviewer->fresh()?->status)->toBe(ReviewerStatus::Active)
        ->and($reviewer->fresh()?->removed_at)->toBeNull();
});

it('lands an organization invitee in that tenant and a reviewer in the reviewer panel', function () {
    $organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);

    expect(OrganizationInvitation::factory()->for($organization)->create()->landingUrl())
        ->toContain('/org/'.$organization->slug)
        ->and(ReviewerInvitation::factory()->create()->landingUrl())
        ->toEndWith('/review');
});

it('names who is inviting, in both tables', function () {
    $organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $conference = Conference::factory()->for($organization)->create(['name' => 'Alpha Annual Meeting']);

    $member = OrganizationInvitation::factory()->for($organization)->create([
        'email' => 'NEW@Example.ORG',
        'role' => OrganizationRole::Admin,
    ]);
    $reviewer = ReviewerInvitation::factory()->for($conference)->create(['name' => 'Dr Omar Khan']);

    expect($member->invitingOrganizationName())->toBe('Alpha Society')
        ->and($member->invitedName())->toBeNull()
        ->and($member->invitationHeadline())->toContain('Alpha Society')
        ->and($reviewer->invitingOrganizationName())->toBe('Alpha Society')
        ->and($reviewer->invitedName())->toBe('Dr Omar Khan')
        ->and($reviewer->invitationHeadline())->toContain('Alpha Annual Meeting');
});
```

`tests/Unit/ReviewModelsTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Enums\ReviewQuestionType;
use App\Enums\ReviewStatus;
use App\Models\Conference;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->conference = Conference::factory()->published()->create();
    $this->form = app(CreateDefaultReviewForm::class)->handle($this->conference);
    $this->submission = Submission::factory()->for($this->conference)->submitted()->create();
    $this->reviewer = User::factory()->create();
});

it('keeps one review per reviewer per submission', function () {
    Review::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]);

    // Spec section 8 lists (submission_id, reviewer_user_id) as unique. SQLite
    // and MySQL both raise here; the action layer never relies on catching it,
    // it is the last line of defence behind SaveReviewDraft's firstOrNew.
    expect(fn () => Review::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]))
        ->toThrow(QueryException::class);
});

it('keeps one assignment per reviewer per submission', function () {
    ReviewAssignment::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]);

    expect(fn () => ReviewAssignment::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]))
        ->toThrow(QueryException::class);
});

it('keeps one answer per question per review', function () {
    $review = Review::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]);
    $question = $this->form->questions()->firstOrFail();

    ReviewAnswer::factory()->for($review)->create(['review_question_id' => $question->id]);

    expect(fn () => ReviewAnswer::factory()->for($review)->create(['review_question_id' => $question->id]))
        ->toThrow(QueryException::class);
});

it('refuses mass assignment on every review table', function () {
    expect(fn () => new Review(['status' => ReviewStatus::Submitted->value]))->toThrow(MassAssignmentException::class)
        ->and(fn () => new ReviewAnswer(['value_int' => 5]))->toThrow(MassAssignmentException::class)
        ->and(fn () => new ReviewAssignment(['submission_id' => 1]))->toThrow(MassAssignmentException::class);
});

it('reads an answer back with the type its question asked for', function () {
    $review = Review::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]);

    $likert = $this->form->questions()->firstOrFail();
    $text = ReviewQuestion::factory()->for($this->form)->freeText()->create(['prompt' => 'Comments', 'sort' => 90]);
    $boolean = ReviewQuestion::factory()->for($this->form)->create([
        'prompt' => 'Anonymised?', 'type' => ReviewQuestionType::Boolean, 'scale_min' => null, 'scale_max' => null, 'sort' => 91,
    ]);
    $select = ReviewQuestion::factory()->for($this->form)->create([
        'prompt' => 'Format', 'type' => ReviewQuestionType::Select, 'scale_min' => null, 'scale_max' => null, 'sort' => 92,
        'options' => [['label' => 'Oral', 'score' => 100], ['label' => 'Poster', 'score' => 50]],
    ]);

    ReviewAnswer::factory()->for($review)->create(['review_question_id' => $likert->id, 'value_int' => 4]);
    ReviewAnswer::factory()->for($review)->create(['review_question_id' => $text->id, 'value_int' => null, 'value_text' => 'Solid work.']);
    ReviewAnswer::factory()->for($review)->create(['review_question_id' => $boolean->id, 'value_int' => null, 'value_bool' => true]);
    ReviewAnswer::factory()->for($review)->create(['review_question_id' => $select->id, 'value_int' => null, 'choice_key' => 'Oral']);

    $answers = $review->refresh()->answers->keyBy('review_question_id');

    expect($answers[$likert->id]->value())->toBe(4)
        ->and($answers[$text->id]->value())->toBe('Solid work.')
        ->and($answers[$boolean->id]->value())->toBeTrue()
        ->and($answers[$select->id]->value())->toBe('Oral')
        // Plan 5 scores from the key. The key IS the label (see the model
        // comment); the lookup is what that plan will call.
        ->and($select->optionScore('Oral'))->toBe(100)
        ->and($select->optionScore('Nonexistent'))->toBeNull()
        ->and($select->optionKeys())->toBe(['Oral', 'Poster']);
});

it('locks a review form once and reports whether it did the locking', function () {
    expect($this->form->isLocked())->toBeFalse()
        ->and($this->form->lockIfUnlocked())->toBeTrue()
        ->and($this->form->refresh()->isLocked())->toBeTrue()
        // Idempotent: the second submitted review must not re-stamp the time.
        ->and($this->form->lockIfUnlocked())->toBeFalse();
});

it('walks from a conference to its reviewers, assignments and reviews', function () {
    $review = Review::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]);
    ReviewAssignment::factory()->for($this->submission)->create(['reviewer_user_id' => $this->reviewer->id]);

    expect($this->submission->reviews)->toHaveCount(1)
        ->and($this->submission->reviewAssignments)->toHaveCount(1)
        ->and($this->reviewer->reviews)->toHaveCount(1)
        ->and($this->reviewer->reviewAssignments)->toHaveCount(1)
        ->and($review->reviewForm->is($this->form))->toBeTrue()
        ->and($this->form->reviews)->toHaveCount(1);
});

it('hides authors from a reviewer only when the conference is blind', function () {
    $member = User::factory()->create();
    $this->conference->organization->addMember($member, App\Enums\OrganizationRole::Owner);

    $this->conference->forceFill(['blind_review' => true])->save();

    expect($this->conference->hidesAuthorsFrom($this->reviewer))->toBeTrue()
        // An organizer is never blinded: spec section 4 gives every
        // organization member "View submissions and files" with no caveat, and
        // somebody has to be able to answer an author's email.
        ->and($this->conference->hidesAuthorsFrom($member))->toBeFalse();

    $this->conference->forceFill(['blind_review' => false])->save();

    expect($this->conference->fresh()?->hidesAuthorsFrom($this->reviewer))->toBeFalse();
});

it('lets a reviewer read a decided conference but not write to it', function () {
    // Two questions, deliberately not one. Reading is open in Reviewing AND
    // Decided - a reviewer may look back at what they said. Writing is open in
    // Reviewing only: a review changed after the committee decided would change
    // the evidence the decision was made on. Task 7's SaveReviewDraft,
    // SubmitReview, ReopenReview and ReviewSubmission::isReadOnly() all ask
    // acceptsReviewWrites(); every read path asks isOpenToReviewers().
    $this->conference->forceFill(['status' => App\Enums\ConferenceStatus::Reviewing])->save();

    expect($this->conference->isOpenToReviewers())->toBeTrue()
        ->and($this->conference->acceptsReviewWrites())->toBeTrue();

    $this->conference->forceFill(['status' => App\Enums\ConferenceStatus::Decided])->save();

    expect($this->conference->isOpenToReviewers())->toBeTrue()
        ->and($this->conference->acceptsReviewWrites())->toBeFalse();

    $this->conference->forceFill(['status' => App\Enums\ConferenceStatus::Closed])->save();

    expect($this->conference->isOpenToReviewers())->toBeFalse()
        ->and($this->conference->acceptsReviewWrites())->toBeFalse();
});
```

In `tests/Feature/Organizer/ChildPolicyBulkAbilitiesTest.php`, first **widen the existing helper**. Today it is declared at line 32 as `function panelAllows(string $ability, string $model): bool`, and this file is under `declare(strict_types=1)` — so the soft-deleted-organization test below, which must pass model *instances* to reach a policy's second argument at all, would be six TypeErrors against a `string` parameter rather than six `false` answers. Filament's own helper already takes either (`get_authorization_response(string $ability, Model|string $model)`, vendor/filament/filament/src/helpers.php:31), so widen ours to match and change nothing else about it:

```php
use Illuminate\Database\Eloquent\Model;

/**
 * Filament resolves a bulk ability through its own helper, and that helper
 * treats a policy without the method as *allowed* (see
 * vendor/filament/filament/src/helpers.php): a child policy that defines only
 * delete() therefore grants deleteAny, restoreAny and forceDeleteAny to
 * everyone the panel lets in. These tests go through the same helper rather
 * than through Gate, so they see what the panel sees.
 *
 * A class-string asks the class-level question (viewAny, create, deleteAny);
 * a Model instance asks the row-level one (view, update, delete on *this*
 * row), which is the only way to exercise a policy's second argument - and
 * therefore the only way to prove the soft-deleted-organization guard below.
 *
 * @param  class-string|Model  $model
 */
function panelAllows(string $ability, Model|string $model): bool
{
    return get_authorization_response($ability, $model)->allowed();
}
```

`use Illuminate\Database\Eloquent\Model;` goes with the file's existing `use` block; every other import and the existing `beforeEach` stay exactly as they are. Then append:

```php
it('refuses every bulk ability on the five plan 4 models', function () {
    // Fact 25: Filament treats a missing policy method as ALLOW, and none of
    // these five models has any bulk UI in any panel, so each policy spells
    // deleteAny, restoreAny and forceDeleteAny out and answers false for every
    // organization member and every outsider. deleteAny is the one Filament
    // actually calls, so it is asserted first rather than left implied.
    $models = [
        App\Models\OrganizationInvitation::class,
        App\Models\ReviewerInvitation::class,
        App\Models\ConferenceReviewer::class,
        App\Models\ReviewAssignment::class,
        App\Models\Review::class,
    ];

    // The platform admin is deliberately NOT in this loop. Unlike the four child
    // policies this file already covers - Track, CustomField, ReviewForm and
    // ReviewQuestion, none of which has a before() at all - all five Plan 4
    // policies declare `before(): ?bool` answering true for is_platform_admin,
    // and Laravel resolves before() AHEAD of the ability itself
    // (Gate::resolvePolicyCallback, vendor/laravel/framework/src/Illuminate/
    // Auth/Access/Gate.php:791-800 returns the non-null before() result without
    // ever calling the method). An explicit `deleteAny(): false` therefore does
    // not beat it, exactly as App\Policies\SubmissionPolicy's own docblock
    // already records and accepts. Asserting otherwise would be asserting
    // against the design.
    foreach ([$this->member, $this->outsider] as $user) {
        actingAs($user);

        foreach ($models as $model) {
            expect(panelAllows('deleteAny', $model))->toBeFalse("deleteAny on {$model}")
                ->and(panelAllows('restoreAny', $model))->toBeFalse("restoreAny on {$model}")
                ->and(panelAllows('forceDeleteAny', $model))->toBeFalse("forceDeleteAny on {$model}");
        }
    }

    // What a platform admin actually gets, pinned here so nobody "fixes" the
    // loop by adding them back, and so Plan 6 adding a screen behind these
    // policies is a decision rather than a discovery: the read-only admin
    // resources it brings have to refuse deletion in before() or in the
    // resource, because the policy method is never reached.
    actingAs($this->admin);

    foreach ($models as $model) {
        expect(panelAllows('deleteAny', $model))->toBeTrue("deleteAny on {$model}")
            ->and(panelAllows('restoreAny', $model))->toBeTrue("restoreAny on {$model}");
    }
});

it('refuses a plan 4 ability over a soft-deleted organization instead of throwing', function () {
    // Organization soft-deletes (app/Models/Organization.php:27) while its
    // conferences and their rows survive, and User::roleIn() type-hints a
    // non-nullable Organization (app/Models/User.php:60) - so a policy that
    // walks conference->organization unguarded turns a gate that must answer
    // "no" into a TypeError 500. SubmissionPolicy already guards that second
    // hop and says why in its docblock; these five now do the same.
    $conference = App\Models\Conference::factory()->for($this->organization)->create();
    $submission = App\Models\Submission::factory()->for($conference)->submitted()->create();
    $reviewerRow = App\Models\ConferenceReviewer::factory()->for($conference)->create();
    $invitation = App\Models\ReviewerInvitation::factory()->for($conference)->create();
    $assignment = App\Models\ReviewAssignment::factory()->for($submission)->create();
    $review = App\Models\Review::factory()->for($submission)->create();

    $this->organization->delete();

    actingAs($this->member);

    expect(panelAllows('view', $invitation->fresh()))->toBeFalse()
        ->and(panelAllows('view', $reviewerRow->fresh()))->toBeFalse()
        ->and(panelAllows('update', $reviewerRow->fresh()))->toBeFalse()
        ->and(panelAllows('view', $assignment->fresh()))->toBeFalse()
        ->and(panelAllows('delete', $assignment->fresh()))->toBeFalse()
        ->and(panelAllows('view', $review->fresh()))->toBeFalse();
});

it('lets an owner and admin manage invitations and refuses a plain member', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);
    $this->organization->addMember($admin, OrganizationRole::Admin);

    foreach ([$owner, $admin] as $user) {
        actingAs($user);
        expect(panelAllows('create', App\Models\OrganizationInvitation::class))->toBeTrue()
            ->and(panelAllows('viewAny', App\Models\OrganizationInvitation::class))->toBeTrue();
    }

    // Spec section 4: "Manage organization members" is owner and admin only,
    // while "Invite reviewers, assign, decide" is every member - so the two
    // invitation policies deliberately answer differently for the same user.
    actingAs($this->member);
    expect(panelAllows('create', App\Models\OrganizationInvitation::class))->toBeFalse()
        ->and(panelAllows('create', App\Models\ReviewerInvitation::class))->toBeTrue()
        ->and(panelAllows('create', App\Models\ReviewAssignment::class))->toBeTrue();

    actingAs($this->outsider);
    expect(panelAllows('create', App\Models\OrganizationInvitation::class))->toBeFalse()
        ->and(panelAllows('create', App\Models\ReviewerInvitation::class))->toBeFalse()
        ->and(panelAllows('create', App\Models\ReviewAssignment::class))->toBeFalse();
});
```

- [ ] **Step 3: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/InvitationModelTest.php tests/Unit/ReviewModelsTest.php tests/Feature/Organizer/ChildPolicyBulkAbilitiesTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -c "Error\|Failed" /tmp/t.log
```

Expected: `rc=1`, and the failures are `Class "App\Models\OrganizationInvitation" not found` and friends. If anything *passes*, a class of that name already exists and you are about to overwrite it.

- [ ] **Step 4: The migrations**

`database/migrations/2026_09_12_000100_create_organization_invitations_table.php`
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
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // Cascade, unlike conferences: an invitation is bookkeeping with no
            // life of its own, exactly like the organization_members row it
            // turns into.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 16);
            // SHA-256 hex of the invitation token (spec section 9). The
            // plaintext exists only inside the emailed link, and `unique` is
            // what makes /invite/{token} a single indexed read.
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // Deliberately an index and NOT unique: a revoked invitation keeps
            // its row, so "invite, revoke, invite again" would fail on a unique
            // key. InviteMember updates the live row instead of inserting.
            $table->index(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
```

`database/migrations/2026_09_12_000200_create_reviewer_invitations_table.php`
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
        Schema::create('reviewer_invitations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('conference_id')->constrained()->cascadeOnDelete();
            // The organizer types a name before any account exists (spec 5.4
            // step 1), and it is what {{reviewer_name}} renders. Nullable
            // because a pasted list may be bare addresses.
            $table->string('name')->nullable();
            $table->string('email');
            // Spec 5.5's conflict rule compares reviewer affiliation with
            // author affiliation, so the affiliation has to exist somewhere
            // before the reviewer has an account. Optional; a null affiliation
            // simply never matches.
            $table->string('affiliation')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['conference_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviewer_invitations');
    }
};
```

`database/migrations/2026_09_12_000300_create_conference_reviewers_table.php`
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
        Schema::create('conference_reviewers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('conference_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // active|removed. A removed reviewer keeps the row so their
            // submitted reviews still have an owner the organizer can name.
            $table->string('status', 16)->default('active');
            $table->string('affiliation')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['conference_id', 'user_id']);
            // The pool query in every open-pool conference, and the
            // "who is still active" query the reminder command runs hourly.
            $table->index(['conference_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conference_reviewers');
    }
};
```

`database/migrations/2026_09_12_000400_create_review_assignments_table.php`
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
        Schema::create('review_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->unique(['submission_id', 'reviewer_user_id']);
            // The reviewer's own queue reads this way round.
            $table->index('reviewer_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_assignments');
    }
};
```

`database/migrations/2026_09_12_000500_create_reviews_table.php`
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
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // RESTRICT on all three: a review is the record of what the
            // committee was told, and Plan 6's hard purge has to delete it
            // deliberately in application code rather than have a cascade do it
            // quietly (spec section 3).
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->restrictOnDelete();
            // Which form this review answers. A conference has exactly one
            // active form today (spec section 3); recording the id is what
            // makes the section 14 "multiple active review forms" item a
            // migration of behaviour rather than of data.
            $table->foreignId('review_form_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();

            // Spec section 8 names this one explicitly.
            $table->unique(['submission_id', 'reviewer_user_id']);
            $table->index(['submission_id', 'status']);
            $table->index(['reviewer_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
```

`database/migrations/2026_09_12_000600_create_review_answers_table.php`
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
        Schema::create('review_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            // RESTRICT is the database-level twin of review_forms.locked_at:
            // once a question has an answer, no code path can hard-delete it.
            $table->foreignId('review_question_id')->constrained()->restrictOnDelete();
            // One column per answer type rather than one polymorphic `value`
            // string: Plan 5 averages the integers, and a text column that
            // sometimes holds "4" is a cast waiting to be got wrong.
            $table->integer('value_int')->nullable();
            $table->mediumText('value_text')->nullable();
            $table->boolean('value_bool')->nullable();
            // The chosen option of a Select question. The key IS the option's
            // label - see App\Models\ReviewQuestion::optionKey() for why.
            $table->string('choice_key', 200)->nullable();
            $table->timestamps();

            $table->unique(['review_id', 'review_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_answers');
    }
};
```

`database/migrations/2026_09_12_000700_create_reviewer_reminders_table.php`
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
        Schema::create('reviewer_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conference_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // days_7 | days_3 | days_1 | overdue. Only the four automatic
            // thresholds live here; a manual "Send reminder now" is throttled by
            // conferences.reviewer_reminded_at instead, because it is allowed to
            // repeat and this unique key exists precisely to stop repeats.
            $table->string('threshold', 16);
            $table->timestamp('sent_at');
            $table->timestamps();

            // The whole point of the table: each threshold at most once per
            // reviewer per conference, enforced by the database rather than by
            // an hourly command remembering what it did last hour.
            $table->unique(['conference_id', 'user_id', 'threshold']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviewer_reminders');
    }
};
```

`database/migrations/2026_09_12_000800_add_reviewer_reminded_at_to_conferences_table.php`
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
        Schema::table('conferences', function (Blueprint $table) {
            // The throttle on the organizer's manual "Send reminder now"
            // (Task 10). On the conference and not in reviewer_reminders,
            // because a manual send is per conference, is allowed to repeat,
            // and must not collide with the unique key that stops the automatic
            // thresholds repeating.
            $table->timestamp('reviewer_reminded_at')->nullable()->after('review_deadline');
        });
    }

    public function down(): void
    {
        Schema::table('conferences', function (Blueprint $table) {
            $table->dropColumn('reviewer_reminded_at');
        });
    }
};
```

`database/migrations/2026_09_12_000900_add_hide_from_reviewers_to_custom_fields_table.php`
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
        Schema::table('custom_fields', function (Blueprint $table) {
            // Spec 5.4 step 4: a blind reviewer must not be handed the author's
            // institution through a question the organizer wrote themselves.
            // Blinding the authors block is worth nothing if an organizer's own
            // "Institution", "Department" or "Funding source" field prints the
            // same answer two sections further down, and nothing in
            // `custom_fields` said which answers identify an author until now.
            // Default false: every field an organizer already created keeps
            // behaving exactly as it did, and hiding one is a deliberate act.
            $table->boolean('hide_from_reviewers')->default(false)->after('required');
        });
    }

    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn('hide_from_reviewers');
        });
    }
};
```

- [ ] **Step 5: The four enums**

`app/Enums/InvitationStatus.php`
```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Derived from `accepted_at`, `revoked_at` and `expires_at`, never stored.
 * `expired` is a function of the clock, so a column would be wrong for exactly
 * as long as nothing ran to correct it.
 */
enum InvitationStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Invited',
            self::Accepted => 'Accepted',
            self::Expired => 'Expired',
            self::Revoked => 'Withdrawn',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Accepted => 'success',
            self::Expired => 'warning',
            self::Revoked => 'gray',
        };
    }

    /** The only state in which /invite/{token} may still be accepted. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
```

`app/Enums/ReviewerStatus.php`
```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReviewerStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Removed = 'removed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Removed => 'Removed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Removed => 'gray',
        };
    }
}
```

`app/Enums/ReviewStatus.php`
```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReviewStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Submitted = 'submitted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'success',
        };
    }

    /** Counted as done by every progress number in this plan. */
    public function countsAsComplete(): bool
    {
        return $this === self::Submitted;
    }
}
```

`app/Enums/ReminderThreshold.php`
```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Spec 5.4 step 5: "the scheduler emails reviewers with outstanding work at 7,
 * 3 and 1 days before the review deadline, and once after it passes".
 *
 * The order of `cases()` is the order they fire in, and
 * App\Support\Reviews\ReminderSchedule::due() walks it - so a conference whose
 * reminders were switched on late fires the *latest* applicable threshold only,
 * rather than three emails in one hour.
 */
enum ReminderThreshold: string implements HasLabel
{
    case Days7 = 'days_7';
    case Days3 = 'days_3';
    case Days1 = 'days_1';
    case Overdue = 'overdue';

    public function getLabel(): string
    {
        return match ($this) {
            self::Days7 => '7 days before the deadline',
            self::Days3 => '3 days before the deadline',
            self::Days1 => '1 day before the deadline',
            self::Overdue => 'After the deadline',
        };
    }

    /** Null for the overdue threshold, which is defined by the deadline having passed. */
    public function daysBefore(): ?int
    {
        return match ($this) {
            self::Days7 => 7,
            self::Days3 => 3,
            self::Days1 => 1,
            self::Overdue => null,
        };
    }

    public function templateKey(): EmailTemplateKey
    {
        return $this === self::Overdue
            ? EmailTemplateKey::ReviewerOverdue
            : EmailTemplateKey::ReviewerReminder;
    }
}
```

- [ ] **Step 6: The `Invitation` contract**

`app/Contracts/Invitation.php`
```php
<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\InvitationStatus;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Everything `/invite/{token}` and App\Actions\Invitations\AcceptInvitation
 * need of an invitation, and nothing else. Two models implement it -
 * OrganizationInvitation and ReviewerInvitation - and they have separate tables
 * with separate real foreign keys (see the Task 1 preamble). This interface is
 * where they are the same.
 *
 * Every implementer is an Eloquent model, so `grantTo()` and `markAccepted()`
 * are free to write with forceFill().
 */
interface Invitation
{
    public function invitationStatus(): InvitationStatus;

    /** Always lower-cased and trimmed: it is compared with users.email. */
    public function invitedEmail(): string;

    /** The name the inviter typed, when there was one to type. */
    public function invitedName(): ?string;

    public function invitingOrganizationName(): string;

    /** One sentence telling the invitee what they are about to accept. */
    public function invitationHeadline(): string;

    public function invitationExpiresAt(): ?CarbonInterface;

    /**
     * Attach the user. Idempotent, and called inside AcceptInvitation's
     * transaction, so it may assume it runs at most once per accept but must
     * survive being called again.
     */
    public function grantTo(User $user): void;

    /** Stamp acceptance. Separate from grantTo() so the order is explicit. */
    public function markAccepted(User $user): void;

    /** Where the invitee lands once the invitation is accepted. */
    public function landingUrl(): string;
}
```

- [ ] **Step 7: The seven models**

`app/Models/OrganizationInvitation.php`
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Invitation;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Filament\Organizer\Pages\Dashboard;
use Carbon\CarbonInterface;
use Database\Factories\OrganizationInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrganizationInvitation extends Model implements Invitation
{
    /** @use HasFactory<OrganizationInvitationFactory> */
    use HasFactory;

    /**
     * Nothing is fillable. `role` and `token_hash` in particular are a
     * privilege escalation with a form field attached; every column is written
     * by InviteMember or AcceptInvitation with forceFill().
     */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrganizationInvitation $invitation): void {
            $invitation->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function invitationStatus(): InvitationStatus
    {
        return match (true) {
            // Revoked wins: an organizer who withdrew an invitation must see
            // that answer whatever the clock or an earlier accept says.
            $this->revoked_at !== null => InvitationStatus::Revoked,
            $this->accepted_at !== null => InvitationStatus::Accepted,
            $this->expires_at !== null && $this->expires_at->isPast() => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    public function invitedEmail(): string
    {
        return mb_strtolower(trim((string) $this->email));
    }

    public function invitedName(): ?string
    {
        return null;
    }

    public function invitingOrganizationName(): string
    {
        return (string) $this->organization->name;
    }

    public function invitationHeadline(): string
    {
        return __('members.invite.headline', [
            'organization' => $this->invitingOrganizationName(),
            'role' => $this->role->getLabel(),
        ]);
    }

    public function invitationExpiresAt(): ?CarbonInterface
    {
        return $this->expires_at;
    }

    public function grantTo(User $user): void
    {
        // A sitting member keeps the role they already have. addMember() is
        // `syncWithoutDetaching([$id => ['role' => …]])`, and sync() UPDATES the
        // pivot of an id it already holds
        // (Illuminate\Database\Eloquent\Relations\Concerns\InteractsWithPivotTable
        // ::attachNew, :250-253) whenever attributes are given - so without this
        // guard an invitation would be a way around ChangeMemberRole's rules:
        // the last-owner invariant, the nobody-changes-their-own-role rule and
        // the owner-grants-owner rule all live there, and a stale link would
        // silently rewrite a sitting admin's row. Changing a role is
        // ChangeMemberRole's job, with a policy in front of it.
        if ($user->roleIn($this->organization) !== null) {
            return;
        }

        $this->organization->addMember($user, $this->role);
    }

    /**
     * The audit entry lives here rather than in AcceptInvitation for two
     * reasons: the description differs per invitation kind (spec section 9 asks
     * for an audit of reviewer removals and organization changes, which are
     * different events), and `activity()->performedOn()` wants a Model - which
     * `$this` is and the `Invitation` interface is not, so putting it in the
     * action would need an intersection type on every signature.
     */
    public function markAccepted(User $user): void
    {
        $this->forceFill(['accepted_at' => now(), 'accepted_by' => $user->getKey()])->save();

        activity()
            ->performedOn($this)
            ->causedBy($user)
            ->withProperties(['organization_id' => $this->organization_id, 'role' => $this->role->value])
            ->log('organization.invitation_accepted');
    }

    public function landingUrl(): string
    {
        return Dashboard::getUrl(panel: 'organizer', tenant: $this->organization);
    }
}
```

`app/Models/ReviewerInvitation.php`
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Invitation;
use App\Enums\InvitationStatus;
use App\Enums\ReviewerStatus;
use Carbon\CarbonInterface;
use Database\Factories\ReviewerInvitationFactory;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ReviewerInvitation extends Model implements Invitation
{
    /** @use HasFactory<ReviewerInvitationFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ReviewerInvitation $invitation): void {
            $invitation->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function invitationStatus(): InvitationStatus
    {
        return match (true) {
            $this->revoked_at !== null => InvitationStatus::Revoked,
            $this->accepted_at !== null => InvitationStatus::Accepted,
            $this->expires_at !== null && $this->expires_at->isPast() => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    public function invitedEmail(): string
    {
        return mb_strtolower(trim((string) $this->email));
    }

    public function invitedName(): ?string
    {
        $name = trim((string) $this->name);

        return $name === '' ? null : $name;
    }

    public function invitingOrganizationName(): string
    {
        return (string) $this->conference->organization->name;
    }

    public function invitationHeadline(): string
    {
        return __('reviewer.invite.headline', [
            'organization' => $this->invitingOrganizationName(),
            'conference' => (string) $this->conference->name,
        ]);
    }

    public function invitationExpiresAt(): ?CarbonInterface
    {
        return $this->expires_at;
    }

    public function grantTo(User $user): void
    {
        /** @var ConferenceReviewer $reviewer */
        $reviewer = ConferenceReviewer::query()->firstOrNew([
            'conference_id' => $this->conference_id,
            'user_id' => $user->getKey(),
        ]);

        $reviewer->forceFill([
            'conference_id' => $this->conference_id,
            'user_id' => $user->getKey(),
            'status' => ReviewerStatus::Active,
            // Only overwritten when the invitation carried one, so accepting a
            // second, bare invitation does not erase an affiliation the
            // organizer typed the first time.
            'affiliation' => $this->affiliation ?? $reviewer->affiliation,
            'invited_by' => $this->invited_by ?? $reviewer->invited_by,
            'invited_at' => $reviewer->invited_at ?? $this->created_at ?? now(),
            'accepted_at' => $reviewer->accepted_at ?? now(),
            // Reactivation: a reviewer the organizer removed and re-invited is
            // active again, and their old reviews are still theirs.
            'removed_at' => null,
        ])->save();
    }

    /** See the twin comment on OrganizationInvitation::markAccepted(). */
    public function markAccepted(User $user): void
    {
        $this->forceFill(['accepted_at' => now(), 'accepted_by' => $user->getKey()])->save();

        activity()
            ->performedOn($this)
            ->causedBy($user)
            ->withProperties(['conference_id' => $this->conference_id])
            ->log('reviewer.invitation_accepted');
    }

    public function landingUrl(): string
    {
        // isStrict: false, so this answers correctly in a request where the
        // reviewer panel is not registered - which is every request until
        // Task 5 adds ReviewerPanelProvider. Once it is registered the panel's
        // own path is `review`, so the value does not change.
        return Filament::getPanel('reviewer', isStrict: false)?->getUrl() ?? url('/review');
    }
}
```

`app/Models/ConferenceReviewer.php`
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewerStatus;
use Database\Factories\ConferenceReviewerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A first-class model rather than a Pivot, even though it sits between
 * `conferences` and `users`: it carries a ULID, a status, an affiliation and a
 * policy of its own, it is the row an organizer acts on in the Reviewers page,
 * and making it a Pivot would mean `Conference::activeReviewers()` returning
 * User models whose interesting attributes all live behind `->pivot`.
 */
class ConferenceReviewer extends Model
{
    /** @use HasFactory<ConferenceReviewerFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ReviewerStatus::class,
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ConferenceReviewer $reviewer): void {
            $reviewer->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isActive(): bool
    {
        return $this->status === ReviewerStatus::Active;
    }

    /**
     * @param  Builder<ConferenceReviewer>  $query
     * @return Builder<ConferenceReviewer>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ReviewerStatus::Active->value);
    }
}
```

`app/Models/ReviewAssignment.php`
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReviewAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewAssignment extends Model
{
    /** @use HasFactory<ReviewAssignmentFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
```

`app/Models/Review.php`
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewStatus;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * Answers are written through ReviewAnswer, and every column here is a
     * transition (SaveReviewDraft, SubmitReview, ReopenReview) rather than a
     * field on a form.
     */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'submitted_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Review $review): void {
            $review->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    /** @return BelongsTo<ReviewForm, $this> */
    public function reviewForm(): BelongsTo
    {
        return $this->belongsTo(ReviewForm::class);
    }

    /** @return HasMany<ReviewAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(ReviewAnswer::class);
    }

    public function isSubmitted(): bool
    {
        return $this->status === ReviewStatus::Submitted;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // Never the answers: the activity log is readable by the platform
            // admin and a review's content is not audit data.
            ->logOnly(['status', 'submitted_at', 'reopened_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
```

`app/Models/ReviewAnswer.php`
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewQuestionType;
use Database\Factories\ReviewAnswerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewAnswer extends Model
{
    /** @use HasFactory<ReviewAnswerFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value_int' => 'integer',
            'value_bool' => 'boolean',
        ];
    }

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /** @return BelongsTo<ReviewQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ReviewQuestion::class, 'review_question_id');
    }

    /**
     * The one value this answer actually carries, chosen by the question's
     * type rather than by which column happens to be non-null - a Likert answer
     * of 0 and a boolean answer of false are both real answers, and `??` over
     * four columns would swallow them.
     */
    public function value(): int|string|bool|null
    {
        return match ($this->question->type) {
            ReviewQuestionType::Likert => $this->value_int,
            ReviewQuestionType::Text => $this->value_text,
            ReviewQuestionType::Boolean => $this->value_bool,
            ReviewQuestionType::Select => $this->choice_key,
        };
    }
}
```

`app/Models/ReviewerReminder.php`
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReminderThreshold;
use Database\Factories\ReviewerReminderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (conference, reviewer, threshold), written the moment the email
 * is queued. The unique key on those three columns is the whole idempotency
 * story: the hourly command does not have to remember anything, and a second
 * run in the same hour - or a worker that restarts mid-send - cannot send
 * twice.
 */
class ReviewerReminder extends Model
{
    /** @use HasFactory<ReviewerReminderFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'threshold' => ReminderThreshold::class,
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 8: The model modifications**

`app/Models/Conference.php` — add `reviewer_reminded_at` to `casts()`, and add these relations and methods after `emailTemplates()`:

```php
    /** @return HasMany<ReviewerInvitation, $this> */
    public function reviewerInvitations(): HasMany
    {
        return $this->hasMany(ReviewerInvitation::class);
    }

    /** @return HasMany<ConferenceReviewer, $this> */
    public function reviewers(): HasMany
    {
        return $this->hasMany(ConferenceReviewer::class);
    }

    /**
     * The pool. Every reviewer query in Plan 4 starts here, so "active" has one
     * definition.
     *
     * @return HasMany<ConferenceReviewer, $this>
     */
    public function activeReviewers(): HasMany
    {
        return $this->reviewers()->where('status', ReviewerStatus::Active->value);
    }

    /** @return HasMany<ReviewerReminder, $this> */
    public function reviewerReminders(): HasMany
    {
        return $this->hasMany(ReviewerReminder::class);
    }

    /**
     * Spec 5.4 step 4: "authors hidden when blind". Blind review is a rule
     * about *reviewers*, not about the conference as a whole - spec section 4
     * gives every organization member "View submissions and files" with no
     * caveat, and somebody has to be able to answer an author's email. So this
     * asks who is looking.
     *
     * One method, called by the reviewer's queue, the review page, the file
     * naming and their tests, so "blind" cannot come to mean four things.
     */
    public function hidesAuthorsFrom(?User $user): bool
    {
        if ($this->blind_review !== true) {
            return false;
        }

        if ($user === null) {
            return true;
        }

        if ($user->is_platform_admin === true) {
            return false;
        }

        return $user->roleIn($this->organization) === null;
    }

    /** Timestamps are stored UTC; organizers and reviewers read them locally. */
    public function reviewDeadlineInConferenceTimezone(): ?CarbonInterface
    {
        return $this->review_deadline?->copy()->setTimezone($this->timezone);
    }

    /** The window in which a reviewer may still change a submitted review. */
    public function reviewWindowIsOpen(): bool
    {
        return $this->review_deadline === null || $this->review_deadline->isFuture();
    }

    /** Conferences a reviewer may open at all (spec 5.4 step 3). */
    public function isOpenToReviewers(): bool
    {
        return in_array($this->status, [ConferenceStatus::Reviewing, ConferenceStatus::Decided], true);
    }

    /**
     * READING a conference is open in Reviewing and in Decided - a reviewer who
     * wants to see what they said about an abstract after the committee has
     * decided should be able to. WRITING a review is open in Reviewing only:
     * once the decisions are out, a new answer would change the evidence the
     * committee was shown after the fact.
     *
     * Kept separate from isOpenToReviewers() on purpose. Every read path
     * (ReviewerScope::constrain, the queue, the dashboard) asks that one; every
     * write path (SaveReviewDraft, SubmitReview, ReopenReview, the review form's
     * read-only rule) asks this one.
     */
    public function acceptsReviewWrites(): bool
    {
        return $this->status === ConferenceStatus::Reviewing;
    }
```

with `use App\Enums\ReviewerStatus;` added to the imports. (`CarbonInterface`, `ConferenceStatus`, `HasMany` and `User` are already imported or in the same namespace.)

`app/Models/Organization.php` — add after `conferences()`:

```php
    /** @return HasMany<OrganizationInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }
```

`app/Models/CustomField.php` — add `'hide_from_reviewers'` to `$fillable` (after `'required'`) and `'hide_from_reviewers' => 'boolean'` to `casts()`. The column is organizer-authored configuration on a tenant-scoped relation manager, exactly like `required`, so it is fillable rather than forceFill-only:

```php
    protected $fillable = [
        'key',
        'label',
        'help_text',
        'type',
        'options',
        'required',
        // Spec 5.4 step 4. A blind reviewer must not read the author's
        // institution out of a question the organizer wrote; Task 6's
        // ReviewSubmission::getCustomFieldLines() drops every field with this
        // set when Conference::hidesAuthorsFrom() says the reader is blinded.
        'hide_from_reviewers',
        'sort',
    ];
```

`app/Filament/Organizer/Resources/Conferences/RelationManagers/CustomFieldsRelationManager.php` — add the toggle to the form, after the `required` toggle:

```php
                Toggle::make('hide_from_reviewers')
                    ->label(__('reviewer.review.hide_from_reviewers'))
                    ->helperText(__('reviewer.review.hide_from_reviewers_help'))
                    ->default(false),
```

with `use Filament\Forms\Components\Toggle;` already imported for the `required` toggle.

`app/Models/Submission.php` — add after `files()`:

```php
    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** @return HasMany<ReviewAssignment, $this> */
    public function reviewAssignments(): HasMany
    {
        return $this->hasMany(ReviewAssignment::class);
    }

    /** Spec 5.4 step 3: the queue is every *submitted* abstract. */
    public function isReviewable(): bool
    {
        return in_array($this->status, [SubmissionStatus::Submitted, SubmissionStatus::UnderReview], true);
    }
```

`app/Models/User.php` — add after `organizations()`:

```php
    /** @return HasMany<ConferenceReviewer, $this> */
    public function conferenceReviewerships(): HasMany
    {
        return $this->hasMany(ConferenceReviewer::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewer_user_id');
    }

    /** @return HasMany<ReviewAssignment, $this> */
    public function reviewAssignments(): HasMany
    {
        return $this->hasMany(ReviewAssignment::class, 'reviewer_user_id');
    }

    /**
     * The gate on the reviewer panel (wired in Task 5) and on every reviewer
     * query. A removed reviewer is not one.
     */
    public function isActiveReviewer(?Conference $conference = null): bool
    {
        $query = $this->conferenceReviewerships()->where('status', ReviewerStatus::Active->value);

        if ($conference !== null) {
            $query->where('conference_id', $conference->getKey());
        }

        return $query->exists();
    }
```

with `use App\Enums\ReviewerStatus;`, `use Illuminate\Database\Eloquent\Relations\HasMany;` added to the imports.

`app/Models/ReviewForm.php` — add after `questions()`:

```php
    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Spec section 3: the form locks when the first review is submitted.
     *
     * A conditional UPDATE rather than a read-then-write, so two reviewers
     * pressing Submit in the same second cannot both believe they were first
     * and stamp two different `locked_at` values. Returns whether *this* call
     * did the locking, which is what SubmitReview logs.
     */
    public function lockIfUnlocked(): bool
    {
        $locked = static::query()
            ->whereKey($this->getKey())
            ->whereNull('locked_at')
            ->update(['locked_at' => now(), 'updated_at' => now()]) === 1;

        if ($locked) {
            $this->forceFill(['locked_at' => now()])->syncOriginal();
        }

        return $locked;
    }
```

with `use Illuminate\Database\Eloquent\Relations\HasMany;` added.

`app/Models/ReviewQuestion.php` — add after `isScored()`:

```php
    /**
     * The stable identifier of one Select choice, stored in
     * `review_answers.choice_key`.
     *
     * **The key is the label.** The alternatives are worse: an array index
     * moves the moment an organizer reorders the Repeater, and a synthetic id
     * would have to be back-filled onto every `options` JSON blob Plan 2
     * already wrote. The label is safe because spec section 3 locks the form
     * the moment the first review is submitted, and a locked question can
     * neither be edited nor reordered (ReviewQuestion::booted() and
     * ReviewQuestionPolicy) - so from the instant any answer exists, the label
     * cannot change. Before the lock, an edited label simply loses its score,
     * which is visible in the draft rather than silently wrong.
     *
     * This method exists so Plan 5 has one place to change if real keys are
     * ever introduced.
     *
     * @param  array<string, mixed>  $option
     */
    public static function optionKey(array $option): string
    {
        return (string) ($option['label'] ?? '');
    }

    /** @return list<string> */
    public function optionKeys(): array
    {
        return array_values(array_map(
            static fn (mixed $option): string => is_array($option) ? static::optionKey($option) : (string) $option,
            $this->options ?? [],
        ));
    }

    /**
     * Label by key, for a Radio or Select component and for the read-only
     * render of a submitted review.
     *
     * @return array<string, string>
     */
    public function optionLabels(): array
    {
        $labels = [];

        foreach ($this->optionKeys() as $key) {
            $labels[$key] = $key;
        }

        return $labels;
    }

    /** Spec 5.6: a choice carries an optional score. Plan 5 reads this. */
    public function optionScore(string $key): int|float|null
    {
        foreach ($this->options ?? [] as $option) {
            if (is_array($option) && static::optionKey($option) === $key) {
                $score = $option['score'] ?? null;

                return is_numeric($score) ? (int) $score : null;
            }
        }

        return null;
    }
```

`config/cass.php` — append before `'countries' => [`:

```php
    // Spec 5.4 step 1 and spec section 9: a 14-day hashed-token invitation,
    // and the invitation-accept rate limit of 10 per minute per address.
    'invitations' => [
        'expiry_days' => (int) env('CASS_INVITATION_EXPIRY_DAYS', 14),
        'accept_rate_limit' => (int) env('CASS_INVITATION_RATE_LIMIT', 10),
        // Spec section 9's "login 5/min/email+IP", applied to the password
        // confirmation on /invite/{token} - the one place outside a Filament
        // panel where a password is checked at all.
        'login_rate_limit' => (int) env('CASS_INVITATION_LOGIN_RATE_LIMIT', 5),
        // A pasted reviewer list is a bulk-mail primitive available to every
        // organization member: cap one batch and meter the actor, or one member
        // can spend the platform's sending reputation in a single click.
        // `list_max` mirrors App\Support\Reviews\ReviewerList::MAX_ENTRIES for
        // anything that wants to read the bound from configuration; the parser
        // itself uses the constant, because a parser with no database must not
        // need a container to answer.
        'list_max' => (int) env('CASS_INVITATION_LIST_MAX', 100),
        'send_rate_limit' => (int) env('CASS_INVITATION_SEND_LIMIT', 200),
    ],

    'review' => [
        // Spec 5.5 skips a reviewer "whose email domain matches an author's
        // email domain". Applied literally in this region that rule would skip
        // almost everybody, because most authors and most reviewers use a free
        // mailbox. A domain on this list is never treated as a conflict on its
        // own; an exact email match still is.
        'free_email_domains' => [
            'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.co.uk', 'outlook.com',
            'live.com', 'msn.com', 'yahoo.com', 'yahoo.co.uk', 'ymail.com', 'icloud.com',
            'me.com', 'aol.com', 'gmx.com', 'proton.me', 'protonmail.com', 'zoho.com',
            'qq.com', '163.com', 'mail.ru', 'yandex.com',
        ],
    ],

    'reminders' => [
        // Reviewer reminders go out in the first run of the hour at or after
        // this local hour in the conference's own timezone (spec section 10:
        // timezone per conference).
        'send_hour' => (int) env('CASS_REMINDER_HOUR', 7),
        // The organizer's manual "Send reminder now", per conference.
        'manual_throttle_hours' => (int) env('CASS_REMINDER_MANUAL_THROTTLE_HOURS', 12),
    ],
```

- [ ] **Step 9: The seven factories**

`database/factories/OrganizationInvitationFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OrganizationInvitation> */
class OrganizationInvitationFactory extends Factory
{
    protected $model = OrganizationInvitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory()->approved(),
            'email' => fake()->unique()->safeEmail(),
            'role' => OrganizationRole::Member,
            // A hash of *something*, not of a token any test can use: a test
            // that needs a working link generates the plaintext itself and
            // passes ['token_hash' => InvitationToken::hash($plain)].
            'token_hash' => hash('sha256', (string) Str::ulid()),
            'invited_by' => null,
            'expires_at' => now()->addDays((int) config('cass.invitations.expiry_days')),
            'accepted_at' => null,
            'accepted_by' => null,
            'revoked_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
```

`database/factories/ReviewerInvitationFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conference;
use App\Models\ReviewerInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ReviewerInvitation> */
class ReviewerInvitationFactory extends Factory
{
    protected $model = ReviewerInvitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conference_id' => Conference::factory()->published(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'affiliation' => null,
            'token_hash' => hash('sha256', (string) Str::ulid()),
            'invited_by' => null,
            'expires_at' => now()->addDays((int) config('cass.invitations.expiry_days')),
            'accepted_at' => null,
            'accepted_by' => null,
            'revoked_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
```

`database/factories/ConferenceReviewerFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReviewerStatus;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConferenceReviewer> */
class ConferenceReviewerFactory extends Factory
{
    protected $model = ConferenceReviewer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conference_id' => Conference::factory()->published(),
            'user_id' => User::factory(),
            'status' => ReviewerStatus::Active,
            'affiliation' => null,
            'invited_by' => null,
            'invited_at' => now()->subWeek(),
            'accepted_at' => now()->subDays(6),
            'removed_at' => null,
        ];
    }

    public function removed(): static
    {
        return $this->state(fn () => [
            'status' => ReviewerStatus::Removed,
            'removed_at' => now(),
        ]);
    }
}
```

`database/factories/ReviewAssignmentFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewAssignment> */
class ReviewAssignmentFactory extends Factory
{
    protected $model = ReviewAssignment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory()->submitted(),
            'reviewer_user_id' => User::factory(),
            'assigned_by' => null,
            'assigned_at' => now(),
        ];
    }
}
```

`database/factories/ReviewFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Enums\ReviewStatus;
use App\Models\Review;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Review> */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory()->submitted(),
            'reviewer_user_id' => User::factory(),
            // Derived from the submission rather than a free-standing
            // ReviewForm::factory(): a review answered against another
            // conference's form would satisfy every foreign key and mean
            // nothing. Factory closures receive the already-resolved
            // attributes, so `submission_id` is a real id by the time this runs.
            'review_form_id' => function (array $attributes): int {
                $submission = Submission::query()->findOrFail($attributes['submission_id']);

                return app(CreateDefaultReviewForm::class)->handle($submission->conference)->id;
            },
            'status' => ReviewStatus::Draft,
            'submitted_at' => null,
            'reopened_at' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => ReviewStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }
}
```

`database/factories/ReviewAnswerFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewAnswer> */
class ReviewAnswerFactory extends Factory
{
    protected $model = ReviewAnswer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'review_id' => Review::factory(),
            'review_question_id' => ReviewQuestion::factory(),
            'value_int' => 4,
            'value_text' => null,
            'value_bool' => null,
            'choice_key' => null,
        ];
    }
}
```

`database/factories/ReviewerReminderFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReminderThreshold;
use App\Models\Conference;
use App\Models\ReviewerReminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewerReminder> */
class ReviewerReminderFactory extends Factory
{
    protected $model = ReviewerReminder::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conference_id' => Conference::factory()->published(),
            'user_id' => User::factory(),
            'threshold' => ReminderThreshold::Days7,
            'sent_at' => now(),
        ];
    }
}
```

- [ ] **Step 10: The five policies**

`app/Policies/OrganizationInvitationPolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Spec section 4: "Manage organization members" belongs to the platform admin,
 * the org owner and the org admin - and to nobody else. A plain member sees the
 * Members page (Task 3 hides the page from them entirely through canAccess())
 * and can change nothing.
 */
class OrganizationInvitationPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->managesCurrentTenant($user);
    }

    public function create(User $user): bool
    {
        return $this->managesCurrentTenant($user);
    }

    /**
     * The `$organization !== null` hop is not defensive noise: Organization
     * soft-deletes (app/Models/Organization.php:27) while its invitations
     * survive, and User::roleIn() type-hints a non-nullable Organization
     * (app/Models/User.php:60) - so walking the relation unguarded turns a gate
     * that must answer "no" into a TypeError 500. SubmissionPolicy has carried
     * this same guard, and this same comment, since Plan 3.
     */
    public function view(User $user, OrganizationInvitation $invitation): bool
    {
        $organization = $invitation->organization;

        return $organization !== null
            && ($user->roleIn($organization)?->canManageOrganization() ?? false);
    }

    /** Resending replaces the token on the row, so it is an update. */
    public function update(User $user, OrganizationInvitation $invitation): bool
    {
        return $this->view($user, $invitation);
    }

    /** Revoking. The row survives; `revoked_at` is stamped. */
    public function delete(User $user, OrganizationInvitation $invitation): bool
    {
        return $this->view($user, $invitation);
    }

    /** Fact 25: a missing method is ALLOW, so every bulk ability is stated. */
    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, OrganizationInvitation $invitation): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, OrganizationInvitation $invitation): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function managesCurrentTenant(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization
            && ($user->roleIn($tenant)?->canManageOrganization() ?? false);
    }
}
```

`app/Policies/ReviewerInvitationPolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\ReviewerInvitation;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Spec section 4 gives "Invite reviewers, assign, decide" to *every*
 * organization member, including a plain `member` - unlike member management
 * above, which is owner and admin only. The two policies therefore answer
 * differently for the same person on purpose.
 */
class ReviewerInvitationPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->isMemberOfCurrentTenant($user);
    }

    public function create(User $user): bool
    {
        return $this->isMemberOfCurrentTenant($user);
    }

    /**
     * The same null guard as SubmissionPolicy::view(), for the same reason, and
     * BOTH hops of it. The organizer panel's tenant-scoped ConferenceResource
     * puts a global scope on the Conference model, so another tenant's
     * conference - and a soft-deleted one - resolves to null here; and
     * Organization soft-deletes too (app/Models/Organization.php:27), so the
     * second hop is null whenever the tenant itself is in the bin while its
     * conference row survives. User::roleIn() type-hints a non-nullable
     * Organization (app/Models/User.php:60), so walking the chain unguarded
     * turns a gate that must answer "no" into a TypeError 500. A gate answers
     * "no", it does not fatal.
     */
    public function view(User $user, ReviewerInvitation $invitation): bool
    {
        $organization = $invitation->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    public function update(User $user, ReviewerInvitation $invitation): bool
    {
        return $this->view($user, $invitation);
    }

    public function delete(User $user, ReviewerInvitation $invitation): bool
    {
        return $this->view($user, $invitation);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ReviewerInvitation $invitation): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ReviewerInvitation $invitation): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function isMemberOfCurrentTenant(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }
}
```

`app/Policies/ConferenceReviewerPolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

class ConferenceReviewerPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        // True in the organizer panel for a member of the tenant, and true in
        // the reviewer panel - which has no tenant at all - for anyone who is
        // an active reviewer somewhere. The row-level view() below is what
        // actually keeps one reviewer out of another's row.
        return $this->isMemberOfCurrentTenant($user) || $user->isActiveReviewer();
    }

    /**
     * Both hops guarded, as in SubmissionPolicy: the conference can be null
     * behind the tenant scope, and the organization can be null on its own
     * because Organization soft-deletes while this row survives. roleIn() takes
     * a non-nullable Organization, so an unguarded walk is a 500 where a "no"
     * belongs.
     */
    public function view(User $user, ConferenceReviewer $reviewer): bool
    {
        if ($reviewer->user_id === $user->getKey()) {
            return true;
        }

        $organization = $reviewer->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    /** Reviewers are created by accepting an invitation, never by a form. */
    public function create(User $user): bool
    {
        return false;
    }

    /** "Remove reviewer" is an update: the row survives with status `removed`. */
    public function update(User $user, ConferenceReviewer $reviewer): bool
    {
        $organization = $reviewer->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    public function delete(User $user, ConferenceReviewer $reviewer): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ConferenceReviewer $reviewer): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ConferenceReviewer $reviewer): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function isMemberOfCurrentTenant(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }
}
```

`app/Policies/ReviewAssignmentPolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Assignment is an organizer capability (spec section 4, "Invite reviewers,
 * assign, decide"). A reviewer can *see* that they were assigned - that is
 * their queue - but every write here belongs to the organization.
 */
class ReviewAssignmentPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->isMemberOfCurrentTenant($user) || $user->isActiveReviewer();
    }

    public function view(User $user, ReviewAssignment $assignment): bool
    {
        if ($assignment->reviewer_user_id === $user->getKey()) {
            return true;
        }

        return $this->belongsToAnOrganizationOf($user, $assignment);
    }

    public function create(User $user): bool
    {
        return $this->isMemberOfCurrentTenant($user);
    }

    public function update(User $user, ReviewAssignment $assignment): bool
    {
        return $this->belongsToAnOrganizationOf($user, $assignment);
    }

    /** Unassigning really does delete the row (Task 8). */
    public function delete(User $user, ReviewAssignment $assignment): bool
    {
        return $this->belongsToAnOrganizationOf($user, $assignment);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ReviewAssignment $assignment): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ReviewAssignment $assignment): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Three hops, all three guarded: the submission can be null behind a scope,
     * the conference can be null behind the tenant scope, and the organization
     * can be null on its own because Organization soft-deletes while this row
     * survives. roleIn() takes a non-nullable Organization, so the unguarded
     * walk is a TypeError 500 where a "no" belongs - the same guard
     * SubmissionPolicy has carried since Plan 3.
     */
    private function belongsToAnOrganizationOf(User $user, ReviewAssignment $assignment): bool
    {
        $organization = $assignment->submission?->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    private function isMemberOfCurrentTenant(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }
}
```

`app/Policies/ReviewPolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Ownership only. Every *rule* about reviews - whether the submission is in the
 * reviewer's pool, whether the review deadline has passed, whether the form is
 * locked - lives in App\Support\Reviews\ReviewerScope and in the three action
 * classes of Task 7, because a policy is handed a model and no clock and no
 * conference state.
 *
 * `submit` and `reopen` are custom abilities so that Filament's
 * `Gate::allows()` in an action's `visible()` reads the same way as everything
 * else in this codebase; both mirror `update`.
 */
class ReviewPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->isMemberOfCurrentTenant($user) || $user->isActiveReviewer();
    }

    /**
     * The reviewer who wrote it, or a member of the organization that owns the
     * conference - spec section 4 gives organizers the "decide" capability, and
     * Plan 5's ranking table reads reviews through this gate. Plan 4 has no
     * organizer-facing screen that shows a review's content; it shows counts.
     *
     * All three hops guarded, as in SubmissionPolicy: the organization hop is
     * null whenever the tenant is soft-deleted while the review survives, and
     * roleIn() takes a non-nullable Organization.
     */
    public function view(User $user, Review $review): bool
    {
        if ($review->reviewer_user_id === $user->getKey()) {
            return true;
        }

        $organization = $review->submission?->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    /** Whether this person may ever write a review at all. */
    public function create(User $user): bool
    {
        return $user->isActiveReviewer();
    }

    public function update(User $user, Review $review): bool
    {
        return $review->reviewer_user_id === $user->getKey();
    }

    public function submit(User $user, Review $review): bool
    {
        return $this->update($user, $review);
    }

    public function reopen(User $user, Review $review): bool
    {
        return $this->update($user, $review);
    }

    /** Nobody deletes a review, in any panel. Withdrawing an abstract does not either. */
    public function delete(User $user, Review $review): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Review $review): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Review $review): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function isMemberOfCurrentTenant(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }
}
```

- [ ] **Step 11: Two language stubs so `invitationHeadline()` resolves**

`lang/en/members.php` (the file grows in Task 3 and is finished in Task 12; these two keys exist now because `OrganizationInvitation::invitationHeadline()` reads one of them)
```php
<?php

declare(strict_types=1);

/*
 * The organizer's Members page and the whole public /invite/{token} page. Spec
 * section 10: locale `en` only in v1, with every string here so Arabic is a
 * copy of this file and not a branch in a Blade view.
 */

return [

    'invite' => [
        'headline' => ':organization has invited you to join their team on CASS as :role.',
    ],

];
```

`lang/en/reviewer.php`
```php
<?php

declare(strict_types=1);

/*
 * The reviewer panel's own views, and the reviewer half of /invite/{token}.
 */

return [

    'invite' => [
        'headline' => ':organization has invited you to review abstracts for :conference.',
    ],

    // The organizer-facing half of the blind-review rule: this key labels the
    // toggle CustomFieldsRelationManager gains in Step 10, and Task 6 reads the
    // column it sets.
    'review' => [
        'hide_from_reviewers' => 'Hide this answer from reviewers',
        'hide_from_reviewers_help' => 'Turn this on for anything that identifies an author - institution, department, funding source. In a blind conference the answer is not shown on the review page.',
    ],

];
```

- [ ] **Step 12: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/InvitationModelTest.php tests/Unit/ReviewModelsTest.php tests/Feature/Organizer/ChildPolicyBulkAbilitiesTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -5 /tmp/t.log
```

Expected: `rc=0`, about 23 passed — two more than the plan's first draft, because Step 2 now also pins `Conference::acceptsReviewWrites()` (`ReviewModelsTest`) and the soft-deleted-organization case over all five new policies (`ChildPolicyBulkAbilitiesTest`).

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test > /tmp/t.log 2>&1; echo "rc=$?"; tail -5 /tmp/t.log
```

Expected: `rc=0`, `baseline + 19` passed.

- [ ] **Step 13: Pint and Larastan**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log
```

Expected: both `rc=0`. The two places Larastan level 6 usually complains here are the `HasMany` PHPDoc generics (every new relation carries `@return HasMany<Model, $this>`) and `ReviewFactory`'s closure, whose `array $attributes` parameter needs no annotation because `findOrFail()` narrows the result.

- [ ] **Step 14: Commit**

The whole suite gates this commit, the same as every other task's: `&&`, not
`;`, so a single red test anywhere stops the `git commit` rather than printing a
non-zero code next to a commit that already happened.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(reviews): schema, models, factories and policies for invitations, reviewers and reviews

Two invitation tables rather than one polymorphic one, because a polymorphic
invitable cannot carry a real foreign key (spec section 3) and the payloads
differ. Invitation status is derived from the timestamps: expired is a function
of the clock and a column would be wrong until something ran to agree with it.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

---
### Task 2: Invitation tokens, `/invite/{token}` and the accept flow

Spec 5.4 step 2 and the `/invite/{token}` row of section 6, built once and shared by both invitation kinds. Nothing in this task *creates* an invitation — Tasks 3 and 4 do — so every test here builds rows with the factories from Task 1 and a token it generates itself.

**The contract, stated once so Tasks 3 and 4 cannot drift from it:**

| Class | Signature |
|---|---|
| `App\Support\Tokens\InvitationToken` | `static generate(): string`, `static hash(string $plain): string` |
| `App\Support\Invitations\InvitationLookup` | `find(string $plainToken): ?Invitation` |
| `App\Actions\Invitations\AcceptInvitation` | `blockers(Invitation $invitation, ?User $user): list<string>` |
| `App\Actions\Invitations\AcceptInvitation` | `handle(Invitation $invitation, User $user): Invitation` |
| `App\Actions\Invitations\AcceptInvitation` | `forNewAccount(Invitation $invitation, string $name, string $password): User` |

**Why an expired or revoked token is a page and not a 404.** `/s/{token}` answers 404 for anything that does not resolve, because there the token is the *only* credential and a distinguishable answer is an oracle. Here the answer is different by design: if the token resolves at all, the person holding it is the person the invitation was emailed to, so telling them "this invitation expired on 25 September — ask the organizer to send another" leaks nothing they did not already know and saves a support email. A token that resolves to **nothing** is still a flat 404, from the same line, whether it was never real or was deleted.

**How this interacts with `MustVerifyEmail`.** `App\Models\User` implements `MustVerifyEmail` and all three panels call `->emailVerification()`, so an unverified account is bounced to the prompt. A person who reached `/invite/{token}` has just clicked a 64-character secret that was only ever sent to that mailbox — which is exactly the proof `VerifyEmail` asks for, delivered by the same mechanism. `AcceptInvitation` therefore calls `$user->markEmailAsVerified()` (fact 6) instead of queueing a second verification email, for a brand-new account *and* for an existing unverified one. The contract is still implemented, `hasVerifiedEmail()` is true, and the invitee reaches their panel on the first request instead of being sent to a prompt for an email they have already proved. **This is the only place in the application that grants verification without the `VerifyEmail` notification**, it is called out in the runbook (Task 12), and it is the reason the accept flow refuses outright when the signed-in user's address does not match the invitation: without that refusal, "accept while signed in as someone else" would be a way to verify an address you do not control.

**Why `signIn()` verifies a password but does not sign anybody in.** `AdminPanelProvider` and `OrganizerPanelProvider` both call `->multiFactorAuthentication([AppAuthentication::make()->recoverable()])`, and Filament challenges that factor in exactly one place — `Filament\Auth\Pages\Login::authenticate()`, `vendor/filament/filament/src/Auth/Pages/Login.php:129-138`. No Filament HTTP middleware re-checks it per request. So an `Auth::attempt()` on this public page would hand out a fully authenticated panel session with the second factor skipped: an attacker with mailbox access — who can already reset the password, and whom MFA exists to stop anyway — would need only one pending invitation to walk into the organizer panel. `signIn()` therefore does a `Hash::check` against the account the invitation names, grants the membership or reviewership, and redirects to `landingUrl()` **unauthenticated**. `Filament\Http\Middleware\Authenticate` there stores the intended URL and sends them to the panel login, which does challenge the factor. The accept must come *first*: `User::canAccessPanel('organizer')` is `organizations()->exists()`, false until the membership exists, so a redirect to the login before accepting would be refused with "these credentials do not match our records" and the invitation could never be accepted at all. `createAccount()` keeps its `Auth::login()` — a brand-new account has no second factor to bypass, and requiring one it has not set up yet would strand the invitee.

**Rate limits (spec section 9, "invitation accept 10/min/IP").** The route carries `throttle:invitation-accept`, which covers the GET — the only thing an enumeration attack can loop. The three Livewire actions POST to `/livewire/update`, which no route middleware on `/invite/...` ever sees (the same reason Plan 3 put the submission throttle inside the component), so the component spends **its own** 10/min/IP budget on every action, keyed `'invitation-accept|'.ClientIp::from(request())`, and the sign-in action additionally spends spec section 9's "login 5/min/email+IP" budget. These are deliberately **two buckets and not one**: `ThrottleRequests::handleRequestUsingNamedLimiter()` stores a named limiter under `md5($limiterName.$limit->key)` (`vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php:134`), a private key format application code must not reproduce to share. Each bucket is the spec's 10/min/IP, so a page view does not eat an accept, and neither path can be looped.

**Files:**
- Create: `app/Support/Tokens/InvitationToken.php`, `app/Support/Invitations/InvitationLookup.php`
- Create: `app/Actions/Invitations/AcceptInvitation.php`, `app/Exceptions/InvitationNotAcceptable.php`
- Create: `app/Livewire/Public/AcceptInvitation.php`, `resources/views/livewire/public/accept-invitation.blade.php`
- Modify: `routes/web.php`, `app/Providers/AppServiceProvider.php`, `lang/en/members.php`, `lang/en/reviewer.php`
- Test: `tests/Unit/InvitationTokenTest.php`, `tests/Feature/Public/AcceptInvitationTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/InvitationTokenTest.php`
```php
<?php

declare(strict_types=1);

use App\Models\Conference;
use App\Models\OrganizationInvitation;
use App\Models\ReviewerInvitation;
use App\Support\Invitations\InvitationLookup;
use App\Support\Tokens\InvitationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('mints 64 hex characters and never the same one twice', function () {
    $tokens = collect(range(1, 25))->map(fn (): string => InvitationToken::generate());

    expect($tokens->unique())->toHaveCount(25);

    foreach ($tokens as $token) {
        expect($token)->toMatch('/^[0-9a-f]{64}$/');
    }
});

it('hashes with sha256 and stores nothing reversible', function () {
    $plain = InvitationToken::generate();

    expect(InvitationToken::hash($plain))->toBe(hash('sha256', $plain))
        ->and(InvitationToken::hash($plain))->toHaveLength(64)
        ->and(InvitationToken::hash($plain))->not->toBe($plain);
});

it('finds an invitation of either kind by its plaintext token', function () {
    $memberToken = InvitationToken::generate();
    $reviewerToken = InvitationToken::generate();

    $member = OrganizationInvitation::factory()->create(['token_hash' => InvitationToken::hash($memberToken)]);
    $reviewer = ReviewerInvitation::factory()->for(Conference::factory()->published())
        ->create(['token_hash' => InvitationToken::hash($reviewerToken)]);

    $lookup = app(InvitationLookup::class);

    expect($lookup->find($memberToken))->toBeInstanceOf(OrganizationInvitation::class)
        ->and($lookup->find($memberToken)?->getKey())->toBe($member->getKey())
        ->and($lookup->find($reviewerToken))->toBeInstanceOf(ReviewerInvitation::class)
        ->and($lookup->find($reviewerToken)?->getKey())->toBe($reviewer->getKey());
});

it('answers null for an unknown, empty or malformed token without a second shape of answer', function () {
    $lookup = app(InvitationLookup::class);

    expect($lookup->find(''))->toBeNull()
        ->and($lookup->find('not-a-token'))->toBeNull()
        ->and($lookup->find(InvitationToken::generate()))->toBeNull();
});

it('still resolves an expired or revoked invitation, because the page explains rather than 404s', function () {
    $expiredToken = InvitationToken::generate();
    $revokedToken = InvitationToken::generate();

    OrganizationInvitation::factory()->expired()->create(['token_hash' => InvitationToken::hash($expiredToken)]);
    OrganizationInvitation::factory()->revoked()->create(['token_hash' => InvitationToken::hash($revokedToken)]);

    $lookup = app(InvitationLookup::class);

    expect($lookup->find($expiredToken))->not->toBeNull()
        ->and($lookup->find($expiredToken)?->invitationStatus())->toBe(App\Enums\InvitationStatus::Expired)
        ->and($lookup->find($revokedToken)?->invitationStatus())->toBe(App\Enums\InvitationStatus::Revoked);
});
```

`tests/Feature/Public/AcceptInvitationTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Livewire\Public\AcceptInvitation;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ReviewerInvitation;
use App\Models\User;
use App\Support\Tokens\InvitationToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->plain = InvitationToken::generate();
    $this->invitation = OrganizationInvitation::factory()->for($this->organization)->create([
        'email' => 'new@example.org',
        'role' => OrganizationRole::Admin,
        'token_hash' => InvitationToken::hash($this->plain),
    ]);
});

it('404s on a token that resolves to nothing', function () {
    get('/invite/'.InvitationToken::generate())->assertNotFound();
    // A path that could not be a token never reaches the database: the route
    // constraint is [A-Za-z0-9]{64}.
    get('/invite/short')->assertNotFound();
});

it('shows the invitation and an account form to a stranger', function () {
    get('/invite/'.$this->plain)
        ->assertOk()
        ->assertSee('Alpha Society')
        ->assertSee('new@example.org')
        ->assertSee(__('members.invite.create_account'));
});

it('creates a verified account, attaches the member and signs them in', function () {
    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->set('name', 'Dr Layla Ahmed')
        ->set('password', 'correct-horse-99')
        ->set('password_confirmation', 'correct-horse-99')
        ->call('createAccount')
        ->assertHasNoErrors()
        ->assertRedirect('/org/'.$this->organization->slug);

    $user = User::query()->where('email', 'new@example.org')->firstOrFail();

    expect($user->name)->toBe('Dr Layla Ahmed')
        // Clicking a 64-character secret that only ever reached this mailbox IS
        // the proof VerifyEmail asks for, so no second verification email.
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->roleIn($this->organization))->toBe(OrganizationRole::Admin)
        ->and(Auth::id())->toBe($user->id)
        ->and($this->invitation->fresh()?->accepted_at)->not->toBeNull()
        ->and($this->invitation->fresh()?->accepted_by)->toBe($user->id);

    expect(Activity::query()->where('description', 'organization.invitation_accepted')->count())->toBe(1);
});

it('refuses a weak password and creates nothing', function () {
    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->set('name', 'Dr Layla Ahmed')
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('createAccount')
        ->assertHasErrors(['password']);

    expect(User::query()->where('email', 'new@example.org')->exists())->toBeFalse()
        ->and($this->invitation->fresh()?->accepted_at)->toBeNull();
});

it('accepts for an existing account on its password without signing it in', function () {
    $existing = User::factory()->create(['email' => 'new@example.org', 'password' => 'correct-horse-99']);

    get('/invite/'.$this->plain)->assertOk()->assertSee(__('members.invite.sign_in'));

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->set('password', 'correct-horse-99')
        ->call('signIn')
        ->assertHasNoErrors()
        ->assertRedirect($this->invitation->fresh()?->landingUrl());

    expect($existing->fresh()?->roleIn($this->organization))->toBe(OrganizationRole::Admin)
        ->and($this->invitation->fresh()?->accepted_by)->toBe($existing->id)
        // No session: the password is VERIFIED here, never used to authenticate.
        // Both the admin and organizer panels enable multi-factor
        // authentication, and Filament challenges that factor only inside its
        // own Login page - so an Auth::attempt() here would be a factor-free
        // front door for anyone holding the link. The redirect lands on the
        // panel, whose Authenticate middleware sends them to the login that
        // does challenge it.
        ->and(Auth::check())->toBeFalse();
});

it('refuses a wrong password without saying whether the account exists', function () {
    User::factory()->create(['email' => 'new@example.org', 'password' => 'correct-horse-99']);

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->set('password', 'wrong-password-11')
        ->call('signIn')
        ->assertHasErrors(['password']);

    expect(Auth::check())->toBeFalse()
        ->and($this->invitation->fresh()?->accepted_at)->toBeNull();
});

it('accepts with one click when the right person is already signed in', function () {
    $existing = User::factory()->create(['email' => 'new@example.org']);
    actingAs($existing);

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->call('accept')
        ->assertHasNoErrors()
        ->assertRedirect('/org/'.$this->organization->slug);

    expect($existing->fresh()?->roleIn($this->organization))->toBe(OrganizationRole::Admin);
});

it('refuses to accept while signed in as somebody else', function () {
    $other = User::factory()->create(['email' => 'someone.else@example.org']);
    actingAs($other);

    get('/invite/'.$this->plain)->assertOk()->assertSee('someone.else@example.org');

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->call('accept')
        ->assertHasErrors(['token']);

    // The whole point: accepting as the wrong account would be a way to have
    // an address you do not control marked verified on an account you do.
    expect($other->fresh()?->roleIn($this->organization))->toBeNull()
        ->and($this->invitation->fresh()?->accepted_at)->toBeNull();
});

it('marks an existing unverified account verified on accept', function () {
    $existing = User::factory()->unverified()->create(['email' => 'new@example.org']);
    actingAs($existing);

    livewire(AcceptInvitation::class, ['token' => $this->plain])->call('accept')->assertHasNoErrors();

    expect($existing->fresh()?->hasVerifiedEmail())->toBeTrue();
});

it('explains an expired, revoked or already accepted invitation instead of 404ing', function () {
    $expired = InvitationToken::generate();
    OrganizationInvitation::factory()->for($this->organization)->expired()
        ->create(['token_hash' => InvitationToken::hash($expired)]);

    get('/invite/'.$expired)->assertOk()->assertSee(__('members.invite.expired'));

    $revoked = InvitationToken::generate();
    OrganizationInvitation::factory()->for($this->organization)->revoked()
        ->create(['token_hash' => InvitationToken::hash($revoked)]);

    get('/invite/'.$revoked)->assertOk()->assertSee(__('members.invite.revoked'));

    $this->invitation->forceFill(['accepted_at' => now()])->save();
    get('/invite/'.$this->plain)->assertOk()->assertSee(__('members.invite.already_accepted'));
});

it('refuses to accept an expired invitation even by calling the action directly', function () {
    $existing = User::factory()->create(['email' => 'new@example.org']);
    actingAs($existing);

    $this->invitation->forceFill(['expires_at' => now()->subMinute()])->save();

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->call('accept')
        ->assertHasErrors(['token']);

    expect($existing->fresh()?->roleIn($this->organization))->toBeNull();
});

it('uses the token once: a second accept changes nothing', function () {
    $existing = User::factory()->create(['email' => 'new@example.org']);
    actingAs($existing);

    livewire(AcceptInvitation::class, ['token' => $this->plain])->call('accept')->assertHasNoErrors();

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->call('accept')
        ->assertHasErrors(['token']);

    expect(App\Models\OrganizationInvitation::query()->whereNotNull('accepted_at')->count())->toBe(1);
});

it('lands a reviewer in the reviewer panel and creates the reviewership', function () {
    $conference = Conference::factory()->for($this->organization)->published()->create();
    $token = InvitationToken::generate();
    ReviewerInvitation::factory()->for($conference)->create([
        'name' => 'Dr Omar Khan',
        'email' => 'omar@example.org',
        'affiliation' => 'KFSH',
        'token_hash' => InvitationToken::hash($token),
    ]);

    get('/invite/'.$token)->assertOk()->assertSee($conference->name)->assertSee('Dr Omar Khan');

    livewire(AcceptInvitation::class, ['token' => $token])
        ->set('name', 'Dr Omar Khan')
        ->set('password', 'correct-horse-99')
        ->set('password_confirmation', 'correct-horse-99')
        ->call('createAccount')
        ->assertHasNoErrors()
        ->assertRedirect('/review');

    $user = User::query()->where('email', 'omar@example.org')->firstOrFail();
    $reviewer = ConferenceReviewer::query()->firstOrFail();

    expect($reviewer->user_id)->toBe($user->id)
        ->and($reviewer->conference_id)->toBe($conference->id)
        ->and($reviewer->affiliation)->toBe('KFSH')
        ->and($user->isActiveReviewer($conference))->toBeTrue()
        ->and($user->roleIn($this->organization))->toBeNull();
});

it('refuses to create a second account at an address that already has one', function () {
    // The view's `@elseif ($accountExists)` branch is a convenience, not a gate:
    // createAccount() is a public Livewire method, and an account created in
    // another tab since this page rendered is an ordinary race. users.email is
    // unique, so without the check in forNewAccount() this is a 500.
    User::factory()->create(['email' => 'new@example.org']);

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->set('name', 'Dr Layla Ahmed')
        ->set('password', 'correct-horse-99')
        ->set('password_confirmation', 'correct-horse-99')
        ->call('createAccount')
        ->assertHasErrors(['token']);

    expect(User::query()->where('email', 'new@example.org')->count())->toBe(1)
        ->and($this->invitation->fresh()?->accepted_at)->toBeNull();
});

it('refuses an owner invitation minted by somebody who has since been demoted', function () {
    // An invitation is the inviter's authority, exercised later. A demoted owner
    // must not still be able to install an owner through a link they made while
    // they could. Task 3 withdraws those rows on the demotion itself; this is
    // the belt to that braces, and it also covers a role changed by any other
    // path.
    $founder = User::factory()->create();
    $second = User::factory()->create();
    $this->organization->addMember($founder, OrganizationRole::Owner);
    $this->organization->addMember($second, OrganizationRole::Owner);

    $token = InvitationToken::generate();
    OrganizationInvitation::factory()->for($this->organization)->create([
        'email' => 'accomplice@example.org',
        'role' => OrganizationRole::Owner,
        'invited_by' => $founder->id,
        'token_hash' => InvitationToken::hash($token),
    ]);

    // The demotion itself, at the model level: addMember() is
    // syncWithoutDetaching and sync() updates the pivot of an id it already
    // holds. Task 3's ChangeMemberRole is the screen-level version of this and
    // additionally withdraws the rows; the rule under test here is that even if
    // the row survives, accepting it does not.
    $this->organization->addMember($founder, OrganizationRole::Admin);

    $invitee = User::factory()->create(['email' => 'accomplice@example.org']);
    actingAs($invitee);

    livewire(AcceptInvitation::class, ['token' => $token])
        ->call('accept')
        ->assertHasErrors(['token'])
        ->assertSee(__('members.invite.blocked.inviter_gone'));

    expect($invitee->fresh()?->roleIn($this->organization))->toBeNull();
});

it('throttles the accept page at ten a minute per address', function () {
    // Spec section 9. The GET is what an enumeration attack loops, so the route
    // middleware is where it is counted. No RateLimiter::clear() here: the array
    // cache store is fresh per test, and the route limiter's key is
    // md5('invitation-accept'.$ip)
    // (ThrottleRequests::handleRequestUsingNamedLimiter,
    // vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php:134),
    // not the component's literal 'invitation-accept|'.$ip - so clearing that
    // string here would clear the wrong bucket and prove nothing.
    foreach (range(1, (int) config('cass.invitations.accept_rate_limit')) as $ignored) {
        get('/invite/'.$this->plain)->assertOk();
    }

    get('/invite/'.$this->plain)->assertStatus(429);
});

it('throttles the livewire actions on their own budget, which no route middleware sees', function () {
    // The other half of the same rule, and the half that matters: a Livewire
    // action is one POST to /livewire/update, which no middleware on
    // /invite/{token} ever sees, so without withinRateLimit() the accept button
    // is an unlimited oracle however tight the route throttle is.
    //
    // Nobody is signed in, so every call is refused for the same reason and the
    // invitation is never consumed - which means the ONLY thing that can change
    // the message on the eleventh call is the component's own limiter.
    foreach (range(1, (int) config('cass.invitations.accept_rate_limit')) as $ignored) {
        livewire(AcceptInvitation::class, ['token' => $this->plain])
            ->call('accept')
            ->assertHasErrors(['token'])
            ->assertSee(__('members.invite.blocked.not_signed_in'));
    }

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->call('accept')
        ->assertHasErrors(['token'])
        ->assertSee(__('members.invite.too_many_attempts'));

    expect($this->invitation->fresh()?->accepted_at)->toBeNull();
});

it('throttles the sign-in attempt separately, per email and address', function () {
    User::factory()->create(['email' => 'new@example.org', 'password' => 'correct-horse-99']);

    foreach (range(1, (int) config('cass.invitations.login_rate_limit')) as $ignored) {
        livewire(AcceptInvitation::class, ['token' => $this->plain])
            ->set('password', 'wrong-password-11')
            ->call('signIn')
            ->assertHasErrors(['password']);
    }

    // Spec section 9's "login 5/min/email+IP". The message changes, so the
    // sixth attempt is refused rather than checked.
    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->set('password', 'correct-horse-99')
        ->call('signIn')
        ->assertHasErrors(['password']);

    expect(Auth::check())->toBeFalse();
});
```

- [ ] **Step 2: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/InvitationTokenTest.php tests/Feature/Public/AcceptInvitationTest.php > /tmp/t.log 2>&1; echo "rc=$?"; head -30 /tmp/t.log
```

Expected: `rc=1`, with `Class "App\Support\Tokens\InvitationToken" not found`.

- [ ] **Step 3: The token and the lookup**

`app/Support/Tokens/InvitationToken.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Tokens;

use Random\RandomException;

/**
 * 32 random bytes rendered as 64 hex characters, stored only as a SHA-256 hash
 * (spec section 9). Hex rather than base64url for the same reason
 * SubmissionToken uses it: the value travels in a path segment constrained to
 * [A-Za-z0-9]{64}, with no padding, no `-` and no `_` for a mail client to
 * mangle when it decides where a link ends.
 *
 * A separate class from SubmissionToken rather than a shared base: the two have
 * the same shape today and entirely different lifetimes - an author token lives
 * as long as the submission and is reissued on demand, an invitation token
 * lives 14 days and dies on acceptance - and a shared parent would be a place
 * for a change to one to silently become a change to both.
 */
final class InvitationToken
{
    public const LENGTH = 64;

    /** @throws RandomException */
    public static function generate(): string
    {
        return bin2hex(random_bytes(self::LENGTH / 2));
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
```

`app/Support/Invitations/InvitationLookup.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Invitations;

use App\Contracts\Invitation;
use App\Models\OrganizationInvitation;
use App\Models\ReviewerInvitation;
use App\Support\Tokens\InvitationToken;

/**
 * One plaintext token, two tables, one answer.
 *
 * Order is fixed - organization invitations first - so a collision across the
 * two unique indexes has defined behaviour rather than lucky behaviour. With
 * 256 bits of entropy per token that is a 2^-256 event; stating the order costs
 * one sentence and removes the question.
 *
 * This returns rows in every state, not only pending ones: `/invite/{token}`
 * explains an expired or withdrawn invitation rather than 404ing at someone who
 * has already proved they hold the emailed secret. `AcceptInvitation::blockers()`
 * is what refuses.
 */
class InvitationLookup
{
    public function find(string $plainToken): ?Invitation
    {
        if ($plainToken === '') {
            return null;
        }

        $hash = InvitationToken::hash($plainToken);

        return OrganizationInvitation::query()->where('token_hash', $hash)->first()
            ?? ReviewerInvitation::query()->where('token_hash', $hash)->first();
    }
}
```

- [ ] **Step 4: The exception and the action**

`app/Exceptions/InvitationNotAcceptable.php`
```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Mirrors ConferenceNotPublishable and SubmissionNotAcceptable: a list of
 * sentences a person can act on, not a validation code.
 */
final class InvitationNotAcceptable extends RuntimeException
{
    /** @param  list<string>  $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }

    public static function because(string $reason): self
    {
        return new self([$reason]);
    }
}
```

`app/Actions/Invitations/AcceptInvitation.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Contracts\Invitation;
use App\Enums\OrganizationRole;
use App\Exceptions\InvitationNotAcceptable;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one path by which an invitation of either kind becomes a membership or a
 * reviewership.
 *
 * `blockers()` is a pure read the page calls to decide what to render;
 * `handle()` re-checks everything and throws, because the page is a convenience
 * and a hand-made Livewire call is not.
 */
class AcceptInvitation
{
    /**
     * @param  User|null  $user  the account that would accept, when there is one yet
     * @return list<string> empty when the invitation may be accepted by that user
     */
    public function blockers(Invitation $invitation, ?User $user): array
    {
        $reasons = [];
        $status = $invitation->invitationStatus();

        if (! $status->isOpen()) {
            $reasons[] = __('members.invite.blocked.'.$status->value);
        }

        if ($user !== null && mb_strtolower(trim((string) $user->email)) !== $invitation->invitedEmail()) {
            // Refusing here is what stops "accept while signed in as someone
            // else" being a way to have an address you do not control marked
            // verified on an account you do.
            $reasons[] = __('members.invite.blocked.wrong_account', ['email' => $invitation->invitedEmail()]);
        }

        // An invitation is the inviter's authority, exercised later. Task 3's
        // RemoveMember and ChangeMemberRole withdraw the invitations a departing
        // or demoted member minted; this is the belt to those braces, because a
        // link created before somebody lost the right to create it must not
        // still install an Owner days afterwards - and because a role can also
        // change by a path neither of those actions owns.
        //
        // `invited_by` is nullable and is only null on rows nothing minted
        // through InviteMember (the factory default, and any future import), so
        // a row with no recorded inviter has no authority to re-check and is not
        // blocked on that ground. The organization hop is null-guarded because
        // Organization soft-deletes while its invitations survive, and
        // User::roleIn() takes a non-nullable Organization.
        if ($invitation instanceof OrganizationInvitation && $invitation->invited_by !== null) {
            $organization = $invitation->organization;
            $inviterRole = $organization === null ? null : $invitation->inviter?->roleIn($organization);

            if ($inviterRole === null
                || ! $inviterRole->canManageOrganization()
                || ($invitation->role === OrganizationRole::Owner && $inviterRole !== OrganizationRole::Owner)) {
                $reasons[] = __('members.invite.blocked.inviter_gone');
            }
        }

        return $reasons;
    }

    public function handle(Invitation $invitation, User $user): Invitation
    {
        $reasons = $this->blockers($invitation, $user);

        if ($reasons !== []) {
            throw new InvitationNotAcceptable($reasons);
        }

        return DB::transaction(function () use ($invitation, $user): Invitation {
            // Fact 6: a forceFill plus a save, no notification. Following a
            // 64-character secret that only ever reached this mailbox is the
            // proof VerifyEmail asks for - see the Task 2 preamble.
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            $invitation->grantTo($user);
            $invitation->markAccepted($user);

            return $invitation;
        });
    }

    /**
     * The short account form of spec 5.4 step 2. The password has already been
     * validated by the caller against Password::defaults(); this class owns the
     * two things a form cannot: the address comes from the *invitation*, never
     * from the request, and the account is created verified inside the same
     * transaction that grants the membership.
     */
    public function forNewAccount(Invitation $invitation, string $name, string $password): User
    {
        $reasons = $this->blockers($invitation, null);

        if ($reasons !== []) {
            throw new InvitationNotAcceptable($reasons);
        }

        // `users.email` is unique
        // (database/migrations/0001_01_01_000000_create_users_table.php:19) and
        // the page's `@elseif ($accountExists)` branch is a convenience, not a
        // gate: createAccount() is a public Livewire method reachable whatever
        // the view rendered, and an account created in another tab since this
        // page loaded is an ordinary race. Refuse here, where every caller
        // reaches it, so this is a sentence rather than an unhandled
        // UniqueConstraintViolationException 500 with the invitation unaccepted.
        if (User::query()->where('email', $invitation->invitedEmail())->exists()) {
            throw InvitationNotAcceptable::because(__('members.invite.blocked.account_exists'));
        }

        return DB::transaction(function () use ($invitation, $name, $password): User {
            /** @var User $user */
            $user = User::query()->create([
                'name' => $name,
                'email' => $invitation->invitedEmail(),
                'password' => $password,
            ]);

            $this->handle($invitation, $user);

            return $user;
        });
    }
}
```

- [ ] **Step 5: The Livewire component**

`app/Livewire/Public/AcceptInvitation.php`
```php
<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Actions\Invitations\AcceptInvitation as AcceptInvitationAction;
use App\Contracts\Invitation;
use App\Exceptions\InvitationNotAcceptable;
use App\Models\ReviewerInvitation;
use App\Models\User;
use App\Support\ClientIp;
use App\Support\Invitations\InvitationLookup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Spec 5.4 step 2 and the `/invite/{token}` row of section 6, for member and
 * reviewer invitations alike.
 *
 * The component holds **only the token**, and re-resolves the invitation on
 * every action. That is one indexed read per click, and it is what makes
 * "revoked while the page was open" and "accepted in another tab" refuse
 * instead of succeeding against a stale snapshot. The token is #[Locked] and
 * lives in the component's signed snapshot, which is fine here for the same
 * reason it is fine on the status page: the identical value is in the address
 * bar of the page that snapshot belongs to.
 */
#[Layout('components.layouts.public')]
#[Title('Your invitation')]
class AcceptInvitation extends Component
{
    #[Locked]
    public string $token;

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        // A token that resolves to nothing is a flat 404, exactly like
        // /s/{token}. A token that resolves to an expired or withdrawn
        // invitation is a page with a sentence on it - see the Task 2 preamble.
        abort_if($this->resolve($token) === null, 404);

        $this->token = $token;
        $this->name = $this->invitation()?->invitedName() ?? '';
    }

    /** One-click accept for a signed-in account whose address matches. */
    public function accept(AcceptInvitationAction $action): mixed
    {
        if (! $this->withinRateLimit()) {
            return null;
        }

        $user = Auth::user();

        if (! $user instanceof User) {
            $this->addError('token', __('members.invite.blocked.not_signed_in'));

            return null;
        }

        return $this->complete($action, fn (Invitation $invitation): mixed => $action->handle($invitation, $user));
    }

    /** An account already exists at this address: check the password, then accept. */
    public function signIn(AcceptInvitationAction $action): mixed
    {
        if (! $this->withinRateLimit()) {
            return null;
        }

        $invitation = $this->invitation();

        if ($invitation === null) {
            $this->addError('token', __('members.invite.blocked.revoked'));

            return null;
        }

        $this->validate(['password' => ['required', 'string']]);

        // Spec section 9: login is 5/min/email+IP. Keyed on both, so one
        // address cannot spend another account's budget and one attacker cannot
        // get a fresh budget per guessed address. Kept even though nothing here
        // signs anybody in: the password check below is still an oracle.
        $key = 'invite-login:'.$invitation->invitedEmail().'|'.ClientIp::from(request());

        if (RateLimiter::tooManyAttempts($key, (int) config('cass.invitations.login_rate_limit'))) {
            $this->addError('password', __('members.invite.too_many_attempts'));

            return null;
        }

        RateLimiter::hit($key, 60);

        // Verify, do NOT authenticate. Auth::attempt() here would hand out a
        // panel session without the multi-factor challenge that
        // Filament\Auth\Pages\Login::authenticate() is the only place to run:
        // AdminPanelProvider and OrganizerPanelProvider both call
        // ->multiFactorAuthentication([AppAuthentication::make()->recoverable()])
        // and no Filament HTTP middleware re-checks the factor per request, so
        // an invitation link plus a password would be a second, factor-free
        // front door into those panels for anyone with mailbox access - who can
        // also reset the password, and who MFA exists to stop. The token proves
        // the mailbox, which is what the *invitation* needs; issuing the
        // *session* is the panel login's job.
        $user = User::query()->where('email', $invitation->invitedEmail())->first();

        if (! $user instanceof User || ! Hash::check($this->password, (string) $user->password)) {
            $this->addError('password', __('members.invite.wrong_password'));

            return null;
        }

        RateLimiter::clear($key);

        // Accept first, then land them on the panel - not the other way round.
        // User::canAccessPanel('organizer') answers organizations()->exists()
        // (app/Models/User.php:66), which is false until the membership exists,
        // so redirecting to the panel login BEFORE accepting would meet "these
        // credentials do not match our records" and the invitation could never
        // be accepted at all. Accepting makes it true; complete()'s redirect to
        // landingUrl() then meets Filament\Http\Middleware\Authenticate, which
        // stores the intended URL and sends them to the login that DOES
        // challenge the second factor.
        return $this->complete($action, fn (Invitation $current): mixed => $action->handle($current, $user));
    }

    /** No account at this address: the four-field form of spec 5.4 step 2. */
    public function createAccount(AcceptInvitationAction $action): mixed
    {
        if (! $this->withinRateLimit()) {
            return null;
        }

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        return $this->complete($action, function (Invitation $invitation) use ($action): mixed {
            $user = $action->forNewAccount($invitation, trim($this->name), $this->password);

            Auth::login($user);
            request()->session()->regenerate();

            return $user;
        });
    }

    public function signOut(): mixed
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return $this->redirect(route('invitation.accept', ['token' => $this->token]), navigate: false);
    }

    public function render(): mixed
    {
        $invitation = $this->invitation();

        // Revoked between the page load and this render: nothing to show and
        // nothing to accept, so treat it the way mount() treats an unknown one.
        abort_if($invitation === null, 404);

        $signedIn = Auth::user();

        return view('livewire.public.accept-invitation', [
            'invitation' => $invitation,
            'status' => $invitation->invitationStatus(),
            'isReviewer' => $invitation instanceof ReviewerInvitation,
            'signedInUser' => $signedIn instanceof User ? $signedIn : null,
            'matchesSignedIn' => $signedIn instanceof User
                && mb_strtolower(trim((string) $signedIn->email)) === $invitation->invitedEmail(),
            'accountExists' => User::query()->where('email', $invitation->invitedEmail())->exists(),
        ]);
    }

    /**
     * Runs the accept, turns an InvitationNotAcceptable into a field error, and
     * redirects to wherever that invitation lands people.
     *
     * @param  callable(Invitation): mixed  $work
     */
    private function complete(AcceptInvitationAction $action, callable $work): mixed
    {
        $invitation = $this->invitation();

        if ($invitation === null) {
            $this->addError('token', __('members.invite.blocked.revoked'));

            return null;
        }

        try {
            $work($invitation);
        } catch (InvitationNotAcceptable $exception) {
            $this->addError('token', $exception->getMessage());

            return null;
        }

        return $this->redirect($invitation->landingUrl(), navigate: false);
    }

    /**
     * Spec section 9's "invitation accept 10/min/IP", spent inside the
     * component as well as on the route: a Livewire action is one POST to
     * /livewire/update, which no middleware on /invite/{token} ever sees.
     */
    private function withinRateLimit(): bool
    {
        $key = 'invitation-accept|'.ClientIp::from(request());

        if (RateLimiter::tooManyAttempts($key, (int) config('cass.invitations.accept_rate_limit'))) {
            $this->addError('token', __('members.invite.too_many_attempts'));

            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }

    private function invitation(): ?Invitation
    {
        return $this->resolve($this->token);
    }

    private function resolve(string $token): ?Invitation
    {
        return app(InvitationLookup::class)->find($token);
    }
}
```

- [ ] **Step 6: The view**

`resources/views/livewire/public/accept-invitation.blade.php`
```blade
@php
    use App\Enums\InvitationStatus;
@endphp

<div class="mx-auto max-w-xl px-4 py-12">
    <h1 class="text-3xl font-semibold tracking-tight">{{ __('members.invite.title') }}</h1>
    <p class="mt-3 text-slate-700">{{ $invitation->invitationHeadline() }}</p>
    <p class="mt-1 text-sm text-slate-500">
        {{ __('members.invite.addressed_to', ['email' => $invitation->invitedEmail()]) }}
    </p>

    @error('token')
        <p class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $message }}</p>
    @enderror

    @if ($status === InvitationStatus::Expired)
        <p class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ __('members.invite.expired') }}</p>
    @elseif ($status === InvitationStatus::Revoked)
        <p class="mt-6 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">{{ __('members.invite.revoked') }}</p>
    @elseif ($status === InvitationStatus::Accepted)
        <p class="mt-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-900">{{ __('members.invite.already_accepted') }}</p>
    @elseif ($signedInUser && ! $matchesSignedIn)
        <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p>{{ __('members.invite.wrong_account', ['email' => $signedInUser->email]) }}</p>
            <button type="button" wire:click="signOut" class="mt-3 rounded-lg bg-brand-500 px-3 py-1.5 font-medium text-white hover:bg-brand-600">
                {{ __('members.invite.sign_out') }}
            </button>
        </div>
    @elseif ($signedInUser)
        <form wire:submit="accept" class="mt-8">
            <p class="text-sm text-slate-600">{{ __('members.invite.signed_in_as', ['email' => $signedInUser->email]) }}</p>
            <button type="submit" class="mt-4 rounded-lg bg-brand-500 px-4 py-2 font-medium text-white hover:bg-brand-600">
                {{ $isReviewer ? __('reviewer.invite.accept') : __('members.invite.accept') }}
            </button>
        </form>
    @elseif ($accountExists)
        <form wire:submit="signIn" class="mt-8 space-y-4" novalidate>
            <h2 class="text-lg font-medium">{{ __('members.invite.sign_in') }}</h2>
            <p class="text-sm text-slate-600">{{ __('members.invite.sign_in_help') }}</p>
            <div>
                <label for="password" class="block text-sm font-medium">{{ __('members.invite.password') }}</label>
                <input id="password" type="password" wire:model="password" autocomplete="current-password"
                       class="mt-1 w-full rounded-lg border-slate-300" required>
                @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2 font-medium text-white hover:bg-brand-600">
                {{ __('members.invite.sign_in_and_accept') }}
            </button>
        </form>
    @else
        <form wire:submit="createAccount" class="mt-8 space-y-4" novalidate>
            <h2 class="text-lg font-medium">{{ __('members.invite.create_account') }}</h2>
            <p class="text-sm text-slate-600">{{ __('members.invite.create_account_help') }}</p>
            <div>
                <label for="name" class="block text-sm font-medium">{{ __('members.invite.name') }}</label>
                <input id="name" type="text" wire:model="name" autocomplete="name"
                       class="mt-1 w-full rounded-lg border-slate-300" required>
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="password" class="block text-sm font-medium">{{ __('members.invite.password') }}</label>
                    <input id="password" type="password" wire:model="password" autocomplete="new-password"
                           class="mt-1 w-full rounded-lg border-slate-300" required>
                    <p class="mt-1 text-xs text-slate-500">{{ __('members.invite.password_help') }}</p>
                    @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium">{{ __('members.invite.password_confirmation') }}</label>
                    <input id="password_confirmation" type="password" wire:model="password_confirmation"
                           autocomplete="new-password" class="mt-1 w-full rounded-lg border-slate-300" required>
                </div>
            </div>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2 font-medium text-white hover:bg-brand-600">
                {{ __('members.invite.create_account_and_accept') }}
            </button>
        </form>
    @endif

    @if ($invitation->invitationExpiresAt() && $status === InvitationStatus::Pending)
        <p class="mt-6 text-xs text-slate-500">
            {{ __('members.invite.expires_on', ['date' => $invitation->invitationExpiresAt()->format('j F Y')]) }}
        </p>
    @endif
</div>
```

- [ ] **Step 7: The language keys**

Replace `lang/en/members.php` with (the Task 1 stub grows; the Members-page keys arrive in Task 3):

```php
<?php

declare(strict_types=1);

/*
 * The organizer's Members page and the whole public /invite/{token} page. Spec
 * section 10: locale `en` only in v1, with every string here so Arabic is a
 * copy of this file and not a branch in a Blade view.
 *
 * The `invite.*` group is shared by member *and* reviewer invitations, because
 * one page serves both; `lang/en/reviewer.php` carries only the two lines where
 * the wording really differs.
 */

return [

    'invite' => [
        'title' => 'Your invitation',
        'headline' => ':organization has invited you to join their team on CASS as :role.',
        'addressed_to' => 'This invitation was sent to :email.',
        'expires_on' => 'This link works until :date.',

        'accept' => 'Accept and join',
        'sign_in' => 'Confirm your password to accept',
        'sign_in_help' => 'You already have a CASS account at this address. Confirm your password to accept; you will be asked to sign in afterwards.',
        'sign_in_and_accept' => 'Accept invitation',
        'create_account' => 'Create your account',
        'create_account_help' => 'You do not have a CASS account yet. Choose a password and you are in - there is no separate email to confirm, because you just followed the link we sent you.',
        'create_account_and_accept' => 'Create account and accept',
        'name' => 'Full name',
        'password' => 'Password',
        'password_help' => 'At least 10 characters with letters and numbers.',
        'password_confirmation' => 'Confirm password',
        'signed_in_as' => 'You are signed in as :email.',
        'sign_out' => 'Sign out and try again',
        'wrong_account' => 'You are signed in as :email, which is not the address this invitation was sent to. Sign out and follow the link again.',
        'wrong_password' => 'That password is not right.',
        'too_many_attempts' => 'Too many attempts. Please wait a minute and try again.',

        'expired' => 'This invitation has expired. Ask whoever invited you to send a new one.',
        'revoked' => 'This invitation has been withdrawn. Ask whoever invited you if you think that is a mistake.',
        'already_accepted' => 'This invitation has already been used. Sign in to your account to continue.',

        'blocked' => [
            'expired' => 'This invitation has expired.',
            'revoked' => 'This invitation has been withdrawn.',
            'accepted' => 'This invitation has already been used.',
            'pending' => 'This invitation cannot be accepted right now.',
            'wrong_account' => 'This invitation was sent to :email. Sign out and follow the link again from that account.',
            'not_signed_in' => 'Sign in first, then accept the invitation.',
            'account_exists' => 'There is already a CASS account at this address. Sign in instead.',
            'inviter_gone' => 'Whoever invited you no longer manages this organization. Ask them to invite you again.',
        ],
    ],

];
```

and `lang/en/reviewer.php`:

```php
<?php

declare(strict_types=1);

/*
 * The reviewer panel's own views, and the two lines of /invite/{token} where a
 * reviewer invitation reads differently from a team invitation.
 */

return [

    'invite' => [
        'headline' => ':organization has invited you to review abstracts for :conference.',
        'accept' => 'Accept and start reviewing',
    ],

];
```

- [ ] **Step 8: The route and the limiter**

In `routes/web.php`, after the `/s/{token}` route:

```php
// Spec sections 6 and 9. Shared by member and reviewer invitations: one page,
// one token shape, two tables (App\Support\Invitations\InvitationLookup).
//
// No AuthenticateSession and no `auth`: a brand-new reviewer has no account at
// all, and a signed-in organizer following the link must not be bounced to a
// login. The component decides which of the four states to render.
//
// The throttle is spec section 9's 10/min/IP and sits on the route because a
// page *view* is what an enumeration attack loops; the component spends the
// same budget again for its own POSTs, which reach /livewire/update instead.
//
// The constraint is the hex alphabet InvitationToken mints, so a path that
// could not be a token never reaches the database.
Route::get('/invite/{token}', AcceptInvitation::class)
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:invitation-accept')
    ->name('invitation.accept');
```

with `use App\Livewire\Public\AcceptInvitation;` added to the imports.

In `app/Providers/AppServiceProvider.php::boot()`, beside the other limiters:

```php
        // Spec section 9: invitation accept, 10/min/IP. Keyed on the address
        // only - not the token - so guessing tokens counts against one budget
        // instead of getting a fresh one per guess, exactly as on /s/{token}.
        RateLimiter::for('invitation-accept', fn (Request $request): Limit => Limit::perMinute(
            (int) config('cass.invitations.accept_rate_limit')
        )->by(ClientIp::from($request)));
```

- [ ] **Step 9: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/InvitationTokenTest.php tests/Feature/Public/AcceptInvitationTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -5 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`; about 23 passed in the first run and `baseline + 42` in the second. Three cases are new to this revision of the plan and one is rewritten, and all four are worth watching individually: `it('refuses to create a second account at an address that already has one')` pins `forNewAccount()`'s existence check; `it('refuses an owner invitation minted by somebody who has since been demoted')` pins `blockers()`'s inviter re-check; `it('throttles the livewire actions on their own budget, which no route middleware sees')` is the first test of `withinRateLimit()` itself, which no route middleware can cover; and the rewritten sign-in case now asserts `Auth::check()` is **false** after a successful accept, because the password is verified and never used to authenticate — see the multi-factor paragraph above.

- [ ] **Step 10: Prove the route by hand as well as by test**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan route:list --except-vendor | grep -E "invite"
```

Expected: one line, `GET|HEAD invite/{token} … invitation.accept › App\Livewire\Public\AcceptInvitation`.

- [ ] **Step 11: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(invitations): hashed tokens and the shared /invite/{token} accept flow

One page and one action serve member and reviewer invitations. Accepting marks
the address verified rather than sending a second verification email: following
a 64-character secret that only reached that mailbox is the proof VerifyEmail
asks for, which is also why accepting while signed in as another account is
refused outright.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 3: Organization member management

The "Manage organization members" row of spec section 4, handed to Plan 2 by Plan 1 and to Plan 4 by Plan 2 (`docs/superpowers/plans/backlog.md`). Until this task ships, every organization in production has exactly its registering owner, so the owner/admin/member distinctions Plans 1-3 already enforce cannot occur.

**Why this is a page with one array-backed table, not two resources.** Members are rows of the `organization_members` pivot; pending invitations are rows of `organization_invitations`. A Filament component hosts exactly one table (`InteractsWithTable`), so two resources would mean two screens for one job, two navigation entries, and an organizer clicking between "who is here" and "who is coming". `Table::records()` over an array (fact 15) puts both in one list with a `state` badge, which is what the person doing the job actually wants to see, and it is the pattern `ConferenceEmailTemplates` already established in this codebase.

**Why the `notify_on_submission` control is an Action and not a `ToggleColumn`.** Fact 36: over array records an editable column silently does nothing unless `updateStateUsing()` is supplied, and both editable columns carry an in-source warning that they bypass model policies. An `Action` goes through `Gate::authorize()` like every other write here.

**Who may do what, and where each rule lives.**

- **`App\Policies\OrganizationMemberPolicy`** answers *who may act*: `update` (change a role) and `delete` (remove) require an owner or admin of that organization. It is a new policy on an existing Pivot model that no code authorized against before, so it has no blast radius — deliberately, rather than adding an `OrganizationPolicy`, which `App\Filament\Admin\Resources\Organizations\OrganizationResource` explicitly does without (its `canAccess()` docblock says so) and which would start being consulted by the admin panel's row actions.
- **`ChangeMemberRole::blockers()` and `RemoveMember::blockers()`** answer *what the act may be*, because these rules need the target role and a count, which a policy is never handed:
  1. an organization always keeps at least one owner;
  2. nobody changes or removes their own membership (an owner who wants out asks another owner — which is also why a sole owner can never be removed, by anyone);
  3. only an owner may grant the owner role, and only an owner may change or remove an existing owner.
- **Invitations** are governed by `OrganizationInvitationPolicy` from Task 1 (owner and admin only).
- **The page itself** is visible to every member of the tenant, because a plain member needs it to turn their own "email me about new submissions" off.

**`member_invitation` is not a spec 5.9 template key**, so it is a plain queued `Notification` sent on demand to an address with no account (fact 20), not a `SendTemplatedEmail` call. It still lands in `email_logs`, because `RecordOutgoingEmail` catches every message and reads the `X-CASS-Organization` header this notification adds — the same trick `NewSubmissionNotice` uses.

**The contract, stated once:**

| Class | Signature |
|---|---|
| `InviteMember` | `blockers(Organization $organization, string $email, OrganizationRole $role, User $actor): list<string>` |
| `InviteMember` | `handle(Organization $organization, string $email, OrganizationRole $role, User $actor): OrganizationInvitation` |
| `RevokeInvitation` | `handle(OrganizationInvitation $invitation, User $actor): OrganizationInvitation` |
| `ChangeMemberRole` | `blockers(Organization $o, User $member, OrganizationRole $role, User $actor): list<string>` and `handle(…): void` |
| `RemoveMember` | `blockers(Organization $o, User $member, User $actor): list<string>` and `handle(…): void` |
| `SetSubmissionNotifications` | `handle(Organization $o, User $member, bool $enabled, User $actor): void` |

**Files:**
- Create: `app/Actions/Organizations/{InviteMember,RevokeInvitation,ChangeMemberRole,RemoveMember,SetSubmissionNotifications}.php`
- Create: `app/Exceptions/MemberChangeRefused.php`, `app/Notifications/MemberInvitation.php`, `app/Policies/OrganizationMemberPolicy.php`
- Create: `app/Filament/Organizer/Pages/Members.php`, `resources/views/filament/organizer/pages/members.blade.php`
- Modify: `app/Models/OrganizationMember.php` (two relations), `lang/en/members.php`
- Test: `tests/Feature/Organizer/MembersPageTest.php`, `tests/Feature/Mail/EmailLogPipelineTest.php` (modified: the one on-demand notification in the app)

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Organizer/MembersPageTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Organizations\ChangeMemberRole;
use App\Actions\Organizations\RemoveMember;
use App\Enums\OrganizationRole;
use App\Exceptions\MemberChangeRefused;
use App\Filament\Organizer\Pages\Members;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\MemberInvitation;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Notification::fake();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->owner = User::factory()->create(['name' => 'Dr Owner', 'email' => 'owner@example.org']);
    $this->admin = User::factory()->create(['name' => 'Dr Admin', 'email' => 'admin@example.org']);
    $this->member = User::factory()->create(['name' => 'Dr Member', 'email' => 'member@example.org']);
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
    $this->organization->addMember($this->admin, OrganizationRole::Admin);
    $this->organization->addMember($this->member, OrganizationRole::Member);

    actingAs($this->owner);
    bootOrganizerPanel($this->organization);
});

it('lists every member with their role and the pending invitations', function () {
    OrganizationInvitation::factory()->for($this->organization)->create(['email' => 'invited@example.org']);

    livewire(Members::class)
        ->assertOk()
        ->assertSee('Dr Owner')
        ->assertSee('Dr Admin')
        ->assertSee('Dr Member')
        ->assertSee('invited@example.org')
        ->assertSee(OrganizationRole::Owner->getLabel())
        ->assertSee(__('members.state.invited'));
});

it('does not list another organization members or invitations', function () {
    [$theirUser, $theirInvitation] = withoutTenant(function (): array {
        $other = Organization::factory()->approved()->create();
        $user = User::factory()->create(['name' => 'Somebody Else', 'email' => 'else@example.org']);
        $other->addMember($user, OrganizationRole::Owner);

        return [$user, OrganizationInvitation::factory()->for($other)->create(['email' => 'their-invite@example.org'])];
    });

    livewire(Members::class)
        ->assertDontSee('Somebody Else')
        ->assertDontSee('their-invite@example.org');

    expect($theirUser->roleIn($this->organization))->toBeNull()
        ->and($theirInvitation->organization_id)->not->toBe($this->organization->id);
});

it('invites by email and role, queues the notification and logs it', function () {
    livewire(Members::class)
        ->callAction('invite', data: ['email' => 'New@Example.ORG', 'role' => OrganizationRole::Admin->value])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $invitation = OrganizationInvitation::query()->where('email', 'new@example.org')->firstOrFail();

    expect($invitation->role)->toBe(OrganizationRole::Admin)
        ->and($invitation->invited_by)->toBe($this->owner->id)
        ->and($invitation->token_hash)->toHaveLength(64)
        ->and($invitation->expires_at->toDateString())->toBe(now()->addDays(14)->toDateString());

    Notification::assertSentOnDemand(
        MemberInvitation::class,
        fn (MemberInvitation $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'new@example.org'
            && $notification->organization->is($this->organization),
    );

    expect(Activity::query()->where('description', 'organization.member_invited')->count())->toBe(1);
});

it('re-invites by refreshing the live row instead of creating a second link', function () {
    livewire(Members::class)->callAction('invite', data: ['email' => 'new@example.org', 'role' => OrganizationRole::Member->value]);
    $first = OrganizationInvitation::query()->where('email', 'new@example.org')->firstOrFail();

    livewire(Members::class)->callAction('invite', data: ['email' => 'new@example.org', 'role' => OrganizationRole::Admin->value]);

    // One row, one live link. Two rows would mean two working links in one
    // inbox and no way to say which "Revoke" revoked.
    expect(OrganizationInvitation::query()->where('email', 'new@example.org')->count())->toBe(1)
        ->and($first->fresh()?->role)->toBe(OrganizationRole::Admin)
        ->and($first->fresh()?->token_hash)->not->toBe($first->token_hash);
});

it('refuses to invite somebody who is already a member', function () {
    livewire(Members::class)
        ->callAction('invite', data: ['email' => 'member@example.org', 'role' => OrganizationRole::Admin->value])
        ->assertNotified();

    expect(OrganizationInvitation::query()->count())->toBe(0);
});

it('lets only an owner offer the owner role', function () {
    // assertSee() after mountAction() would read the component HTML from BEFORE
    // the action mounted (Livewire's SubsequentRender forwards the previous
    // html; the modal is in effects.partials), so it would pass here for the
    // wrong reason - the members table already prints the word "Owner" on the
    // owner's own row - and prove nothing about the Select's options.
    // assertMountedActionModalSee/DontSee read the partial Filament actually
    // rendered (vendor/filament/actions/src/Testing/TestsActions.php:512, :533);
    // tests/Feature/Organizer/ConferenceEmailTemplatesTest.php records the same
    // trap for this repo.
    livewire(Members::class)
        ->mountAction('invite')
        ->assertMountedActionModalSee(OrganizationRole::Owner->getLabel());

    actingAs($this->admin);

    // The option is not on an admin's list at all - roleOptions() filters it ...
    livewire(Members::class)
        ->mountAction('invite')
        ->assertMountedActionModalDontSee(OrganizationRole::Owner->getLabel());

    // ... and InviteMember::blockers() refuses it even if the value is forged.
    expect(app(App\Actions\Organizations\InviteMember::class)->blockers(
        $this->organization, 'x@example.org', OrganizationRole::Owner, $this->admin,
    ))->not->toBe([]);
});

it('resends an invitation with a new token and revokes one', function () {
    $invitation = OrganizationInvitation::factory()->for($this->organization)->create(['email' => 'invited@example.org']);
    $original = $invitation->token_hash;

    livewire(Members::class)
        ->callTableAction('resend', 'invitation:'.$invitation->getKey())
        ->assertHasNoTableActionErrors();

    expect($invitation->fresh()?->token_hash)->not->toBe($original);

    livewire(Members::class)
        ->callTableAction('revoke', 'invitation:'.$invitation->getKey())
        ->assertHasNoTableActionErrors();

    expect($invitation->fresh()?->revoked_at)->not->toBeNull();

    // Revoked, so it leaves the list - but the row survives, so the person
    // holding the emailed link gets a sentence rather than a 404 (Task 2).
    livewire(Members::class)->assertDontSee('invited@example.org');
});

it('changes a role and writes an activity entry', function () {
    livewire(Members::class)
        ->callTableAction('changeRole', 'member:'.$this->member->getKey(), data: ['role' => OrganizationRole::Admin->value])
        ->assertHasNoTableActionErrors();

    expect($this->member->fresh()?->roleIn($this->organization))->toBe(OrganizationRole::Admin)
        ->and(Activity::query()->where('description', 'organization.role_changed')->count())->toBe(1);
});

it('refuses to change your own role, from the page and from the action', function () {
    livewire(Members::class)
        ->assertTableActionHidden('changeRole', 'member:'.$this->owner->getKey())
        ->assertTableActionHidden('remove', 'member:'.$this->owner->getKey());

    expect(fn () => app(ChangeMemberRole::class)->handle($this->organization, $this->owner, OrganizationRole::Member, $this->owner))
        ->toThrow(MemberChangeRefused::class);
});

it('keeps at least one owner', function () {
    // The only owner: demoting them would leave the organization with nobody
    // who can invite, change a role, or edit the profile.
    $this->organization->members()->updateExistingPivot($this->admin->getKey(), ['role' => OrganizationRole::Member->value]);

    expect(app(ChangeMemberRole::class)->blockers($this->organization, $this->owner, OrganizationRole::Admin, $this->owner))
        ->not->toBe([])
        ->and(app(RemoveMember::class)->blockers($this->organization, $this->owner, $this->admin))
        ->not->toBe([]);

    // With a second owner, the first may be demoted.
    $this->organization->members()->updateExistingPivot($this->admin->getKey(), ['role' => OrganizationRole::Owner->value]);

    actingAs($this->admin);
    app(ChangeMemberRole::class)->handle($this->organization, $this->owner, OrganizationRole::Member, $this->admin);

    expect($this->owner->fresh()?->roleIn($this->organization))->toBe(OrganizationRole::Member);
});

it('stops an admin touching an owner', function () {
    actingAs($this->admin);
    bootOrganizerPanel($this->organization);

    expect(app(ChangeMemberRole::class)->blockers($this->organization, $this->owner, OrganizationRole::Member, $this->admin))->not->toBe([])
        ->and(app(RemoveMember::class)->blockers($this->organization, $this->owner, $this->admin))->not->toBe([])
        // ... but may still manage a plain member.
        ->and(app(RemoveMember::class)->blockers($this->organization, $this->member, $this->admin))->toBe([]);
});

it('removes a member and leaves their user account alone', function () {
    livewire(Members::class)
        ->callTableAction('remove', 'member:'.$this->member->getKey())
        ->assertHasNoTableActionErrors();

    expect($this->member->fresh()?->roleIn($this->organization))->toBeNull()
        ->and(User::query()->whereKey($this->member->getKey())->exists())->toBeTrue()
        ->and(Activity::query()->where('description', 'organization.member_removed')->count())->toBe(1);
});

it('lets a plain member open the page and toggle only their own notifications', function () {
    actingAs($this->member);
    bootOrganizerPanel($this->organization);

    livewire(Members::class)
        ->assertOk()
        ->assertActionHidden('invite')
        ->assertTableActionHidden('changeRole', 'member:'.$this->owner->getKey())
        ->assertTableActionHidden('notifications', 'member:'.$this->owner->getKey())
        ->assertTableActionVisible('notifications', 'member:'.$this->member->getKey())
        ->callTableAction('notifications', 'member:'.$this->member->getKey())
        ->assertHasNoTableActionErrors();

    // Default is true (Plan 1's column default), so one click turns it off -
    // and SubmitAbstract::notifiableMembers() stops reaching them.
    expect((bool) $this->member->fresh()?->organizations()->whereKey($this->organization)->first()?->pivot->notify_on_submission)
        ->toBeFalse()
        ->and(App\Actions\Submissions\SubmitAbstract::notifiableMembers(
            App\Models\Conference::factory()->for($this->organization)->published()->create(),
        )->pluck('id')->all())->not->toContain($this->member->id);
});

it('refuses the page to somebody with no role in this organization', function () {
    $outsider = User::factory()->create();
    actingAs($outsider);
    bootOrganizerPanel($this->organization);

    expect(Members::canAccess())->toBeFalse();
});

it('refuses a member ability over a soft-deleted organization instead of throwing', function () {
    // OrganizationMember::organization() can be null twice over: Organization
    // soft-deletes (app/Models/Organization.php:27) while the pivot row
    // survives, and User::roleIn() type-hints a non-nullable Organization
    // (app/Models/User.php:60). Unguarded, a gate that must answer "no" is a
    // TypeError 500 - the same guard SubmissionPolicy has carried since Plan 3.
    $row = App\Models\OrganizationMember::query()
        ->where('organization_id', $this->organization->getKey())
        ->where('user_id', $this->member->getKey())
        ->firstOrFail();

    $this->organization->delete();

    expect($this->owner->can('view', $row->fresh()))->toBeFalse()
        ->and($this->owner->can('update', $row->fresh()))->toBeFalse()
        ->and($this->owner->can('delete', $row->fresh()))->toBeFalse();
});

it('withdraws the invitations a removed or demoted member had minted', function () {
    // An invitation is the actor's authority, exercised later. A removed owner
    // must not still be able to install an owner through a link they made while
    // they could, and a demoted one must not either.
    $second = User::factory()->create(['email' => 'second.owner@example.org']);
    $this->organization->addMember($second, OrganizationRole::Owner);

    $byOwner = OrganizationInvitation::factory()->for($this->organization)->create([
        'email' => 'owner-invite@example.org',
        'role' => OrganizationRole::Owner,
        'invited_by' => $this->owner->id,
    ]);
    $byAdminAtOwner = OrganizationInvitation::factory()->for($this->organization)->create([
        'email' => 'admin-invite@example.org',
        'role' => OrganizationRole::Member,
        'invited_by' => $this->admin->id,
    ]);

    // Demotion from Owner to Admin: the Owner-level invitations they could no
    // longer mint go; a Member-level one they could still mint stays.
    $byOwnerAtMember = OrganizationInvitation::factory()->for($this->organization)->create([
        'email' => 'still-fine@example.org',
        'role' => OrganizationRole::Member,
        'invited_by' => $this->owner->id,
    ]);

    actingAs($second);
    app(ChangeMemberRole::class)->handle($this->organization, $this->owner, OrganizationRole::Admin, $second);

    expect($byOwner->fresh()?->revoked_at)->not->toBeNull()
        ->and($byOwnerAtMember->fresh()?->revoked_at)->toBeNull()
        ->and($byAdminAtOwner->fresh()?->revoked_at)->toBeNull();

    // Removal takes every live invitation the removed member minted, whatever
    // its role: they manage nothing now.
    app(RemoveMember::class)->handle($this->organization, $this->admin, $second);

    expect($byAdminAtOwner->fresh()?->revoked_at)->not->toBeNull();
});
```

Append to `tests/Feature/Mail/EmailLogPipelineTest.php` (keep its existing `beforeEach` and imports; it deliberately fakes nothing, and `phpunit.xml:36-37` pins `MAIL_MAILER=array` and `QUEUE_CONNECTION=sync`, so the queued notification is delivered inline and the mail events fire):

```php
it('logs an on-demand member invitation with its organization context', function () {
    $organization = Organization::factory()->approved()->create();
    $owner = User::factory()->create(['name' => 'Dr Owner']);
    $organization->addMember($owner, OrganizationRole::Owner);

    // Notification::route() is the only on-demand sender in the application:
    // the invitee has no account yet, by definition, so this is the one path
    // RecordOutgoingEmail::sending() ever sees with an AnonymousNotifiable
    // rather than a User. Everything else in this file is $user->notify().
    // Without this case nothing proves that member_invitation reaches
    // email_logs at all, or that MemberInvitation sets X-CASS-Organization,
    // which is the only thing that puts organization_id on the row
    // (app/Listeners/RecordOutgoingEmail.php:39-60).
    Notification::route('mail', 'invited@example.org')->notify(
        new App\Notifications\MemberInvitation(
            $organization,
            OrganizationRole::Admin,
            App\Support\Tokens\InvitationToken::generate(),
            'Dr Owner',
        ),
    );

    $log = EmailLog::query()->where('to_email', 'invited@example.org')->firstOrFail();

    expect($log->mailable)->toBe(App\Notifications\MemberInvitation::class)
        ->and($log->organization_id)->toBe($organization->id)
        // Not a spec 5.9 template key, so no template_key and no email_templates
        // row - deliberately, and asserted so nobody "tidies" it into one.
        ->and($log->template_key)->toBeNull()
        ->and($log->status)->toBe(EmailLogStatus::Sent);
});
```

with `use App\Enums\EmailLogStatus;`, `use App\Enums\OrganizationRole;`, `use App\Models\EmailLog;`, `use App\Models\Organization;`, `use App\Models\User;` and `use Illuminate\Support\Facades\Notification;` present in that file's import block (add whichever it does not already have).

- [ ] **Step 2: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/MembersPageTest.php tests/Feature/Mail/EmailLogPipelineTest.php > /tmp/t.log 2>&1; echo "rc=$?"; head -20 /tmp/t.log
```

Expected: `rc=1`, `Class "App\Filament\Organizer\Pages\Members" not found` from the first file and `Class "App\Notifications\MemberInvitation" not found` from the second.

- [ ] **Step 3: The exception, the policy and the two model relations**

`app/Exceptions/MemberChangeRefused.php`
```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class MemberChangeRefused extends RuntimeException
{
    /** @param  list<string>  $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
```

`app/Policies/OrganizationMemberPolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Who may act on a membership row. *What* the act may be - the last-owner rule,
 * the own-row rule, who may grant the owner role - lives in
 * ChangeMemberRole::blockers() and RemoveMember::blockers(), because those need
 * the target role and a count and a policy is handed neither.
 *
 * A new policy on a model nothing authorized against before, so it changes no
 * existing behaviour. It is deliberately not an OrganizationPolicy: the admin
 * panel's OrganizationResource explicitly works without one (see its
 * canAccess() docblock) and adding one would start being consulted by its row
 * actions.
 */
class OrganizationMemberPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    /** Every member sees the list; only some of them can change it. */
    public function viewAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }

    /**
     * `$organization !== null` is load-bearing, not defensive noise:
     * `$member->organization` is null whenever the tenant is soft-deleted
     * (app/Models/Organization.php:27) while its pivot rows survive, and
     * User::roleIn() type-hints a non-nullable Organization
     * (app/Models/User.php:60) - so the unguarded walk turns a gate that must
     * answer "no" into a TypeError 500. SubmissionPolicy has carried the same
     * guard since Plan 3.
     */
    public function view(User $user, OrganizationMember $member): bool
    {
        $organization = $member->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    /** Members arrive by accepting an invitation, never by a create form. */
    public function create(User $user): bool
    {
        return false;
    }

    /** Changing a role. */
    public function update(User $user, OrganizationMember $member): bool
    {
        $organization = $member->organization;

        return $organization !== null
            && ($user->roleIn($organization)?->canManageOrganization() ?? false);
    }

    /** Removing someone from the organization. */
    public function delete(User $user, OrganizationMember $member): bool
    {
        return $this->update($user, $member);
    }

    /** Your own "email me about new submissions" switch, and nobody else's. */
    public function updateNotifications(User $user, OrganizationMember $member): bool
    {
        return $member->user_id === $user->getKey();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, OrganizationMember $member): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, OrganizationMember $member): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
```

`app/Models/OrganizationMember.php` — add the two relations the policy reads:

```php
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
```

with `use Illuminate\Database\Eloquent\Relations\BelongsTo;` added.

- [ ] **Step 4: The five actions**

`app/Actions/Organizations/InviteMember.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Exceptions\MemberChangeRefused;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\MemberInvitation;
use App\Support\Tokens\InvitationToken;
use Illuminate\Support\Facades\Notification;

/**
 * Invite, re-invite and resend are all this one method.
 *
 * There is no unique index on (organization_id, email) - a revoked invitation
 * keeps its row, so a unique key would make "invite, revoke, invite again" fail
 * at the database. Uniqueness of the *live* invitation is here instead: the
 * newest un-accepted, un-revoked row for that address is refreshed with a new
 * token and a new expiry rather than joined by a second one, so an organizer
 * who clicks Invite twice does not put two working links in one inbox.
 */
class InviteMember
{
    /** @return list<string> empty when the invitation may be sent */
    public function blockers(Organization $organization, string $email, OrganizationRole $role, User $actor): array
    {
        $reasons = [];
        $email = mb_strtolower(trim($email));
        $actorRole = $actor->roleIn($organization);

        if ($actorRole === null || ! $actorRole->canManageOrganization()) {
            $reasons[] = __('members.errors.not_allowed');
        }

        if ($role === OrganizationRole::Owner && $actorRole !== OrganizationRole::Owner) {
            $reasons[] = __('members.errors.owner_grants_owner');
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $reasons[] = __('members.errors.bad_email');
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null && $existing->roleIn($organization) !== null) {
            $reasons[] = __('members.errors.already_a_member', ['email' => $email]);
        }

        return array_values(array_unique($reasons));
    }

    public function handle(Organization $organization, string $email, OrganizationRole $role, User $actor): OrganizationInvitation
    {
        $reasons = $this->blockers($organization, $email, $role, $actor);

        if ($reasons !== []) {
            throw new MemberChangeRefused($reasons);
        }

        $email = mb_strtolower(trim($email));
        $plain = InvitationToken::generate();

        $invitation = OrganizationInvitation::query()
            ->where('organization_id', $organization->getKey())
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->latest('id')
            ->first() ?? new OrganizationInvitation;

        $invitation->forceFill([
            'organization_id' => $organization->getKey(),
            'email' => $email,
            'role' => $role,
            'token_hash' => InvitationToken::hash($plain),
            'invited_by' => $actor->getKey(),
            'expires_at' => now()->addDays((int) config('cass.invitations.expiry_days')),
            'accepted_at' => null,
            'accepted_by' => null,
            'revoked_at' => null,
        ])->save();

        // On demand, because the invitee has no account yet by definition
        // (fact 20). Queued, and picked up by RecordOutgoingEmail through the
        // X-CASS-Organization header the notification adds.
        Notification::route('mail', $email)
            ->notify(new MemberInvitation($organization, $role, $plain, (string) $actor->name));

        activity()
            ->performedOn($invitation)
            ->causedBy($actor)
            ->withProperties(['email' => $email, 'role' => $role->value])
            ->log('organization.member_invited');

        return $invitation;
    }
}
```

`app/Actions/Organizations/RevokeInvitation.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\OrganizationInvitation;
use App\Models\User;

/**
 * The row survives with `revoked_at` stamped rather than being deleted, so the
 * person holding the emailed link reads "this invitation has been withdrawn"
 * instead of a 404 (Task 2).
 */
class RevokeInvitation
{
    public function handle(OrganizationInvitation $invitation, User $actor): OrganizationInvitation
    {
        if ($invitation->revoked_at === null) {
            $invitation->forceFill(['revoked_at' => now()])->save();

            activity()
                ->performedOn($invitation)
                ->causedBy($actor)
                ->withProperties(['email' => $invitation->email])
                ->log('organization.invitation_revoked');
        }

        return $invitation;
    }
}
```

`app/Actions/Organizations/ChangeMemberRole.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Exceptions\MemberChangeRefused;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ChangeMemberRole
{
    /** @return list<string> empty when the change may be made */
    public function blockers(Organization $organization, User $member, OrganizationRole $role, User $actor): array
    {
        $reasons = [];
        $actorRole = $actor->roleIn($organization);
        $currentRole = $member->roleIn($organization);

        if ($currentRole === null) {
            $reasons[] = __('members.errors.not_a_member');
        }

        if ($actorRole === null || ! $actorRole->canManageOrganization()) {
            $reasons[] = __('members.errors.not_allowed');
        }

        if ($member->is($actor)) {
            // Nobody edits their own role. An owner who wants to step down asks
            // another owner, which is also what stops the last owner demoting
            // themselves into an organization nobody can administer.
            $reasons[] = __('members.errors.own_role');
        }

        if ($role === OrganizationRole::Owner && $actorRole !== OrganizationRole::Owner) {
            $reasons[] = __('members.errors.owner_grants_owner');
        }

        if ($currentRole === OrganizationRole::Owner && $actorRole !== OrganizationRole::Owner) {
            $reasons[] = __('members.errors.owner_only_changes_owner');
        }

        if ($currentRole === OrganizationRole::Owner && $role !== OrganizationRole::Owner
            && $organization->owners()->count() <= 1) {
            $reasons[] = __('members.errors.last_owner');
        }

        return array_values(array_unique($reasons));
    }

    public function handle(Organization $organization, User $member, OrganizationRole $role, User $actor): void
    {
        $reasons = $this->blockers($organization, $member, $role, $actor);

        if ($reasons !== []) {
            throw new MemberChangeRefused($reasons);
        }

        $from = $member->roleIn($organization);

        // updateExistingPivot rather than a second withPivotValue relation:
        // withPivotValue() also *constrains the query* (fact 22), so a relation
        // built with it could not be used to write a different value.
        $organization->members()->updateExistingPivot($member->getKey(), ['role' => $role->value]);

        // A demotion takes the invitations they already minted with it. An
        // invitation is authority exercised later: a link created while somebody
        // could create it must not still install an Owner days after they lost
        // the right to. Promoting to Owner withdraws nothing. Demoting from
        // Owner to Admin - who still manages the organization - withdraws only
        // the Owner-level invitations they could no longer mint. Anything below
        // a manager withdraws all of them. AcceptInvitation::blockers() re-checks
        // the same rule at accept time, for rows this action never saw.
        if ($role !== OrganizationRole::Owner) {
            $organization->invitations()
                ->where('invited_by', $member->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->when(
                    $role->canManageOrganization(),
                    fn (Builder $query): Builder => $query->where('role', OrganizationRole::Owner->value),
                )
                ->update(['revoked_at' => now()]);
        }

        activity()
            ->performedOn($organization)
            ->causedBy($actor)
            ->withProperties(['user_id' => $member->getKey(), 'from' => $from?->value, 'to' => $role->value])
            ->log('organization.role_changed');
    }
}
```

`app/Actions/Organizations/RemoveMember.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Exceptions\MemberChangeRefused;
use App\Models\Organization;
use App\Models\User;

class RemoveMember
{
    /** @return list<string> empty when the member may be removed */
    public function blockers(Organization $organization, User $member, User $actor): array
    {
        $reasons = [];
        $actorRole = $actor->roleIn($organization);
        $currentRole = $member->roleIn($organization);

        if ($currentRole === null) {
            $reasons[] = __('members.errors.not_a_member');
        }

        if ($actorRole === null || ! $actorRole->canManageOrganization()) {
            $reasons[] = __('members.errors.not_allowed');
        }

        if ($member->is($actor)) {
            $reasons[] = __('members.errors.own_membership');
        }

        if ($currentRole === OrganizationRole::Owner && $actorRole !== OrganizationRole::Owner) {
            $reasons[] = __('members.errors.owner_only_changes_owner');
        }

        if ($currentRole === OrganizationRole::Owner && $organization->owners()->count() <= 1) {
            $reasons[] = __('members.errors.last_owner');
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Detaches the pivot row only. The `users` row survives, because the person
     * may be a member of another organization, a reviewer on a conference, or
     * simply somebody with an account - and because their name still has to
     * render beside the conferences they published.
     */
    public function handle(Organization $organization, User $member, User $actor): void
    {
        $reasons = $this->blockers($organization, $member, $actor);

        if ($reasons !== []) {
            throw new MemberChangeRefused($reasons);
        }

        $role = $member->roleIn($organization);

        // An invitation is the removed member's authority, exercised later. A
        // removed owner must not still be able to install an owner through a
        // link they minted while they could, so every live invitation of theirs
        // goes with them. Revoked rather than deleted, so whoever holds the
        // emailed link meets Task 2's sentence rather than a 404.
        // AcceptInvitation::blockers() re-checks the same rule at accept time.
        $organization->invitations()
            ->where('invited_by', $member->getKey())
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $organization->members()->detach($member->getKey());

        activity()
            ->performedOn($organization)
            ->causedBy($actor)
            ->withProperties(['user_id' => $member->getKey(), 'role' => $role?->value])
            ->log('organization.member_removed');
    }
}
```

`app/Actions/Organizations/SetSubmissionNotifications.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Exceptions\MemberChangeRefused;
use App\Models\Organization;
use App\Models\User;

/**
 * `organization_members.notify_on_submission`, the column Plan 1 created and
 * Plan 3's SubmitAbstract::notifiableMembers() reads. A person sets their own
 * preference and nobody else's: an owner switching a colleague's mail off is a
 * support ticket waiting to happen, and there is no reason for it.
 */
class SetSubmissionNotifications
{
    public function handle(Organization $organization, User $member, bool $enabled, User $actor): void
    {
        if (! $member->is($actor)) {
            throw new MemberChangeRefused([__('members.errors.own_notifications')]);
        }

        if ($member->roleIn($organization) === null) {
            throw new MemberChangeRefused([__('members.errors.not_a_member')]);
        }

        $organization->members()->updateExistingPivot($member->getKey(), [
            'notify_on_submission' => $enabled,
        ]);
    }
}
```

- [ ] **Step 5: The notification**

`app/Notifications/MemberInvitation.php`
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * Deliberately *not* a spec 5.9 template key and deliberately not editable per
 * conference: it is a platform message about a platform account, sent before
 * the recipient has any relationship with the organization beyond this
 * invitation, and it has nothing to do with any one conference.
 *
 * It still lands in `email_logs`: RecordOutgoingEmail writes a row for every
 * message and reads the X-CASS-Organization header added below, exactly as it
 * does for NewSubmissionNotice.
 *
 * The plaintext token travels in the queued job payload for as long as the job
 * is queued. That is the same exposure Plan 3's status links already have, and
 * it is written down in the runbook rather than pretended away.
 */
class MemberInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Organization $organization,
        public OrganizationRole $role,
        public string $token,
        public string $inviterName,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('invitation.accept', ['token' => $this->token]);

        return (new MailMessage)
            ->subject(__('members.mail.subject', ['organization' => $this->organization->name]))
            ->greeting(__('members.mail.greeting'))
            ->line(__('members.mail.intro', [
                'inviter' => $this->inviterName,
                'organization' => $this->organization->name,
                'role' => $this->role->getLabel(),
            ]))
            ->action(__('members.mail.action'), $url)
            ->line(__('members.mail.expiry', ['days' => (int) config('cass.invitations.expiry_days')]))
            ->line(__('members.mail.ignore'))
            ->withSymfonyMessage(function (Email $message): void {
                $message->getHeaders()->addTextHeader('X-CASS-Organization', (string) $this->organization->getKey());
            });
    }
}
```

- [ ] **Step 6: The page**

`app/Filament/Organizer/Pages/Members.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Pages;

use App\Actions\Organizations\ChangeMemberRole;
use App\Actions\Organizations\InviteMember;
use App\Actions\Organizations\RemoveMember;
use App\Actions\Organizations\RevokeInvitation;
use App\Actions\Organizations\SetSubmissionNotifications;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Exceptions\MemberChangeRefused;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Spec section 4, "Manage organization members".
 *
 * One **array-backed** table (fact 15) listing members and open invitations
 * together, because that is the question the person on this page is asking. Row
 * actions receive `array $record`; the `__key` is `member:{user id}` or
 * `invitation:{invitation id}`, which is also what every test addresses.
 */
class Members extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.organizer.pages.members';

    /**
     * Every member of the tenant, not only the managers: a plain member needs
     * this page to turn their own submission notifications off. The management
     * actions are each gated separately.
     *
     * Note what this does NOT admit: a platform admin who is not a member of the
     * organization. OrganizationMemberPolicy::before() and
     * OrganizationInvitationPolicy::before() both answer true for them, but this
     * page lives in the membership-gated organizer panel
     * (User::canAccessPanel('organizer') is organizations()->exists(),
     * app/Models/User.php:70), so spec section 4's platform-admin cell for
     * "Manage organization members" has no screen behind it - exactly as Plan 3
     * left submissions. Plan 6 adds the read-only admin resources; it is in the
     * backlog and in the "does NOT build" list, not a surprise.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();

        return $user instanceof User
            && $tenant instanceof Organization
            && $user->roleIn($tenant) !== null;
    }

    public static function getNavigationLabel(): string
    {
        return __('members.page.title');
    }

    public function getTitle(): string
    {
        return __('members.page.title');
    }

    public function getSubheading(): ?string
    {
        return __('members.page.subheading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->rows())
            ->columns([
                TextColumn::make('name')->label(__('members.columns.name'))->weight('semibold')
                    ->description(fn (array $record): string => $record['email']),
                TextColumn::make('role')->label(__('members.columns.role'))->badge(),
                TextColumn::make('state')->label(__('members.columns.state'))->badge()
                    ->color(fn (array $record): string => $record['state_color']),
                TextColumn::make('joined')->label(__('members.columns.joined')),
                TextColumn::make('notify_label')->label(__('members.columns.notify')),
            ])
            ->recordActions([
                $this->changeRoleAction(),
                $this->notificationsAction(),
                $this->removeAction(),
                $this->resendAction(),
                $this->revokeAction(),
            ])
            ->paginated(false);
    }

    /**
     * The value type is the exact shape rather than `array<string, mixed>`:
     * Collection's TValue is invariant, so the wider annotation is a
     * return.type error at Larastan level 6, and the narrow one tells every
     * column and action closure below what `$record` really holds.
     *
     * @return Collection<string, array{kind: string, id: int, name: string, email: string,
     *     role: string, role_value: string, state: string, state_color: string, joined: string,
     *     notify: bool|null, notify_label: string, is_self: bool, is_owner: bool}>
     */
    private function rows(): Collection
    {
        $organization = $this->getOrganization();
        $actor = $this->actor();

        /** @var Collection<string, array<string, mixed>> $members */
        $members = $organization->members()->orderBy('name')->get()
            ->mapWithKeys(function (User $user) use ($actor): array {
                /** @var OrganizationMember $pivot */
                $pivot = $user->pivot;
                $isSelf = $actor !== null && $actor->getKey() === $user->getKey();

                return ['member:'.$user->getKey() => [
                    'kind' => 'member',
                    'id' => (int) $user->getKey(),
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                    'role' => $pivot->role->getLabel(),
                    'role_value' => $pivot->role->value,
                    'state' => __('members.state.member'),
                    'state_color' => 'success',
                    'joined' => $pivot->created_at?->format('j M Y') ?? '-',
                    'notify' => (bool) $pivot->notify_on_submission,
                    // Only your own preference is anybody's business.
                    'notify_label' => $isSelf
                        ? ($pivot->notify_on_submission ? __('members.notify.on') : __('members.notify.off'))
                        : '-',
                    'is_self' => $isSelf,
                    'is_owner' => $pivot->role === OrganizationRole::Owner,
                ]];
            });

        /** @var Collection<string, array<string, mixed>> $invitations */
        $invitations = $organization->invitations()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(function (OrganizationInvitation $invitation): array {
                $status = $invitation->invitationStatus();

                return ['invitation:'.$invitation->getKey() => [
                    'kind' => 'invitation',
                    'id' => (int) $invitation->getKey(),
                    'name' => $invitation->email,
                    'email' => $invitation->email,
                    'role' => $invitation->role->getLabel(),
                    'role_value' => $invitation->role->value,
                    'state' => $status === InvitationStatus::Expired
                        ? __('members.state.expired')
                        : __('members.state.invited'),
                    'state_color' => $status->getColor(),
                    'joined' => '-',
                    'notify' => null,
                    'notify_label' => '-',
                    'is_self' => false,
                    'is_owner' => false,
                ]];
            });

        /** @var Collection<string, array{kind: string, id: int, name: string, email: string, role: string, role_value: string, state: string, state_color: string, joined: string, notify: bool|null, notify_label: string, is_self: bool, is_owner: bool}> $rows */
        $rows = $members->merge($invitations);

        return $rows;
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label(__('members.actions.invite'))
                ->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn (): bool => Gate::allows('create', OrganizationInvitation::class))
                ->modalHeading(__('members.actions.invite_heading'))
                ->modalDescription(__('members.actions.invite_description'))
                ->schema([
                    TextInput::make('email')->label(__('members.fields.email'))
                        ->email()->required()->maxLength(255),
                    Select::make('role')->label(__('members.fields.role'))
                        // A plain options array, not `->options(OrganizationRole::class)`:
                        // an enum-backed Select registers a state cast (fact 27)
                        // and `$data['role']` inside the action closure would be
                        // an enum case here and a string there depending on how
                        // it was filled. A string both ways removes the question,
                        // and the list has to be filtered by the actor's own role
                        // anyway.
                        ->options(fn (): array => $this->roleOptions())
                        ->default(OrganizationRole::Member->value)
                        ->required()
                        ->helperText(__('members.fields.role_help')),
                ])
                ->action(function (array $data, InviteMember $invite): void {
                    Gate::authorize('create', OrganizationInvitation::class);

                    try {
                        $invite->handle(
                            $this->getOrganization(),
                            (string) $data['email'],
                            OrganizationRole::from((string) $data['role']),
                            $this->requireActor(),
                        );
                    } catch (MemberChangeRefused $exception) {
                        $this->refuse($exception);

                        return;
                    }

                    Notification::make()->success()
                        ->title(__('members.notices.invited'))
                        ->body(__('members.notices.invited_body', ['email' => e((string) $data['email'])]))
                        ->send();
                }),
        ];
    }

    private function changeRoleAction(): Action
    {
        return Action::make('changeRole')
            ->label(__('members.actions.change_role'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (array $record): bool => $record['kind'] === 'member'
                && ! $record['is_self']
                && $this->canManageMember($record['id']))
            ->modalHeading(fn (array $record): string => __('members.actions.change_role_heading', ['name' => $record['name']]))
            ->fillForm(fn (array $record): array => ['role' => $record['role_value']])
            ->schema([
                Select::make('role')->label(__('members.fields.role'))
                    ->options(fn (): array => $this->roleOptions())
                    ->required(),
            ])
            ->action(function (array $record, array $data, ChangeMemberRole $change): void {
                $member = $this->memberUser($record['id']);

                Gate::authorize('update', $this->pivotFor($member));

                try {
                    $change->handle(
                        $this->getOrganization(),
                        $member,
                        OrganizationRole::from((string) $data['role']),
                        $this->requireActor(),
                    );
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
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => __('members.actions.remove_heading', ['name' => $record['name']]))
            ->modalDescription(__('members.actions.remove_description'))
            ->visible(fn (array $record): bool => $record['kind'] === 'member'
                && ! $record['is_self']
                && $this->canManageMember($record['id']))
            ->action(function (array $record, RemoveMember $remove): void {
                $member = $this->memberUser($record['id']);

                Gate::authorize('delete', $this->pivotFor($member));

                try {
                    $remove->handle($this->getOrganization(), $member, $this->requireActor());
                } catch (MemberChangeRefused $exception) {
                    $this->refuse($exception);

                    return;
                }

                Notification::make()->success()->title(__('members.notices.removed'))->send();
            });
    }

    private function notificationsAction(): Action
    {
        return Action::make('notifications')
            ->label(fn (array $record): string => $record['notify'] === true
                ? __('members.actions.notifications_off')
                : __('members.actions.notifications_on'))
            ->icon(Heroicon::OutlinedBell)
            ->color('gray')
            ->visible(fn (array $record): bool => $record['kind'] === 'member' && $record['is_self'])
            ->action(function (array $record, SetSubmissionNotifications $set): void {
                $actor = $this->requireActor();

                Gate::authorize('updateNotifications', $this->pivotFor($actor));

                try {
                    $set->handle($this->getOrganization(), $actor, $record['notify'] !== true, $actor);
                } catch (MemberChangeRefused $exception) {
                    $this->refuse($exception);

                    return;
                }

                Notification::make()->success()->title(__('members.notices.notifications_saved'))->send();
            });
    }

    private function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('members.actions.resend'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('members.actions.resend_heading'))
            ->modalDescription(__('members.actions.resend_description'))
            ->visible(fn (array $record): bool => $record['kind'] === 'invitation'
                && Gate::allows('create', OrganizationInvitation::class))
            ->action(function (array $record, InviteMember $invite): void {
                $invitation = $this->invitation($record['id']);

                Gate::authorize('update', $invitation);

                try {
                    // The same method as Invite: one live row per address, with
                    // a new token that kills the old link.
                    $invite->handle(
                        $this->getOrganization(),
                        (string) $invitation->email,
                        $invitation->role,
                        $this->requireActor(),
                    );
                } catch (MemberChangeRefused $exception) {
                    $this->refuse($exception);

                    return;
                }

                Notification::make()->success()->title(__('members.notices.resent'))->send();
            });
    }

    private function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('members.actions.revoke'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('members.actions.revoke_heading'))
            ->modalDescription(__('members.actions.revoke_description'))
            ->visible(fn (array $record): bool => $record['kind'] === 'invitation'
                && Gate::allows('create', OrganizationInvitation::class))
            ->action(function (array $record, RevokeInvitation $revoke): void {
                $invitation = $this->invitation($record['id']);

                Gate::authorize('delete', $invitation);

                $revoke->handle($invitation, $this->requireActor());

                Notification::make()->success()->title(__('members.notices.revoked'))->send();
            });
    }

    /**
     * The owner role is only offered by an owner. `InviteMember::blockers()`
     * and `ChangeMemberRole::blockers()` refuse it as well, so a forged option
     * value is refused rather than merely invisible.
     *
     * @return array<string, string>
     */
    private function roleOptions(): array
    {
        $actorRole = $this->actor()?->roleIn($this->getOrganization());

        $options = [];

        foreach (OrganizationRole::cases() as $role) {
            if ($role === OrganizationRole::Owner && $actorRole !== OrganizationRole::Owner) {
                continue;
            }

            $options[$role->value] = $role->getLabel();
        }

        return $options;
    }

    public function getOrganization(): Organization
    {
        /** @var Organization $tenant */
        $tenant = Filament::getTenant();

        return $tenant;
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function requireActor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function canManageMember(int $userId): bool
    {
        $member = User::query()->find($userId);

        return $member !== null && Gate::allows('update', $this->pivotFor($member));
    }

    /** The pivot row the policy is handed, fetched rather than reconstructed. */
    private function pivotFor(User $member): OrganizationMember
    {
        /** @var OrganizationMember $pivot */
        $pivot = OrganizationMember::query()
            ->where('organization_id', $this->getOrganization()->getKey())
            ->where('user_id', $member->getKey())
            ->firstOrFail();

        return $pivot;
    }

    private function memberUser(int $userId): User
    {
        /** @var User $user */
        $user = $this->getOrganization()->members()->whereKey($userId)->firstOrFail();

        return $user;
    }

    private function invitation(int $id): OrganizationInvitation
    {
        /** @var OrganizationInvitation $invitation */
        $invitation = $this->getOrganization()->invitations()->whereKey($id)->firstOrFail();

        return $invitation;
    }

    private function refuse(MemberChangeRefused $exception): void
    {
        // Filament renders a notification body as sanitised HTML whose shared
        // config keeps `style` and `class` (Plan 2 fact 15), and these messages
        // interpolate an address somebody typed - escape it.
        Notification::make()->danger()
            ->title(__('members.notices.refused'))
            ->body(e($exception->getMessage()))
            ->persistent()
            ->send();
    }
}
```

`resources/views/filament/organizer/pages/members.blade.php`
```blade
<x-filament-panels::page>
    {{-- The panel loads no Tailwind utilities (backlog: the organizer theme),
         so spacing here is an inline style, exactly as on the email-templates
         and share pages. --}}
    <div style="display:flex;flex-direction:column;gap:1.5rem">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
```

- [ ] **Step 7: The language keys**

Append to `lang/en/members.php`, inside the top-level array:

```php
    'page' => [
        'title' => 'Team',
        'subheading' => 'Everyone who can work on this organization\'s conferences, and everyone who has been invited.',
    ],

    'columns' => [
        'name' => 'Name',
        'role' => 'Role',
        'state' => 'Status',
        'joined' => 'Joined',
        'notify' => 'New-abstract emails',
    ],

    'state' => [
        'member' => 'Member',
        'invited' => 'Invited',
        'expired' => 'Invitation expired',
    ],

    'notify' => [
        'on' => 'On',
        'off' => 'Off',
    ],

    'fields' => [
        'email' => 'Email address',
        'role' => 'Role',
        'role_help' => 'Owners and admins manage the team and the organization profile. Members create conferences, invite reviewers and read submissions.',
    ],

    'actions' => [
        'invite' => 'Invite someone',
        'invite_heading' => 'Invite someone to this organization',
        'invite_description' => 'They get an email with a link that works for 14 days. If they already have a CASS account, the link signs them in and adds them.',
        'change_role' => 'Change role',
        'change_role_heading' => 'Change the role of :name',
        'remove' => 'Remove',
        'remove_heading' => 'Remove :name?',
        'remove_description' => 'They lose access to this organization straight away. Their CASS account and anything they created stay exactly as they are, and you can invite them again later.',
        'notifications_on' => 'Email me about new abstracts',
        'notifications_off' => 'Stop emailing me about new abstracts',
        'resend' => 'Resend',
        'resend_heading' => 'Send the invitation again?',
        'resend_description' => 'A new link is emailed. **Any link they already have stops working**, which is the point when a link was lost.',
        'revoke' => 'Withdraw',
        'revoke_heading' => 'Withdraw this invitation?',
        'revoke_description' => 'The link stops working. If they follow it they are told the invitation was withdrawn.',
    ],

    'notices' => [
        'invited' => 'Invitation sent',
        'invited_body' => 'A link is on its way to :email.',
        'role_changed' => 'Role updated',
        'removed' => 'Removed from the organization',
        'notifications_saved' => 'Preference saved',
        'resent' => 'Invitation sent again',
        'revoked' => 'Invitation withdrawn',
        'refused' => 'Nothing changed',
    ],

    'errors' => [
        'not_allowed' => 'Only an owner or an admin can manage the team.',
        'not_a_member' => 'That person is not a member of this organization.',
        'own_role' => 'You cannot change your own role. Ask another owner.',
        'own_membership' => 'You cannot remove yourself. Ask another owner.',
        'own_notifications' => 'You can only change your own email preference.',
        'owner_grants_owner' => 'Only an owner can make somebody else an owner.',
        'owner_only_changes_owner' => 'Only an owner can change or remove another owner.',
        'last_owner' => 'An organization always needs at least one owner. Make somebody else an owner first.',
        'already_a_member' => ':email is already a member of this organization.',
        'bad_email' => 'That does not look like an email address.',
    ],

    'mail' => [
        'subject' => 'You have been invited to join :organization on CASS',
        'greeting' => 'Hello,',
        'intro' => ':inviter has invited you to join **:organization** on CASS as :role. CASS is where they collect and review conference abstracts.',
        'action' => 'Accept the invitation',
        'expiry' => 'This link works for :days days. After that, ask them to send a new one.',
        'ignore' => 'If you were not expecting this, you can ignore this email and nothing happens.',
    ],
```

- [ ] **Step 8: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/MembersPageTest.php tests/Feature/Mail/EmailLogPipelineTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -6 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`, **nothing skipped**; about 15 in `MembersPageTest` plus `EmailLogPipelineTest`'s existing cases and its one new one, and `baseline + 59` in the second. Three of those are new to this revision of the plan: `it('refuses a member ability over a soft-deleted organization instead of throwing')`, `it('withdraws the invitations a removed or demoted member had minted')`, and `EmailLogPipelineTest`'s `it('logs an on-demand member invitation with its organization context')` — the only thing in the suite that proves `member_invitation` reaches `email_logs` with an `organization_id` on it. The `it('lets only an owner offer the owner role')` case now uses `assertMountedActionModalSee`/`DontSee`, so it reads the modal partial instead of the pre-mount HTML; if it fails, the cause is `roleOptions()` not filtering Owner for an admin, which is the rule it exists to prove.

- [ ] **Step 9: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(organizer): invite, promote and remove organization members

One array-backed page lists members and open invitations together. The
last-owner, own-row and owner-grants-owner rules live in the actions' blockers()
because they need a target role and a count that a policy is never handed;
OrganizationMemberPolicy answers only who may act.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`. The Larastan complaint to expect here is `Collection` invariance on `rows()`; the two intermediate `@var` annotations plus the final narrowing one are what satisfy it.

---
### Task 4: Reviewer invitations on the conference

Spec 5.4 steps 1 and 2 from the organizer's side: invite one reviewer by name and email, or paste a list; resend; withdraw; remove an accepted reviewer. The first of the three reviewer template keys Plan 3 shipped defaults for is finally *sent*.

**The same page shape as Task 3, and for the same reason.** Reviewers live in `conference_reviewers`, pending invitations in `reviewer_invitations`, and an organizer asks one question — "who is reviewing this conference?" — so it is one array-backed table with a state badge, registered as a conference resource page at `conferences/{record}/reviewers`, exactly as `ConferenceShortLink` and `ConferenceEmailTemplates` are (fact 15, and Plan 3's fact 11 for why a page beats a relation manager when the table is not one relation).

**Removing a reviewer deletes their assignments and keeps their reviews.** A removed reviewer is not going to review, so leaving `review_assignments` rows behind would make the coverage summary in Task 8 and the balance in Task 9 both read from a fiction — "this abstract has two reviewers" when it has one. Reviews are the opposite: a submitted review is the record of what the committee was told and survives its author's removal, and a draft survives because re-inviting the same person is one click and their work should still be there. Both halves are logged (spec section 9 names reviewer removals explicitly).

**`{{review_link}}` means two different things, on purpose.** In `reviewer_invitation` it is the **accept URL** (`/invite/{token}`) — the reviewer has no account and no queue yet. In `reviewer_reminder` and `reviewer_overdue` (Task 10) it is the reviewer panel's queue. That is what spec 5.4 describes in steps 1 and 5 respectively, and the platform default bodies Plan 3 wrote read correctly under both readings.

**The contract:**

| Class | Signature |
|---|---|
| `App\Support\Reviews\ReviewerList` | `static parse(string $text): self`, with `list<array{name: ?string, email: string}> $entries`, `list<string> $errors`, `list<string> $duplicates` |
| `InviteReviewer` | `blockers(Conference $c, string $email, User $actor): list<string>` |
| `InviteReviewer` | `handle(Conference $c, string $email, ?string $name, ?string $affiliation, User $actor): ReviewerInvitation` |
| `InviteReviewer` | `static placeholderValues(Conference $c, string $reviewerName, string $reviewLink): array<string, string|null>` |
| `InviteReviewerList` | `handle(Conference $c, string $text, User $actor): array{invited: int, skipped: list<string>, errors: list<string>}` |
| `RevokeReviewerInvitation` | `handle(ReviewerInvitation $invitation, User $actor): ReviewerInvitation` |
| `RemoveConferenceReviewer` | `handle(ConferenceReviewer $reviewer, User $actor): ConferenceReviewer` |

**Files:**
- Create: `app/Support/Reviews/ReviewerList.php`
- Create: `app/Actions/Reviewers/{InviteReviewer,InviteReviewerList,RevokeReviewerInvitation,RemoveConferenceReviewer}.php`
- Create: `app/Filament/Organizer/Resources/Conferences/Pages/ConferenceReviewers.php`, `resources/views/filament/organizer/resources/conferences/pages/reviewers.blade.php`
- Modify: `app/Filament/Organizer/Resources/Conferences/ConferenceResource.php`, `.../Tables/ConferenceStatusActions.php`, `app/Actions/Mail/SendTemplatedEmail.php` (the subject redaction gains the `/invite/{64}` shape), `config/cass.php` (`list_max`, `send_rate_limit` — declared in Task 1 Step 8, used here), `lang/en/reviewer.php`
- Test: `tests/Unit/ReviewerListTest.php`, `tests/Feature/Organizer/ConferenceReviewersTest.php`, `tests/Feature/Mail/TemplatedMailTest.php` (modified: the second token shape)

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ReviewerListTest.php`
```php
<?php

declare(strict_types=1);

use App\Support\Reviews\ReviewerList;

it('parses the two shapes spec 5.4 names', function () {
    $parsed = ReviewerList::parse(<<<'TXT'
    Dr Omar Khan <omar@example.org>
    sara@example.org
    TXT);

    expect($parsed->entries)->toBe([
        ['name' => 'Dr Omar Khan', 'email' => 'omar@example.org'],
        ['name' => null, 'email' => 'sara@example.org'],
    ])->and($parsed->errors)->toBe([])
        ->and($parsed->duplicates)->toBe([]);
});

it('parses the shapes a spreadsheet paste actually produces', function () {
    // Comma, semicolon and tab separated, in both orders. Whichever half looks
    // like an address is the address; the other half is the name.
    $parsed = ReviewerList::parse(<<<'TXT'
    Dr Omar Khan, omar@example.org
    sara@example.org; Dr Sara Al-Harbi
    Dr Layla Ahmed	layla@example.org
      "Dr Noor Ali" <noor@example.org>
    TXT);

    expect(array_column($parsed->entries, 'email'))
        ->toBe(['omar@example.org', 'sara@example.org', 'layla@example.org', 'noor@example.org'])
        ->and(array_column($parsed->entries, 'name'))
        ->toBe(['Dr Omar Khan', 'Dr Sara Al-Harbi', 'Dr Layla Ahmed', 'Dr Noor Ali']);
});

it('lower-cases addresses, drops blank lines and reports every bad line with its number', function () {
    $parsed = ReviewerList::parse(<<<'TXT'
    Dr Omar Khan <OMAR@Example.ORG>

    not an address at all
    Dr Nobody <@example.org>
    TXT);

    expect(array_column($parsed->entries, 'email'))->toBe(['omar@example.org'])
        ->and($parsed->errors)->toHaveCount(2)
        ->and($parsed->errors[0])->toContain('3')
        ->and($parsed->errors[0])->toContain('not an address at all')
        ->and($parsed->errors[1])->toContain('4');
});

it('deduplicates on the address and keeps the first name it was given', function () {
    $parsed = ReviewerList::parse(<<<'TXT'
    Dr Omar Khan <omar@example.org>
    omar@example.org
    Someone Else <OMAR@EXAMPLE.ORG>
    TXT);

    expect($parsed->entries)->toBe([['name' => 'Dr Omar Khan', 'email' => 'omar@example.org']])
        ->and($parsed->duplicates)->toBe(['omar@example.org']);
});

it('answers empty for empty input rather than one blank entry', function () {
    $parsed = ReviewerList::parse("\n  \n\t\n");

    expect($parsed->entries)->toBe([])->and($parsed->errors)->toBe([]);
});

it('stops at the entry cap and says so', function () {
    // The textarea takes 20,000 characters - about 2,500 bare addresses - and
    // InviteReviewerList loops synchronously, about eight queries plus a
    // rendered template plus a queued mailable per entry, inside one Livewire
    // POST that php-fpm and nginx both abandon at 60 seconds. A paste past the
    // cap must be a sentence, not a 504 halfway through a batch whose already
    // delivered links the retry would invalidate.
    $text = collect(range(1, 150))->map(fn (int $i): string => "r{$i}@example.org")->implode("\n");

    $parsed = ReviewerList::parse($text);

    expect($parsed->entries)->toHaveCount(ReviewerList::MAX_ENTRIES)
        ->and($parsed->errors)->toHaveCount(1)
        ->and($parsed->errors[0])->toContain((string) ReviewerList::MAX_ENTRIES);
});
```

`tests/Feature/Organizer/ConferenceReviewersTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\EmailTemplateKey;
use App\Enums\OrganizationRole;
use App\Enums\ReviewerStatus;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceReviewers;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\Review;
use App\Models\ReviewerInvitation;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Mail::fake();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->closed()->create([
        'name' => 'Alpha Annual Meeting',
        'review_deadline' => now()->addMonth(),
    ]);
});

function reviewersPage(Conference $conference): object
{
    return livewire(ConferenceReviewers::class, ['record' => $conference->getRouteKey()]);
}

it('invites one reviewer, queues the templated email and logs it', function () {
    reviewersPage($this->conference)
        ->callAction('invite', data: [
            'name' => 'Dr Omar Khan',
            'email' => 'Omar@Example.ORG',
            'affiliation' => 'KFSH',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $invitation = ReviewerInvitation::query()->firstOrFail();

    expect($invitation->email)->toBe('omar@example.org')
        ->and($invitation->name)->toBe('Dr Omar Khan')
        ->and($invitation->affiliation)->toBe('KFSH')
        ->and($invitation->conference_id)->toBe($this->conference->id)
        ->and($invitation->invited_by)->toBe($this->user->id)
        ->and($invitation->token_hash)->toHaveLength(64);

    Mail::assertQueued(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->hasTo('omar@example.org')
            && $mail->templateKey === EmailTemplateKey::ReviewerInvitation->value
            // {{review_link}} in an invitation is the ACCEPT url, because the
            // reviewer has no account and no queue yet (spec 5.4 step 1).
            && str_contains($mail->body, '/invite/'),
    );

    $log = EmailLog::query()->firstOrFail();
    expect($log->template_key)->toBe('reviewer_invitation')
        ->and($log->conference_id)->toBe($this->conference->id)
        ->and($log->to_email)->toBe('omar@example.org');
});

it('renders the conference and deadline into the invitation', function () {
    reviewersPage($this->conference)->callAction('invite', data: [
        'name' => 'Dr Omar Khan', 'email' => 'omar@example.org', 'affiliation' => null,
    ]);

    Mail::assertQueued(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => str_contains($mail->body, 'Dr Omar Khan')
            && str_contains($mail->body, 'Alpha Annual Meeting')
            && str_contains($mail->body, 'Alpha Society')
            && str_contains($mail->body, $this->conference->reviewDeadlineInConferenceTimezone()->format('j F Y')),
    );
});

it('invites a pasted list, reports the bad lines and skips the duplicates', function () {
    ReviewerInvitation::factory()->for($this->conference)->create(['email' => 'already@example.org']);

    reviewersPage($this->conference)
        ->callAction('inviteList', data: ['list' => implode("\n", [
            'Dr Omar Khan <omar@example.org>',
            'sara@example.org',
            'omar@example.org',
            'rubbish',
            'already@example.org',
        ])])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(ReviewerInvitation::query()->pluck('email')->sort()->values()->all())
        ->toBe(['already@example.org', 'omar@example.org', 'sara@example.org'])
        // The duplicate line and the existing invitation both refresh rather
        // than insert, so three addresses means three rows.
        ->and(ReviewerInvitation::query()->count())->toBe(3);

    Mail::assertQueued(TemplatedMail::class, 3);
});

it('caps one paste at a hundred and says what it did not send', function () {
    // The textarea takes 20,000 characters and InviteReviewerList loops
    // synchronously inside one Livewire POST that php-fpm abandons at 60
    // seconds, so an uncapped paste is a 504 halfway through a half-delivered
    // batch - and, because ReviewerInvitationPolicy::create() admits every
    // organization member, an uncapped bulk-mail primitive besides.
    $text = collect(range(1, 150))->map(fn (int $i): string => "r{$i}@example.org")->implode("\n");

    reviewersPage($this->conference)
        ->callAction('inviteList', data: ['list' => $text])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(ReviewerInvitation::query()->count())->toBe(App\Support\Reviews\ReviewerList::MAX_ENTRIES);

    Mail::assertQueued(TemplatedMail::class, App\Support\Reviews\ReviewerList::MAX_ENTRIES);
});

it('stops the whole run once the hourly send limit trips, and says so once', function () {
    // Not once per remaining line: the limit is about the run, and forty copies
    // of one sentence in a notification body is not a report.
    $limit = (int) config('cass.invitations.send_rate_limit');
    Illuminate\Support\Facades\RateLimiter::clear('invitation-send:'.$this->user->getKey());

    // A live invitation sent before the limit tripped, whose address is
    // deliberately the first line of the paste below. InviteReviewer re-invites
    // by refreshing this very row - new token_hash, new expires_at - so if the
    // limiter were consulted AFTER that write, this refused run would silently
    // kill a link the reviewer was emailed an hour ago, and would leave phantom
    // "Invited" rows for b@ and c@ that no mail ever matched. Reading the
    // persisted values before and after, rather than comparing to the in-memory
    // model, keeps the assertion honest about sub-second precision.
    $live = ReviewerInvitation::factory()->for($this->conference)->create([
        'email' => 'a@example.org',
        'name' => 'Dr Aya Nasser',
    ]);
    $hashBefore = (string) $live->token_hash;
    $expiresBefore = (string) $live->fresh()?->expires_at?->toDateTimeString();

    foreach (range(1, $limit) as $ignored) {
        Illuminate\Support\Facades\RateLimiter::hit('invitation-send:'.$this->user->getKey(), 3600);
    }

    $result = app(App\Actions\Reviewers\InviteReviewerList::class)->handle(
        $this->conference,
        "a@example.org\nb@example.org\nc@example.org",
        $this->user,
    );

    expect($result['invited'])->toBe(0)
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0])->toBe(__('reviewer.errors.send_limit'))
        ->and(ReviewerInvitation::query()->count())->toBe(1)
        ->and((string) $live->fresh()?->token_hash)->toBe($hashBefore)
        ->and((string) $live->fresh()?->expires_at?->toDateTimeString())->toBe($expiresBefore);

    Mail::assertNothingQueued();
});

it('re-invites by refreshing the live row instead of creating a second link', function () {
    reviewersPage($this->conference)->callAction('invite', data: ['name' => 'Dr Omar Khan', 'email' => 'omar@example.org', 'affiliation' => null]);
    $first = ReviewerInvitation::query()->firstOrFail();

    reviewersPage($this->conference->fresh())
        ->callTableAction('resend', 'invitation:'.$first->getKey())
        ->assertHasNoTableActionErrors();

    expect(ReviewerInvitation::query()->count())->toBe(1)
        ->and($first->fresh()?->token_hash)->not->toBe($first->token_hash);

    Mail::assertQueued(TemplatedMail::class, 2);
});

it('withdraws an invitation and drops it off the list', function () {
    $invitation = ReviewerInvitation::factory()->for($this->conference)->create(['email' => 'omar@example.org']);

    reviewersPage($this->conference)
        ->callTableAction('revoke', 'invitation:'.$invitation->getKey())
        ->assertHasNoTableActionErrors();

    expect($invitation->fresh()?->revoked_at)->not->toBeNull();

    reviewersPage($this->conference->fresh())->assertDontSee('omar@example.org');
});

it('lists accepted reviewers with their state', function () {
    $reviewer = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['name' => 'Dr Omar Khan', 'email' => 'omar@example.org'])->id,
        'affiliation' => 'KFSH',
    ]);

    reviewersPage($this->conference)
        ->assertSee('Dr Omar Khan')
        ->assertSee('omar@example.org')
        ->assertSee('KFSH')
        ->assertSee(ReviewerStatus::Active->getLabel())
        ->assertTableActionVisible('remove', 'reviewer:'.$reviewer->getKey());
});

it('removes a reviewer, deletes their assignments, keeps their reviews and logs it', function () {
    $user = User::factory()->create();
    $reviewer = ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $user->id]);
    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    ReviewAssignment::factory()->for($submission)->create(['reviewer_user_id' => $user->id]);
    $review = Review::factory()->for($submission)->submitted()->create(['reviewer_user_id' => $user->id]);

    reviewersPage($this->conference)
        ->callTableAction('remove', 'reviewer:'.$reviewer->getKey())
        ->assertHasNoTableActionErrors();

    expect($reviewer->fresh()?->status)->toBe(ReviewerStatus::Removed)
        ->and($reviewer->fresh()?->removed_at)->not->toBeNull()
        ->and($user->fresh()?->isActiveReviewer($this->conference))->toBeFalse()
        // The assignment goes: leaving it would make the coverage summary say
        // this abstract has a reviewer when it does not.
        ->and(ReviewAssignment::query()->count())->toBe(0)
        // The review stays: it is the record of what the committee was told.
        ->and(Review::query()->whereKey($review->getKey())->exists())->toBeTrue()
        ->and(Activity::query()->where('description', 'reviewer.removed')->count())->toBe(1);
});

it('invites a removed reviewer again in one click', function () {
    $user = User::factory()->create(['email' => 'omar@example.org', 'name' => 'Dr Omar Khan']);
    $reviewer = ConferenceReviewer::factory()->for($this->conference)->removed()->create(['user_id' => $user->id]);

    reviewersPage($this->conference)
        ->callTableAction('reinvite', 'reviewer:'.$reviewer->getKey())
        ->assertHasNoTableActionErrors();

    expect(ReviewerInvitation::query()->where('email', 'omar@example.org')->count())->toBe(1);

    Mail::assertQueued(TemplatedMail::class);
});

it('refuses to invite somebody who is already an active reviewer', function () {
    $user = User::factory()->create(['email' => 'omar@example.org']);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $user->id]);

    reviewersPage($this->conference)
        ->callAction('invite', data: ['name' => null, 'email' => 'omar@example.org', 'affiliation' => null])
        ->assertNotified();

    expect(ReviewerInvitation::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('is reachable from the conference view and from the generated url', function () {
    get(ConferenceResource::getUrl('reviewers', ['record' => $this->conference]))->assertOk();

    livewire(App\Filament\Organizer\Resources\Conferences\Pages\ViewConference::class, [
        'record' => $this->conference->getRouteKey(),
    ])->assertActionVisible('reviewers');
});

it('does not open the reviewers page of another organization conference', function () {
    $theirs = withoutTenant(fn (): Conference => Conference::factory()->closed()->create());

    // Through the route, not livewire(). Filament's InteractsWithRecord
    // ::resolveRecord() throws ModelNotFoundException when the tenant-scoped
    // query excludes the record (vendor/filament/filament/src/Resources/Pages/
    // Concerns/InteractsWithRecord.php:42-44), and Livewire's test harness
    // rethrows everything except HttpException and AuthorizationException
    // (vendor/livewire/livewire/src/Features/SupportTesting/RequestBroker.php:29),
    // so livewire(...)->assertNotFound() would ERROR rather than assert. Only a
    // real request turns it into the 404. This repo already records the same
    // trap in tests/Feature/Organizer/ConferenceEmailTemplatesTest.php:188-193.
    get(ConferenceResource::getUrl('reviewers', ['record' => $theirs]))->assertNotFound();
});

it('refuses every reviewer action to somebody with no role in this organization', function () {
    $invitation = ReviewerInvitation::factory()->for($this->conference)->create();
    $outsider = User::factory()->create();
    actingAs($outsider);

    expect($outsider->can('create', App\Models\ReviewerInvitation::class))->toBeFalse()
        ->and($outsider->can('update', $invitation))->toBeFalse()
        ->and($outsider->can('delete', $invitation))->toBeFalse();
});
```

- [ ] **Step 2: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ReviewerListTest.php tests/Feature/Organizer/ConferenceReviewersTest.php > /tmp/t.log 2>&1; echo "rc=$?"; head -20 /tmp/t.log
```

Expected: `rc=1`, `Class "App\Support\Reviews\ReviewerList" not found`.

- [ ] **Step 3: The list parser**

`app/Support/Reviews/ReviewerList.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Reviews;

/**
 * Spec 5.4 step 1: "Organizer invites reviewers by name and email (single or
 * pasted list)."
 *
 * The two shapes the spec names are `Name <email>` and a bare `email`. The two
 * it does not name are what a spreadsheet paste actually produces - comma,
 * semicolon or tab separated, in either order - and refusing those would mean
 * an organizer hand-editing forty lines. Whichever half of a separated line
 * looks like an address is the address; the other half is the name.
 *
 * Nothing here touches the database: it is a pure parse with a unit test, and
 * InviteReviewerList decides what to do with the result.
 */
final readonly class ReviewerList
{
    /**
     * The cap on one paste, and the reason there is one.
     *
     * InviteReviewerList loops synchronously: every entry is roughly eight
     * queries plus a rendered template plus an `email_logs` insert plus a queued
     * mailable, all inside ONE Livewire POST. php-fpm gives that request 60
     * seconds (`docker/php.ini` `max_execution_time=60`) and nginx gives up on
     * it at the same point (`docker/nginx.conf` `fastcgi_read_timeout 60s`), and
     * the textarea accepts 20,000 characters - about 2,500 bare addresses. A
     * 400-line paste would 504 halfway through with no report, and the
     * organizer's retry would re-mint tokens that invalidate the links already
     * delivered to the first half. A hundred at a time is a paste that finishes.
     *
     * It is also the outer bound on the bulk-mail primitive this feature is:
     * InviteReviewer additionally meters the actor
     * (`cass.invitations.send_rate_limit`), so the cap bounds one click and the
     * limiter bounds one hour.
     */
    public const MAX_ENTRIES = 100;

    /**
     * @param  list<array{name: string|null, email: string}>  $entries
     * @param  list<string>  $errors  one sentence per unusable line, with its number
     * @param  list<string>  $duplicates  addresses that appeared more than once
     */
    private function __construct(
        public array $entries,
        public array $errors,
        public array $duplicates,
    ) {}

    public static function parse(string $text): self
    {
        $entries = [];
        $errors = [];
        $duplicates = [];
        $seen = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $rawLine) {
            $line = trim($rawLine);
            $number = $index + 1;

            if ($line === '') {
                continue;
            }

            [$name, $email] = self::split($line);

            if ($email === null) {
                $errors[] = __('reviewer.list.bad_line', ['line' => $number, 'text' => $line]);

                continue;
            }

            if (isset($seen[$email])) {
                if (! in_array($email, $duplicates, true)) {
                    $duplicates[] = $email;
                }

                continue;
            }

            $seen[$email] = true;

            // Stop collecting at the cap and say so, rather than handing
            // InviteReviewerList a batch that cannot finish inside one request.
            // The message goes in `errors`, which ConferenceReviewers shows
            // first, so the organizer is told what did NOT happen.
            if (count($entries) >= self::MAX_ENTRIES) {
                $errors[] = __('reviewer.list.too_many', ['max' => self::MAX_ENTRIES]);

                break;
            }

            $entries[] = ['name' => $name, 'email' => $email];
        }

        return new self($entries, $errors, $duplicates);
    }

    /**
     * @return array{0: string|null, 1: string|null} the name and the address, either of which may be null
     */
    private static function split(string $line): array
    {
        // `Name <email>` first, because a display name may itself contain a
        // comma ("Khan, Omar <omar@example.org>").
        if (preg_match('/^(.*?)<\s*([^<>\s]+)\s*>$/', $line, $matches) === 1) {
            return [self::cleanName($matches[1]), self::cleanEmail($matches[2])];
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/[,;\t]+/', $line) ?: []), fn (string $p): bool => $p !== ''));

        if (count($parts) === 1) {
            return [null, self::cleanEmail($parts[0])];
        }

        if (count($parts) === 2) {
            $first = self::cleanEmail($parts[0]);
            $second = self::cleanEmail($parts[1]);

            if ($first !== null && $second === null) {
                return [self::cleanName($parts[1]), $first];
            }

            if ($second !== null && $first === null) {
                return [self::cleanName($parts[0]), $second];
            }
        }

        return [null, null];
    }

    private static function cleanEmail(string $value): ?string
    {
        $value = mb_strtolower(trim($value, " \t\"'<>"));

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : $value;
    }

    private static function cleanName(string $value): ?string
    {
        $value = trim($value, " \t\"'");

        return $value === '' ? null : $value;
    }
}
```

- [ ] **Step 4: The four actions**

`app/Actions/Reviewers/InviteReviewer.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Reviewers;

use App\Actions\Mail\SendTemplatedEmail;
use App\Enums\EmailTemplateKey;
use App\Enums\ReviewerStatus;
use App\Exceptions\MemberChangeRefused;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\ReviewerInvitation;
use App\Models\User;
use App\Support\Tokens\InvitationToken;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Spec 5.4 step 1. Invite, re-invite and resend are all this one method, for
 * the same reason InviteMember is: there is no unique index on
 * (conference_id, email) - a withdrawn invitation keeps its row - so the live
 * row is refreshed with a new token rather than joined by a second one.
 */
class InviteReviewer
{
    public function __construct(private readonly SendTemplatedEmail $sendTemplatedEmail) {}

    /** @return list<string> empty when the invitation may be sent */
    public function blockers(Conference $conference, string $email, User $actor): array
    {
        $reasons = [];
        $email = mb_strtolower(trim($email));

        // Same guarded hop as the policies: Organization soft-deletes while its
        // conferences survive, and User::roleIn() takes a non-nullable
        // Organization - so an unguarded walk is a TypeError 500 where a refusal
        // belongs.
        $organization = $conference->organization;

        if ($organization === null || $actor->roleIn($organization) === null) {
            $reasons[] = __('reviewer.errors.not_allowed');
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $reasons[] = __('reviewer.errors.bad_email');
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null && $conference->reviewers()
            ->where('user_id', $existing->getKey())
            ->where('status', ReviewerStatus::Active->value)
            ->exists()) {
            $reasons[] = __('reviewer.errors.already_reviewing', ['email' => $email]);
        }

        return array_values(array_unique($reasons));
    }

    public function handle(
        Conference $conference,
        string $email,
        ?string $name,
        ?string $affiliation,
        User $actor,
    ): ReviewerInvitation {
        $reasons = $this->blockers($conference, $email, $actor);

        if ($reasons !== []) {
            throw new MemberChangeRefused($reasons);
        }

        $email = mb_strtolower(trim($email));
        $name = $name === null || trim($name) === '' ? null : trim($name);
        $plain = InvitationToken::generate();

        // Meter the actor, not the conference. ReviewerInvitationPolicy::create()
        // admits every organization member down to a plain `member` (spec
        // section 4 gives "Invite reviewers, assign, decide" to all of them),
        // and the pasted list turns one click into a hundred organization-signed
        // emails to addresses of the sender's choosing. Without a limiter that
        // is a spam relay wearing the platform's sending reputation. A hundred a
        // click and 200 an hour is more than any real conference needs and far
        // less than a relay wants.
        //
        // It is consulted HERE, before the row below is touched, and that
        // ordering is load-bearing. This method re-invites by refreshing the
        // live row in place - new `token_hash`, new `expires_at` - so a limiter
        // that threw *after* the forceFill would have already invalidated a
        // link the reviewer was emailed an hour ago, for a message that then
        // never leaves; on a first invitation it would instead leave a phantom
        // "Invited" row nobody ever received a mail for. Refusing first leaves
        // the row byte-for-byte as it was, which is what Step 1's send-limit
        // test asserts with its row count and its two $live expectations.
        $key = 'invitation-send:'.$actor->getKey();

        if (RateLimiter::tooManyAttempts($key, (int) config('cass.invitations.send_rate_limit'))) {
            throw new MemberChangeRefused([__('reviewer.errors.send_limit')]);
        }

        RateLimiter::hit($key, 3600);

        $invitation = $conference->reviewerInvitations()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->latest('id')
            ->first() ?? new ReviewerInvitation;

        $invitation->forceFill([
            'conference_id' => $conference->getKey(),
            'email' => $email,
            // Never blanked by a later bare invitation: the organizer typed
            // these once and a pasted list without a name must not erase them.
            'name' => $name ?? $invitation->name,
            'affiliation' => $affiliation ?? $invitation->affiliation,
            'token_hash' => InvitationToken::hash($plain),
            'invited_by' => $actor->getKey(),
            'expires_at' => now()->addDays((int) config('cass.invitations.expiry_days')),
            'accepted_at' => null,
            'accepted_by' => null,
            'revoked_at' => null,
        ])->save();

        $this->sendTemplatedEmail->handle(
            EmailTemplateKey::ReviewerInvitation,
            $conference,
            $email,
            self::placeholderValues(
                $conference,
                $invitation->invitedName() ?? $email,
                route('invitation.accept', ['token' => $plain]),
            ),
        );

        activity()
            ->performedOn($invitation)
            ->causedBy($actor)
            ->withProperties(['email' => $email, 'conference_id' => $conference->getKey()])
            ->log('reviewer.invited');

        return $invitation;
    }

    /**
     * The spec 5.9 placeholder bag for all three reviewer keys, in one place so
     * Task 10's reminders cannot drift from Task 4's invitation - the same
     * pattern as SubmitAbstract::placeholderValues().
     *
     * `{{review_link}}` is whatever the caller passes: the accept URL for an
     * invitation, the reviewer's queue for a reminder (spec 5.4 steps 1 and 5).
     *
     * @return array<string, string|null>
     */
    public static function placeholderValues(Conference $conference, string $reviewerName, string $reviewLink): array
    {
        $deadline = $conference->reviewDeadlineInConferenceTimezone();

        return [
            'reviewer_name' => $reviewerName,
            'conference' => (string) $conference->name,
            'organization' => (string) $conference->organization->name,
            'deadline' => $deadline === null
                ? __('reviewer.mail.no_deadline')
                : $deadline->format('j F Y, H:i').' ('.$conference->timezone.')',
            'review_link' => $reviewLink,
        ];
    }
}
```

`app/Actions/Reviewers/InviteReviewerList.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Reviewers;

use App\Exceptions\MemberChangeRefused;
use App\Models\Conference;
use App\Models\User;
use App\Support\Reviews\ReviewerList;

/**
 * The pasted-list half of spec 5.4 step 1. Every line is reported on: invited,
 * skipped (already reviewing, or a duplicate inside the paste) or unusable.
 * Nothing is silently dropped, because a list of forty addresses with one typo
 * is exactly the case where silence costs a reviewer.
 */
class InviteReviewerList
{
    public function __construct(private readonly InviteReviewer $inviteReviewer) {}

    /**
     * @return array{invited: int, skipped: list<string>, errors: list<string>}
     */
    public function handle(Conference $conference, string $text, User $actor): array
    {
        $parsed = ReviewerList::parse($text);

        $invited = 0;
        $skipped = [];
        $errors = $parsed->errors;

        foreach ($parsed->duplicates as $duplicate) {
            $skipped[] = __('reviewer.list.duplicate', ['email' => $duplicate]);
        }

        foreach ($parsed->entries as $entry) {
            try {
                $this->inviteReviewer->handle($conference, $entry['email'], $entry['name'], null, $actor);
                $invited++;
            } catch (MemberChangeRefused $exception) {
                // The hourly send limit is about the whole run, not this line:
                // once it trips, every remaining entry would fail for the same
                // reason and print the same sentence forty times. Report it once
                // and stop, so the count of what WAS sent stays honest.
                if ($exception->getMessage() === __('reviewer.errors.send_limit')) {
                    $errors[] = $exception->getMessage();

                    break;
                }

                // One bad entry must not abandon the other thirty-nine.
                $skipped[] = $exception->getMessage();
            }
        }

        return ['invited' => $invited, 'skipped' => $skipped, 'errors' => $errors];
    }
}
```

`app/Actions/Reviewers/RevokeReviewerInvitation.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Reviewers;

use App\Models\ReviewerInvitation;
use App\Models\User;

class RevokeReviewerInvitation
{
    /** The row survives so /invite/{token} can explain rather than 404 (Task 2). */
    public function handle(ReviewerInvitation $invitation, User $actor): ReviewerInvitation
    {
        if ($invitation->revoked_at === null) {
            $invitation->forceFill(['revoked_at' => now()])->save();

            activity()
                ->performedOn($invitation)
                ->causedBy($actor)
                ->withProperties(['email' => $invitation->email])
                ->log('reviewer.invitation_revoked');
        }

        return $invitation;
    }
}
```

`app/Actions/Reviewers/RemoveConferenceReviewer.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Reviewers;

use App\Enums\ReviewerStatus;
use App\Models\ConferenceReviewer;
use App\Models\ReviewAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Spec section 9 names reviewer removals as an audited event.
 *
 * Assignments go, reviews stay. A removed reviewer is not going to review, so
 * an assignment left behind would make Task 8's coverage summary and Task 9's
 * balance both read "this abstract has two reviewers" when it has one. A
 * submitted review is the record of what the committee was told and survives
 * its author's removal; a draft survives because re-inviting the same person is
 * one click and their work should still be there.
 */
class RemoveConferenceReviewer
{
    public function handle(ConferenceReviewer $reviewer, User $actor): ConferenceReviewer
    {
        return DB::transaction(function () use ($reviewer, $actor): ConferenceReviewer {
            $dropped = ReviewAssignment::query()
                ->where('reviewer_user_id', $reviewer->user_id)
                ->whereHas(
                    'submission',
                    fn (Builder $query): Builder => $query->where('conference_id', $reviewer->conference_id),
                )
                ->delete();

            $reviewer->forceFill([
                'status' => ReviewerStatus::Removed,
                'removed_at' => now(),
            ])->save();

            activity()
                ->performedOn($reviewer)
                ->causedBy($actor)
                ->withProperties([
                    'conference_id' => $reviewer->conference_id,
                    'user_id' => $reviewer->user_id,
                    'assignments_removed' => $dropped,
                ])
                ->log('reviewer.removed');

            return $reviewer;
        });
    }
}
```

- [ ] **Step 5: The page**

`app/Filament/Organizer/Resources/Conferences/Pages/ConferenceReviewers.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Actions\Reviewers\InviteReviewer;
use App\Actions\Reviewers\InviteReviewerList;
use App\Actions\Reviewers\RemoveConferenceReviewer;
use App\Actions\Reviewers\RevokeReviewerInvitation;
use App\Enums\InvitationStatus;
use App\Enums\ReviewerStatus;
use App\Exceptions\MemberChangeRefused;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\ReviewerInvitation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Spec 5.4 steps 1 and 2 from the organizer's side. One array-backed table
 * (fact 15) listing reviewers and open invitations together; the `__key` is
 * `reviewer:{id}` or `invitation:{id}`.
 */
class ConferenceReviewers extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ConferenceResource::class;

    protected string $view = 'filament.organizer.resources.conferences.pages.reviewers';

    public function mount(int|string $record): void
    {
        // resolveRecord() runs through ConferenceResource::getEloquentQuery(),
        // which carries the panel's tenancy global scope, so another
        // organization's conference is already a 404. The policy check is
        // defence in depth, exactly as on ConferenceShortLink.
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 404);
    }

    public function getTitle(): string
    {
        return __('reviewer.page.title');
    }

    public function getSubheading(): ?string
    {
        return __('reviewer.page.subheading');
    }

    public function getConference(): Conference
    {
        /** @var Conference $conference */
        $conference = $this->getRecord();

        return $conference;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->rows())
            ->columns([
                TextColumn::make('name')->label(__('reviewer.columns.name'))->weight('semibold')
                    ->description(fn (array $record): string => $record['email']),
                TextColumn::make('affiliation')->label(__('reviewer.columns.affiliation'))->placeholder('-'),
                TextColumn::make('state')->label(__('reviewer.columns.state'))->badge()
                    ->color(fn (array $record): string => $record['state_color']),
                TextColumn::make('since')->label(__('reviewer.columns.since')),
            ])
            ->recordActions([
                $this->removeAction(),
                $this->reinviteAction(),
                $this->resendAction(),
                $this->revokeAction(),
            ])
            ->paginated(false);
    }

    /**
     * @return Collection<string, array{kind: string, id: int, name: string, email: string,
     *     affiliation: string, state: string, state_color: string, since: string, is_active: bool}>
     */
    private function rows(): Collection
    {
        $conference = $this->getConference();

        /** @var Collection<string, array<string, mixed>> $reviewers */
        $reviewers = $conference->reviewers()->with('user')->get()
            ->mapWithKeys(function (ConferenceReviewer $reviewer): array {
                return ['reviewer:'.$reviewer->getKey() => [
                    'kind' => 'reviewer',
                    'id' => (int) $reviewer->getKey(),
                    'name' => (string) ($reviewer->user?->name ?? '-'),
                    'email' => (string) ($reviewer->user?->email ?? '-'),
                    'affiliation' => (string) ($reviewer->affiliation ?? '-'),
                    'state' => $reviewer->status->getLabel(),
                    'state_color' => $reviewer->status->getColor(),
                    'since' => $reviewer->accepted_at?->format('j M Y') ?? '-',
                    'is_active' => $reviewer->isActive(),
                ]];
            });

        /** @var Collection<string, array<string, mixed>> $invitations */
        $invitations = $conference->reviewerInvitations()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(function (ReviewerInvitation $invitation): array {
                $status = $invitation->invitationStatus();

                return ['invitation:'.$invitation->getKey() => [
                    'kind' => 'invitation',
                    'id' => (int) $invitation->getKey(),
                    'name' => (string) ($invitation->invitedName() ?? $invitation->email),
                    'email' => (string) $invitation->email,
                    'affiliation' => (string) ($invitation->affiliation ?? '-'),
                    'state' => $status === InvitationStatus::Expired
                        ? __('reviewer.state.expired')
                        : __('reviewer.state.invited'),
                    'state_color' => $status->getColor(),
                    'since' => '-',
                    'is_active' => false,
                ]];
            });

        /** @var Collection<string, array{kind: string, id: int, name: string, email: string, affiliation: string, state: string, state_color: string, since: string, is_active: bool}> $rows */
        $rows = $reviewers->merge($invitations);

        return $rows;
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label(__('reviewer.actions.invite'))
                ->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn (): bool => Gate::allows('create', ReviewerInvitation::class))
                ->modalHeading(__('reviewer.actions.invite_heading'))
                ->schema([
                    TextInput::make('name')->label(__('reviewer.fields.name'))->maxLength(255),
                    TextInput::make('email')->label(__('reviewer.fields.email'))->email()->required()->maxLength(255),
                    TextInput::make('affiliation')->label(__('reviewer.fields.affiliation'))->maxLength(255)
                        ->helperText(__('reviewer.fields.affiliation_help')),
                ])
                ->action(function (array $data, InviteReviewer $invite): void {
                    Gate::authorize('create', ReviewerInvitation::class);

                    try {
                        $invite->handle(
                            $this->getConference(),
                            (string) $data['email'],
                            $data['name'] === null ? null : (string) $data['name'],
                            $data['affiliation'] === null ? null : (string) $data['affiliation'],
                            $this->actor(),
                        );
                    } catch (MemberChangeRefused $exception) {
                        $this->refuse($exception->getMessage());

                        return;
                    }

                    Notification::make()->success()
                        ->title(__('reviewer.notices.invited'))
                        ->body(__('reviewer.notices.invited_body', ['email' => e((string) $data['email'])]))
                        ->send();
                }),

            Action::make('inviteList')
                ->label(__('reviewer.actions.invite_list'))
                ->icon(Heroicon::OutlinedUsers)
                ->color('gray')
                ->visible(fn (): bool => Gate::allows('create', ReviewerInvitation::class))
                ->modalHeading(__('reviewer.actions.invite_list_heading'))
                ->modalDescription(__('reviewer.actions.invite_list_description'))
                ->schema([
                    Textarea::make('list')->label(__('reviewer.fields.list'))->rows(12)->required()
                        ->maxLength(20000)
                        ->helperText(__('reviewer.fields.list_help')),
                ])
                ->action(function (array $data, InviteReviewerList $invite): void {
                    Gate::authorize('create', ReviewerInvitation::class);

                    $result = $invite->handle($this->getConference(), (string) $data['list'], $this->actor());

                    $lines = array_merge($result['errors'], $result['skipped']);

                    Notification::make()
                        ->status($result['invited'] > 0 ? 'success' : 'warning')
                        ->title(__('reviewer.notices.list_done', ['count' => $result['invited']]))
                        // Every line the organizer typed comes back escaped: it
                        // is their own text, but a Filament notification body is
                        // sanitised HTML that keeps `style` and `class`
                        // (Plan 2 fact 15).
                        ->body($lines === [] ? null : e(implode(' ', array_slice($lines, 0, 10))))
                        // duration(), not persistent($condition): persistent()
                        // takes no arguments in Filament 5.8.1
                        // (notifications/src/Concerns/HasDuration.php:30), so an
                        // argument would be silently discarded and a clean run
                        // would leave a notification to dismiss by hand.
                        // 'persistent' is the duration sentinel, which makes
                        // duration() the conditional form.
                        ->duration($lines === [] ? 6000 : 'persistent')
                        ->send();
                }),

            Action::make('backToConference')
                ->label(__('reviewer.actions.back'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(fn (): string => ConferenceResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    private function removeAction(): Action
    {
        return Action::make('remove')
            ->label(__('reviewer.actions.remove'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => __('reviewer.actions.remove_heading', ['name' => $record['name']]))
            ->modalDescription(__('reviewer.actions.remove_description'))
            ->visible(fn (array $record): bool => $record['kind'] === 'reviewer'
                && $record['is_active']
                && Gate::allows('update', $this->reviewer($record['id'])))
            ->action(function (array $record, RemoveConferenceReviewer $remove): void {
                $reviewer = $this->reviewer($record['id']);

                Gate::authorize('update', $reviewer);

                $remove->handle($reviewer, $this->actor());

                Notification::make()->success()->title(__('reviewer.notices.removed'))->send();
            });
    }

    private function reinviteAction(): Action
    {
        return Action::make('reinvite')
            ->label(__('reviewer.actions.reinvite'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('reviewer.actions.reinvite_heading'))
            ->visible(fn (array $record): bool => $record['kind'] === 'reviewer'
                && ! $record['is_active']
                && Gate::allows('create', ReviewerInvitation::class))
            ->action(function (array $record, InviteReviewer $invite): void {
                $reviewer = $this->reviewer($record['id']);

                Gate::authorize('create', ReviewerInvitation::class);

                try {
                    $invite->handle(
                        $this->getConference(),
                        (string) $reviewer->user?->email,
                        $reviewer->user?->name === null ? null : (string) $reviewer->user->name,
                        $reviewer->affiliation === null ? null : (string) $reviewer->affiliation,
                        $this->actor(),
                    );
                } catch (MemberChangeRefused $exception) {
                    $this->refuse($exception->getMessage());

                    return;
                }

                Notification::make()->success()->title(__('reviewer.notices.invited'))->send();
            });
    }

    private function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('reviewer.actions.resend'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('reviewer.actions.resend_heading'))
            ->modalDescription(__('reviewer.actions.resend_description'))
            ->visible(fn (array $record): bool => $record['kind'] === 'invitation'
                && Gate::allows('create', ReviewerInvitation::class))
            ->action(function (array $record, InviteReviewer $invite): void {
                $invitation = $this->invitation($record['id']);

                Gate::authorize('update', $invitation);

                try {
                    $invite->handle(
                        $this->getConference(),
                        (string) $invitation->email,
                        $invitation->name === null ? null : (string) $invitation->name,
                        $invitation->affiliation === null ? null : (string) $invitation->affiliation,
                        $this->actor(),
                    );
                } catch (MemberChangeRefused $exception) {
                    $this->refuse($exception->getMessage());

                    return;
                }

                Notification::make()->success()->title(__('reviewer.notices.resent'))->send();
            });
    }

    private function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('reviewer.actions.revoke'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('reviewer.actions.revoke_heading'))
            ->modalDescription(__('reviewer.actions.revoke_description'))
            ->visible(fn (array $record): bool => $record['kind'] === 'invitation'
                && Gate::allows('create', ReviewerInvitation::class))
            ->action(function (array $record, RevokeReviewerInvitation $revoke): void {
                $invitation = $this->invitation($record['id']);

                Gate::authorize('delete', $invitation);

                $revoke->handle($invitation, $this->actor());

                Notification::make()->success()->title(__('reviewer.notices.revoked'))->send();
            });
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function reviewer(int $id): ConferenceReviewer
    {
        /** @var ConferenceReviewer $reviewer */
        $reviewer = $this->getConference()->reviewers()->whereKey($id)->firstOrFail();

        return $reviewer;
    }

    private function invitation(int $id): ReviewerInvitation
    {
        /** @var ReviewerInvitation $invitation */
        $invitation = $this->getConference()->reviewerInvitations()->whereKey($id)->firstOrFail();

        return $invitation;
    }

    private function refuse(string $message): void
    {
        Notification::make()->danger()
            ->title(__('reviewer.notices.refused'))
            ->body(e($message))
            ->persistent()
            ->send();
    }
}
```

`resources/views/filament/organizer/resources/conferences/pages/reviewers.blade.php`
```blade
<x-filament-panels::page>
    <div style="display:flex;flex-direction:column;gap:1.5rem">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
```

- [ ] **Step 6: Register the page and the link**

`app/Filament/Organizer/Resources/Conferences/ConferenceResource.php` — add the import and one line to `getPages()`, before `'edit'`:

```php
            'reviewers' => ConferenceReviewers::route('/{record}/reviewers'),
```

`app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php` — add:

```php
    public static function reviewers(): Action
    {
        return Action::make('reviewers')
            ->label(__('reviewer.actions.page_link'))
            ->icon(Heroicon::OutlinedUserGroup)
            ->color('gray')
            ->visible(fn (Conference $record): bool => Gate::allows('view', $record))
            ->url(fn (Conference $record): string => ConferenceResource::getUrl('reviewers', ['record' => $record]));
    }
```

and put it in `all()`:

```php
    /** @return list<Action> */
    public static function all(): array
    {
        return [static::share(), static::emails(), static::reviewers(), static::publish(), static::close(), static::archive()];
    }
```

- [ ] **Step 7: The language keys**

Append to `lang/en/reviewer.php`, inside the top-level array (the `invite` group from Task 2 stays):

```php
    'page' => [
        'title' => 'Reviewers',
        'subheading' => 'Who reviews the abstracts sent to this conference, and who has been invited but has not accepted yet.',
    ],

    'columns' => [
        'name' => 'Reviewer',
        'affiliation' => 'Affiliation',
        'state' => 'Status',
        'since' => 'Reviewing since',
    ],

    'state' => [
        'invited' => 'Invited',
        'expired' => 'Invitation expired',
    ],

    'fields' => [
        'name' => 'Name',
        'email' => 'Email address',
        'affiliation' => 'Affiliation',
        'affiliation_help' => 'Optional. Used to keep a reviewer away from abstracts from their own institution.',
        'list' => 'One reviewer per line',
        'list_help' => 'Either "Dr Omar Khan <omar@example.org>" or just the address. A comma, a semicolon or a tab between a name and an address works too.',
    ],

    'actions' => [
        'page_link' => 'Reviewers',
        'back' => 'Back to the conference',
        'invite' => 'Invite a reviewer',
        'invite_heading' => 'Invite a reviewer',
        'invite_list' => 'Invite a list',
        'invite_list_heading' => 'Invite several reviewers at once',
        'invite_list_description' => 'Paste one reviewer per line. Every line is reported back: invited, already reviewing, or not understood.',
        'remove' => 'Remove',
        'remove_heading' => 'Remove :name from this conference?',
        'remove_description' => 'They lose access to these abstracts straight away, and any abstract assigned to them becomes unassigned. Reviews they have already written are kept.',
        'reinvite' => 'Invite again',
        'reinvite_heading' => 'Invite this reviewer again?',
        'resend' => 'Resend',
        'resend_heading' => 'Send the invitation again?',
        'resend_description' => 'A new link is emailed. **Any link they already have stops working.**',
        'revoke' => 'Withdraw',
        'revoke_heading' => 'Withdraw this invitation?',
        'revoke_description' => 'The link stops working. If they follow it they are told the invitation was withdrawn.',
    ],

    'notices' => [
        'invited' => 'Invitation sent',
        'invited_body' => 'A link is on its way to :email.',
        'list_done' => ':count invitation(s) sent',
        'removed' => 'Reviewer removed',
        'resent' => 'Invitation sent again',
        'revoked' => 'Invitation withdrawn',
        'refused' => 'Nothing changed',
    ],

    'list' => [
        'bad_line' => 'Line :line could not be read: ":text"',
        'duplicate' => ':email appears more than once; it was invited once.',
        'too_many' => 'Only the first :max reviewers on the list were invited. Paste the rest as a second list.',
    ],

    'errors' => [
        'not_allowed' => 'Only a member of this organization can invite reviewers.',
        'bad_email' => 'That does not look like an email address.',
        'already_reviewing' => ':email is already reviewing this conference.',
        'send_limit' => 'Too many invitations have been sent from this account in the last hour. Try again later.',
    ],

    'mail' => [
        'no_deadline' => 'a date the organizers will confirm',
    ],
```

Finally, widen the subject redaction in the same commit that introduces the second bearer-token URL shape. `app/Actions/Mail/SendTemplatedEmail.php:52` currently strips only `/s/{64}` — Plan 3's author status link — from a rendered subject before it is stored in `email_logs` and put on the wire, and its own docblock calls that "the belt to that braces, for any path that does not go through the editor at all". Plan 4 adds `/invite/{64}`, a credential of exactly the same weight, which that pattern does not match. Replace the single `preg_replace` with:

```php
        // Two bearer-token URL shapes now: Plan 3's author status link and Plan
        // 4's member/reviewer invitation link. Both are credentials, and a
        // Subject header travels in clear text through every relay between here
        // and the recipient - and is stored verbatim in email_logs, which an
        // organizer can read. The editor's subjectPlaceholders() is the braces;
        // this is the belt, for any path that does not go through the editor.
        $subject = (string) preg_replace(
            ['#/s/[A-Za-z0-9]{64}#', '#/invite/[A-Za-z0-9]{64}#'],
            ['/s/[redacted]', '/invite/[redacted]'],
            $rendered->subject,
        );
```

and add a case to the existing mail test (`tests/Feature/Mail/TemplatedMailTest.php`, beside the `/s/` case it already has):

```php
it('redacts an invitation token from a subject as well as a status token', function () {
    $conference = Conference::factory()->published()->create();
    $token = App\Support\Tokens\InvitationToken::generate();

    $conference->emailTemplates()->create([
        'key' => EmailTemplateKey::ReviewerInvitation->value,
        'subject' => 'Review for us: '.route('invitation.accept', ['token' => $token]),
        'body' => 'Body.',
        'is_active' => true,
    ]);

    app(SendTemplatedEmail::class)->handle(
        EmailTemplateKey::ReviewerInvitation,
        $conference,
        'reviewer@example.org',
        ['reviewer_name' => 'Dr Omar Khan', 'review_link' => 'https://example.test/invite/'.$token],
    );

    $log = EmailLog::query()->firstOrFail();

    expect($log->subject)->toContain('/invite/[redacted]')
        ->and($log->subject)->not->toContain($token);
});
```

- [ ] **Step 8: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ReviewerListTest.php tests/Feature/Organizer/ConferenceReviewersTest.php tests/Feature/Mail/TemplatedMailTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -6 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`; about 20 in the first two files plus `TemplatedMailTest`'s existing cases and its new one, and `baseline + 80` in the second. Four cases are new to this revision of the plan: `ReviewerListTest`'s `it('stops at the entry cap and says so')`; `ConferenceReviewersTest`'s `it('caps one paste at a hundred and says what it did not send')` and `it('stops the whole run once the hourly send limit trips, and says so once')`; and `TemplatedMailTest`'s `it('redacts an invitation token from a subject as well as a status token')`. The send-limit case is also the one that pins the *ordering* inside `InviteReviewer::handle()`: its `ReviewerInvitation::query()->count()` and the two `$live` expectations fail if the limiter is ever moved back below the `forceFill(...)->save()`, because a refused run would then have rewritten a live token and minted phantom rows. The cross-tenant case now goes through `get(...)` only — `livewire(ConferenceReviewers::class, …)->assertNotFound()` would error rather than assert, for the reason written above it.

- [ ] **Step 9: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(organizer): invite reviewers to a conference, singly or as a pasted list

Sends the first of Plan 3's three reviewer template keys. Removing a reviewer
deletes their assignments and keeps their reviews: an assignment left behind
would make the coverage summary describe a reviewer who is gone.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 5: The reviewer panel, its login, its dashboard and the two switch links

The `/review/...` row of spec section 6 and the sentence under that table: "A user with both organizer and reviewer roles sees a switch link in the panel header."

**Who may open `/review`.** `User::canAccessPanel('reviewer')` is `$this->isActiveReviewer()` — an active reviewer of **at least one** conference, in any state. Deliberately not "has something to review right now": a reviewer who accepted an invitation before the organizer moved the conference to Reviewing must still be able to log in, set up MFA and see that there is nothing waiting. The shape follows the **organizer** branch and not the admin one: it does not test `hasVerifiedEmail()`, so an unverified reviewer is redirected to the panel's verification prompt rather than meeting a bare 403 (`tests/Feature/Organizer/PanelAccessTest.php` already pins that distinction for the organizer panel, and this task pins it for the reviewer panel).

**The switch links.** One builder, `App\Support\Panels\PanelSwitch`, returning two `Filament\Actions\Action`s that are handed to each panel's `userMenuItems()`. `Action` rather than `MenuItem` because `MenuItem::toAction()` derives the action's *name* from its label (fact 9), and these two are addressed by name in the tests. Both are `visible()`-gated on the user really having the other role, and both hide themselves if the target URL comes back null (fact 10) — a reviewer with no organization gets no link rather than a dead one. The organizer URL is built from `App\Filament\Organizer\Pages\Dashboard::getUrl(panel: 'organizer', tenant: …)` rather than `Panel::getUrl()`, because the page URL is never null and takes the tenant explicitly instead of guessing it from the current user.

**What the dashboard shows in this task, and what it does not.** A list of the conferences this person reviews, with the organization, the review deadline in the conference's own timezone, and whether reviewing has started. **No link into a queue** — the queue is Task 6, and a link to a page that does not exist is the kind of placeholder this plan does not ship. Task 6 adds the link; Task 11 adds the progress numbers. Three small edits to one view, each in the task that makes them true.

**Files:**
- Create: `app/Providers/Filament/ReviewerPanelProvider.php`, `app/Filament/Reviewer/Pages/Dashboard.php`, `resources/views/filament/reviewer/pages/dashboard.blade.php`, `app/Support/Panels/PanelSwitch.php`
- Modify: `bootstrap/providers.php`, `app/Models/User.php`, `app/Providers/Filament/OrganizerPanelProvider.php`, `tests/Pest.php`, `lang/en/reviewer.php`
- Test: `tests/Feature/Reviewer/PanelAccessTest.php`

- [ ] **Step 1: Fix the existing panel helper, then add its twin**

`tests/Pest.php` — **replace** the existing `bootOrganizerPanel()` and add `bootReviewerPanel()` beside it. Both boot the panel *object* rather than calling `Filament::bootCurrentPanel()`:

```php
function bootOrganizerPanel(Organization $organization): void
{
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organization);
    // Not bootCurrentPanel(): FilamentManager guards it with a one-shot
    // $isCurrentPanelBooted flag it never resets
    // (vendor/filament/filament/src/FilamentManager.php:65-73), and
    // setCurrentPanel() does not reset it either (:885-892). So in a test that
    // has already booted ANOTHER panel - which is now possible, because
    // bootReviewerPanel() exists - this call would return immediately and
    // silently skip registering the organizer panel's tenancy global scope and
    // its tenant-associating `creating` observer, the two things
    // withoutTenant() exists to work around. The organizer half of such a test
    // would then assert against unscoped queries and pass for the wrong reason.
    //
    // Re-booting the same panel is safe: registerTenancyModelGlobalScope() is
    // guarded by hasGlobalScope()
    // (Resources/Resource/Concerns/BelongsToTenant.php:139), and the
    // creating/created listeners return early unless this panel is the current
    // one.
    Filament::getPanel('organizer')->boot();
}

/**
 * Put the request into the reviewer panel, the way that panel's middleware does
 * in a real request. There is no tenant: the reviewer panel is not
 * tenant-scoped, and a tenant left over from an earlier bootOrganizerPanel()
 * call in the same test would make Filament's tenancy global scope fire on
 * models this panel reads without one.
 */
function bootReviewerPanel(): void
{
    Filament::setCurrentPanel('reviewer');
    Filament::setTenant(null, isQuiet: true);
    Filament::getPanel('reviewer')->boot();
}
```

with `use App\Models\Organization;` already imported by the existing helper. The only test in this plan that boots both panels in one case is Task 6's `ReviewQueueTest`, `it('blinds nothing for the organizer, including the csv export')` — whose `beforeEach` boots the reviewer panel and which then calls `bootOrganizerPanel($this->organization)`. That case is precisely why the helper cannot go through `bootCurrentPanel()`, and it is the one to watch if this change is skipped.

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Reviewer/PanelAccessTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Reviewer\Pages\Dashboard;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->conference = Conference::factory()->for($this->organization)->closed()->create([
        'name' => 'Alpha Annual Meeting',
        'review_deadline' => now()->addMonth(),
    ]);
    $this->reviewer = User::factory()->create(['name' => 'Dr Omar Khan']);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->reviewer->id]);
});

it('registers a third panel at /review with its own login', function () {
    get('/review')->assertRedirect('/review/login');
    get('/review/login')->assertOk();

    expect(Filament::getPanel('reviewer')->getId())->toBe('reviewer')
        ->and(Filament::getPanel('reviewer')->getPath())->toBe('review')
        ->and(Filament::getPanel('reviewer')->hasTenancy())->toBeFalse()
        ->and(route('filament.reviewer.auth.login'))->toEndWith('/review/login');
});

it('lets an active reviewer in', function () {
    actingAs($this->reviewer)->get('/review')->assertOk()->assertSee('Alpha Annual Meeting');
});

it('keeps everybody who is not an active reviewer out', function () {
    $stranger = User::factory()->create();
    $organizer = User::factory()->create();
    $this->organization->addMember($organizer, OrganizationRole::Owner);

    actingAs($stranger)->get('/review')->assertForbidden();
    // An organizer is not automatically a reviewer: spec section 4 gives them
    // "Invite reviewers, assign, decide" and not "Submit reviews".
    actingAs($organizer)->get('/review')->assertForbidden();
});

it('keeps a removed reviewer out', function () {
    ConferenceReviewer::query()->where('user_id', $this->reviewer->id)->firstOrFail()
        ->forceFill(['status' => App\Enums\ReviewerStatus::Removed, 'removed_at' => now()])->save();

    actingAs($this->reviewer->fresh())->get('/review')->assertForbidden();

    expect($this->reviewer->fresh()?->isActiveReviewer())->toBeFalse();
});

it('sends an unverified reviewer to the prompt rather than a bare 403', function () {
    $unverified = User::factory()->unverified()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $unverified->id]);

    actingAs($unverified)->get('/review')->assertRedirect('/review/email-verification/prompt');
});

it('lists the conferences this reviewer reviews and nobody else\'s', function () {
    $otherConference = Conference::factory()->closed()->create(['name' => 'Somebody Else Meeting']);
    ConferenceReviewer::factory()->for($otherConference)->create();

    actingAs($this->reviewer);
    bootReviewerPanel();

    livewire(Dashboard::class)
        ->assertOk()
        ->assertSee('Alpha Annual Meeting')
        ->assertSee('Alpha Society')
        ->assertDontSee('Somebody Else Meeting');
});

it('shows the review deadline in the conference timezone', function () {
    $this->conference->forceFill([
        'timezone' => 'Asia/Riyadh',
        'review_deadline' => Carbon\Carbon::parse('2026-11-03 20:59:00', 'UTC'),
    ])->save();

    actingAs($this->reviewer);
    bootReviewerPanel();

    // 20:59 UTC is 23:59 in Riyadh. Printing the UTC hour next to the words
    // "Asia/Riyadh" is the bug this asserts against (spec section 10).
    livewire(Dashboard::class)->assertSee('3 November 2026, 23:59');
});

it('offers the organizer a link to the reviewer panel only when they review something', function () {
    $both = User::factory()->create();
    $this->organization->addMember($both, OrganizationRole::Owner);

    actingAs($both);
    bootOrganizerPanel($this->organization);

    expect(App\Support\Panels\PanelSwitch::toReviewer()->isVisible())->toBeFalse();

    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $both->id]);

    actingAs($both->fresh());
    bootOrganizerPanel($this->organization);

    $action = App\Support\Panels\PanelSwitch::toReviewer();

    expect($action->isVisible())->toBeTrue()
        ->and($action->getUrl())->toEndWith('/review');
});

it('offers the reviewer a link back to their organization only when they belong to one', function () {
    actingAs($this->reviewer);
    bootReviewerPanel();

    expect(App\Support\Panels\PanelSwitch::toOrganizer()->isVisible())->toBeFalse();

    $this->organization->addMember($this->reviewer, OrganizationRole::Member);

    actingAs($this->reviewer->fresh());
    bootReviewerPanel();

    $action = App\Support\Panels\PanelSwitch::toOrganizer();

    expect($action->isVisible())->toBeTrue()
        ->and($action->getUrl())->toContain('/org/'.$this->organization->slug);
});

it('renders both switch links in the panels that own them', function () {
    $this->organization->addMember($this->reviewer, OrganizationRole::Owner);

    actingAs($this->reviewer->fresh())
        ->get('/review')
        ->assertOk()
        ->assertSee(__('reviewer.switch.to_organizer'));

    actingAs($this->reviewer->fresh())
        ->get('/org/'.$this->organization->slug)
        ->assertOk()
        ->assertSee(__('reviewer.switch.to_reviewer'));
});
```

- [ ] **Step 3: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Reviewer/PanelAccessTest.php > /tmp/t.log 2>&1; echo "rc=$?"; head -20 /tmp/t.log
```

Expected: `rc=1`, with `NoSuchPanelException` or a 404 on `/review`.

- [ ] **Step 4: The panel provider**

`app/Providers/Filament/ReviewerPanelProvider.php`
```php
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Reviewer\Pages\Dashboard;
use App\Support\Panels\PanelSwitch;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Spec section 6: `/review/...`, its own login, the same `users` table.
 *
 * **No tenancy.** A reviewer belongs to conferences, not to organizations, and
 * may review for several organizations at once; a tenant in the URL would mean
 * choosing one of them to look at their own queue. What replaces it is
 * App\Support\Reviews\ReviewerScope (Task 6), which is the single definition of
 * what this panel may read, plus a policy on every model.
 *
 * `discoverResources` points at a directory Task 6 creates. That is safe:
 * Panel::discoverComponents() returns early when the directory does not exist
 * (fact 39), so this provider works on its own for the length of this task.
 */
class ReviewerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('reviewer')
            ->path('review')
            ->login()
            ->passwordReset()
            ->emailVerification()
            ->profile()
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ])
            ->brandName('CASS Review')
            ->brandLogo(fn () => view('brand.logo'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('favicon.ico'))
            ->colors([
                'primary' => Color::hex('#176BB8'),
            ])
            ->discoverResources(in: app_path('Filament/Reviewer/Resources'), for: 'App\Filament\Reviewer\Resources')
            ->discoverPages(in: app_path('Filament/Reviewer/Pages'), for: 'App\Filament\Reviewer\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Reviewer/Widgets'), for: 'App\Filament\Reviewer\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            // Spec section 6: a user with both roles sees a switch link in the
            // panel header. Filament normalises a MenuItem into an Action
            // anyway (fact 9), so the Action is built directly and keeps a
            // stable name the tests can address.
            ->userMenuItems([
                PanelSwitch::toOrganizer(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
```

`bootstrap/providers.php`
```php
<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\OrganizerPanelProvider;
use App\Providers\Filament\ReviewerPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    OrganizerPanelProvider::class,
    ReviewerPanelProvider::class,
];
```

- [ ] **Step 5: `canAccessPanel` and the switch links**

`app/Models/User.php` — the `canAccessPanel()` match gains one arm:

```php
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->is_platform_admin && $this->hasVerifiedEmail(),
            // Verification is enforced by the panel's email-verification middleware,
            // which redirects unverified users to the prompt instead of a bare 403.
            'organizer' => $this->organizations()->exists(),
            // Same shape as the organizer arm, and for the same reason. An
            // active reviewer of *any* conference may come in, even before the
            // organizer has started reviewing - there is a dashboard to read
            // and MFA to set up.
            'reviewer' => $this->isActiveReviewer(),
            default => false,
        };
    }
```

`app/Support/Panels/PanelSwitch.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Panels;

use App\Filament\Organizer\Pages\Dashboard as OrganizerDashboard;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;

/**
 * Spec section 6: "A user with both organizer and reviewer roles sees a switch
 * link in the panel header."
 *
 * One definition of each direction, handed to each panel's userMenuItems(), so
 * the two links cannot drift. Both hide themselves when the target URL is null
 * (fact 10) rather than rendering a link that goes nowhere.
 */
final class PanelSwitch
{
    /** Shown in the organizer panel, to somebody who also reviews. */
    public static function toReviewer(): Action
    {
        return Action::make('switch-to-reviewer')
            ->label(__('reviewer.switch.to_reviewer'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->url(fn (): ?string => self::reviewerUrl())
            ->visible(fn (): bool => (self::user()?->isActiveReviewer() ?? false)
                && self::reviewerUrl() !== null);
    }

    /** Shown in the reviewer panel, to somebody who also organizes. */
    public static function toOrganizer(): Action
    {
        return Action::make('switch-to-organizer')
            ->label(__('reviewer.switch.to_organizer'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->url(fn (): ?string => self::organizerUrl())
            ->visible(fn (): bool => self::organizerUrl() !== null);
    }

    private static function reviewerUrl(): ?string
    {
        // isStrict: false so this is null rather than an exception in a request
        // where the reviewer panel is somehow not registered.
        return Filament::getPanel('reviewer', isStrict: false)?->getUrl();
    }

    /**
     * Built from the organizer Dashboard page rather than from
     * Panel::getUrl(): the page URL takes the tenant explicitly and is never
     * null, while Panel::getUrl() resolves the tenant from the current user and
     * returns null when there is not one (fact 10).
     */
    private static function organizerUrl(): ?string
    {
        $organization = self::user()?->organizations()->orderBy('name')->first();

        if (! $organization instanceof Organization) {
            return null;
        }

        return OrganizerDashboard::getUrl(panel: 'organizer', tenant: $organization);
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
```

`app/Providers/Filament/OrganizerPanelProvider.php` — add the import and one builder call after `->widgets([...])`:

```php
            ->userMenuItems([
                PanelSwitch::toReviewer(),
            ])
```

- [ ] **Step 6: The dashboard**

`app/Filament/Reviewer/Pages/Dashboard.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Pages;

use App\Models\Conference;
use App\Models\User;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Spec 5.4 step 3: "Reviewer panel shows conferences, progress, and a queue."
 *
 * This task builds the conference list. Task 6 adds the link into each queue
 * and Task 11 adds the progress numbers, each in the task that makes them true.
 */
class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.reviewer.pages.dashboard';

    public function getTitle(): string
    {
        return __('reviewer.dashboard.title');
    }

    /**
     * Every conference this person is an active reviewer of, in whatever state.
     * A conference that has not reached Reviewing yet is still listed, with a
     * sentence saying so - otherwise a reviewer who accepted an invitation last
     * week sees an empty page and writes to the organizer.
     *
     * The `whereHas` closure parameter is typed because Larastan level 6 runs
     * MissingClosureParameterTypehintRule: "Anonymous function has parameter
     * $query with no type specified" is an error, not a warning, and there is
     * no untyped closure parameter anywhere in app/ to copy. The bare
     * `Builder` with no type variables is the house style and passes - see
     * app/Filament/Organizer/Resources/Submissions/Tables/SubmissionsTable.php:87.
     *
     * @return Collection<int, Conference>
     */
    public function getConferences(): Collection
    {
        $user = $this->reviewer();

        if ($user === null) {
            /** @var Collection<int, Conference> $empty */
            $empty = Conference::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        /** @var Collection<int, Conference> $conferences */
        $conferences = Conference::query()
            ->with('organization')
            ->whereHas('activeReviewers', fn (Builder $query): Builder => $query->where('user_id', $user->getKey()))
            ->orderBy('review_deadline')
            ->orderBy('name')
            ->get();

        return $conferences;
    }

    public function reviewer(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
```

`resources/views/filament/reviewer/pages/dashboard.blade.php`
```blade
<x-filament-panels::page>
    @php($conferences = $this->getConferences())

    @if ($conferences->isEmpty())
        <x-filament::section :heading="__('reviewer.dashboard.empty_heading')">
            <p style="font-size:0.875rem">{{ __('reviewer.dashboard.empty_body') }}</p>
        </x-filament::section>
    @else
        @foreach ($conferences as $conference)
            <x-filament::section :heading="$conference->name">
                <p style="font-size:0.875rem">{{ $conference->organization->name }}</p>

                <p style="margin-top:0.5rem;font-size:0.875rem">
                    @if ($conference->review_deadline)
                        {{ __('reviewer.dashboard.deadline', [
                            'date' => $conference->reviewDeadlineInConferenceTimezone()->format('j F Y, H:i'),
                            'timezone' => $conference->timezone,
                        ]) }}
                    @else
                        {{ __('reviewer.dashboard.no_deadline') }}
                    @endif
                </p>

                @unless ($conference->isOpenToReviewers())
                    <p style="margin-top:0.5rem;font-size:0.875rem;opacity:0.75">
                        {{ __('reviewer.dashboard.not_started') }}
                    </p>
                @endunless
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>
```

- [ ] **Step 7: The language keys**

Append to `lang/en/reviewer.php`:

```php
    'switch' => [
        'to_reviewer' => 'Switch to reviewing',
        'to_organizer' => 'Switch to organizing',
    ],

    // Two of these sentences deliberately do NOT promise an email at the moment
    // reviewing opens. Spec 5.4 step 5 defines reviewer mail as reminders at 7,
    // 3 and 1 days before the review deadline and once after it, and spec 5.9
    // fixes the reviewer key list at three - so there is no "reviewing has
    // started" send, and adding one would fire a *reminder* with a deadline
    // weeks away, outside ReminderSchedule and outside the reviewer_reminders
    // idempotency key. The copy says what the application actually does.
    'dashboard' => [
        'title' => 'Your reviewing',
        'empty_heading' => 'Nothing to review yet',
        'empty_body' => 'When a conference you review opens its abstracts for review, it appears here.',
        'deadline' => 'Reviews are due by :date (:timezone).',
        'no_deadline' => 'The organizers have not set a review deadline yet.',
        'not_started' => 'Reviewing has not started for this conference yet. Check back after the submission deadline; we email reminders as the review deadline approaches.',
    ],
```

- [ ] **Step 8: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Reviewer/PanelAccessTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -6 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`; 10 passed in the first, `baseline + 90` in the second. **Watch the second number:** a third panel adds routes, and if `tests/Feature/Organizer/PanelAccessTest.php` or `tests/Feature/Admin/*` go red, the cause is almost always a route-name collision or the new panel's `userMenuItems()` closure running for a user the organizer panel did not expect.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan route:list --except-vendor | grep -c "filament.reviewer" && \
php artisan route:list --except-vendor | grep "filament.reviewer.auth"
```

Expected: a non-zero count, and `filament.reviewer.auth.login`, `.logout`, `.password-reset.request`, `.password-reset.reset`, `.email-verification.prompt`, `.email-verification.verify`, `.profile`.

- [ ] **Step 9: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(reviewer): a third Filament panel at /review, with its own login and a dashboard

No tenancy: a reviewer belongs to conferences, not organizations, and may review
for several at once. canAccessPanel follows the organizer shape so an unverified
reviewer meets the verification prompt rather than a bare 403.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 6: The reviewer's queue and the read-only abstract, with blind review and file access

Spec 5.4 steps 3 and 4, minus the form (Task 7): both review modes, the abstract as a reviewer sees it, and the "assigned or pool only" cell of spec section 4 — for the submission **and** for its files.

**One definition of what a reviewer may see, in `App\Support\Reviews\ReviewerScope`.** Four separate things ask this question — the queue resource, the review page's record binding, `SubmissionFilePolicy`, and Task 10's reminder command, which has to know who still has outstanding work — and four copies of a `whereHas` is how "assigned or pool only" comes to mean four different things. The scope is one `constrain()` that narrows any `Builder<Submission>`, plus one `allows()` for a single row, and it is unit-tested on its own before any panel reads it.

The rule, written out once:

1. the conference is in `reviewing` or `decided` (`Conference::isOpenToReviewers()`); before that there is nothing to review and after `archived` there is nothing to see;
2. the reviewer has an **active** `conference_reviewers` row for it;
3. the submission's status is `submitted` or `under_review` (`Submission::isReviewable()`) — never a draft and never a withdrawal;
4. and either the conference is `open_pool`, **or** there is a `review_assignments` row for this reviewer.

**Blind review reaches the download, through the signature.** `Conference::hidesAuthorsFrom()` (Task 1) decides; the review page then hides the authors block, the affiliations and the contact phone. That is not enough on its own — Plan 3's backlog already flagged it: an author who names their PDF `al-harbi-kfsh-final.pdf` has deanonymised themselves in the `Content-Disposition` header. So a blind reviewer's link is minted as `URL::temporarySignedRoute('files.download', …, ['ulid' => …, 'blind' => 1])`, and because every parameter is inside the HMAC (fact 19) the recipient cannot strip it. `SubmissionFileController` reads `blind` and serves `attachment-2.pdf` instead. No new route, no session check on a route the author reaches with no session at all.

**Blind review does not blind the organizer, and the CSV export proves it.** Spec section 4 gives every organization member "View submissions and files" with no caveat, and somebody has to answer an author's email. `Conference::hidesAuthorsFrom()` asks *who is looking*, `ExportSubmissionsCsv` is reachable only from the tenant-scoped organizer panel behind `SubmissionPolicy::export()`, and Step 1 adds a test that a blind conference's export still contains the author's address — so that nobody "fixes" this later by blinding the wrong audience.

**Blind review also covers the organizer's own questions.** Hiding the authors block is worth nothing if a conference's `Institution`, `Department` or `Funding source` custom field prints the same answer two sections down the same page, and nothing in `custom_fields` said which answers identify an author until Task 1 added `hide_from_reviewers`. `getCustomFieldLines()` drops every field with that flag when `isBlind()`, and drives off the `custom_fields` rows rather than the answer bag so a deleted or newly hidden field stops printing even though the answer is still in `submissions.custom_field_values`.

**Files:**
- Create: `app/Support/Reviews/ReviewerScope.php`
- Create: `app/Filament/Reviewer/Resources/Submissions/{SubmissionResource.php,Tables/QueueTable.php,Pages/ListSubmissions.php,Pages/ReviewSubmission.php}`, `resources/views/filament/reviewer/resources/submissions/pages/review-submission.blade.php`
- Modify: `app/Policies/SubmissionPolicy.php`, `app/Policies/SubmissionFilePolicy.php`, `app/Models/SubmissionFile.php`, `app/Http/Controllers/Public/SubmissionFileController.php`, `resources/views/filament/reviewer/pages/dashboard.blade.php`, `lang/en/reviewer.php`
- Test: `tests/Unit/ReviewerScopeTest.php`, `tests/Feature/Reviewer/ReviewQueueTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ReviewerScopeTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\ReviewMode;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use App\Support\Reviews\ReviewerScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->reviewer = User::factory()->create();
    $this->conference = Conference::factory()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_mode' => ReviewMode::OpenPool,
    ]);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->reviewer->id]);

    $this->submitted = Submission::factory()->for($this->conference)->submitted()->create();
});

it('shows every submitted abstract in an open pool conference', function () {
    $second = Submission::factory()->for($this->conference)->submitted()->create();

    expect(ReviewerScope::submissions($this->reviewer)->pluck('id')->sort()->values()->all())
        ->toBe([$this->submitted->id, $second->id])
        ->and(ReviewerScope::allows($this->reviewer, $second))->toBeTrue();
});

it('counts an under_review abstract and excludes a draft or a withdrawal', function () {
    $underReview = Submission::factory()->for($this->conference)->submitted()->create();
    $underReview->forceFill(['status' => SubmissionStatus::UnderReview])->save();
    $draft = Submission::factory()->for($this->conference)->create();
    $withdrawn = Submission::factory()->for($this->conference)->withdrawn()->create();

    $visible = ReviewerScope::submissions($this->reviewer)->pluck('id')->all();

    expect($visible)->toContain($underReview->id)
        ->not->toContain($draft->id)
        ->not->toContain($withdrawn->id)
        ->and(ReviewerScope::allows($this->reviewer, $draft))->toBeFalse()
        ->and(ReviewerScope::allows($this->reviewer, $withdrawn))->toBeFalse();
});

it('shows nothing until the conference is in review, and shows it again once decided', function () {
    foreach ([ConferenceStatus::Open, ConferenceStatus::Closed, ConferenceStatus::Draft, ConferenceStatus::Archived] as $status) {
        $this->conference->forceFill(['status' => $status])->save();

        expect(ReviewerScope::submissions($this->reviewer)->count())->toBe(0, $status->value);
    }

    foreach ([ConferenceStatus::Reviewing, ConferenceStatus::Decided] as $status) {
        $this->conference->forceFill(['status' => $status])->save();

        expect(ReviewerScope::submissions($this->reviewer)->count())->toBe(1, $status->value);
    }
});

it('shows only the assignments in an assigned conference', function () {
    $this->conference->forceFill(['review_mode' => ReviewMode::Assigned])->save();
    $mine = Submission::factory()->for($this->conference)->submitted()->create();
    ReviewAssignment::factory()->for($mine)->create(['reviewer_user_id' => $this->reviewer->id]);

    expect(ReviewerScope::submissions($this->reviewer)->pluck('id')->all())->toBe([$mine->id])
        ->and(ReviewerScope::allows($this->reviewer, $this->submitted))->toBeFalse()
        ->and(ReviewerScope::allows($this->reviewer, $mine))->toBeTrue();
});

it('shows nothing to a removed reviewer, even in an open pool', function () {
    ConferenceReviewer::query()->where('user_id', $this->reviewer->id)->firstOrFail()
        ->forceFill(['status' => App\Enums\ReviewerStatus::Removed, 'removed_at' => now()])->save();

    expect(ReviewerScope::submissions($this->reviewer)->count())->toBe(0)
        ->and(ReviewerScope::allows($this->reviewer, $this->submitted))->toBeFalse();
});

it('shows nothing from a conference this person does not review', function () {
    $other = Conference::factory()->create(['status' => ConferenceStatus::Reviewing]);
    $theirs = Submission::factory()->for($other)->submitted()->create();

    expect(ReviewerScope::allows($this->reviewer, $theirs))->toBeFalse()
        ->and(ReviewerScope::submissions($this->reviewer)->pluck('id')->all())->not->toContain($theirs->id);
});

it('narrows to one conference when asked', function () {
    $second = Conference::factory()->create(['status' => ConferenceStatus::Reviewing]);
    ConferenceReviewer::factory()->for($second)->create(['user_id' => $this->reviewer->id]);
    Submission::factory()->for($second)->submitted()->create();

    expect(ReviewerScope::submissions($this->reviewer)->count())->toBe(2)
        ->and(ReviewerScope::submissions($this->reviewer, $this->conference)->count())->toBe(1);
});

it('lists the conferences a reviewer has work in', function () {
    $quiet = Conference::factory()->create(['status' => ConferenceStatus::Reviewing]);
    ConferenceReviewer::factory()->for($quiet)->create(['user_id' => $this->reviewer->id]);

    expect(ReviewerScope::conferences($this->reviewer)->pluck('id')->all())
        ->toContain($this->conference->id)
        ->toContain($quiet->id);
});
```

`tests/Feature/Reviewer/ReviewQueueTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\CustomFieldType;
use App\Enums\OrganizationRole;
use App\Enums\ReviewMode;
use App\Filament\Organizer\Resources\Submissions\Pages\ListSubmissions as OrganizerList;
use App\Filament\Reviewer\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Reviewer\Resources\Submissions\Pages\ReviewSubmission;
use App\Filament\Reviewer\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->conference = Conference::factory()->for($this->organization)->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
        'review_mode' => ReviewMode::OpenPool,
        'blind_review' => true,
        'review_deadline' => now()->addMonth(),
        'submission_opens_at' => now()->subMonths(2),
        'submission_deadline' => now()->subWeek(),
    ]);

    $this->reviewer = User::factory()->create(['name' => 'Dr Omar Khan']);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->reviewer->id]);

    $this->submission = Submission::factory()->for($this->conference)->submitted()
        ->withCorrespondingAuthor('sara@example.org', 'Dr Sara Al-Harbi')
        ->create(['title' => 'Early mobilisation after cardiac surgery', 'contact_phone' => '+966500000000']);
    $this->submission->forceFill(['reference' => 'AAM26-017'])->save();

    actingAs($this->reviewer);
    bootReviewerPanel();
});

it('lists the pool and offers a way into each abstract', function () {
    $track = Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);
    $this->submission->forceFill(['track_id' => $track->id])->save();

    livewire(ListSubmissions::class)
        ->assertCanSeeTableRecords([$this->submission])
        ->assertCanRenderTableColumn('reference')
        ->assertCanRenderTableColumn('title')
        ->assertSee('AAM26-017')
        ->assertSee('Early mobilisation after cardiac surgery')
        ->assertSee('Neurocritical care')
        ->assertTableActionVisible('review', $this->submission);
});

it('never shows an author name in the queue of a blind conference', function () {
    livewire(ListSubmissions::class)
        ->assertDontSee('Dr Sara Al-Harbi')
        ->assertDontSee('sara@example.org');
});

it('shows only assignments in an assigned conference', function () {
    $this->conference->forceFill(['review_mode' => ReviewMode::Assigned])->save();
    $mine = Submission::factory()->for($this->conference)->submitted()->create(['title' => 'Mine to review']);
    ReviewAssignment::factory()->for($mine)->create(['reviewer_user_id' => $this->reviewer->id]);

    livewire(ListSubmissions::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$this->submission]);
});

it('does not list another conference abstracts', function () {
    $theirs = Submission::factory()->submitted()->create(['title' => 'Somebody else entirely']);

    livewire(ListSubmissions::class)
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertDontSee('Somebody else entirely');
});

it('opens the abstract with everything a reviewer needs and nothing that identifies the author', function () {
    livewire(ReviewSubmission::class, ['record' => $this->submission->getRouteKey()])
        ->assertOk()
        ->assertSee('AAM26-017')
        ->assertSee('Early mobilisation after cardiac surgery')
        ->assertSee($this->submission->abstract)
        // Blind: the author, the affiliation and the contact number are all
        // out (spec 5.4 step 4).
        ->assertDontSee('Dr Sara Al-Harbi')
        ->assertDontSee('sara@example.org')
        ->assertDontSee('+966500000000')
        ->assertSee(__('reviewer.review.blind_notice'));
});

it('shows the authors when the conference is not blind', function () {
    $this->conference->forceFill(['blind_review' => false])->save();

    livewire(ReviewSubmission::class, ['record' => $this->submission->fresh()->getRouteKey()])
        ->assertSee('Dr Sara Al-Harbi')
        ->assertSee('sara@example.org')
        ->assertDontSee(__('reviewer.review.blind_notice'));
});

it('hides an identifying custom field from a blind reviewer and shows it otherwise', function () {
    // Blinding the authors block is theatre if the organizer's own
    // "Institution" question prints the same answer two sections down. Only the
    // organizer knows which of their questions identify an author, so
    // custom_fields.hide_from_reviewers is how they say so (Task 1).
    CustomField::factory()->for($this->conference)->create([
        'key' => 'institution',
        'label' => 'Institution',
        'type' => CustomFieldType::Text,
        'required' => false,
        'hide_from_reviewers' => true,
        'sort' => 1,
    ]);
    CustomField::factory()->for($this->conference)->create([
        'key' => 'study_design',
        'label' => 'Study design',
        'type' => CustomFieldType::Text,
        'required' => false,
        'hide_from_reviewers' => false,
        'sort' => 2,
    ]);

    $this->submission->forceFill(['custom_field_values' => [
        'institution' => 'King Faisal Specialist Hospital',
        'study_design' => 'Prospective cohort',
    ]])->save();

    livewire(ReviewSubmission::class, ['record' => $this->submission->fresh()->getRouteKey()])
        ->assertDontSee('King Faisal Specialist Hospital')
        ->assertSee('Prospective cohort');

    $this->conference->forceFill(['blind_review' => false])->save();

    // Not blind: the flag says "identifies an author", not "secret", so an
    // open-review conference still prints it.
    livewire(ReviewSubmission::class, ['record' => $this->submission->fresh()->getRouteKey()])
        ->assertSee('King Faisal Specialist Hospital')
        ->assertSee('Prospective cohort');
});

it('404s an abstract outside the pool or the assignments', function () {
    $theirs = Submission::factory()->submitted()->create();

    // Through the route, not livewire(). ReviewSubmission::mount() goes through
    // Filament's InteractsWithRecord::resolveRecord(), which throws
    // ModelNotFoundException for a record the scoped query excludes
    // (vendor/filament/filament/src/Resources/Pages/Concerns/
    // InteractsWithRecord.php:42-44), and Livewire's harness rethrows anything
    // that is not an HttpException or an AuthorizationException
    // (vendor/livewire/livewire/src/Features/SupportTesting/RequestBroker.php:29)
    // - so livewire(...)->assertNotFound() would ERROR instead of asserting.
    get(SubmissionResource::getUrl('review', ['record' => $theirs], panel: 'reviewer'))->assertNotFound();

    $this->conference->forceFill(['review_mode' => ReviewMode::Assigned])->save();

    // In assigned mode, an abstract nobody assigned to this reviewer is a 404
    // rather than a 403: the reviewer has no business knowing it exists.
    get(SubmissionResource::getUrl('review', ['record' => $this->submission->fresh()], panel: 'reviewer'))
        ->assertNotFound();
});

it('blinds the downloaded file name and cannot be un-blinded by editing the url', function () {
    $sha = hash('sha256', 'pdf');
    Storage::disk('local')->put(substr($sha, 0, 2).'/a.pdf', '%PDF-1.4 test');
    $file = SubmissionFile::factory()->for($this->submission)->create([
        'original_name' => 'al-harbi-kfsh-final.pdf',
        'path' => substr($sha, 0, 2).'/a.pdf',
        'sha256' => $sha,
        'mime' => 'application/pdf',
        'sort' => 1,
    ]);

    livewire(ReviewSubmission::class, ['record' => $this->submission->getRouteKey()])
        ->assertSee('attachment-1.pdf')
        ->assertDontSee('al-harbi-kfsh-final.pdf');

    $blind = $file->temporaryUrl(blind: true);

    get($blind)->assertOk()->assertDownload('attachment-1.pdf');

    // Every parameter is inside the HMAC (fact 19), so stripping `blind`
    // invalidates the signature rather than revealing the name.
    get((string) preg_replace('/blind=1&?/', '', $blind))->assertForbidden();

    // The organizer's own link is not blinded.
    expect($file->temporaryUrl())->not->toContain('blind=');
});

it('lets a reviewer download a file in their pool and refuses one outside it', function () {
    $sha = hash('sha256', 'pdf2');
    Storage::disk('local')->put(substr($sha, 0, 2).'/b.pdf', '%PDF-1.4 test');
    $mine = SubmissionFile::factory()->for($this->submission)->create([
        'original_name' => 'abstract.pdf', 'path' => substr($sha, 0, 2).'/b.pdf', 'sha256' => $sha, 'sort' => 1,
    ]);

    $theirSubmission = Submission::factory()->submitted()->create();
    $theirs = SubmissionFile::factory()->for($theirSubmission)->create([
        'original_name' => 'theirs.pdf', 'path' => substr($sha, 0, 2).'/b.pdf', 'sha256' => hash('sha256', 'other'), 'sort' => 1,
    ]);

    // The policy is what decides where a signed URL may be *minted*
    // (SubmissionFilePolicy's own docblock); the route itself is authorized by
    // the signature.
    expect($this->reviewer->can('view', $mine))->toBeTrue()
        ->and($this->reviewer->can('view', $theirs))->toBeFalse();
});

it('blinds nothing for the organizer, including the csv export', function () {
    $organizer = User::factory()->create();
    $this->organization->addMember($organizer, OrganizationRole::Owner);
    actingAs($organizer);
    bootOrganizerPanel($this->organization);

    expect($this->conference->fresh()?->blind_review)->toBeTrue()
        ->and($this->conference->fresh()?->hidesAuthorsFrom($organizer))->toBeFalse();

    $component = livewire(OrganizerList::class)
        ->callTableAction('export')
        ->assertFileDownloaded();

    $csv = base64_decode((string) data_get($component->effects, 'download.content'), true);

    // Blind review is a rule about reviewers. Blinding the organizer's own
    // export would leave nobody able to answer an author's email.
    expect($csv)->toContain('sara@example.org')->toContain('Dr Sara Al-Harbi');
});

it('links each conference on the dashboard into its queue', function () {
    livewire(App\Filament\Reviewer\Pages\Dashboard::class)
        ->assertSee(__('reviewer.dashboard.open_queue'));

    get(SubmissionResource::urlForConference($this->conference))->assertOk();
});
```

- [ ] **Step 2: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ReviewerScopeTest.php tests/Feature/Reviewer/ReviewQueueTest.php > /tmp/t.log 2>&1; echo "rc=$?"; head -20 /tmp/t.log
```

Expected: `rc=1`, `Class "App\Support\Reviews\ReviewerScope" not found`.

- [ ] **Step 3: The scope**

`app/Support/Reviews/ReviewerScope.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\ConferenceStatus;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * **The** definition of what a reviewer may see (spec section 4, "assigned or
 * pool only"; spec 5.4 step 3).
 *
 * Four things ask this question - the queue resource, the review page's record
 * binding, SubmissionFilePolicy, and Task 10's reminder command, which has to
 * know who still has outstanding work - and four copies of a whereHas is how
 * one rule becomes four rules. Everything here is a query the database can run;
 * nothing loads a collection to filter it in PHP, because a conference with 500
 * abstracts is the size spec section 10 budgets for.
 *
 * The rule:
 *   1. the conference is `reviewing` or `decided`;
 *   2. the reviewer has an *active* conference_reviewers row for it;
 *   3. the submission is `submitted` or `under_review`;
 *   4. and either the conference is open_pool, or a review_assignments row
 *      names this reviewer.
 *
 * Filament's tenancy global scope on Conference is inert here: it returns early
 * unless the *current panel* is the one that registered it and a tenant is set
 * (fact 40), and the reviewer panel has no tenancy at all.
 */
final class ReviewerScope
{
    /**
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public static function constrain(Builder $query, User $reviewer, ?Conference $conference = null): Builder
    {
        $query
            ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])
            ->whereHas('conference', function (Builder $conferenceQuery) use ($reviewer): void {
                $conferenceQuery
                    ->whereIn('status', [ConferenceStatus::Reviewing->value, ConferenceStatus::Decided->value])
                    ->whereHas('reviewers', fn (Builder $reviewers): Builder => $reviewers
                        ->where('user_id', $reviewer->getKey())
                        ->where('status', ReviewerStatus::Active->value));
            })
            ->where(function (Builder $either) use ($reviewer): void {
                $either
                    ->whereHas('conference', fn (Builder $c): Builder => $c->where('review_mode', ReviewMode::OpenPool->value))
                    ->orWhereHas('reviewAssignments', fn (Builder $a): Builder => $a->where('reviewer_user_id', $reviewer->getKey()));
            });

        if ($conference !== null) {
            $query->where('conference_id', $conference->getKey());
        }

        return $query;
    }

    /** @return Builder<Submission> */
    public static function submissions(User $reviewer, ?Conference $conference = null): Builder
    {
        return self::constrain(Submission::query(), $reviewer, $conference);
    }

    /**
     * One row. A fresh query rather than a read of the in-memory model, because
     * the answer depends on the conference's status and the reviewer's row,
     * neither of which the caller reliably has loaded.
     */
    public static function allows(User $reviewer, Submission $submission): bool
    {
        return self::submissions($reviewer)->whereKey($submission->getKey())->exists();
    }

    /**
     * The conferences this person reviews, in whatever state - the dashboard
     * lists a conference that has not started reviewing yet, with a sentence
     * saying so.
     *
     * @return Builder<Conference>
     */
    public static function conferences(User $reviewer): Builder
    {
        return Conference::query()
            ->whereHas('reviewers', fn (Builder $reviewers): Builder => $reviewers
                ->where('user_id', $reviewer->getKey())
                ->where('status', ReviewerStatus::Active->value));
    }
}
```

- [ ] **Step 4: The two policy changes and the blind file name**

`app/Policies/SubmissionPolicy.php` — `viewAny()` and `view()` gain the reviewer case:

```php
    public function viewAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        // Two panels ask this. In the organizer panel the answer is tenant
        // membership; in the reviewer panel there is no tenant at all and the
        // answer is "does this person review anywhere" - the row-level view()
        // below is what actually keeps one reviewer out of another's pool.
        return ($tenant instanceof Organization && $user->roleIn($tenant) !== null)
            || $user->isActiveReviewer();
    }

    /**
     * KEEP the existing class docblock paragraph about the guarded hops, and
     * keep both of them here: the conference can be null behind the tenant
     * scope, and `$conference->organization` is null on its own whenever the
     * tenant is soft-deleted while its conference row survives
     * (app/Models/Organization.php:27). User::roleIn() type-hints a non-nullable
     * Organization (app/Models/User.php:60), so dropping the second hop would
     * turn a gate that must answer "no" into a TypeError 500 - which is the
     * regression the shipped docblock says this guard exists to prevent.
     */
    public function view(User $user, Submission $submission): bool
    {
        $conference = $submission->conference;
        $organization = $conference?->organization;

        if ($conference === null || $organization === null) {
            return false;
        }

        if ($user->roleIn($organization) !== null) {
            return true;
        }

        // Spec section 4: a reviewer sees assigned-or-pool only.
        return ReviewerScope::allows($user, $submission);
    }
```

with `use App\Support\Reviews\ReviewerScope;` added to the imports.

`app/Policies/SubmissionFilePolicy.php` — the same two, and the class docblock's "Plan 4 adds the reviewer case" sentence is replaced. **Keep** the docblock paragraph that says why the chain is walked with `?->` at every hop ("Organization deletes as well"), and keep all three hops guarded here for the same reason as above:

```php
    public function viewAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return ($tenant instanceof Organization && $user->roleIn($tenant) !== null)
            || $user->isActiveReviewer();
    }

    public function view(User $user, SubmissionFile $file): bool
    {
        $submission = $file->submission;
        $organization = $submission?->conference?->organization;

        if ($submission === null || $organization === null) {
            return false;
        }

        if ($user->roleIn($organization) !== null) {
            return true;
        }

        return ReviewerScope::allows($user, $submission);
    }
```

with `use App\Support\Reviews\ReviewerScope;` added to this file's imports too.

`app/Models/SubmissionFile.php` — `temporaryUrl()` gains the blind flag, and `extension()` plus `blindName()` arrive. The model today has exactly five members — `casts()`, `getRouteKeyName()`, `submission()`, `temporaryUrl()`, `readStream()` — so `extension()` is genuinely new and must land in the same edit as its only caller:

```php
    /**
     * Spec section 8: downloads only through signed routes. Thirty minutes is
     * long enough to click a link in an email client that prefetches, short
     * enough that a forwarded URL is dead by the time it is forwarded.
     *
     * `blind` travels *inside* the signature (fact 19), so a reviewer cannot
     * remove it to learn the author's file name. It is absent from an
     * organizer's link entirely, which is also what the test asserts.
     */
    public function temporaryUrl(?int $minutes = null, bool $blind = false): string
    {
        $parameters = ['ulid' => $this->ulid];

        if ($blind) {
            $parameters['blind'] = 1;
        }

        return URL::temporarySignedRoute(
            'files.download',
            now()->addMinutes($minutes ?? (int) config('cass.file_url_minutes')),
            $parameters,
        );
    }

    /**
     * The stored `original_name` is the only place an extension lives - there
     * is no `extension` column, and this model has no such method today, so
     * blindName() below would be a fatal "call to undefined method" without
     * it. With the PATHINFO_EXTENSION flag, pathinfo() answers a plain string
     * and gives back `''` for a name with no dot at all (checked on the
     * 8.4.23 here: `pathinfo('foo', PATHINFO_EXTENSION)` is `string(0) ""`),
     * which is exactly the case blindName() tests for. `original_name` is cast
     * because nothing on this model casts it, and the answer is lower-cased so
     * `Poster.PDF` and `poster.pdf` both blind to `attachment-1.pdf`.
     */
    public function extension(): string
    {
        return mb_strtolower((string) pathinfo((string) $this->original_name, PATHINFO_EXTENSION));
    }

    /**
     * What a blind reviewer sees and downloads. Plan 3's backlog raised this:
     * an author who calls their PDF `al-harbi-kfsh-final.pdf` has
     * deanonymised themselves, and hiding the authors block while serving that
     * file name would be theatre.
     */
    public function blindName(): string
    {
        $extension = $this->extension();
        $position = max(1, (int) $this->sort);

        return 'attachment-'.$position.($extension === '' ? '' : '.'.$extension);
    }
```

`app/Http/Controllers/Public/SubmissionFileController.php` — the signature gains the request, and the download name becomes a decision:

```php
    public function __invoke(Request $request, string $ulid): StreamedResponse
    {
        $file = SubmissionFile::query()->where('ulid', $ulid)->first();

        abort_if($file === null, 404);
        abort_if($file->submission === null, 404);
        abort_unless(Storage::disk('local')->exists((string) $file->path), 404);

        // `blind` was signed into the URL by whoever minted it (Task 6), so it
        // cannot be removed or added by the recipient. There is still no policy
        // call and no session check here: the signature is the capability, and
        // the author reaching this route has no session at all.
        $name = $request->boolean('blind')
            ? $file->blindName()
            : (string) $file->original_name;

        return Storage::disk('local')->download((string) $file->path, $name, [
            'Content-Type' => (string) $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
```

with `use Illuminate\Http\Request;` added.

- [ ] **Step 5: The queue resource and its table**

`app/Filament/Reviewer/Resources/Submissions/SubmissionResource.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Resources\Submissions;

use App\Filament\Reviewer\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Reviewer\Resources\Submissions\Pages\ReviewSubmission;
use App\Filament\Reviewer\Resources\Submissions\Tables\QueueTable;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\User;
use App\Support\Reviews\ReviewerScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The reviewer's queue (spec 5.4 step 3).
 *
 * `$isScopedToTenant = false` because the reviewer panel has no tenancy at all;
 * what replaces it is ReviewerScope in getEloquentQuery(), which
 * resolveRecordRouteBinding() also uses
 * (HasRoutes::getRecordRouteBindingEloquentQuery()), so the list, the review
 * page and every generated URL are covered by one override. SubmissionPolicy is
 * the second, independent gate.
 *
 * @extends \Filament\Resources\Resource<Submission>
 */
class SubmissionResource extends Resource
{
    protected static ?string $model = Submission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $recordTitleAttribute = 'title';

    protected static bool $isScopedToTenant = false;

    // No $recordRouteKeyName: Submission::getRouteKeyName() is the ULID and
    // Filament builds URLs from getRouteKey(), so the two agree (fact 28).

    public static function getNavigationLabel(): string
    {
        return __('reviewer.queue.title');
    }

    public static function getModelLabel(): string
    {
        return __('reviewer.queue.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reviewer.queue.model_plural');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isActiveReviewer();
    }

    public static function table(Table $table): Table
    {
        return QueueTable::configure($table);
    }

    /** @return Builder<Submission> */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        $query = parent::getEloquentQuery()->with(['conference.organization', 'track']);

        // whereRaw(false) rather than an empty result by luck: outside an
        // authenticated reviewer this resource has no meaning, and returning
        // every submission on the platform because auth() happened to be empty
        // is the failure mode this line exists to prevent.
        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return ReviewerScope::constrain($query, $user);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubmissions::route('/'),
            'review' => ReviewSubmission::route('/{record}/review'),
        ];
    }

    /**
     * The dashboard's link into one conference's queue. The query string has to
     * be the table filter's own shape - Filament binds `$tableFilters` as
     * `#[Url(as: 'filters')]` and a SelectFilter's state is `['value' => …]` -
     * the same rule Plan 3 discovered for the organizer's list.
     */
    public static function urlForConference(Conference $conference): string
    {
        return static::getUrl('index', [
            'filters' => ['conference_id' => ['value' => $conference->getKey()]],
        ], panel: 'reviewer');
    }
}
```

`app/Filament/Reviewer/Resources/Submissions/Tables/QueueTable.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Resources\Submissions\Tables;

use App\Filament\Reviewer\Resources\Submissions\SubmissionResource;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Support\Reviews\ReviewerScope;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Deliberately has **no author column and no author search**, in any mode.
 * Blind review is per conference, a reviewer's queue can span several, and a
 * column that is present for one conference and blank for another is a column
 * whose absence tells you something. The review page is where a non-blind
 * conference shows its authors.
 */
class QueueTable
{
    public static function configure(Table $table): Table
    {
        $user = auth()->user();
        $user = $user instanceof User ? $user : null;

        return $table
            ->defaultSort('reference')
            ->columns([
                TextColumn::make('reference')->label(__('reviewer.queue.columns.reference'))
                    ->fontFamily('mono')->searchable()->sortable()->placeholder('-'),
                TextColumn::make('title')->label(__('reviewer.queue.columns.title'))
                    ->searchable()->sortable()->wrap()->limit(90),
                TextColumn::make('conference.name')->label(__('reviewer.queue.columns.conference'))
                    ->sortable()->toggleable(),
                TextColumn::make('track.name')->label(__('reviewer.queue.columns.track'))->placeholder('-'),
                TextColumn::make('presentation_preference')->label(__('reviewer.queue.columns.preference'))
                    ->badge()->placeholder('-'),
                TextColumn::make('files_count')->counts('files')->label(__('reviewer.queue.columns.files'))
                    ->badge()->color('gray'),
            ])
            ->filters([
                SelectFilter::make('conference_id')
                    ->label(__('reviewer.queue.columns.conference'))
                    // This reviewer's conferences only. `->relationship()`
                    // would query every conference on the platform, because
                    // this resource is not tenant-scoped by Filament.
                    ->options(fn (): array => $user === null
                        ? []
                        : ReviewerScope::conferences($user)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('track_id')
                    ->label(__('reviewer.queue.columns.track'))
                    ->options(fn (): array => $user === null
                        ? []
                        : Track::query()
                            ->whereIn('conference_id', ReviewerScope::conferences($user)->select('id'))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                    ->searchable(),
            ])
            ->recordActions([
                Action::make('review')
                    ->label(__('reviewer.queue.actions.review'))
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->url(fn (Submission $record): string => SubmissionResource::getUrl(
                        'review',
                        ['record' => $record],
                        panel: 'reviewer',
                    )),
            ])
            ->emptyStateHeading(__('reviewer.queue.empty_heading'))
            ->emptyStateDescription(__('reviewer.queue.empty_body'));
    }
}
```

There is deliberately **no** `eagerLoads(): Builder` helper on this class. Nothing calls it — the eager loads that matter are already on `SubmissionResource::getEloquentQuery()` above in this same step — and an uncalled `): Builder` would have to carry `@return Builder<Submission>` or fail Step 9's Larastan run, because level 6 reports a generic class in a return type without its type variables and every other `): Builder` in `app/` is annotated for that reason (`app/Filament/Organizer/Widgets/ConferencesOverview.php:72` carries `@return Builder<Conference>`). Dead code that needs a docblock to survive the analyser is dead code with a maintenance bill; a later plan that wants the eager loads can read them off the resource. `Illuminate\Database\Eloquent\Builder` is therefore **not** imported by this file — it would be an unused import and `pint --test` would fail on it.

`app/Filament/Reviewer/Resources/Submissions/Pages/ListSubmissions.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Resources\Submissions\Pages;

use App\Filament\Reviewer\Resources\Submissions\SubmissionResource;
use Filament\Resources\Pages\ListRecords;

class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;

    public function getTitle(): string
    {
        return __('reviewer.queue.title');
    }

    // No header actions: a reviewer creates nothing here.
}
```

- [ ] **Step 6: The review page (read-only half)**

`app/Filament/Reviewer/Resources/Submissions/Pages/ReviewSubmission.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Resources\Submissions\Pages;

use App\Filament\Reviewer\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Spec 5.4 step 4: "Review page shows the abstract (authors hidden when blind),
 * files, and the review form."
 *
 * This task builds everything except the form; Task 7 adds `form()`, the three
 * header actions and the state. The record is resolved through
 * SubmissionResource::getEloquentQuery(), which is ReviewerScope - so an
 * abstract outside this reviewer's pool or assignments is a **404**, not a 403.
 * A reviewer has no business learning that it exists.
 */
class ReviewSubmission extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SubmissionResource::class;

    protected string $view = 'filament.reviewer.resources.submissions.pages.review-submission';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        // Defence in depth behind the scoped query, exactly as on
        // ConferenceShortLink: the policy is the second, independent gate.
        abort_unless(Gate::allows('view', $this->getRecord()), 404);
    }

    public function getTitle(): string
    {
        return (string) $this->getSubmission()->title;
    }

    public function getSubmission(): Submission
    {
        /** @var Submission $submission */
        $submission = $this->getRecord();

        return $submission;
    }

    public function getConference(): Conference
    {
        return $this->getSubmission()->conference;
    }

    /** Spec 5.4 step 4. One call, to the one method that owns the rule. */
    public function isBlind(): bool
    {
        return $this->getConference()->hidesAuthorsFrom($this->reviewer());
    }

    /** @return Collection<int, SubmissionFile> */
    public function getFiles(): Collection
    {
        /** @var Collection<int, SubmissionFile> $files */
        $files = $this->getSubmission()->files;

        return $files;
    }

    /**
     * A fresh 30-minute signed URL per render, minted only after the policy
     * says this person may read the file; `blind` is baked into the signature
     * so the name cannot be recovered from the URL (fact 19).
     */
    public function fileUrl(SubmissionFile $file): ?string
    {
        return Gate::allows('view', $file)
            ? $file->temporaryUrl(blind: $this->isBlind())
            : null;
    }

    public function fileName(SubmissionFile $file): string
    {
        return $this->isBlind() ? $file->blindName() : (string) $file->original_name;
    }

    /**
     * The conference's custom fields, printed with the label the organizer
     * wrote rather than the derived key - the same rendering the organizer's
     * infolist does.
     *
     * Minus anything the organizer marked `hide_from_reviewers`, when the reader
     * is blinded. Hiding the authors block is worth nothing if an organizer's
     * own "Institution", "Department" or "Funding source" question prints the
     * same answer two sections down, and only the organizer knows which of their
     * questions identify an author - which is why Task 1 added the column and
     * the toggle rather than trying to guess from the label.
     *
     * Driven off the `custom_fields` rows rather than the answer bag, so a field
     * that was deleted (or hidden) stops printing even though the answer is
     * still in `submissions.custom_field_values`.
     *
     * @return array<int, string>
     */
    public function getCustomFieldLines(): array
    {
        $submission = $this->getSubmission();

        $fields = $submission->conference->customFields()
            ->when($this->isBlind(), fn (Builder $query): Builder => $query->where('hide_from_reviewers', false))
            ->pluck('label', 'key');

        $lines = [];

        foreach ((array) $submission->custom_field_values as $key => $value) {
            if (! $fields->has($key)) {
                continue;
            }

            $printable = match (true) {
                is_bool($value) => $value ? __('reviewer.review.yes') : __('reviewer.review.no'),
                is_scalar($value) => (string) $value,
                default => json_encode($value) ?: '',
            };

            $lines[] = $fields[$key].': '.$printable;
        }

        return $lines;
    }

    public function reviewer(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToQueue')
                ->label(__('reviewer.review.back'))
                ->icon(Heroicon::OutlinedQueueList)
                ->color('gray')
                ->url(fn (): string => SubmissionResource::getUrl('index', panel: 'reviewer')),
        ];
    }
}
```

`resources/views/filament/reviewer/resources/submissions/pages/review-submission.blade.php`
```blade
@php
    $submission = $this->getSubmission();
    $conference = $this->getConference();
    $files = $this->getFiles();
    $customFields = $this->getCustomFieldLines();
@endphp

<x-filament-panels::page>
    <x-filament::section :heading="__('reviewer.review.abstract')">
        <p style="font-family:ui-monospace,monospace;font-size:0.875rem">{{ $submission->reference }}</p>
        <h2 style="margin-top:0.5rem;font-size:1.125rem;font-weight:600">{{ $submission->title }}</h2>

        <div style="margin-top:0.75rem;display:flex;gap:1.5rem;flex-wrap:wrap;font-size:0.875rem">
            <span>{{ __('reviewer.review.track') }}: {{ $submission->track?->name ?? '-' }}</span>
            <span>{{ __('reviewer.review.preference') }}: {{ $submission->presentation_preference?->getLabel() ?? '-' }}</span>
            <span>{{ __('reviewer.review.words', ['count' => $submission->word_count]) }}</span>
        </div>

        {{-- Plain text, never HTML: the column is plain text and the public
             form is a textarea, so this is an escaped echo by construction. --}}
        <p style="margin-top:1rem;white-space:pre-wrap">{{ $submission->abstract }}</p>
    </x-filament::section>

    @if ($this->isBlind())
        <x-filament::section :heading="__('reviewer.review.authors')">
            <p style="font-size:0.875rem">{{ __('reviewer.review.blind_notice') }}</p>
        </x-filament::section>
    @else
        <x-filament::section :heading="__('reviewer.review.authors')">
            <ul style="font-size:0.875rem;display:flex;flex-direction:column;gap:0.25rem">
                @foreach ($submission->authors as $author)
                    <li>
                        {{ $author->name }}
                        @if ($author->affiliation) &mdash; {{ $author->affiliation }} @endif
                        @if ($author->is_corresponding) ({{ $author->email }}) @endif
                    </li>
                @endforeach
            </ul>
            @if ($submission->contact_phone)
                <p style="margin-top:0.5rem;font-size:0.875rem">{{ __('reviewer.review.phone') }}: {{ $submission->contact_phone }}</p>
            @endif
        </x-filament::section>
    @endif

    @if ($customFields !== [])
        <x-filament::section :heading="__('reviewer.review.extra')">
            <ul style="font-size:0.875rem;display:flex;flex-direction:column;gap:0.25rem">
                @foreach ($customFields as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    @if ($files->isNotEmpty())
        <x-filament::section :heading="__('reviewer.review.files')">
            <ul style="font-size:0.875rem;display:flex;flex-direction:column;gap:0.5rem">
                @foreach ($files as $file)
                    <li>
                        @php($url = $this->fileUrl($file))
                        @if ($url)
                            <x-filament::link :href="$url" target="_blank" rel="noopener">{{ $this->fileName($file) }}</x-filament::link>
                        @else
                            {{ $this->fileName($file) }}
                        @endif
                        <span style="opacity:0.7">({{ number_format($file->size / 1024) }} KB)</span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
```

- [ ] **Step 7: The dashboard link and the language keys**

`resources/views/filament/reviewer/pages/dashboard.blade.php` — inside each conference section, after the deadline paragraph:

```blade
                @if ($conference->isOpenToReviewers())
                    <p style="margin-top:0.75rem">
                        <x-filament::link :href="\App\Filament\Reviewer\Resources\Submissions\SubmissionResource::urlForConference($conference)">
                            {{ __('reviewer.dashboard.open_queue') }}
                        </x-filament::link>
                    </p>
                @endif
```

Append to `lang/en/reviewer.php`:

```php
    'queue' => [
        'title' => 'Abstracts to review',
        'model' => 'abstract',
        'model_plural' => 'abstracts',
        'empty_heading' => 'Nothing waiting',
        'empty_body' => 'When the organizers open a conference for review, the abstracts you can review appear here.',
        'columns' => [
            'reference' => 'Reference',
            'title' => 'Title',
            'conference' => 'Conference',
            'track' => 'Track',
            'preference' => 'Preference',
            'files' => 'Files',
        ],
        'actions' => [
            'review' => 'Open',
        ],
    ],

    'review' => [
        'abstract' => 'Abstract',
        'authors' => 'Authors',
        'blind_notice' => 'This conference is reviewed blind, so the authors and their affiliations are hidden from you. File names are hidden for the same reason.',
        'extra' => 'Additional answers',
        'files' => 'Files',
        'track' => 'Track',
        'preference' => 'Presentation preference',
        'phone' => 'Contact phone',
        'words' => ':count words',
        'yes' => 'Yes',
        'no' => 'No',
        'back' => 'Back to the queue',
    ],
```

and to `lang/en/reviewer.php`'s `dashboard` group:

```php
        'open_queue' => 'Open the abstracts',
```

- [ ] **Step 8: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ReviewerScopeTest.php tests/Feature/Reviewer/ReviewQueueTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -6 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`; about 21 passed in the first and `baseline + 111` in the second. **`tests/Feature/Public/SubmissionFileDownloadTest.php` is the file to watch**: the controller signature changed, and an organizer's link must still download under its real name. One case is new to this revision of the plan — `it('hides an identifying custom field from a blind reviewer and shows it otherwise')` — and `it('404s an abstract outside the pool or the assignments')` now goes through `get(...)` twice instead of `livewire(...)`, for the reason written inside it. If `it('blinds nothing for the organizer, including the csv export')` fails with an unscoped-query symptom, the cause is `bootOrganizerPanel()` still calling `Filament::bootCurrentPanel()`: Task 5 Step 1 replaced it for exactly this test.

- [ ] **Step 9: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(reviewer): the queue, the read-only abstract and blind review

ReviewerScope is the single definition of assigned-or-pool, used by the queue,
the review page, SubmissionFilePolicy and (from Task 10) the reminder command.
Blind review reaches the download through the signature: blind=1 is inside the
HMAC, so a reviewer cannot strip it to learn the author's file name.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 7: The review form, draft and submit, the form lock and `under_review`

Spec 5.4 step 4's second half, the review-form lock rule of spec section 3, and the `Submitted -> UnderReview` transition `SubmissionStatus` has been declaring since Plan 3.

**One place builds the form, one place validates it.** `App\Support\Reviews\ReviewFormSchema` turns `ReviewQuestion` rows into Filament components **and** into Laravel validation rules, from the same `match` on `ReviewQuestionType`. The panel renders the first and `SubmitReview` applies the second, so a Likert question with bounds 1-7 cannot offer eight options on screen and accept nine over the wire. The keys are question **ULIDs**, not ids: they are in a Livewire snapshot and in an error bag, and spec section 3 makes the ULID the public identifier.

**What the review deadline actually stops — decided here, flagged for the owner.** The brief for this plan says "a submitted review can be reopened by its reviewer until the review deadline; after the deadline reviews are read-only." Read strictly, that second clause would also stop a reviewer *finishing* outstanding work — which is impossible to square with `reviewer_overdue`, a spec 5.9 template key whose platform default (shipped in Plan 3, `lang/en/mail.php`) tells a reviewer after the deadline to "complete them as soon as you can". So the rule this plan implements is:

- **a submitted review becomes read-only at the review deadline** — no reopen, no edit;
- **a draft may still be saved and submitted** while the conference is in `reviewing`, deadline or no deadline.

That is what makes the overdue email honest. It is recorded as an **open question for the owner** in Task 13's backlog, with the one-line change that makes it strict.

**The lock.** `SubmitReview` calls `ReviewForm::lockIfUnlocked()` (Task 1) inside the same transaction as the review's transition. That is a conditional `UPDATE … WHERE locked_at IS NULL`, so two reviewers submitting in the same second cannot both believe they were first. Everything that enforces the lock already exists from Plan 2 — `ReviewQuestion::booted()`'s `updating`/`deleting` guards, `ReviewQuestionPolicy::update()/delete()`, and `reorderable(null)` in the relation manager — and Plan 2's tests set `locked_at` by hand. Step 1 replaces one of those with a test that submits a **real review** and then asserts the organizer's relation manager has gone read-only, so the two halves are finally joined.

**`Submitted -> UnderReview`.** The first submitted review on an abstract moves it, and only from `Submitted`; an abstract already `under_review`, `withdrawn`, or decided by Plan 5 is left alone. Task 6's test that `/s/{token}` renders a sane block for `under_review` finally has a real way to reach that status.

**The contract:**

| Class | Signature |
|---|---|
| `App\Support\Reviews\ReviewFormSchema` | `static components(ReviewForm $form, bool $disabled = false): list<Component>` |
| `App\Support\Reviews\ReviewFormSchema` | `static rules(ReviewForm $form, bool $required): array<string, list<mixed>>` |
| `App\Support\Reviews\ReviewFormSchema` | `static fill(?Review $review): array<string, mixed>` |
| `SaveReviewDraft` | `handle(Submission $s, User $reviewer, array<string, mixed> $answers): Review` |
| `SubmitReview` | `blockers(Submission $s, User $reviewer, array<string, mixed> $answers): list<string>` |
| `SubmitReview` | `handle(Submission $s, User $reviewer, array<string, mixed> $answers): Review` |
| `ReopenReview` | `blockers(Review $review): list<string>` and `handle(Review $review, User $reviewer): Review` |

**Files:**
- Create: `app/Support/Reviews/ReviewFormSchema.php`, `app/Exceptions/ReviewNotAcceptable.php`
- Create: `app/Actions/Reviews/{SaveReviewDraft,SubmitReview,ReopenReview}.php`
- Modify: `app/Filament/Reviewer/Resources/Submissions/Pages/ReviewSubmission.php`, its Blade view, `app/Filament/Reviewer/Resources/Submissions/Tables/QueueTable.php` (a "your review" column), `app/Filament/Reviewer/Resources/Submissions/SubmissionResource.php` (the constrained `reviews` eager load Step 6 adds, without which the new `review_state` column is one query per row), `lang/en/reviewer.php`
- Test: `tests/Unit/SubmitReviewTest.php`, `tests/Feature/Reviewer/ReviewSubmissionTest.php`, `tests/Feature/Organizer/ReviewQuestionsRelationManagerTest.php` (modified)

- [ ] **Step 1: Write the failing tests**

`tests/Unit/SubmitReviewTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Actions\Reviews\ReopenReview;
use App\Actions\Reviews\SaveReviewDraft;
use App\Actions\Reviews\SubmitReview;
use App\Enums\ConferenceStatus;
use App\Enums\ReviewQuestionType;
use App\Enums\ReviewStatus;
use App\Enums\SubmissionStatus;
use App\Exceptions\ReviewNotAcceptable;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\User;
use App\Support\Reviews\ReviewerScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->conference = Conference::factory()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
    ]);
    $this->form = app(CreateDefaultReviewForm::class)->handle($this->conference);
    // Nine Likert questions from the default template; trim to two so the
    // tests below say what they mean.
    $this->form->questions()->orderBy('sort')->skip(2)->take(99)->get()->each->delete();
    [$this->first, $this->second] = $this->form->questions()->orderBy('sort')->get()->all();

    $this->submission = Submission::factory()->for($this->conference)->submitted()->create();
    $this->reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->reviewer->id]);
});

/** @return array<string, mixed> */
function fullAnswers(object $test): array
{
    return [$test->first->ulid => 4, $test->second->ulid => 5];
}

it('saves a draft with no validation at all', function () {
    // A draft is a scratchpad: half an answer must survive a coffee break.
    $review = app(SaveReviewDraft::class)->handle($this->submission, $this->reviewer, [
        $this->first->ulid => 3,
    ]);

    expect($review->status)->toBe(ReviewStatus::Draft)
        ->and($review->submitted_at)->toBeNull()
        ->and($review->review_form_id)->toBe($this->form->id)
        ->and($review->answers)->toHaveCount(1)
        ->and($this->submission->fresh()?->status)->toBe(SubmissionStatus::Submitted)
        ->and($this->form->fresh()?->isLocked())->toBeFalse();
});

it('reuses the same review row and replaces the answer instead of appending', function () {
    $first = app(SaveReviewDraft::class)->handle($this->submission, $this->reviewer, [$this->first->ulid => 3]);
    $second = app(SaveReviewDraft::class)->handle($this->submission, $this->reviewer, [$this->first->ulid => 5]);

    expect($second->is($first))->toBeTrue()
        ->and(Review::query()->count())->toBe(1)
        ->and(ReviewAnswer::query()->count())->toBe(1)
        ->and($second->answers()->first()?->value_int)->toBe(5);
});

it('drops an answer to a question from another form', function () {
    $foreign = ReviewQuestion::factory()->create();

    $review = app(SaveReviewDraft::class)->handle($this->submission, $this->reviewer, [
        $this->first->ulid => 4,
        $foreign->ulid => 1,
    ]);

    // A cross-form foreign key would satisfy every constraint and mean nothing.
    expect($review->answers()->pluck('review_question_id')->all())->toBe([$this->first->id]);
});

it('names every missing and out-of-range answer instead of throwing on the first', function () {
    $blockers = app(SubmitReview::class)->blockers($this->submission, $this->reviewer, [
        $this->first->ulid => 9,
    ]);

    expect($blockers)->toHaveCount(2)
        ->and(implode(' ', $blockers))->toContain((string) $this->first->prompt)
        ->and(implode(' ', $blockers))->toContain((string) $this->second->prompt);
});

it('submits, stamps the time, locks the form and moves the abstract to under review', function () {
    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    expect($review->status)->toBe(ReviewStatus::Submitted)
        ->and($review->submitted_at)->not->toBeNull()
        ->and($review->answers)->toHaveCount(2)
        // Spec section 3: the first submitted review locks the form.
        ->and($this->form->fresh()?->isLocked())->toBeTrue()
        ->and($this->submission->fresh()?->status)->toBe(SubmissionStatus::UnderReview)
        ->and(Activity::query()->where('description', 'review.submitted')->count())->toBe(1);
});

it('locks the form once, whichever reviewer is second', function () {
    $other = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $other->id]);

    app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));
    $lockedAt = $this->form->fresh()?->locked_at;

    Carbon::setTestNow(now()->addHour());
    app(SubmitReview::class)->handle($this->submission, $other, fullAnswers($this));
    Carbon::setTestNow();

    expect($this->form->fresh()?->locked_at?->toDateTimeString())->toBe($lockedAt?->toDateTimeString());
});

it('does not drag a withdrawn or decided abstract back to under review', function () {
    $this->submission->forceFill(['status' => SubmissionStatus::UnderReview])->save();

    app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    expect($this->submission->fresh()?->status)->toBe(SubmissionStatus::UnderReview);
});

it('refuses a second submit and refuses a submission outside the pool', function () {
    app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    expect(fn () => app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this)))
        ->toThrow(ReviewNotAcceptable::class);

    $stranger = User::factory()->create();

    expect(fn () => app(SubmitReview::class)->handle($this->submission, $stranger, fullAnswers($this)))
        ->toThrow(ReviewNotAcceptable::class)
        ->and(fn () => app(SaveReviewDraft::class)->handle($this->submission, $stranger, []))
        ->toThrow(ReviewNotAcceptable::class);
});

it('validates every question type against its own rule', function () {
    $text = ReviewQuestion::factory()->for($this->form)->freeText()->create(['prompt' => 'Comments', 'required' => true, 'sort' => 30]);
    $boolean = ReviewQuestion::factory()->for($this->form)->create([
        'prompt' => 'Anonymised?', 'type' => ReviewQuestionType::Boolean,
        'scale_min' => null, 'scale_max' => null, 'sort' => 31,
    ]);
    $select = ReviewQuestion::factory()->for($this->form)->create([
        'prompt' => 'Format', 'type' => ReviewQuestionType::Select,
        'scale_min' => null, 'scale_max' => null, 'sort' => 32,
        'options' => [['label' => 'Oral', 'score' => 100], ['label' => 'Poster', 'score' => 50]],
    ]);

    $bad = fullAnswers($this) + [$text->ulid => '', $boolean->ulid => null, $select->ulid => 'Keynote'];

    expect(app(SubmitReview::class)->blockers($this->submission, $this->reviewer, $bad))->toHaveCount(3);

    $good = fullAnswers($this) + [$text->ulid => 'Solid work.', $boolean->ulid => true, $select->ulid => 'Oral'];
    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, $good);

    $answers = $review->answers->keyBy('review_question_id');

    expect($answers[$text->id]->value_text)->toBe('Solid work.')
        ->and($answers[$boolean->id]->value_bool)->toBeTrue()
        ->and($answers[$select->id]->choice_key)->toBe('Oral')
        // The typed column, not a string in value_text: Plan 5 averages these.
        ->and($answers[$this->first->id]->value_int)->toBe(4);
});

it('accepts an option label that contains a comma and refuses one of its halves', function () {
    // An option key IS the organizer's free-text label
    // (ReviewQuestion::optionKey()), and Laravel parses an `in:` parameter list
    // with str_getcsv - so 'in:'.implode(',', $keys) would split "Oral, in
    // person" into two bogus allowed values, reject the option the reviewer
    // actually chose, and silently admit "Oral" and " in person" into
    // review_answers.choice_key. Rule::in() quotes each value.
    $select = ReviewQuestion::factory()->for($this->form)->create([
        'prompt' => 'Format', 'type' => ReviewQuestionType::Select,
        'scale_min' => null, 'scale_max' => null, 'sort' => 33,
        'options' => [['label' => 'Oral, in person', 'score' => 100], ['label' => 'Poster', 'score' => 50]],
    ]);

    $answers = fullAnswers($this) + [$select->ulid => 'Oral, in person'];

    expect(app(SubmitReview::class)->blockers($this->submission, $this->reviewer, $answers))->toBe([])
        ->and(app(SubmitReview::class)->blockers(
            $this->submission,
            $this->reviewer,
            fullAnswers($this) + [$select->ulid => 'Oral'],
        ))->not->toBe([]);

    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, $answers);

    expect($review->answers->keyBy('review_question_id')[$select->id]->choice_key)->toBe('Oral, in person');
});

it('drops a non-scalar draft answer instead of throwing', function () {
    // A draft is permissive about completeness and strict about shape: an array
    // posted for a question is a forged payload, not half an answer, and
    // `(string) $value` on it is an ErrorException and a 500.
    $review = app(SaveReviewDraft::class)->handle($this->submission, $this->reviewer, [
        $this->first->ulid => ['not' => 'a scalar'],
    ]);

    expect($review->answers()->where('review_question_id', $this->first->id)->first()?->value_int)->toBeNull();
});

it('lets an optional question be left blank', function () {
    $optional = ReviewQuestion::factory()->for($this->form)->freeText()->create([
        'prompt' => 'Anything else?', 'required' => false, 'sort' => 40,
    ]);

    expect(app(SubmitReview::class)->blockers($this->submission, $this->reviewer, fullAnswers($this)))->toBe([]);

    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    // Stored as a row with a null value rather than not stored: Plan 5 counts
    // "answered and empty" differently from "never asked".
    expect($review->answers()->where('review_question_id', $optional->id)->exists())->toBeTrue();
});

it('reopens a submitted review before the deadline and refuses after it', function () {
    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    $reopened = app(ReopenReview::class)->handle($review, $this->reviewer);

    expect($reopened->status)->toBe(ReviewStatus::Draft)
        ->and($reopened->reopened_at)->not->toBeNull()
        // The form stays locked: a review WAS submitted, and unlocking would
        // let the organizer edit a question two reviewers already answered.
        ->and($this->form->fresh()?->isLocked())->toBeTrue()
        // The answers survive the reopen, because reopening is "let me change
        // my mind", not "start again".
        ->and($reopened->answers)->toHaveCount(2);

    app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    Carbon::setTestNow($this->conference->review_deadline->copy()->addMinute());

    expect(app(ReopenReview::class)->blockers($review->fresh()))->not->toBe([])
        ->and(fn () => app(ReopenReview::class)->handle($review->fresh(), $this->reviewer))
        ->toThrow(ReviewNotAcceptable::class);

    Carbon::setTestNow();
});

it('still lets outstanding work be finished after the deadline', function () {
    // This is the rule the `reviewer_overdue` template already promises: "the
    // deadline has passed and some of your reviews are still outstanding.
    // Please complete them as soon as you can." Freezing a draft at the
    // deadline would make that email a lie. Recorded as an owner question in
    // Task 13.
    Carbon::setTestNow($this->conference->review_deadline->copy()->addWeek());

    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    expect($review->status)->toBe(ReviewStatus::Submitted);

    Carbon::setTestNow();
});

it('stops everything once the conference leaves reviewing', function () {
    // Decided is still READABLE - ReviewerScope::allows() admits it, because a
    // reviewer may look back at what they said - so nothing in the scope stops
    // a write here. Conference::acceptsReviewWrites() is what does, and the
    // three write actions each ask it.
    $review = app(SubmitReview::class)->handle($this->submission, $this->reviewer, fullAnswers($this));

    $this->conference->forceFill(['status' => ConferenceStatus::Decided])->save();

    expect(ReviewerScope::allows($this->reviewer, $this->submission->fresh()))->toBeTrue()
        ->and(fn () => app(SaveReviewDraft::class)->handle($this->submission->fresh(), $this->reviewer, []))
        ->toThrow(ReviewNotAcceptable::class)
        ->and(app(SubmitReview::class)->blockers($this->submission->fresh(), $this->reviewer, fullAnswers($this)))
        ->not->toBe([])
        ->and(app(ReopenReview::class)->blockers($review->fresh()))->not->toBe([]);
});
```

`tests/Feature/Reviewer/ReviewSubmissionTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Enums\ConferenceStatus;
use App\Enums\ReviewStatus;
use App\Enums\SubmissionStatus;
use App\Filament\Reviewer\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Reviewer\Resources\Submissions\Pages\ReviewSubmission;
use App\Filament\Reviewer\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Review;
use App\Models\Submission;
use App\Models\User;
use Carbon\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->conference = Conference::factory()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
        'blind_review' => false,
    ]);
    $this->form = app(CreateDefaultReviewForm::class)->handle($this->conference);
    $this->form->questions()->orderBy('sort')->skip(2)->take(99)->get()->each->delete();
    [$this->first, $this->second] = $this->form->questions()->orderBy('sort')->get()->all();

    $this->submission = Submission::factory()->for($this->conference)->submitted()->create([
        'title' => 'Early mobilisation after cardiac surgery',
    ]);
    $this->reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->reviewer->id]);

    actingAs($this->reviewer);
    bootReviewerPanel();
});

function reviewPage(Submission $submission): object
{
    return livewire(ReviewSubmission::class, ['record' => $submission->getRouteKey()]);
}

it('renders every question of the active form', function () {
    reviewPage($this->submission)
        ->assertOk()
        ->assertSee($this->first->prompt)
        ->assertSee($this->second->prompt)
        ->assertFormFieldExists('answers.'.$this->first->ulid)
        ->assertActionVisible('saveDraft')
        ->assertActionVisible('submitReview')
        ->assertActionHidden('reopenReview');
});

it('saves a draft from the page and fills it back in on the next visit', function () {
    reviewPage($this->submission)
        ->fillForm(['answers.'.$this->first->ulid => 3])
        ->callAction('saveDraft')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $review = Review::query()->firstOrFail();

    expect($review->status)->toBe(ReviewStatus::Draft);

    reviewPage($this->submission->fresh())
        ->assertSchemaStateSet(['answers.'.$this->first->ulid => 3]);
});

it('refuses an incomplete submit with a field error on the missing question', function () {
    reviewPage($this->submission)
        ->fillForm(['answers.'.$this->first->ulid => 4])
        ->callAction('submitReview')
        ->assertHasFormErrors(['answers.'.$this->second->ulid]);

    expect(Review::query()->where('status', ReviewStatus::Submitted->value)->count())->toBe(0);
});

it('submits from the page, moves the abstract and turns the form read-only', function () {
    reviewPage($this->submission)
        ->fillForm([
            'answers.'.$this->first->ulid => 4,
            'answers.'.$this->second->ulid => 5,
        ])
        ->callAction('submitReview')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($this->submission->fresh()?->status)->toBe(SubmissionStatus::UnderReview)
        ->and($this->form->fresh()?->isLocked())->toBeTrue();

    reviewPage($this->submission->fresh())
        ->assertActionHidden('saveDraft')
        ->assertActionHidden('submitReview')
        ->assertActionVisible('reopenReview')
        ->assertSee(__('reviewer.review.submitted_notice'));
});

it('reopens from the page and hides the reopen once the deadline passes', function () {
    reviewPage($this->submission)
        ->fillForm(['answers.'.$this->first->ulid => 4, 'answers.'.$this->second->ulid => 5])
        ->callAction('submitReview');

    reviewPage($this->submission->fresh())
        ->callAction('reopenReview')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Review::query()->firstOrFail()->status)->toBe(ReviewStatus::Draft);

    reviewPage($this->submission->fresh())
        ->fillForm(['answers.'.$this->first->ulid => 4, 'answers.'.$this->second->ulid => 5])
        ->callAction('submitReview');

    Carbon::setTestNow($this->conference->review_deadline->copy()->addMinute());

    reviewPage($this->submission->fresh())
        ->assertActionHidden('reopenReview')
        ->assertSee(__('reviewer.review.deadline_passed'));

    Carbon::setTestNow();
});

it('shows the queue which abstracts are still waiting', function () {
    $done = Submission::factory()->for($this->conference)->submitted()->create(['title' => 'Already reviewed']);

    livewire(ListSubmissions::class)
        ->assertCanRenderTableColumn('review_state')
        ->assertSee(__('reviewer.queue.state.not_started'));

    reviewPage($done)
        ->fillForm(['answers.'.$this->first->ulid => 4, 'answers.'.$this->second->ulid => 5])
        ->callAction('submitReview');

    livewire(ListSubmissions::class)->assertSee(__('reviewer.queue.state.submitted'));
});

it('refuses to write a review for an abstract outside the pool', function () {
    $theirs = Submission::factory()->submitted()->create();

    // Through the route, not livewire(): ReviewSubmission::mount() resolves the
    // record through Filament's InteractsWithRecord::resolveRecord(), which
    // throws ModelNotFoundException for a record the scoped query excludes
    // (InteractsWithRecord.php:42-44), and Livewire's harness rethrows anything
    // that is not an HttpException or an AuthorizationException
    // (RequestBroker.php:29) - so livewire(...)->assertNotFound() would ERROR
    // rather than assert.
    get(SubmissionResource::getUrl('review', ['record' => $theirs], panel: 'reviewer'))->assertNotFound();

    expect($this->reviewer->can('view', $theirs))->toBeFalse();
});

it('lets a reviewer see and change only their own review', function () {
    $other = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $other->id]);
    $theirReview = Review::factory()->for($this->submission)->submitted()->create(['reviewer_user_id' => $other->id]);

    expect($this->reviewer->can('update', $theirReview))->toBeFalse()
        ->and($this->reviewer->can('view', $theirReview))->toBeFalse()
        ->and($other->can('update', $theirReview))->toBeTrue();

    // A second reviewer's review is invisible on the page: this is a review
    // form, not a discussion.
    reviewPage($this->submission)->assertDontSee($theirReview->ulid);
});
```

Replace one case in `tests/Feature/Organizer/ReviewQuestionsRelationManagerTest.php` — the existing `it('locks editing, reordering and deleting once reviews exist')` sets `locked_at` by hand. Keep it, and add the case that joins the two halves for real:

```php
it('is locked by a real submitted review, not only by a hand-set timestamp', function () {
    $submission = App\Models\Submission::factory()->for($this->conference)->submitted()->create();
    $reviewer = User::factory()->create();
    App\Models\ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $reviewer->id]);
    $this->conference->forceFill(['status' => App\Enums\ConferenceStatus::Reviewing])->save();

    $answers = $this->form->questions()->get()
        ->mapWithKeys(fn (ReviewQuestion $question): array => [$question->ulid => 4])
        ->all();

    app(App\Actions\Reviews\SubmitReview::class)->handle($submission->fresh(), $reviewer, $answers);

    $question = $this->form->questions()->first();

    reviewQuestionsManager($this->conference->fresh())
        ->assertSee('Locked')
        ->assertTableActionHidden('edit', $question)
        ->assertTableActionHidden('delete', $question)
        // Spec section 3: appending is still allowed on a locked form.
        ->assertTableActionVisible('create');

    expect($this->form->fresh()?->isLocked())->toBeTrue();
});
```

- [ ] **Step 2: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/SubmitReviewTest.php tests/Feature/Reviewer/ReviewSubmissionTest.php > /tmp/t.log 2>&1; echo "rc=$?"; head -20 /tmp/t.log
```

Expected: `rc=1`, `Class "App\Actions\Reviews\SaveReviewDraft" not found`.

- [ ] **Step 3: The schema builder**

`app/Support/Reviews/ReviewFormSchema.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\ReviewQuestionType;
use App\Models\Review;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\Rule;

/**
 * `ReviewQuestion` rows to Filament components, and the same rows to Laravel
 * validation rules, from one `match` on the question type.
 *
 * Two copies of that match - one drawing the form, one checking it - is how a
 * Likert question with bounds 1-7 comes to offer eight options on screen and
 * accept nine over the wire. The panel renders `components()`, SubmitReview
 * applies `rules()`, and neither knows how the other works.
 *
 * Keys are question **ULIDs**. They travel in a Livewire snapshot and in an
 * error bag, and spec section 3 makes the ULID the public identifier; a
 * database id in an error key is an id an organizer can read off the page.
 */
final class ReviewFormSchema
{
    /**
     * @return list<Component>
     */
    public static function components(ReviewForm $form, bool $disabled = false): array
    {
        $components = [];

        /** @var ReviewQuestion $question */
        foreach ($form->questions()->get() as $question) {
            $components[] = self::component($question)
                ->label((string) $question->prompt)
                ->helperText($question->help_text === null ? null : (string) $question->help_text)
                ->required($question->required === true)
                ->disabled($disabled)
                ->columnSpanFull();
        }

        return $components;
    }

    private static function component(ReviewQuestion $question): Radio|Select|Textarea
    {
        $key = 'answers.'.$question->ulid;

        return match ($question->type) {
            // Radio, not a Select: a 1-5 scale with its bounds visible is one
            // glance, and a reviewer answering nine of these in a row should
            // not have to open nine dropdowns.
            ReviewQuestionType::Likert => Radio::make($key)
                ->options(self::scaleOptions($question))
                ->inline()
                ->inlineLabel(false),
            ReviewQuestionType::Text => Textarea::make($key)->rows(4)->maxLength(5000),
            ReviewQuestionType::Boolean => Radio::make($key)
                ->options(['1' => __('reviewer.review.yes'), '0' => __('reviewer.review.no')])
                ->inline()
                ->inlineLabel(false),
            // Plain option arrays, never `->options(SomeEnum::class)`: these
            // are database rows, so no state cast is involved and the state is
            // an unambiguous string (fact 27).
            ReviewQuestionType::Select => Select::make($key)
                ->options($question->optionLabels())
                ->native(false),
        };
    }

    /** @return array<int, string> */
    private static function scaleOptions(ReviewQuestion $question): array
    {
        $min = (int) ($question->scale_min ?? 1);
        $max = (int) ($question->scale_max ?? 5);

        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }

        $options = [];

        for ($value = $min; $value <= $max; $value++) {
            $options[$value] = (string) $value;
        }

        return $options;
    }

    /**
     * @param  bool  $required  false while saving a draft: a draft is a
     *                          scratchpad and half an answer must survive a
     *                          coffee break
     * @return array<string, list<mixed>>
     */
    public static function rules(ReviewForm $form, bool $required): array
    {
        $rules = [];

        /** @var ReviewQuestion $question */
        foreach ($form->questions()->get() as $question) {
            $key = 'answers.'.$question->ulid;
            $must = $required && $question->required === true;

            $rules[$key] = match ($question->type) {
                ReviewQuestionType::Likert => [
                    $must ? 'required' : 'nullable',
                    'integer',
                    'min:'.(int) ($question->scale_min ?? 1),
                    'max:'.(int) ($question->scale_max ?? 5),
                ],
                ReviewQuestionType::Text => [$must ? 'required' : 'nullable', 'string', 'max:5000'],
                ReviewQuestionType::Boolean => [$must ? 'required' : 'nullable', 'boolean'],
                // Rule::in(), never 'in:'.implode(','). The option key IS the
                // organizer's free-text label (ReviewQuestion::optionKey()), and
                // Laravel parses an `in:` parameter list with str_getcsv
                // (Illuminate\Validation\ValidationRuleParser::parseParameters),
                // so a label such as "Oral, in person" would be split into two
                // bogus allowed values - rejecting the option the reviewer
                // actually chose, which no amount of re-picking can fix, while
                // silently admitting "Oral" and " in person" into
                // review_answers.choice_key. Rule::in() quotes each value, which
                // is exactly what it exists for.
                ReviewQuestionType::Select => [
                    $must ? 'required' : 'nullable',
                    'string',
                    Rule::in($question->optionKeys()),
                ],
            };
        }

        return $rules;
    }

    /**
     * The state a review fills the form with. A question with no answer row is
     * present and null, so Filament renders it blank rather than absent.
     *
     * @return array<string, mixed>
     */
    public static function fill(?Review $review): array
    {
        $answers = [];

        if ($review === null) {
            return ['answers' => $answers];
        }

        /** @var \App\Models\ReviewAnswer $answer */
        foreach ($review->answers()->with('question')->get() as $answer) {
            $question = $answer->question;

            if ($question === null) {
                continue;
            }

            $answers[$question->ulid] = match ($question->type) {
                ReviewQuestionType::Likert => $answer->value_int,
                ReviewQuestionType::Text => $answer->value_text,
                // The Radio's options are the strings '1' and '0'.
                ReviewQuestionType::Boolean => $answer->value_bool === null ? null : ($answer->value_bool ? '1' : '0'),
                ReviewQuestionType::Select => $answer->choice_key,
            };
        }

        return ['answers' => $answers];
    }
}
```

- [ ] **Step 4: The exception and the three actions**

`app/Exceptions/ReviewNotAcceptable.php`
```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A list of sentences a reviewer can act on, keyed where possible by the
 * question they belong to, so the panel can turn them into field errors rather
 * than one banner.
 */
final class ReviewNotAcceptable extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     * @param  array<string, string>  $fieldErrors  question ULID => message
     */
    public function __construct(public readonly array $reasons, public readonly array $fieldErrors = [])
    {
        parent::__construct(implode(' ', $reasons));
    }

    public static function because(string $reason): self
    {
        return new self([$reason]);
    }
}
```

`app/Actions/Reviews/SaveReviewDraft.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Enums\ReviewQuestionType;
use App\Enums\ReviewStatus;
use App\Exceptions\ReviewNotAcceptable;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\User;
use App\Support\Reviews\ReviewerScope;
use Illuminate\Support\Facades\DB;

/**
 * Spec 5.4 step 4: "Reviews can be saved as draft and submitted."
 *
 * A draft is a scratchpad. Nothing here validates an answer's *content* - a
 * reviewer who has answered three of nine questions and closes the laptop must
 * find those three there tomorrow. What is checked is the two things a draft
 * still cannot be: written by somebody outside the pool, and written against a
 * question from another conference's form.
 */
class SaveReviewDraft
{
    public function __construct(private readonly CreateDefaultReviewForm $createDefaultReviewForm) {}

    /**
     * @param  array<string, mixed>  $answers  keyed by review_questions.ulid
     */
    public function handle(Submission $submission, User $reviewer, array $answers): Review
    {
        if (! ReviewerScope::allows($reviewer, $submission)) {
            throw ReviewNotAcceptable::because(__('reviewer.errors.not_yours'));
        }

        // ReviewerScope answers a READING question, and it admits Decided
        // (Conference::isOpenToReviewers) so that a reviewer can look back at
        // what they said. Writing is narrower: once the committee has decided,
        // a new answer would change the evidence the decision was made on.
        if (! $submission->conference->acceptsReviewWrites()) {
            throw ReviewNotAcceptable::because(__('reviewer.errors.review_closed'));
        }

        $form = $this->form($submission);

        return DB::transaction(function () use ($submission, $reviewer, $answers, $form): Review {
            $review = $this->review($submission, $reviewer, $form);

            $this->writeAnswers($review, $form, $answers);

            return $review->refresh();
        });
    }

    /**
     * The review row, created on first save. `firstOrNew` on the unique pair
     * rather than `create`, so a double-clicked Save cannot hit the unique key.
     */
    public function review(Submission $submission, User $reviewer, ReviewForm $form): Review
    {
        /** @var Review $review */
        $review = Review::query()->firstOrNew([
            'submission_id' => $submission->getKey(),
            'reviewer_user_id' => $reviewer->getKey(),
        ]);

        if (! $review->exists) {
            $review->forceFill([
                'submission_id' => $submission->getKey(),
                'reviewer_user_id' => $reviewer->getKey(),
                'review_form_id' => $form->getKey(),
                'status' => ReviewStatus::Draft,
            ])->save();
        }

        return $review;
    }

    /**
     * The conference's active form, created on demand for a conference that
     * somehow has none (a factory, the Plan 6 import) exactly as the organizer
     * relation manager does.
     */
    public function form(Submission $submission): ReviewForm
    {
        $existing = $submission->conference->reviewForm()->first();

        return $existing instanceof ReviewForm
            ? $existing
            : $this->createDefaultReviewForm->handle($submission->conference);
    }

    /**
     * Replaces the answer set wholesale, one row per question of the form.
     * A question the caller did not mention becomes a row with a null value
     * rather than no row at all: Plan 5 counts "answered and empty" differently
     * from "never asked", and an updateOrCreate keyed on the unique pair is
     * what makes a second save idempotent.
     *
     * @param  array<string, mixed>  $answers
     */
    private function writeAnswers(Review $review, ReviewForm $form, array $answers): void
    {
        /** @var ReviewQuestion $question */
        foreach ($form->questions()->get() as $question) {
            // An answer to a question from another form is dropped rather than
            // stored: the foreign key would be satisfied and the row would mean
            // nothing.
            $value = $answers[$question->ulid] ?? null;

            /** @var ReviewAnswer $answer */
            $answer = ReviewAnswer::query()->firstOrNew([
                'review_id' => $review->getKey(),
                'review_question_id' => $question->getKey(),
            ]);

            $answer->forceFill([
                'review_id' => $review->getKey(),
                'review_question_id' => $question->getKey(),
                ...self::columnsFor($question, $value),
            ])->save();
        }
    }

    /**
     * One column per answer type, the other three explicitly null - so changing
     * a draft answer from "Oral" to a blank cannot leave `choice_key` behind.
     *
     * @return array{value_int: int|null, value_text: string|null, value_bool: bool|null, choice_key: string|null}
     */
    public static function columnsFor(ReviewQuestion $question, mixed $value): array
    {
        // A draft is permissive about COMPLETENESS and strict about SHAPE. An
        // array or an object posted for a Text question is not half an answer,
        // it is a forged payload, and `(string) $value` on it raises "Array to
        // string conversion", which HandleExceptions promotes to an
        // ErrorException and a 500. Drop it instead.
        if ($value !== null && ! is_scalar($value)) {
            $value = null;
        }

        $blank = $value === null || $value === '';

        return match ($question->type) {
            ReviewQuestionType::Likert => [
                'value_int' => $blank ? null : (int) $value,
                'value_text' => null, 'value_bool' => null, 'choice_key' => null,
            ],
            ReviewQuestionType::Text => [
                'value_int' => null,
                // Capped at the same 5000 the submit path enforces
                // (ReviewFormSchema::rules()) and the component renders
                // (->maxLength(5000)). `review_answers.value_text` is a
                // mediumText column; nothing else stops a draft save writing
                // megabytes into it once a request reaches this method without
                // going through the form.
                'value_text' => $blank ? null : mb_substr((string) $value, 0, 5000),
                'value_bool' => null, 'choice_key' => null,
            ],
            ReviewQuestionType::Boolean => [
                'value_int' => null, 'value_text' => null,
                'value_bool' => $blank ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'choice_key' => null,
            ],
            ReviewQuestionType::Select => [
                'value_int' => null, 'value_text' => null, 'value_bool' => null,
                'choice_key' => $blank ? null : (string) $value,
            ],
        };
    }
}
```

`app/Actions/Reviews/SubmitReview.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewQuestionType;
use App\Enums\ReviewStatus;
use App\Enums\SubmissionStatus;
use App\Exceptions\ReviewNotAcceptable;
use App\Models\Review;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\User;
use App\Support\Reviews\ReviewFormSchema;
use App\Support\Reviews\ReviewerScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Spec 5.4 step 4, spec section 3's lock rule, and the
 * `Submitted -> UnderReview` transition.
 *
 * Same shape as PublishConference and SubmitAbstract: `blockers()` is a pure
 * read the page calls to paint errors, `handle()` re-checks and throws, because
 * the page is a convenience and a hand-made Livewire call is not.
 *
 * **What the review deadline stops.** A *submitted* review is read-only once
 * the deadline passes (ReopenReview refuses). A *draft* may still be finished:
 * `reviewer_overdue`, a spec 5.9 key whose platform default Plan 3 already
 * ships, tells a reviewer after the deadline to complete outstanding reviews,
 * and freezing them would make that email a lie. Recorded as an owner question.
 */
class SubmitReview
{
    public function __construct(private readonly SaveReviewDraft $saveDraft) {}

    /**
     * @param  array<string, mixed>  $answers
     * @return list<string> empty when the review may be submitted
     */
    public function blockers(Submission $submission, User $reviewer, array $answers): array
    {
        $reasons = [];

        if (! ReviewerScope::allows($reviewer, $submission)) {
            // Covers every "not yours" case at once: not a reviewer, removed,
            // not assigned, conference not in review, abstract withdrawn.
            return [__('reviewer.errors.not_yours')];
        }

        // ...except the one it deliberately does not cover: ReviewerScope admits
        // Decided, because a reviewer may still READ what they said after the
        // committee decides. Writing stops at Reviewing.
        if (! $submission->conference->acceptsReviewWrites()) {
            $reasons[] = __('reviewer.errors.review_closed');
        }

        $existing = Review::query()
            ->where('submission_id', $submission->getKey())
            ->where('reviewer_user_id', $reviewer->getKey())
            ->first();

        if ($existing !== null && $existing->status === ReviewStatus::Submitted) {
            $reasons[] = __('reviewer.errors.already_submitted');
        }

        foreach ($this->fieldErrors($submission, $answers) as $message) {
            $reasons[] = $message;
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Per-question messages, so the panel can attach each one to its own field
     * instead of printing a banner listing nine prompts.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, string> question ULID => message
     */
    public function fieldErrors(Submission $submission, array $answers): array
    {
        $form = $this->saveDraft->form($submission);
        $rules = ReviewFormSchema::rules($form, required: true);

        $validator = Validator::make(['answers' => $answers], $rules);

        if ($validator->passes()) {
            return [];
        }

        $messages = [];

        /** @var ReviewQuestion $question */
        foreach ($form->questions()->get() as $question) {
            $key = 'answers.'.$question->ulid;

            if (! $validator->errors()->has($key)) {
                continue;
            }

            $messages[(string) $question->ulid] = __('reviewer.errors.answer', [
                'prompt' => (string) $question->prompt,
                'detail' => $this->detail($question),
            ]);
        }

        return $messages;
    }

    private function detail(ReviewQuestion $question): string
    {
        return match ($question->type) {
            ReviewQuestionType::Likert => __('reviewer.errors.detail_scale', [
                'min' => (int) ($question->scale_min ?? 1),
                'max' => (int) ($question->scale_max ?? 5),
            ]),
            ReviewQuestionType::Select => __('reviewer.errors.detail_choice', [
                'choices' => implode(', ', $question->optionKeys()),
            ]),
            ReviewQuestionType::Boolean => __('reviewer.errors.detail_boolean'),
            ReviewQuestionType::Text => __('reviewer.errors.detail_text'),
        };
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function handle(Submission $submission, User $reviewer, array $answers): Review
    {
        $reasons = $this->blockers($submission, $reviewer, $answers);

        if ($reasons !== []) {
            throw new ReviewNotAcceptable($reasons, $this->fieldErrors($submission, $answers));
        }

        $form = $this->saveDraft->form($submission);

        $review = DB::transaction(function () use ($submission, $reviewer, $answers, $form): Review {
            // The answers are written by the same code a draft save uses, so
            // "submit without saving first" and "save then submit" cannot store
            // different rows.
            $review = $this->saveDraft->handle($submission, $reviewer, $answers);

            $review->forceFill([
                'status' => ReviewStatus::Submitted,
                'submitted_at' => now(),
                'review_form_id' => $form->getKey(),
            ])->save();

            // Spec section 3. A conditional UPDATE, so two reviewers submitting
            // in the same second cannot both stamp a different locked_at.
            $form->lockIfUnlocked();

            // Only from Submitted. An abstract already under review, withdrawn,
            // or decided by Plan 5 is left exactly where it is.
            if ($submission->status === SubmissionStatus::Submitted) {
                $submission->forceFill(['status' => SubmissionStatus::UnderReview])->save();
            }

            return $review;
        });

        activity()
            ->performedOn($review)
            ->causedBy($reviewer)
            ->withProperties(['submission_id' => $submission->getKey()])
            ->log('review.submitted');

        return $review->refresh();
    }
}
```

`app/Actions/Reviews/ReopenReview.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewStatus;
use App\Exceptions\ReviewNotAcceptable;
use App\Models\Review;
use App\Models\User;

/**
 * Spec 5.4 step 4: "a submitted review can be reopened by the reviewer until
 * the review deadline."
 *
 * The answers are kept: reopening is "let me change my mind", not "start
 * again". The form stays locked - a review *was* submitted, and unlocking would
 * let the organizer edit a question another reviewer has already answered.
 */
class ReopenReview
{
    /** @return list<string> empty when the review may be reopened */
    public function blockers(Review $review): array
    {
        $reasons = [];

        if ($review->status !== ReviewStatus::Submitted) {
            $reasons[] = __('reviewer.errors.not_submitted');
        }

        $conference = $review->submission?->conference;

        if ($conference === null) {
            return [__('reviewer.errors.not_yours')];
        }

        // acceptsReviewWrites(), not isOpenToReviewers(): the latter admits
        // Decided so a reviewer can still READ their review after the committee
        // decides. Reopening is a write, and a review changed after the decision
        // would change the evidence that decision was made on.
        if (! $conference->acceptsReviewWrites()) {
            $reasons[] = __('reviewer.errors.review_closed');
        }

        if (! $conference->reviewWindowIsOpen()) {
            $reasons[] = __('reviewer.errors.deadline_passed');
        }

        return array_values(array_unique($reasons));
    }

    public function handle(Review $review, User $reviewer): Review
    {
        if ($review->reviewer_user_id !== $reviewer->getKey()) {
            throw ReviewNotAcceptable::because(__('reviewer.errors.not_yours'));
        }

        $reasons = $this->blockers($review);

        if ($reasons !== []) {
            throw new ReviewNotAcceptable($reasons);
        }

        $review->forceFill([
            'status' => ReviewStatus::Draft,
            'submitted_at' => null,
            'reopened_at' => now(),
        ])->save();

        activity()
            ->performedOn($review)
            ->causedBy($reviewer)
            ->log('review.reopened');

        return $review->refresh();
    }
}
```

- [ ] **Step 5: The page gains the form**

`app/Filament/Reviewer/Resources/Submissions/Pages/ReviewSubmission.php` — add to the existing class from Task 6:

```php
    /**
     * @property-read \Filament\Schemas\Schema $form
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];
```

(the `@property-read` line goes on the **class** docblock, as `Filament\Auth\Pages\EditProfile` does — fact 31)

and these members — **`mount()` and `getHeaderActions()` REPLACE the versions Task 6 wrote into this same class, they are not added beside them**; every other member below is new. Applying this step literally as an addition is a `Cannot redeclare method` fatal:

```php
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(Gate::allows('view', $this->getRecord()), 404);

        $this->form->fill(ReviewFormSchema::fill($this->review()));
    }

    /**
     * `defaultForm` runs before `form` (fact 31) and is where the state path
     * lives; `form` carries only the components.
     */
    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(ReviewFormSchema::components($this->reviewForm(), disabled: $this->isReadOnly()))
            ->columns(1);
    }

    public function review(): ?Review
    {
        $reviewer = $this->reviewer();

        if ($reviewer === null) {
            return null;
        }

        return Review::query()
            ->where('submission_id', $this->getSubmission()->getKey())
            ->where('reviewer_user_id', $reviewer->getKey())
            ->first();
    }

    public function reviewForm(): ReviewForm
    {
        return app(SaveReviewDraft::class)->form($this->getSubmission());
    }

    /**
     * Submitted, or the conference has moved on: the form renders disabled.
     *
     * acceptsReviewWrites(), not isOpenToReviewers(): a Decided conference is
     * still readable (the reviewer can open the page and see what they wrote)
     * but is no longer writable, and the three actions behind this all refuse
     * there too.
     */
    public function isReadOnly(): bool
    {
        return $this->review()?->isSubmitted() === true
            || ! $this->getConference()->acceptsReviewWrites();
    }

    public function deadlineHasPassed(): bool
    {
        return ! $this->getConference()->reviewWindowIsOpen();
    }

    /**
     * The REPLACEMENT for Task 6's version of this method, not an addition - so
     * `backToQueue` appears again at the bottom, unchanged. Task 6 shipped this
     * method with that one action in it; Task 7 puts three in front of it.
     *
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveDraft')
                ->label(__('reviewer.review.save_draft'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->visible(fn (): bool => ! $this->isReadOnly())
                ->action(function (SaveReviewDraft $save): void {
                    // getRawState(), not getState(): a draft is deliberately
                    // not validated (HasState.php:450 - getState() validates),
                    // and half an answer has to survive a coffee break.
                    /** @var array<string, mixed> $state */
                    $state = $this->form->getRawState();

                    try {
                        $save->handle($this->getSubmission(), $this->requireReviewer(), $this->answersFrom($state));
                    } catch (ReviewNotAcceptable $exception) {
                        $this->refuse($exception);

                        return;
                    }

                    Notification::make()->success()->title(__('reviewer.notices.draft_saved'))->send();
                }),

            Action::make('submitReview')
                ->label(__('reviewer.review.submit'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->requiresConfirmation()
                ->modalHeading(__('reviewer.review.submit_heading'))
                ->modalDescription(__('reviewer.review.submit_description'))
                ->visible(fn (): bool => ! $this->isReadOnly())
                ->action(function (SubmitReview $submit): void {
                    /** @var array<string, mixed> $state */
                    $state = $this->form->getRawState();
                    $answers = $this->answersFrom($state);

                    try {
                        $submit->handle($this->getSubmission(), $this->requireReviewer(), $answers);
                    } catch (ReviewNotAcceptable $exception) {
                        $this->refuse($exception);

                        return;
                    }

                    Notification::make()->success()->title(__('reviewer.notices.submitted'))->send();
                }),

            Action::make('reopenReview')
                ->label(__('reviewer.review.reopen'))
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(__('reviewer.review.reopen_heading'))
                ->visible(function (): bool {
                    $review = $this->review();

                    return $review !== null
                        && Gate::allows('reopen', $review)
                        && app(ReopenReview::class)->blockers($review) === [];
                })
                ->action(function (ReopenReview $reopen): void {
                    $review = $this->review();

                    if ($review === null) {
                        return;
                    }

                    Gate::authorize('reopen', $review);

                    try {
                        $reopen->handle($review, $this->requireReviewer());
                    } catch (ReviewNotAcceptable $exception) {
                        $this->refuse($exception);

                        return;
                    }

                    Notification::make()->success()->title(__('reviewer.notices.reopened'))->send();
                }),

            Action::make('backToQueue')
                ->label(__('reviewer.review.back'))
                ->icon(Heroicon::OutlinedQueueList)
                ->color('gray')
                ->url(fn (): string => SubmissionResource::getUrl('index', panel: 'reviewer')),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function answersFrom(array $state): array
    {
        $answers = $state['answers'] ?? [];

        return is_array($answers) ? $answers : [];
    }

    private function requireReviewer(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * Per-question messages become field errors on the very fields they belong
     * to, so a reviewer sees "answer this one" beside the question rather than
     * a banner listing nine prompts.
     */
    private function refuse(ReviewNotAcceptable $exception): void
    {
        foreach ($exception->fieldErrors as $ulid => $message) {
            $this->addError('data.answers.'.$ulid, $message);
        }

        if ($exception->fieldErrors === []) {
            Notification::make()->danger()
                ->title(__('reviewer.notices.refused'))
                ->body(e($exception->getMessage()))
                ->persistent()
                ->send();
        }
    }
```

with these imports added: `App\Actions\Reviews\{ReopenReview,SaveReviewDraft,SubmitReview}`, `App\Exceptions\ReviewNotAcceptable`, `App\Models\{Review,ReviewForm}`, `App\Support\Reviews\ReviewFormSchema`, `Filament\Notifications\Notification`, `Filament\Schemas\Schema`.

The Blade view gains the form and the two notices, after the files section:

```blade
    <x-filament::section :heading="__('reviewer.review.your_review')">
        @if ($this->isReadOnly())
            <p style="font-size:0.875rem;margin-bottom:1rem">
                {{ $this->deadlineHasPassed() ? __('reviewer.review.deadline_passed') : __('reviewer.review.submitted_notice') }}
            </p>
        @endif

        {{-- The fields only. There is no <form> element and no submit button
             here on purpose: the three actions live in the page header, where
             a test can call them by name (fact 32). --}}
        {{ $this->form }}
    </x-filament::section>
```

- [ ] **Step 6: The queue learns what is done**

`app/Filament/Reviewer/Resources/Submissions/Tables/QueueTable.php` — one column, after `files_count`:

```php
                TextColumn::make('review_state')
                    ->label(__('reviewer.queue.columns.state'))
                    ->badge()
                    // Not a relation column: "my review" is one row of a
                    // HasMany chosen by the current user, and `reviews.status`
                    // would print every reviewer's state joined together.
                    ->state(function (Submission $record) use ($user): string {
                        $review = $user === null
                            ? null
                            : $record->reviews->firstWhere('reviewer_user_id', $user->getKey());

                        return match (true) {
                            $review === null => __('reviewer.queue.state.not_started'),
                            $review->isSubmitted() => __('reviewer.queue.state.submitted'),
                            default => __('reviewer.queue.state.draft'),
                        };
                    })
                    ->color(fn (string $state): string => match ($state) {
                        __('reviewer.queue.state.submitted') => 'success',
                        __('reviewer.queue.state.draft') => 'warning',
                        default => 'gray',
                    }),
```

and the resource's eager loads gain the reviewer's own reviews, so the column costs no query per row:

```php
        $query = parent::getEloquentQuery()->with([
            'conference.organization',
            'track',
            // Constrained to this reviewer: the column reads one row and the
            // page must not load every reviewer's review of every abstract.
            'reviews' => fn (HasMany $reviews) => $reviews->where('reviewer_user_id', $user->getKey()),
        ]);
```

(move the `$user instanceof User` guard above the `with()` call so `$user->getKey()` is safe, and import `Illuminate\Database\Eloquent\Relations\HasMany`.)

- [ ] **Step 7: The language keys**

Append to `lang/en/reviewer.php`'s `queue` group:

```php
        'state' => [
            'not_started' => 'Not started',
            'draft' => 'Draft',
            'submitted' => 'Submitted',
        ],
```

and to `columns`: `'state' => 'Your review',`. Append to the `review` group:

```php
        'your_review' => 'Your review',
        'save_draft' => 'Save draft',
        'submit' => 'Submit review',
        'submit_heading' => 'Submit this review?',
        'submit_description' => 'The organizers can see it straight away. You can reopen it and change it until the review deadline.',
        'reopen' => 'Reopen',
        'reopen_heading' => 'Reopen this review so you can change it?',
        'submitted_notice' => 'You have submitted this review. Reopen it if you want to change anything.',
        'deadline_passed' => 'The review deadline has passed, so a submitted review can no longer be changed.',
```

and a new top-level group:

```php
    'notices' => [
        // (existing keys from Task 4 stay)
        'draft_saved' => 'Draft saved',
        'submitted' => 'Review submitted',
        'reopened' => 'Review reopened',
    ],

    'errors' => [
        // (existing keys from Task 4 stay)
        'not_yours' => 'This abstract is not in your queue.',
        'already_submitted' => 'You have already submitted this review. Reopen it if you want to change it.',
        'not_submitted' => 'This review has not been submitted, so there is nothing to reopen.',
        'review_closed' => 'This conference is no longer open for reviewing.',
        'deadline_passed' => 'The review deadline has passed.',
        'answer' => 'Please answer ":prompt". :detail',
        'detail_scale' => 'Choose a number between :min and :max.',
        'detail_choice' => 'Choose one of: :choices.',
        'detail_boolean' => 'Choose yes or no.',
        'detail_text' => 'Write something, or ask the organizers to make this question optional.',
    ],
```

- [ ] **Step 8: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/SubmitReviewTest.php tests/Feature/Reviewer/ReviewSubmissionTest.php tests/Feature/Organizer/ReviewQuestionsRelationManagerTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -6 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`; about 30 passed in the first and `baseline + 135` in the second. `tests/Feature/Public/SubmissionStatusPageTest.php` is worth watching: `under_review` is finally reachable, and Plan 3's neutral status block is what the author sees.

- [ ] **Step 9: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(reviewer): the review form, drafts, submit, reopen and the form lock

One ReviewFormSchema builds the components and the validation rules from the
same match, so a 1-7 scale cannot render eight options and accept nine. The
first submitted review locks the form with a conditional UPDATE and moves the
abstract to under_review.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 8: Assignments — per-submission reviewer pickers, unassign and coverage

Spec 5.5's manual half: "Organizer assigns reviewers per submission manually … Assignments can be changed until the review is submitted." The balanced auto-assign is Task 9, and it plugs into the page this task builds.

**This table is Eloquent-backed, unlike the Members and Reviewers pages.** Those two list two different kinds of thing and had to be arrays (fact 15). This one lists submissions — real rows, of which spec section 10 budgets for 500 — so it uses `Table::query()` and gets sorting, searching, pagination and a filter for free, and the reviewer set is a loaded relation rather than a computed column.

**The page only exists for an assigned-mode conference.** In open pool every active reviewer already sees every abstract, so an assignment would be a row that changes nothing; `AssignReviewers::blockers()` refuses it and `ConferenceAssignments::canAccess()`-equivalent (the `mount()` guard) 404s the page, so a bookmarked URL does not survive a mode change either.

**Unassigning is always allowed, even after that reviewer submitted.** The rule from the brief, and it is the right one: an organizer who realises they assigned the wrong person needs to be able to say so, and a submitted review is evidence that outlives the assignment (Plan 5 averages `reviews`, not `review_assignments`). A **draft** review survives too and simply leaves the queue — `ReviewerScope` reads assignments, not reviews — so re-assigning the same person restores their work. Note that this **contradicts spec 5.5's "Assignments can be changed until the review is submitted"**; it is deliberate, it is entry 8 in Task 13's deviations list, and it is an open question for the owner there.

**There is one way to unassign, not two.** The multi-select `assign` action sets the whole reviewer set of an abstract, so removing somebody is deselecting them and saving — one code path, behind one `Gate::authorize()`, covered by the tests below. This plan deliberately ships **no** per-row `UnassignReviewer` action class: a class that deletes rows, reachable by any caller, with no policy check and no conference check and nothing in the application calling it, is a cross-tenant delete waiting for the next plan to wire a row action to it. Task 13's backlog records what a per-row unassign would have to bring with it.

**The contract:**

| Class | Signature |
|---|---|
| `AssignReviewers` | `blockers(Submission $s, list<int> $reviewerUserIds): list<string>` |
| `AssignReviewers` | `handle(Submission $s, list<int> $reviewerUserIds, User $actor): array{added: int, removed: int}` |
| `Conference::assignmentCoverage()` | `array{target: int, submissions: int, covered: int, under: int, unassigned: int}` |

**Files:**
- Create: `app/Actions/Reviews/AssignReviewers.php`
- Create: `app/Filament/Organizer/Resources/Conferences/Pages/ConferenceAssignments.php`, `resources/views/filament/organizer/resources/conferences/pages/assignments.blade.php`
- Modify: `app/Models/Conference.php` (`assignmentCoverage()`), `app/Filament/Organizer/Resources/Conferences/ConferenceResource.php`, `.../Tables/ConferenceStatusActions.php`, `lang/en/reviewer.php`
- Test: `tests/Feature/Organizer/ConferenceAssignmentsTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Organizer/ConferenceAssignmentsTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Reviews\AssignReviewers;
use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewMode;
use App\Exceptions\ReviewNotAcceptable;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceAssignments;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->owner = User::factory()->create();
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
    actingAs($this->owner);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->create([
        'status' => ConferenceStatus::Closed,
        'review_mode' => ReviewMode::Assigned,
        'reviewers_per_submission' => 2,
        'review_deadline' => now()->addMonth(),
        'submission_opens_at' => now()->subMonths(2),
        'submission_deadline' => now()->subWeek(),
    ]);

    $this->submission = Submission::factory()->for($this->conference)->submitted()
        ->create(['title' => 'Early mobilisation after cardiac surgery']);
    $this->submission->forceFill(['reference' => 'AAM26-017'])->save();

    $this->omar = User::factory()->create(['name' => 'Dr Omar Khan']);
    $this->sara = User::factory()->create(['name' => 'Dr Sara Nasser']);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->omar->id]);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->sara->id]);
});

function assignmentsPage(Conference $conference): object
{
    return livewire(ConferenceAssignments::class, ['record' => $conference->getRouteKey()]);
}

it('lists the submitted abstracts with their reviewers and the coverage summary', function () {
    ReviewAssignment::factory()->for($this->submission)->create(['reviewer_user_id' => $this->omar->id]);
    Submission::factory()->for($this->conference)->create(['title' => 'A draft nobody sent']);

    assignmentsPage($this->conference)
        ->assertOk()
        ->assertSee('AAM26-017')
        ->assertSee('Dr Omar Khan')
        // A draft is not a thing to assign: only `submitted` and
        // `under_review` abstracts appear.
        ->assertDontSee('A draft nobody sent')
        ->assertSee(__('reviewer.assign.coverage_under', ['count' => 1]));
});

it('assigns a set of reviewers and logs the change', function () {
    assignmentsPage($this->conference)
        ->callTableAction('assign', $this->submission, data: [
            'reviewers' => [$this->omar->id, $this->sara->id],
        ])
        ->assertHasNoTableActionErrors();

    expect($this->submission->reviewAssignments()->pluck('reviewer_user_id')->sort()->values()->all())
        ->toBe([$this->omar->id, $this->sara->id])
        ->and($this->submission->reviewAssignments()->first()?->assigned_by)->toBe($this->owner->id)
        ->and(Activity::query()->where('description', 'review.assignments_changed')->count())->toBe(1);
});

it('replaces the set rather than appending to it', function () {
    app(AssignReviewers::class)->handle($this->submission, [$this->omar->id], $this->owner);
    $result = app(AssignReviewers::class)->handle($this->submission, [$this->sara->id], $this->owner);

    expect($result)->toBe(['added' => 1, 'removed' => 1])
        ->and($this->submission->reviewAssignments()->pluck('reviewer_user_id')->all())->toBe([$this->sara->id]);
});

it('is idempotent, so saving the same set twice writes nothing', function () {
    app(AssignReviewers::class)->handle($this->submission, [$this->omar->id], $this->owner);
    $assignedAt = $this->submission->reviewAssignments()->first()?->assigned_at;

    $result = app(AssignReviewers::class)->handle($this->submission, [$this->omar->id], $this->owner);

    expect($result)->toBe(['added' => 0, 'removed' => 0])
        ->and($this->submission->reviewAssignments()->first()?->assigned_at?->toDateTimeString())
        ->toBe($assignedAt?->toDateTimeString());
});

it('refuses a reviewer who is not an active reviewer of this conference', function () {
    $stranger = User::factory()->create();
    $removed = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->removed()->create(['user_id' => $removed->id]);

    expect(app(AssignReviewers::class)->blockers($this->submission, [$stranger->id]))->not->toBe([])
        ->and(app(AssignReviewers::class)->blockers($this->submission, [$removed->id]))->not->toBe([])
        ->and(fn () => app(AssignReviewers::class)->handle($this->submission, [$stranger->id], $this->owner))
        ->toThrow(ReviewNotAcceptable::class);

    expect(ReviewAssignment::query()->count())->toBe(0);
});

it('offers only this conference active reviewers in the picker', function () {
    $elsewhere = User::factory()->create(['name' => 'Somebody Elsewhere']);
    ConferenceReviewer::factory()->create(['user_id' => $elsewhere->id]);

    assignmentsPage($this->conference)
        ->mountTableAction('assign', $this->submission)
        // assertSee() here would read the component HTML from BEFORE the action
        // mounted - Livewire's SubsequentRender forwards the previous html, and
        // Filament's own helpers read effects.partials, which is where the modal
        // is (vendor/filament/actions/src/Testing/TestsActions.php:512, :533).
        // The searchable Select embeds its options in that partial, so only
        // these assertions can see them; the repo records the same trap in
        // tests/Feature/Organizer/ConferenceEmailTemplatesTest.php:135-140.
        ->assertMountedActionModalSee('Dr Omar Khan')
        ->assertMountedActionModalSee('Dr Sara Nasser')
        ->assertMountedActionModalDontSee('Somebody Elsewhere');
});

it('unassigns, keeps a draft review and keeps a submitted one', function () {
    app(AssignReviewers::class)->handle($this->submission, [$this->omar->id, $this->sara->id], $this->owner);

    $draft = Review::factory()->for($this->submission)->create(['reviewer_user_id' => $this->omar->id]);
    $submitted = Review::factory()->for($this->submission)->submitted()->create(['reviewer_user_id' => $this->sara->id]);

    assignmentsPage($this->conference)
        ->callTableAction('assign', $this->submission, data: ['reviewers' => [$this->sara->id]])
        ->assertHasNoTableActionErrors();

    expect($this->submission->reviewAssignments()->pluck('reviewer_user_id')->all())->toBe([$this->sara->id])
        // Neither review is touched. A draft simply leaves the queue, because
        // ReviewerScope reads assignments and not reviews; re-assigning the
        // same person brings their work back.
        ->and(Review::query()->whereKey($draft->getKey())->exists())->toBeTrue()
        ->and(Review::query()->whereKey($submitted->getKey())->exists())->toBeTrue();
});

it('counts coverage against reviewers_per_submission', function () {
    $second = Submission::factory()->for($this->conference)->submitted()->create();

    app(AssignReviewers::class)->handle($this->submission, [$this->omar->id, $this->sara->id], $this->owner);
    app(AssignReviewers::class)->handle($second, [$this->omar->id], $this->owner);

    expect($this->conference->fresh()?->assignmentCoverage())->toBe([
        'target' => 2,
        'submissions' => 2,
        'covered' => 1,
        'under' => 1,
        'unassigned' => 0,
    ]);
});

it('filters to the abstracts that still need reviewers', function () {
    $second = Submission::factory()->for($this->conference)->submitted()->create(['title' => 'Fully covered']);
    $second->forceFill(['reference' => 'AAM26-018'])->save();

    app(AssignReviewers::class)->handle($second, [$this->omar->id, $this->sara->id], $this->owner);

    assignmentsPage($this->conference)
        ->filterTable('under_target')
        ->assertCanSeeTableRecords([$this->submission])
        ->assertCanNotSeeTableRecords([$second]);
});

it('does not exist for an open pool conference', function () {
    $pool = Conference::factory()->for($this->organization)->closed()->create(['review_mode' => ReviewMode::OpenPool]);
    $poolSubmission = Submission::factory()->for($pool)->submitted()->create();

    // This one CAN stay on livewire(): the record resolves (it is this tenant's
    // conference) and the 404 comes from mount()'s
    // `abort_unless(… === ReviewMode::Assigned, 404)`, a NotFoundHttpException,
    // which Livewire's RequestBroker excepts and hands to the real handler
    // (RequestBroker.php:29). The cross-tenant case below cannot, because there
    // resolveRecord() throws ModelNotFoundException instead and the harness
    // rethrows it.
    livewire(ConferenceAssignments::class, ['record' => $pool->getRouteKey()])->assertNotFound();
    get(ConferenceResource::getUrl('assignments', ['record' => $pool]))->assertNotFound();

    expect(app(AssignReviewers::class)->blockers($poolSubmission, []))->not->toBe([]);

    // ... and the link is not offered either.
    livewire(App\Filament\Organizer\Resources\Conferences\Pages\ViewConference::class, [
        'record' => $pool->getRouteKey(),
    ])->assertActionHidden('assignments');
});

it('does not open the assignments page of another organization conference', function () {
    $theirs = withoutTenant(fn (): Conference => Conference::factory()->closed()->create([
        'review_mode' => ReviewMode::Assigned,
    ]));

    // Route only. The tenant-scoped query excludes this record, so
    // resolveRecord() throws ModelNotFoundException
    // (InteractsWithRecord.php:42-44) and Livewire's harness rethrows anything
    // but an HttpException or an AuthorizationException
    // (RequestBroker.php:29) - livewire(...)->assertNotFound() would ERROR here.
    get(ConferenceResource::getUrl('assignments', ['record' => $theirs]))->assertNotFound();
});

it('refuses assignment to somebody with no role in this organization', function () {
    $outsider = User::factory()->create();
    actingAs($outsider);

    expect($outsider->can('create', App\Models\ReviewAssignment::class))->toBeFalse();
});
```

- [ ] **Step 2: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferenceAssignmentsTest.php > /tmp/t.log 2>&1; echo "rc=$?"; head -20 /tmp/t.log
```

Expected: `rc=1`, `Class "App\Actions\Reviews\AssignReviewers" not found`.

- [ ] **Step 3: The two actions**

`app/Actions/Reviews/AssignReviewers.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Exceptions\ReviewNotAcceptable;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Spec 5.5: the manual half. Sets the whole reviewer set of one submission, so
 * the caller says what the answer should be rather than what to change - which
 * is what makes the picker a multi-select and makes saving the same set twice
 * write nothing.
 */
class AssignReviewers
{
    /**
     * @param  list<int>  $reviewerUserIds
     * @return list<string> empty when the set may be saved
     */
    public function blockers(Submission $submission, array $reviewerUserIds): array
    {
        $reasons = [];
        $conference = $submission->conference;

        if ($conference === null) {
            return [__('reviewer.errors.not_yours')];
        }

        if ($conference->review_mode !== ReviewMode::Assigned) {
            // In open pool every active reviewer already sees every abstract,
            // so an assignment row would change nothing and mislead the
            // coverage summary.
            $reasons[] = __('reviewer.assign.errors.open_pool');
        }

        if (! $submission->isReviewable()) {
            $reasons[] = __('reviewer.assign.errors.not_reviewable');
        }

        $eligible = $conference->reviewers()
            ->where('status', ReviewerStatus::Active->value)
            ->pluck('user_id')
            ->all();

        foreach ($reviewerUserIds as $id) {
            if (! in_array($id, $eligible, true)) {
                $reasons[] = __('reviewer.assign.errors.not_a_reviewer');
                break;
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  list<int>  $reviewerUserIds
     * @return array{added: int, removed: int}
     */
    public function handle(Submission $submission, array $reviewerUserIds, User $actor): array
    {
        $reasons = $this->blockers($submission, $reviewerUserIds);

        if ($reasons !== []) {
            throw new ReviewNotAcceptable($reasons);
        }

        $wanted = array_values(array_unique(array_map('intval', $reviewerUserIds)));

        return DB::transaction(function () use ($submission, $wanted, $actor): array {
            $current = $submission->reviewAssignments()->pluck('reviewer_user_id')->map('intval')->all();

            $toAdd = array_values(array_diff($wanted, $current));
            $toRemove = array_values(array_diff($current, $wanted));

            foreach ($toAdd as $id) {
                $assignment = new ReviewAssignment;
                $assignment->forceFill([
                    'submission_id' => $submission->getKey(),
                    'reviewer_user_id' => $id,
                    'assigned_by' => $actor->getKey(),
                    'assigned_at' => now(),
                ])->save();
            }

            if ($toRemove !== []) {
                // The reviews are deliberately untouched: a submitted review is
                // evidence that outlives its assignment, and a draft simply
                // leaves the queue until the same person is assigned again.
                $submission->reviewAssignments()->whereIn('reviewer_user_id', $toRemove)->delete();
            }

            if ($toAdd !== [] || $toRemove !== []) {
                // Spec section 9 names assignment changes as an audited event.
                activity()
                    ->performedOn($submission)
                    ->causedBy($actor)
                    ->withProperties(['added' => $toAdd, 'removed' => $toRemove])
                    ->log('review.assignments_changed');
            }

            return ['added' => count($toAdd), 'removed' => count($toRemove)];
        });
    }
}
```

There is no `UnassignReviewer` class. Removing one reviewer is deselecting them in the multi-select `assign` action and saving, which goes through `AssignReviewers::handle()` above — one code path, one `Gate::authorize()`, one activity entry, and the tests in Step 1 cover it. A separate per-row class would be a row-deleting method with no policy check and no conference check that nothing in the application calls, which is exactly the shape a later plan wires a row action to by accident. Task 13's backlog records what one would have to bring with it if Plan 5 wants it: the actor's role in the submission's organization checked before the delete, and a cross-conference negative test.

- [ ] **Step 4: The coverage summary on the model**

`app/Models/Conference.php` — after `submissionCounts()`:

```php
    /**
     * Spec 5.5: "a coverage summary (submissions with fewer than N reviewers)".
     *
     * One grouped query plus one count, rather than a loop over submissions:
     * spec section 10 budgets for 500 abstracts and this renders on every visit
     * to the assignments page.
     *
     * @return array{target: int, submissions: int, covered: int, under: int, unassigned: int}
     */
    public function assignmentCoverage(): array
    {
        $target = max(1, (int) $this->reviewers_per_submission);

        /** @var array<int, int> $counts assignment count keyed by submission id */
        $counts = $this->submissions()
            ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])
            ->withCount('reviewAssignments')
            ->pluck('review_assignments_count', 'id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $covered = 0;
        $unassigned = 0;

        foreach ($counts as $count) {
            if ($count >= $target) {
                $covered++;
            }

            if ($count === 0) {
                $unassigned++;
            }
        }

        $submissions = count($counts);

        return [
            'target' => $target,
            'submissions' => $submissions,
            'covered' => $covered,
            'under' => $submissions - $covered,
            'unassigned' => $unassigned,
        ];
    }
```

- [ ] **Step 5: The page**

`app/Filament/Organizer/Resources/Conferences/Pages/ConferenceAssignments.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Actions\Reviews\AssignReviewers;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Enums\SubmissionStatus;
use App\Exceptions\ReviewNotAcceptable;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Spec 5.5, the manual half.
 *
 * Unlike the Members and Reviewers pages, this table is **Eloquent-backed**:
 * it lists submissions, which are real rows and of which spec section 10
 * budgets for 500, so `Table::query()` gives sorting, searching, pagination and
 * a filter for free and the reviewer set is a loaded relation.
 */
class ConferenceAssignments extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ConferenceResource::class;

    protected string $view = 'filament.organizer.resources.conferences.pages.assignments';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 404);

        // Assignment has no meaning in open pool, and a bookmarked URL must not
        // survive a mode change.
        abort_unless($this->getConference()->review_mode === ReviewMode::Assigned, 404);
    }

    public function getTitle(): string
    {
        return __('reviewer.assign.title');
    }

    public function getSubheading(): ?string
    {
        $coverage = $this->getConference()->assignmentCoverage();

        return __('reviewer.assign.subheading', [
            'target' => $coverage['target'],
            'covered' => $coverage['covered'],
            'submissions' => $coverage['submissions'],
        ]);
    }

    public function getConference(): Conference
    {
        /** @var Conference $conference */
        $conference = $this->getRecord();

        return $conference;
    }

    /** @return array{target: int, submissions: int, covered: int, under: int, unassigned: int} */
    public function getCoverage(): array
    {
        return $this->getConference()->assignmentCoverage();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->getConference()->submissions()
                ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])
                ->with(['track', 'reviewAssignments.reviewer'])
                ->withCount('reviewAssignments'))
            ->defaultSort('reference')
            ->columns([
                TextColumn::make('reference')->label(__('reviewer.assign.columns.reference'))
                    ->fontFamily('mono')->searchable()->sortable()->placeholder('-'),
                TextColumn::make('title')->label(__('reviewer.assign.columns.title'))
                    ->searchable()->sortable()->wrap()->limit(70),
                TextColumn::make('track.name')->label(__('reviewer.assign.columns.track'))->placeholder('-')->toggleable(),
                TextColumn::make('review_assignments_count')->label(__('reviewer.assign.columns.count'))
                    ->badge()
                    ->sortable()
                    ->color(fn (Submission $record): string => $record->review_assignments_count >= $this->getCoverage()['target']
                        ? 'success'
                        : 'warning'),
                TextColumn::make('reviewers')->label(__('reviewer.assign.columns.reviewers'))
                    ->listWithLineBreaks()
                    // Not a relation column: the names come from two hops
                    // (assignment -> user) and the relation is already loaded.
                    ->state(fn (Submission $record): array => $record->reviewAssignments
                        ->map(fn (ReviewAssignment $assignment): string => (string) ($assignment->reviewer?->name ?? '-'))
                        ->all())
                    ->placeholder(__('reviewer.assign.none')),
            ])
            ->filters([
                Filter::make('under_target')
                    ->label(__('reviewer.assign.filters.under_target'))
                    // `has(..., '<', N)` compiles to
                    // `(select count(*) from review_assignments where
                    //   review_assignments.submission_id = submissions.id) < N`
                    // in the WHERE clause
                    // (QueriesRelationships::addWhereCountQuery; N === 1 becomes
                    // `where not exists`, which is the same rule).
                    //
                    // NOT `having()` over the withCount() alias: the query has
                    // no GROUP BY, and SQLite refuses a HAVING clause on a
                    // non-aggregate query outright - "SQLSTATE[HY000]: General
                    // error: 1 HAVING clause on a non-aggregate query" -
                    // whatever the expression is, `havingRaw` with a correlated
                    // sub-select included. MySQL happens to allow it, which is
                    // exactly the driver split this project must not ship. It
                    // would also have to survive the paginator's own count()
                    // query, which a WHERE predicate does and a HAVING does not.
                    ->query(fn (Builder $query): Builder => $query->has(
                        'reviewAssignments',
                        '<',
                        $this->getCoverage()['target'],
                    )),
            ])
            ->recordActions([
                $this->assignAction(),
            ])
            ->emptyStateHeading(__('reviewer.assign.empty_heading'))
            ->emptyStateDescription(__('reviewer.assign.empty_body'));
    }

    private function assignAction(): Action
    {
        return Action::make('assign')
            ->label(__('reviewer.assign.actions.assign'))
            ->icon(Heroicon::OutlinedUserGroup)
            ->visible(fn (): bool => Gate::allows('create', ReviewAssignment::class))
            ->modalHeading(fn (Submission $record): string => __('reviewer.assign.actions.assign_heading', [
                'reference' => (string) ($record->reference ?? $record->title),
            ]))
            ->fillForm(fn (Submission $record): array => [
                'reviewers' => $record->reviewAssignments->pluck('reviewer_user_id')->map('intval')->all(),
            ])
            ->schema([
                Select::make('reviewers')
                    ->label(__('reviewer.assign.fields.reviewers'))
                    ->multiple()
                    ->searchable()
                    // A plain option array built from this conference's active
                    // reviewers. `->relationship()` would reach every user on
                    // the platform, because there is no tenant scope on users.
                    ->options(fn (): array => $this->reviewerOptions())
                    ->helperText(__('reviewer.assign.fields.reviewers_help', [
                        'target' => $this->getCoverage()['target'],
                    ])),
            ])
            ->action(function (Submission $record, array $data, AssignReviewers $assign): void {
                Gate::authorize('create', ReviewAssignment::class);

                /** @var list<int> $ids */
                $ids = array_values(array_map('intval', (array) ($data['reviewers'] ?? [])));

                try {
                    $assign->handle($record, $ids, $this->actor());
                } catch (ReviewNotAcceptable $exception) {
                    Notification::make()->danger()
                        ->title(__('reviewer.notices.refused'))
                        ->body(e($exception->getMessage()))
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->success()->title(__('reviewer.assign.notices.saved'))->send();
            });
    }

    /**
     * The closure parameter is typed for the same reason as every other
     * closure in app/: Larastan level 6 runs MissingClosureParameterTypehintRule
     * and "Anonymous function has parameter $reviewer with no type specified"
     * fails Step 9's gate. `ConferenceReviewer` is also what makes
     * `$reviewer->user` resolve to `?User` rather than `mixed` here.
     *
     * @return array<int, string>
     */
    private function reviewerOptions(): array
    {
        /** @var array<int, string> $options */
        $options = $this->getConference()->reviewers()
            ->where('status', ReviewerStatus::Active->value)
            ->with('user')
            ->get()
            ->mapWithKeys(fn (ConferenceReviewer $reviewer): array => [
                (int) $reviewer->user_id => (string) ($reviewer->user?->name ?? $reviewer->user?->email ?? '-'),
            ])
            ->all();

        asort($options);

        return $options;
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToConference')
                ->label(__('reviewer.actions.back'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(fn (): string => ConferenceResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
```

`resources/views/filament/organizer/resources/conferences/pages/assignments.blade.php`
```blade
@php($coverage = $this->getCoverage())

<x-filament-panels::page>
    <div style="display:flex;flex-direction:column;gap:1.5rem">
        <x-filament::section :heading="__('reviewer.assign.coverage')">
            <div style="display:flex;gap:2rem;flex-wrap:wrap">
                <p style="font-size:0.875rem">{{ __('reviewer.assign.coverage_target', ['count' => $coverage['target']]) }}</p>
                <p style="font-size:0.875rem">{{ __('reviewer.assign.coverage_covered', ['count' => $coverage['covered'], 'total' => $coverage['submissions']]) }}</p>
                <p style="font-size:0.875rem">{{ __('reviewer.assign.coverage_under', ['count' => $coverage['under']]) }}</p>
                <p style="font-size:0.875rem">{{ __('reviewer.assign.coverage_unassigned', ['count' => $coverage['unassigned']]) }}</p>
            </div>
        </x-filament::section>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
```

- [ ] **Step 6: Register the page and the link**

`ConferenceResource::getPages()` gains, before `'edit'`:

```php
            'assignments' => ConferenceAssignments::route('/{record}/assignments'),
```

`ConferenceStatusActions` gains an action, offered only in assigned mode:

```php
    public static function assignments(): Action
    {
        return Action::make('assignments')
            ->label(__('reviewer.assign.page_link'))
            ->icon(Heroicon::OutlinedScale)
            ->color('gray')
            ->visible(fn (Conference $record): bool => $record->review_mode === ReviewMode::Assigned
                && Gate::allows('view', $record))
            ->url(fn (Conference $record): string => ConferenceResource::getUrl('assignments', ['record' => $record]));
    }
```

with `use App\Enums\ReviewMode;` added, and `all()` becoming:

```php
        return [static::share(), static::emails(), static::reviewers(), static::assignments(), static::publish(), static::close(), static::archive()];
```

- [ ] **Step 7: The language keys**

Append to `lang/en/reviewer.php`:

```php
    'assign' => [
        'page_link' => 'Assignments',
        'title' => 'Assignments',
        'subheading' => ':covered of :submissions abstracts have all :target reviewers.',
        'coverage' => 'Coverage',
        'coverage_target' => 'Target: :count reviewers per abstract',
        'coverage_covered' => 'Fully covered: :count of :total',
        'coverage_under' => 'Still short of reviewers: :count',
        'coverage_unassigned' => 'With no reviewer at all: :count',
        'none' => 'Nobody yet',
        'empty_heading' => 'No abstracts to assign',
        'empty_body' => 'Abstracts appear here once authors have submitted them.',
        'columns' => [
            'reference' => 'Reference',
            'title' => 'Title',
            'track' => 'Track',
            'count' => 'Reviewers',
            'reviewers' => 'Assigned to',
        ],
        'filters' => [
            'under_target' => 'Still short of reviewers',
        ],
        'fields' => [
            'reviewers' => 'Reviewers',
            'reviewers_help' => 'This conference aims for :target reviewers per abstract. Only reviewers who have accepted their invitation are listed.',
        ],
        'actions' => [
            'assign' => 'Assign',
            'assign_heading' => 'Who reviews :reference?',
        ],
        'notices' => [
            'saved' => 'Assignments saved',
        ],
        'errors' => [
            'open_pool' => 'This conference is in open pool mode, where every reviewer already sees every abstract. Switch it to assigned review first.',
            'not_reviewable' => 'Only a submitted abstract can be assigned.',
            'not_a_reviewer' => 'One of those people is not an active reviewer of this conference.',
        ],
    ],
```

- [ ] **Step 8: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferenceAssignmentsTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -6 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`; 11 passed in the first and `baseline + 146` in the second.

The `under_target` filter deliberately uses `has('reviewAssignments', '<', $target)` and not `having()`. SQLite refuses a HAVING clause on a query with no GROUP BY whatever the expression is — `SQLSTATE[HY000]: General error: 1 HAVING clause on a non-aggregate query` — and `havingRaw` with a correlated sub-select fails identically, verified on this machine's SQLite 3.53.2; the same predicate in WHERE returns the right rows. The filter also has to survive the paginator's own `count()` query, which a WHERE predicate does. If `filterTable('under_target')` fails, do **not** reach for `having()` or `havingRaw()`: the bug is somewhere else.

`it('does not open the assignments page of another organization conference')` asserts through `get(...)` only. `it('does not exist for an open pool conference')` keeps its `livewire(...)->assertNotFound()` line as well, and is the only place in this plan that may: there the record *resolves* — it is this tenant's own conference — and the 404 comes from `mount()`'s `abort_unless(… === ReviewMode::Assigned, 404)`, a `NotFoundHttpException`, which Livewire's `RequestBroker` excepts and hands to the real handler. A record the tenant-scoped query excludes throws `ModelNotFoundException` instead, which the harness rethrows, so those cases must go through a real request.

- [ ] **Step 9: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(organizer): per-submission reviewer assignment and a coverage summary

AssignReviewers sets the whole reviewer set of one abstract rather than adding
and removing, so saving the same set twice writes nothing. Unassigning keeps
both a draft and a submitted review: a draft simply leaves the queue.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 9: Balanced auto-assign, with conflicts and a preview

Spec 5.5, word for word: "for each submission pick the N reviewers with the fewest assignments, skipping reviewers whose affiliation matches any author affiliation (case-insensitive) or whose email domain matches an author's email domain. Result is shown for confirmation before saving."

**Deterministic without a seed.** The brief asked for "deterministic given a seed for tests". A total ordering is strictly better and is what this builds: submissions are walked in `id` order, and candidate reviewers are sorted by `(current load asc, conference_reviewers.id asc)`. There is no tie to break randomly, so the same input always produces the same plan — in a test, in a preview, and again when the organizer presses Save. A seed would only be needed if the tie-break were random, and a random tie-break in a *preview-then-confirm* flow means the plan shown is not the plan saved.

**Two methods, because the spec asks for a confirmation step.** `plan()` is pure — it reads and returns an `AssignmentPlan` and writes nothing — and `apply()` takes that plan and saves it through `AssignReviewers`, so the audit trail and the eligibility checks of Task 8 are the same ones. The modal shows `plan()`'s rows; pressing Save runs `plan()` **again** and applies that, because between the preview and the click a reviewer may have been removed, and applying a stale plan is how a removed reviewer gets assigned.

**The conflict rules, and the one refinement the spec needs.**

1. **Exact email match** between a reviewer and any author — always a conflict, on any domain.
2. **Email domain match** — a conflict, **except** for the free-mailbox domains listed in `config('cass.review.free_email_domains')` (added in Task 1). Applied literally in this region the spec's rule would skip almost everybody, because most authors and most reviewers submit from a free mailbox; "reviewer and author are both on gmail.com" is not a conflict of interest, it is an artefact. The list is configuration, not code, so an organizer support request is an environment change.
3. **Affiliation match**, case-insensitive, after collapsing whitespace and stripping punctuation — `conference_reviewers.affiliation` against `submission_authors.affiliation`. A reviewer with no affiliation never matches, which is why Task 4 asks the organizer for one.
4. **Organization membership is NOT a conflict** — decided here, and recorded. For a small society the programme chair reviews too, and treating membership as a conflict would empty the pool of exactly the people who know the field. The real conflict that membership sometimes proxies for — the reviewer works where the author works — is already caught by rules 2 and 3.

**Balance.** With no conflicts, walking submissions in id order and always taking the least-loaded reviewers gives loads that differ by at most one. With conflicts, exact balance can be impossible, so the guarantee is narrower and the tests say which is which: without conflicts, `max(load) - min(load) <= 1`; with conflicts, every assignment made is conflict-free and any submission that could not reach `reviewers_per_submission` is named in `AssignmentPlan::$shortfalls` rather than silently left short.

**The contract:**

| Class | Signature |
|---|---|
| `App\Support\Reviews\AssignmentPlan` | readonly: `list<array{submission_id: int, label: string, add: list<int>, names: list<string>}> $rows`, `list<string> $shortfalls`, `int $assignments`, `array<int, int> $loads` |
| `AutoAssignReviewers` | `plan(Conference $conference): AssignmentPlan` |
| `AutoAssignReviewers` | `apply(Conference $conference, User $actor): AssignmentPlan` |
| `AutoAssignReviewers` | `static conflicts(ConferenceReviewer $reviewer, Submission $submission): bool` |

**Files:**
- Create: `app/Support/Reviews/AssignmentPlan.php`, `app/Actions/Reviews/AutoAssignReviewers.php`
- Modify: `app/Filament/Organizer/Resources/Conferences/Pages/ConferenceAssignments.php`, `lang/en/reviewer.php`
- Test: `tests/Unit/AutoAssignReviewersTest.php`, `tests/Feature/Organizer/ConferenceAssignmentsTest.php` (two cases appended)

- [ ] **Step 1: Write the failing tests**

`tests/Unit/AutoAssignReviewersTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Reviews\AutoAssignReviewers;
use App\Enums\ConferenceStatus;
use App\Enums\ReviewMode;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->conference = Conference::factory()->create([
        'status' => ConferenceStatus::Closed,
        'review_mode' => ReviewMode::Assigned,
        'reviewers_per_submission' => 2,
    ]);
    $this->actor = User::factory()->create();
});

/** @return list<ConferenceReviewer> */
function makeReviewers(Conference $conference, int $count, string $domain = 'reviewers.example'): array
{
    $reviewers = [];

    for ($i = 1; $i <= $count; $i++) {
        $user = User::factory()->create([
            'name' => 'Reviewer '.$i,
            'email' => 'reviewer'.$i.'@'.$domain,
        ]);
        $reviewers[] = ConferenceReviewer::factory()->for($conference)->create(['user_id' => $user->id]);
    }

    return $reviewers;
}

/** @return list<Submission> */
function makeSubmissions(Conference $conference, int $count): array
{
    $submissions = [];

    for ($i = 1; $i <= $count; $i++) {
        $submission = Submission::factory()->for($conference)->submitted()->create(['title' => 'Abstract '.$i]);
        // Pin the reference. AssignmentPlan labels a row and a shortfall with
        // `$submission->reference ?? $submission->title`, and
        // SubmissionFactory::submitted() always sets a random reference
        // (database/factories/SubmissionFactory.php:44-50) - so without this,
        // every assertion on "Abstract 1" or on a title is asserting against a
        // string nobody ever prints.
        $submission->forceFill(['reference' => 'AAM26-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)])->save();
        SubmissionAuthor::factory()->for($submission)->corresponding()->create([
            'name' => 'Author '.$i,
            'email' => 'author'.$i.'@authors.example',
            'affiliation' => 'Authors Hospital',
            'sort' => 1,
        ]);
        $submissions[] = $submission->refresh();
    }

    return $submissions;
}

it('gives every abstract its target and keeps the loads within one of each other', function () {
    makeReviewers($this->conference, 3);
    makeSubmissions($this->conference, 6);

    $plan = app(AutoAssignReviewers::class)->apply($this->conference, $this->actor);

    // 6 abstracts x 2 reviewers = 12 assignments over 3 reviewers = 4 each.
    expect($plan->assignments)->toBe(12)
        ->and($plan->shortfalls)->toBe([])
        ->and(max($plan->loads))->toBe(4)
        ->and(min($plan->loads))->toBe(4)
        ->and(ReviewAssignment::query()->count())->toBe(12);

    foreach ($this->conference->submissions as $submission) {
        expect($submission->reviewAssignments()->count())->toBe(2)
            // Never the same reviewer twice on one abstract.
            ->and($submission->reviewAssignments()->distinct('reviewer_user_id')->count('reviewer_user_id'))->toBe(2);
    }
});

it('produces the same plan every time it is asked', function () {
    makeReviewers($this->conference, 4);
    makeSubmissions($this->conference, 5);

    $action = app(AutoAssignReviewers::class);

    $first = $action->plan($this->conference);
    $second = $action->plan($this->conference);

    expect(array_column($first->rows, 'add'))->toBe(array_column($second->rows, 'add'))
        // plan() is pure: asking twice must not have written anything.
        ->and(ReviewAssignment::query()->count())->toBe(0);
});

it('counts the assignments an organizer already made by hand and tops up around them', function () {
    $reviewers = makeReviewers($this->conference, 3);
    $submissions = makeSubmissions($this->conference, 3);

    ReviewAssignment::factory()->for($submissions[0])->create(['reviewer_user_id' => $reviewers[0]->user_id]);
    ReviewAssignment::factory()->for($submissions[1])->create(['reviewer_user_id' => $reviewers[0]->user_id]);

    $plan = app(AutoAssignReviewers::class)->apply($this->conference, $this->actor);

    // Reviewer 1 starts two ahead, so the balance work goes to the other two.
    expect($plan->loads[$reviewers[0]->user_id])->toBe(2)
        ->and(ReviewAssignment::query()->count())->toBe(6);

    foreach ($submissions as $submission) {
        expect($submission->reviewAssignments()->count())->toBe(2);
    }
});

it('skips a reviewer whose email domain matches an author, and does not skip a free mailbox', function () {
    $institutional = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['email' => 'omar@authors.example'])->id,
    ]);
    $gmailReviewer = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['email' => 'sara@gmail.com'])->id,
    ]);
    $clean = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['email' => 'noor@reviewers.example'])->id,
    ]);

    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    SubmissionAuthor::factory()->for($submission)->corresponding()->create([
        'email' => 'author@authors.example', 'affiliation' => null, 'sort' => 1,
    ]);
    SubmissionAuthor::factory()->for($submission)->create([
        'email' => 'coauthor@gmail.com', 'affiliation' => null, 'sort' => 2,
    ]);

    expect(AutoAssignReviewers::conflicts($institutional, $submission))->toBeTrue()
        // "both on gmail" is an artefact of free mailboxes, not a conflict -
        // see config('cass.review.free_email_domains').
        ->and(AutoAssignReviewers::conflicts($gmailReviewer, $submission))->toBeFalse()
        ->and(AutoAssignReviewers::conflicts($clean, $submission))->toBeFalse();

    $plan = app(AutoAssignReviewers::class)->apply($this->conference, $this->actor);

    expect($submission->reviewAssignments()->pluck('reviewer_user_id')->sort()->values()->all())
        ->toBe(collect([$gmailReviewer->user_id, $clean->user_id])->sort()->values()->all());
});

it('skips an exact email match even on a free mailbox', function () {
    $sameAddress = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['email' => 'author@gmail.com'])->id,
    ]);

    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    SubmissionAuthor::factory()->for($submission)->corresponding()->create([
        'email' => 'Author@Gmail.com', 'affiliation' => null, 'sort' => 1,
    ]);

    expect(AutoAssignReviewers::conflicts($sameAddress, $submission))->toBeTrue();
});

it('skips a reviewer whose affiliation matches an author, whatever the punctuation', function () {
    $sameHospital = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['email' => 'omar@reviewers.example'])->id,
        'affiliation' => '  king faisal specialist hospital ',
    ]);
    $elsewhere = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['email' => 'sara@reviewers.example'])->id,
        'affiliation' => 'Somewhere Else',
    ]);
    $unknown = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['email' => 'noor@reviewers.example'])->id,
        'affiliation' => null,
    ]);

    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    SubmissionAuthor::factory()->for($submission)->corresponding()->create([
        'email' => 'author@authors.example',
        'affiliation' => 'King Faisal Specialist Hospital.',
        'sort' => 1,
    ]);

    expect(AutoAssignReviewers::conflicts($sameHospital, $submission))->toBeTrue()
        ->and(AutoAssignReviewers::conflicts($elsewhere, $submission))->toBeFalse()
        // No affiliation on file never matches: that is why Task 4 asks for one.
        ->and(AutoAssignReviewers::conflicts($unknown, $submission))->toBeFalse();
});

it('does not treat organization membership as a conflict', function () {
    // Decided: for a small society the programme chair reviews too, and
    // treating membership as a conflict empties the pool of the people who know
    // the field. The real conflict membership sometimes proxies for - working
    // where the author works - is caught by the domain and affiliation rules.
    $member = User::factory()->create(['email' => 'chair@reviewers.example']);
    $this->conference->organization->addMember($member, App\Enums\OrganizationRole::Owner);
    $reviewer = ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $member->id]);

    $submission = makeSubmissions($this->conference, 1)[0];

    expect(AutoAssignReviewers::conflicts($reviewer, $submission))->toBeFalse();
});

it('names every abstract it could not fill instead of leaving it short in silence', function () {
    $this->conference->forceFill(['reviewers_per_submission' => 3])->save();
    makeReviewers($this->conference, 2);
    $submissions = makeSubmissions($this->conference, 2);

    $plan = app(AutoAssignReviewers::class)->apply($this->conference->fresh(), $this->actor);

    expect($plan->shortfalls)->toHaveCount(2)
        // By reference, not title: AssignmentPlan labels a shortfall
        // `$submission->reference ?? $submission->title`, and makeSubmissions()
        // pins the reference for exactly this assertion.
        ->and(implode(' ', $plan->shortfalls))->toContain('AAM26-001')
        ->and($submissions[0]->reviewAssignments()->count())->toBe(2);
});

it('plans nothing at all when there are no reviewers', function () {
    makeSubmissions($this->conference, 2);

    $plan = app(AutoAssignReviewers::class)->plan($this->conference);

    expect($plan->assignments)->toBe(0)
        ->and($plan->rows)->toBe([])
        ->and($plan->shortfalls)->toHaveCount(2);
});

it('ignores drafts, withdrawals and removed reviewers', function () {
    $active = makeReviewers($this->conference, 1)[0];
    ConferenceReviewer::factory()->for($this->conference)->removed()->create();

    $submitted = makeSubmissions($this->conference, 1)[0];
    Submission::factory()->for($this->conference)->create(['title' => 'Draft']);
    Submission::factory()->for($this->conference)->withdrawn()->create(['title' => 'Withdrawn']);

    $plan = app(AutoAssignReviewers::class)->apply($this->conference, $this->actor);

    expect($plan->assignments)->toBe(1)
        ->and(ReviewAssignment::query()->pluck('submission_id')->all())->toBe([$submitted->id])
        ->and(ReviewAssignment::query()->pluck('reviewer_user_id')->all())->toBe([$active->user_id]);
});

it('writes one activity entry per submission it changed', function () {
    makeReviewers($this->conference, 2);
    makeSubmissions($this->conference, 3);

    app(AutoAssignReviewers::class)->apply($this->conference, $this->actor);

    expect(Activity::query()->where('description', 'review.assignments_changed')->count())->toBe(3)
        ->and(Activity::query()->where('description', 'review.auto_assigned')->count())->toBe(1);
});
```

Append to `tests/Feature/Organizer/ConferenceAssignmentsTest.php`:

```php
it('previews the auto-assign plan before saving it', function () {
    $second = Submission::factory()->for($this->conference)->submitted()->create(['title' => 'Second abstract']);
    // The preview labels a row by reference when there is one, and
    // SubmissionFactory::submitted() always sets a random one, so pin it.
    $second->forceFill(['reference' => 'AAM26-018'])->save();

    assignmentsPage($this->conference)
        ->mountAction('autoAssign')
        // assertMountedActionModalSee, not assertSee: after mountAction() a
        // plain assertSee() reads the html from before the action mounted, and
        // the preview table lives in the modal partial
        // (TestsActions.php:512).
        ->assertMountedActionModalSee('Dr Omar Khan')
        ->assertMountedActionModalSee('Dr Sara Nasser')
        ->assertMountedActionModalSee((string) $second->fresh()?->reference);

    // Mounting is a preview and writes nothing.
    expect(ReviewAssignment::query()->count())->toBe(0);

    assignmentsPage($this->conference)
        ->callAction('autoAssign')
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($this->submission->reviewAssignments()->count())->toBe(2)
        ->and($second->reviewAssignments()->count())->toBe(2);
});

it('re-plans on save, so a reviewer removed since the preview is not assigned', function () {
    $page = assignmentsPage($this->conference)->mountAction('autoAssign');

    App\Models\ConferenceReviewer::query()->where('user_id', $this->sara->id)->firstOrFail()
        ->forceFill(['status' => App\Enums\ReviewerStatus::Removed, 'removed_at' => now()])->save();

    $page->callMountedAction();

    expect($this->submission->reviewAssignments()->pluck('reviewer_user_id')->all())->toBe([$this->omar->id]);
});
```

- [ ] **Step 2: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/AutoAssignReviewersTest.php > /tmp/t.log 2>&1; echo "rc=$?"; head -20 /tmp/t.log
```

Expected: `rc=1`, `Class "App\Actions\Reviews\AutoAssignReviewers" not found`.

- [ ] **Step 3: The plan value object**

`app/Support/Reviews/AssignmentPlan.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Reviews;

/**
 * The result of AutoAssignReviewers::plan(). Spec 5.5 requires the result to be
 * "shown for confirmation before saving", so this exists to be rendered: rows
 * carry the names, not only the ids, and a submission that could not reach its
 * target is a sentence rather than a silence.
 */
final readonly class AssignmentPlan
{
    /**
     * @param  list<array{submission_id: int, label: string, add: list<int>, names: list<string>}>  $rows
     * @param  list<string>  $shortfalls  one sentence per submission left short of the target
     * @param  array<int, int>  $loads  final assignment count per reviewer user id
     */
    public function __construct(
        public array $rows,
        public array $shortfalls,
        public int $assignments,
        public array $loads,
    ) {}

    public function isEmpty(): bool
    {
        return $this->assignments === 0;
    }
}
```

- [ ] **Step 4: The action**

`app/Actions/Reviews/AutoAssignReviewers.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewerStatus;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\User;
use App\Support\Reviews\AssignmentPlan;
use Illuminate\Support\Facades\DB;

/**
 * Spec 5.5's balanced auto-assign.
 *
 * **Deterministic without a seed.** Submissions are walked in `id` order and
 * candidates are sorted by `(current load asc, conference_reviewers.id asc)`,
 * so there is no tie left to break randomly. That matters more than it sounds:
 * the spec asks for the plan to be shown before it is saved, and a random
 * tie-break would mean the plan shown is not the plan saved.
 *
 * `plan()` writes nothing; `apply()` re-plans and saves through AssignReviewers
 * so the eligibility checks and the audit entries are the same ones a manual
 * assignment gets. Re-planning on save is deliberate: between the preview and
 * the click a reviewer may have been removed.
 */
class AutoAssignReviewers
{
    public function __construct(private readonly AssignReviewers $assignReviewers) {}

    public function plan(Conference $conference): AssignmentPlan
    {
        $target = max(1, (int) $conference->reviewers_per_submission);

        /** @var \Illuminate\Database\Eloquent\Collection<int, ConferenceReviewer> $reviewers */
        $reviewers = $conference->reviewers()
            ->where('status', ReviewerStatus::Active->value)
            ->with('user')
            ->orderBy('id')
            ->get();

        /** @var \Illuminate\Database\Eloquent\Collection<int, Submission> $submissions */
        $submissions = $conference->submissions()
            ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])
            ->with(['authors', 'reviewAssignments'])
            ->orderBy('id')
            ->get();

        // Current load per reviewer, across this conference only: a reviewer's
        // work on another conference is not this conference's business.
        $loads = [];

        foreach ($reviewers as $reviewer) {
            $loads[(int) $reviewer->user_id] = 0;
        }

        foreach ($submissions as $submission) {
            foreach ($submission->reviewAssignments as $assignment) {
                $id = (int) $assignment->reviewer_user_id;

                if (array_key_exists($id, $loads)) {
                    $loads[$id]++;
                }
            }
        }

        $rows = [];
        $shortfalls = [];
        $total = 0;

        foreach ($submissions as $submission) {
            $already = $submission->reviewAssignments->pluck('reviewer_user_id')->map('intval')->all();
            $need = $target - count($already);

            if ($need <= 0) {
                continue;
            }

            $candidates = $reviewers
                ->reject(fn (ConferenceReviewer $reviewer): bool => in_array((int) $reviewer->user_id, $already, true))
                ->reject(fn (ConferenceReviewer $reviewer): bool => self::conflicts($reviewer, $submission))
                ->sortBy([
                    fn (ConferenceReviewer $a, ConferenceReviewer $b): int => $loads[(int) $a->user_id] <=> $loads[(int) $b->user_id],
                    // The total ordering that removes the tie, and with it the
                    // need for a seed.
                    fn (ConferenceReviewer $a, ConferenceReviewer $b): int => (int) $a->getKey() <=> (int) $b->getKey(),
                ])
                ->take($need)
                ->values();

            $add = $candidates->map(fn (ConferenceReviewer $reviewer): int => (int) $reviewer->user_id)->all();

            foreach ($add as $id) {
                $loads[$id]++;
            }

            $total += count($add);

            if ($add !== []) {
                $rows[] = [
                    'submission_id' => (int) $submission->getKey(),
                    'label' => (string) ($submission->reference ?? $submission->title),
                    'add' => $add,
                    'names' => $candidates
                        ->map(fn (ConferenceReviewer $reviewer): string => (string) ($reviewer->user?->name ?? $reviewer->user?->email ?? '-'))
                        ->all(),
                ];
            }

            if (count($add) < $need) {
                $shortfalls[] = __('reviewer.assign.shortfall', [
                    'label' => (string) ($submission->reference ?? $submission->title),
                    'have' => count($already) + count($add),
                    'target' => $target,
                ]);
            }
        }

        return new AssignmentPlan($rows, $shortfalls, $total, $loads);
    }

    /**
     * Re-plans and saves. Every write goes through AssignReviewers, so the
     * eligibility checks and the `review.assignments_changed` entries are
     * identical to a manual assignment, and one extra entry records that this
     * batch was automatic.
     */
    public function apply(Conference $conference, User $actor): AssignmentPlan
    {
        $plan = $this->plan($conference);

        if ($plan->isEmpty()) {
            return $plan;
        }

        DB::transaction(function () use ($conference, $plan, $actor): void {
            foreach ($plan->rows as $row) {
                /** @var Submission $submission */
                $submission = $conference->submissions()->whereKey($row['submission_id'])->firstOrFail();

                $keep = $submission->reviewAssignments()->pluck('reviewer_user_id')->map('intval')->all();

                // The union, not the plan alone: auto-assign tops up, it never
                // takes away an assignment an organizer made by hand.
                $this->assignReviewers->handle(
                    $submission,
                    array_values(array_unique([...$keep, ...$row['add']])),
                    $actor,
                );
            }

            activity()
                ->performedOn($conference)
                ->causedBy($actor)
                ->withProperties([
                    'assignments' => $plan->assignments,
                    'submissions' => count($plan->rows),
                    'shortfalls' => count($plan->shortfalls),
                ])
                ->log('review.auto_assigned');
        });

        return $plan;
    }

    /**
     * Spec 5.5's conflict rule, plus the one refinement it needs to be usable:
     *
     *  - an exact email match is always a conflict;
     *  - a shared email *domain* is a conflict unless the domain is a free
     *    mailbox (config('cass.review.free_email_domains')) - most authors and
     *    most reviewers here submit from one, and "both on gmail" is an
     *    artefact rather than a conflict of interest;
     *  - a shared affiliation is a conflict, compared case-insensitively after
     *    collapsing whitespace and dropping punctuation;
     *  - organization membership is deliberately **not** a conflict.
     *
     * Static so the tests can ask it one reviewer and one submission at a time
     * and so the rule has exactly one home.
     */
    public static function conflicts(ConferenceReviewer $reviewer, Submission $submission): bool
    {
        $email = mb_strtolower(trim((string) $reviewer->user?->email));
        $domain = self::domain($email);
        $affiliation = self::normalise((string) $reviewer->affiliation);

        $free = array_map('mb_strtolower', (array) config('cass.review.free_email_domains', []));

        /** @var SubmissionAuthor $author */
        foreach ($submission->authors as $author) {
            $authorEmail = mb_strtolower(trim((string) $author->email));

            if ($email !== '' && $email === $authorEmail) {
                return true;
            }

            $authorDomain = self::domain($authorEmail);

            if ($domain !== '' && $domain === $authorDomain && ! in_array($domain, $free, true)) {
                return true;
            }

            if ($affiliation !== '' && $affiliation === self::normalise((string) $author->affiliation)) {
                return true;
            }
        }

        return false;
    }

    private static function domain(string $email): string
    {
        $at = mb_strrpos($email, '@');

        return $at === false ? '' : mb_substr($email, $at + 1);
    }

    /**
     * "King Faisal Specialist Hospital." and "  king faisal specialist hospital "
     * are the same institution. Anything more clever - abbreviations, "Dept of"
     * prefixes - is guesswork, and guessing wrong here means an abstract nobody
     * reviews.
     */
    private static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
```

- [ ] **Step 5: The bulk action on the assignments page**

`ConferenceAssignments::getHeaderActions()` gains, before `backToConference`:

```php
            Action::make('autoAssign')
                ->label(__('reviewer.assign.actions.auto'))
                ->icon(Heroicon::OutlinedSparkles)
                ->visible(fn (): bool => Gate::allows('create', ReviewAssignment::class))
                ->modalHeading(__('reviewer.assign.actions.auto_heading'))
                ->modalDescription(__('reviewer.assign.actions.auto_description'))
                ->modalSubmitActionLabel(__('reviewer.assign.actions.auto_confirm'))
                ->modalWidth('4xl')
                // Spec 5.5: "Result is shown for confirmation before saving."
                // A schema of read-only placeholders rather than a custom view,
                // so the preview lives next to the action that produces it.
                ->schema(fn (): array => [
                    Placeholder::make('plan')
                        ->hiddenLabel()
                        ->content(fn (): HtmlString => $this->previewHtml(app(AutoAssignReviewers::class)->plan($this->getConference()))),
                ])
                ->action(function (AutoAssignReviewers $auto): void {
                    Gate::authorize('create', ReviewAssignment::class);

                    // Re-plans rather than applying the preview: between the
                    // modal opening and this click a reviewer may have been
                    // removed, and applying a stale plan would assign them.
                    $plan = $auto->apply($this->getConference(), $this->actor());

                    Notification::make()
                        ->status($plan->assignments > 0 ? 'success' : 'warning')
                        ->title(__('reviewer.assign.notices.auto_done', ['count' => $plan->assignments]))
                        ->body($plan->shortfalls === [] ? null : e(implode(' ', array_slice($plan->shortfalls, 0, 8))))
                        // duration(), not persistent($condition): persistent()
                        // takes no arguments in Filament 5.8.1
                        // (notifications/src/Concerns/HasDuration.php:30), so
                        // the argument would be discarded and a clean run would
                        // leave a notification to dismiss by hand. 'persistent'
                        // is the duration sentinel.
                        ->duration($plan->shortfalls === [] ? 6000 : 'persistent')
                        ->send();
                }),
```

and the class gains one private method plus the imports `Filament\Forms\Components\Placeholder`, `Illuminate\Support\HtmlString`, `App\Actions\Reviews\AutoAssignReviewers`, `App\Support\Reviews\AssignmentPlan`:

```php
    /**
     * The preview table. Built as an HtmlString and escaped by hand because a
     * Filament Placeholder renders its content as HTML: every value here is a
     * title or a name somebody typed.
     */
    private function previewHtml(AssignmentPlan $plan): HtmlString
    {
        if ($plan->isEmpty()) {
            return new HtmlString('<p>'.e(__('reviewer.assign.preview_empty')).'</p>');
        }

        $rows = '';

        foreach ($plan->rows as $row) {
            $rows .= '<li><strong>'.e($row['label']).'</strong>: '.e(implode(', ', $row['names'])).'</li>';
        }

        $html = '<p>'.e(__('reviewer.assign.preview_summary', [
            'count' => $plan->assignments,
            'submissions' => count($plan->rows),
        ])).'</p><ul style="margin-top:0.5rem;display:flex;flex-direction:column;gap:0.25rem">'.$rows.'</ul>';

        if ($plan->shortfalls !== []) {
            $html .= '<p style="margin-top:0.75rem">'.e(implode(' ', $plan->shortfalls)).'</p>';
        }

        return new HtmlString($html);
    }
```

- [ ] **Step 6: The language keys**

Append inside `lang/en/reviewer.php`'s `assign` group:

```php
        'shortfall' => ':label has :have of :target reviewers; there was nobody left without a conflict.',
        'preview_empty' => 'Every abstract already has the reviewers it needs, so there is nothing to assign.',
        'preview_summary' => 'This will add :count assignments across :submissions abstracts.',
```

and inside its `actions` group:

```php
            'auto' => 'Auto-assign',
            'auto_heading' => 'Assign reviewers automatically',
            'auto_description' => 'Each abstract is given the reviewers with the fewest assignments so far, skipping anyone who shares an author\'s institution or email domain. Assignments you made by hand are kept.',
            'auto_confirm' => 'Save these assignments',
```

and inside its `notices` group:

```php
            'auto_done' => ':count assignments made',
```

- [ ] **Step 7: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/AutoAssignReviewersTest.php tests/Feature/Organizer/ConferenceAssignmentsTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -6 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`; about 24 passed in the first and `baseline + 159` in the second.

- [ ] **Step 8: Prove the balance on a bigger set than a test asserts**

Write `/tmp/balance.php`:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// A shape a real society produces: 120 abstracts, 9 reviewers, 3 each.
$conference = App\Models\Conference::factory()->create([
    'status' => App\Enums\ConferenceStatus::Closed,
    'review_mode' => App\Enums\ReviewMode::Assigned,
    'reviewers_per_submission' => 3,
]);

for ($i = 1; $i <= 9; $i++) {
    App\Models\ConferenceReviewer::factory()->for($conference)->create([
        'user_id' => App\Models\User::factory()->create(['email' => "r{$i}@reviewers.example"])->id,
    ]);
}

for ($i = 1; $i <= 120; $i++) {
    $submission = App\Models\Submission::factory()->for($conference)->submitted()->create();
    App\Models\SubmissionAuthor::factory()->for($submission)->corresponding()->create([
        'email' => "a{$i}@authors.example", 'affiliation' => 'Authors Hospital', 'sort' => 1,
    ]);
}

$started = microtime(true);
$plan = app(App\Actions\Reviews\AutoAssignReviewers::class)->apply($conference, App\Models\User::factory()->create());
$elapsed = round((microtime(true) - $started) * 1000);

echo 'assignments=', $plan->assignments, ' spread=', max($plan->loads) - min($plan->loads), ' ms=', $elapsed, PHP_EOL;
```

```bash
cd /c/Users/ahmed/Documents/CASS && DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan migrate:fresh --force >/dev/null 2>&1; \
php /tmp/balance.php
```

Expected: `assignments=360 spread=0` (120 × 3 over 9 reviewers is 40 each) and a time well under a second. A spread above 1 means the sort is not doing what this task claims; stop and fix it before committing. (Run this against the local SQLite file rather than `:memory:` if the fresh migrate is inconvenient — the numbers are the point, not the driver.)

- [ ] **Step 9: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(organizer): balanced auto-assign with conflict skipping and a preview

Deterministic without a seed: submissions in id order, candidates sorted by
(load, conference_reviewers.id), so no tie is broken randomly and the plan shown
is the plan saved. A shared free-mailbox domain is not treated as a conflict;
organization membership is not either, and both decisions are recorded.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 10: Reviewer reminders — hourly, idempotent, and one button

Spec 5.4 step 5: "the scheduler emails reviewers with outstanding work at 7, 3 and 1 days before the review deadline, and once after it passes. Organizers can trigger a manual reminder." The last two of Plan 3's three reviewer template keys are finally sent.

**Hourly, not daily at 07:00 — and why.** Spec section 10 gives every conference its own timezone, and a `Schedule` entry carries exactly one. A daily entry would have to pick a timezone and be wrong for everyone else, or become one entry per timezone. So the command runs **hourly**, and for each conference asks two questions in that conference's own zone: is it at or past the local send hour (`config('cass.reminders.send_hour')`, default 7), and is a threshold due that has not been sent? The `unique (conference_id, user_id, threshold)` index from Task 1 is what makes "has not been sent" a fact rather than a memory — a second run in the same hour, a worker restart, or a deploy in the middle of a send all resolve to the same answer. `->withoutOverlapping(55)` on the schedule entry keeps two runs from racing in the first place — 55 minutes rather than the 1440-minute default, so a container killed mid-run cannot leave a `cache_locks` row that suppresses every reminder for a day.

**The threshold that fires is the latest applicable one, not the exact one.** `ReminderSchedule::due()` answers `Days1` at one day or less, `Days3` at three or less, `Days7` at seven or less, and `Overdue` once the deadline has passed. A conference whose reviewers were invited five days before the deadline therefore gets the 7-day reminder five days out — late, but sent — instead of nothing at all because the exact day was missed. Three emails never arrive in one hour, because the first run writes a row for the threshold it sent and the next run asks for the *next* one down.

**Carbon 3 changed `diffInDays`** (fact 4): it is signed by default and returns a float. `ReminderSchedule` passes `absolute: false` explicitly and rounds, and `tests/Unit/ReminderScheduleTest.php` pins the value at every boundary and across a DST change.

**The manual reminder does not write a `reviewer_reminders` row.** That table's unique key exists precisely to stop repeats, and a manual reminder is allowed to repeat; the throttle is `conferences.reviewer_reminded_at` and `config('cass.reminders.manual_throttle_hours')` (12 by default, so twice a day). Every manual send still lands in `email_logs`, because it goes through `SendTemplatedEmail` like everything else.

**The contract:**

| Class | Signature |
|---|---|
| `App\Support\Reviews\ReminderSchedule` | `static daysLeft(Conference $c, ?CarbonInterface $now = null): ?int` |
| `App\Support\Reviews\ReminderSchedule` | `static due(Conference $c, ?CarbonInterface $now = null): ?ReminderThreshold` |
| `App\Support\Reviews\ReminderSchedule` | `static isSendHour(Conference $c, ?CarbonInterface $now = null): bool` |
| `SendReviewerReminders` | `outstanding(Conference $c): Collection<int, ConferenceReviewer>` |
| `SendReviewerReminders` | `automatic(Conference $c): array{sent: int, threshold: ?ReminderThreshold}` |
| `SendReviewerReminders` | `manualBlockers(Conference $c): list<string>` and `manual(Conference $c, User $actor): int` |

**Files:**
- Create: `app/Support/Reviews/ReminderSchedule.php`, `app/Actions/Reviews/SendReviewerReminders.php`, `app/Console/Commands/SendReviewerRemindersCommand.php`
- Modify: `bootstrap/app.php`, `routes/console.php`, `app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php`, `app/Models/Conference.php` (one cast), `lang/en/reviewer.php`
- Test: `tests/Unit/ReminderScheduleTest.php`, `tests/Feature/ReviewerRemindersTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ReminderScheduleTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ReminderThreshold;
use App\Models\Conference;
use App\Support\Reviews\ReminderSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // 23:59 Riyadh on 3 November 2026 is 20:59 UTC. Every expectation below is
    // written in the conference's own timezone, which is the whole point.
    $this->conference = Conference::factory()->create([
        'timezone' => 'Asia/Riyadh',
        'review_deadline' => Carbon::parse('2026-11-03 20:59:00', 'UTC'),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('counts the days left in the conference timezone', function (string $nowUtc, int $expected) {
    Carbon::setTestNow(Carbon::parse($nowUtc, 'UTC'));

    expect(ReminderSchedule::daysLeft($this->conference))->toBe($expected);
})->with([
    // 08:00 UTC is 11:00 Riyadh, so the local day is the one named.
    ['2026-10-27 08:00:00', 7],
    ['2026-10-31 08:00:00', 3],
    ['2026-11-02 08:00:00', 1],
    ['2026-11-03 08:00:00', 0],
    ['2026-11-04 08:00:00', -1],
    // 22:00 UTC on 26 October is 01:00 Riyadh on 27 October: the local day
    // boundary, not the UTC one, is what decides.
    ['2026-10-26 22:00:00', 7],
]);

it('fires the latest applicable threshold and nothing before seven days out', function (string $nowUtc, ?ReminderThreshold $expected) {
    Carbon::setTestNow(Carbon::parse($nowUtc, 'UTC'));

    expect(ReminderSchedule::due($this->conference))->toBe($expected);
})->with([
    ['2026-10-20 08:00:00', null],
    ['2026-10-27 08:00:00', ReminderThreshold::Days7],
    // Five days out: the 7-day reminder, late, rather than nothing at all.
    ['2026-10-29 08:00:00', ReminderThreshold::Days7],
    ['2026-10-31 08:00:00', ReminderThreshold::Days3],
    ['2026-11-01 08:00:00', ReminderThreshold::Days3],
    ['2026-11-02 08:00:00', ReminderThreshold::Days1],
    ['2026-11-03 08:00:00', ReminderThreshold::Days1],
    // One minute past the deadline.
    ['2026-11-03 21:00:00', ReminderThreshold::Overdue],
    ['2026-11-10 08:00:00', ReminderThreshold::Overdue],
]);

it('answers nothing at all for a conference with no review deadline', function () {
    $this->conference->forceFill(['review_deadline' => null])->save();

    expect(ReminderSchedule::daysLeft($this->conference))->toBeNull()
        ->and(ReminderSchedule::due($this->conference))->toBeNull()
        ->and(ReminderSchedule::isSendHour($this->conference))->toBeFalse();
});

it('waits for the local send hour', function () {
    // 03:00 UTC is 06:00 Riyadh - before the 07:00 default.
    Carbon::setTestNow(Carbon::parse('2026-10-27 03:00:00', 'UTC'));
    expect(ReminderSchedule::isSendHour($this->conference))->toBeFalse();

    // 04:00 UTC is 07:00 Riyadh.
    Carbon::setTestNow(Carbon::parse('2026-10-27 04:00:00', 'UTC'));
    expect(ReminderSchedule::isSendHour($this->conference))->toBeTrue();

    // And every hour after it, so a run that was missed catches up the same day.
    Carbon::setTestNow(Carbon::parse('2026-10-27 19:00:00', 'UTC'));
    expect(ReminderSchedule::isSendHour($this->conference))->toBeTrue();
});

it('survives a daylight saving change in a zone that has one', function () {
    // Europe/London moves off BST at 02:00 on 25 October 2026. A day that is
    // 25 hours long must still count as one day.
    $conference = Conference::factory()->create([
        'timezone' => 'Europe/London',
        'review_deadline' => Carbon::parse('2026-10-26 12:00:00', 'UTC'),
    ]);

    Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00', 'UTC'));

    // Carbon 3 returns a float here and is signed by default (fact 4); rounding
    // is what keeps 1.958 from becoming 1.
    expect(ReminderSchedule::daysLeft($conference))->toBe(2);
});
```

`tests/Feature/ReviewerRemindersTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Reviews\SendReviewerReminders;
use App\Enums\ConferenceStatus;
use App\Enums\EmailTemplateKey;
use App\Enums\OrganizationRole;
use App\Enums\ReminderThreshold;
use App\Exceptions\ReviewNotAcceptable;
use App\Filament\Organizer\Resources\Conferences\Pages\ViewConference;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewerReminder;
use App\Models\Submission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Mail::fake();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->conference = Conference::factory()->for($this->organization)->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
        'timezone' => 'Asia/Riyadh',
        'review_deadline' => Carbon::parse('2026-11-03 20:59:00', 'UTC'),
    ]);

    $this->submission = Submission::factory()->for($this->conference)->submitted()->create();

    $this->behind = User::factory()->create(['name' => 'Dr Omar Khan', 'email' => 'omar@example.org']);
    $this->done = User::factory()->create(['name' => 'Dr Sara Nasser', 'email' => 'sara@example.org']);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->behind->id]);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->done->id]);

    Review::factory()->for($this->submission)->submitted()->create(['reviewer_user_id' => $this->done->id]);

    // 04:00 UTC on 27 October is 07:00 Riyadh, seven days out.
    Carbon::setTestNow(Carbon::parse('2026-10-27 04:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('names only the reviewers with outstanding work', function () {
    expect(app(SendReviewerReminders::class)->outstanding($this->conference)->pluck('user_id')->all())
        ->toBe([$this->behind->id]);
});

it('never reminds a removed reviewer or a reviewer of another conference', function () {
    // outstanding() is the ONLY gate on who gets emailed, and every other case
    // in this file feeds it two active reviewers of one conference - so without
    // this, dropping the `status = active` filter or the per-conference
    // constraint would email removed reviewers and strangers with a green suite.
    $removed = User::factory()->create(['email' => 'removed@example.org']);
    ConferenceReviewer::factory()->for($this->conference)->removed()->create(['user_id' => $removed->id]);

    $elsewhere = Conference::factory()->create([
        'status' => ConferenceStatus::Reviewing,
        'timezone' => 'Asia/Riyadh',
        'review_deadline' => Carbon::parse('2026-11-03 20:59:00', 'UTC'),
    ]);
    $theirReviewer = User::factory()->create(['email' => 'elsewhere@example.org']);
    ConferenceReviewer::factory()->for($elsewhere)->create(['user_id' => $theirReviewer->id]);
    Submission::factory()->for($elsewhere)->submitted()->create();

    expect(app(SendReviewerReminders::class)->outstanding($this->conference)->pluck('user_id')->all())
        ->toBe([$this->behind->id]);

    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Mail::assertNotQueued(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->hasTo('removed@example.org'),
    );

    // The other conference gets its own reminder, to its own reviewer, carrying
    // its own organization - never this one's.
    Mail::assertNotQueued(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->hasTo('elsewhere@example.org')
            && $mail->organization->is($this->organization),
    );
});

it('emails the seven-day reminder once and records that it did', function () {
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Mail::assertQueued(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->hasTo('omar@example.org')
            && $mail->templateKey === EmailTemplateKey::ReviewerReminder->value
            // {{review_link}} in a reminder is the reviewer's queue, not an
            // accept link (spec 5.4 step 5).
            && str_contains($mail->body, '/review'),
    );
    Mail::assertQueued(TemplatedMail::class, 1);

    expect(ReviewerReminder::query()->count())->toBe(1)
        ->and(ReviewerReminder::query()->first()?->threshold)->toBe(ReminderThreshold::Days7)
        ->and(EmailLog::query()->where('template_key', 'reviewer_reminder')->count())->toBe(1);

    // The hourly run is idempotent: the unique key is the memory.
    artisan('cass:reviewer-reminders')->assertExitCode(0);
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Mail::assertQueued(TemplatedMail::class, 1);
    expect(ReviewerReminder::query()->count())->toBe(1);
});

it('waits for the local send hour', function () {
    // 03:00 UTC is 06:00 Riyadh.
    Carbon::setTestNow(Carbon::parse('2026-10-27 03:00:00', 'UTC'));

    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Mail::assertNothingQueued();
    expect(ReviewerReminder::query()->count())->toBe(0);
});

it('walks down the thresholds one email at a time', function () {
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Carbon::setTestNow(Carbon::parse('2026-10-31 04:00:00', 'UTC'));
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Carbon::setTestNow(Carbon::parse('2026-11-02 04:00:00', 'UTC'));
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Carbon::setTestNow(Carbon::parse('2026-11-04 04:00:00', 'UTC'));
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    // orderBy('id'): no ORDER BY means insertion order only by luck, and MySQL -
    // which Task 13 makes the real gate - is under no obligation to return a
    // scan in primary-key order.
    expect(ReviewerReminder::query()->orderBy('id')->pluck('threshold')->map(fn ($t) => $t->value)->all())
        ->toBe(['days_7', 'days_3', 'days_1', 'overdue']);

    Mail::assertQueued(TemplatedMail::class, 4);
    Mail::assertQueued(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->templateKey === EmailTemplateKey::ReviewerOverdue->value,
    );
});

it('sends the overdue email once and then stops', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-04 04:00:00', 'UTC'));

    artisan('cass:reviewer-reminders')->assertExitCode(0);
    Carbon::setTestNow(Carbon::parse('2026-11-05 04:00:00', 'UTC'));
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Mail::assertQueued(TemplatedMail::class, 1);
    expect(ReviewerReminder::query()->count())->toBe(1);
});

it('stops reminding a reviewer who has finished', function () {
    Review::factory()->for($this->submission)->submitted()->create(['reviewer_user_id' => $this->behind->id]);

    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Mail::assertNothingQueued();
});

it('ignores a conference that is not in review, or has no deadline', function () {
    $this->conference->forceFill(['status' => ConferenceStatus::Closed])->save();
    artisan('cass:reviewer-reminders')->assertExitCode(0);
    Mail::assertNothingQueued();

    $this->conference->forceFill(['status' => ConferenceStatus::Reviewing, 'review_deadline' => null])->save();
    artisan('cass:reviewer-reminders')->assertExitCode(0);
    Mail::assertNothingQueued();
});

it('sends a manual reminder from the conference page and throttles the next one', function () {
    $owner = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);
    actingAs($owner);
    bootOrganizerPanel($this->organization);

    livewire(ViewConference::class, ['record' => $this->conference->getRouteKey()])
        ->callAction('remindReviewers')
        ->assertNotified();

    Mail::assertQueued(TemplatedMail::class, 1);

    expect($this->conference->fresh()?->reviewer_reminded_at)->not->toBeNull()
        // A manual send is allowed to repeat, so it writes no reviewer_reminders
        // row - that table's unique key exists to stop repeats.
        ->and(ReviewerReminder::query()->count())->toBe(0);

    livewire(ViewConference::class, ['record' => $this->conference->fresh()->getRouteKey()])
        ->assertActionHidden('remindReviewers');

    expect(app(SendReviewerReminders::class)->manualBlockers($this->conference->fresh()))->not->toBe([]);

    Carbon::setTestNow(now()->addHours((int) config('cass.reminders.manual_throttle_hours') + 1));

    expect(app(SendReviewerReminders::class)->manualBlockers($this->conference->fresh()))->toBe([]);
});

it('offers no manual reminder when nobody is behind', function () {
    Review::factory()->for($this->submission)->submitted()->create(['reviewer_user_id' => $this->behind->id]);

    $owner = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);
    actingAs($owner);
    bootOrganizerPanel($this->organization);

    expect(app(SendReviewerReminders::class)->manualBlockers($this->conference))->not->toBe([]);

    livewire(ViewConference::class, ['record' => $this->conference->getRouteKey()])
        ->assertActionHidden('remindReviewers');
});

it('skips rather than crashes when the reminder row already exists', function () {
    // Idempotency is the INSERT, not a read before it. A manual
    // `artisan cass:reviewer-reminders` running beside the scheduled one - or a
    // withoutOverlapping mutex that expired - has both runs pass a
    // read-then-write, and the second insert would raise an uncaught
    // QueryException that abandons the rest of the hourly pass. Every later
    // conference in that run would be silently skipped, which is the opposite
    // of what the unique key is for.
    App\Models\ReviewerReminder::factory()->create([
        'conference_id' => $this->conference->id,
        'user_id' => $this->behind->id,
        'threshold' => ReminderThreshold::Days7,
    ]);

    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Mail::assertNothingQueued();
    expect(ReviewerReminder::query()->count())->toBe(1);
});

it('does no per-reviewer work once every reviewer has the current threshold', function () {
    // `Overdue` stays due for ever once the deadline passes, and isSendHour() is
    // true for most of the day - so a conference left in `reviewing` would
    // otherwise rebuild its whole queue, one ReviewerScope exists() per active
    // reviewer, every hour for ever, and send nothing. One `whereNotExists`
    // decides who could still receive this threshold before any of that runs.
    //
    // The caught-up reviewer is removed first so that every reviewer who is left
    // ends the first run holding a row: that is the state the short-circuit is
    // for, and it is the state a conference settles into.
    ConferenceReviewer::query()->where('user_id', $this->done->id)->firstOrFail()
        ->forceFill(['status' => App\Enums\ReviewerStatus::Removed, 'removed_at' => now()])->save();

    Carbon::setTestNow(Carbon::parse('2026-11-04 04:00:00', 'UTC'));
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    expect(ReviewerReminder::query()->where('threshold', ReminderThreshold::Overdue->value)->count())->toBe(1);

    DB::enableQueryLog();
    Carbon::setTestNow(Carbon::parse('2026-11-04 05:00:00', 'UTC'));
    artisan('cass:reviewer-reminders')->assertExitCode(0);
    $second = DB::getQueryLog();
    DB::disableQueryLog();

    // The per-reviewer scan is the only thing in this command that selects from
    // `submissions` (through ReviewerScope); the candidate query does not.
    expect(collect($second)->filter(fn (array $query): bool => str_contains((string) $query['query'], 'from "submissions"')
        || str_contains((string) $query['query'], 'from `submissions`')))
        ->toBeEmpty();

    Mail::assertQueued(TemplatedMail::class, 1);
});

it('claims the manual window before sending, so two clicks send one batch', function () {
    // Read-then-send lets two overlapping requests both pass manualBlockers()
    // and both mail everybody. manual() claims the window with one conditional
    // UPDATE first - the same shape as the review-form lock - so the loser
    // refuses instead of amplifying by one email per outstanding reviewer.
    $owner = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);

    app(SendReviewerReminders::class)->manual($this->conference->fresh(), $owner);

    expect(fn () => app(SendReviewerReminders::class)->manual($this->conference->fresh(), $owner))
        ->toThrow(ReviewNotAcceptable::class);

    Mail::assertQueued(TemplatedMail::class, 1);
});

it('is registered on the schedule and as an artisan command', function () {
    expect(collect(Illuminate\Support\Facades\Artisan::all()))->toHaveKey('cass:reviewer-reminders');

    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command);

    expect($events->filter(fn (string $command): bool => str_contains($command, 'cass:reviewer-reminders')))
        ->not->toBeEmpty();
});
```

- [ ] **Step 2: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ReminderScheduleTest.php tests/Feature/ReviewerRemindersTest.php > /tmp/t.log 2>&1; echo "rc=$?"; head -20 /tmp/t.log
```

Expected: `rc=1`, `Class "App\Support\Reviews\ReminderSchedule" not found`.

- [ ] **Step 3: The schedule helper**

`app/Support/Reviews/ReminderSchedule.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\ReminderThreshold;
use App\Models\Conference;
use Carbon\CarbonInterface;

/**
 * Spec 5.4 step 5's thresholds, computed in the conference's own timezone
 * (spec section 10).
 *
 * Carbon 3 changed `diffInDays`: it is signed by default and returns a float
 * (fact 4). `absolute: false` is passed explicitly and the result is rounded,
 * because a day across a DST boundary is 23 or 25 hours long and would
 * otherwise floor to the day before.
 */
final class ReminderSchedule
{
    /** Whole local days from today to the deadline; negative once it has passed. */
    public static function daysLeft(Conference $conference, ?CarbonInterface $now = null): ?int
    {
        $deadline = $conference->review_deadline;

        if ($deadline === null) {
            return null;
        }

        $zone = (string) $conference->timezone;
        $today = ($now ?? now())->copy()->setTimezone($zone)->startOfDay();
        $due = $deadline->copy()->setTimezone($zone)->startOfDay();

        return (int) round($today->diffInDays($due, absolute: false));
    }

    /**
     * The threshold that applies *now*, or null.
     *
     * `<=` rather than `===` on purpose: a conference whose reviewers were
     * invited five days before the deadline gets the seven-day reminder five
     * days out - late, but sent - instead of nothing because the exact day was
     * missed. Three emails never land in one hour, because the caller writes a
     * reviewer_reminders row for whatever it sent and the next run asks again.
     */
    public static function due(Conference $conference, ?CarbonInterface $now = null): ?ReminderThreshold
    {
        $deadline = $conference->review_deadline;

        if ($deadline === null) {
            return null;
        }

        if ($deadline->isBefore($now ?? now())) {
            return ReminderThreshold::Overdue;
        }

        $days = self::daysLeft($conference, $now);

        return match (true) {
            $days === null => null,
            $days <= 1 => ReminderThreshold::Days1,
            $days <= 3 => ReminderThreshold::Days3,
            $days <= 7 => ReminderThreshold::Days7,
            default => null,
        };
    }

    /**
     * The command runs hourly (one Schedule entry cannot carry every
     * conference's timezone), so this is what makes it behave like "daily at
     * 07:00 local": the first run at or after the local send hour does the
     * work, and the reviewer_reminders unique key stops the rest of the day's
     * runs repeating it.
     */
    public static function isSendHour(Conference $conference, ?CarbonInterface $now = null): bool
    {
        if ($conference->review_deadline === null) {
            return false;
        }

        $hour = (int) ($now ?? now())->copy()->setTimezone((string) $conference->timezone)->hour;

        return $hour >= (int) config('cass.reminders.send_hour');
    }
}
```

- [ ] **Step 4: The action**

`app/Actions/Reviews/SendReviewerReminders.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Actions\Mail\SendTemplatedEmail;
use App\Actions\Reviewers\InviteReviewer;
use App\Enums\ConferenceStatus;
use App\Enums\ReminderThreshold;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewStatus;
use App\Filament\Reviewer\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\ReviewerReminder;
use App\Models\User;
use App\Exceptions\ReviewNotAcceptable;
use App\Support\Reviews\ReminderSchedule;
use App\Support\Reviews\ReviewerScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Spec 5.4 step 5, for one conference at a time. The command in
 * app/Console/Commands loops conferences and does nothing else.
 */
class SendReviewerReminders
{
    public function __construct(private readonly SendTemplatedEmail $sendTemplatedEmail) {}

    /**
     * Active reviewers of this conference with at least one abstract in their
     * queue that they have not submitted a review for.
     *
     * One `exists` query per reviewer rather than one clever join: the queue
     * definition is ReviewerScope's and nothing else may restate it, and a
     * conference with fifty reviewers costs fifty cheap queries once an hour.
     *
     * @return Collection<int, ConferenceReviewer>
     */
    public function outstanding(Conference $conference): Collection
    {
        return $this->behind($conference, $conference->reviewers()
            ->where('status', ReviewerStatus::Active->value)
            ->with('user')
            ->orderBy('id')
            ->get());
    }

    /**
     * The filter half of outstanding(), over a set of reviewers somebody else
     * chose. Split out so automatic() can narrow the candidates in SQL first and
     * still ask exactly this question of the survivors - one definition of
     * "behind", not two.
     *
     * @param  Collection<int, ConferenceReviewer>  $reviewers
     * @return Collection<int, ConferenceReviewer>
     */
    private function behind(Conference $conference, Collection $reviewers): Collection
    {
        /** @var Collection<int, ConferenceReviewer> $result */
        $result = $reviewers->filter(function (ConferenceReviewer $reviewer) use ($conference): bool {
            $user = $reviewer->user;

            if (! $user instanceof User) {
                return false;
            }

            return ReviewerScope::submissions($user, $conference)
                ->whereDoesntHave('reviews', fn (Builder $reviews): Builder => $reviews
                    ->where('reviewer_user_id', $user->getKey())
                    ->where('status', ReviewStatus::Submitted->value))
                ->exists();
        })->values();

        return $result;
    }

    /**
     * The hourly pass for one conference.
     *
     * @return array{sent: int, threshold: ReminderThreshold|null}
     */
    public function automatic(Conference $conference): array
    {
        if ($conference->status !== ConferenceStatus::Reviewing) {
            return ['sent' => 0, 'threshold' => null];
        }

        if (! ReminderSchedule::isSendHour($conference)) {
            return ['sent' => 0, 'threshold' => null];
        }

        $threshold = ReminderSchedule::due($conference);

        if ($threshold === null) {
            return ['sent' => 0, 'threshold' => null];
        }

        // Ask the database who could still receive THIS threshold before
        // building any queue. `Overdue` stays due for ever once the deadline
        // passes (ReminderSchedule::due) and isSendHour() is true for most of
        // the day, so a conference left in `reviewing` would otherwise rebuild
        // its whole queue - one exists() per active reviewer, through
        // ReviewerScope - every hour, for ever, and send nothing. One
        // `whereNotExists` costs one query and usually answers "nobody".
        $candidates = $conference->reviewers()
            ->where('status', ReviewerStatus::Active->value)
            ->whereNotExists(fn (QueryBuilder $already): QueryBuilder => $already
                ->selectRaw('1')
                ->from('reviewer_reminders')
                ->whereColumn('reviewer_reminders.user_id', 'conference_reviewers.user_id')
                ->where('reviewer_reminders.conference_id', $conference->getKey())
                ->where('reviewer_reminders.threshold', $threshold->value))
            ->with('user')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return ['sent' => 0, 'threshold' => $threshold];
        }

        $sent = 0;

        foreach ($this->behind($conference, $candidates) as $reviewer) {
            $user = $reviewer->user;

            if (! $user instanceof User) {
                continue;
            }

            try {
                DB::transaction(function () use ($conference, $user, $threshold): void {
                    // The INSERT is the decision, not a read before it. Two
                    // overlapping runs - a manual `artisan cass:reviewer-reminders`
                    // beside the scheduled one, or a withoutOverlapping mutex
                    // that expired - both pass a read-then-write, and the second
                    // insert would raise an uncaught QueryException that
                    // abandons the whole pass mid-loop, silently skipping every
                    // later conference. The unique key on
                    // (conference_id, user_id, threshold) is what makes "already
                    // sent" a fact, so let it answer.
                    $reminder = new ReviewerReminder;
                    $reminder->forceFill([
                        'conference_id' => $conference->getKey(),
                        'user_id' => $user->getKey(),
                        'threshold' => $threshold,
                        'sent_at' => now(),
                    ])->save();

                    $this->send($conference, $user, $threshold);
                });
            } catch (UniqueConstraintViolationException) {
                // Another run got there first. Nothing to send, nothing wrong.
                continue;
            }

            $sent++;
        }

        return ['sent' => $sent, 'threshold' => $threshold];
    }

    /** @return list<string> empty when the organizer may send one now */
    public function manualBlockers(Conference $conference): array
    {
        $reasons = [];

        if ($conference->status !== ConferenceStatus::Reviewing) {
            $reasons[] = __('reviewer.remind.errors.not_reviewing');
        }

        $last = $conference->reviewer_reminded_at;
        $hours = (int) config('cass.reminders.manual_throttle_hours');

        if ($last !== null && $last->copy()->addHours($hours)->isFuture()) {
            $reasons[] = __('reviewer.remind.errors.too_soon', ['hours' => $hours]);
        }

        // The queue scan below is one query per active reviewer, and this method
        // runs inside remindReviewers()'s visible() - which means on EVERY
        // render of ViewConference and EditConference, both of which spread
        // ConferenceStatusActions::all() into their header actions. A conference
        // in review with a hundred reviewers would cost a hundred extra queries
        // per page view. The two reasons above are constant time and already
        // hide the button, so only ask the expensive question when neither
        // applies.
        if ($reasons !== []) {
            return $reasons;
        }

        if ($this->outstanding($conference)->isEmpty()) {
            $reasons[] = __('reviewer.remind.errors.nobody_behind');
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Spec 5.4 step 5's "Organizers can trigger a manual reminder". Writes no
     * reviewer_reminders row: this one is allowed to repeat, and that table's
     * unique key exists to stop repeats. The throttle is on the conference.
     */
    public function manual(Conference $conference, User $actor): int
    {
        $reasons = $this->manualBlockers($conference);

        if ($reasons !== []) {
            throw new ReviewNotAcceptable($reasons);
        }

        $hours = (int) config('cass.reminders.manual_throttle_hours');

        // Claim the window BEFORE sending, not after. manualBlockers() is a
        // read, so two overlapping clicks both pass it and both mail every
        // outstanding reviewer - the twice-a-day cap the button promises would
        // be advisory, and the amplification is one email per outstanding
        // reviewer per extra click. One conditional UPDATE, one winner: the same
        // shape as ReviewForm::lockIfUnlocked().
        $claimed = Conference::query()
            ->whereKey($conference->getKey())
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('reviewer_reminded_at')
                ->orWhere('reviewer_reminded_at', '<=', now()->subHours($hours)))
            ->update(['reviewer_reminded_at' => now(), 'updated_at' => now()]) === 1;

        if (! $claimed) {
            throw new ReviewNotAcceptable([__('reviewer.remind.errors.too_soon', ['hours' => $hours])]);
        }

        // Keep the in-memory model in step with the row this just wrote, without
        // writing it a second time.
        $conference->forceFill(['reviewer_reminded_at' => now()])->syncOriginal();

        // Past the deadline the overdue wording is the honest one.
        $threshold = ReminderSchedule::due($conference) === ReminderThreshold::Overdue
            ? ReminderThreshold::Overdue
            : ReminderThreshold::Days7;

        $sent = 0;

        foreach ($this->outstanding($conference) as $reviewer) {
            $user = $reviewer->user;

            if (! $user instanceof User) {
                continue;
            }

            $this->send($conference, $user, $threshold);
            $sent++;
        }

        activity()
            ->performedOn($conference)
            ->causedBy($actor)
            ->withProperties(['reviewers' => $sent])
            ->log('reviewer.reminded');

        return $sent;
    }

    private function send(Conference $conference, User $user, ReminderThreshold $threshold): void
    {
        $this->sendTemplatedEmail->handle(
            $threshold->templateKey(),
            $conference,
            (string) $user->email,
            // The same bag Task 4's invitation uses, so a template that starts
            // using {{organization}} needs no second call site updated. Here
            // {{review_link}} is the reviewer's own queue, not an accept link.
            InviteReviewer::placeholderValues(
                $conference,
                (string) ($user->name ?? $user->email),
                SubmissionResource::urlForConference($conference),
            ),
        );
    }
}
```

- [ ] **Step 5: The command and its registration**

`app/Console/Commands/SendReviewerRemindersCommand.php`
```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Reviews\SendReviewerReminders;
use App\Enums\ConferenceStatus;
use App\Models\Conference;
use Illuminate\Console\Command;

/**
 * Spec 5.4 step 5. Runs hourly (routes/console.php) and decides nothing itself:
 * every rule lives in ReminderSchedule and SendReviewerReminders, which are
 * unit-tested without a console at all.
 *
 * Hourly rather than daily because spec section 10 gives every conference its
 * own timezone and one Schedule entry carries exactly one - see the Task 10
 * preamble.
 */
class SendReviewerRemindersCommand extends Command
{
    protected $signature = 'cass:reviewer-reminders';

    protected $description = 'Email reviewers with outstanding work at 7, 3 and 1 days before the review deadline, and once after it.';

    public function handle(SendReviewerReminders $reminders): int
    {
        $total = 0;

        Conference::query()
            ->where('status', ConferenceStatus::Reviewing->value)
            ->whereNotNull('review_deadline')
            ->orderBy('id')
            ->each(function (Conference $conference) use ($reminders, &$total): void {
                $result = $reminders->automatic($conference);

                if ($result['sent'] > 0) {
                    $total += $result['sent'];

                    $this->info(sprintf(
                        '%s: %d reminder(s) at the %s threshold.',
                        (string) $conference->name,
                        $result['sent'],
                        $result['threshold']?->value ?? '-',
                    ));
                }
            });

        $this->info($total.' reminder(s) queued.');

        return self::SUCCESS;
    }
}
```

`bootstrap/app.php` — one call, after `withRouting(...)`:

```php
    // `withRouting(commands: routes/console.php)` registers that *file* only;
    // app/Console/Commands is NOT scanned without this (fact 2). The class is
    // named rather than the directory, so the registration is greppable and a
    // second command has to be declared on purpose.
    ->withCommands([
        SendReviewerRemindersCommand::class,
    ])
```

with `use App\Console\Commands\SendReviewerRemindersCommand;` added.

`routes/console.php` — one entry beside the two that are already there:

```php
// Spec 5.4 step 5. Hourly, not daily: every conference has its own timezone
// (spec section 10) and a Schedule entry carries one, so the command asks each
// conference whether it is past its local send hour.
Schedule::command(SendReviewerRemindersCommand::class)
    ->hourly()
    // withoutOverlapping() is safe on a command event - it is only a closure
    // event that needs a ->name() first (fact 3).
    //
    // 55 minutes, NOT the 1440-minute default
    // (Illuminate\Console\Scheduling\ManagesAttributes::withoutOverlapping,
    // :180, and CacheEventMutex::create, :43, which multiplies it by 60). This
    // command finishes in seconds. A container killed mid-run - an OOM kill or
    // `docker kill`, which skip the signal handler that would have released the
    // mutex - must not then suppress every reviewer reminder on the platform for
    // a whole day. In production the lock is a durable row in `cache_locks`
    // (docker-compose.production.yml sets CACHE_STORE=database), so nothing
    // clears it on restart either. At 55 minutes the next hourly run takes over.
    ->withoutOverlapping(55);
```

with `use App\Console\Commands\SendReviewerRemindersCommand;` added.

`app/Models/Conference.php` — one line in `casts()`:

```php
            'reviewer_reminded_at' => 'datetime',
```

- [ ] **Step 6: The manual reminder action**

`ConferenceStatusActions` gains:

```php
    public static function remindReviewers(): Action
    {
        return Action::make('remindReviewers')
            ->label(__('reviewer.remind.action'))
            ->icon(Heroicon::OutlinedBellAlert)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('reviewer.remind.heading'))
            ->modalDescription(__('reviewer.remind.description'))
            // Hidden rather than disabled when it cannot be used: the three
            // reasons (not reviewing, sent recently, nobody behind) are all
            // states where the button would be noise.
            ->visible(fn (Conference $record): bool => Gate::allows('view', $record)
                && app(SendReviewerReminders::class)->manualBlockers($record) === [])
            ->action(function (Conference $record, SendReviewerReminders $reminders): void {
                Gate::authorize('view', $record);

                /** @var User $actor */
                $actor = auth()->user();

                try {
                    $sent = $reminders->manual($record, $actor);
                } catch (ReviewNotAcceptable $exception) {
                    Notification::make()->danger()
                        ->title(__('reviewer.notices.refused'))
                        ->body(e($exception->getMessage()))
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->success()
                    ->title(__('reviewer.remind.sent', ['count' => $sent]))
                    ->send();
            });
    }
```

with `use App\Actions\Reviews\SendReviewerReminders;` and `use App\Exceptions\ReviewNotAcceptable;` added, and `all()` becoming:

```php
        return [
            static::share(), static::emails(), static::reviewers(), static::assignments(),
            static::remindReviewers(), static::publish(), static::close(), static::archive(),
        ];
```

- [ ] **Step 7: The language keys**

Append to `lang/en/reviewer.php`:

```php
    'remind' => [
        'action' => 'Remind reviewers',
        'heading' => 'Email everyone who is behind?',
        'description' => 'Only reviewers with abstracts they have not reviewed yet are emailed. You can do this again after 12 hours.',
        'sent' => 'Reminder sent to :count reviewer(s)',
        'errors' => [
            'not_reviewing' => 'This conference is not open for reviewing.',
            'too_soon' => 'A reminder was sent recently. You can send another after :hours hours.',
            'nobody_behind' => 'Every reviewer has finished, so there is nobody to remind.',
        ],
    ],
```

- [ ] **Step 8: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ReminderScheduleTest.php tests/Feature/ReviewerRemindersTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -6 /tmp/t.log && \
php artisan list | grep cass && \
php artisan schedule:list && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: `rc=0`; about 37 passed (the two datasets carry most of them); `cass:reviewer-reminders` appears in `artisan list`; `schedule:list` shows it hourly alongside `queue:prune-failed` and `model:prune`; and the suite is `baseline + 196`.

Four cases are new to this revision of the plan, and each pins one of this task's concurrency or cost rules: `it('never reminds a removed reviewer or a reviewer of another conference')` is the only negative on `outstanding()`, the sole gate on who gets emailed; `it('skips rather than crashes when the reminder row already exists')` proves the unique key is treated as "already sent" and not as a fatal, so an overlapping run does not abandon the whole hourly pass; `it('does no per-reviewer work once every reviewer has the current threshold')` proves the `whereNotExists` short-circuit, without which an overdue conference rebuilds its queue every hour for ever; and `it('claims the manual window before sending, so two clicks send one batch')` proves the conditional UPDATE in `manual()`.

If `artisan list` does not show the command, `bootstrap/app.php` is missing `->withCommands([...])` — fact 2, and the single most likely thing to go wrong in this task.

- [ ] **Step 9: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(reviews): hourly reviewer reminders at 7, 3 and 1 days, once overdue, plus a manual send

Hourly rather than daily because every conference has its own timezone and a
Schedule entry carries one; the unique key on (conference, reviewer, threshold)
is what makes 'already sent' a fact rather than a memory. The manual reminder
writes no such row - it is allowed to repeat - and is throttled on the
conference instead.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 11: `Closed -> Reviewing`, and progress on both sides

The backlog item Plan 2 left behind — "Conference status `reviewing` and `decided` are in the enum and the transition table but nothing drives them yet: Plan 4 moves Closed -> Reviewing" — plus the "progress" half of spec 5.4 step 3.

**Why `StartReviewing` has a blocker list rather than a bare guard.** `PublishConference` established the shape in Plan 2: a transition an organizer will get wrong answers with sentences, not a validation code. Everything a conference needs before reviewers can start is knowable, and each missing piece has an obvious next action:

1. the conference is `closed` — `ConferenceStatus::allowedTransitions()` permits `Closed -> Reviewing` and nothing else does;
2. a review deadline is set — without one there are no reminders and no reopen window;
3. at least one **active** reviewer has accepted;
4. at least one abstract is `submitted`;
5. the active review form has at least one question (true for anything published, checked anyway because `PublishConference` is not the only way in);
6. **in assigned mode**, every reviewable abstract has at least one assignment — an unassigned abstract in assigned mode is invisible to everybody.

**Two progress numbers, two audiences.** `Conference::reviewProgress()` is what the organizer sees: reviews submitted against reviews expected, plus a line per reviewer. `Conference::reviewProgressFor($reviewer)` is what one reviewer sees on their dashboard: their own two numbers. Both derive "expected" the same way and say so:

- **assigned mode**: expected = the number of `review_assignments` rows, because that is exactly the work that was handed out;
- **open pool**: expected = reviewable abstracts × `reviewers_per_submission`, because in open pool nobody was handed anything and the conference's own target is the only honest denominator. A reviewer's personal expectation in open pool is the whole pool, which is what the dashboard shows.

**Files:**
- Create: `app/Actions/Conferences/StartReviewing.php`
- Modify: `app/Models/Conference.php`, `app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceInfolist.php`, `.../Tables/ConferenceStatusActions.php`, `app/Filament/Reviewer/Pages/Dashboard.php`, `resources/views/filament/reviewer/pages/dashboard.blade.php`, `lang/en/reviewer.php`
- Test: `tests/Unit/StartReviewingTest.php`, `tests/Feature/Organizer/ConferenceTransitionsTest.php` (appended), `tests/Feature/Reviewer/ReviewerDashboardTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/StartReviewingTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Actions\Conferences\StartReviewing;
use App\Enums\ConferenceStatus;
use App\Enums\ReviewMode;
use App\Exceptions\ConferenceNotPublishable;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->conference = Conference::factory()->closed()->create([
        'review_deadline' => now()->addMonth(),
        'reviewers_per_submission' => 2,
    ]);
    app(CreateDefaultReviewForm::class)->handle($this->conference);
});

function readyToReview(Conference $conference): Conference
{
    ConferenceReviewer::factory()->for($conference)->create();
    Submission::factory()->for($conference)->submitted()->create();

    return $conference->fresh() ?? $conference;
}

it('moves a ready conference to reviewing and logs it', function () {
    $conference = readyToReview($this->conference);

    expect(app(StartReviewing::class)->blockers($conference))->toBe([]);

    $result = app(StartReviewing::class)->handle($conference, $this->actor);

    expect($result->status)->toBe(ConferenceStatus::Reviewing)
        ->and(Activity::query()->where('description', 'conference.reviewing')->count())->toBe(1);
});

it('names every blocker at once', function () {
    // No reviewers, no abstracts, no deadline, and the wrong status.
    $conference = Conference::factory()->create(['review_deadline' => null]);

    $blockers = app(StartReviewing::class)->blockers($conference);

    expect($blockers)->toHaveCount(5)
        ->and(fn () => app(StartReviewing::class)->handle($conference, $this->actor))
        ->toThrow(ConferenceNotPublishable::class);
});

it('refuses without an active reviewer', function () {
    ConferenceReviewer::factory()->for($this->conference)->removed()->create();
    Submission::factory()->for($this->conference)->submitted()->create();

    expect(app(StartReviewing::class)->blockers($this->conference->fresh()))->toHaveCount(1);
});

it('refuses without a submitted abstract, and a draft does not count', function () {
    ConferenceReviewer::factory()->for($this->conference)->create();
    Submission::factory()->for($this->conference)->create();

    expect(app(StartReviewing::class)->blockers($this->conference->fresh()))->toHaveCount(1);
});

it('refuses in assigned mode until every abstract has a reviewer', function () {
    $this->conference->forceFill(['review_mode' => ReviewMode::Assigned])->save();
    $reviewer = ConferenceReviewer::factory()->for($this->conference)->create();
    $assigned = Submission::factory()->for($this->conference)->submitted()->create();
    $orphan = Submission::factory()->for($this->conference)->submitted()->create(['title' => 'Nobody is reading this']);

    ReviewAssignment::factory()->for($assigned)->create(['reviewer_user_id' => $reviewer->user_id]);

    $blockers = app(StartReviewing::class)->blockers($this->conference->fresh());

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0])->toContain('1');

    ReviewAssignment::factory()->for($orphan)->create(['reviewer_user_id' => $reviewer->user_id]);

    expect(app(StartReviewing::class)->blockers($this->conference->fresh()))->toBe([]);
});

it('refuses from a status the graph does not allow', function () {
    foreach ([ConferenceStatus::Draft, ConferenceStatus::Open, ConferenceStatus::Decided, ConferenceStatus::Archived] as $status) {
        $conference = readyToReview($this->conference);
        $conference->forceFill(['status' => $status])->save();

        expect(app(StartReviewing::class)->blockers($conference->fresh()))->not->toBe([], $status->value);
    }
});

it('counts the progress an organizer looks at', function () {
    $conference = readyToReview($this->conference);
    $second = Submission::factory()->for($conference)->submitted()->create();
    $reviewer = $conference->reviewers()->firstOrFail();
    $other = ConferenceReviewer::factory()->for($conference)->create();

    Review::factory()->for($conference->submissions()->firstOrFail())->submitted()
        ->create(['reviewer_user_id' => $reviewer->user_id]);
    Review::factory()->for($second)->create(['reviewer_user_id' => $other->user_id]);

    $progress = $conference->fresh()->reviewProgress();

    // Open pool: two abstracts times the conference's own target of 2.
    expect($progress['expected'])->toBe(4)
        ->and($progress['submitted'])->toBe(1)
        ->and($progress['drafts'])->toBe(1)
        ->and($progress['reviewers'])->toHaveCount(2)
        ->and($progress['reviewers'][0]['submitted'] + $progress['reviewers'][1]['submitted'])->toBe(1);
});

it('counts assignments as the expectation in assigned mode', function () {
    $conference = readyToReview($this->conference);
    $conference->forceFill(['review_mode' => ReviewMode::Assigned])->save();
    $reviewer = $conference->reviewers()->firstOrFail();
    ReviewAssignment::factory()->for($conference->submissions()->firstOrFail())
        ->create(['reviewer_user_id' => $reviewer->user_id]);

    expect($conference->fresh()->reviewProgress()['expected'])->toBe(1);
});

it('counts one reviewer own progress for their dashboard', function () {
    $conference = readyToReview($this->conference);
    Submission::factory()->for($conference)->submitted()->create();
    $reviewer = $conference->reviewers()->firstOrFail();
    $user = User::query()->findOrFail($reviewer->user_id);

    Review::factory()->for($conference->submissions()->firstOrFail())->submitted()
        ->create(['reviewer_user_id' => $user->id]);

    $conference->forceFill(['status' => ConferenceStatus::Reviewing])->save();

    // Open pool: a reviewer's own expectation is the whole pool.
    expect($conference->fresh()->reviewProgressFor($user))->toBe(['expected' => 2, 'submitted' => 1]);
});
```

Append to `tests/Feature/Organizer/ConferenceTransitionsTest.php`:

```php
it('starts reviewing from the view page and shows why it cannot', function () {
    $conference = readyConference($this->organization);
    $conference->forceFill([
        'status' => App\Enums\ConferenceStatus::Closed,
        'review_deadline' => now()->addMonth(),
    ])->save();

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->callAction('startReviewing')
        ->assertNotified();

    // Not ready: no reviewers and no abstracts, so the action reports rather
    // than transitions - exactly as `publish` does.
    expect($conference->fresh()?->status)->toBe(App\Enums\ConferenceStatus::Closed);

    App\Models\ConferenceReviewer::factory()->for($conference)->create();
    App\Models\Submission::factory()->for($conference)->submitted()->create();

    livewire(ViewConference::class, ['record' => $conference->fresh()->getRouteKey()])
        ->callAction('startReviewing')
        ->assertNotified();

    expect($conference->fresh()?->status)->toBe(App\Enums\ConferenceStatus::Reviewing);
});

it('shows review progress on the conference once reviewing has started', function () {
    $conference = readyConference($this->organization);
    $conference->forceFill([
        'status' => App\Enums\ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
    ])->save();

    $reviewer = App\Models\ConferenceReviewer::factory()->for($conference)->create();
    $submission = App\Models\Submission::factory()->for($conference)->submitted()->create();
    App\Models\Review::factory()->for($submission)->submitted()->create(['reviewer_user_id' => $reviewer->user_id]);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->assertSee(__('reviewer.progress.heading'))
        ->assertSee((string) ($reviewer->user?->name ?? '-'));
});

it('hides start-reviewing from a conference that is not closed', function () {
    $conference = readyConference($this->organization);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->assertActionHidden('startReviewing');
});
```

`tests/Feature/Reviewer/ReviewerDashboardTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Filament\Reviewer\Pages\Dashboard;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Review;
use App\Models\Submission;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->conference = Conference::factory()->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
        'reviewers_per_submission' => 2,
    ]);
    $this->reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->reviewer->id]);

    $this->first = Submission::factory()->for($this->conference)->submitted()->create();
    $this->second = Submission::factory()->for($this->conference)->submitted()->create();

    actingAs($this->reviewer);
    bootReviewerPanel();
});

it('shows nothing done at the start', function () {
    livewire(Dashboard::class)
        ->assertSee(__('reviewer.progress.yours', ['submitted' => 0, 'expected' => 2]));
});

it('counts a submitted review and ignores a draft', function () {
    Review::factory()->for($this->first)->submitted()->create(['reviewer_user_id' => $this->reviewer->id]);
    Review::factory()->for($this->second)->create(['reviewer_user_id' => $this->reviewer->id]);

    livewire(Dashboard::class)
        ->assertSee(__('reviewer.progress.yours', ['submitted' => 1, 'expected' => 2]));
});

it('does not count another reviewer work as yours', function () {
    $other = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $other->id]);
    Review::factory()->for($this->first)->submitted()->create(['reviewer_user_id' => $other->id]);

    livewire(Dashboard::class)
        ->assertSee(__('reviewer.progress.yours', ['submitted' => 0, 'expected' => 2]));
});

it('shows no progress line for a conference that has not started reviewing', function () {
    $this->conference->forceFill(['status' => ConferenceStatus::Closed])->save();

    livewire(Dashboard::class)
        ->assertSee(__('reviewer.dashboard.not_started'))
        ->assertDontSee(__('reviewer.progress.yours', ['submitted' => 0, 'expected' => 2]));
});
```

- [ ] **Step 2: Run them and watch them fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/StartReviewingTest.php tests/Feature/Reviewer/ReviewerDashboardTest.php > /tmp/t.log 2>&1; echo "rc=$?"; head -20 /tmp/t.log
```

Expected: `rc=1`, `Class "App\Actions\Conferences\StartReviewing" not found`.

- [ ] **Step 3: The action**

`app/Actions/Conferences/StartReviewing.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\ConferenceStatus;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Enums\SubmissionStatus;
use App\Exceptions\ConferenceNotPublishable;
use App\Models\Conference;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The `Closed -> Reviewing` half of the lifecycle ConferenceStatus has declared
 * since Plan 2. Plan 5 moves `Reviewing -> Decided`.
 *
 * Same shape as PublishConference, including the exception type: an organizer
 * meeting a transition they cannot make gets sentences, not a code, and the
 * panel action prints them.
 */
class StartReviewing
{
    /** @return list<string> empty when reviewing may start */
    public function blockers(Conference $conference): array
    {
        $reasons = [];

        if ($conference->status !== ConferenceStatus::Closed) {
            // Not canTransitionTo(): the graph also allows Closed -> Open, and
            // this action is the Closed -> Reviewing step and nothing else.
            $reasons[] = __('reviewer.start.errors.wrong_status', [
                'status' => mb_strtolower($conference->status->getLabel()),
            ]);
        }

        if ($conference->review_deadline === null) {
            $reasons[] = __('reviewer.start.errors.no_deadline');
        }

        if ($conference->reviewers()->where('status', ReviewerStatus::Active->value)->doesntExist()) {
            $reasons[] = __('reviewer.start.errors.no_reviewers');
        }

        $reviewable = $conference->submissions()
            ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value]);

        if ((clone $reviewable)->doesntExist()) {
            $reasons[] = __('reviewer.start.errors.no_submissions');
        }

        if (($conference->reviewForm()->first()?->questions()->count() ?? 0) === 0) {
            $reasons[] = __('reviewer.start.errors.no_questions');
        }

        if ($conference->review_mode === ReviewMode::Assigned) {
            $unassigned = (clone $reviewable)
                ->whereDoesntHave('reviewAssignments', fn (Builder $query): Builder => $query)
                ->count();

            if ($unassigned > 0) {
                // An unassigned abstract in assigned mode is invisible to
                // everybody, which is the worst possible failure here.
                $reasons[] = __('reviewer.start.errors.unassigned', ['count' => $unassigned]);
            }
        }

        return array_values(array_unique($reasons));
    }

    public function handle(Conference $conference, User $actor): Conference
    {
        $reasons = $this->blockers($conference);

        if ($reasons !== []) {
            throw new ConferenceNotPublishable($reasons);
        }

        $conference->forceFill(['status' => ConferenceStatus::Reviewing])->save();

        activity()->performedOn($conference)->causedBy($actor)->log('conference.reviewing');

        return $conference->refresh();
    }
}
```

- [ ] **Step 4: The two progress methods**

`app/Models/Conference.php` — after `assignmentCoverage()`:

```php
    /**
     * Spec 5.4 step 3's "progress", from the organizer's side.
     *
     * "Expected" is derived differently by mode, and the difference is the
     * honest one: in assigned mode the work that was handed out is exactly the
     * `review_assignments` rows, while in open pool nobody was handed anything
     * and the conference's own `reviewers_per_submission` target is the only
     * denominator that means something.
     *
     * @return array{expected: int, submitted: int, drafts: int, reviewers: list<array{name: string, submitted: int, expected: int}>}
     */
    public function reviewProgress(): array
    {
        $reviewableIds = $this->submissions()
            ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])
            ->pluck('id');

        $assignments = ReviewAssignment::query()->whereIn('submission_id', $reviewableIds)->count();

        $expected = $this->review_mode === ReviewMode::Assigned
            ? $assignments
            : $reviewableIds->count() * max(1, (int) $this->reviewers_per_submission);

        /** @var array<string, int> $byStatus */
        $byStatus = Review::query()
            ->whereIn('submission_id', $reviewableIds)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $reviewers = [];

        /** @var ConferenceReviewer $reviewer */
        foreach ($this->activeReviewers()->with('user')->orderBy('id')->get() as $reviewer) {
            $reviewers[] = [
                'name' => (string) ($reviewer->user?->name ?? $reviewer->user?->email ?? '-'),
                'submitted' => Review::query()
                    ->whereIn('submission_id', $reviewableIds)
                    ->where('reviewer_user_id', $reviewer->user_id)
                    ->where('status', ReviewStatus::Submitted->value)
                    ->count(),
                'expected' => $this->review_mode === ReviewMode::Assigned
                    ? ReviewAssignment::query()
                        ->whereIn('submission_id', $reviewableIds)
                        ->where('reviewer_user_id', $reviewer->user_id)
                        ->count()
                    : $reviewableIds->count(),
            ];
        }

        return [
            'expected' => $expected,
            'submitted' => (int) ($byStatus[ReviewStatus::Submitted->value] ?? 0),
            'drafts' => (int) ($byStatus[ReviewStatus::Draft->value] ?? 0),
            'reviewers' => $reviewers,
        ];
    }

    /**
     * The same two numbers for one reviewer, for their own dashboard. In open
     * pool a reviewer's expectation is the whole pool; in assigned mode it is
     * what they were given.
     *
     * @return array{expected: int, submitted: int}
     */
    public function reviewProgressFor(User $reviewer): array
    {
        $queue = ReviewerScope::submissions($reviewer, $this);

        return [
            'expected' => (clone $queue)->count(),
            'submitted' => (clone $queue)
                ->whereHas('reviews', fn (Builder $reviews): Builder => $reviews
                    ->where('reviewer_user_id', $reviewer->getKey())
                    ->where('status', ReviewStatus::Submitted->value))
                ->count(),
        ];
    }
```

with `use App\Enums\ReviewStatus;`, `use App\Support\Reviews\ReviewerScope;` and `use Illuminate\Database\Eloquent\Builder;` added to the imports.

- [ ] **Step 5: The transition action and the infolist section**

`ConferenceStatusActions` gains:

```php
    public static function startReviewing(): Action
    {
        return Action::make('startReviewing')
            ->label(__('reviewer.start.action'))
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('reviewer.start.heading'))
            ->modalDescription(__('reviewer.start.description'))
            ->visible(fn (Conference $record): bool => $record->status === ConferenceStatus::Closed
                && Gate::allows('publish', $record))
            ->action(function (Conference $record, StartReviewing $start): void {
                Gate::authorize('publish', $record);

                $blockers = $start->blockers($record);

                if ($blockers !== []) {
                    // The same shape the publish action uses: report, do not
                    // throw, and name every missing piece at once.
                    Notification::make()
                        ->danger()
                        ->title(__('reviewer.start.not_ready'))
                        ->body(implode(' ', array_map('e', $blockers)))
                        ->persistent()
                        ->send();

                    return;
                }

                /** @var User $actor */
                $actor = auth()->user();
                $start->handle($record, $actor);

                Notification::make()->success()->title(__('reviewer.start.started'))->send();
            });
    }
```

with `use App\Actions\Conferences\StartReviewing;` added, and `all()` becoming:

```php
        return [
            static::share(), static::emails(), static::reviewers(), static::assignments(),
            static::remindReviewers(), static::publish(), static::startReviewing(),
            static::close(), static::archive(),
        ];
```

`ConferenceInfolist` gains a section after `Submissions`, with the same `WeakMap` memoisation the file already uses for blockers and counts:

```php
    /**
     * @var WeakMap<Conference, array{expected: int, submitted: int, drafts: int, reviewers: list<array{name: string, submitted: int, expected: int}>}>|null
     */
    private static ?WeakMap $progress = null;

    /** @return array{expected: int, submitted: int, drafts: int, reviewers: list<array{name: string, submitted: int, expected: int}>} */
    private static function progress(Conference $record): array
    {
        self::$progress ??= new WeakMap;

        return self::$progress[$record] ??= $record->reviewProgress();
    }
```

and the section itself:

```php
            Section::make(__('reviewer.progress.heading'))
                // Only once reviewing has started: a checklist of zeroes on a
                // conference still collecting abstracts is noise.
                ->visible(fn (Conference $record): bool => in_array(
                    $record->status,
                    [ConferenceStatus::Reviewing, ConferenceStatus::Decided],
                    true,
                ))
                ->headerActions([
                    Action::make('manageReviewers')
                        ->label(__('reviewer.actions.page_link'))
                        ->icon(Heroicon::OutlinedUserGroup)
                        ->color('gray')
                        ->url(fn (Conference $record): string => ConferenceResource::getUrl('reviewers', ['record' => $record])),
                ])
                ->columns(3)
                ->components([
                    TextEntry::make('reviews_submitted')->label(__('reviewer.progress.submitted'))->badge()->color('success')
                        ->state(fn (Conference $record): string => self::progress($record)['submitted'].' / '.self::progress($record)['expected']),
                    TextEntry::make('reviews_drafts')->label(__('reviewer.progress.drafts'))->badge()->color('warning')
                        ->state(fn (Conference $record): int => self::progress($record)['drafts']),
                    TextEntry::make('reviewers_active')->label(__('reviewer.progress.reviewers'))->badge()
                        ->state(fn (Conference $record): int => count(self::progress($record)['reviewers'])),
                    TextEntry::make('per_reviewer')->label(__('reviewer.progress.per_reviewer'))
                        ->listWithLineBreaks()
                        ->columnSpanFull()
                        ->placeholder(__('reviewer.progress.none'))
                        ->state(fn (Conference $record): array => array_map(
                            fn (array $row): string => $row['name'].' — '.$row['submitted'].' / '.$row['expected'],
                            self::progress($record)['reviewers'],
                        )),
                ]),
```

with `use App\Filament\Organizer\Resources\Conferences\ConferenceResource;` added (the file already imports `Action`, `Heroicon`, `TextEntry`, `Section` and `ConferenceStatus`).

- [ ] **Step 6: Progress on the reviewer dashboard**

`app/Filament/Reviewer/Pages/Dashboard.php` — one method:

```php
    /** @return array{expected: int, submitted: int} */
    public function progressFor(Conference $conference): array
    {
        $user = $this->reviewer();

        return $user === null
            ? ['expected' => 0, 'submitted' => 0]
            : $conference->reviewProgressFor($user);
    }
```

and the view, inside the `@if ($conference->isOpenToReviewers())` block that Task 6 added, above the queue link:

```blade
                    @php($progress = $this->progressFor($conference))
                    <p style="margin-top:0.5rem;font-size:0.875rem;font-weight:600">
                        {{ __('reviewer.progress.yours', ['submitted' => $progress['submitted'], 'expected' => $progress['expected']]) }}
                    </p>
```

- [ ] **Step 7: The language keys**

Append to `lang/en/reviewer.php`:

```php
    'start' => [
        'action' => 'Start reviewing',
        'heading' => 'Open this conference for review?',
        'description' => 'Reviewers can see the abstracts and start writing reviews. Authors are not emailed, and you can still send reviewer reminders from here.',
        'started' => 'Reviewing has started',
        'not_ready' => 'This conference is not ready for review',
        'errors' => [
            'wrong_status' => 'Only a conference whose submissions are closed can move to review; this one is :status.',
            'no_deadline' => 'Set a review deadline first, so reviewers know when their work is due and reminders can be sent.',
            'no_reviewers' => 'No reviewer has accepted an invitation yet.',
            'no_submissions' => 'No abstract has been submitted, so there is nothing to review.',
            'no_questions' => 'The review form has no questions yet.',
            'unassigned' => ':count abstract(s) have no reviewer assigned. In assigned review, an abstract nobody is assigned to is invisible to everybody.',
        ],
    ],

    'progress' => [
        'heading' => 'Review progress',
        'submitted' => 'Reviews submitted',
        'drafts' => 'Drafts in progress',
        'reviewers' => 'Active reviewers',
        'per_reviewer' => 'By reviewer',
        'none' => 'No reviewers yet',
        'yours' => 'You have submitted :submitted of :expected reviews.',
    ],
```

- [ ] **Step 8: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/StartReviewingTest.php tests/Feature/Reviewer/ReviewerDashboardTest.php tests/Feature/Organizer/ConferenceTransitionsTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -6 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`; about 30 passed in the first (`ConferenceTransitionsTest` carries fourteen of its own) and `baseline + 213` in the second.

- [ ] **Step 9: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(conferences): Closed -> Reviewing, with a blocker list and progress on both sides

StartReviewing follows PublishConference: every missing piece is a sentence, not
a code. In assigned mode an abstract with no assignment blocks the move, because
an unassigned abstract there is invisible to everybody.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 12: The language sweep, the environment file and the runbook

Spec section 10: "Locale `en` only in v1, with all strings in language files so Arabic can be added without code changes." Plan 3 built `tests/Feature/LanguageCoverageTest.php` to make that true rather than aspirational; this task points it at everything Plan 4 wrote and fixes whatever it finds.

**What is in scope and what is deliberately not.** The public `/invite/{token}` page is a public page and every visible string on it goes through `__()`. The reviewer panel's **own Blade views** — the dashboard and the review page — do too, because they are the screens a reviewer actually reads and a reviewer is as likely to want Arabic as an author. **Filament's own chrome stays Filament's**: the labels it generates, its pagination, its modal buttons and its validation messages come from `vendor/filament/*/resources/lang`, and translating a panel means publishing those, which is a separate decision with a separate cost. Every string *this plan* puts into a panel — action labels, column headings, notification titles, blocker sentences — is already a `__()` call by construction, because Tasks 3 to 11 wrote them that way.

**Files:**
- Modify: `tests/Feature/LanguageCoverageTest.php`, `lang/en/members.php`, `lang/en/reviewer.php`, `.env.example`, `docs/runbooks/deploy-production.md`
- No application code changes, unless the coverage test finds a hardcoded string — which is the point of running it.

- [ ] **Step 1: Extend the language coverage test**

`tests/Feature/LanguageCoverageTest.php` — add a second source list and two cases beside Plan 3's. Do **not** rewrite Plan 3's `$plan3Sources` block; the new list is additive so a future plan can add a third.

```php
/**
 * Every file Plan 4 added or modified that may contain a translated string.
 *
 * Two of these are *modified*, not created, and they are here precisely because
 * of that: `ConferenceStatusActions.php` carries the reviewers link, the
 * assignments link, `reviewer.remind.*` and `reviewer.start.*`, and
 * `ConferenceInfolist.php` carries `reviewer.progress.*`. Those are the busiest
 * `reviewer.*` surface Plan 4 adds to the organizer panel, and leaving them out
 * means a typo in `reviewer.remind.action` renders the raw key string on the
 * conference page with this test still green - which is the exact failure this
 * test exists to prevent. The three organizer Blade views are here for the same
 * reason: the second case below scans only `.blade.php` entries.
 */
$plan4Sources = [
    'resources/views/livewire/public/accept-invitation.blade.php',
    'resources/views/filament/organizer/pages/members.blade.php',
    'resources/views/filament/organizer/resources/conferences/pages/assignments.blade.php',
    'resources/views/filament/organizer/resources/conferences/pages/reviewers.blade.php',
    'resources/views/filament/reviewer/pages/dashboard.blade.php',
    'resources/views/filament/reviewer/resources/submissions/pages/review-submission.blade.php',
    'app/Livewire/Public/AcceptInvitation.php',
    'app/Actions/Invitations/AcceptInvitation.php',
    'app/Actions/Organizations/ChangeMemberRole.php',
    'app/Actions/Organizations/InviteMember.php',
    'app/Actions/Organizations/RemoveMember.php',
    'app/Actions/Organizations/SetSubmissionNotifications.php',
    'app/Actions/Reviewers/InviteReviewer.php',
    'app/Actions/Reviewers/InviteReviewerList.php',
    'app/Actions/Reviews/AssignReviewers.php',
    'app/Actions/Reviews/AutoAssignReviewers.php',
    'app/Actions/Reviews/ReopenReview.php',
    'app/Actions/Reviews/SaveReviewDraft.php',
    'app/Actions/Reviews/SendReviewerReminders.php',
    'app/Actions/Reviews/SubmitReview.php',
    'app/Actions/Conferences/StartReviewing.php',
    'app/Filament/Organizer/Pages/Members.php',
    'app/Filament/Organizer/Resources/Conferences/Pages/ConferenceAssignments.php',
    'app/Filament/Organizer/Resources/Conferences/Pages/ConferenceReviewers.php',
    'app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceInfolist.php',
    'app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php',
    'app/Filament/Reviewer/Pages/Dashboard.php',
    'app/Filament/Reviewer/Resources/Submissions/Pages/ListSubmissions.php',
    'app/Filament/Reviewer/Resources/Submissions/Pages/ReviewSubmission.php',
    'app/Filament/Reviewer/Resources/Submissions/SubmissionResource.php',
    'app/Filament/Reviewer/Resources/Submissions/Tables/QueueTable.php',
    'app/Models/OrganizationInvitation.php',
    'app/Models/ReviewerInvitation.php',
    'app/Support/Reviews/ReviewFormSchema.php',
    'app/Support/Reviews/ReviewerList.php',
    'app/Support/Panels/PanelSwitch.php',
    'app/Notifications/MemberInvitation.php',
];

it('resolves every translation key plan 4 uses', function () use ($plan4Sources) {
    $missing = [];

    foreach ($plan4Sources as $relative) {
        $path = base_path($relative);

        expect(file_exists($path))->toBeTrue("Expected {$relative} to exist.");

        // The trailing group cannot end on a dot, so a concatenated key such as
        // `__('members.invite.blocked.'.$status->value)` in
        // app/Actions/Invitations/AcceptInvitation.php is skipped rather than
        // captured as the literal prefix 'members.invite.blocked.' - for which
        // Lang::has() is false (Arr::get explodes it to a final empty segment)
        // and which no language file can ever satisfy. Those four keys are
        // asserted directly in the third case below, by enum case.
        preg_match_all(
            "/__\\(\\s*'((?:members|reviewer)(?:\\.[a-z0-9_]+)+)'/",
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

it('leaves no visible english hardcoded in the pages plan 4 added', function () use ($plan4Sources) {
    // The same compile-then-strip approach as Plan 3's case, for the same
    // reason: hand-written regexes over Blade directives produce phantom
    // offenders that no language key can ever fix.
    $allowed = ['KB', 'MB', 'PDF'];
    $offenders = [];

    foreach ($plan4Sources as $relative) {
        if (! str_ends_with($relative, '.blade.php')) {
            continue;
        }

        $compiled = Blade::compileString((string) file_get_contents(base_path($relative)));

        $text = strip_tags((string) preg_replace([
            '/<\?php.*?\?>/s',
            '/<\?php.*$/s',
            '/<script\b[^>]*>.*?<\/script>/s',
            '/<style\b[^>]*>.*?<\/style>/s',
        ], ' ', $compiled));

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');

            if ($line === '' || in_array($line, $allowed, true)) {
                continue;
            }

            if (str_word_count($line) >= 3) {
                $offenders[] = "{$relative}: {$line}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('has an english line for every reminder threshold and reviewer status', function () {
    foreach (App\Enums\ReminderThreshold::cases() as $threshold) {
        expect($threshold->getLabel())->not->toBe('');
    }

    // The four invitation states the accept page branches on each need a
    // sentence, or an invitee meets a blank box.
    foreach (App\Enums\InvitationStatus::cases() as $status) {
        expect(Lang::has('members.invite.blocked.'.$status->value))->toBeTrue($status->value);
    }
});
```

- [ ] **Step 2: Run it and fix what it finds**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/LanguageCoverageTest.php > /tmp/t.log 2>&1; echo "rc=$?"; sed -n '1,60p' /tmp/t.log
```

Expected first run: `rc=1`, with a list of either missing keys or hardcoded lines. Fix each one at its source — add the key to `lang/en/members.php` or `lang/en/reviewer.php`, or replace the literal with a `__()` call — and re-run until `rc=0`. **Do not add the offending view to an exclusion list**; that is the one change that makes this test stop meaning anything.

- [ ] **Step 3: The environment file**

`.env.example` — append after the bot-protection block:

```bash
# --- Reviewers (Plan 4) ---
# Member and reviewer invitations: how long an emailed link works, and the two
# rate limits of spec section 9 (accept 10/min/IP, login 5/min/email+IP).
CASS_INVITATION_EXPIRY_DAYS=14
CASS_INVITATION_RATE_LIMIT=10
CASS_INVITATION_LOGIN_RATE_LIMIT=5

# A pasted reviewer list is a bulk-mail primitive that every organization member
# can reach, so it is bounded twice: LIST_MAX caps one paste (which also keeps
# the request inside php-fpm's 60-second budget), and SEND_LIMIT caps how many
# reviewer invitations one account can send in a rolling hour. Raise them only if
# a real conference genuinely needs to, and watch the bounce rate if you do.
CASS_INVITATION_LIST_MAX=100
CASS_INVITATION_SEND_LIMIT=200

# Reviewer reminders. The scheduler runs `cass:reviewer-reminders` every hour
# and, for each conference in review, sends at 7, 3 and 1 days before the
# review deadline and once after it - in that conference's own timezone, in the
# first run at or after this local hour. Each threshold reaches each reviewer at
# most once, enforced by a unique key, so a restart or a redeploy cannot double
# up. The organizer's manual "Remind reviewers" button is throttled separately.
CASS_REMINDER_HOUR=7
CASS_REMINDER_MANUAL_THROTTLE_HOURS=12
```

Then prove nothing is missing:

```bash
cd /c/Users/ahmed/Documents/CASS && \
grep -ohrE "env\('([A-Z0-9_]+)'" config/ | sed -E "s/env\('//; s/'//" | sort -u > /tmp/used.txt && \
grep -oE '^[A-Z0-9_]+' .env.example | sort -u > /tmp/declared.txt && \
comm -23 /tmp/used.txt /tmp/declared.txt
```

Expected: no output. Spec section 9 says `.env.example` lists every variable, and this is the cheapest way to keep that true.

- [ ] **Step 4: The runbook**

`docs/runbooks/deploy-production.md` — three new sections, placed after "Email triage" (which is where an operator already goes when a reviewer says they got nothing).

````markdown
## Reviewer reminders

`php artisan schedule:work` already runs under supervisord (`docker/supervisord.conf`),
so nothing new starts at deploy time. The entry added in Plan 4 is:

| Command | Cadence |
|---|---|
| `cass:reviewer-reminders` | hourly, `withoutOverlapping(55)` |

**What it does each hour.** For every conference whose status is `reviewing` and
which has a `review_deadline`, it works in *that conference's* timezone and asks
two questions: is the local clock at or past `CASS_REMINDER_HOUR` (07:00 by
default), and is a threshold due that this reviewer has not already been sent?
The thresholds are 7, 3 and 1 days before the deadline and once after it. The
latest applicable threshold fires, so a conference that opened for review five
days before its deadline gets the seven-day reminder late rather than not at all.

**Why hourly and not daily.** One `Schedule` entry carries one timezone, and
every conference has its own (spec section 10). The hourly pass plus the
`unique (conference_id, user_id, threshold)` index on `reviewer_reminders` gives
the same behaviour as "daily at 07:00 local" without picking a timezone, and it
is self-healing: an outage that spans the send hour catches up on the next run
the same day.

**Checking it by hand.**

```bash
docker exec cass su-exec app php artisan schedule:list
docker exec cass su-exec app php artisan cass:reviewer-reminders
```

The command is idempotent, so running it by hand is safe. It prints one line per
conference it sent for and a total.

**If a reviewer says they got nothing**, in order:

1. `select * from reviewer_reminders where conference_id = ? and user_id = ?` —
   a row means it was sent; find it in `email_logs` by `to_email` and check
   `status`.
2. No row: are they `active` in `conference_reviewers`, and does
   `ReviewerScope` still give them outstanding work? A reviewer who has
   submitted every review in their queue is deliberately not reminded.
3. Still nothing: is the conference `reviewing`, and is its `review_deadline`
   set? A conference in `closed` sends nothing.

To re-send one threshold deliberately, delete that one row and wait for the next
hourly run — do not edit `sent_at`.

**If nothing is sent for hours and `schedule:list` looks right**, the overlap
mutex may be stale after a hard restart: an OOM kill or `docker kill` skips the
signal handler that would have released it, and in production the lock is a
durable row in the `cache_locks` table (`CACHE_STORE=database`), so a restart
does not clear it either. Clear it with the framework's own command rather than
SQL — the row's key carries the cache prefix (`cass-cache-framework/schedule-<sha1>`),
so a hand-written `LIKE 'framework/%'` query finds nothing:

```bash
sudo docker exec -it "$C" su-exec app php artisan schedule:list
sudo docker exec -it "$C" su-exec app php artisan schedule:clear-cache
```

It expires by itself after 55 minutes in any case, which is why the entry passes
that value rather than taking Laravel's 1440-minute default.

## Invitations and their tokens

Member invitations (`organization_invitations`) and reviewer invitations
(`reviewer_invitations`) share one route, `/invite/{token}`, one token shape and
one accept action. The token is 32 random bytes as 64 hex characters, stored
**only** as a SHA-256 hash; the plaintext exists in the emailed link and nowhere
else, so a lost link is re-sent (which mints a new token and kills the old one),
never recovered.

**Two consequences worth knowing before they surprise somebody.**

- **Accepting an invitation marks the address verified** without sending a
  verification email. Following a 64-character secret that only ever reached
  that mailbox is the same proof `VerifyEmail` asks for. This is the only place
  in CASS that grants verification that way, and it is why accepting while
  signed in as a *different* account is refused outright.
- **The plaintext token is in the queued job payload** for as long as the
  notification or mailable is queued, exactly as author status links already
  are. The `jobs` table is on the internal-only MySQL network and failed jobs
  are pruned after 30 days (`queue:prune-failed --hours=720`); if a failed job
  carrying an invitation is ever exported for debugging, treat the export as
  containing a live credential until the invitation expires (14 days by default,
  `CASS_INVITATION_EXPIRY_DAYS`).

**Two bearer-token URL shapes are redacted from stored subjects.** There are now
two credential-carrying URLs in this application — `/s/{64}` (an author's status
link) and `/invite/{64}` — and `SendTemplatedEmail` strips both from a rendered
subject before it is written to `email_logs` and put on the wire, because a
Subject header travels in clear text through every relay and an organizer can
read `email_logs`. If you see a live 64-character token in a stored subject, that
pattern has been narrowed and it is a bug, not a curiosity.

**Two rate-limit buckets protect `/invite/{token}`, not one.** The GET carries
the route middleware `throttle:invitation-accept`; each Livewire action spends a
second, separate 10/min/IP budget inside the component, because a Livewire action
POSTs to `/livewire/update` and no middleware on `/invite/...` ever sees it.
Laravel stores a named route limiter under `md5($limiterName.$key)`, a private
format application code must not reproduce, so the two cannot share one counter.
A 429 on the page itself is the route limiter; "Too many attempts" rendered
*inside* the page is the component's. Both are the spec's 10/min/IP.

**Signing in on that page does not sign anybody in.** The password field
*verifies* the account and grants the membership or reviewership; the panel login
is what issues the session, so the multi-factor challenge cannot be skipped by
holding an invitation link. An invitee who confirms their password lands on the
panel's login screen next, and that is intended.

An invitation that is expired, withdrawn or already used is answered with a page
explaining which, not a 404 — the holder has already proved they have the
secret, and a 404 there only generates support mail. A token that matches
nothing is a flat 404.

An invitation is also refused at accept time if whoever minted it no longer
manages the organization, or minted an Owner invitation and is no longer an
Owner. `RemoveMember` and `ChangeMemberRole` withdraw those rows on the spot;
this is the belt to that braces.

## Blind review and file names

When a conference has `blind_review` on, a reviewer sees no authors, no
affiliations and no contact number — **and no real file names**. The reviewer's
download link carries `blind=1` inside its HMAC signature, so it cannot be
stripped, and the file is served as `attachment-1.pdf`. An organizer's link is
not blinded: spec section 4 gives every organization member full sight of
submissions and files, the CSV export still contains author addresses, and
somebody has to be able to answer an author's email.

**Custom fields are the blind spot to check when setting a conference up.** A
question the organizer wrote themselves — "Institution", "Department", "Funding
source" — prints its answer on the review page like any other, and only the
organizer knows which of their questions identify an author. Each custom field
has a **Hide this answer from reviewers** toggle; turn it on for those, and a
blind conference stops printing them. It changes nothing for a non-blind
conference, and nothing for the organizer's own screens or the CSV export.
````

- [ ] **Step 5: Run the whole suite and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && tail -4 /tmp/all.log && \
./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?" && \
git add -A && git commit -q -m "docs: language coverage for plan 4, the reviewer env variables and three runbook sections

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`, and `baseline + 216` (the three new language cases).

---
### Task 13: MySQL, the backlog, the final gate and the pull request

**Files:**
- Modify: `docs/superpowers/plans/backlog.md`, `.github/workflows/ci.yml` (two smoke assertions for the third panel, Step 3)
- No application code changes.

- [ ] **Step 1: Run the whole suite against MySQL, not only SQLite**

Five things in this plan behave differently on MySQL and are the reason this run is the real gate, not a formality:

1. `char(64)` on both `token_hash` columns, each `unique` — a stray trailing space or a case difference surfaces here and nowhere else.
2. The three composite unique keys this plan adds — `(submission_id, reviewer_user_id)` on `reviews` and on `review_assignments`, `(review_id, review_question_id)` on `review_answers`, `(conference_id, user_id, threshold)` on `reviewer_reminders` — are real constraints only here. `tests/Unit/ReviewModelsTest.php` asserts the `QueryException` they raise.
3. `ReviewForm::lockIfUnlocked()` is a conditional `UPDATE … WHERE locked_at IS NULL`; SQLite serialises writes, so MySQL is the only place the concurrency it is written for is real.
4. `ConferenceAssignments`'s `under_target` filter is a correlated sub-select in the WHERE clause via `has('reviewAssignments', '<', $target)` — deliberately not a `HAVING`, which SQLite refuses outright on a query with no GROUP BY. MySQL is where its query plan and its collation-free comparison are confirmed against real data volume.
5. `reviewer_reminders.threshold` is a `varchar(16)` in MySQL strict mode: an enum value longer than 16 characters would be an exception rather than a silent truncation.

```bash
cd /c/Users/ahmed/Documents/CASS && docker compose -f docker-compose.dev.yml up -d && sleep 15 && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php artisan test > /tmp/mysql.log 2>&1; echo "mysql rc=$?"; tail -4 /tmp/mysql.log
```

Expected: `mysql rc=0` and the same count as the SQLite run.

- [ ] **Step 2: Prove the form lock under real concurrency**

`ReviewForm::lockIfUnlocked()` is the one piece of this plan whose correctness depends on the database, and the unit test can only show that the second call returns `false`. Prove the `UPDATE … WHERE` once, by hand, against MySQL. Write `/tmp/lock.php`:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Step 1's `php artisan test` uses RefreshDatabase (tests/Pest.php), so the
// schema survives the run but NO ROWS DO - `ReviewForm::query()->firstOrFail()`
// here would throw ModelNotFoundException and this proof would never run at all.
// Build exactly what it needs, then remove it again. ReviewFormFactory creates
// its own conference.
$form = App\Models\ReviewForm::factory()->create(['locked_at' => null]);

// Two *instances* of the same row, the shape two concurrent requests really
// have: each read `locked_at` as null before either wrote.
$a = App\Models\ReviewForm::query()->findOrFail($form->getKey());
$b = App\Models\ReviewForm::query()->findOrFail($form->getKey());

$first = $a->lockIfUnlocked();
$second = $b->lockIfUnlocked();

echo 'first=', var_export($first, true), ' second=', var_export($second, true), PHP_EOL;
echo 'locked_at=', (string) $form->refresh()->locked_at, PHP_EOL;

// Leave the database as this script found it.
$form->conference->delete();
```

```bash
cd /c/Users/ahmed/Documents/CASS && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php /tmp/lock.php
```

Expected: `first=true second=false` and one timestamp. (`php -` does not work on this machine, which is why this is a file.)

- [ ] **Step 3: Confirm the routes and the pages this plan added**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan route:list --except-vendor | grep -E "invite|review|members|assignments|reviewers"
```

Expected:

- `GET invite/{token}` → `invitation.accept`
- the reviewer panel's auth routes under `review/` (`filament.reviewer.auth.*`) plus `filament.reviewer.pages.dashboard`
- two reviewer resource routes: `review/submissions` and `review/submissions/{record}/review`
- one organizer page: `org/{tenant:slug}/members`
- two organizer conference pages: `org/{tenant:slug}/conferences/{record}/reviewers` and `.../assignments`

and:

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan about --only=drivers && php artisan schedule:list
```

Expected: three scheduled entries — `queue:prune-failed`, `model:prune` and `cass:reviewer-reminders`.

Then teach CI the same thing, because the smoke job is the only step that boots the **built image** with `config:cache`, `route:cache` and opcache `validate_timestamps=0`, and today it asserts only the two pre-existing Filament asset URLs — so a third panel that 500s under cached routes would ship green. In `.github/workflows/ci.yml`, add to the smoke job's "Assertions" step, after the existing Filament asset `curl`s:

```yaml
          # The third panel (Plan 4) inside the built image, under route:cache +
          # config:cache + opcache validate_timestamps=0. A guest on /review must
          # be redirected to the reviewer login, not 500. The command's own
          # registration is already covered by tests/Feature/ReviewerRemindersTest.php
          # and by `schedule:list` in Step 3 above, so it is not repeated here.
          code=$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: localhost' http://127.0.0.1:8080/review)
          echo "reviewer panel -> $code"; test "$code" = "302"
          curl -sf -o /dev/null -H 'Host: localhost' http://127.0.0.1:8080/review/login
```

- [ ] **Step 4: Update `docs/superpowers/plans/backlog.md`**

**Remove** the four entries Plan 4 actually closed:

- under `## Organizer features (Plan 2 onwards)`: "Conference status `reviewing` and `decided` are in the enum … Plan 4 moves Closed -> Reviewing" — done, Task 11. Rewrite it rather than delete it, because half of it is still open:

  ```markdown
  - `ConferenceStatus::Decided` is in the enum and the transition table but nothing drives it: **Plan 5** moves Reviewing -> Decided. `Closed -> Reviewing` is done (Plan 4, `StartReviewing`).
  ```

- under the same heading: "`review_forms.locked_at` is written by nothing except factories until Plan 4's `SubmitReview` sets it" — done, Task 7. Delete.
- under the same heading: "Organization member management (spec 4 …) moves on to **Plan 4**" — done, Task 3. Delete.
- under `## Submissions (deferred by Plan 3)`: "**Reviewer visibility of submission files** … Plan 4 adds the reviewer case, and has to decide whether a blind review hides the file's *name* too" — done, Task 6, and it does. Delete.
- under the same heading: "**The seven template keys nothing sends.** … Plan 4 sends `reviewer_invitation`, `reviewer_reminder` and `reviewer_overdue`" — rewrite to name only what is left:

  ```markdown
  - **The four `decision_*` template keys are still not sent.** Plan 3 shipped platform defaults and a per-conference editor for all eleven keys of spec 5.9; Plan 4 sends the three reviewer keys; **Plan 5** sends the four decision keys. The editor tells the organizer which is which ("Not sent yet").
  ```

Then append a new section:

```markdown
## Reviewing (deferred by Plan 4)

- **Open question for the owner: what the review deadline freezes.** Plan 4 makes a *submitted* review read-only at the review deadline (no reopen) but lets a *draft* still be saved and submitted while the conference is in `reviewing`. The alternative reading of "after the deadline reviews are read-only" freezes everything — but `reviewer_overdue`, a spec 5.9 key whose platform default Plan 3 already ships, tells a reviewer after the deadline to "complete them as soon as you can", which that reading makes impossible. Making it strict is one added clause in `SubmitReview::blockers()` (`! $conference->reviewWindowIsOpen()`) and inverting `it('still lets outstanding work be finished after the deadline')` in `tests/Unit/SubmitReviewTest.php`. **Confirm before launch.**
- **Open question for the owner: who may remove a reviewer and change a role.** `ReviewerInvitationPolicy` and `ConferenceReviewerPolicy` mirror spec section 4's "Invite reviewers, assign, decide", which is **every** organization member down to a plain `member` — so a plain member can remove a reviewer mid-review (deleting their assignments) and invite anyone. Member *management* is owner/admin only, which is the spec's other row. Both are logged with the actor, and the invitation send path is metered per actor (`CASS_INVITATION_SEND_LIMIT`, 200 an hour) with one paste capped at `ReviewerList::MAX_ENTRIES`, so the blast radius is bounded rather than unbounded. Confirm, or narrow the two reviewer policies to `canManageOrganization()`.
- **Open question for the owner: unassigning after a review is submitted.** Plan 4 allows it; spec 5.5 says assignments can be changed "until the review is submitted". The submitted review survives — only the assignment row goes, and Plan 5 averages `reviews` rather than `review_assignments` — but an organizer *can* remove a reviewer who has already delivered. Making it strict is one clause in `AssignReviewers::blockers()` refusing a removal whose reviewer has a submitted review. **Confirm before launch.**
- **A per-row "unassign this reviewer" action.** Plan 4 has exactly one unassign path: deselect in the multi-select `assign` action and save, which goes through `AssignReviewers` behind its `Gate::authorize()`. A one-reviewer row action was deliberately *not* shipped, because the obvious implementation is a row-deleting method with no policy check and no conference check that nothing calls — a cross-tenant delete waiting for a row action. If Plan 5 wants one it arrives with the actor's role in the submission's organization checked before the delete, and a cross-conference negative test.
- **Reviewer bidding and declared conflicts of interest** are spec section 14 (v2). Plan 4 computes conflicts from data the app already holds — author emails, author affiliations, reviewer email, reviewer affiliation — and a reviewer cannot declare one or decline an abstract. The nearest thing today is telling the organizer, who removes them; `reviewer_reminder`'s platform default says exactly that.
- **A reviewer cannot edit their own affiliation.** `conference_reviewers.affiliation` is typed by the organizer when inviting and copied on accept; it is the only input to spec 5.5's affiliation conflict rule, and a reviewer who moves institution cannot correct it. The reviewer panel's `->profile()` page is where that field belongs, per conference.
- **Per-reviewer expertise tags and AI-assisted matching** are spec section 14. `AutoAssignReviewers` balances load and skips conflicts and knows nothing about subject matter; a conference with tracks would get better assignments by matching `submissions.track_id` to a reviewer's declared tracks, which is a column and a picker away.
- **Reviewer and presenter certificates** are spec section 14.
- **`AutoAssignReviewers` never *removes* an assignment.** It tops up towards `reviewers_per_submission` and leaves everything an organizer did by hand alone, which is the safe default; there is no "rebalance from scratch" action, and adding one means deciding what happens to a reviewer who has already started.
- **Free-mailbox domains are a config list, not a lookup.** `config('cass.review.free_email_domains')` carries twenty-one domains. A conflict that should have been skipped but was not, because the domain is not on the list, is an environment change - but it is also silent. If this ever matters, the check belongs behind a small service with a test per domain.
- **Decided:** organization membership is **not** a conflict of interest for auto-assign. For a small society the programme chair reviews too, and treating membership as a conflict empties the pool of the people who know the field; the real conflict it sometimes proxies for — the reviewer works where the author works — is caught by the email-domain and affiliation rules.
- **Decided:** removing a reviewer deletes their `review_assignments` and keeps their `reviews`. An assignment left behind makes the coverage summary and the auto-assign balance both describe a reviewer who is gone; a submitted review is the record of what the committee was told, and a draft comes back if the same person is invited again.
- **Decided:** invitation status is derived from `accepted_at` / `revoked_at` / `expires_at` rather than stored. `expired` is a function of the clock, and a stored column would be wrong for exactly as long as nothing ran to agree with it. The cost is that an invitation list cannot `where('status', …)` in SQL, which is why both invitation lists are array-backed pages over a handful of rows.
- **The Filament table testing helpers this suite uses are deprecated aliases.** `callTableAction`, `mountTableAction`, `assertTableActionVisible/Hidden`, `assertHasNoTableActionErrors` and `callTableBulkAction` are all marked `@deprecated` in `vendor/filament/tables/.stubs.php` in favour of the unified `callAction()` / `mountAction()` / `assertAction*()` family plus `Filament\Actions\Testing\TestAction`. They work in 5.8.1 and the whole suite uses them consistently; the sweep is one mechanical pass and belongs in whichever plan next has to touch Filament's version.
- **`email_logs` still grows for ever** (raised by Plan 3, and Plan 4 adds three more senders to it). `Prunable` plus a retention variable, before the table passes a few hundred thousand rows.
- **A platform-admin view of reviews, reviewers and assignments.** Every new policy's `before()` answers `true` for `is_platform_admin`, and there is no screen behind any of them — the same state Plan 3 left submissions in. **Plan 6**, alongside the read-only admin `SubmissionResource`. Note for whoever builds it: Laravel returns a non-null `before()` result *without ever calling the ability* (`Gate::resolvePolicyCallback`), so the explicit `deleteAny(): false` / `restoreAny(): false` / `forceDeleteAny(): false` on all five policies does **not** apply to a platform admin. `tests/Feature/Organizer/ChildPolicyBulkAbilitiesTest.php` pins that as the current state on purpose; refusing deletion on those screens is Plan 6's job, in `before()` or in the resource.
- **A platform-admin view of organization members.** The same shape as reviews and as submissions (backlog.md:57): `OrganizationMemberPolicy::before()` and `OrganizationInvitationPolicy::before()` answer for a platform admin, and nothing lets one into the tenant panel — `canAccessPanel('organizer')` is `organizations()->exists()`. So spec section 4's platform-admin cell for "Manage organization members" has no screen. **Plan 6.**
- **The hard purge (Plan 6) has four more tables to delete.** `reviews` and `review_answers` are `RESTRICT`-ed onto submissions and questions on purpose, so a purge must delete them in application code, in order, before the abstracts — and `review_assignments`, `conference_reviewers`, `reviewer_invitations` and `reviewer_reminders` cascade but should be counted in the confirmation the admin is shown.
- **No reviewer sees another reviewer's review, in any panel, in Plan 4.** `ReviewPolicy::view()` allows the conference's organization members, but no organizer screen prints a review's *content* yet — Plan 4 shows counts. Plan 5's ranking table is the first thing to read them, and is where "should reviewers see each other's comments after decisions" has to be answered.
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
./vendor/bin/pest --configuration=phpunit.browser.xml > /tmp/browser.log 2>&1; echo "browser rc=$?"; tail -3 /tmp/browser.log
```

Expected: every `rc=0`. The browser suite is Plan 3's one end-to-end submission run; this plan adds no browser test and must not have broken that one.

- [ ] **Step 6: Commit the backlog and open the pull request**

Both suites gate this commit too, with `&&` rather than `;`, so the last commit of the plan cannot land on a red tree even if Step 5's output was skimmed.

```bash
cd /c/Users/ahmed/Documents/CASS && \
php artisan test > /tmp/sqlite.log 2>&1 && echo "sqlite rc=0" && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php artisan test > /tmp/mysql.log 2>&1 && echo "mysql rc=0" && \
git add -A && git commit -q -m "docs: record what plan 4 closed and what it deferred

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && \
git push -u origin plan-4-reviewers && \
gh pr create --fill --title "Plan 4: members, reviewers, the reviewer panel, reviews, assignment and reminders" --body "$(cat <<'BODY'
Implements spec 5.4 and 5.5, the "Manage organization members" row of section 4
(deferred from Plans 1-2), the `/review/...` and `/invite/{token}` rows of
section 6, the review-form lock rule of section 3, and `Closed -> Reviewing`.

## For organizers

- **Team page** (`/org/{org}/members`): invite by email and role, resend,
  withdraw, change a role, remove, and your own "email me about new abstracts"
  switch. An organization always keeps one owner, nobody edits their own role,
  and only an owner grants owner.
- **Reviewers page** on each conference: invite one by name and email or paste a
  list, resend, withdraw, remove, invite again. Sends `reviewer_invitation`.
- **Assignments page** (assigned mode): a reviewer picker per abstract, a
  coverage summary, and a balanced auto-assign that skips conflicts and shows
  the plan before saving.
- **Start reviewing**: `Closed -> Reviewing` with a blocker list, and review
  progress on the conference view.
- **Remind reviewers**: one button, throttled to twice a day.

## For reviewers

- A third panel at `/review` with its own login, MFA and verification.
- A dashboard listing your conferences, their deadlines in their own timezone,
  and your progress.
- A queue: every submitted abstract in open pool, your assignments in assigned
  mode.
- A review page with the abstract, its files and the conference's review form.
  Save a draft, submit, reopen until the deadline.
- Blind conferences hide the authors, the affiliations, the contact number
  **and the file names** - the last through the URL signature, so it cannot be
  undone by editing the link.

## Platform

- `/invite/{token}` serves member and reviewer invitations alike: sign in,
  or create an account in four fields. Accepting marks the address verified,
  because following the emailed secret is the proof `VerifyEmail` asks for.
- `cass:reviewer-reminders` runs hourly and emails at 7, 3 and 1 days before the
  review deadline and once after, in each conference's own timezone, each
  threshold at most once per reviewer.
- Seven new tables, five new policies, one new panel. No new packages.

## Verification

- `php artisan test` green on SQLite and on MySQL 8.4.
- `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse` (level 6) clean.
- The Plan 3 browser suite still green.
- Cross-tenant and cross-conference negative cases on every new page, resource
  and action; a reviewer cannot reach an abstract outside their pool or
  assignments, and gets a 404 rather than a 403.

## Deferred, with reasons

See `docs/superpowers/plans/backlog.md`, section "Reviewing (deferred by
Plan 4)". Two of them are **questions for the owner**: what the review deadline
freezes, and whether a plain organization member should be able to remove a
reviewer.
BODY
)"
```

- [ ] **Step 7: Self-review against the spec**

Read each row and confirm it against the code, not against this plan's prose.

**Section 5.4 — Reviewer invitation and review**

| Step | Where |
|---|---|
| 1. Invite by name and email, single or pasted list; hashed token; 14-day expiry; email with the accept link | Task 4: `InviteReviewer`, `InviteReviewerList`, `ReviewerList`, `reviewer_invitations.token_hash`, `config('cass.invitations.expiry_days')` |
| 2. `/invite/{token}`: existing account signs in, otherwise a short account form; invitation accepted, token invalidated | Task 2: `AcceptInvitation` (component and action), `InvitationLookup`; the token is invalidated by `accepted_at`, and `blockers()` refuses a used one |
| 3. Panel shows conferences, progress and a queue; open pool is every submitted abstract, assigned is the reviewer's assignments | Tasks 5, 6, 11: `Dashboard`, `ReviewerScope`, `QueueTable` |
| 4. Review page shows the abstract (authors hidden when blind), files, the form; draft and submit; reopen until the deadline | Tasks 6, 7: `ReviewSubmission`, `ReviewFormSchema`, `SaveReviewDraft`, `SubmitReview`, `ReopenReview` |
| 5. Reminders at 7, 3, 1 days and once after; manual reminder | Task 10: `ReminderSchedule`, `SendReviewerReminders`, `cass:reviewer-reminders`, `ConferenceStatusActions::remindReviewers()` |

**Section 5.5 — Assignment**

| Requirement | Where |
|---|---|
| Manual assignment per submission | Task 8: `ConferenceAssignments`, `AssignReviewers` |
| Balanced auto-assign: the N reviewers with the fewest assignments | Task 9: `AutoAssignReviewers::plan()` |
| Skip a reviewer whose affiliation matches an author's (case-insensitive) | Task 9: `conflicts()`, normalised |
| Skip a reviewer whose email domain matches an author's | Task 9: `conflicts()`, **with free mailboxes excluded** — recorded deviation |
| Result shown for confirmation before saving | Task 9: the `autoAssign` modal, which re-plans on save |
| Assignments changeable until the review is submitted | Task 8: changeable at any time, **including after** — a contradiction of this clause, deliberate, argued in Task 8 and listed as deviation 8 below |

**Section 4 — Roles and permissions**

| Cell | Where |
|---|---|
| Manage organization members: owner, admin | Task 3: `OrganizationMemberPolicy`, `OrganizationInvitationPolicy`. **The platform-admin cell is policy-only**: both policies' `before()` answers `true`, but the organizer panel is membership-gated (`canAccessPanel('organizer')` needs `organizations()->exists()`), so there is no screen behind it — exactly as Plan 3 left submissions. In the backlog; **Plan 6** |
| Invite reviewers, assign, decide: every organization member | Tasks 4, 8: `ReviewerInvitationPolicy`, `ReviewAssignmentPolicy` — flagged as an owner question |
| View submissions and files — reviewer: assigned or pool only | Task 6: `ReviewerScope`, `SubmissionPolicy`, `SubmissionFilePolicy` |
| Submit reviews — reviewer only | Task 7: `ReviewPolicy::create()` is `isActiveReviewer()`; `update()` is ownership |

**Section 6 — URL scheme**: `/review/...` (Task 5), `/invite/{token}` (Task 2), and the switch link in the panel header (Task 5, `PanelSwitch` in both panels' `userMenuItems()`).

**Section 3 — the lock**: `review_forms.locked_at` is set by `SubmitReview` (Task 7) and enforced by `ReviewQuestion::booted()`, `ReviewQuestionPolicy` and `reorderable(null)` — all three from Plan 2, now proved against a real submitted review.

**Section 8 — data storage**: every new foreign key is a real constraint with a decided `on delete` (Task 1's table), `(submission_id, reviewer_user_id)` is unique exactly as the spec names it, and every foreign key is indexed.

**Section 9 — security**: tokens hashed with SHA-256, plaintext only in the link; `/invite/{token}` at 10/min/IP and the sign-in attempt at 5/min/email+IP; a policy on every new model with every ability Filament may call; cross-tenant and cross-conference negative cases on every page; audit entries for role changes, member removals, reviewer removals, assignment changes, invitations, reviews and the `Closed -> Reviewing` transition.

**Section 10 — non-functional**: every deadline is rendered in the conference's own timezone and every reminder threshold is computed in it; every new organizer- and reviewer-facing string is in `lang/en/members.php` or `lang/en/reviewer.php`, proved by `LanguageCoverageTest` over the 37-file `$plan4Sources` list — which deliberately includes the two *modified* organizer files, `ConferenceStatusActions.php` and `ConferenceInfolist.php`, because that is where most of Plan 4's organizer-facing `reviewer.*` keys live. What stays hardcoded English is the four new enums' `getLabel()` values, exactly as every enum from Plans 1-3 is; that falls under the existing backlog item on extracting hardcoded English before any Arabic work (`backlog.md:29`, `:62`).

**Section 12 — testing**: unit tests for auto-assign balancing and conflict skipping, token issue and expiry, and reminder thresholds at each boundary; feature tests for every flow in 5.4 and 5.5 and member management, both review modes, deadline enforcement, token expiry and single use, and the `/invite/{token}` rate limit; panel tests with `livewire()` for every new resource and page in both panels, each with a cross-tenant or cross-conference negative case.

**Explicit deviations from the brief, each argued where it is made**

1. **Two invitation tables, not one polymorphic one** (Task 1) — spec section 3 requires real foreign keys, which a polymorphic parent cannot carry.
2. **Invitation status derived, not stored** (Task 1) — `expired` is a function of the clock.
3. **Free-mailbox domains are not a conflict** (Task 9) — the spec's rule applied literally would empty the pool in this region.
4. **Auto-assign is deterministic by total ordering, not by a seed** (Task 9) — a random tie-break in a preview-then-confirm flow means the plan shown is not the plan saved.
5. **The review deadline freezes reopening, not finishing** (Task 7) — otherwise `reviewer_overdue` asks for something impossible. Flagged for the owner.
6. **Reminders run hourly, not daily at 07:00** (Task 10) — one `Schedule` entry carries one timezone and every conference has its own.
7. **Member management is a page with one array-backed table, not two resources** (Task 3) — a Filament component hosts one table, and the question the organizer is asking spans both kinds of row.
8. **Assignments stay changeable after a review is submitted** (Task 8) — spec 5.5 says "until the review is submitted". An organizer who realises they assigned the wrong person has to be able to say so; the submitted review is kept as evidence, Plan 5 averages `reviews` rather than `review_assignments`, and a draft comes back if the same person is re-assigned. **Flagged for the owner**, with the one-clause change that makes it strict recorded in the backlog.
9. **`signIn()` on `/invite/{token}` verifies a password but issues no session** (Task 2) — the brief implies signing in there; Filament challenges multi-factor authentication only inside its own login page, so an `Auth::attempt()` there would be a factor-free front door into the organizer and admin panels for anyone holding the link. The invitee is accepted and then sent to the panel login, which does challenge it.
10. **A pasted reviewer list is capped, and reviewer invitations are metered per actor** (Task 4) — the brief describes neither, but "invite reviewers" is available to every organization member and the pasted list turns one click into a hundred platform-sent emails. `ReviewerList::MAX_ENTRIES` bounds one click, `CASS_INVITATION_SEND_LIMIT` bounds one hour, and both are `.env` variables an owner can raise.
11. **Custom fields gained a `hide_from_reviewers` flag** (Task 1, Task 6) — the brief's blind review hides authors, affiliations and the contact number. It says nothing about the organizer's own questions, and an "Institution" field would have printed the author's hospital two sections below the hidden authors block. The flag defaults to `false`, so nothing an organizer already created changes behaviour.

- [ ] **Step 8: Type consistency check**

One last pass for the mismatches Larastan cannot see:

```bash
cd /c/Users/ahmed/Documents/CASS && \
grep -rn "reviewers_per_submission\|review_deadline\|blind_review" app/ --include=*.php | grep -v "^app/Models/Conference.php" | head -30
```

Every reader of `reviewers_per_submission` must go through `max(1, (int) …)` or `assignmentCoverage()`; every reader of `review_deadline` must use `reviewDeadlineInConferenceTimezone()` or `reviewWindowIsOpen()` rather than comparing a UTC timestamp to a local one; and every reader of `blind_review` must be `Conference::hidesAuthorsFrom()` and nothing else. A hit outside those three shapes is the bug this check exists to find.

---
