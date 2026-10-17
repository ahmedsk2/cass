# CASS v2 Plan 8: Phase-B Guards (B9 Suspended Organisations, B10 Status-Link Caps, B11 Review-Settings Lock)

> **Status: DRAFT, reviewed 2026-10-17 (written the same day against `main` at `6256b06`).**
> Every task was prototyped in a scratch copy of that tree, red then green in plan order; the whole suite, Larastan and Pint ran clean on the result.
> The six-lens adversarial review (`CLAUDE.md`, "Pipeline per plan") ran on 2026-10-17 and its editor's changes are applied in this text. The owner approved the summary on 2026-10-17 (review point 1), with the go-ahead for the compose-file lines in Task 2; the two open questions were not answered and Task 6 records them in the backlog.

**Goal:** close the three code items of the expert brief's phase B that stand between CASS and real authors. A pending or suspended organisation can no longer send invitations or reviewer reminders (B9). The public draft form stops being a branded mail relay: it emails at most three status links per address and ten per client IP in 24 hours, and past either cap still saves the draft and tells the author the page they land on is their link (B10). Review mode and blind review, the two settings that give a review its meaning, lock once reviewing starts or any review exists; a track abstracts are filed under or a custom field authors answered can no longer be deleted; deadline edits stay logged with the organiser who made them (B11).

**Architecture.** One owner per seam:
- Organisation approval gates outgoing mail: `InviteReviewer::organizationBlockers()` (single invitations, and pasted lists through `InviteReviewerList`), `InviteMember::blockers()`, `SendReviewerReminders::manualBlockers()` and `::automatic()`.
- Status-link caps: `SendSubmissionStatusLink::handleForAuthor()`, the public form's only send; `handle()` stays the organiser's uncapped resend.
- Review-settings lock and the edit form's writes: `UpdateConference` (new), called by `EditConference::handleRecordUpdate()` and by `ConferenceStatusActions::edit()`, the Edit button of the view page and the list table; `ConferenceForm` disables the two fields from `EditConference::reviewSettingsLocked()`.
- Rows in use: `TrackPolicy::delete()` and `CustomFieldPolicy::delete()`; the two relation managers show the policy's reason as the delete button's tooltip.
- Deadline audit: the model's own `LogsActivity` entry (`Conference::getActivitylogOptions()`), whose causer `UpdateConference` sets to the actor.

**Tech stack**, from `composer.lock` and `package.json` at `6256b06`: PHP 8.4.26, Laravel 13.37.0, Filament 5.10.2, Livewire 4.4.7, Pest 5.3.2 with pest-plugin-laravel 5.0.1 and pest-plugin-livewire 5.0.0, PHPUnit 13.4.2, Larastan 3.13.0 (level 6), Pint 1.32.1, spatie/laravel-activitylog 5.1.1, Vite ^8.3.4 with Tailwind ^4.0.0; MySQL 8.4 in CI and production, SQLite in memory locally. Nothing is installed or upgraded.

**Spec and brief:** `docs/reviews/2026-10-16-expert-brief.md`, phase-B rows B9, B10 and B11 (their "Done when" is each task's acceptance test) and "Question 6, architecture", point 2; `docs/decisions/0010-filament-default-saves-outside-actions.md` (why `UpdateConference` arrives now); `docs/decisions/0002-withdraw-and-resend-owner-admin-only.md` (the resend left alone); `docs/superpowers/specs/2026-09-10-cass-v2-design.md` sections 4, 5.3, 5.4 and 9.

**Environment facts for every command below**
- `$REPO` is the tree you implement in: this worktree, `/c/Users/khawl/Desktop/Ahmed/claude/projects/CASS/.claude/worktrees/nifty-vaughan-0e33f3`, branch `plan-8-phase-b-guards` from `main` at `6256b06`, unless the run gives your task its own `CASS-wt-N` worktree and branch, which are then yours. No command below sets `$REPO`, and shell variables do not survive between Bash calls: begin every call with `REPO=<that path>;` or write the path itself in its place (never hard-code this worktree's path into a command block). Git Bash; start every command with `cd $REPO` after `export PATH="/c/Users/khawl/Desktop/Ahmed/claude/tools/php84:/c/Users/khawl/Desktop/Ahmed/claude/tools/composer:$PATH"`.
- This plan installs nothing; Composer, if ever needed, is `php /c/Users/khawl/Desktop/Ahmed/claude/tools/composer/composer.phar`, never `composer.bat`. `npm run build` has run; run it again before testing only if `public/build/manifest.json` is missing, and never with `npm run dev` running (`public/hot` makes the panels link the dev server).
- `php -` hangs on this machine. The production hook refuses a Bash command whose text names the host's tools, so write commit messages and the PR body with the Write tool and edit docs with the Edit tool.
- Baseline is the count `php artisan test` reports on the fresh branch: 1,341 passed, 1 skipped at `6256b06`. Each task ends at "baseline + k"; counts are a guide and `rc=0` is the gate.
- **Order.** Tasks 1 and 2 touch disjoint files and may run as a parallel pair. Tasks 3, 4 and 5 run one after another in that order: Task 4 calls Task 3's `UpdateConference`, and each appends its `lang/en/organizer.php` group after the previous task's (`update`, then `review_settings`, then `tracks` and `custom_fields`). Task 6 runs last. Each "baseline + k" assumes the tasks land in plan order; a task run in its own worktree counts from that worktree's base.
- Red and green runs, each to its own log (the gates below overwrite `/tmp/t-task-N.log`, and the red log is the test-first proof a report cites): `cd $REPO && php artisan test --compact --filter='<filter>' > /tmp/t-task-N-red.log 2>&1; echo "rc=$?"`, then the same command to `/tmp/t-task-N-green.log` once the code is in. Read a log with `tail -n 60 /tmp/t-task-N.log` in Bash, or with the Read tool at `C:\Users\khawl\AppData\Local\Temp\t-task-N.log`; read `/tmp/pr-task-6.log`, which holds the pull request URL, the same way.
- **The gates**, N being the task number, each judged by its exit code alone:
  ```bash
  cd $REPO && php artisan test > /tmp/t-task-N.log 2>&1; echo "test rc=$?"
  ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/phpstan-task-N.log 2>&1; echo "stan rc=$?"
  ./vendor/bin/pint --test > /tmp/pint-task-N.log 2>&1; echo "pint rc=$?"
  ```
- **Commits:** write the message with the Write tool to `C:\Users\khawl\AppData\Local\Temp\commit-plan8-task-N.txt`, the folder Git Bash calls `/tmp` (the Write and Read tools turn a bare `/tmp/...` into `C:\tmp\...`, which git never reads), ending with the trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`; `git add` the task's files by name, then `test -s /tmp/commit-plan8-task-N.txt && git commit -F /tmp/commit-plan8-task-N.txt`. Never with a gate red, and never while any test in the suite fails. The `plan8` prefix keeps a parallel worktree's message file out of this branch's commits.
- New files: every PHP file begins `<?php`, a blank line, `declare(strict_types=1);`, a blank line, then the namespace for a class; code blocks below start after that. Imports are listed in prose: write one `use` statement per class in the order given (Pint's `ordered_imports`), then a blank line and any `use function` lines. Laravel's test teardown resets Carbon's test clock (`vendor/laravel/framework/src/Illuminate/Foundation/Testing/Concerns/InteractsWithTestCaseLifecycle.php:163-169`), so no test needs an `afterEach`.

**What this plan does NOT build**
- A cap on the status link `SubmitAbstract` emails on submit (`submission_received`): deferred to the backlog in Task 6.
- A refusal of the organiser's **Resend status link** for a suspended organisation (open question 2): `SendSubmissionStatusLink::handle()` is unchanged.
- Hidden invite buttons for a pending or suspended organisation: they answer with the refusal. The reminder button hides itself, because its `visible()` already asks `manualBlockers()` (fact 6).
- A new activity entry for deadline edits: the model's own `updated` entry already carries old and new values (fact 9).
- A lock on `reviewers_per_submission` or on the deadlines, and any change to the two policies' `deleteAny()`: no bulk delete exists on either table (fact 13); their docblocks are corrected.
- Address folding beyond the trim and lower-casing the form already does (`name+1@`, dots): the per-IP cap and Turnstile (B1) cover a sprayer. Both caps are read and then counted, so simultaneous saves can each pass; the five-a-minute bot limit bounds that.
- The phase-D work the brief ties to opening a file: D5's split of `SubmissionForm` (changed by Task 2); D2's conversion of the English literals in `SendSubmissionStatusLink` (Task 2), `ConferenceForm`, `ConferenceStatusActions` and `ConferencesTable` (Task 4) and the two relation managers (Task 5); and D6's query scope for the active-reviewer filter in `InviteReviewer` and `SendReviewerReminders` (Task 1). A guard PR carries no refactor, and the brief lets phase D wait until after launch (question 3). D2's backlog entry ("Launch", the language sweep) stays open, and D5 and D6 stay in the brief's phase D.
- A migration. None is needed.

## Owner summary (review point 1)

#### Behaviour changes a user would notice
- An organisation that is **pending** (not yet approved) or **suspended** can no longer invite members or reviewers, re-invite or resend an invitation: each answers with a sentence saying why, and a pasted reviewer list says it once. **Today a pending organisation can invite both.**
- For those organisations the **Remind reviewers** button disappears and the hourly reminders skip their conferences.
- **Save draft** on the public form stops emailing the status link once the author's address has had **3 status-link emails** in 24 hours (draft links, submit receipts and organiser resends all count, though receipts and resends still go out), or once the client IP has had **10 draft links** in 24 hours. Past either cap the draft is still saved and the author lands on its status page, which says no email was sent and that the page is their link. **Resend status link** is never capped.
- On the conference edit form **Review mode** and **Blind review** are greyed out from `reviewing` onward and on any conference that has a review (also back in `closed`), with a helper text; a forged change is refused with "Nothing was saved". Deadlines stay editable, each edit logged with the organiser as causer. The helper text shows under both fields on every conference form, the create form included.
- A track abstracts are filed under, or a custom field authors have answered, can no longer be deleted: the delete button is greyed out and its tooltip says why.
#### Policies touched or added
- `TrackPolicy::delete()` refuses a track any abstract references (withdrawn or trashed included), with a reason; `TrackPolicy::view()` refuses instead of erroring when the conference or organisation is out of reach.
- `CustomFieldPolicy::delete()` and `view()`: the same, for a field an abstract of its conference has answered. No policy is added: `UpdateConference` checks membership itself, as the other actions do.
#### Migrations: none
#### Environment variables (both optional; the defaults need nothing set in production)
- `CASS_STATUS_LINK_PER_ADDRESS_PER_DAY`, default `3`: status-link emails of any kind an address may have had in 24 hours before the public form stops emailing it draft links.
- `CASS_STATUS_LINK_PER_IP_PER_DAY`, default `10`: status-link emails the public form sends for one client IP in 24 hours.
#### Sensitive files touched
- `app/Policies/TrackPolicy.php`: refuses deleting a track in use; null-safe `view()`.
- `app/Policies/CustomFieldPolicy.php`: refuses deleting an answered field; null-safe `view()`.
- `config/cass.php`: the two caps.
- `docker-compose.production.yml`: passes the two optional caps to the app container; a Coolify variable reaches it only through `${...}` here.
#### Open questions for the owner (each ships the behaviour stated)
1. Is 10 status links per client IP per day right for a hospital network where many authors share one address? It ships at 10; setting `CASS_STATUS_LINK_PER_IP_PER_DAY` in Coolify and redeploying raises it.
2. Should **Resend status link** be refused for a suspended organisation? It sends today, and the status page it links to is offline until reinstatement.

## File structure created or changed by this plan

```
app/Actions/Conferences/UpdateConference.php, app/Exceptions/ConferenceUpdateRefused.php  created, T3: the edit form's writer (blockers(), handle(), reviewSettingsLocked()) and its refusal, carrying its reasons
app/Actions/Reviewers/InviteReviewer.php              changed, T1: organizationBlockers(), asked by blockers()
app/Actions/Reviewers/InviteReviewerList.php          changed, T1: refuses a whole list once for the organisation
app/Actions/Organizations/InviteMember.php            changed, T1: pending and suspended reasons in blockers()
app/Actions/Reviews/SendReviewerReminders.php         changed, T1: manualBlockers() reasons first; automatic() skips
app/Actions/Submissions/SendSubmissionStatusLink.php  changed, T2: handleForAuthor(), the capped public send
app/Livewire/Public/SubmissionForm.php                changed, T2: saveDraft() sends through handleForAuthor(), flashes a refusal
config/cass.php, .env.example, docker-compose.production.yml  changed, T2: the two caps (EnvExampleTest fails T2 without the second; the third passes them to the container)
app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceForm.php  changed, T4: two fields disabled, sent, helper text
app/Filament/Organizer/Resources/Conferences/Pages/EditConference.php    changed, T4: reviewSettingsLocked(), handleRecordUpdate()
app/Filament/Organizer/Resources/Conferences/{Tables/ConferenceStatusActions,Pages/ViewConference,Tables/ConferencesTable}.php  changed, T4: edit(), the shared EditAction that saves through UpdateConference, used by the other two
app/Policies/TrackPolicy.php, app/Policies/CustomFieldPolicy.php         changed, T5: delete() refuses rows in use; view() null-safe
app/Filament/Organizer/Resources/Conferences/RelationManagers/{Tracks,CustomFields}RelationManager.php  changed, T5: authorizationTooltip()
lang/en/reviewer.php, lang/en/members.php (T1), lang/en/submission.php (T2), lang/en/organizer.php (T3, T4, T5)  changed: new keys
tests/Unit/OrganizationMailBlockersTest.php (T1), tests/Feature/Public/StatusLinkCapTest.php (T2), tests/Unit/UpdateConferenceTest.php (T3), tests/Feature/Organizer/ConferenceReviewSettingsTest.php (T4), tests/Feature/Organizer/ReferencedRowsTest.php (T5)  created
tests/Feature/Organizer/SubmissionResourceTest.php    changed, T2: one case pinning the uncapped resend
docs/decisions/0013-review-settings-lock-and-referenced-rows.md, docs/decisions/0014-status-link-caps-on-the-public-form.md  created, T6
docs/HANDOVER.md, docs/superpowers/plans/backlog.md, docs/runbooks/deploy-production.md  changed, T6
```

## Facts verified before writing this plan

Read at `6256b06` on 2026-10-17; vendor paths are under `vendor/`. "Prototype" means the behaviour was run, not only read.

1. **Versions** as in Tech stack: `composer.lock:2477` laravel/framework, `:1423` filament/filament, `:3631` livewire, `:11249` pest, `:10772` larastan, `:10941` pint, `:5669` activitylog, `:12675` phpunit. Baseline 1,341 passed, 1 skipped; the prototype ended at 1,369 passed, 1 skipped, and the review added one case each to Tasks 3 and 4, so the plan ends at 1,371.
2. **Pest helpers:** `tests/Pest.php:18-22` (Feature gets `RefreshDatabase`; Unit files add `uses(RefreshDatabase::class)`, as `tests/Unit/PublishConferenceTest.php:21` does), `bootOrganizerPanel()` `:38-59`, `withoutTenant()` `:86-96`; `livewire()` is `Pest\Livewire\livewire`.
3. **Factories:** an organisation defaults to `Pending` (`database/factories/OrganizationFactory.php:27`; `approved()` `:31-37`); a conference's default organisation is approved (`ConferenceFactory.php:22`); `status` is not fillable, so tests `forceFill()` it. A factory organisation's colours are null in memory and `OrganizationTheme::for()` upper-cases them (`app/Support/Branding/OrganizationTheme.php:20`), so form tests set both, as `tests/Feature/Public/SubmissionFormTest.php:38-42` does (prototype: a `ViewException` without them). `EmailLogFactory::failed()`: `database/factories/EmailLogFactory.php:39-42`.
4. **The house refusal for approval:** `PublishConference::blockers()` gives two reasons, pending and suspended (`app/Actions/Conferences/PublishConference.php:31-37`); `SendOneDecisionEmail::blockers()` asks `organization?->isApproved()` (`app/Actions/Decisions/SendOneDecisionEmail.php:97`); `Organization::isApproved()` is `app/Models/Organization.php:155-158`, and organisations soft-delete (`:28`).
5. **Invitations:** `InviteReviewer::blockers()` `app/Actions/Reviewers/InviteReviewer.php:28-57`, organisation null-guarded at `:37-41`; `InviteReviewerList::handle()` calls `InviteReviewer::handle()` per line and stops early only on the send limit (`InviteReviewerList.php:37-55`), and the page shows ten lines of the result (`app/Filament/Organizer/Resources/Conferences/Pages/ConferenceReviewers.php:223`); `InviteMember::blockers()` `app/Actions/Organizations/InviteMember.php:30-55`. Both pages turn `MemberChangeRefused` into a notification (`ConferenceReviewers.php:185-189`, `app/Filament/Organizer/Pages/Members.php:276-280`).
6. **Reminders:** `SendReviewerReminders::automatic()` `app/Actions/Reviews/SendReviewerReminders.php:98-176`; `manualBlockers()` `:179-211`, whose comment `:194-204` says every early reason must be constant time, and which is the button's `visible()` (`app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php:204`); the hourly command loops all reviewing conferences, catching each one's `Throwable` (`app/Console/Commands/SendReviewerRemindersCommand.php:33-53`).
7. **Language coverage:** `tests/Feature/LanguageCoverageTest.php:530-583` resolves every `__()` key in `app/`, and `:208-243` the `members`/`reviewer` keys of the Plan 4 files, which include all three B9 actions. Groups touched: `lang/en/reviewer.php:84-107` and `:275-279`, `lang/en/members.php:125-137`, `lang/en/submission.php:109-113`, `lang/en/organizer.php:20-33`.
8. **Filament's save:** `EditRecord::save()` (`vendor/filament/filament/src/Resources/Pages/EditRecord.php:159-204`) ends quietly on `Halt` (`:181-186`: no "Saved" notification, no redirect) and rethrows anything else; `handleRecordUpdate(Model $record, array $data): Model` is `$record->update($data)` (`:281-286`). `Model::preventSilentlyDiscardingAttributes()` is on outside production (`app/Providers/AppServiceProvider.php:52`) and `Conference::$fillable` (`app/Models/Conference.php:63-69`) leaves out `status`, so `fill()` keeps Filament's behaviour.
9. **Activity log: a panel save already logs deadline edits with old and new values.** `Conference` logs dirty changes of the three deadlines among others (`app/Models/Conference.php:690-696`); the trait's `updated` entry stores `old` and `attributes` in `attribute_changes`, not in `properties` (`vendor/spatie/laravel-activitylog/src/Models/Concerns/LogsActivity.php:31-70`, `:237-264`; column `database/migrations/2026_09_10_043926_create_activity_log_table.php:20`; a collection, `src/Models/Activity.php:36-40`). Its causer is the auth guard's user (`src/Support/CauserResolver.php:116-119`; no `config/activitylog.php`, so `default_auth_driver` is null). Prototype: one panel save of `review_deadline` writes exactly one `updated` row naming the signed-in user, with both values. `CauserResolver::withCauser()` (`:95-105`, scoped binding at `src/ActivitylogServiceProvider.php:31`) lets `UpdateConference` name the actor with no second row.
10. **A disabled field is not dehydrated, so its saved value is never blanked:** `disabled()` also sets `saved(false)` (`vendor/filament/schemas/src/Components/Concerns/CanBeDisabled.php:16-28`), `isDehydrated()` falls back to `isSaved()` unless `dehydrated()` was called (`Components/Concerns/HasState.php:782-791`), and a non-dehydrated key leaves the state (`:321-350`). `reference_prefix` relies on this (`ConferenceForm.php:161-168`); the two locked fields add `->dehydrated()` so their values reach `UpdateConference`.
11. **Status graph:** `Reviewing -> Closed` and `Closed -> Open` are allowed (`app/Enums/ConferenceStatus.php:51-61`). `reviews.submission_id` is indexed (`database/migrations/2026_09_12_000500_create_reviews_table.php:20`, `:34`); submissions soft-delete (`app/Models/Submission.php:30`).
12. **Query cost of the lock** (prototype): a `disabled()` and a `helperText()` closure per field asking `UpdateConference::reviewSettingsLocked()` ran its query 18 times per edit-page render; a per-request memo on the page and a constant helper text bring it to one.
13. **DeleteAction in a relation manager** asks the policy's `delete` through `Gate::inspect()` (`vendor/filament/filament/src/Resources/RelationManagers/RelationManager.php:345-368`, `Resources/Concerns/InteractsWithRelationshipTable.php:269-272`, `:434-452`, `src/helpers.php:31-63`). A denial hides the action (`vendor/filament/actions/src/Concerns/CanBeHidden.php:74-95`) unless `authorizationTooltip()` is set and the denial has a message, when it shows disabled with that message as tooltip (`Concerns/CanBeAuthorized.php:200-250`, `Concerns/CanBeDisabled.php:18-44`, `Concerns/HasTooltip.php:19-30`); a disabled action is neither mounted nor run (`Concerns/InteractsWithActions.php:247-251`, `:373-375`). A bare `false` is a denial without a message (`vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:402-417`). `deleteAny` is asked only for a `DeleteBulkAction` (`RelationManager.php:362`), which neither manager has (`TracksRelationManager.php:67-70`, `CustomFieldsRelationManager.php:87-91`).
14. **JSON paths on both drivers:** `whereNotNull('custom_field_values->key')` is `json_extract(...) is not null` on SQLite (`vendor/laravel/framework/src/Illuminate/Database/Query/Grammars/Grammar.php:459-462`, `SQLiteGrammar.php:505-510`), where a JSON null reads as SQL NULL, and on MySQL also excludes a JSON null (`MySqlGrammar.php:113-123`); `where(path, '!=', '')` compares `json_unquote(json_extract())` on MySQL (`:618-623`). Prototype on SQLite with `false`, `''`, `null` and a sibling conference; CI's MySQL job is its first MySQL run. Keys are `[a-z0-9_]` slugs: the `creating` hook sets `key` through `uniqueKey()`, which slugs the label with `Str::of($label)->slug('_')` (`app/Models/CustomField.php:53-73`); the column holds answers keyed by them (`create_submissions_table.php:44-46`).
15. **Rows in use:** `submissions.track_id` is `nullOnDelete` (`create_submissions_table.php:21-23`); tracks and custom fields do not soft-delete. Prototype: under the organizer panel the tenancy scope hides another organisation's conference, so today's `TrackPolicy::view()` (`app/Policies/TrackPolicy.php:26-29`) throws "Attempt to read property organization on null"; `ConferenceReviewerPolicy::view()` already guards that hop (`app/Policies/ConferenceReviewerPolicy.php:41-43`).
16. **RateLimiter:** `tooManyAttempts()` (`vendor/laravel/framework/src/Illuminate/Cache/RateLimiter.php:128-139`); `increment()` adds the `:timer` key with `cache->add`, so a key's decay is set by its first hit only (`:161-180`), as `passesBotChecks()` says (`app/Livewire/Public/SubmissionForm.php:661-669`). It uses the default store (`Illuminate/Cache/CacheServiceProvider.php:34-36`, `config/cache.php:20`): `database` in production (`.env.example:55`), `array` in tests (`phpunit.xml`). Keyed by `App\Support\ClientIp::from()` (`app/Support/ClientIp.php:20-31`).
17. **email_logs:** `template_key`, `to_email` (`database/migrations/2026_09_11_001500_create_email_logs_table.php:26-28`), `to_email` indexed (`:36`); `SendTemplatedEmail` writes the row before queueing, `to_email` as passed (`app/Actions/Mail/SendTemplatedEmail.php:80-94`); the status-link keys are `submission_draft_saved` and `submission_received` (`app/Enums/EmailTemplateKey.php:19-20`), chosen at `SendSubmissionStatusLink.php:61-63`; the form stores the address trimmed and lower-cased (`SaveSubmissionDraft.php:121`), so an exact match finds its rows on both drivers.
18. **The form:** `saveDraft()` sends inside a `try` at `SubmissionForm.php:365-376` and flashes at `:382`; the status page prints `session('status')` (`resources/views/livewire/public/submission-status.blade.php:2-3`), whereas `reportBlockers()` errors stay on the form the redirect leaves. The organiser's resend calls `handle($record)` (`app/Filament/Organizer/Resources/Submissions/Tables/SubmissionActions.php:72-86`). `tests/Unit/EnvExampleTest.php:44-61` fails for any `env()` in `config/*.php` missing from `.env.example`.

### Task 1: No invitations or reminders from a pending or suspended organisation (B9)
Row B9: `InviteReviewer`, `InviteMember` and the reminder sender refuse when the organisation is not approved, with the two reasons `PublishConference` gives (fact 4). A pasted list says it once (fact 5). `manualBlockers()` puts the new reasons first, before the queue scan; `automatic()` skips quietly, because the hourly pass loops every conference (fact 6).

**Files:** create `tests/Unit/OrganizationMailBlockersTest.php`; modify `app/Actions/Reviewers/InviteReviewer.php`, `app/Actions/Reviewers/InviteReviewerList.php`, `app/Actions/Organizations/InviteMember.php`, `app/Actions/Reviews/SendReviewerReminders.php`, `lang/en/reviewer.php`, `lang/en/members.php`.
- [ ] **Step 1: The failing test, run red.** Imports: `App\Actions\Organizations\InviteMember`, `App\Actions\Reviewers\InviteReviewer`, `App\Actions\Reviewers\InviteReviewerList`, `App\Actions\Reviews\SendReviewerReminders`, `App\Enums\ConferenceStatus`, `App\Enums\OrganizationRole`, `App\Enums\OrganizationStatus`, `App\Exceptions\MemberChangeRefused`, `App\Models\Conference`, `App\Models\ConferenceReviewer`, `App\Models\Organization`, `App\Models\OrganizationInvitation`, `App\Models\ReviewerInvitation`, `App\Models\Submission`, `App\Models\User`, `Carbon\Carbon`, `Illuminate\Foundation\Testing\RefreshDatabase`, `Illuminate\Support\Facades\DB`, `Illuminate\Support\Facades\Mail`, `Illuminate\Support\Facades\Notification`, `Illuminate\Support\Facades\RateLimiter`; `use function Pest\Laravel\artisan;`.
```php
uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    $this->owner = User::factory()->create();
    $this->organization = Organization::factory()->approved()->create();
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
    // In review, one reviewer behind, 07:00 in Riyadh seven days before the deadline: a reminder is due.
    $this->conference = Conference::factory()->for($this->organization)
        ->create(['status' => ConferenceStatus::Reviewing, 'timezone' => 'Asia/Riyadh', 'review_deadline' => Carbon::parse('2026-11-03 20:59:00', 'UTC')]);
    ConferenceReviewer::factory()->for($this->conference)->create();
    Submission::factory()->for($this->conference)->submitted()->create();
    Carbon::setTestNow(Carbon::parse('2026-10-27 04:00:00', 'UTC'));
    $this->setStatus = fn (OrganizationStatus $status) => $this->organization->forceFill(['status' => $status])->save();
});

dataset('organization not approved', [
    'pending' => [OrganizationStatus::Pending, 'organization_pending'],
    'suspended' => [OrganizationStatus::Suspended, 'organization_suspended'],
]);

it('refuses a reviewer invitation, and a pasted list once, and sends nothing', function (OrganizationStatus $status, string $reason) {
    ($this->setStatus)($status);
    $conference = $this->conference->fresh();

    expect(app(InviteReviewer::class)->blockers($conference, 'new@example.org', $this->owner))->toBe([__('reviewer.errors.'.$reason)])
        ->and(fn () => app(InviteReviewer::class)->handle($conference, 'new@example.org', null, null, $this->owner))
        ->toThrow(MemberChangeRefused::class, __('reviewer.errors.'.$reason))
        ->and(app(InviteReviewerList::class)->handle($conference, "a@example.org\nb@example.org", $this->owner))
        ->toBe(['invited' => 0, 'skipped' => [], 'errors' => [__('reviewer.errors.'.$reason)]])
        ->and(ReviewerInvitation::query()->count())->toBe(0)
        ->and(RateLimiter::attempts('invitation-send:'.$this->owner->getKey()))->toBe(0);
    Mail::assertNothingQueued();
})->with('organization not approved');

it('refuses a member invitation and sends nothing', function (OrganizationStatus $status, string $reason) {
    ($this->setStatus)($status);

    expect(app(InviteMember::class)->blockers($this->organization, 'new@example.org', OrganizationRole::Member, $this->owner))
        ->toBe([__('members.errors.'.$reason)])
        ->and(fn () => app(InviteMember::class)->handle($this->organization, 'new@example.org', OrganizationRole::Member, $this->owner))
        ->toThrow(MemberChangeRefused::class, __('members.errors.'.$reason))
        ->and(OrganizationInvitation::query()->count())->toBe(0)
        ->and(RateLimiter::attempts('invitation-send:'.$this->owner->getKey()))->toBe(0);
    Notification::assertNothingSent();
})->with('organization not approved');

it('refuses a manual reminder before it scans any reviewer queue', function (OrganizationStatus $status, string $reason) {
    ($this->setStatus)($status);
    $conference = $this->conference->fresh();
    DB::enableQueryLog();

    // The queue scan is the only part of manualBlockers() that reads submissions.
    expect(app(SendReviewerReminders::class)->manualBlockers($conference))->toBe([__('reviewer.remind.errors.'.$reason)])
        ->and(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains((string) $query['query'], 'submissions')))->toBeEmpty();
})->with('organization not approved');

it('skips the automatic reminder without failing the hourly pass, and sends it once approved', function (OrganizationStatus $status) {
    ($this->setStatus)($status);

    expect(app(SendReviewerReminders::class)->automatic($this->conference->fresh()))->toBe(['sent' => 0, 'threshold' => null]);
    artisan('cass:reviewer-reminders')->assertExitCode(0);
    Mail::assertNothingQueued();

    ($this->setStatus)(OrganizationStatus::Approved);
    expect(app(SendReviewerReminders::class)->automatic($this->conference->fresh())['sent'])->toBe(1);
})->with('organization not approved');
```
Run it with the filter `OrganizationMailBlockersTest`. Expected: `rc=1`, 8 failed, each "Failed asserting that two arrays are identical" (`[]` where a reason belongs; `sent => 1` from the automatic pass).

- [ ] **Step 2: The blockers.** `InviteReviewer`: import `App\Enums\OrganizationStatus`; in `blockers()`, directly after the `not_allowed` check, add `$reasons = [...$reasons, ...$this->organizationBlockers($conference)];`; between `blockers()` and `handle()`, add the first part of the block below. `InviteReviewerList::handle()` opens with the second part and a blank line, before `$parsed = ReviewerList::parse($text);`. `InviteMember`: import `App\Enums\OrganizationStatus`; in `blockers()`, after the `owner_grants_owner` check, the third part.
```php
    /**
     * No invitation in the name of an organization that is not approved: the
     * two reasons PublishConference::blockers() gives. Public so that
     * InviteReviewerList refuses a pasted list once, not once per line.
     *
     * @return list<string>
     */
    public function organizationBlockers(Conference $conference): array
    {
        return match ($conference->organization?->status) {
            OrganizationStatus::Pending => [__('reviewer.errors.organization_pending')],
            OrganizationStatus::Suspended => [__('reviewer.errors.organization_suspended')],
            default => [],
        };
    }
// InviteReviewerList::handle(), its first lines:
        // Every line would be refused for the same reason, so say it once.
        $refused = $this->inviteReviewer->organizationBlockers($conference);

        if ($refused !== []) {
            return ['invited' => 0, 'skipped' => [], 'errors' => $refused];
        }
// InviteMember::blockers(), after the `owner_grants_owner` check:
        // No invitation in the organization's name before approval or while suspended.
        $reasons = [...$reasons, ...match ($organization->status) {
            OrganizationStatus::Pending => [__('members.errors.organization_pending')],
            OrganizationStatus::Suspended => [__('members.errors.organization_suspended')],
            default => [],
        }];
```
`SendReviewerReminders`: import `App\Enums\OrganizationStatus`. In `automatic()`, after the `ConferenceStatus::Reviewing` early return, add the first part of the block below; in `manualBlockers()`, replace `$reasons = [];` with the second; in the comment above `if ($reasons !== [])`, "The two reasons above are constant time and already hide the button, so only ask the expensive question when neither applies." becomes "Every reason above is constant time and already hides the button, so only ask the expensive question when none applies." The language closes the block, each pair after the line named.
```php
        // Skipped, not refused: the hourly pass loops every conference in review,
        // and a pending, suspended or deleted organization sends no mail.
        if ($conference->organization?->isApproved() !== true) {
            return ['sent' => 0, 'threshold' => null];
        }
// SendReviewerReminders::manualBlockers(), in place of `$reasons = [];`:
        $reasons = match ($conference->organization?->status) {
            OrganizationStatus::Pending => [__('reviewer.remind.errors.organization_pending')],
            OrganizationStatus::Suspended => [__('reviewer.remind.errors.organization_suspended')],
            default => [],
        };
// lang/en/reviewer.php, `errors`, after 'send_limit':
        'organization_pending' => 'Your organization is still waiting for platform approval. You can invite reviewers as soon as it is approved.',
        'organization_suspended' => 'This organization is suspended, so it cannot invite reviewers.',
// lang/en/reviewer.php, `remind.errors`, after 'nobody_behind':
            'organization_pending' => 'Your organization is still waiting for platform approval, so reviewers cannot be emailed yet.',
            'organization_suspended' => 'This organization is suspended, so reviewers cannot be emailed.',
// lang/en/members.php, `errors`, after 'send_limit':
        'organization_pending' => 'Your organization is still waiting for platform approval. You can invite members as soon as it is approved.',
        'organization_suspended' => 'This organization is suspended, so it cannot invite members.',
```

- [ ] **Step 3: Green, the gates (N=1), commit.** Expected: the filter `rc=0`, 8 passed; `test rc=0` at baseline + 8, `stan rc=0`, `pint rc=0`. Commit the seven files with the subject `feat(organizations): no invitations or reviewer reminders before approval or while suspended` and the body "Both invitations and both reminder paths refuse a pending or suspended organization with the reasons PublishConference gives; a pasted list says it once and the hourly pass skips quietly."

### Task 2: Cap the status-link emails of the public draft form (B10)
Row B10 and its decisions: the per-address count is read from `email_logs` (fact 17), the per-IP count lives in the rate limiter keyed by `ClientIp`, with a 24-hour decay and a hit only after a send (fact 16), and both are `CASS_*` knobs. `handleForAuthor()` is a new method rather than a nullable `$clientIp` on `handle()`, so the organiser's resend keeps its signature and cannot be capped by a caller passing an address, and the form's one question is whether a link went out (`null`: none did). `.env.example` changes here, not in Task 6, because `EnvExampleTest` fails this task's gate otherwise (fact 18).

**Files:** create `tests/Feature/Public/StatusLinkCapTest.php`; modify `tests/Feature/Organizer/SubmissionResourceTest.php`, `app/Actions/Submissions/SendSubmissionStatusLink.php`, `app/Livewire/Public/SubmissionForm.php`, `config/cass.php`, `.env.example`, `docker-compose.production.yml`, `lang/en/submission.php`.
- [ ] **Step 1: The failing tests, run red.** Imports: `App\Actions\Submissions\SendSubmissionStatusLink`, `App\Livewire\Public\SubmissionForm`, `App\Mail\TemplatedMail`, `App\Models\Conference`, `App\Models\EmailLog`, `App\Models\Organization`, `App\Models\Submission`, `Illuminate\Support\Facades\Mail`, `Illuminate\Support\Facades\RateLimiter`; `use function Pest\Livewire\livewire;`.
```php
beforeEach(function () {
    Mail::fake();
    // Colours as SubmissionFormTest sets them: the layout reads both.
    $this->organization = Organization::factory()->approved()->create(['primary_color' => '#0F4C8A', 'accent_color' => '#B45309']);
    $this->conference = Conference::factory()->for($this->organization)->published()->create();
    // A fresh form each time, so every save is a new draft that emails its link.
    $this->saveDraft = fn (string $email) => livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->set('title', 'A work in progress')->set('authors.0.email', $email)
        ->call('saveDraft')->assertHasNoErrors()->assertRedirectContains('/s/');
    $this->linksTo = fn (string $email): int => EmailLog::query()->where('to_email', $email)->where('template_key', 'submission_draft_saved')->count();
});

it('emails one address three links a day, saves a fourth draft without one, and emails again a day later', function () {
    foreach (range(1, 3) as $save) {
        ($this->saveDraft)('sara@example.org');
    }
    expect(($this->linksTo)('sara@example.org'))->toBe(3)->and(session('status'))->toBe(__('submission.flash.draft_saved'));

    // 23 hours on, still inside the day: a shorter window would email here.
    $this->travel(23)->hours();
    ($this->saveDraft)('sara@example.org');
    expect(Submission::query()->count())->toBe(4)
        ->and(($this->linksTo)('sara@example.org'))->toBe(3)
        ->and(session('status'))->toBe(__('submission.flash.draft_not_emailed'))
        // Three sends, three hits: the address-cap refusal spent nothing of the shared IP budget.
        ->and(RateLimiter::attempts('status-link:127.0.0.1'))->toBe(3);
    Mail::assertQueued(TemplatedMail::class, 3);

    // A day and a minute after the first three, the address may have one again.
    $this->travel(61)->minutes();
    ($this->saveDraft)('sara@example.org');
    expect(($this->linksTo)('sara@example.org'))->toBe(4)->and(session('status'))->toBe(__('submission.flash.draft_saved'));
});

it('counts a failed link and a submit receipt to the address, and no other template', function () {
    EmailLog::factory()->failed()->create(['to_email' => 'sara@example.org', 'template_key' => 'submission_received']);
    EmailLog::factory()->create(['to_email' => 'sara@example.org', 'template_key' => 'submission_draft_saved']);
    EmailLog::factory()->count(3)->create(['to_email' => 'sara@example.org', 'template_key' => 'reviewer_invitation']);

    ($this->saveDraft)('sara@example.org');
    expect(session('status'))->toBe(__('submission.flash.draft_saved'));
    ($this->saveDraft)('sara@example.org');
    expect(session('status'))->toBe(__('submission.flash.draft_not_emailed'));
    Mail::assertQueued(TemplatedMail::class, 1);
});

it('caps the links one client IP can send for a day, whoever they are addressed to', function () {
    config()->set('cass.status_link.per_ip_per_day', 2);

    ($this->saveDraft)('one@example.org');
    ($this->saveDraft)('two@example.org');
    // 23 hours on, still inside this IP's day: a shorter decay would email here.
    $this->travel(23)->hours();
    ($this->saveDraft)('three@example.org');
    expect(Submission::query()->count())->toBe(3)
        ->and(($this->linksTo)('three@example.org'))->toBe(0)
        ->and(session('status'))->toBe(__('submission.flash.draft_not_emailed'));
    Mail::assertQueued(TemplatedMail::class, 2);

    // Keyed by IP: another client still gets its link while this one is capped.
    expect(app(SendSubmissionStatusLink::class)->handleForAuthor(Submission::query()->latest('id')->firstOrFail(), null, '203.0.113.7'))->not->toBeNull();

    // 24 hours after this IP's first link its count is gone.
    $this->travel(61)->minutes();
    ($this->saveDraft)('four@example.org');
    expect(($this->linksTo)('four@example.org'))->toBe(1);
});
```
Append to `tests/Feature/Organizer/SubmissionResourceTest.php`, importing `App\Models\EmailLog` after `App\Models\ConferenceReviewer`. It pins the unchanged resend, so it passes before and after:
```php
it('resends a status link however many the public form has sent the author today', function () {
    EmailLog::factory()->count(3)->create(['to_email' => 'sara@example.org', 'template_key' => 'submission_draft_saved']);

    livewire(ViewSubmission::class, ['record' => $this->submission->getRouteKey()])
        ->callAction('resendLink')
        ->assertHasNoActionErrors();

    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('sara@example.org'));
});
```
Run them with the filter `StatusLinkCapTest|resends a status link however many`. Expected: `rc=1`, 3 failed ("4 is identical to 3", "two strings are identical", "1 is identical to 0"), 1 passed (the pin).

- [ ] **Step 2: The cap.** `SendSubmissionStatusLink`: import `Illuminate\Support\Facades\RateLimiter`; add after `handle()` the first part of the PHP block below. `SubmissionForm::saveDraft()` (fact 18): replace the two comment lines above the `try` and its line `$sendLink->handle($link->submission, $link->token);` with the second part, keeping the `catch` and its comment; at the end of the method the flash becomes `session()->flash('status', $emailed ? __('submission.flash.draft_saved') : __('submission.flash.draft_not_emailed'));`. `config/cass.php`, after `status_page_rate_limit`, the third part. `.env.example`, after `CASS_STATUS_PAGE_RATE_LIMIT=20`: the comment `# Status-link emails the public draft form allows per address (submit receipts and organizer resends count) and per client IP in 24 hours; past either cap the draft is saved and no email is sent.` (two lines, wrapped like its neighbours), then `CASS_STATUS_LINK_PER_ADDRESS_PER_DAY=3` and `CASS_STATUS_LINK_PER_IP_PER_DAY=10`. `lang/en/submission.php`, `flash`, after `draft_saved`: `'draft_not_emailed' => 'Your draft is saved, but we have not emailed you a link: too many have been sent in the last day. This page is your link, so bookmark it or copy its address to come back to your abstract.',`. The YAML block goes in `docker-compose.production.yml`, in the `app` service's `environment:` directly after `CASS_CSP_REPORT_ONLY: ${CASS_CSP_REPORT_ONLY:-false}`. That is a compose file, which `tools/claude/README.md` ("Without the owner in the session") lets an agent edit only with the owner's go-ahead; the owner's approval of this plan's summary, which lists it under Sensitive files touched, is that go-ahead. Without it, leave the compose file alone and use the fallback sentences in Task 6, Step 2.
```php
    /**
     * The public draft form's send; handle() is the organizer's resend and is
     * never capped. Null, with nothing written, once the address has had its
     * day's links (counted in email_logs, which survive a deploy) or the client
     * IP has sent its day's worth (counted in the rate limiter, sends only).
     */
    public function handleForAuthor(Submission $submission, ?string $plainToken, string $clientIp): ?EmailLog
    {
        $ipKey = 'status-link:'.$clientIp;
        $sentToAddress = EmailLog::query()
            ->where('to_email', (string) $submission->correspondingAuthor()?->email)
            ->whereIn('template_key', [EmailTemplateKey::SubmissionDraftSaved->value, EmailTemplateKey::SubmissionReceived->value])
            ->where('created_at', '>', now()->subDay())
            ->count();

        if ($sentToAddress >= (int) config('cass.status_link.per_address_per_day')
            || RateLimiter::tooManyAttempts($ipKey, (int) config('cass.status_link.per_ip_per_day'))) {
            return null;
        }

        $log = $this->handle($submission, $plainToken);

        // After the send, so a refusal or a throw spends nothing. hit() sets the
        // decay on the first hit only (cache->add in RateLimiter::increment()):
        // the window is the 24 hours after this IP's first link, not a sliding day.
        RateLimiter::hit($ipKey, 86400);

        return $log;
    }
// SubmissionForm::saveDraft(), in place of the two comment lines above the `try` and its send line:
        // The same token the redirect uses, so the emailed link and the page
        // the author lands on are one URL. Null when a daily cap refused it:
        // the draft is saved and the flash below says no email was sent.
        $emailed = true;

        try {
            $emailed = $sendLink->handleForAuthor($link->submission, $link->token, ClientIp::from(request())) !== null;
// config/cass.php, after `status_page_rate_limit`:
    // The public draft form emails its status link to any address typed, so it
    // is capped; the organizer's "Resend status link" never is.
    'status_link' => [
        // Per address in 24 hours, from email_logs: every status-link row counts.
        'per_address_per_day' => (int) env('CASS_STATUS_LINK_PER_ADDRESS_PER_DAY', 3),
        // Per client IP (App\Support\ClientIp), for 24 hours from its first link.
        'per_ip_per_day' => (int) env('CASS_STATUS_LINK_PER_IP_PER_DAY', 10),
    ],
```
```yaml
      # The public draft form's status-link caps (config/cass.php, status_link).
      # Optional. Named here because a Coolify variable reaches the container
      # only through ${...} in this file. Keep the explicit defaults: ${VAR:-}
      # would hand env() an empty string, which (int) reads as a cap of 0.
      CASS_STATUS_LINK_PER_ADDRESS_PER_DAY: ${CASS_STATUS_LINK_PER_ADDRESS_PER_DAY:-3}
      CASS_STATUS_LINK_PER_IP_PER_DAY: ${CASS_STATUS_LINK_PER_IP_PER_DAY:-10}
```

- [ ] **Step 3: Green, the gates (N=2), commit.** Expected: the filter `rc=0`, 4 passed; `test rc=0` at baseline + 12 (`SubmissionFormTest`'s five saves to one address still pass: they count submissions, not mail), `stan rc=0`, `pint rc=0`. Commit the eight files (seven without the compose go-ahead; without it, drop ", and the production compose file passes both to the app container" from the body, and the closing CI image-job sentence does not hold) with the subject `feat(submissions): cap the status links the public draft form emails` and the body "CASS_STATUS_LINK_PER_ADDRESS_PER_DAY (3, from email_logs) and CASS_STATUS_LINK_PER_IP_PER_DAY (10, rate limiter); past either cap the draft is saved without an email, and the production compose file passes both to the app container. The organizer's resend is unchanged." CI's `image` job runs on the pull request because a compose file changed.

### Task 3: `UpdateConference`, the one writer of the conference edit form (B11 a and b)
Decision 0010: a status-dependent rule cannot live in Filament's default save, so it arrives with this Action. It does what the default save did (`fill()` then `save()`, fact 8), refuses a changed review setting while locked (fact 11), and names the actor as causer of the model's own deadline entry (fact 9). Membership in the conference's organisation is the cross-tenant check. Task 4 wires it to the page.

**Files:** create `app/Actions/Conferences/UpdateConference.php`, `app/Exceptions/ConferenceUpdateRefused.php`, `tests/Unit/UpdateConferenceTest.php`; modify `lang/en/organizer.php`.
- [ ] **Step 1: The failing test, run red.** Imports: `App\Actions\Conferences\UpdateConference`, `App\Enums\ConferenceStatus`, `App\Enums\OrganizationRole`, `App\Enums\ReviewMode`, `App\Exceptions\ConferenceUpdateRefused`, `App\Models\Conference`, `App\Models\Organization`, `App\Models\Review`, `App\Models\Submission`, `App\Models\User`, `Carbon\Carbon`, `Illuminate\Foundation\Testing\RefreshDatabase`. The review-row lock's scope is proved here and its effect on the form in Task 4.
```php
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->owner = User::factory()->create();
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
    $this->make = fn (ConferenceStatus $status): Conference => Conference::factory()->for($this->organization)
        ->create(['status' => $status, 'review_mode' => ReviewMode::OpenPool, 'blind_review' => true, 'review_deadline' => Carbon::parse('2026-11-03 20:59:00', 'UTC')]);
    $this->change = ['review_mode' => ReviewMode::Assigned->value, 'blind_review' => false];
    $this->refusals = [__('organizer.update.errors.review_mode_locked'), __('organizer.update.errors.blind_review_locked')];
});

it('refuses a change to either review setting from reviewing onward, and not before', function (ConferenceStatus $status, bool $locked) {
    $conference = ($this->make)($status);

    expect(UpdateConference::reviewSettingsLocked($conference))->toBe($locked)
        ->and(app(UpdateConference::class)->blockers($conference, $this->change, $this->owner))->toBe($locked ? $this->refusals : [])
        // The stored values, as the disabled fields send them back, are never a change.
        ->and(app(UpdateConference::class)->blockers($conference, ['review_mode' => ReviewMode::OpenPool, 'blind_review' => true], $this->owner))->toBe([]);
})->with([
    'draft' => [ConferenceStatus::Draft, false], 'open' => [ConferenceStatus::Open, false], 'closed' => [ConferenceStatus::Closed, false],
    'reviewing' => [ConferenceStatus::Reviewing, true], 'decided' => [ConferenceStatus::Decided, true], 'archived' => [ConferenceStatus::Archived, true],
]);

it('saves an open conference\'s settings and names the actor as causer of a deadline edit', function () {
    $conference = ($this->make)(ConferenceStatus::Open);

    // Nobody is signed in: the causer comes from the action, not the auth guard.
    app(UpdateConference::class)->handle($conference, [...$this->change, 'review_deadline' => '2026-11-10 20:59:00'], $this->owner);
    $entry = $conference->activitiesAsSubject()->where('event', 'updated')->sole();

    expect($conference->fresh()?->review_mode)->toBe(ReviewMode::Assigned)
        ->and($entry->causer_id)->toBe($this->owner->id)
        ->and(Carbon::parse($entry->attribute_changes['old']['review_deadline'])->equalTo(Carbon::parse('2026-11-03 20:59:00', 'UTC')))->toBeTrue()
        ->and(Carbon::parse($entry->attribute_changes['attributes']['review_deadline'])->equalTo(Carbon::parse('2026-11-10 20:59:00', 'UTC')))->toBeTrue();
});

it('refuses an edit by a member of another organization', function () {
    $conference = ($this->make)(ConferenceStatus::Draft);
    $stranger = User::factory()->create();
    Organization::factory()->approved()->create()->addMember($stranger, OrganizationRole::Owner);

    expect(fn () => app(UpdateConference::class)->handle($conference, ['name' => 'Taken over'], $stranger))
        ->toThrow(ConferenceUpdateRefused::class, __('organizer.update.errors.not_allowed'))
        ->and($conference->fresh()?->name)->not->toBe('Taken over');
});

it('locks on a review of this conference, trashed abstracts included, and never on a review of another conference', function () {
    $conference = ($this->make)(ConferenceStatus::Closed);
    $sibling = ($this->make)(ConferenceStatus::Closed);
    Review::factory()->for(Submission::factory()->for($sibling)->submitted())->create();

    expect(UpdateConference::reviewSettingsLocked($conference))->toBeFalse()
        ->and(UpdateConference::reviewSettingsLocked($sibling))->toBeTrue();

    $trashed = Submission::factory()->for($conference)->submitted()->create();
    Review::factory()->for($trashed)->create();
    $trashed->delete();

    expect(UpdateConference::reviewSettingsLocked($conference))->toBeTrue()
        ->and(app(UpdateConference::class)->blockers($conference, $this->change, $this->owner))->toBe($this->refusals);
});
```
Run it with the filter `UpdateConferenceTest`. Expected: `rc=2`, 9 failed. The seven cases that open on a static call fail with `Class "App\Actions\Conferences\UpdateConference" not found`. The save case fails with `BindingResolutionException` "Target class [App\Actions\Conferences\UpdateConference] does not exist.". The outsider case fails its `toThrow`: while `ConferenceUpdateRefused` does not exist, Pest looks for that class name in the same exception's message ("Expected: Target class [...] does not exist." then "To contain: App\Exceptions\ConferenceUpdateRefused").

- [ ] **Step 2: The Action.** `app/Exceptions/ConferenceUpdateRefused.php` is `app/Exceptions/MemberChangeRefused.php` with the class renamed: `final`, extends `RuntimeException`, `public readonly array $reasons` (`list<string>`), message `implode(' ', $reasons)`. `app/Actions/Conferences/UpdateConference.php`, namespace `App\Actions\Conferences`, imports `App\Enums\ConferenceStatus`, `App\Enums\ReviewMode`, `App\Exceptions\ConferenceUpdateRefused`, `App\Models\Conference`, `App\Models\Review`, `App\Models\Submission`, `App\Models\User`, `Spatie\Activitylog\Support\CauserResolver`, is the class below. `lang/en/organizer.php` gains, after `publish`, an `update` group laid out like its neighbours: `'refused' => 'Nothing was saved',`, then an `errors` array holding `'not_allowed' => 'Only a member of this organization can edit its conferences.',`, `'review_mode_locked' => 'The review mode cannot change once reviewing has started or a review has been written.',` and `'blind_review_locked' => 'Blind review cannot be switched on or off once reviewing has started or a review has been written.',`.
```php
/**
 * The one writer of the organizer's conference edit form: Filament's own save plus the
 * review-settings lock, and the actor as causer of the model's deadline activity entry.
 */
class UpdateConference
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<string> empty when the edit may be saved
     */
    public function blockers(Conference $conference, array $data, User $actor): array
    {
        $organization = $conference->organization;

        if ($organization === null || $actor->roleIn($organization) === null) {
            return [__('organizer.update.errors.not_allowed')];
        }

        if (! self::reviewSettingsLocked($conference)) {
            return [];
        }

        $reasons = [];
        $mode = $data['review_mode'] ?? null;
        $mode = $mode instanceof ReviewMode ? $mode : (is_string($mode) ? ReviewMode::tryFrom($mode) : null);

        if (array_key_exists('review_mode', $data) && $mode !== $conference->review_mode) {
            $reasons[] = __('organizer.update.errors.review_mode_locked');
        }

        if (array_key_exists('blind_review', $data) && (bool) $data['blind_review'] !== $conference->blind_review) {
            $reasons[] = __('organizer.update.errors.blind_review_locked');
        }

        return $reasons;
    }

    /** @param  array<string, mixed>  $data */
    public function handle(Conference $conference, array $data, User $actor): Conference
    {
        $reasons = $this->blockers($conference, $data, $actor);

        if ($reasons !== []) {
            throw new ConferenceUpdateRefused($reasons);
        }

        // As Filament did: $fillable keeps `status` out, and a stray key throws outside production.
        $conference->fill($data);

        // Not the auth guard's user, who is the actor on the panel and nobody elsewhere.
        app(CauserResolver::class)->withCauser($actor, fn (): bool => $conference->save());

        return $conference;
    }

    /**
     * Both settings change what a written review meant, so they lock from
     * `reviewing` on and while any review exists (Reviewing -> Closed is allowed).
     */
    public static function reviewSettingsLocked(Conference $conference): bool
    {
        if (in_array($conference->status, [ConferenceStatus::Reviewing, ConferenceStatus::Decided, ConferenceStatus::Archived], true)) {
            return true;
        }

        return Review::query()
            ->whereIn('submission_id', Submission::withTrashed()->select('id')->where('conference_id', $conference->getKey()))
            ->exists();
    }
}
```

- [ ] **Step 3: Green, the gates (N=3), commit.** Expected: the filter `rc=0`, 9 passed; `test rc=0` at baseline + 21, `stan rc=0`, `pint rc=0`. Commit the four files with the subject `feat(conferences): UpdateConference, the one writer of the edit form` and the body "Locks review_mode and blind_review from reviewing on and while a review exists, refuses outsiders, and names the actor as causer."

### Task 4: Lock review mode and blind review on the edit form (B11 a)
`EditConference::handleRecordUpdate()` calls `UpdateConference` and turns a refusal into a notification plus `Halt` (fact 8). The two fields are disabled when locked yet still sent, so a change made on a page opened before reviewing started is refused with a message instead of vanishing (fact 10). The lock is asked once per request through `EditConference::reviewSettingsLocked()`, a non-public memo, because the form reads it many times per render (fact 12); `UpdateConference` asks again before saving. The helper text is constant, so organisers read the rule before it bites. The view page's header and the list table's rows each carry an `EditAction` drawn as a link to this page. A hand-made `mountAction('edit')` still mounts it (`vendor/filament/actions/src/Concerns/InteractsWithActions.php:224-251` asks only `isDisabled()`) and saves with `$record->update($data)` (`vendor/filament/actions/src/EditAction.php:81-111`), where the form's lock is off because `$livewire` is not an `EditConference`. Both doors share `ConferenceStatusActions::edit()`, whose `using()` saves through `UpdateConference`.

**Files:** create `tests/Feature/Organizer/ConferenceReviewSettingsTest.php`; modify `app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceForm.php`, `app/Filament/Organizer/Resources/Conferences/Pages/EditConference.php`, `app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php`, `app/Filament/Organizer/Resources/Conferences/Pages/ViewConference.php`, `app/Filament/Organizer/Resources/Conferences/Tables/ConferencesTable.php`, `lang/en/organizer.php`.
- [ ] **Step 1: The failing test, run red.** Imports: `App\Enums\ConferenceStatus`, `App\Enums\OrganizationRole`, `App\Enums\ReviewMode`, `App\Filament\Organizer\Resources\Conferences\Pages\EditConference`, `App\Filament\Organizer\Resources\Conferences\Pages\ListConferences`, `App\Filament\Organizer\Resources\Conferences\Pages\ViewConference`, `App\Models\Conference`, `App\Models\Organization`, `App\Models\Review`, `App\Models\Submission`, `App\Models\User`, `Carbon\Carbon`, `Illuminate\Support\Facades\DB`; `use function Pest\Laravel\actingAs;`, `use function Pest\Livewire\livewire;`.
```php
beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);
    // Whole-minute dates, so a save changes only what a test types.
    $this->make = fn (ConferenceStatus $status): Conference => Conference::factory()->for($this->organization)->create([
        'status' => $status, 'submission_opens_at' => Carbon::parse('2026-09-01 00:00:00', 'UTC'),
        'submission_deadline' => Carbon::parse('2026-10-01 20:59:00', 'UTC'), 'review_deadline' => Carbon::parse('2026-11-03 20:59:00', 'UTC'),
    ]);
    $this->edit = fn (Conference $conference) => livewire(EditConference::class, ['record' => $conference->getRouteKey()]);
});

it('disables both review settings from reviewing onward, and not before', function (ConferenceStatus $status, bool $locked) {
    $page = ($this->edit)(($this->make)($status))->assertSee(__('organizer.review_settings.locked'));

    $locked
        ? $page->assertFormFieldDisabled('review_mode')->assertFormFieldDisabled('blind_review')
        : $page->assertFormFieldEnabled('review_mode')->assertFormFieldEnabled('blind_review');
})->with(['closed' => [ConferenceStatus::Closed, false], 'reviewing' => [ConferenceStatus::Reviewing, true]]);

it('locks a closed conference with a review, asking the database once per request', function () {
    $conference = ($this->make)(ConferenceStatus::Closed);
    Review::factory()->for(Submission::factory()->for($conference)->submitted())->create();
    DB::enableQueryLog();

    $page = ($this->edit)($conference)->assertFormFieldDisabled('review_mode')->assertFormFieldDisabled('blind_review');
    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/["`]reviews["`]/', (string) $query['query']) === 1))->toHaveCount(1);
    $page->fillForm(['review_mode' => ReviewMode::Assigned->value])->call('save')->assertNotified(__('organizer.update.refused'));
    expect($conference->fresh()?->review_mode)->toBe(ReviewMode::OpenPool);
});

it('refuses a forged change to a locked setting and saves nothing from that form', function () {
    $conference = ($this->make)(ConferenceStatus::Reviewing);

    ($this->edit)($conference)->fillForm(['blind_review' => false, 'name' => 'Renamed while refused'])
        ->call('save')->assertNotified(__('organizer.update.refused'));
    expect($conference->fresh()?->blind_review)->toBeTrue()->and($conference->fresh()?->name)->not->toBe('Renamed while refused');
});

it('keeps the deadlines editable while reviewing and logs the edit once, with the organizer as causer', function () {
    $conference = ($this->make)(ConferenceStatus::Reviewing);

    // Typed in the conference's timezone, Asia/Riyadh (UTC+3), and stored in UTC.
    ($this->edit)($conference)->fillForm(['review_deadline' => '2026-11-10 23:59:00'])->call('save')->assertHasNoFormErrors();
    expect($conference->fresh()?->review_deadline?->equalTo(Carbon::parse('2026-11-10 20:59:00', 'UTC')))->toBeTrue()
        ->and($conference->activitiesAsSubject()->where('event', 'updated')->sole()->causer_id)->toBe($this->user->id);
});

it('refuses the same forged change through the view page and the list table', function () {
    $conference = ($this->make)(ConferenceStatus::Reviewing);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->mountAction('edit')->setActionData(['blind_review' => false])->callMountedAction()
        ->assertNotified(__('organizer.update.refused'));
    livewire(ListConferences::class)
        ->mountTableAction('edit', $conference)->setTableActionData(['review_mode' => ReviewMode::Assigned->value])
        ->callMountedTableAction()->assertNotified(__('organizer.update.refused'));

    expect($conference->fresh()?->blind_review)->toBeTrue()
        ->and($conference->fresh()?->review_mode)->toBe(ReviewMode::OpenPool);
});
```
Run it with the filter `ConferenceReviewSettingsTest`. Expected: `rc=1`, 5 failed (no helper text; fields enabled; no notification; the view page and the table still save), 1 passed: the deadline case pins fact 9.

- [ ] **Step 2: The form and the page.** `ConferenceForm`: import `App\Filament\Organizer\Resources\Conferences\Pages\EditConference`; append the first part of the block below to the `Select::make('review_mode')` chain after `->columnSpanFull()`, and the same three calls, without the comment, to `Toggle::make('blind_review')` after its `->label(...)`. `EditConference`: imports `App\Actions\Conferences\UpdateConference`, `App\Exceptions\ConferenceUpdateRefused`, `App\Models\Conference`, `App\Models\User`, `Filament\Notifications\Notification`, `Filament\Support\Exceptions\Halt`, `Illuminate\Database\Eloquent\Model`; after `$resource`, the property and `reviewSettingsLocked()` from the `// EditConference` part; after `getHeaderActions()`, its `handleRecordUpdate()`. `ConferenceStatusActions` imports, in alphabetical order, `App\Actions\Conferences\UpdateConference`, `App\Exceptions\ConferenceUpdateRefused`, `Filament\Actions\EditAction` and `Filament\Support\Exceptions\Halt` (it already imports `Conference`, `User` and `Notification`), and gains the block's last part, `edit()`, before `all()`. In `ViewConference::getHeaderActions()` and in `ConferencesTable`'s `recordActions()`, `EditAction::make(),` becomes `ConferenceStatusActions::edit(),`; delete both files' now-unused `use Filament\Actions\EditAction;`, because Pint's laravel preset fails on unused imports (`ViewConference` already imports `ConferenceStatusActions`, and `ConferencesTable` shares its namespace). `lang/en/organizer.php`, after `update`: `'review_settings' => ['locked' => 'Fixed once reviewing starts or the first review is written, so every review is read under the settings it was written under.'],` laid out over three lines like its neighbours.
```php
                            ->disabled(fn (object $livewire): bool => $livewire instanceof EditConference && $livewire->reviewSettingsLocked())
                            // Sent even while disabled (disabled() alone drops it
                            // from the save), so a change made on a page opened
                            // before the lock is refused by UpdateConference
                            // instead of vanishing.
                            ->dehydrated()
                            ->helperText(__('organizer.review_settings.locked')),
// EditConference:
    /** Not public: asked afresh on every request and never sent to the browser. */
    private ?bool $reviewSettingsLocked = null;

    /** Once per request for the form, which asks often; UpdateConference asks again before saving. */
    public function reviewSettingsLocked(): bool
    {
        /** @var Conference $conference */
        $conference = $this->getRecord();

        return $this->reviewSettingsLocked ??= UpdateConference::reviewSettingsLocked($conference);
    }

    /**
     * UpdateConference rather than the parent's update(): it refuses a locked
     * review setting and names the organizer as causer. A refusal is a
     * notification; Halt ends save() without "Saved" or the redirect.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Conference $record */
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(UpdateConference::class)->handle($record, $data, $actor);
        } catch (ConferenceUpdateRefused $exception) {
            Notification::make()->danger()
                ->title(__('organizer.update.refused'))
                ->body(e($exception->getMessage()))
                ->persistent()
                ->send();

            throw new Halt;
        }
    }
// ConferenceStatusActions, before all():
    /**
     * The Edit button of the view page and the table. Drawn as a link to
     * EditConference, but a hand-made mountAction('edit') still runs Filament's
     * modal save over the same form, so it saves through UpdateConference too.
     */
    public static function edit(): EditAction
    {
        return EditAction::make()->using(function (Conference $record, array $data, UpdateConference $update): Conference {
            /** @var User $actor */
            $actor = auth()->user();

            try {
                return $update->handle($record, $data, $actor);
            } catch (ConferenceUpdateRefused $exception) {
                Notification::make()->danger()->title(__('organizer.update.refused'))
                    ->body(e($exception->getMessage()))->persistent()->send();

                throw new Halt;
            }
        });
    }
```

- [ ] **Step 3: Green, the gates (N=4), commit.** Expected: the filter `rc=0`, 6 passed; `test rc=0` at baseline + 27 (`ConferenceResourceTest`'s edits now save through `UpdateConference` unchanged), `stan rc=0`, `pint rc=0`. Commit the seven files with the subject `feat(conferences): lock review mode and blind review on the edit form` and the body "EditConference and the Edit button's modal (ConferenceStatusActions::edit()) save through UpdateConference and show a refusal as a notification; both fields are disabled from reviewing onward and while a review exists, yet still sent, and the lock is asked once a request."

### Task 5: A track or custom field in use cannot be deleted (B11 c)
`delete()` asks membership first, through a `view()` that now refuses rather than errors when the conference or organisation is out of reach (fact 15), so an outsider's refusal carries no reason; then the reference check, whose denial carries the reason the relation manager shows as a tooltip (fact 13). The custom-field check is one JSON-path query that agrees on SQLite and MySQL (fact 14). Trashed abstracts count: a restore would bring one back without its track or answer.

**Files:** create `tests/Feature/Organizer/ReferencedRowsTest.php`; modify `app/Policies/TrackPolicy.php`, `app/Policies/CustomFieldPolicy.php`, `app/Filament/Organizer/Resources/Conferences/RelationManagers/TracksRelationManager.php`, `app/Filament/Organizer/Resources/Conferences/RelationManagers/CustomFieldsRelationManager.php`, `lang/en/organizer.php`.
- [ ] **Step 1: The failing test, run red.** Imports: `App\Enums\OrganizationRole`, `App\Filament\Organizer\Resources\Conferences\Pages\EditConference`, `App\Filament\Organizer\Resources\Conferences\RelationManagers\CustomFieldsRelationManager`, `App\Filament\Organizer\Resources\Conferences\RelationManagers\TracksRelationManager`, `App\Models\Conference`, `App\Models\CustomField`, `App\Models\Organization`, `App\Models\Submission`, `App\Models\Track`, `App\Models\User`, `Illuminate\Support\Facades\Gate`; `use function Pest\Laravel\actingAs;`, `use function Pest\Livewire\livewire;`.
```php
beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);
    $this->conference = Conference::factory()->for($this->organization)->published()->create();
    $this->manager = fn (string $class) => livewire($class, ['ownerRecord' => $this->conference, 'pageClass' => EditConference::class]);
});

it('keeps a track an abstract is filed under, a withdrawn or trashed abstract included, and says why', function () {
    [$filed, $filedTrashed, $free] = Track::factory()->for($this->conference)->count(3)->create()->all();
    Submission::factory()->for($this->conference)->withdrawn()->create(['track_id' => $filed->id]);
    Submission::factory()->for($this->conference)->create(['track_id' => $filedTrashed->id])->delete();

    expect(Gate::inspect('delete', $filed)->message())->toBe(__('organizer.tracks.in_use'))
        ->and(Gate::inspect('delete', $filedTrashed)->message())->toBe(__('organizer.tracks.in_use'))
        ->and(Gate::allows('delete', $free))->toBeTrue();
    ($this->manager)(TracksRelationManager::class)
        ->assertTableActionDisabled('delete', $filed)->assertTableActionEnabled('delete', $free)
        ->callTableAction('delete', $filed)->callTableAction('delete', $free);
    expect($filed->fresh())->not->toBeNull()->and($free->fresh())->toBeNull();
});

it('keeps a custom field an abstract of this conference answered, and lets an empty or null entry go', function () {
    $answered = CustomField::factory()->for($this->conference)->create(['label' => 'First time presenting']);
    $blank = CustomField::factory()->for($this->conference)->create(['label' => 'Dietary needs']);
    $nulled = CustomField::factory()->for($this->conference)->create(['label' => 'Second language']);
    $trashedOnly = CustomField::factory()->for($this->conference)->create(['label' => 'Ethics approval']);
    // An unticked checkbox is an answer; an emptied text box and a null are not.
    Submission::factory()->for($this->conference)
        ->create(['custom_field_values' => ['first_time_presenting' => false, 'dietary_needs' => '', 'second_language' => null]]);
    // The same key answered in a sibling conference does not count here.
    Submission::factory()->for(Conference::factory()->for($this->organization)->published())
        ->create(['custom_field_values' => ['dietary_needs' => 'Vegetarian']]);
    // Answered only by an abstract in the bin: a restore would bring the answer back.
    Submission::factory()->for($this->conference)->create(['custom_field_values' => ['ethics_approval' => 'Granted']])->delete();

    expect(Gate::inspect('delete', $answered)->message())->toBe(__('organizer.custom_fields.in_use'))
        ->and(Gate::allows('delete', $blank))->toBeTrue()
        ->and(Gate::allows('delete', $nulled))->toBeTrue()
        ->and(Gate::inspect('delete', $trashedOnly)->message())->toBe(__('organizer.custom_fields.in_use'));
    ($this->manager)(CustomFieldsRelationManager::class)
        ->assertTableActionDisabled('delete', $answered)
        ->callTableAction('delete', $answered)->callTableAction('delete', $blank);
    expect($answered->fresh())->not->toBeNull()->and($blank->fresh())->toBeNull();
});

it('refuses another organization\'s referenced track and field without a reason', function () {
    [$theirTrack, $theirField] = withoutTenant(function (): array {
        $conference = Conference::factory()->published()->create();
        $track = Track::factory()->for($conference)->create();
        $field = CustomField::factory()->for($conference)->create(['label' => 'Funding source']);
        Submission::factory()->for($conference)->create(['track_id' => $track->id, 'custom_field_values' => ['funding_source' => 'None']]);

        return [$track, $field];
    });

    // Membership is asked first, so the refusal says nothing about whether the row is in use.
    foreach ([$theirTrack, $theirField] as $row) {
        expect(Gate::inspect('delete', $row)->denied())->toBeTrue()->and(Gate::inspect('delete', $row)->message())->toBeNull();
    }
});
```
Run it with the filter `ReferencedRowsTest`. Expected: `rc=2`, 3 failed: two "Failed asserting that null is identical to" the reason, and the cross-tenant case's `ErrorException` "Attempt to read property "organization" on null" from `TrackPolicy::view()`.

- [ ] **Step 2: The policies and the tooltips.** Both policies import `App\Models\Submission` and `Illuminate\Auth\Access\Response`. In both, `view()` becomes `$organization = $track->conference?->organization;` (`$customField->` in `CustomFieldPolicy`), a blank line, `return $organization !== null && $user->roleIn($organization) !== null;`, under the docblock `/** Null-safe: another tenant's conference is scoped away under the panel, and organizations soft-delete. */` in `TrackPolicy` and `/** Null-safe for the reason TrackPolicy::view() gives. */` in `CustomFieldPolicy`. Their `delete()` methods become the first two parts of the block below. In both `deleteAny()` docblocks "deleteAny mirrors delete();" is now wrong: `TrackPolicy`'s sentence becomes "deleteAny answers by membership alone, because it is handed no row to look for abstracts in; the relation manager offers no bulk delete, and one added later must call authorizeIndividualRecords() so delete() is asked row by row. Tracks are not soft-deletable, so there is nothing to restore or force-delete." and `CustomFieldPolicy`'s "deleteAny answers by membership alone, for the reason TrackPolicy::deleteAny() gives. Custom fields are not soft-deletable, so there is nothing to restore or force-delete." The rest of the block holds the two relation-manager edits and the language.
```php
    /**
     * Not while an abstract, trashed ones included, is filed under the track:
     * submissions.track_id is nullOnDelete. Membership is asked first, so the
     * reason, shown as the button's tooltip, never reaches an outsider.
     */
    public function delete(User $user, Track $track): Response|bool
    {
        if (! $this->view($user, $track)) {
            return false;
        }

        if (Submission::withTrashed()->where('track_id', $track->getKey())->exists()) {
            return Response::deny(__('organizer.tracks.in_use'));
        }

        return true;
    }
// CustomFieldPolicy::delete():
    /**
     * Not while an abstract of the conference, trashed included, holds an
     * answer under the key: false counts, null and '' do not. whereNotNull()
     * on a JSON path skips a JSON null on MySQL and SQLite alike.
     */
    public function delete(User $user, CustomField $customField): Response|bool
    {
        if (! $this->view($user, $customField)) {
            return false;
        }

        $path = 'custom_field_values->'.$customField->key;

        if (Submission::withTrashed()
            ->where('conference_id', $customField->conference_id)
            ->whereNotNull($path)
            ->where($path, '!=', '')
            ->exists()) {
            return Response::deny(__('organizer.custom_fields.in_use'));
        }

        return true;
    }
// TracksRelationManager::table(), `DeleteAction::make(),` becomes:
                // A track in use stays: TrackPolicy::delete()'s reason is the tooltip.
                DeleteAction::make()->authorizationTooltip(),
// CustomFieldsRelationManager::table(), the DeleteAction and its ->modalDescription(...) become (the description promised a delete the policy now refuses):
                // An answered field stays: CustomFieldPolicy::delete()'s reason is the tooltip,
                // so the confirmation only ever opens for a field nobody has answered.
                DeleteAction::make()->authorizationTooltip(),
// lang/en/organizer.php, after `review_settings`:
    'tracks' => [
        'in_use' => 'Abstracts are filed under this track, so it cannot be deleted. Rename it instead.',
    ],

    'custom_fields' => [
        'in_use' => 'Authors have answered this field, so it cannot be deleted.',
    ],
```

- [ ] **Step 3: Green, the gates (N=5), commit.** Expected: the filter `rc=0`, 3 passed; `test rc=0` at baseline + 30 (`ChildPolicyBulkAbilitiesTest` and `ConferenceRelationManagersTest` unchanged: their rows are unused), `stan rc=0`, `pint rc=0`. Commit the six files with the subject `feat(conferences): a track or custom field in use cannot be deleted` and the body "TrackPolicy and CustomFieldPolicy refuse delete() while a submission, trashed included, references the row, with the reason as the button's tooltip; both view() methods now refuse instead of erroring."

### Task 6: The record, the final gates and the pull request
**Files:** create `docs/decisions/0013-review-settings-lock-and-referenced-rows.md` and `docs/decisions/0014-status-link-caps-on-the-public-form.md` (Write tool); modify `docs/HANDOVER.md`, `docs/superpowers/plans/backlog.md`, `docs/runbooks/deploy-production.md` with the Edit tool.
- [ ] **Step 1: The decisions.** Create 0013 and 0014 with the texts below. Decision 0010 is not edited (`docs/decisions/README.md`): 0013 names it as the decision it replaces for conference editing.
```markdown
# Review settings lock once reviewing starts; tracks and fields in use stay

- **Context.** `review_mode` decides which abstracts a reviewer may see and `blind_review` whether reviewers see the authors, so changing either after a review exists changes what that review meant. A track abstracts were filed under could be deleted, and `submissions.track_id` is `nullOnDelete`, so they silently lost it; a custom field authors had answered could be deleted, leaving the answers in `submissions.custom_field_values` but never shown or exported. The outside review asked for both (`docs/reviews/2026-10-16-expert-brief.md`, question 6 point 2, work order B11). This decision replaces decision 0010 for conference editing only: `EditConference` and `ConferenceStatusActions::edit()` now save through `UpdateConference`. 0010 still covers the tracks, custom fields and review questions managers, which keep Filament's default saves; their new delete refusals are policy checks, as 0010's Consequence allows.
- **Decision.** Decided by the owner on 2026-10-17 (Plan 8 summary). Both settings are read-only on the conference edit form from `reviewing` on and on any conference with a `reviews` row, because `reviewing -> closed` is allowed; `App\Actions\Conferences\UpdateConference`, now the only writer of the edit page and of the Edit button's modal on the view page and the list (`ConferenceStatusActions::edit()`), refuses a change to either while locked. A track is not deletable while a submission (withdrawn or trashed included) references it, nor a custom field while a submission of its conference holds a non-null, non-empty answer under its key (`TrackPolicy::delete()`, `CustomFieldPolicy::delete()`); the greyed-out delete button's tooltip says why. Deadlines stay editable, and the model's own activity entry records old and new values with the editor as causer.
- **Consequence.** A wrong review mode must be fixed before reviewing starts; afterwards only a new conference changes it. A track in use can be renamed but not removed, and an answered field stays on the form, where it can be renamed or made optional.
- **To reverse.** Drop the `disabled()` and `dehydrated()` calls on the two fields in `ConferenceForm` and the locked checks in `UpdateConference::blockers()`, and return the membership check alone from the two policies' `delete()`; invert `tests/Unit/UpdateConferenceTest.php`, `tests/Feature/Organizer/ConferenceReviewSettingsTest.php` and `tests/Feature/Organizer/ReferencedRowsTest.php`.
```
```markdown
# The public draft form caps the status links it emails

- **Context.** A draft save emailed a branded status link to any address typed, which with no Turnstile made the form a mail relay (`docs/reviews/2026-10-16-expert-brief.md`, question 5 point 2, work order B10).
- **Decision.** Decided by the owner on 2026-10-17 (Plan 8 summary). The public form emails a new draft's link only while the address has had fewer than `CASS_STATUS_LINK_PER_ADDRESS_PER_DAY` (3) status-link emails in 24 hours, counted in `email_logs` over `submission_draft_saved` and `submission_received` whatever their status, so submit receipts and organizer resends count, and only while its client IP has sent fewer than `CASS_STATUS_LINK_PER_IP_PER_DAY` (10) draft links in the 24 hours from its first. Past either cap the draft is saved without an email and the status page says so. The organizer's **Resend status link** is never capped; the submit receipt is not capped yet (backlog, "Submissions").
- **Consequence.** An author who saves and submits several abstracts in one day may get a later draft's link only as the status page they land on, or in that abstract's submit receipt.
- **To reverse.** Make `SubmissionForm::saveDraft()` call `SendSubmissionStatusLink::handle()` again; invert `tests/Feature/Public/StatusLinkCapTest.php`.
```

- [ ] **Step 2: Runbook and backlog.** In `docs/runbooks/deploy-production.md`, "Author status links", after the paragraph ending "which rotates the token and kills the old one.", add the first paragraph below; in "Reviewer reminders", item 3, after "A conference in `closed` sends nothing.", append "Nor does one whose organization is pending or suspended: the hourly pass skips it, and **Remind reviewers** stays hidden until the platform admin approves or reinstates the organization." In `docs/superpowers/plans/backlog.md`, append the second block as the last bullet of "Submissions (deferred by Plan 3)"; then, unless the owner answered both open questions at plan approval, rename "Open questions for the owner (raised by Plan 7)" to "Open questions for the owner (raised by Plans 7 and 8)" and append the last two bullets to it. If Task 2 left the compose file alone, the runbook's "Both variables are optional; set one in the application's environment and redeploy to change it." becomes "Both variables are optional. Changing either in production also needs its line in `docker-compose.production.yml`, because a Coolify variable reaches the container only through `${...}` there.", and the first question's bullet ends "It ships at 10; raising it needs the variable and its line in `docker-compose.production.yml`."
```markdown
**Caps on the public form.** Saving a new draft emails its status link at most `CASS_STATUS_LINK_PER_ADDRESS_PER_DAY` times (default 3) to one address in 24 hours, counted in `email_logs` (every `submission_draft_saved` and `submission_received` row to that address, whatever its status, organizer resends included), and at most `CASS_STATUS_LINK_PER_IP_PER_DAY` times (default 10) for one client IP, counted in the cache under `status-link:<ip>` for the 24 hours after that IP's first link (to lift one client IP's cap early: `C=$(cass_container app)`, then `sudo docker exec "$C" su-exec app php artisan tinker --execute="Illuminate\Support\Facades\RateLimiter::clear('status-link:203.0.113.7');"`, with that IP in place of 203.0.113.7. Never `cache:clear`: with the database cache store it empties the whole cache table, including the scheduler heartbeat `cass:health` reads and every other rate limit. The per-address count lives in `email_logs` and is not reset this way; **Resend status link** answers that author). Past either cap the draft is still saved and the author lands on its status page, which says no email was sent and that the page is their link. **Resend status link** is never capped: it is the answer when an author says the link did not come. Both variables are optional; set one in the application's environment and redeploy to change it. A submit still emails its link without a cap (backlog, "Submissions").

- **The status link a submit emails is not capped.** Plan 8 caps the public draft form's link (`SendSubmissionStatusLink::handleForAuthor()`, `CASS_STATUS_LINK_*`); `SubmitAbstract` still emails `submission_received` on every submit, behind the same five-a-minute bot checks and a complete abstract. Route it through `handleForAuthor()` if `email_logs` shows one address receiving repeated `submission_received` rows. Decision: `docs/decisions/0014-status-link-caps-on-the-public-form.md`.

- **Is 10 status links per client IP per day right for a hospital network where many authors share one address?** It ships at 10; `CASS_STATUS_LINK_PER_IP_PER_DAY` raises it.
- **Should Resend status link be refused for a suspended organization?** It still sends, and the status page it links to is offline until reinstatement.
```

- [ ] **Step 3: The final gates (N=6).** Expected: `test rc=0` at baseline + 30 (1,371 passed, 1 skipped against today's baseline), `stan rc=0`, `pint rc=0`. Commit the four docs touched so far with the message `docs: decisions 0013 and 0014, the status-link caps in the runbook, the submit-link deferral`.

- [ ] **Step 4: Push and open the pull request.** Write `C:\Users\khawl\AppData\Local\Temp\pr-plan8.md` (Write tool; Git Bash reads it as `/tmp/pr-plan8.md`) with the body below, ending with the attribution line the run prescribes. If Task 2 left the compose file alone, delete the docker-compose.production.yml bullet from Sensitive files. Then:
```bash
cd $REPO && git push -u origin plan-8-phase-b-guards > /tmp/push-task-6.log 2>&1; echo "push rc=$?"
gh pr create --base main --head plan-8-phase-b-guards --title "Plan 8: phase-B guards (B9 to B11)" --body-file /tmp/pr-plan8.md > /tmp/pr-task-6.log 2>&1; echo "pr rc=$?"
```
```markdown
**Summary.** The three code items of the expert brief's phase B. B9: a pending or suspended organization can no longer invite members or reviewers, and its reviewer reminders stop (the manual button hides, the hourly pass skips it). B10: the public draft form emails at most 3 status links per address and 10 per client IP in 24 hours; past either cap the draft is saved without an email and the status page says so; the organizer's Resend status link is unchanged. B11: review mode and blind review lock from reviewing onward and while any review exists, through the new UpdateConference action behind EditConference and the Edit button's modal; a track in use or an answered custom field cannot be deleted; deadline edits stay logged with the editor as causer.

**Sensitive files.**
- app/Policies/TrackPolicy.php: delete() refuses a referenced track, with a reason; view() refuses instead of erroring on an unreachable conference.
- app/Policies/CustomFieldPolicy.php: delete() refuses an answered field, with a reason; view() null-safe the same way.
- config/cass.php: cass.status_link.per_address_per_day and per_ip_per_day.
- docker-compose.production.yml: passes the two optional caps to the app container; a Coolify variable reaches it only through `${...}` here.

**Migrations.** None.

**Environment variables.** CASS_STATUS_LINK_PER_ADDRESS_PER_DAY (default 3) and CASS_STATUS_LINK_PER_IP_PER_DAY (default 10), both optional; in .env.example and the runbook, "Author status links".

**Docs changed.** docs/HANDOVER.md, docs/superpowers/plans/backlog.md, docs/decisions/0013-review-settings-lock-and-referenced-rows.md (new), docs/decisions/0014-status-link-caps-on-the-public-form.md (new), docs/runbooks/deploy-production.md, .env.example.

**Gate logs.** /tmp/t-task-1.log to /tmp/t-task-6.log, /tmp/phpstan-task-6.log, /tmp/pint-task-6.log, all rc=0; the red runs are /tmp/t-task-1-red.log to /tmp/t-task-5-red.log.

**Tests added (baseline + 30).** tests/Unit/OrganizationMailBlockersTest.php (8), tests/Feature/Public/StatusLinkCapTest.php (3), one case in tests/Feature/Organizer/SubmissionResourceTest.php, tests/Unit/UpdateConferenceTest.php (9), tests/Feature/Organizer/ConferenceReviewSettingsTest.php (6), tests/Feature/Organizer/ReferencedRowsTest.php (3). CI's MySQL job is the first MySQL run of CustomFieldPolicy's JSON-path query.

**Anything skipped.** The status link SubmitAbstract emails on submit stays uncapped (backlog, "Submissions"). The organizer's Resend status link still sends for a suspended organization (owner question 2), and the per-IP default ships at 10 (owner question 1). The phase-D work the brief ties to opening a file is not done: D2's literals in SendSubmissionStatusLink, ConferenceForm, ConferenceStatusActions, ConferencesTable and the two relation managers; D5's split of SubmissionForm; D6's active-reviewer query scope in InviteReviewer and SendReviewerReminders.
```

- [ ] **Step 5: HANDOVER, with the pull request's number.** In `docs/HANDOVER.md`, give the phase-B table a fourth column, `Status`: `Done (#NN)` for B9, B10 and B11 (#NN the number `gh` printed), `Not started` for the rest unless the file already records progress. In the `main` row, "`39fad5c` (2026-10-17)" becomes "`6256b06` (2026-10-17)" and "#31 the npm group with Vite 8)." becomes "#31 the npm group with Vite 8) and #38, the A9 record (docs only).", followed by "Plan 8 (#NN, the phase-B guards B9 to B11) adds no migration and two optional environment variables, `CASS_STATUS_LINK_PER_ADDRESS_PER_DAY` (3) and `CASS_STATUS_LINK_PER_IP_PER_DAY` (10), whose defaults need nothing set in production." In the "Open pull requests" row, "#38 (the A9 record; docs only)." becomes "#NN (Plan 8, the phase-B guards B9 to B11; awaiting the owner's merge)."; unless Step 2 left the backlog's open questions unchanged, "the six questions Plan 7 raised" becomes "the questions Plans 7 and 8 raised"; "Plans 1–7 and the backlog" becomes "Plans 1–8 and the backlog". Commit with `docs: HANDOVER records plan 8 (#NN)` and push. Expected: `push rc=0`; CI runs on the pull request.

## Verification

- After Task 6 the gates (N=6) exit 0, with the suite at baseline + 30.
- CI is green on the pull request, its MySQL job included (fact 14).
- Every red step failed for the reason stated, before its code was written: `/tmp/t-task-1-red.log` to `/tmp/t-task-5-red.log` hold the red runs, and `/tmp/t-task-N.log` holds each task's final gate.
- The pull request lists the sensitive files, no migration, the two variables, the tests added, anything skipped, the docs and the gate logs, for the owner's review point 2.

## Deferred

- `docs/superpowers/plans/backlog.md`, "Submissions": the uncapped status link of `SubmitAbstract` (Task 6, Step 2).
- Owner questions 1 and 2 ship the behaviour stated and are recorded in the backlog (Task 6, Step 2; backlog, "Open questions for the owner") unless the owner answered them at plan approval; a yes to either is a later change.
