# CASS v2 Plan 5: Scoring, the Ranking Table, Decisions, Decision Letters and `Reviewing -> Decided`

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The reviews stop being a pile of answers and become a decision. Every submitted review is normalised to a 0–100 weighted mean, every abstract carries the mean of its reviews, the spread between them and how many there were, and all three live in denormalised columns that a ranking table can sort five hundred rows by without touching `reviews` once. The organizer opens one page per conference: a summary strip, a sortable, filterable, searchable ranking, a row action and a bulk action that apply one of four decisions, and CSV and XLSX exports of exactly the rows on screen. Decisions are prepared quietly — nothing is emailed until the organizer clicks "Send decision emails", types the confirmation word and watches the count per decision — and after that a decision can only be changed through an explicit "Change decision and resend", which appends to the history and queues a new letter. Each letter is rendered once, at send time, and stored on the decision it belongs to, so an organizer editing a template next March does not rewrite what an author was told in September. The author sees that letter on `/s/{token}`, where Plan 3 left a neutral placeholder. Finally `Reviewing -> Decided` becomes a real transition with a blocker list, the conference view and the admin list print decision counts, and a reviewer whose conference has decided is told, on their own dashboard, that their reviews are now read-only.

**Architecture:** Single Laravel application, three Filament panels, unchanged. Scoring is three pure classes under `App\Support\Scoring` with no Filament, no Eloquent writes and no side effects: `AnswerNormaliser` turns one answer into 0–100 or null, `ReviewScorer` turns a review into a weighted mean or null, `SubmissionScorer` turns a list of review scores into a mean, a sample standard deviation and a count. Exactly one action writes the denormalised columns — `ComputeSubmissionScore` — and exactly two places call it: one line inside Plan 4's `SubmitReview::handle()`, one line inside Plan 4's `ReopenReview::handle()`, plus the `cass:rescore` console command for everything else. Which abstracts a conference ranks is decided in one place, `App\Support\Scoring\RankedSubmissions`, which the ranking page, both exports, `SendDecisionEmails`, `MarkDecided::blockers()` and the summary strip all call, so "the abstracts under consideration" cannot come to mean six different things. Decisions are an append-only `submission_decisions` history plus two denormalised columns on `submissions`; the letter is three columns on the history row, written at send time. The ranking page is a `Filament\Resources\Pages\Page` with a *query-backed* table — the same class shape as Plan 3's `ConferenceEmailTemplates`, but with `Table::query()` instead of `Table::records()`, so sorting, filtering, pagination and bulk selection are all SQL.

**Tech Stack:** PHP 8.4, Laravel 13.31, Filament 5.8.1, Livewire 4.4.4, Tailwind 4.3 (Vite, public pages only), Pest 5.1.4 with the Laravel, Livewire and Browser plugins, Larastan level 6, Pint (strict types), spatie/laravel-activitylog 5.1, openspout/openspout 4.32, MySQL 8.4 in CI and production, SQLite in-memory locally.

**Spec:** `docs/superpowers/specs/2026-09-10-cass-v2-design.md` section 5.6 (scoring, ranking, decisions) **in full**; the four `decision_*` template keys of 5.9, whose defaults and per-conference editor already exist from Plan 3 and which this plan *sends*; the `Reviewing -> Decided` transition declared in `ConferenceStatus` since Plan 2 and driven by nothing until now; step 5 of 5.3 ("After decisions, it shows the decision letter") on `/s/{token}`; the `App\Support\Scoring` directory named in section 7 and the `ComputeSubmissionScore` / `ApplyDecision` / `SendDecisionEmails` action classes named in the same list; the "ranking table for 500 submissions in under 1 s" budget of section 10; the CSV **and XLSX** exports of 5.6; the platform-admin cells of section 4 as Plans 3 and 4 interpreted them (read-only, and only where a screen already exists); and sections 8 (indexes and foreign keys), 9 (policies, audit log, hashed tokens), 10 (language files, timezones) and 12 (unit tests for "scoring normalisation and weighting", panel tests with a cross-tenant negative on every resource).

**Read this before you trust a single Plan 4 symbol below.** Plan 4 — members, reviewers, the reviewer panel, reviews, assignment and reminders — was being implemented at the moment this plan was written, from `docs/superpowers/plans/2026-09-11-plan-4-reviewers.md`. Every Plan 4 name used here (`App\Models\Review`, `App\Models\ReviewAnswer`, `App\Enums\ReviewStatus`, `Review::answers()`, `ReviewAnswer::question()`, `ReviewAnswer::value_int|value_text|value_bool|choice_key`, `Submission::reviews()`, `Submission::isReviewable()`, `Conference::activeReviewers()`, `Conference::acceptsReviewWrites()`, `Conference::isOpenToReviewers()`, `Conference::reviewProgress()`, `App\Actions\Reviews\SubmitReview`, `App\Actions\Reviews\ReopenReview`, `App\Support\Reviews\ReviewerScope`, `ReviewQuestion::optionScore()`, `ReviewQuestion::optionKeys()`, `ReviewForm::lockIfUnlocked()`, `bootReviewerPanel()`, `tests/Feature/Organizer/ChildPolicyBulkAbilitiesTest.php`'s `panelAllows()`) is quoted from that plan, not read off `main`. **Task 1 Step 1 greps for all of them and stops if one is missing or has moved**; if Plan 4's own review changed a name, a signature or a file path, the code on `main` is authoritative and this plan's call sites are adjusted to it. Do not "fix" `main` to match this document.

**Environment facts for every command below**

- Repo root: `C:\Users\ahmed\Documents\CASS` (Git Bash path `/c/Users/ahmed/Documents/CASS`). All commands are Git Bash.
- Composer: run `php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar <args>`. Do **not** use the `composer.bat` wrapper: it passes through cmd.exe and silently strips `^` from version constraints. **This plan installs nothing**, so the only composer command below is the `show` in Task 1 Step 1.
- PHP 8.4.23 at `C:\Users\ahmed\AppData\Local\php84\php.exe`. `php -m` on this machine lists `bcmath calendar ctype curl date dom fileinfo filter gd hash iconv intl json libxml mbstring mysqlnd openssl pcre PDO pdo_mysql pdo_sqlite Phar random readline Reflection session SimpleXML sockets SPL sqlite3 standard tokenizer xml xmlreader xmlwriter OPcache zip zlib` — **no `sodium`, no `exif`**, and `zip` **is** present, which is what the XLSX writer needs (fact 3).
- `php -` (reading a script from stdin) does not work on this machine. Every ad-hoc PHP snippet below is written to a file first and run as `php <file>`.
- Node 24.15.0, npm 11.12.1, Docker 29 (daemon running), git, `gh` (logged in as `ahmedsk2`).
- **Baseline before this plan:** branch `plan-5-decisions`, created from `main` after Plan 4 merges. **"Baseline" throughout this plan means one thing: the number of passing tests `php artisan test` reports at the end of Plan 4, on `main`, before a single line of Plan 5 exists.** That number is **not** hardcoded anywhere below, because Plan 4 was still being implemented while this was drafted — **Task 1 Step 1 runs the suite on the freshly branched tree and records the real number**, and every "Expected: `baseline + N`" line below is measured from what Step 1 wrote down. As a sanity check only: Plan 3 ended at 450 and Plan 4's own "Expected" lines add roughly 230, so Step 1 should print something in the high 600s. If it prints 450-ish, the branch has the wrong parent and Plan 4 is not merged.
- Commit after every task with the trailer `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`. **No task may commit with a failing test.**
- **Verify test runs by exit code**, never by reading piped output: `php artisan test > /tmp/t.log 2>&1; echo "rc=$?"; tail -5 /tmp/t.log`.
- **The test *counts* in every "Expected" line below are approximate.** They were computed by counting `it()` blocks and dataset rows while writing the plan, and one extra dataset row moves them. The `rc=0` in the same line is the gate; a count that is off by a few is not a failure, a count that is off by *dozens* means a file did not run.

**Packages added by this plan**

**None.** Everything here is Laravel, Filament and a package that has been a direct dependency since Plan 3:

| Thing that might look like it needs a package | What is used instead | Why |
|---|---|---|
| XLSX export | `OpenSpout\Writer\XLSX\Writer` | `openspout/openspout` v4.32.0 is already a direct dependency (`composer.json` require block) and already writes the submission-list CSV. It writes XLSX from the same `Row` objects; `ext-zip` is present locally, in the image (`Dockerfile:25`) and in CI (`.github/workflows/ci.yml:44`, `:87`). Plan 3's backlog already recorded this as "a writer, not a package". |
| Standard deviation | 11 lines in `App\Support\Scoring\SubmissionScorer` | The formula is one line of arithmetic; `ext-stats` is not installed anywhere and a package for `sqrt(sum/(n-1))` would be a dependency to explain in every future audit. |
| A ranking/leaderboard component | `Filament\Tables` over an Eloquent query | The ranking is a table of `submissions` rows sorted by a column. Nothing about it is special except that the columns are already computed. |
| A typed-confirmation modal | `Action::schema([TextInput::make('confirm')->rule(...)])` | Filament has `requiresConfirmation()` for yes/no and a schema for anything more; a "type SEND to continue" field is one `TextInput` plus one closure rule, the idiom `ConferenceEmailTemplates` already uses for its placeholder rule. |
| A queue/batch runner for decision emails | `SendTemplatedEmail`, which already queues and already logs | Plan 3's pipeline writes the `email_logs` row before queueing and marks it failed from `TemplatedMail::failed()`. A batch abstraction would bypass both. |

**What this plan does NOT build (kept honest):**

- **Per-track quotas and per-track ranking cut-offs.** Spec 5.6 says nothing about them and spec section 14 puts the programme builder in v2. The ranking table filters by track, which is the read-only half of the same idea; deciding "the top eight of each track" is still the organizer reading the filtered list.
- **A programme builder, presenter certificates, reviewer certificates, proceedings export and per-abstract public pages** are spec section 14, restated in the backlog.
- **Reviewer-visible decisions.** A reviewer sees their own review and, after `decided`, sees it read-only. No reviewer sees another reviewer's review or any abstract's score, in any panel. Plan 4's backlog raised "should reviewers see each other's comments after decisions" as the open question the ranking table would force; this plan answers it **no**, and records the answer as an owner question rather than a closed door.
- **Reviewer comments on the ranking table.** The table shows numbers. The free-text answers of a review are readable from... nowhere yet: `ReviewPolicy::view()` allows organization members but no organizer screen prints a review's content. That is a real gap between spec section 4 and the code, it is **Plan 6**'s read-only review screen, and Task 11 writes it into the backlog with that name on it.
- **A platform-admin decision UI.** `SubmissionDecisionPolicy::before()` answers `true` for a platform admin, as every policy in this codebase does, and the admin conference list gains two read-only count columns. There is no admin screen that applies a decision, for the reason Plans 3 and 4 already recorded: the organizer panel is membership-gated, so the platform-admin cells of spec section 4 have no route in until Plan 6 builds read-only admin resources.
- **Un-sending a decision email.** There is no such thing. A wrong decision is corrected with "Change decision and resend", which appends to the history and queues a second letter; the first letter is in the author's inbox and pretending otherwise would be the lie this whole design is built to avoid.
- **Automatic decisions.** Nothing computes a decision from a score. The ranking sorts; a person decides. Spec 5.6 describes exactly that and nothing more.
- **An organizer theme.** Still the Plan 2 backlog item: Tailwind utility classes do nothing inside a Filament panel, so the ranking page's summary strip is `<x-filament::section>` plus inline styles, exactly like `ConferenceEmailTemplates` and `ConferenceShortLink`.
- **Queue, worker, Docker or compose changes.** Every email here goes through Plan 3's `SendTemplatedEmail`; the existing worker and the existing `email_logs` listener handle it. The only ops change is a runbook section and two `.env` knobs.

---

## File structure created or changed by this plan

```
app/
  Actions/
    Conferences/
      MarkDecided.php                      # blockers() + handle(), Reviewing -> Decided
    Decisions/
      ApplyDecision.php                    # one submission, appends history
      ApplyDecisions.php                   # bulk wrapper with a per-row report
      SendDecisionEmails.php               # per conference, idempotent, queued
      SendOneDecisionEmail.php             # one row; the resend path and the loop body
    Submissions/
      ComputeSubmissionScore.php           # THE writer of the denormalised columns
      ExportRankingCsv.php
      ExportRankingXlsx.php
      ExportSubmissionsCsv.php             (modified: guard() moves to SpreadsheetCell)
  Console/
    Commands/
      RescoreConferenceCommand.php         # cass:rescore {conference}
  Enums/
    Decision.php                           # accepted_oral|accepted_poster|waitlisted|rejected
    SubmissionStatus.php                   (modified: isDrivenInPlan3 -> isWithOrganizers)
  Exceptions/
    DecisionNotAcceptable.php
  Filament/
    Admin/Resources/Conferences/
      Tables/ConferencesTable.php          (modified: two read-only decision columns)
    Organizer/Resources/Conferences/
      ConferenceResource.php               (modified: the ranking page)
      Pages/ConferenceRanking.php          # the ranking table, summary strip, exports
      Pages/ConferenceEmailTemplates.php   (modified: the four decision "Sent when" sentences)
      Schemas/ConferenceInfolist.php       (modified: the Decisions section)
      Tables/ConferenceStatusActions.php   (modified: ranking(), markDecided())
      Tables/DecisionActions.php           # decide, change-and-resend, bulk, resend, send-all
    Organizer/Resources/Submissions/
      Schemas/SubmissionInfolist.php       (modified: the read-only Decision section)
  Livewire/Public/
    SubmissionStatus.php                   (modified: the letter)
  Models/
    Conference.php                         (modified: decisionCounts(), rankingSummary())
    Review.php                             (modified: score cast)
    Submission.php                         (modified: score casts, decisions(), letter)
    SubmissionDecision.php
  Policies/
    ConferencePolicy.php                   (modified: sendDecisions())
    SubmissionDecisionPolicy.php
    SubmissionPolicy.php                   (modified: decide())
  Support/
    Export/SpreadsheetCell.php             # the formula-injection guard, one copy
    Reviews/ReviewerScope.php              (modified: a decided abstract the reviewer reviewed stays readable)
    Scoring/AnswerNormaliser.php
    Scoring/RankedSubmissions.php          # THE definition of "under consideration"
    Scoring/RankingRows.php                # headers + one guarded row, shared by both writers
    Scoring/ReviewScorer.php
    Scoring/SubmissionScorer.php
bootstrap/
  app.php                                  (modified: RescoreConferenceCommand in withCommands)
config/
  cass.php                                 (modified: the decisions block)
database/
  factories/SubmissionDecisionFactory.php
  factories/SubmissionFactory.php          (modified: scored() and decided() states)
  migrations/
    2026_09_13_000100_add_score_columns_to_submissions_table.php
    2026_09_13_000200_add_score_to_reviews_table.php
    2026_09_13_000300_add_decision_columns_to_submissions_table.php
    2026_09_13_000400_create_submission_decisions_table.php
lang/
  en/decisions.php                         # the ranking page, the decision actions, the emails
  en/reviewer.php                          (modified: dashboard.decided)
  en/submission.php                        (modified: status.decision.*)
resources/
  views/
    filament/organizer/pages/conference-ranking.blade.php
    filament/reviewer/pages/dashboard.blade.php   (modified: the read-only line)
    livewire/public/submission-status.blade.php   (modified: the letter block)
app/Actions/Reviews/SubmitReview.php       (modified: ONE line + one constructor argument)
app/Actions/Reviews/ReopenReview.php       (modified: ONE line + one constructor argument)
.env.example                               (modified)
docs/runbooks/deploy-production.md         (modified: decisions, rescore, email volume)
docs/superpowers/plans/backlog.md          (modified)
tests/
  Feature/
    Organizer/ChildPolicyBulkAbilitiesTest.php  (modified: SubmissionDecisionPolicy)
    Organizer/ConferenceRankingTest.php
    Organizer/ConferenceRankingExportTest.php
    Organizer/ConferenceRankingPerformanceTest.php
    Organizer/DecisionEmailsTest.php
    Organizer/DecisionsTest.php
    Organizer/ConferenceTransitionsTest.php     (modified: the Decided case)
    Admin/ConferencesTest.php                   (modified: the decision columns)
    Public/SubmissionStatusPageTest.php         (modified: the letter)
    Reviewer/ReviewerDashboardTest.php          (modified: read-only after decided)
    LanguageCoverageTest.php                    (modified: the Plan 5 sources)
  Unit/
    AnswerNormaliserTest.php
    ApplyDecisionTest.php
    ComputeSubmissionScoreTest.php
    DecisionModelTest.php
    MarkDecidedTest.php
    RescoreCommandTest.php
    ReviewerScopeTest.php                       (modified: a decided abstract stays readable)
    ReviewScorerTest.php
    SubmissionScorerTest.php
```

Files this plan *modifies* that are easy to miss in the tree above: `app/Actions/Reviews/SubmitReview.php` and `app/Actions/Reviews/ReopenReview.php` (Task 3 — one line each, and the test that proves each line fires), `app/Actions/Submissions/ExportSubmissionsCsv.php` (Task 5 — a private method moves out and its existing test must stay green untouched), `app/Enums/SubmissionStatus.php` (Task 8 — one method renamed), `app/Support/Reviews/ReviewerScope.php` (Task 9 — one widened status clause in a Plan 4 file, plus the case appended to Plan 4's `tests/Unit/ReviewerScopeTest.php`), and `app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php`, which grows in Tasks 4 and 9 — two separate edits to one file that Plan 4 already edited four times — plus two one-off edits in files whose resources this plan does not otherwise touch: `app/Filament/Organizer/Resources/Submissions/Schemas/SubmissionInfolist.php` (Task 6) and `app/Filament/Organizer/Resources/Conferences/Pages/ConferenceEmailTemplates.php` (Task 7).

---

## Facts verified in `vendor`, in `php -m` and in the repository before writing this plan

Read these before you doubt a name or a claim below. Every one was checked on this machine on 2026-09-11, against the tree at commit `e5cdf36` (Plan 3 merged, Plan 4 in progress). Plan 3's facts 12, 17, 22, 23 and 24 and Plan 4's facts 2, 5, 12, 13, 14, 16, 17, 25, 26, 28, 34 and 40 still hold; the ones this plan leans on are restated below with their own numbers.

1. **Installed versions, from `composer.lock`:** `filament/filament` v5.8.1 (`:1279-1280`) with every `filament/*` sibling at the same version, `laravel/framework` v13.31.0 (`:2329`), `livewire/livewire` v4.4.4 (`:3479`), `nesbot/carbon` (`:3727`), `openspout/openspout` v4.32.0 (`:4208`), `spatie/laravel-activitylog` (`:5517`), `larastan/larastan` v3.11.0 (`:10619`), `laravel/pint` (`:10788`), `pestphp/pest` v5.1.4 (`:11096`). `phpstan.neon` (there is no `.dist`) is `level: 6` over `app`, `config`, `database`, `routes`. `pint.json` is the `laravel` preset plus `declare_strict_types` and alphabetical imports.

2. **openspout turns a leading `=` into a real formula cell — the injection guard is not cosmetic in XLSX.** `OpenSpout\Common\Entity\Cell::fromValue()` (`vendor/openspout/openspout/src/Common/Entity/Cell.php:41-60`) returns a `BooleanCell` for a bool (`:43-45`), an `EmptyCell` for null or `''` (`:46-48`), a `NumericCell` for int or float (`:49-51`), and — the one that matters — a **`FormulaCell`** when `isset($value[0]) && '=' === $value[0]` (`:58-60`). `Row::fromValues()` (`src/Common/Entity/Row.php:41-47`) maps every value through it. So a title an author typed as `=HYPERLINK(...)` would be written into `xl/worksheets/sheet1.xml` as an `<f>` element: a live formula inside the workbook, not merely a string that Excel decides to evaluate. The guard must therefore run **before** `Row::fromValues`, which is exactly where `ExportSubmissionsCsv::guard()` already runs (`app/Actions/Submissions/ExportSubmissionsCsv.php:81`, method at `:141-144`). Task 5 moves that one method into `App\Support\Export\SpreadsheetCell` so both writers share one copy.

3. **XLSX writes through a temp folder and copies the finished zip to the stream at `close()`.** `OpenSpout\Writer\XLSX\Writer` (`vendor/openspout/openspout/src/Writer/XLSX/Writer.php:21`) builds the workbook under `Options::getTempFolder()`; `AbstractOptions` (`src/Writer/Common/AbstractOptions.php:10-12`) uses `OpenSpout\Common\TempFolderOptionTrait`, whose `getTempFolder()` falls back to `sys_get_temp_dir()` (`src/Common/TempFolderOptionTrait.php:25-32`), and `ZipHelper::closeArchiveAndCopyToStream()` (`src/Writer/Common/Helper/ZipHelper.php:128`) streams the zip out. `AbstractWriter::openToFile($path)` (`src/Writer/AbstractWriter.php:28-48`) simply `fopen`s the path, so `php://output` inside a `StreamedResponse` works. **Consequence, stated so nobody promises otherwise:** the CSV export really is row-streamed to the client and the XLSX one is not — its memory stays flat but the whole file is assembled on disk first and sent in one go. openspout requires `ext-zip` (`vendor/openspout/openspout/composer.json`, `require` block) and it is present everywhere: `php -m` locally, `Dockerfile:25` (`docker-php-ext-install … zip …`), `.github/workflows/ci.yml:44` and `:87`.

4. **XLSX detail used below:** `XLSX\Options::$SHOULD_USE_INLINE_STRINGS = true` by default (`vendor/openspout/openspout/src/Writer/XLSX/Options.php:20`), so strings land in `xl/worksheets/sheet1.xml` as `<is><t>…</t></is>` — which is what Task 5's test reads back out of the zip with `ZipArchive`. `Style::setFontBold()` is `src/Common/Entity/Style/Style.php:157` and `Row::fromValuesWithStyles()` is `src/Common/Entity/Row.php:53`; the header row uses both. CSV's `Options::$SHOULD_ADD_BOM` is already used and commented in `ExportSubmissionsCsv` (`:40-46`).

5. **A Filament 5 resource *page* can host a real Eloquent table, and that is what makes the ranking fast.** `Filament\Tables\Concerns\HasRecords::getTableRecords()` (`vendor/filament/tables/src/Concerns/HasRecords.php:95-96`) takes the array-record branch **only when `! $this->getTable()->hasQuery()`**; `Table::query(Builder|Closure|null)` is `vendor/filament/tables/src/Table/Concerns/HasQuery.php:27`. So `ConferenceRanking` — a `Filament\Resources\Pages\Page` with `InteractsWithRecord` and `InteractsWithTable`, the same class shape as `ConferenceEmailTemplates` (`app/Filament/Organizer/Resources/Conferences/Pages/ConferenceEmailTemplates.php:44-51`) — gets SQL sorting, SQL filtering, SQL pagination and bulk selection, none of which the array-backed templates page has. Plan 3 fact 10's array-record rules (`ArrayRecord::getKeyName()`, `__key`, `resolveSelectedRecordsUsing`) therefore do **not** apply here at all.

6. **Table builder names, re-verified in 5.8.1:** `columns(array)`, `filters(array, ...)` (`Table/Concerns/HasFilters.php:88`), `recordActions(array|ActionGroup, ...)` (`HasRecordActions.php:32`), `headerActions(array|ActionGroup, ...)` (`HasHeaderActions.php:32`), `toolbarActions(array|ActionGroup)` (`HasToolbarActions.php:20`), `defaultSort(string|Closure|null, string|Closure|null $direction = 'asc')` (`CanSortRecords.php:23`), `recordUrl(string|Closure|null, bool|Closure|null)` (`HasRecordUrl.php:30`), `heading()` (`HasHeader.php:31`) and `description()` (`:17`), `paginated(bool|array|Closure)` (`CanPaginateRecords.php:62`), `deferLoading()` (`CanDeferLoading.php:11`), `persistFiltersInSession()` (`HasFilters.php:164`), `selectable()` (`HasBulkActions.php:109`), `checkIfRecordIsSelectableUsing(?Closure)` (`HasBulkActions.php:66`), `query(Builder|Closure|null)` (`HasQuery.php:27`).

7. **Column helpers:** `TextColumn::badge()` (`vendor/filament/tables/src/Columns/TextColumn.php:60`), `numeric(int|Closure|null $decimalPlaces = null, ...)` (`Columns/Concerns/CanFormatState.php:256`), `sortable(bool|array|Closure $condition = true, ?Closure $query = null)` (`Concerns/CanBeSortable.php:22`), `searchable(...)` (`Concerns/CanBeSearchable.php:31`), `summarize(array|Summarizer)` (`Concerns/CanBeSummarized.php:20`), `placeholder(string|Htmlable|Closure|null)` (`vendor/filament/support/src/Concerns/HasPlaceholder.php:12`), and `counts(string|array|Closure|null)` (`vendor/filament/support/src/Concerns/CanAggregateRelatedModels.php:62`), which reaches the query as `$query->withCount(Arr::wrap($this->getRelationshipsToCount()))` (`tables/src/Columns/Concerns/InteractsWithTableQuery.php:24-25`). `Arr::wrap` leaves an associative array alone, so Laravel's `['relation as alias' => Closure]` form works — which is how Task 9's admin columns count decided and notified rows in the same query as the list.

8. **A `Filter` carries its own schema, and `filterTable()` knows exactly three shapes.** `Filament\Tables\Filters\Concerns\HasSchema::schema(array|Closure|null)` is `vendor/filament/tables/src/Filters/Concerns/HasSchema.php:24`. The query hook is `InteractsWithTableQuery::query(?Closure)` (`Filters/Concerns/InteractsWithTableQuery.php:68`), applied by `apply()` (`:19-40`), which returns the query untouched when no modification callback is set **or** when `($data['isActive'] ?? true)` is false. `filterTable(string $name, $data = null)` (`tables/src/Testing/TestsFilters.php:24-56`) sends `['value' => …]` for a single `SelectFilter`, `['values' => […]]` for a multiple one, and `['isActive' => …]` for anything else *unless `$data` is already an array*, in which case it is passed through — so the "fewer than N reviews" filter is driven from a test with `filterTable('under_reviewed', ['count' => 2])`.

9. **Bulk actions:** `Filament\Actions\BulkAction` (`vendor/filament/actions/src/BulkAction.php:5`) calls `bulk()` and `accessSelectedRecords()` in `setUp()` (`:7-13`), and `InteractsWithSelectedRecords::getSelectedRecords()` (`vendor/filament/actions/src/Concerns/InteractsWithSelectedRecords.php:52-65`) throws `LogicException` without the latter. `authorizeIndividualRecords(...)` is `Concerns/CanBeAuthorized.php:252` and `getIndividuallyAuthorizedSelectedRecords()` is `InteractsWithSelectedRecords.php:77`. **This plan deliberately does not use them:** the bulk decision has to tell the organizer *which* rows it skipped and *why* — "withdrawn", "already emailed", "not yours" are three different answers and only one of them is an authorization failure — and Filament's helper collapses every refusal into a count. `ApplyDecisions` returns a per-row report instead and the action renders it.

10. **Action and modal API:** `Action::schema(array|Closure|null)` (`vendor/filament/actions/src/Concerns/HasSchema.php:26`; `form()` survives at `:128` marked `@deprecated` at `:124`), `requiresConfirmation(bool|Closure $condition = true)` (`Concerns/CanRequireConfirmation.php:11`), `modalIcon()` (`Concerns/CanOpenModal.php:166`), `modalSubmitActionLabel()` (`:275`), `modalContent()` (`:299`), `modalHeading()` (`:321`), `modalDescription()` (`:328`), `modalWidth()` (`:345`). A field rule is `Field::rule(mixed $rule, bool|Closure $condition = true)` (`vendor/filament/forms/src/Components/Concerns/CanBeValidated.php:477`), and a **custom** rule must be wrapped in a closure that *returns* the rule — Filament evaluates a `Closure` rule as a callback, so an unwrapped one is called by the closure evaluator and throws `BindingResolutionException` on its `string $attribute` parameter. `ConferenceEmailTemplates::editAction()` already uses the correct idiom (`app/Filament/Organizer/Resources/Conferences/Pages/ConferenceEmailTemplates.php:181`, `:194`, rule at `:271-286`), and the typed-confirmation field in Task 7 copies it.

11. **Filament test helpers used below, all present in 5.8.1:** `callTableAction(string|array $actions, $record = null, array $data = [], array $arguments = [])` (`vendor/filament/tables/src/Testing/TestsActions.php:62-73`), `assertTableActionExists()` (`:84`), `assertTableActionVisible()` (`:152`), `assertTableActionHidden()` (`:165`), `assertTableHeaderActionsExistInOrder()` (`:124`), `callTableBulkAction(string|array $actions, array|Collection $records, array $data = [], array $arguments = [])` (`TestsBulkActions.php:73-85`), `selectTableRecords()` (`:21`), `assertCanSeeTableRecords(array|Collection $records, bool $inOrder = false)` (`TestsRecords.php:20`), `assertCanNotSeeTableRecords()` (`:44`), `assertCountTableRecords(int)` (`:64`), `assertCanRenderTableColumn()` (`TestsColumns.php:23`), `sortTable()` (`:619`), `searchTable()` (`:628`), `filterTable()` (`TestsFilters.php:24`). The `*Table*` family is marked `@deprecated` in `vendor/filament/tables/.stubs.php` in favour of the unified `callAction()`/`assertAction*()` names; **this plan keeps using it**, because the whole suite does and a half-migrated suite is worse than a consistently deprecated one (Plan 4 fact 34, and the sweep is already in the backlog).

12. **A streamed download is testable two ways, and this repo already does both.** Through the panel: `->callTableAction('export')->assertFileDownloaded()` then `base64_decode((string) data_get($component->effects, 'download.content'), true)` — Livewire runs the stream callback and base64-encodes what it wrote (`tests/Feature/Organizer/SubmissionResourceTest.php:197-208`, with the reasoning at `:189-196`). Directly: `ob_start(); $response->sendContent(); $csv = (string) ob_get_clean();` (`:345-352`). Task 5 uses the first for CSV and both for XLSX, because the XLSX assertions have to open the bytes with `ZipArchive` and that wants a real file on disk.

13. **The four decision templates, their placeholders and their platform defaults already exist and this plan adds none.** `EmailTemplateKey::DecisionAcceptedOral|DecisionAcceptedPoster|DecisionWaitlisted|DecisionRejected` (`app/Enums/EmailTemplateKey.php:24-27`), each declaring exactly `['author_name','title','reference','conference','organization','decision','status_link']` (`:71-74`), each with a subject and a Markdown body in `lang/en/mail.php` (`:126`, `:146`, `:166`, `:185`). `subjectPlaceholders()` (`:91-94`) removes `status_link` and `review_link`, so a decision *subject* cannot carry the author's bearer credential even if an organizer types it, and `SendTemplatedEmail::handle()` redacts `/s/{64}` from every subject as a second line of defence (`app/Actions/Mail/SendTemplatedEmail.php:52`). The per-conference editor already lists all four; Task 10 only changes their "Sent when" sentence in `ConferenceEmailTemplates::sendsWhen()` (`:332-335`), which currently reads "Not sent yet — decisions arrive in a later release."

14. **A select choice's score is already declared to be on the 0–100 scale, so there is nothing to convert.** The choice repeater in `ReviewQuestionsRelationManager` renders `TextInput::make('score')->numeric()->minValue(0)->maxValue(100)` with the helper text *"Optional, 0-100. A choice without a score does not count towards the review score."* (`app/Filament/Organizer/Resources/Conferences/RelationManagers/ReviewQuestionsRelationManager.php:103-104`). `AnswerNormaliser` therefore passes a select score through and only clamps it. Likert bounds are `scale_min` 0–10 and `scale_max` 1–10 with `->gt('scale_min')` (`:90-96`), both `unsignedTinyInteger` nullable (`database/migrations/2026_09_11_000500_create_review_questions_table.php:19-20`); `weight` is `decimal(5,2)` default 1 (`:22`), min 0 max 999.99 step 0.25 in the form (`:82-84`), cast `decimal:2` on the model (`app/Models/ReviewQuestion.php:40`) — which means **`$question->weight` is the string `"1.00"`, not a float**, on both drivers, and every reader in this plan casts it.

15. **`ReviewQuestion::isScored()` already answers "does this question contribute a score at all"**, including the select-with-no-scored-choice case, by reading `options[].score` (`app/Models/ReviewQuestion.php`, the method after `booted()`), and `ReviewQuestionType::isScored()` is "not Text" (`app/Enums/ReviewQuestionType.php:28-31`). Plan 5 reads both rather than re-deriving either. `ReviewQuestion::optionScore(string $key): int|float|null`, `optionKeys()` and `optionLabels()` are **Plan 4 Task 1** additions to the same file — `optionScore()` returns `is_numeric($score) ? (int) $score : null`, so a fractional option score is floored to an int before this plan ever sees it. Verify all three on `main` before use.

16. **`Model::preventSilentlyDiscardingAttributes()` is on outside production** (`app/Providers/AppServiceProvider.php:35`), so every column this plan adds is written with `forceFill()` inside an action and **none of them is fillable**. `Submission::$fillable` (`app/Models/Submission.php:38-41`) is unchanged by this plan; `SubmissionDecision` is `protected $guarded = ['*']`, the shape Plan 4's `Review` uses.

17. **A cross-tenant page assertion must go through a real request.** `Filament\Resources\Pages\Concerns\InteractsWithRecord::resolveRecord()` throws `ModelNotFoundException` when the scoped query excludes the record (`vendor/filament/filament/src/Resources/Pages/Concerns/InteractsWithRecord.php:39-43`), and Livewire's test harness rethrows everything except `HttpException` and `AuthorizationException` (`vendor/livewire/livewire/src/Features/SupportTesting/RequestBroker.php:29`) — so `livewire(ConferenceRanking::class, ['record' => $theirs])->assertNotFound()` would **error**, not assert. Every cross-tenant case below uses `get(ConferenceResource::getUrl('ranking', ['record' => $theirs]))->assertNotFound()`. The repo already records this trap in `tests/Feature/Organizer/ConferenceEmailTemplatesTest.php:188-193` and Plan 4 repeats it in Tasks 4, 6 and 8.

18. **A missing policy method means ALLOW, not 403** (Plan 3 fact 17 / Plan 4 fact 25, still load-bearing). `Filament\get_authorization_response()` (`vendor/filament/filament/src/helpers.php:59-93`) only calls the Gate when `method_exists($policy, $actionValue)`; otherwise it runs the `before()` callbacks and returns `Response::allow()` (`:81-93`). `SubmissionDecisionPolicy` therefore spells out every ability Filament may call — `viewAny`, `view`, `create`, `update`, `delete`, `deleteAny`, `restore`, `restoreAny`, `forceDelete`, `forceDeleteAny` — and `tests/Feature/Organizer/ChildPolicyBulkAbilitiesTest.php` grows a case that goes through the same helper via its own `panelAllows()` (`:36-40`).

19. **`SubmissionPolicy::export()` already exists** and is `viewAny()` (`app/Policies/SubmissionPolicy.php:119-123`); `view()` is "any role in the conference's organization, guarded for a soft-deleted conference or organization" (`:50-55`). Plan 5 reuses `export` for both ranking exports and adds only `SubmissionPolicy::decide()` and `ConferencePolicy::sendDecisions()` — the latter on `ConferencePolicy` because Laravel resolves a policy from the **first** argument's class (`vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:781-785`), every call site passes the `Conference`, and a method the resolved policy does not have makes the ability answer `false` for everybody, platform admin included.

20. **Null ordering agrees on both drivers for the one sort that matters.** `ORDER BY score DESC` puts NULLs last in MySQL 8 (NULL sorts lowest, so descending puts it at the end) and in SQLite (NULL is smaller than every value). An unscored abstract therefore sinks to the bottom of the default ranking on both. This is the one fact below that was reasoned rather than executed, so **Task 4's in-order assertion is repeated in Task 11's MySQL run** rather than trusted from the SQLite run alone.

21. **`ConferenceStatus` already declares the transition.** `Reviewing->allowedTransitions()` is `[Decided, Closed, Archived]` and `Decided->allowedTransitions()` is `[Archived]` (`app/Enums/ConferenceStatus.php:56-59`), so `Reviewing -> Decided` and the existing `ArchiveConference` (`app/Actions/Conferences/ArchiveConference.php:19-30`) need no enum change. `PublishConference` is the shape `MarkDecided` copies exactly: `blockers(): list<string>` of sentences, then a `handle()` that re-checks and throws `ConferenceNotPublishable` (`app/Actions/Conferences/PublishConference.php:24-85`; `app/Exceptions/ConferenceNotPublishable.php`). `ConferenceStatusActions::publish()` shows how a panel action renders those sentences instead of throwing (`app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php:46-76`).

22. **`Conference::submissionCounts()` is one grouped query** returning `array{total,draft,submitted,withdrawn}` (`app/Models/Conference.php:235-250`), and `ConferenceInfolist` memoises it per record in a `WeakMap` (`:41-52`) because a section asks for it once per entry. Task 4's `rankingSummary()` and Task 9's `decisionCounts()` follow both patterns, and the infolist's new section reuses the same `WeakMap` idiom.

23. **The status page's neutral block is one `@elseif`.** `resources/views/livewire/public/submission-status.blade.php:44-51` renders `submission.status.decision_pending` when `! $submission->status->isDrivenInPlan3()`, with a comment naming Plan 5 as its replacement. `grep -rn isDrivenInPlan3 app/ resources/ tests/` returns exactly two lines: the enum method (`app/Enums/SubmissionStatus.php:64-67`) and that view. Task 8 renames the method and replaces the block.

24. **The letter is safe to print as HTML because of a chain that already exists.** `RenderEmailTemplate::renderBody()` substitutes each value with `VALUE_ESCAPES` (`[` → `\[`, `<` → `&lt;`, `>` → `&gt;`, `app/Actions/Mail/RenderEmailTemplate.php:38`) and then escapes **every** `<` in the finished body (`:96-99`), which is what keeps raw HTML an organizer typed inert. `ConferenceEmailTemplates::preview()` then hands that output to `Illuminate\Mail\Markdown::parse(...)->toHtml()` and injects it as an `HtmlString` (`:301-310`). Task 7 stores the output of `renderBody()` verbatim; Task 8 parses it the same way on the public page. No tag can survive that chain, which is the whole reason the letter may be rendered rather than escaped.

25. **`QUEUE_CONNECTION=sync` and `MAIL_MAILER=array` in `phpunit.xml`** (`:36-37`), and every mail test in this repo calls `Mail::fake()` and asserts `Mail::assertQueued(TemplatedMail::class, …)`. `SendTemplatedEmail::handle()` writes the `email_logs` row **before** queueing and passes the log's ULID into the mailable (`app/Actions/Mail/SendTemplatedEmail.php:54-76`), so a faked mailer still produces the row Task 7's tests read.

26. **The author's status token is hashed and the plaintext exists only inside an emailed link** (spec section 9): `submissions.access_token_hash` is `char(64)` unique (`database/migrations/2026_09_11_001100_create_submissions_table.php:50`), `Submission::findByPlainToken()` is one indexed read (`app/Models/Submission.php:143-150`), and `IssueSubmissionToken::handle()` mints a new plaintext and **replaces** the hash, killing every link already in circulation (`app/Actions/Submissions/IssueSubmissionToken.php:10-30`, with the reasoning in its docblock). `SendSubmissionStatusLink` follows the same rule (`app/Actions/Submissions/SendSubmissionStatusLink.php:54-56`). **Consequence this plan has to own:** there is no way to put a working `{{status_link}}` into a decision email except by minting a new token, so sending decision emails rotates every notified author's status link. Task 7 does it once per row, the runbook says so, and Task 11 records it as an owner question with the two alternatives.

27. **`app/Console/Commands` is not auto-discovered.** `bootstrap/app.php:11-16` has `->withRouting(commands: __DIR__.'/../routes/console.php')` and no `$commandPaths`, so a class in that folder is registered only if it is named in `->withCommands([...])` (Plan 4 fact 2). Plan 4 Task 10 adds that call with `SendReviewerRemindersCommand::class`; Task 3 below **appends** `RescoreConferenceCommand::class` to the same array. If Plan 4's implementation put the call somewhere else, follow the code.

28. **The scheduler and the worker are already running in production** (`docker/supervisord.conf`, Plan 4 fact 23). This plan schedules nothing and adds no worker. `routes/console.php` currently holds `queue:prune-failed --hours=720` and `model:prune`, both `->daily()` (`routes/console.php:13-18`); `cass:rescore` is deliberately **not** scheduled — it is an operator command, and a nightly rescore would silently rewrite a score an organizer is looking at.

29. **`bootOrganizerPanel()` and `withoutTenant()`** are `tests/Pest.php:30-35` and `:48-58`; `tests/Unit` does **not** get `RefreshDatabase` by default (`tests/Pest.php:14`), so every unit test below that touches the database says `uses(RefreshDatabase::class);` itself — the shape `tests/Unit/PublishConferenceTest.php` and Plan 4's `tests/Unit/ReviewModelsTest.php` already use. `bootReviewerPanel()` is Plan 4 Task 5's addition to the same file.

30. **The formula guard's existing test must stay green.** `it('exports the visible rows as csv and neutralises a spreadsheet formula')` (`tests/Feature/Organizer/SubmissionResourceTest.php:183-209`) asserts `toContain("'=HYPERLINK")` and the UTF-8 BOM. Task 5 moves `ExportSubmissionsCsv::guard()` into `SpreadsheetCell::text()` and changes **one call site**; that test is not edited, and its staying green unedited is the proof the move was behaviour-preserving.

---
### Task 1: Schema, the `Decision` enum, models, factories and policies

Four migrations, one enum, one new model, two modified models, one factory, one new policy and two new abilities on an existing one. No user interface, no arithmetic and no email — those are Tasks 2 onwards. The whole of Plan 5's data model lands here so that every later task adds behaviour rather than a migration.

**Four decisions this task makes, with the reasoning, because every later task depends on them.**

**1. The scores are denormalised columns on `submissions`, not a view and not a computed column.** Spec section 10 budgets "ranking table for 500 submissions in under 1 s". A ranking that computes `avg(reviews.score)` per row is a correlated subquery per row; a ranking that computes it once with a `GROUP BY` join is one query but cannot be `ORDER BY`-ed and paginated without a derived table, and cannot be filtered by "fewer than two reviews" without a `HAVING` that SQLite refuses on a query with no `GROUP BY` (the trap Plan 4 Task 8 hit with `under_target`). Four plain columns — `score`, `score_spread`, `review_count`, `scored_at` — make the ranking `select … from submissions where conference_id = ? order by score desc limit 50`, which is an index scan. The cost is that something has to keep them true, and that something is exactly one class (`ComputeSubmissionScore`, Task 3) with exactly three callers.

**2. `submission_decisions` is the history of decision *events*, and the letter lives on the row whose decision it announced.** The brief offered a separate `decision_letters` table. One table is better here for the reason spec section 3 gives about foreign keys: a letter has exactly one parent, exactly the same lifetime, and would need the same `RESTRICT`, the same purge entry in Plan 6 and the same tenant reasoning as the row it belongs to. Putting `letter_subject`, `letter_markdown` and `notified_at` on `submission_decisions` makes "the letter this author was sent about this decision" a column read rather than a join, and makes "a decision that was never emailed" a null rather than a missing row. `email_logs` already records *every send* — recipient, subject, template key, status, error (`app/Actions/Mail/SendTemplatedEmail.php:54-68`) — so the send history is not lost by keeping one letter per decision.

**3. The letter is stored at send time and a *resend* overwrites it; a *changed decision* appends a new row.** Spec 5.6 wants decisions prepared quietly and emailed on a click, and an organizer who edits the `decision_accepted_oral` template next March must not rewrite what an author was told in September — so the rendered Markdown is stored, not re-rendered on every page view. A **resend** of the same decision does overwrite `letter_subject`, `letter_markdown` and `notified_at`, and that is the honest behaviour rather than a bug: the author is now holding a newly sent email, and `/s/{token}` has to show what is in their inbox, not what was in it six months ago. A **changed** decision appends a new `submission_decisions` row with null letter columns and sets `submissions.decision_notified_at` back to null, so the new letter is a new row and the superseded row keeps its old letter for the audit. "Keep every individual send as its own row" is recorded in the backlog as a deliberate deferral, with `email_logs` named as the place the send history already lives.

**4. `submissions.decision` is a string column, not an enum column, and `submissions.status` is written alongside it.** The pattern is Plan 2's: statuses are `string(16)` in the database and a PHP backed enum on the model, so a new case is a deploy and not a migration. `ApplyDecision` writes `decision` **and** `status` in one `forceFill`, because they are two views of one fact and a row whose `decision` is `accepted_oral` while its `status` is still `under_review` is a row every screen in the application disagrees about. `Decision::submissionStatus()` is the single mapping.

**Foreign-key delete behaviour, decided per column:**

| Column | On delete | Why |
|---|---|---|
| `submission_decisions.submission_id` | **restrict** | A decision is evidence of what an author was told. It matches `reviews.submission_id` (Plan 4) and `submissions.conference_id` (Plan 3): Plan 6's hard purge deletes it on purpose, in application code, rather than having a cascade do it quietly (spec section 3). |
| `submission_decisions.decided_by` | **null on delete** | The decision survives the person. There is no user-deletion path in the app today; when Plan 6 adds one, a decision must not vanish with the account that made it, and a null `decided_by` renders as "a former member" rather than as nothing. |

**Files:**
- Create: `database/migrations/2026_09_13_000100_add_score_columns_to_submissions_table.php`, `..._000200_add_score_to_reviews_table.php`, `..._000300_add_decision_columns_to_submissions_table.php`, `..._000400_create_submission_decisions_table.php`
- Create: `app/Enums/Decision.php`
- Create: `app/Models/SubmissionDecision.php`
- Create: `database/factories/SubmissionDecisionFactory.php`
- Create: `app/Policies/SubmissionDecisionPolicy.php`
- Create: `app/Exceptions/DecisionNotAcceptable.php`
- Create: `lang/en/decisions.php` (one key, the one `SubmissionDecision::actorName()` resolves; Tasks 4-9 fill the rest of the file)
- Modify: `app/Models/Submission.php`, `app/Models/Review.php`, `app/Policies/SubmissionPolicy.php`, `app/Policies/ConferencePolicy.php`, `database/factories/SubmissionFactory.php`, `config/cass.php`
- Test: `tests/Unit/DecisionModelTest.php`, `tests/Feature/Organizer/ChildPolicyBulkAbilitiesTest.php` (modified)

- [ ] **Step 1: Record the baseline, and verify every Plan 4 symbol this plan calls**

```bash
cd /c/Users/ahmed/Documents/CASS && git checkout main && git pull --ff-only && \
git checkout -b plan-5-decisions && \
php artisan test > /tmp/baseline.log 2>&1; echo "baseline rc=$?"; tail -3 /tmp/baseline.log && \
./vendor/bin/pint --test > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?" && \
php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar show openspout/openspout filament/filament | grep -E "^(name|versions)"
```

Expected: `baseline rc=0`, `pint rc=0`, `stan rc=0`, openspout `v4.32.0`, filament `v5.8.1`. **Write the passing-test count from `tail -3 /tmp/baseline.log` into your notes — every "Expected" line in this plan is `baseline + N`.** If any of the three is non-zero, stop: Plan 4 is not finished and this branch has the wrong parent.

Now the Plan 4 symbol check. This plan was written against Plan 4's *plan*, and Plan 4's implementation may have deviated in small reviewed ways. Write `/tmp/plan4symbols.sh`:

```bash
#!/usr/bin/env bash
# Every Plan 4 name Plan 5 calls. A missing line is not a blocker - it means
# read the code on main and adjust this plan's call site to it.
cd /c/Users/ahmed/Documents/CASS
missing=0
check () { # $1 = pattern, $2 = path
  if grep -rqn -- "$1" "$2" 2>/dev/null; then
    printf 'ok    %s\n' "$1"
  else
    printf 'MISSING %-46s (looked in %s)\n' "$1" "$2"; missing=$((missing+1))
  fi
}
check 'class Review extends Model'              app/Models/Review.php
check 'public function answers()'               app/Models/Review.php
check 'public function isSubmitted()'           app/Models/Review.php
check 'class ReviewAnswer extends Model'        app/Models/ReviewAnswer.php
check 'public function question()'              app/Models/ReviewAnswer.php
check 'enum ReviewStatus'                       app/Enums/ReviewStatus.php
check 'public function reviews()'               app/Models/Submission.php
check 'public function isReviewable()'          app/Models/Submission.php
check 'public function acceptsReviewWrites()'   app/Models/Conference.php
check 'public function isOpenToReviewers()'     app/Models/Conference.php
check 'public function activeReviewers()'       app/Models/Conference.php
check 'public function reviewProgress()'        app/Models/Conference.php
check 'class SubmitReview'                      app/Actions/Reviews/SubmitReview.php
check 'class ReopenReview'                      app/Actions/Reviews/ReopenReview.php
check 'final class ReviewerScope'               app/Support/Reviews/ReviewerScope.php
check 'public function optionScore('            app/Models/ReviewQuestion.php
check 'public function optionKeys('             app/Models/ReviewQuestion.php
check 'public function isScored('               app/Models/ReviewQuestion.php
check 'class StartReviewing'                    app/Actions/Conferences/StartReviewing.php
check 'function bootReviewerPanel'              tests/Pest.php
check 'function panelAllows'                    tests/Feature/Organizer/ChildPolicyBulkAbilitiesTest.php
check 'withCommands('                           bootstrap/app.php
printf '\n%d missing\n' "$missing"
```

```bash
cd /c/Users/ahmed/Documents/CASS && bash /tmp/plan4symbols.sh
```

Expected: `0 missing`. **If anything is missing, do not edit `main` — read the real code and adjust this plan's call sites.** The three places that matter most are `SubmitReview::handle()` and `ReopenReview::handle()` (Task 3 inserts one line into each) and `ReviewAnswer`'s four value columns (Task 2 reads all four).

Finally, record the exact shape of the two hook sites, so Task 3 does not guess:

```bash
cd /c/Users/ahmed/Documents/CASS && \
grep -n "public function __construct\|public function handle\|return \$review" app/Actions/Reviews/SubmitReview.php app/Actions/Reviews/ReopenReview.php
```

- [ ] **Step 2: Write the failing tests**

`tests/Unit/DecisionModelTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\Decision;
use App\Enums\EmailTemplateKey;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\SubmissionDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('maps every decision to a status and to a template key', function () {
    // The two mappings are the reason this enum exists. A case added later
    // without both is a row whose status disagrees with its decision, and an
    // email nothing can render.
    expect(Decision::AcceptedOral->submissionStatus())->toBe(SubmissionStatus::Accepted)
        ->and(Decision::AcceptedPoster->submissionStatus())->toBe(SubmissionStatus::Accepted)
        ->and(Decision::Waitlisted->submissionStatus())->toBe(SubmissionStatus::Waitlisted)
        ->and(Decision::Rejected->submissionStatus())->toBe(SubmissionStatus::Rejected)
        ->and(Decision::AcceptedOral->templateKey())->toBe(EmailTemplateKey::DecisionAcceptedOral)
        ->and(Decision::AcceptedPoster->templateKey())->toBe(EmailTemplateKey::DecisionAcceptedPoster)
        ->and(Decision::Waitlisted->templateKey())->toBe(EmailTemplateKey::DecisionWaitlisted)
        ->and(Decision::Rejected->templateKey())->toBe(EmailTemplateKey::DecisionRejected);

    foreach (Decision::cases() as $case) {
        expect($case->getLabel())->toBeString()->not->toBe('')
            ->and($case->getColor())->toBeString()->not->toBe('')
            // Every case must be covered by the two maps above, whatever is
            // added later: a match() with no default throws UnhandledMatchError
            // here rather than in an organizer's face.
            ->and($case->submissionStatus())->toBeInstanceOf(SubmissionStatus::class)
            ->and($case->templateKey())->toBeInstanceOf(EmailTemplateKey::class);
    }

    expect(Decision::AcceptedOral->isAccepted())->toBeTrue()
        ->and(Decision::AcceptedPoster->isAccepted())->toBeTrue()
        ->and(Decision::Waitlisted->isAccepted())->toBeFalse()
        ->and(Decision::Rejected->isAccepted())->toBeFalse();
});

it('keeps the whole decision history and names the current one', function () {
    $submission = Submission::factory()->submitted()->create();
    $actor = User::factory()->create();

    $first = SubmissionDecision::factory()->for($submission)->create([
        'decision' => Decision::Waitlisted,
        'decided_by' => $actor->id,
        'decided_at' => now()->subDay(),
    ]);
    $second = SubmissionDecision::factory()->for($submission)->create([
        'decision' => Decision::AcceptedOral,
        'decided_by' => $actor->id,
        'decided_at' => now(),
    ]);

    expect($submission->decisions()->count())->toBe(2)
        // Newest first: the history is read top-down by a human.
        ->and($submission->decisions()->first()?->is($second))->toBeTrue()
        ->and($submission->currentDecision()?->is($second))->toBeTrue()
        ->and($first->fresh()?->decision)->toBe(Decision::Waitlisted);
});

it('refuses mass assignment on the decision history', function () {
    // Every column is written by ApplyDecision or SendDecisionEmails with
    // forceFill(). A fillable `decision` is a form field that decides.
    expect(fn () => new SubmissionDecision(['decision' => Decision::AcceptedOral->value]))
        ->toThrow(MassAssignmentException::class);
});

it('restricts deleting a submission that has a decision', function () {
    $submission = Submission::factory()->submitted()->create();
    SubmissionDecision::factory()->for($submission)->create();

    // Soft delete is fine - that is what the organizer panel does.
    $submission->delete();
    expect(Submission::withTrashed()->whereKey($submission->getKey())->exists())->toBeTrue();

    // A hard delete is not: the decision is evidence, and Plan 6's purge has to
    // remove it deliberately in application code. SQLite enforces this only
    // with foreign keys on, which tests/TestCase.php turns on for the suite.
    expect(fn () => $submission->forceDelete())->toThrow(QueryException::class);
});

it('starts every submission unscored and undecided', function () {
    // fresh(), not the in-memory model: the four score columns have database
    // defaults the factory deliberately does not set, so an unrefreshed model
    // answers null for review_count rather than the 0 the column holds.
    $submission = Submission::factory()->submitted()->create()->fresh();

    expect($submission?->score)->toBeNull()
        ->and($submission?->score_spread)->toBeNull()
        ->and($submission?->review_count)->toBe(0)
        ->and($submission?->scored_at)->toBeNull()
        ->and($submission?->decision)->toBeNull()
        ->and($submission?->decision_notified_at)->toBeNull()
        ->and($submission?->currentDecision())->toBeNull()
        ->and($submission?->decisionLetter())->toBeNull();
});

it('casts the two score columns to two decimal places on every driver', function () {
    // Laravel gives a decimal column NUMERIC affinity on SQLite, which returns
    // int(72)/float(72.5), while MySQL returns "72.00"/"72.50". The cast makes
    // both a two-decimal string - the same reason review_questions.weight is
    // cast - so a test written here passes in Task 11's MySQL run too.
    $submission = Submission::factory()->submitted()->create();
    $submission->forceFill(['score' => 72.5, 'score_spread' => 4, 'review_count' => 3, 'scored_at' => now()])->save();

    expect($submission->fresh()?->score)->toBe('72.50')
        ->and($submission->fresh()?->score_spread)->toBe('4.00')
        ->and($submission->fresh()?->review_count)->toBe(3)
        ->and($submission->fresh()?->scored_at)->not->toBeNull();
});

it('shows a letter only once the decision has been notified', function () {
    $submission = Submission::factory()->submitted()->create();
    $decision = SubmissionDecision::factory()->for($submission)->create([
        'decision' => Decision::AcceptedOral,
        'letter_subject' => 'AAM26-017 accepted for oral presentation',
        'letter_markdown' => 'Dear Dr Sara Al-Harbi, we are pleased...',
        'notified_at' => null,
    ]);

    // The letter exists on the row but the submission has not been notified:
    // the page must show nothing. Two conditions, both required, because a
    // half-finished send must not leak a letter.
    expect($submission->decisionLetter())->toBeNull();

    $submission->forceFill(['decision' => Decision::AcceptedOral, 'decision_notified_at' => now()])->save();
    $decision->forceFill(['notified_at' => now()])->save();

    expect($submission->fresh()?->decisionLetter()?->is($decision))->toBeTrue();
});
```

Append to `tests/Feature/Organizer/ChildPolicyBulkAbilitiesTest.php` (the file already defines `panelAllows()` and its `beforeEach`):

```php
it('never offers a bulk delete of the decision history', function () {
    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    SubmissionDecision::factory()->for($submission)->create();
    $policy = app(SubmissionDecisionPolicy::class);

    // Filament treats a policy WITHOUT the method as allowed
    // (vendor/filament/filament/src/helpers.php), so every ability a table may
    // ask for is spelled out. The history is append-only for everyone the
    // panel lets in: a decision that can be deleted is a decision that can be
    // denied.
    expect(panelAllows('viewAny', SubmissionDecision::class))->toBeTrue()
        ->and(panelAllows('create', SubmissionDecision::class))->toBeFalse()
        ->and(panelAllows('deleteAny', SubmissionDecision::class))->toBeFalse()
        ->and(panelAllows('restoreAny', SubmissionDecision::class))->toBeFalse()
        ->and(panelAllows('forceDeleteAny', SubmissionDecision::class))->toBeFalse()
        ->and($policy->update($this->member, $submission->decisions()->firstOrFail()))->toBeFalse()
        ->and($policy->delete($this->member, $submission->decisions()->firstOrFail()))->toBeFalse();

    actingAs($this->outsider);
    expect(panelAllows('viewAny', SubmissionDecision::class))->toBeFalse();
});

it('lets a member decide but only an owner or admin send the letters', function () {
    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    $submissionPolicy = app(SubmissionPolicy::class);
    $conferencePolicy = app(ConferencePolicy::class);

    $owner = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);

    // Spec section 4 puts "Invite reviewers, assign, decide" on every
    // organization member, so deciding is a member's job. SENDING is a
    // bulk-mail primitive over every author in the conference, which this plan
    // narrows to owner/admin and records as an owner question.
    expect($submissionPolicy->decide($this->member, $submission))->toBeTrue()
        ->and($conferencePolicy->sendDecisions($this->member, $submission->conference))->toBeFalse()
        ->and($submissionPolicy->decide($owner, $submission))->toBeTrue()
        ->and($conferencePolicy->sendDecisions($owner, $submission->conference))->toBeTrue()
        ->and($submissionPolicy->decide($this->outsider, $submission))->toBeFalse()
        ->and($conferencePolicy->sendDecisions($this->outsider, $submission->conference))->toBeFalse();

    // Through the Gate, not the object: this ability is always asked with a
    // Conference, so the policy Laravel resolves from that first argument is
    // the thing under test. Defined on SubmissionPolicy it would resolve
    // ConferencePolicy, find no method, and answer false for everybody.
    expect(Gate::forUser($owner)->allows('sendDecisions', $submission->conference))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('sendDecisions', $submission->conference))->toBeFalse();
});
```

with `use App\Models\SubmissionDecision;`, `use App\Policies\ConferencePolicy;`, `use App\Policies\SubmissionDecisionPolicy;`, `use App\Policies\SubmissionPolicy;` and `use Illuminate\Support\Facades\Gate;` added to the file's imports (it already imports `OrganizationRole`, `Submission`, `User` and `actingAs` — a second `use App\Models\Submission;` is a fatal, not a duplicate line to ignore).

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/DecisionModelTest.php tests/Feature/Organizer/ChildPolicyBulkAbilitiesTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -20 /tmp/t.log
```

Expected: `rc=1`, with errors naming `App\Enums\Decision` and `App\Models\SubmissionDecision`. **This is the failing-first gate: do not continue until you have seen it fail for that reason.**

- [ ] **Step 3: The four migrations**

`database/migrations/2026_09_13_000100_add_score_columns_to_submissions_table.php`
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
        Schema::table('submissions', function (Blueprint $table) {
            // Spec 5.6 normalises every answer to 0-100, so decimal(5,2) holds
            // the whole range with room to spare and keeps the value exact -
            // a float column would make "72.10" sort differently on two
            // drivers for no benefit anybody can see.
            $table->decimal('score', 5, 2)->nullable()->after('word_count');
            // Sample standard deviation of the submitted review scores. Null
            // with fewer than two of them: a spread of one observation is not
            // zero, it is undefined, and 0.00 would claim an agreement that was
            // never tested.
            $table->decimal('score_spread', 5, 2)->nullable()->after('score');
            // How many reviews were SUBMITTED. Not how many scored: a review of
            // an all-text form is still somebody's work, and the "fewer than N
            // reviews" filter is asking about people, not about arithmetic.
            $table->unsignedSmallInteger('review_count')->default(0)->after('score_spread');
            $table->timestamp('scored_at')->nullable()->after('review_count');

            // The ranking's default sort, scoped to one conference: spec
            // section 10 budgets 500 rows in under a second and this is the
            // index that makes it an index scan rather than a filesort.
            $table->index(['conference_id', 'score']);
            // The "fewer than N reviews" filter and the summary strip.
            $table->index(['conference_id', 'review_count']);
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['conference_id', 'score']);
            $table->dropIndex(['conference_id', 'review_count']);
            $table->dropColumn(['score', 'score_spread', 'review_count', 'scored_at']);
        });
    }
};
```

`database/migrations/2026_09_13_000200_add_score_to_reviews_table.php`
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
        Schema::table('reviews', function (Blueprint $table) {
            // The weighted mean of THIS review's scored answers, recomputed
            // whenever the answers change. Kept on the row rather than derived
            // on read so SubmissionScorer averages four columns instead of
            // re-walking review_answers and review_questions per review.
            //
            // Written for a draft as well as for a submitted review - it is the
            // score of the answers as they stand, and the aggregate reads only
            // submitted ones. That makes reopening a review a pure status
            // change and makes a rescore idempotent.
            $table->decimal('score', 5, 2)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('score');
        });
    }
};
```

`database/migrations/2026_09_13_000300_add_decision_columns_to_submissions_table.php`
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
        Schema::table('submissions', function (Blueprint $table) {
            // accepted_oral | accepted_poster | waitlisted | rejected, or null
            // for "not decided". A string and not a database enum, the same
            // rule every status column in this schema follows: a new case is a
            // deploy, not a migration.
            //
            // Denormalised from the newest submission_decisions row on purpose.
            // The history is what happened; this column is what is true now,
            // and it is what the ranking filters and sorts on.
            $table->string('decision', 16)->nullable()->after('status');
            // Set when the decision email is QUEUED, which is what makes
            // SendDecisionEmails idempotent and what gates the letter on
            // /s/{token}. Reset to null by a change-and-resend, so the new
            // decision goes out in the next run.
            $table->timestamp('decision_notified_at')->nullable()->after('decision');

            // The ranking's decision filter and the summary strip, and
            // SendDecisionEmails' "decided but not notified" query.
            $table->index(['conference_id', 'decision']);
            $table->index(['conference_id', 'decision_notified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['conference_id', 'decision']);
            $table->dropIndex(['conference_id', 'decision_notified_at']);
            $table->dropColumn(['decision', 'decision_notified_at']);
        });
    }
};
```

`database/migrations/2026_09_13_000400_create_submission_decisions_table.php`
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
        Schema::create('submission_decisions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // RESTRICT, like reviews.submission_id: a decision is the record of
            // what an author was told, and Plan 6's hard purge has to delete it
            // deliberately in application code rather than have a cascade do it
            // quietly (spec section 3).
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->string('decision', 16);
            // The decision survives the person. There is no user-deletion path
            // today; this is what keeps a decision from vanishing with an
            // account when Plan 6 adds one.
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            // The organizer's own note. Never sent: it is the committee's
            // reason, written for the next organizer and for the audit, and an
            // author reads the letter instead.
            $table->text('note')->nullable();

            // The letter, rendered ONCE at send time and stored, so an
            // organizer who edits the template in March does not rewrite what
            // an author was told in September. Null until this decision's email
            // is queued; a change-and-resend appends a new row and leaves these
            // three on the superseded one.
            $table->string('letter_subject')->nullable();
            $table->mediumText('letter_markdown')->nullable();
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();

            // The history is read newest-first for one submission, and the
            // status page asks for "the notified one" by the same key.
            $table->index(['submission_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_decisions');
    }
};
```

- [ ] **Step 4: The `Decision` enum**

`app/Enums/Decision.php`
```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Spec 5.6: "Decisions: `accepted_oral`, `accepted_poster`, `waitlisted`,
 * `rejected`."
 *
 * Two mappings live here and nowhere else, because a copy of either is how a
 * row comes to have a decision its status disagrees with, or a decision whose
 * email nothing can render:
 *
 * - `submissionStatus()` - what `submissions.status` becomes. Both accepted
 *   cases map to the single `Accepted` status: the *status* is the author's
 *   answer ("you are in the programme") and the *decision* is the format, and
 *   collapsing them would need two more SubmissionStatus cases that every
 *   badge, filter and label in Plans 1-4 would have to learn.
 * - `templateKey()` - which of the four spec 5.9 decision templates is sent.
 *
 * Neither match() has a default arm, so a fifth case added later is an
 * UnhandledMatchError in tests/Unit/DecisionModelTest.php rather than a silent
 * wrong answer in an author's inbox.
 */
enum Decision: string implements HasColor, HasLabel
{
    case AcceptedOral = 'accepted_oral';
    case AcceptedPoster = 'accepted_poster';
    case Waitlisted = 'waitlisted';
    case Rejected = 'rejected';

    /**
     * The label is what `{{decision}}` expands to in the decision email, so it
     * is a sentence fragment an author reads ("has been **accepted for oral
     * presentation**"), not a panel word. It is deliberately NOT translated
     * through __() here, for the same reason every other enum in this codebase
     * is not: the language sweep of spec section 10 is a single backlog item
     * covering all of them, and half-translating one enum is worse than
     * translating none.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::AcceptedOral => 'Accepted for oral presentation',
            self::AcceptedPoster => 'Accepted for poster presentation',
            self::Waitlisted => 'Waitlisted',
            self::Rejected => 'Not accepted',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AcceptedOral => 'success',
            self::AcceptedPoster => 'success',
            self::Waitlisted => 'warning',
            self::Rejected => 'danger',
        };
    }

    public function submissionStatus(): SubmissionStatus
    {
        return match ($this) {
            self::AcceptedOral, self::AcceptedPoster => SubmissionStatus::Accepted,
            self::Waitlisted => SubmissionStatus::Waitlisted,
            self::Rejected => SubmissionStatus::Rejected,
        };
    }

    public function templateKey(): EmailTemplateKey
    {
        return match ($this) {
            self::AcceptedOral => EmailTemplateKey::DecisionAcceptedOral,
            self::AcceptedPoster => EmailTemplateKey::DecisionAcceptedPoster,
            self::Waitlisted => EmailTemplateKey::DecisionWaitlisted,
            self::Rejected => EmailTemplateKey::DecisionRejected,
        };
    }

    public function isAccepted(): bool
    {
        return in_array($this, [self::AcceptedOral, self::AcceptedPoster], true);
    }

    /**
     * The four cases in the order the summary strip and the send-emails modal
     * print them: the good news first, then the waiting list, then the
     * refusals. `cases()` already returns them in this order; this method
     * exists so a future reordering of the enum cannot silently reorder the
     * organizer's screen.
     *
     * @return list<self>
     */
    public static function inReportOrder(): array
    {
        return [self::AcceptedOral, self::AcceptedPoster, self::Waitlisted, self::Rejected];
    }
}
```

- [ ] **Step 5: The exception, the model, the factory**

`app/Exceptions/DecisionNotAcceptable.php`
```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Mirrors App\Exceptions\SubmissionNotAcceptable and Plan 2's
 * ConferenceNotPublishable: a list of sentences an organizer can act on, not a
 * validation code. ApplyDecision::blockers() is the read-only half; this is
 * what handle() throws when a caller ignored it.
 */
class DecisionNotAcceptable extends RuntimeException
{
    /** @param  list<string>  $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }

    public static function because(string ...$reasons): self
    {
        return new self(array_values($reasons));
    }
}
```

`app/Models/SubmissionDecision.php`
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Decision;
use Database\Factories\SubmissionDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One row per decision *event* (spec 5.6: "Each decision records who and when
 * and appends to `SubmissionDecision`"), plus the letter that announced it.
 *
 * The letter columns are filled when this decision's email is queued and are
 * null until then. A resend of the SAME decision overwrites them, because the
 * author is now holding a newly sent email and /s/{token} has to show what is
 * in their inbox; a CHANGED decision appends a new row and leaves this one's
 * letter alone, which is what keeps the audit honest. Every individual send is
 * recorded in `email_logs` regardless.
 */
class SubmissionDecision extends Model
{
    /** @use HasFactory<SubmissionDecisionFactory> */
    use HasFactory;

    /**
     * Every column is written by ApplyDecision or by SendOneDecisionEmail with
     * forceFill(). Model::preventSilentlyDiscardingAttributes() is on outside
     * production, so a fillable `decision` would be a form field that decides.
     */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decision' => Decision::class,
            'decided_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SubmissionDecision $decision): void {
            $decision->ulid ??= (string) Str::ulid();
        });
    }

    /** Spec section 3: ULIDs are the public identifiers. */
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
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function wasNotified(): bool
    {
        return $this->notified_at !== null && $this->letter_markdown !== null;
    }

    /**
     * Who made this decision, for the history list. A null `decided_by` is a
     * deleted account, not an anonymous decision - the row is nulled on delete
     * precisely so the decision survives - so it says so rather than printing
     * an empty cell.
     */
    public function actorName(): string
    {
        return (string) ($this->decidedBy?->name ?? __('decisions.history.former_member'));
    }
}
```

`database/factories/SubmissionDecisionFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Decision;
use App\Models\Submission;
use App\Models\SubmissionDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubmissionDecision> */
class SubmissionDecisionFactory extends Factory
{
    protected $model = SubmissionDecision::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory()->submitted(),
            'decision' => Decision::AcceptedOral,
            'decided_by' => User::factory(),
            'decided_at' => now(),
            'note' => null,
            'letter_subject' => null,
            'letter_markdown' => null,
            'notified_at' => null,
        ];
    }

    /** The state a sent decision is in: a stored letter and a timestamp. */
    public function notified(string $markdown = 'Dear author, your abstract has been **accepted**.'): static
    {
        return $this->state(fn (): array => [
            'letter_subject' => 'A decision on your abstract',
            'letter_markdown' => $markdown,
            'notified_at' => now(),
        ]);
    }
}
```

- [ ] **Step 6: The two modified models**

`app/Models/Submission.php` — add `'decision' => Decision::class`, the two score casts and the three timestamp casts to `casts()`, so the method becomes:

```php
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'presentation_preference' => PresentationPreference::class,
            'decision' => Decision::class,
            'custom_field_values' => 'array',
            'word_count' => 'integer',
            // Laravel gives a decimal column NUMERIC affinity on SQLite, which
            // returns int(72) / float(72.5), while MySQL returns "72.00" /
            // "72.50". The cast makes both a two-decimal string, exactly as
            // ReviewQuestion::weight is cast, so a value written here reads the
            // same on both drivers and a test written locally passes in CI.
            // Every reader that needs arithmetic casts to float itself.
            'score' => 'decimal:2',
            'score_spread' => 'decimal:2',
            'review_count' => 'integer',
            'scored_at' => 'datetime',
            'decision_notified_at' => 'datetime',
            'submitted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'last_edited_at' => 'datetime',
        ];
    }
```

and add these methods after `files()` (which Plan 4 has already followed with `reviews()`, `reviewAssignments()` and `isReviewable()` — put these after those):

```php
    /**
     * The whole history, newest first. Spec 5.6: "Each decision records who and
     * when and appends to `SubmissionDecision`."
     *
     * @return HasMany<SubmissionDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(SubmissionDecision::class)->orderByDesc('decided_at')->orderByDesc('id');
    }

    /**
     * The row behind `submissions.decision`. Reads the loaded relation when
     * there is one, so a history list already eager-loaded does not issue a
     * query per row - the same rule correspondingAuthor() follows.
     */
    public function currentDecision(): ?SubmissionDecision
    {
        if ($this->relationLoaded('decisions')) {
            return $this->decisions->first();
        }

        return $this->decisions()->first();
    }

    /**
     * The letter the author may read on /s/{token}, or null.
     *
     * Three conditions, all required. `decision_notified_at` on this row is the
     * one SendDecisionEmails sets, so a half-finished send cannot leak a
     * letter; `notified_at` and `letter_markdown` on the decision row are what
     * make it a letter rather than an intention; and a withdrawn abstract never
     * shows one, because an author who withdrew is not waiting for an answer
     * and an old decision printed under "Withdrawn" would read as a reversal.
     */
    public function decisionLetter(): ?SubmissionDecision
    {
        if ($this->decision_notified_at === null || $this->status === SubmissionStatus::Withdrawn) {
            return null;
        }

        $decision = $this->currentDecision();

        return $decision?->wasNotified() === true ? $decision : null;
    }
```

**No `isRanked()` convenience predicate on this model, deliberately.** "Which abstracts a conference ranks" has exactly one definition in this plan — `App\Support\Scoring\RankedSubmissions::statuses()` / `::query()` (Task 4) — and every caller goes through it. A per-row method repeating the Draft/Withdrawn list here is the second definition Task 11 Step 8's grep exists to catch. If a per-row predicate is wanted later, it must be written in terms of `RankedSubmissions::statuses()` rather than repeating the pair.

with `use App\Enums\Decision;` added to the imports (`HasMany` and `SubmissionStatus` are already imported).

Also extend the activity-log field list, so a decision shows up in the audit trail spec section 9 asks for:

```php
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // Never `abstract` and never `access_token_hash`: the activity log
            // is readable by the platform admin, and neither belongs there.
            // `decision` and `decision_notified_at` do: spec section 9 lists
            // decisions among the things that must be audited.
            ->logOnly(['status', 'reference', 'title', 'submitted_at', 'withdrawn_at', 'decision', 'decision_notified_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
```

`app/Models/Review.php` (Plan 4's) — add one cast to `casts()`:

```php
            // Written by ComputeSubmissionScore for every review, draft or
            // submitted; the submission aggregate reads only the submitted
            // ones. Same decimal:2 reasoning as submissions.score.
            'score' => 'decimal:2',
```

`database/factories/SubmissionFactory.php` — add two states after `withdrawn()`:

```php
    /**
     * A submission that already carries denormalised scores, for the ranking
     * and export tests that do not care how the numbers got there. Nothing in
     * production writes these columns except ComputeSubmissionScore; a factory
     * runs inside Model::unguarded(), so it may.
     */
    public function scored(float $score = 72.5, ?float $spread = 4.0, int $reviews = 2): static
    {
        return $this->submitted()->state(fn (): array => [
            'score' => $score,
            'score_spread' => $spread,
            'review_count' => $reviews,
            'scored_at' => now(),
        ]);
    }

    /**
     * A decided submission, with the matching status AND the history row the
     * denormalised columns are a view of. `notified` also stamps
     * decision_notified_at, which is what SendDecisionEmails skips on and what
     * gates the letter on /s/{token}.
     *
     * The afterCreating() half is not decoration. Writing only the columns
     * produces a fixture that looks like a hand-written UPDATE, and
     * SendOneDecisionEmail::blockers() (Task 7) refuses exactly that with
     * `no_history` - so without the row every case in DecisionEmailsTest would
     * skip both of its fixtures and report `sent` 0.
     */
    public function decided(Decision $decision = Decision::AcceptedOral, bool $notified = false): static
    {
        return $this->submitted()
            ->state(fn (): array => [
                'decision' => $decision,
                'status' => $decision->submissionStatus(),
                'decision_notified_at' => $notified ? now() : null,
            ])
            ->afterCreating(function (Submission $submission) use ($decision, $notified): void {
                $row = new SubmissionDecision;
                $row->forceFill([
                    'submission_id' => $submission->getKey(),
                    'decision' => $decision,
                    'decided_by' => User::factory()->create()->getKey(),
                    'decided_at' => now(),
                    'note' => null,
                    'letter_subject' => $notified ? 'A decision on your abstract' : null,
                    'letter_markdown' => $notified ? 'Dear author, a decision has been made.' : null,
                    'notified_at' => $notified ? now() : null,
                ])->save();
            });
    }
```

with `use App\Enums\Decision;`, `use App\Models\SubmissionDecision;` and `use App\Models\User;` added to that file's imports (`Submission` is already imported).

- [ ] **Step 7: The policies**

`app/Policies/SubmissionDecisionPolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\SubmissionDecision;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * The decision history is **append-only** for everyone the panel lets in.
 *
 * Reading it is reading a submission: any role in the conference's
 * organization. Writing it is not a thing a screen does - ApplyDecision appends
 * a row and SendOneDecisionEmail fills in the letter columns, both through
 * `SubmissionPolicy::decide` and `ConferencePolicy::sendDecisions` - so
 * `create`, `update` and every delete ability is false here. A decision that can be deleted is a
 * decision that can be denied, and spec section 9 requires the opposite.
 *
 * Every ability Filament might ask for is spelled out, including the three bulk
 * ones: a policy without the method means ALLOW, not 403
 * (vendor/filament/filament/src/helpers.php:59-93). Note what these `false`s do
 * NOT cover: before() returns true for a platform admin and short-circuits all
 * of them, so when Plan 6 gives the admin panel a screen over these rows,
 * deletion has to be refused there or in before().
 */
class SubmissionDecisionPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }

    /**
     * The same nullable walk SubmissionPolicy::view() makes, and for the same
     * two reasons: the conference is invisible under another tenant's global
     * scope and both it and the organization soft delete, so either hop can be
     * null while this row survives. A gate answers "no"; it does not 500.
     */
    public function view(User $user, SubmissionDecision $decision): bool
    {
        $organization = $decision->submission?->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SubmissionDecision $decision): bool
    {
        return false;
    }

    public function delete(User $user, SubmissionDecision $decision): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, SubmissionDecision $decision): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, SubmissionDecision $decision): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
```

`app/Policies/SubmissionPolicy.php` — add **one** method after `resendLink()` (leave `export()` exactly as it is; it already exists and both ranking exports reuse it). No new import is needed:

```php
    /**
     * Spec section 4 puts "Invite reviewers, assign, decide" on **every**
     * organization member, owner down to plain member, so deciding mirrors
     * view(). Every application is logged with the actor and appends to the
     * history, so a mistaken decision is visible and reversible rather than
     * silent.
     */
    public function decide(User $user, Submission $submission): bool
    {
        return $this->view($user, $submission);
    }
```

`app/Policies/ConferencePolicy.php` — add one method after `archive()`. No new import is needed either: that file already has `use App\Models\Conference;`.

```php
    /**
     * **Narrower than the spec row on purpose, and recorded as an owner
     * question.** "Send decision emails" is a single click that queues one
     * email to the corresponding author of every decided abstract in a
     * conference - the largest bulk-mail primitive in the application, and
     * unlike a reviewer invitation it is addressed to people who did not ask
     * for an account and cannot be un-sent. Plan 4 bounded its bulk-mail
     * primitive with a cap and a rate limit; this one is bounded by role:
     * owner and admin only, which is spec section 4's own "Manage organization
     * members" row applied to the one action with the same blast radius.
     *
     * It lives HERE and not on SubmissionPolicy for a mechanical reason:
     * Laravel resolves a policy from the first argument's class
     * (vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:781-785),
     * every call site passes the Conference, and an ability whose method is not
     * on the resolved policy answers false for everybody - the send button
     * would simply never appear and the resend would always throw. Taking the
     * Conference is itself the right shape: the send is per conference, and a
     * policy method whose argument is one row would have to be called with an
     * arbitrary row to authorize an action over all of them.
     */
    public function sendDecisions(User $user, Conference $conference): bool
    {
        // canManage() is this file's own owner/admin check
        // (app/Policies/ConferencePolicy.php:107-110), the one shape in this
        // codebase already proven clean at Larastan level 6 - it uses `->` and
        // not `?->` on the organization, because Larastan types a BelongsTo
        // accessor as non-nullable and reports a null check here as an
        // always-true condition.
        return $this->canManage($user, $conference);
    }
```

(`SubmissionDecisionPolicy::view()` above keeps its `!== null` guard for the opposite reason: its walk starts with `?->`, which makes the chain genuinely nullable — the same asymmetry `SubmissionPolicy::view()` already lives with.)

Laravel discovers `SubmissionDecisionPolicy` by convention (`App\Models\SubmissionDecision` → `App\Policies\SubmissionDecisionPolicy`), exactly as every other policy in this application is discovered; there is no `AuthServiceProvider` map to edit.

- [ ] **Step 8: The config block**

`config/cass.php` — append before `'countries' => [`:

```php
    // Spec 5.6 (decisions) and spec section 10 (the 500-row ranking budget).
    'decisions' => [
        // Rows the ranking table shows per page before the organizer asks for
        // more. 50 is two screens of scrolling and one query; the page also
        // offers 100, 250 and "all", and "all" over 500 rows is what the
        // performance test in Task 4 measures.
        'page_size' => (int) env('CASS_RANKING_PAGE_SIZE', 50),
        // How many decision emails one "Send decision emails" click queues
        // before it stops and tells the organizer to click again. The whole run
        // happens inside one php-fpm request (docker/php.ini's 60-second
        // budget), and each row is a render, a token mint, an email_logs insert
        // and a queue push. 200 is comfortably inside it for the conference
        // sizes this platform is for; raise it only after measuring.
        'send_chunk' => (int) env('CASS_DECISION_SEND_CHUNK', 200),
    ],
```

- [ ] **Step 9: The language file, with the one key this task's code resolves**

`SubmissionDecision::actorName()` (Step 5) resolves `decisions.history.former_member`, so the file has to exist from this commit — a method that returns its own key as a literal string is a bug that nothing notices until Task 6 renders the history. Tasks 4 to 9 append their groups to this same file and Task 10 sweeps it.

`lang/en/decisions.php`
```php
<?php

declare(strict_types=1);

// The ranking page, the decision actions and the decision emails all land in
// this file across Tasks 4-9. This task needs exactly one key: the fallback
// SubmissionDecision::actorName() prints when `decided_by` was nulled by a
// deleted account.
return [

    'history' => [
        'former_member' => 'a former member',
    ],

];
```

- [ ] **Step 10: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/DecisionModelTest.php tests/Feature/Organizer/ChildPolicyBulkAbilitiesTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -6 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`; about 16 passed in the first (`ChildPolicyBulkAbilitiesTest` carries its own Plan 2-4 cases) and `baseline + 9` in the second.

If `it('restricts deleting a submission that has a decision')` fails with "no such thing as a foreign key", check that the suite really runs with foreign keys on — `tests/TestCase.php` / the SQLite connection config — before weakening the test.

- [ ] **Step 11: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(decisions): score columns, the decision enum and an append-only history

Four denormalised columns on submissions turn the ranking into an index scan.
submission_decisions is the history of decision events and carries the letter
that announced each one, rendered once at send time so a later template edit
cannot rewrite what an author was told.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 2: `App\Support\Scoring` — normalisation, weighting and spread, with exhaustive unit tests

The arithmetic of spec 5.6, in three classes that write nothing, read no config, touch no Filament and have no side effects. Spec section 12's first unit-test bullet is "scoring normalisation and weighting"; this task is that bullet.

```
Each answer   -> AnswerNormaliser  -> 0-100, or null
Each review   -> ReviewScorer      -> weighted mean of its non-null answers, or null
Each abstract -> SubmissionScorer  -> mean, sample SD and count of its submitted reviews
```

**Five rules this task decides, each because the spec sentence stops one word short of saying it.**

**1. A value outside its own scale is clamped, not extrapolated.** Spec 5.6 gives `(value − min) / (max − min) × 100`. Plan 4's `SubmitReview` validates a Likert answer `between:scale_min,scale_max`, so a submitted review cannot hold one out of range — but a *draft* can (a draft is deliberately not validated), the Plan 6 legacy import will, and an organizer who narrows a scale before the form locks leaves old answers behind. The formula applied literally to a 7 on a 1–5 scale gives 150, which then drags a mean above 100 and makes the ranking's numbers unexplainable. Clamping to 0–100 keeps a bad row visible in its own review and harmless in the aggregate.

**2. `max <= min` scores nothing.** `scale_max` is validated `->gt('scale_min')` in the panel and both columns are nullable in the database, so a null bound or a legacy row with `min = max` reaches this code. Dividing by zero in PHP 8 raises `DivisionByZeroError`, which is a 500 on a ranking page. Null is the right answer: a scale with no range carries no information.

**3. A weight of zero — or less — removes the question from the mean rather than zeroing it.** `review_questions.weight` is `decimal(5,2)` with `minValue(0)` in the form, so `0.00` is a value an organizer can set and clearly means "do not count this". Including it with weight 0 gives the same mean but makes the total weight smaller for no reason; and if *every* question is weight 0 the total is 0 and the division is the same crash as rule 2. So: skip non-positive weights, and a review whose scored questions all have weight 0 has a null score.

**4. `review_count` counts submitted reviews; `score` and `spread` are computed only from the ones that produced a number.** They are two different questions. "How many people reviewed this" is what the organizer filters on when chasing stragglers, and a reviewer who filled in an all-text form did the work. "What did they score it" is arithmetic over the reviews that scored. The two can disagree — three reviews, one score — and the ranking prints both side by side so the disagreement is visible rather than averaged away.

**5. The spread is the *sample* standard deviation and is null below two reviews.** Spec 5.6 says "sample standard deviation" and the sample formula divides by `n − 1`, which is undefined for one observation. Returning `0.00` for a single review would print perfect agreement between one person and themselves, right next to a genuine 0.00 from two reviewers who agreed — and an organizer sorting by spread to find the contentious abstracts would find neither. Null, and the table prints an em dash.

**Where the numbers are rounded, once.** Every public method rounds to two decimals with `round($value, 2)` at the moment it returns, because the columns are `decimal(5,2)` and a value that is rounded on write but not on compute makes `72.495` become `72.50` in the database and `72.49` in a test. `ReviewScorer` rounds its review score; `SubmissionScorer` rounds the mean and the spread **from the unrounded review scores it was given**, not from the stored two-decimal ones — so `ComputeSubmissionScore` (Task 3) hands it what it just computed rather than re-reading the column it just wrote.

**Files:**
- Create: `app/Support/Scoring/AnswerNormaliser.php`, `app/Support/Scoring/ReviewScorer.php`, `app/Support/Scoring/SubmissionScorer.php`
- Test: `tests/Unit/AnswerNormaliserTest.php`, `tests/Unit/ReviewScorerTest.php`, `tests/Unit/SubmissionScorerTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/AnswerNormaliserTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ReviewQuestionType;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewQuestion;
use App\Support\Scoring\AnswerNormaliser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- The pure half: no models, no database ------------------------------

it('turns a likert value into a percentage of its own scale', function (?int $value, ?int $min, ?int $max, ?float $expected) {
    expect(AnswerNormaliser::likert($value, $min, $max))->toBe($expected);
})->with([
    'bottom of 1-5'            => [1, 1, 5, 0.0],
    'middle of 1-5'            => [3, 1, 5, 50.0],
    'top of 1-5'               => [5, 1, 5, 100.0],
    'an awkward point of 1-5'  => [2, 1, 5, 25.0],
    'bottom of 0-10'           => [0, 0, 10, 0.0],
    'middle of 0-10'           => [5, 0, 10, 50.0],
    'a two point scale'        => [1, 0, 1, 100.0],
    // Rule 1: clamped, not extrapolated.
    'above its own scale'      => [7, 1, 5, 100.0],
    'below its own scale'      => [0, 1, 5, 0.0],
    // Rule 2: no range, no information. None of these may divide by zero.
    'min equals max'           => [3, 3, 3, null],
    'max below min'            => [3, 5, 1, null],
    'no bounds at all'         => [3, null, null, null],
    'only a minimum'           => [3, 1, null, null],
    'only a maximum'           => [3, null, 5, null],
    // No answer is not a zero answer.
    'unanswered'               => [null, 1, 5, null],
]);

it('never divides by zero, whatever the bounds', function () {
    // The explicit statement of rule 2: a DivisionByZeroError here is a 500 on
    // the ranking page, and the guard is one line that is easy to "simplify".
    //
    // Assert the ANSWER, not the absence of one exception class. Pest's
    // ->not->toThrow() catches the ExpectationFailedException that a
    // non-matching Throwable raises and treats it as a pass
    // (pest/src/Expectations/OppositeExpectation.php:627-641), so
    // `->not->toThrow(DivisionByZeroError::class)` is green even when the class
    // does not exist yet - it could never be shown failing first.
    foreach ([[3, 3, 3], [0, 0, 0], [10, 10, 10], [1, 5, 5]] as [$value, $min, $max]) {
        expect(AnswerNormaliser::likert($value, $min, $max))->toBeNull();
    }
});

it('clamps any score into the reportable range', function () {
    expect(AnswerNormaliser::clamp(-5.0))->toBe(0.0)
        ->and(AnswerNormaliser::clamp(0.0))->toBe(0.0)
        ->and(AnswerNormaliser::clamp(50.0))->toBe(50.0)
        ->and(AnswerNormaliser::clamp(100.0))->toBe(100.0)
        ->and(AnswerNormaliser::clamp(150.0))->toBe(100.0);
});

// --- The model half -----------------------------------------------------

function answerFor(ReviewQuestion $question, array $columns): ReviewAnswer
{
    $review = Review::factory()->create(['review_form_id' => $question->review_form_id]);

    $answer = new ReviewAnswer;
    $answer->forceFill([
        'review_id' => $review->getKey(),
        'review_question_id' => $question->getKey(),
        'value_int' => null, 'value_text' => null, 'value_bool' => null, 'choice_key' => null,
        ...$columns,
    ])->save();

    return $answer->fresh() ?? $answer;
}

it('scores a likert answer from the question it answers', function () {
    $question = ReviewQuestion::factory()->create(['scale_min' => 1, 'scale_max' => 5]);

    expect(AnswerNormaliser::normalise($question, answerFor($question, ['value_int' => 4])))->toBe(75.0)
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['value_int' => null])))->toBeNull();
});

it('scores yes as a hundred and no as a zero', function () {
    $question = ReviewQuestion::factory()->create([
        'type' => ReviewQuestionType::Boolean,
        'scale_min' => null,
        'scale_max' => null,
    ]);

    // `false` is an answer and must not be swallowed by a `??`. This is the
    // case a naive implementation gets wrong and no other case catches.
    expect(AnswerNormaliser::normalise($question, answerFor($question, ['value_bool' => true])))->toBe(100.0)
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['value_bool' => false])))->toBe(0.0)
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['value_bool' => null])))->toBeNull();
});

it('scores a select answer from the chosen option, and only when it carries one', function () {
    $question = ReviewQuestion::factory()->create([
        'type' => ReviewQuestionType::Select,
        'scale_min' => null,
        'scale_max' => null,
        // The panel's own editor declares these 0-100
        // (ReviewQuestionsRelationManager:103-104), so they are passed through
        // rather than converted from some other scale.
        'options' => [
            ['label' => 'Strong accept', 'score' => 100],
            ['label' => 'Weak accept', 'score' => 60],
            ['label' => 'No opinion', 'score' => null],
        ],
    ]);

    expect(AnswerNormaliser::normalise($question, answerFor($question, ['choice_key' => 'Strong accept'])))->toBe(100.0)
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['choice_key' => 'Weak accept'])))->toBe(60.0)
        // A choice with no score contributes nothing at all - not a zero.
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['choice_key' => 'No opinion'])))->toBeNull()
        // A key that is not on the question any more: the label was edited
        // before the form locked. Visible as a missing score, never as a zero.
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['choice_key' => 'Deleted choice'])))->toBeNull()
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['choice_key' => null])))->toBeNull();
});

it('never scores a text answer', function () {
    $question = ReviewQuestion::factory()->freeText()->create();

    // Spec 5.6: "text answers do not score." Not even a numeric-looking one -
    // this is the case that turns "4" into a silent 4/100.
    expect(AnswerNormaliser::normalise($question, answerFor($question, ['value_text' => 'Excellent work'])))->toBeNull()
        ->and(AnswerNormaliser::normalise($question, answerFor($question, ['value_text' => '100'])))->toBeNull();
});

it('reads the value column the question type names, not the first one that is set', function () {
    // A question edited from Likert to Boolean before the form locked leaves a
    // value_int behind. The type decides which column means anything.
    $question = ReviewQuestion::factory()->create([
        'type' => ReviewQuestionType::Boolean,
        'scale_min' => null,
        'scale_max' => null,
    ]);

    expect(AnswerNormaliser::normalise($question, answerFor($question, ['value_int' => 5, 'value_bool' => false])))->toBe(0.0);
});
```

`tests/Unit/ReviewScorerTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ReviewQuestionType;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Support\Scoring\ReviewScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- The pure half ------------------------------------------------------

it('takes the weighted mean of what was scored', function (array $scored, ?float $expected) {
    expect(ReviewScorer::weightedMean($scored))->toBe($expected);
})->with([
    'nothing scored'          => [[], null],
    'one answer'              => [[['score' => 80.0, 'weight' => 1.0]], 80.0],
    'equal weights'           => [[['score' => 100.0, 'weight' => 1.0], ['score' => 0.0, 'weight' => 1.0]], 50.0],
    'one worth three'         => [[['score' => 100.0, 'weight' => 3.0], ['score' => 0.0, 'weight' => 1.0]], 75.0],
    'fractional weights'      => [[['score' => 100.0, 'weight' => 0.5], ['score' => 50.0, 'weight' => 0.25]], 83.33],
    // Rule 3: a zero weight is "do not count this", not "count this as zero".
    'a zero weight is out'    => [[['score' => 100.0, 'weight' => 1.0], ['score' => 0.0, 'weight' => 0.0]], 100.0],
    'a negative weight is out' => [[['score' => 100.0, 'weight' => 1.0], ['score' => 0.0, 'weight' => -2.0]], 100.0],
    'every weight zero'       => [[['score' => 100.0, 'weight' => 0.0], ['score' => 0.0, 'weight' => 0.0]], null],
    // Rounded once, on return, because the column is decimal(5,2).
    'rounds to two places'    => [[['score' => 100.0, 'weight' => 1.0], ['score' => 0.0, 'weight' => 2.0]], 33.33],
]);

it('never divides by zero however the weights are set', function () {
    // The value, not the absence of one exception class: Pest's ->not->toThrow()
    // passes for a DIFFERENT Throwable too (OppositeExpectation.php:627-641),
    // so it would be green with ReviewScorer missing entirely.
    expect(ReviewScorer::weightedMean([['score' => 50.0, 'weight' => 0.0]]))->toBeNull();
});

// --- The model half -----------------------------------------------------

function reviewWithAnswers(array $questions, array $values): Review
{
    $form = ReviewForm::factory()->create();
    $review = Review::factory()->create(['review_form_id' => $form->getKey()]);

    foreach ($questions as $index => $attributes) {
        $question = ReviewQuestion::factory()->for($form)->create($attributes);
        $answer = new ReviewAnswer;
        $answer->forceFill([
            'review_id' => $review->getKey(),
            'review_question_id' => $question->getKey(),
            'value_int' => null, 'value_text' => null, 'value_bool' => null, 'choice_key' => null,
            ...($values[$index] ?? []),
        ])->save();
    }

    return $review->fresh() ?? $review;
}

it('weights a real review by its questions', function () {
    $review = reviewWithAnswers(
        [
            ['scale_min' => 1, 'scale_max' => 5, 'weight' => '3.00'],
            ['scale_min' => 1, 'scale_max' => 5, 'weight' => '1.00'],
        ],
        [['value_int' => 5], ['value_int' => 1]],
    );

    // (100 * 3 + 0 * 1) / 4 = 75
    expect(app(ReviewScorer::class)->forReview($review))->toBe(75.0);
});

it('ignores the text answers and scores the rest', function () {
    $review = reviewWithAnswers(
        [
            ['scale_min' => 1, 'scale_max' => 5, 'weight' => '1.00'],
            ['type' => ReviewQuestionType::Text, 'scale_min' => null, 'scale_max' => null, 'weight' => '5.00', 'required' => false],
        ],
        [['value_int' => 4], ['value_text' => 'A long comment that must not count for anything']],
    );

    // The comment's weight of 5 must not appear in the denominator either.
    expect(app(ReviewScorer::class)->forReview($review))->toBe(75.0);
});

it('has no score at all when nothing on the form scores', function () {
    $review = reviewWithAnswers(
        [['type' => ReviewQuestionType::Text, 'scale_min' => null, 'scale_max' => null, 'required' => false]],
        [['value_text' => 'Only words']],
    );

    // Spec 12's "weighting" case that an implementation most often gets wrong
    // by returning 0.0: an all-text review is UNSCORED, and an unscored review
    // must not drag a submission's mean to zero.
    expect(app(ReviewScorer::class)->forReview($review))->toBeNull();
});

it('has no score when the review has no answers at all', function () {
    $review = Review::factory()->create();

    expect(app(ReviewScorer::class)->forReview($review))->toBeNull();
});

it('skips an answer whose question belongs to another form', function () {
    // review_answers.review_question_id is a real foreign key but it does not
    // constrain the question to the review's own form. ReviewScorer reads the
    // question each answer points at, so the arithmetic is right either way -
    // this pins that it does not crash or double-count.
    $review = reviewWithAnswers([['scale_min' => 1, 'scale_max' => 5, 'weight' => '1.00']], [['value_int' => 5]]);

    $stray = ReviewQuestion::factory()->create(['scale_min' => 1, 'scale_max' => 5, 'weight' => '1.00']);
    $answer = new ReviewAnswer;
    $answer->forceFill([
        'review_id' => $review->getKey(),
        'review_question_id' => $stray->getKey(),
        'value_int' => 1, 'value_text' => null, 'value_bool' => null, 'choice_key' => null,
    ])->save();

    expect(app(ReviewScorer::class)->forReview($review->fresh() ?? $review))->toBe(50.0);
});
```

`tests/Unit/SubmissionScorerTest.php`
```php
<?php

declare(strict_types=1);

use App\Support\Scoring\SubmissionScorer;

// No database at all: this class is arithmetic over a list of numbers, and
// ComputeSubmissionScore (Task 3) is the thing that reads rows.

it('summarises a list of review scores', function (array $scores, int $submitted, ?float $score, ?float $spread) {
    $summary = SubmissionScorer::summarise($scores, $submitted);

    expect($summary['score'])->toBe($score)
        ->and($summary['spread'])->toBe($spread)
        ->and($summary['review_count'])->toBe($submitted);
})->with([
    // Spec 5.6: "spread = sample standard deviation", which divides by n-1 and
    // is undefined below two observations.
    'no reviews'              => [[], 0, null, null],
    'one review'              => [[80.0], 1, 80.0, null],
    'two that agree'          => [[80.0, 80.0], 2, 80.0, 0.0],
    'two that do not'         => [[70.0, 90.0], 2, 80.0, 14.14],
    'three'                   => [[60.0, 70.0, 80.0], 3, 70.0, 10.0],
    'three with a decimal'    => [[61.5, 70.25, 80.0], 3, 70.58, 9.25],
    // The two numbers answer different questions (rule 4): three people
    // reviewed, one of them filled in an all-text form.
    'more reviewers than scores' => [[70.0, 90.0], 3, 80.0, 14.14],
    // Submitted reviews, none of which scored: the count still stands.
    'reviewed but unscored'   => [[], 2, null, null],
]);

it('is not fooled by a zero score', function () {
    // 0.0 is a real score - every reviewer gave the bottom of the scale - and
    // an implementation that uses `?:` or array_filter() without a strict
    // callback loses it.
    $summary = SubmissionScorer::summarise([0.0, 0.0], 2);

    expect($summary['score'])->toBe(0.0)
        ->and($summary['spread'])->toBe(0.0)
        ->and($summary['review_count'])->toBe(2);
});

it('rounds the mean and the spread to two places', function () {
    $summary = SubmissionScorer::summarise([1 / 3 * 100, 2 / 3 * 100], 2);

    expect($summary['score'])->toBe(50.0)
        ->and($summary['spread'])->toBe(23.57);
});

it('never divides by zero', function () {
    // The values, not the absence of one exception class: Pest's
    // ->not->toThrow() passes for any other Throwable as well
    // (OppositeExpectation.php:627-641), so it would be green before the class
    // exists and could never be shown failing first.
    expect(SubmissionScorer::summarise([], 0)['score'])->toBeNull()
        ->and(SubmissionScorer::summarise([], 0)['spread'])->toBeNull()
        ->and(SubmissionScorer::summarise([50.0], 1)['score'])->toBe(50.0)
        ->and(SubmissionScorer::summarise([50.0], 1)['spread'])->toBeNull();
});
```

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/AnswerNormaliserTest.php tests/Unit/ReviewScorerTest.php tests/Unit/SubmissionScorerTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -20 /tmp/t.log
```

Expected: `rc=1`, naming `App\Support\Scoring\AnswerNormaliser`.

- [ ] **Step 2: `AnswerNormaliser`**

`app/Support/Scoring/AnswerNormaliser.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Scoring;

use App\Enums\ReviewQuestionType;
use App\Models\ReviewAnswer;
use App\Models\ReviewQuestion;

/**
 * Spec 5.6, first bullet: "Each answer is normalised to 0-100: Likert
 * `(value - min) / (max - min) x 100`; boolean yes = 100, no = 0; select
 * options carry an optional score; text answers do not score."
 *
 * Null everywhere means "this answer contributes nothing", and it is the
 * difference between an abstract nobody scored and an abstract everybody hated.
 * Zero is a score. Null is not.
 *
 * Nothing here reads the database, writes anything, or knows what a conference
 * is: it takes a question and an answer and returns a number or null. The two
 * genuinely pure helpers - likert() and clamp() - are public so they can be
 * exercised without a row, which is what makes the boundary table in
 * tests/Unit/AnswerNormaliserTest.php cheap enough to be exhaustive.
 */
final class AnswerNormaliser
{
    public const MIN = 0.0;

    public const MAX = 100.0;

    public static function normalise(ReviewQuestion $question, ReviewAnswer $answer): ?float
    {
        // The TYPE decides which column means anything, never "the first column
        // that is not null": a question edited from Likert to Boolean before
        // the form locked leaves a stale value_int behind, and a `??` chain
        // over four columns would read it.
        return match ($question->type) {
            ReviewQuestionType::Likert => self::likert($answer->value_int, $question->scale_min, $question->scale_max),
            ReviewQuestionType::Boolean => self::boolean($answer->value_bool),
            ReviewQuestionType::Select => self::select($question, $answer->choice_key),
            // Spec 5.6, verbatim. Not even a numeric-looking comment.
            ReviewQuestionType::Text => null,
        };
    }

    /**
     * The spec formula, with two guards the sentence leaves implicit.
     *
     * `max <= min` (including both null, one null, or a legacy row where they
     * are equal) answers null rather than dividing by zero: a scale with no
     * range carries no information, and a DivisionByZeroError here is a 500 on
     * the ranking page.
     *
     * A value outside its own scale is clamped rather than extrapolated. Plan
     * 4's SubmitReview validates `between:scale_min,scale_max`, so a *submitted*
     * review cannot hold one - but a draft is deliberately unvalidated, the
     * Plan 6 import will carry whatever the legacy database holds, and an
     * organizer may narrow a scale before the form locks. A 7 on a 1-5 scale
     * would otherwise score 150 and drag a mean above 100.
     */
    public static function likert(?int $value, ?int $min, ?int $max): ?float
    {
        if ($value === null || $min === null || $max === null || $max <= $min) {
            return null;
        }

        return self::clamp((($value - $min) / ($max - $min)) * self::MAX);
    }

    public static function boolean(?bool $value): ?float
    {
        // Not `$value ? 100 : 0` over a nullable: false is an answer and null
        // is not, and collapsing them scores every unanswered yes/no as a no.
        return match ($value) {
            true => self::MAX,
            false => self::MIN,
            null => null,
        };
    }

    /**
     * Spec 5.6: "select options carry an optional score". The panel declares
     * that score to be 0-100 already
     * (ReviewQuestionsRelationManager's choice repeater:
     * `->numeric()->minValue(0)->maxValue(100)`), so there is no scale to
     * convert from - only a range to enforce, for the same reason likert()
     * clamps.
     *
     * A choice with no score, and a key that is no longer on the question,
     * both answer null: the first is the organizer saying "this choice means
     * nothing numerically", the second is a label edited before the form
     * locked. Neither is a zero.
     */
    public static function select(ReviewQuestion $question, ?string $key): ?float
    {
        if ($key === null || $key === '') {
            return null;
        }

        $score = $question->optionScore($key);

        return $score === null ? null : self::clamp((float) $score);
    }

    public static function clamp(float $value): float
    {
        return max(self::MIN, min(self::MAX, $value));
    }
}
```

- [ ] **Step 3: `ReviewScorer`**

`app/Support/Scoring/ReviewScorer.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Scoring;

use App\Models\Review;
use App\Models\ReviewAnswer;

/**
 * Spec 5.6, second bullet: "Review score = weighted mean of scored answers."
 *
 * The weight is `review_questions.weight`, which is cast `decimal:2` and
 * therefore arrives as the string "1.00" on both drivers - every read below
 * casts it, and a reader that forgets would get PHP's string-to-number
 * coercion, which happens to be right until somebody writes a weight with a
 * thousands separator.
 *
 * A weight of zero or less REMOVES the question from the mean rather than
 * zeroing it: `minValue(0)` in the panel makes 0.00 a value an organizer can
 * set, and it plainly means "do not count this". Including it would leave the
 * mean unchanged but shrink the denominator for no reason, and a form where
 * every weight is 0 would divide by zero.
 */
class ReviewScorer
{
    /**
     * One review's weighted mean, or null when nothing on it scored.
     *
     * Reads `answers.question` through the relation rather than through the
     * form, so an answer whose question was moved to another form still scores
     * the question it actually answers. `loadMissing` rather than `load`, so a
     * caller that already eager-loaded the pair (ComputeSubmissionScore does)
     * does not pay for a second query per review.
     */
    public function forReview(Review $review): ?float
    {
        $review->loadMissing('answers.question');

        $scored = [];

        /** @var ReviewAnswer $answer */
        foreach ($review->answers as $answer) {
            $question = $answer->question;

            if ($question === null) {
                continue;
            }

            $score = AnswerNormaliser::normalise($question, $answer);

            if ($score === null) {
                continue;
            }

            $scored[] = ['score' => $score, 'weight' => (float) $question->weight];
        }

        return self::weightedMean($scored);
    }

    /**
     * The arithmetic, with no model in sight, so the boundary table in
     * tests/Unit/ReviewScorerTest.php can be exhaustive without touching a
     * database.
     *
     * @param  list<array{score: float, weight: float}>  $scored
     */
    public static function weightedMean(array $scored): ?float
    {
        $weighted = 0.0;
        $total = 0.0;

        foreach ($scored as $entry) {
            if ($entry['weight'] <= 0.0) {
                continue;
            }

            $weighted += $entry['score'] * $entry['weight'];
            $total += $entry['weight'];
        }

        // The only guard this method needs, and it covers both "no scored
        // answers" and "every weight is zero".
        if ($total <= 0.0) {
            return null;
        }

        return round($weighted / $total, 2);
    }
}
```

- [ ] **Step 4: `SubmissionScorer`**

`app/Support/Scoring/SubmissionScorer.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Scoring;

/**
 * Spec 5.6, third bullet: "Submission score = mean of submitted review scores;
 * spread = sample standard deviation; review count shown alongside."
 *
 * Two numbers that answer two different questions, kept apart on purpose:
 *
 * - `$scores` are the review scores that EXIST - the weighted means
 *   ReviewScorer produced for the submitted reviews that scored anything.
 * - `$submitted` is how many reviews were SUBMITTED, scored or not.
 *
 * They can disagree - three reviewers, two scores, because one conference's
 * form is all free text - and the ranking prints both so the disagreement is
 * visible rather than averaged away. A reviewer who filled in an all-text form
 * did the work, and "fewer than two reviews" is a question about people.
 *
 * The spread is the SAMPLE standard deviation (divides by n-1), which is what
 * the spec names and which is undefined for one observation. Null, not 0.00: a
 * zero would print perfect agreement between one person and themselves, right
 * beside a genuine zero from two reviewers who agreed, and an organizer sorting
 * by spread to find the contentious abstracts would find neither.
 */
final class SubmissionScorer
{
    /**
     * @param  list<float>  $scores  the review scores that exist, unrounded
     * @param  int  $submitted  how many reviews were submitted, scored or not
     * @return array{score: float|null, spread: float|null, review_count: int}
     */
    public static function summarise(array $scores, int $submitted): array
    {
        $count = count($scores);

        if ($count === 0) {
            return ['score' => null, 'spread' => null, 'review_count' => max(0, $submitted)];
        }

        $mean = array_sum($scores) / $count;

        return [
            'score' => round($mean, 2),
            'spread' => self::sampleStandardDeviation($scores, $mean),
            'review_count' => max(0, $submitted),
        ];
    }

    /**
     * @param  list<float>  $scores
     */
    private static function sampleStandardDeviation(array $scores, float $mean): ?float
    {
        $count = count($scores);

        // n - 1 is the sample denominator, so one observation has no spread at
        // all. This is the guard, and it is also the division-by-zero guard.
        if ($count < 2) {
            return null;
        }

        $sumOfSquares = 0.0;

        foreach ($scores as $score) {
            $sumOfSquares += ($score - $mean) ** 2;
        }

        return round(sqrt($sumOfSquares / ($count - 1)), 2);
    }
}
```

- [ ] **Step 5: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/AnswerNormaliserTest.php tests/Unit/ReviewScorerTest.php tests/Unit/SubmissionScorerTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -8 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`; about 47 passed in the first (most of them dataset rows) and `baseline + 56` in the second.

If `'three with a decimal' => [[61.5, 70.25, 80.0], 3, 70.58, 9.25]` fails by a hundredth, do **not** loosen the expectation: recompute it by hand. The mean is `211.75 / 3 = 70.5833…` → `70.58`; the deviations are `-9.0833`, `-0.3333` and `9.4167`, whose squares sum to `171.0834`, over `n - 1 = 2` is `85.5417`, whose square root is `9.2489…` → `9.25`. A result that is off by more than a rounding step means the spread is being computed with the population denominator (`n`), which would give `7.55`.

- [ ] **Step 6: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(scoring): normalisation, weighting and spread as three pure classes

Spec 5.6's arithmetic, with the four guards the sentence leaves implicit: a
value outside its own scale is clamped, a scale with no range scores nothing, a
zero weight removes a question rather than zeroing it, and the sample standard
deviation of one review is null rather than 0.00.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 3: `ComputeSubmissionScore`, the two-line hook into Plan 4, and `cass:rescore`

Task 2's arithmetic writes nothing. This task is the one class that does, the two lines that call it, and the console command for everything else.

**Three decisions this task makes.**

**1. `ComputeSubmissionScore::handle()` recomputes the whole submission, not one review.** It writes `reviews.score` for **every** review of the abstract — draft and submitted alike — and then the four columns on `submissions`. Recomputing two or three reviews costs one query for the answers and one small update each, and it makes the action *idempotent by construction*: calling it twice writes the same rows, and calling it after anything at all (a submit, a reopen, a weight fix, an import) leaves the abstract correct rather than correct-if-the-right-thing-changed. A per-review variant would be faster and would need a second code path for the aggregate, which is how the denormalised columns come to disagree with the reviews.

**2. `reviews.score` is written for a draft too, and the aggregate reads only submitted reviews.** The column is "what these answers are worth", not "what this reviewer contributed". Writing it for drafts means reopening a review is a pure status change — nothing recomputes the answers, and resubmitting unchanged answers produces the same number — and it gives the Plan 6 admin review screen something to show. The aggregate's `where status = submitted` is the only place the distinction is made.

**3. Editing a weight after the form locks is *not* allowed, and `cass:rescore` exists anyway.** Spec section 3 locks the review form the moment the first review is submitted; a weight is a column on `review_questions`, and `ReviewQuestion::booted()`'s `updating` hook throws `ReviewFormLocked` for any change to a locked form (`app/Models/ReviewQuestion.php`, the `static::updating` closure) — so a weight is locked with everything else, and this plan does **not** carve out an exception for it. A conference whose weights are wrong after the first review is a conference whose committee has to say so out loud, not one where an admin quietly re-weights an in-flight review round.

So `cass:rescore {conference}` is an **operator** command, not an organizer feature, and it is not scheduled. Its four real uses: once immediately after the release that adds the score columns, to backfill every conference that already has reviews (the four columns land empty for every abstract that already exists and nothing recomputes them on read); after the Plan 6 legacy import writes reviews and answers directly; after a platform admin corrects data by hand in the database; and after a bug in the scorer is fixed, when every affected conference has to be recomputed with the new code. It is documented in Task 10's runbook under exactly those four headings.

**Files:**
- Create: `app/Actions/Submissions/ComputeSubmissionScore.php`, `app/Console/Commands/RescoreConferenceCommand.php`
- Modify: `app/Actions/Reviews/SubmitReview.php` (one constructor argument, one line), `app/Actions/Reviews/ReopenReview.php` (one constructor argument, one line), `bootstrap/app.php`
- Modify: `tests/Pest.php` (one shared helper, `scoredReview()`)
- Test: `tests/Unit/ComputeSubmissionScoreTest.php`, `tests/Unit/RescoreCommandTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ComputeSubmissionScoreTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Reviews\ReopenReview;
use App\Actions\Reviews\SubmitReview;
use App\Actions\Submissions\ComputeSubmissionScore;
use App\Enums\ConferenceStatus;
use App\Enums\ReviewStatus;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->conference = Conference::factory()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
    ]);
    $this->form = ReviewForm::factory()->for($this->conference)->create(['is_active' => true]);
    $this->question = ReviewQuestion::factory()->for($this->form)->create([
        'scale_min' => 1, 'scale_max' => 5, 'weight' => '1.00',
    ]);
    $this->submission = Submission::factory()->for($this->conference)->submitted()->create();
});

// `scoredReview()` is NOT declared here. It lives in tests/Pest.php (below),
// because tests/Unit/RescoreCommandTest.php calls it too and a helper declared
// in one test file is a fatal "Call to undefined function" the moment somebody
// runs or --filters the other file on its own.

it('writes the review score and the four submission columns', function () {
    scoredReview($this->submission, $this->form, $this->question, 5);
    scoredReview($this->submission, $this->form, $this->question, 3);

    app(ComputeSubmissionScore::class)->handle($this->submission);

    $fresh = $this->submission->fresh();

    // 100 and 50 -> mean 75, sample SD sqrt(((25)^2 + (25)^2)/1) = 35.36
    expect($fresh?->score)->toBe('75.00')
        ->and($fresh?->score_spread)->toBe('35.36')
        ->and($fresh?->review_count)->toBe(2)
        ->and($fresh?->scored_at)->not->toBeNull()
        ->and(Review::query()->pluck('score')->sort()->values()->all())->toBe(['50.00', '100.00']);
});

it('scores a draft review but leaves it out of the aggregate', function () {
    scoredReview($this->submission, $this->form, $this->question, 5);
    $draft = scoredReview($this->submission, $this->form, $this->question, 1, ReviewStatus::Draft);

    app(ComputeSubmissionScore::class)->handle($this->submission);

    $fresh = $this->submission->fresh();

    // The draft's own score is written - it is what those answers are worth -
    // but it is not one of the reviews the committee has been given.
    expect($draft->fresh()?->score)->toBe('0.00')
        ->and($fresh?->score)->toBe('100.00')
        ->and($fresh?->review_count)->toBe(1);
});

it('counts a submitted review that scored nothing, without averaging it in', function () {
    $textOnly = ReviewQuestion::factory()->for($this->form)->freeText()->create();

    scoredReview($this->submission, $this->form, $this->question, 4);

    // A second reviewer who answered only the free-text question.
    $reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $reviewer->id]);
    $review = Review::factory()->create([
        'submission_id' => $this->submission->getKey(),
        'reviewer_user_id' => $reviewer->getKey(),
        'review_form_id' => $this->form->getKey(),
        'status' => ReviewStatus::Submitted,
        'submitted_at' => now(),
    ]);
    $answer = new ReviewAnswer;
    $answer->forceFill([
        'review_id' => $review->getKey(),
        'review_question_id' => $textOnly->getKey(),
        'value_int' => null, 'value_text' => 'Good, but no numbers from me', 'value_bool' => null, 'choice_key' => null,
    ])->save();

    app(ComputeSubmissionScore::class)->handle($this->submission);

    $fresh = $this->submission->fresh();

    // Two people reviewed it; one score exists. Both numbers are true and the
    // ranking prints both.
    expect($fresh?->review_count)->toBe(2)
        ->and($fresh?->score)->toBe('75.00')
        ->and($fresh?->score_spread)->toBeNull()
        ->and($review->fresh()?->score)->toBeNull();
});

it('clears the columns of an abstract whose reviews have gone', function () {
    scoredReview($this->submission, $this->form, $this->question, 5);
    app(ComputeSubmissionScore::class)->handle($this->submission);
    expect($this->submission->fresh()?->score)->toBe('100.00');

    Review::query()->delete();
    app(ComputeSubmissionScore::class)->handle($this->submission);

    // Back to "nobody has reviewed this", not "everybody scored it zero".
    $fresh = $this->submission->fresh();
    expect($fresh?->score)->toBeNull()
        ->and($fresh?->score_spread)->toBeNull()
        ->and($fresh?->review_count)->toBe(0)
        // scored_at still moves: the answer "no reviews" was computed just now,
        // and an organizer asking "is this up to date" needs the timestamp to
        // mean "last computed", not "last non-null".
        ->and($fresh?->scored_at)->not->toBeNull();
});

it('is idempotent', function () {
    scoredReview($this->submission, $this->form, $this->question, 4);
    scoredReview($this->submission, $this->form, $this->question, 2);

    app(ComputeSubmissionScore::class)->handle($this->submission);
    $first = $this->submission->fresh();

    app(ComputeSubmissionScore::class)->handle($this->submission);
    app(ComputeSubmissionScore::class)->handle($this->submission);
    $third = $this->submission->fresh();

    expect($third?->score)->toBe($first?->score)
        ->and($third?->score_spread)->toBe($first?->score_spread)
        ->and($third?->review_count)->toBe($first?->review_count);
});

// --- The hook into Plan 4 -----------------------------------------------

it('is fired by submitting a review', function () {
    // The whole point of the one-line hook: nothing else in the application
    // calls ComputeSubmissionScore on the happy path, so if this line is ever
    // dropped from SubmitReview the ranking silently shows stale zeros.
    $reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $reviewer->id]);

    expect($this->submission->fresh()?->score)->toBeNull();

    app(SubmitReview::class)->handle($this->submission, $reviewer, [$this->question->ulid => 4]);

    expect($this->submission->fresh()?->score)->toBe('75.00')
        ->and($this->submission->fresh()?->review_count)->toBe(1);
});

it('is fired by reopening a review', function () {
    $reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $reviewer->id]);

    $review = app(SubmitReview::class)->handle($this->submission, $reviewer, [$this->question->ulid => 4]);
    expect($this->submission->fresh()?->review_count)->toBe(1);

    app(ReopenReview::class)->handle($review, $reviewer);

    // A reopened review is a review the committee no longer has, so the
    // aggregate drops it - and the answers keep their own score.
    expect($this->submission->fresh()?->review_count)->toBe(0)
        ->and($this->submission->fresh()?->score)->toBeNull()
        ->and($review->fresh()?->score)->toBe('75.00');
});
```

`tests/Unit/RescoreCommandTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\ReviewStatus;
use App\Models\Conference;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->conference = Conference::factory()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
    ]);
    $this->form = ReviewForm::factory()->for($this->conference)->create(['is_active' => true]);
    $this->question = ReviewQuestion::factory()->for($this->form)->create([
        'scale_min' => 1, 'scale_max' => 5, 'weight' => '1.00',
    ]);
});

it('rescores every abstract of one conference by its ulid', function () {
    $first = Submission::factory()->for($this->conference)->submitted()->create();
    $second = Submission::factory()->for($this->conference)->submitted()->create();
    scoredReview($first, $this->form, $this->question, 5);
    scoredReview($second, $this->form, $this->question, 1);

    // Nothing has computed anything yet: the rows were written directly, which
    // is exactly the state the Plan 6 legacy import leaves behind.
    expect($first->fresh()?->score)->toBeNull();

    $this->artisan('cass:rescore', ['conference' => $this->conference->ulid])
        ->expectsOutputToContain('2')
        ->assertSuccessful();

    expect($first->fresh()?->score)->toBe('100.00')
        ->and($second->fresh()?->score)->toBe('0.00');
});

it('accepts the numeric id as well, and refuses anything else', function () {
    Submission::factory()->for($this->conference)->submitted()->create();

    $this->artisan('cass:rescore', ['conference' => (string) $this->conference->id])->assertSuccessful();

    // A failed lookup is exit code 1 and a sentence, not an exception trace in
    // an operator's terminal.
    $this->artisan('cass:rescore', ['conference' => 'not-a-conference'])
        ->expectsOutputToContain('No conference')
        ->assertFailed();
});

it('leaves another conference alone', function () {
    $mine = Submission::factory()->for($this->conference)->submitted()->create();
    scoredReview($mine, $this->form, $this->question, 5);

    $other = Conference::factory()->create(['status' => ConferenceStatus::Reviewing]);
    $otherForm = ReviewForm::factory()->for($other)->create(['is_active' => true]);
    $otherQuestion = ReviewQuestion::factory()->for($otherForm)->create(['scale_min' => 1, 'scale_max' => 5]);
    $theirs = Submission::factory()->for($other)->submitted()->create();
    scoredReview($theirs, $otherForm, $otherQuestion, 5);

    $this->artisan('cass:rescore', ['conference' => $this->conference->ulid])->assertSuccessful();

    expect($mine->fresh()?->score)->toBe('100.00')
        ->and($theirs->fresh()?->score)->toBeNull();
});

it('rescores a draft and a withdrawn abstract too', function () {
    // The command is a repair tool, not a report: it recomputes every row of
    // the conference, so an abstract that is withdrawn today and reinstated by
    // hand tomorrow does not carry a stale number.
    $draft = Submission::factory()->for($this->conference)->create();
    $withdrawn = Submission::factory()->for($this->conference)->withdrawn()->create();
    scoredReview($draft, $this->form, $this->question, 5, ReviewStatus::Submitted);
    scoredReview($withdrawn, $this->form, $this->question, 3, ReviewStatus::Submitted);

    $this->artisan('cass:rescore', ['conference' => $this->conference->ulid])->assertSuccessful();

    expect($draft->fresh()?->score)->toBe('100.00')
        ->and($withdrawn->fresh()?->score)->toBe('50.00');
});
```

**`scoredReview()` goes in `tests/Pest.php`, not in either test file.** Both files above call it, and a helper declared inside one of them is a fatal `Call to undefined function scoredReview()` the moment anybody runs the other file alone or uses `--filter` — which is the normal way a red test is chased. (There is no precedent for sharing one across files in this repo: Plan 4's `readyToReview()` is declared in `tests/Unit/StartReviewingTest.php` and every call to it is in that same file.) Append to `tests/Pest.php`, beside `bootOrganizerPanel()` and `withoutTenant()`:

```php
/**
 * One review of $submission, with one answer to $question, without going near
 * the reviewer panel. Here rather than in a test file because
 * tests/Unit/ComputeSubmissionScoreTest.php and
 * tests/Unit/RescoreCommandTest.php both call it, and a helper declared in one
 * of them makes a single-file run of the other a fatal error.
 */
function scoredReview(
    App\Models\Submission $submission,
    App\Models\ReviewForm $form,
    App\Models\ReviewQuestion $question,
    int $value,
    App\Enums\ReviewStatus $status = App\Enums\ReviewStatus::Submitted,
): App\Models\Review {
    $reviewer = App\Models\User::factory()->create();
    App\Models\ConferenceReviewer::factory()->for($submission->conference)->create(['user_id' => $reviewer->id]);

    $review = App\Models\Review::factory()->create([
        'submission_id' => $submission->getKey(),
        'reviewer_user_id' => $reviewer->getKey(),
        'review_form_id' => $form->getKey(),
        'status' => $status,
        'submitted_at' => $status === App\Enums\ReviewStatus::Submitted ? now() : null,
    ]);

    $answer = new App\Models\ReviewAnswer;
    $answer->forceFill([
        'review_id' => $review->getKey(),
        'review_question_id' => $question->getKey(),
        'value_int' => $value, 'value_text' => null, 'value_bool' => null, 'choice_key' => null,
    ])->save();

    return $review;
}
```

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ComputeSubmissionScoreTest.php tests/Unit/RescoreCommandTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -20 /tmp/t.log && \
php artisan test tests/Unit/RescoreCommandTest.php > /tmp/one.log 2>&1; echo "single-file rc=$?"; tail -5 /tmp/one.log
```

Expected: `rc=1`, naming `App\Actions\Submissions\ComputeSubmissionScore`. The single-file run must fail for the **same** reason — if it says `Call to undefined function scoredReview()`, the helper went into a test file instead of `tests/Pest.php`.

- [ ] **Step 2: `ComputeSubmissionScore`**

`app/Actions/Submissions/ComputeSubmissionScore.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\ReviewStatus;
use App\Models\Review;
use App\Models\Submission;
use App\Support\Scoring\ReviewScorer;
use App\Support\Scoring\SubmissionScorer;
use Illuminate\Support\Facades\DB;

/**
 * **The** writer of `reviews.score` and of the four denormalised columns on
 * `submissions` (spec 5.6, spec section 10's ranking budget).
 *
 * Exactly three things call it: Plan 4's SubmitReview, Plan 4's ReopenReview,
 * and `cass:rescore`. Nothing else may write those columns - a second writer is
 * how a ranking comes to disagree with the reviews behind it.
 *
 * It recomputes the WHOLE submission every time rather than patching one
 * review. Two or three reviews is one query for the answers and one small
 * update each, and the result is idempotent by construction: calling it twice
 * writes the same rows, and calling it after anything at all leaves the
 * abstract correct rather than correct-if-the-right-thing-changed.
 *
 * `reviews.score` is written for a draft as well as for a submitted review - it
 * is what those answers are worth - and only the submitted ones reach the
 * aggregate. That is what makes reopening a review a pure status change.
 */
class ComputeSubmissionScore
{
    public function __construct(private readonly ReviewScorer $reviewScorer) {}

    public function handle(Submission $submission): Submission
    {
        // One query for the reviews, one for their answers, one for the
        // questions those answers point at. Everything below is in memory.
        $reviews = $submission->reviews()->with('answers.question')->get();

        /** @var list<float> $submittedScores */
        $submittedScores = [];
        $submitted = 0;

        DB::transaction(function () use ($reviews, &$submittedScores, &$submitted, $submission): void {
            /** @var Review $review */
            foreach ($reviews as $review) {
                $score = $this->reviewScorer->forReview($review);

                // ->toBase() before ->update(), not forceFill()->save() and not
                // a bare Eloquent update. The model has LogsActivity and
                // nothing about a recomputed number belongs in the audit trail;
                // and `updated_at` on a review means "the reviewer touched it",
                // which an Eloquent Builder::update() would rewrite on every
                // recompute because it calls addUpdatedAtColumn()
                // (vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:1303-1306).
                // A whole-conference cass:rescore would then reset that
                // timestamp on every review in the conference. The base query
                // keeps the model's global scopes and skips both the timestamp
                // and the model events, which is exactly what is wanted here.
                Review::query()->whereKey($review->getKey())->toBase()->update(['score' => $score]);

                if ($review->status !== ReviewStatus::Submitted) {
                    continue;
                }

                $submitted++;

                if ($score !== null) {
                    // The unrounded-once value the scorer just produced, not the
                    // decimal:2 string the column would hand back: rounding a
                    // mean of already-rounded numbers is a second rounding, and
                    // a test written locally would drift from the MySQL run.
                    $submittedScores[] = $score;
                }
            }

            $summary = SubmissionScorer::summarise($submittedScores, $submitted);

            $submission->forceFill([
                'score' => $summary['score'],
                'score_spread' => $summary['spread'],
                'review_count' => $summary['review_count'],
                // Always moves, even when the answer is "no reviews": an
                // organizer asking "is this up to date" needs the timestamp to
                // mean "last computed", not "last non-null".
                'scored_at' => now(),
            ])->save();
        });

        return $submission->refresh();
    }

    /**
     * Every abstract of one conference, in chunks, for `cass:rescore` and for
     * the Plan 6 legacy import.
     *
     * Deliberately every row, not only the reviewable ones: the command is a
     * repair tool, and a withdrawn abstract that is reinstated by hand must not
     * carry a number computed under different weights.
     *
     * @return int how many submissions were recomputed
     */
    public function forConference(\App\Models\Conference $conference): int
    {
        $count = 0;

        $conference->submissions()
            ->select('submissions.*')
            ->chunkById(100, function (\Illuminate\Database\Eloquent\Collection $submissions) use (&$count): void {
                /** @var Submission $submission */
                foreach ($submissions as $submission) {
                    $this->handle($submission);
                    $count++;
                }
            });

        return $count;
    }
}
```

Pint's `ordered_imports` will want those two inline FQCNs as `use` statements; write them as imports from the start:

```php
use App\Models\Conference;
use Illuminate\Database\Eloquent\Collection;
```

and the two signatures become `forConference(Conference $conference): int` and `function (Collection $submissions) use (&$count): void`.

- [ ] **Step 3: The two-line hook into Plan 4**

**Read both files on `main` first.** Task 1 Step 1 printed their constructors; the edits below are additive and must not disturb anything else.

`app/Actions/Reviews/SubmitReview.php` — the constructor becomes:

```php
    public function __construct(
        private readonly SaveReviewDraft $saveDraft,
        private readonly ComputeSubmissionScore $computeScore,
    ) {}
```

and **one line** is added in `handle()`, immediately after the `DB::transaction(...)` call returns and before the `activity()` call:

```php
        // Spec 5.6. The one place a submitted review turns into a number on the
        // abstract. Outside the transaction on purpose: the review is committed
        // by now, so a failure here leaves a correct review with a stale score
        // (which `cass:rescore` repairs) rather than rolling back a reviewer's
        // work over an arithmetic error.
        $this->computeScore->handle($submission);
```

with `use App\Actions\Submissions\ComputeSubmissionScore;` added to the imports.

`app/Actions/Reviews/ReopenReview.php` — the class gains a constructor (Plan 4's version has none):

```php
    public function __construct(private readonly ComputeSubmissionScore $computeScore) {}
```

and **one line** is added in `handle()`, after the `forceFill([...])->save()` and before the `activity()` call:

```php
        // A reopened review is a review the committee no longer has, so the
        // abstract's mean, spread and count all change. Same one-line hook as
        // SubmitReview, and the same reason it is not inside a transaction.
        $this->computeScore->handle($review->submission);
```

with `use App\Actions\Submissions\ComputeSubmissionScore;` added to the imports.

**`$review->submission` is not nullable here**: `reviews.submission_id` is a non-nullable foreign key with `restrictOnDelete`, and `ReopenReview::blockers()` has already refused when `$review->submission?->conference` is null. If Larastan disagrees after Plan 4's implementation, use the shape `blockers()` already uses in the same file rather than inventing a second guard.

Confirm both edits landed, and that they are the only two calls:

```bash
cd /c/Users/ahmed/Documents/CASS && grep -rn "computeScore->handle\|ComputeSubmissionScore" app/ | sort
```

Expected at this point: **nine** lines — the class declaration; one `use`, one constructor property and one call in each of `SubmitReview` and `ReopenReview`; and the two docblocks that name the class (`app/Models/Review.php`'s `score` cast comment from Task 1 and `ReviewScorer::forReview()`'s `loadMissing` note from Task 2). Step 4 adds two more — the command's `use` and its `handle()` parameter type — for eleven.

The number that actually matters is the *call* count, so confirm it on its own:

```bash
cd /c/Users/ahmed/Documents/CASS && grep -rn "computeScore->handle" app/ | wc -l
```

Expected: `2`. A third caller means something outside `SubmitReview` and `ReopenReview` is writing the denormalised score columns, which is the one rule this task exists to hold.

- [ ] **Step 4: The console command and its registration**

`app/Console/Commands/RescoreConferenceCommand.php`
```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Submissions\ComputeSubmissionScore;
use App\Models\Conference;
use Illuminate\Console\Command;

/**
 * Recompute every review score and every denormalised submission score for one
 * conference.
 *
 * **Not an organizer feature and not scheduled.** Spec section 3 locks the
 * review form the moment the first review is submitted, and a question's weight
 * is locked with it (ReviewQuestion::booted()'s `updating` hook throws
 * ReviewFormLocked), so nothing an organizer can do makes a stored score wrong.
 * This command exists for the four cases where the stored numbers are not what
 * the application itself would have written:
 *
 *   1. once immediately after the release that adds the score columns, which
 *      land empty for every abstract that already exists and are never
 *      recomputed on read — the one-time backfill in the runbook;
 *   2. after the Plan 6 legacy import, which inserts reviews and answers
 *      directly and has no SubmitReview to hook;
 *   3. after a platform admin corrects data by hand;
 *   4. after a bug in App\Support\Scoring is fixed, when every affected
 *      conference has to be recomputed with the new code.
 *
 * A nightly schedule was deliberately NOT added: a rescore rewrites numbers an
 * organizer may be looking at, and the four cases above are all deliberate
 * human acts.
 */
class RescoreConferenceCommand extends Command
{
    /**
     * The ULID is the public identifier (spec section 3) and is what an
     * organizer can read off a panel URL; the numeric id is accepted because a
     * platform admin working in the database has that and not the ULID.
     */
    protected $signature = 'cass:rescore {conference : The conference ULID or numeric id}';

    protected $description = 'Recompute review and submission scores for one conference';

    public function handle(ComputeSubmissionScore $compute): int
    {
        $key = (string) $this->argument('conference');

        $conference = Conference::query()
            ->where('ulid', $key)
            ->when(ctype_digit($key), fn ($query) => $query->orWhere('id', (int) $key))
            ->first();

        if (! $conference instanceof Conference) {
            // A sentence and exit 1, not a ModelNotFoundException trace in an
            // operator's terminal at two in the morning.
            $this->components->error("No conference found for [{$key}]. Pass the ULID from the panel URL, or the numeric id.");

            return self::FAILURE;
        }

        $this->components->info("Rescoring [{$conference->name}]...");

        $count = $compute->forConference($conference);

        $this->components->info("Rescored {$count} abstract(s).");

        return self::SUCCESS;
    }
}
```

The `when(...)` closure's `$query` parameter is untyped, which Larastan level 6 rejects. Write it typed:

```php
            ->when(ctype_digit($key), fn (Builder $query): Builder => $query->orWhere('id', (int) $key))
```

with `use Illuminate\Database\Eloquent\Builder;` added, and the method's docblock carrying `@param Builder<Conference> $query` is unnecessary because the closure is inline — but if Larastan still complains about the generic, annotate the whole expression:

```php
        /** @var Conference|null $conference */
        $conference = Conference::query()
            ->where('ulid', $key)
            ->when(ctype_digit($key), fn (Builder $query): Builder => $query->orWhere('id', (int) $key))
            ->first();
```

`bootstrap/app.php` — **append** to the `->withCommands([...])` array Plan 4 Task 10 added:

```php
    ->withCommands([
        \App\Console\Commands\SendReviewerRemindersCommand::class,
        \App\Console\Commands\RescoreConferenceCommand::class,
    ])
```

(imported at the top of the file, not inline, so Pint's `ordered_imports` is satisfied). **If Plan 4's implementation registered its command differently, follow the code**: a class under `app/Console/Commands` is not discovered by itself (`bootstrap/app.php` has no `$commandPaths`), so the only requirement is that `php artisan list | grep cass:rescore` finds it.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan list 2>/dev/null | grep -E "cass:"
```

Expected: both `cass:rescore` and Plan 4's `cass:reviewer-reminders`.

- [ ] **Step 5: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ComputeSubmissionScoreTest.php tests/Unit/RescoreCommandTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -8 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`; about 11 passed in the first and `baseline + 67` in the second.

**Watch the second number.** `tests/Unit/SubmitReviewTest.php` and every Plan 4 test that calls `SubmitReview` now also runs `ComputeSubmissionScore`, so a failure there is a real regression and not a count drift — most likely an eager-load assumption or a query-count assertion Plan 4 wrote. If a Plan 4 query-count test goes red, the honest fix is to update that test's number with a comment naming this hook, not to move the hook.

- [ ] **Step 6: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(scoring): one writer of the denormalised scores, and two lines that call it

ComputeSubmissionScore recomputes a whole abstract rather than patching one
review, so it is idempotent by construction. SubmitReview and ReopenReview each
gain one line; cass:rescore is the operator's repair tool for the rows nothing
in the application wrote.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 4: The ranking page — table, sorting, filters, search, summary strip and the 500-row budget

Spec 5.6, fourth bullet: "Ranking table: sortable by score, spread, count, track; filters by status and track; export CSV and XLSX." Plus spec section 10's "ranking table for 500 submissions in under 1 s". The exports are Task 5; the decision actions are Task 6; everything else is here.

**A page on `ConferenceResource`, not a second resource. Why.**

The obvious alternative is `App\Filament\Organizer\Resources\Rankings\RankingResource` over `Submission`. It is the wrong shape for four reasons, each concrete:

1. **The ranking is per conference, and every number on it is.** "Fewer than N reviews" means "fewer than *this conference's* `reviewers_per_submission`". The summary strip's denominator is this conference's abstracts. `MarkDecided`'s blockers are about this conference. A panel-wide resource would have to make the conference a filter that is mandatory, which is a page with a record in the URL wearing a resource's clothes.
2. **`SubmissionResource` already *is* the panel-wide list.** A second navigation item over the same model, with a different set of columns, is two answers to "where do I look at abstracts". Plan 3 already links from the conference view into that list with a pre-set filter (`SubmissionResource::urlForConference()`).
3. **A second resource over `Submission` needs a second copy of the tenancy override.** `SubmissionResource` carries `$isScopedToTenant = false` plus a hand-written `whereHas('conference')`, and a 50-line comment explaining that `Submission`'s `organization()` is a `HasOneThrough` which Filament's `created` observer would fatal on. Copying that is copying the one piece of this codebase most likely to be got wrong. A resource *page* inherits `ConferenceResource`'s real tenancy — `resolveRecord()` runs through `ConferenceResource::getEloquentQuery()`, which carries the panel's tenancy global scope — and then scopes the table by `conference_id`, which needs no override at all.
4. **The page pattern is proved.** `ConferenceEmailTemplates` and `ConferenceShortLink` are both `Filament\Resources\Pages\Page` with `InteractsWithRecord`, both registered in `ConferenceResource::getPages()`, both reached from `ConferenceStatusActions`, and the first already hosts a table with `InteractsWithTable`. The only new thing here is `Table::query()` instead of `Table::records()` — and that is the thing that makes sorting, filtering, pagination and bulk selection all SQL (fact 5).

**How the 1-second budget is actually met, and how it is actually tested.**

The table's query is `select … from submissions where conference_id = ? and status in (…) order by score desc limit …`, with one eager load for `track`. It touches `reviews` **zero** times, because the four numbers it prints are columns. That is the property, and the property is what the test asserts:

- `it('renders five hundred abstracts without touching the reviews table')` is the **gate**. It logs every query the render issues and asserts that none of them names `reviews` or `review_answers`, and that the total count is under a small ceiling. It is deterministic, it runs everywhere, and it fails the moment somebody adds `->withAvg('reviews', 'score')` to make one column prettier.
- `it('renders five hundred abstracts inside the one-second budget of spec section 10')` measures wall clock and is **skipped on CI**. A shared GitHub runner's wall clock measures the runner, not the code: the same tree can take 0.4 s and 2.1 s an hour apart, and a budget assertion that goes red for that reason gets deleted within a month, taking the real one with it. Locally, on the machine this plan was written on, it is a real signal. `->skip(fn (): bool => (bool) env('CI'), '…')` with the reason written out, so nobody has to guess why.

Both are in their own file, `tests/Feature/Organizer/ConferenceRankingPerformanceTest.php`, because both insert 500 rows and neither belongs in the file a person reads to learn what the page does.

**Files:**
- Create: `app/Support/Scoring/RankedSubmissions.php`
- Create: `app/Filament/Organizer/Resources/Conferences/Pages/ConferenceRanking.php`
- Create: `resources/views/filament/organizer/pages/conference-ranking.blade.php`
- Modify: `app/Models/Conference.php` (`rankingSummary()`), `app/Filament/Organizer/Resources/Conferences/ConferenceResource.php` (one page), `app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php` (`ranking()`)
- Modify: `lang/en/decisions.php` (created in Task 1; this task adds the `ranking` and `summary` groups, which Tasks 6-9 extend and Task 10 sweeps)
- Test: `tests/Feature/Organizer/ConferenceRankingTest.php`, `tests/Feature/Organizer/ConferenceRankingPerformanceTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Organizer/ConferenceRankingTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceRanking;
use App\Filament\Organizer\Resources\Conferences\Pages\ViewConference;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Support\Scoring\RankedSubmissions;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->closed()->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
        'reviewers_per_submission' => 2,
        'review_deadline' => now()->addMonth(),
    ]);

    $this->track = Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);

    $this->best = Submission::factory()->for($this->conference)->scored(91.0, 2.0, 3)
        ->create(['title' => 'Early mobilisation after cardiac surgery', 'track_id' => $this->track->id]);
    $this->best->forceFill(['reference' => 'AAM26-001'])->save();

    $this->middle = Submission::factory()->for($this->conference)->scored(55.5, 20.0, 2)
        ->create(['title' => 'A middling abstract']);
    $this->middle->forceFill(['reference' => 'AAM26-002'])->save();

    $this->unreviewed = Submission::factory()->for($this->conference)->submitted()
        ->create(['title' => 'Nobody has read this yet']);
    $this->unreviewed->forceFill(['reference' => 'AAM26-003'])->save();
});

it('lists the abstracts under consideration, best first', function () {
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->assertCanSeeTableRecords([$this->best, $this->middle, $this->unreviewed], inOrder: true)
        ->assertCanRenderTableColumn('reference')
        ->assertCanRenderTableColumn('title')
        ->assertCanRenderTableColumn('score')
        ->assertCanRenderTableColumn('score_spread')
        ->assertCanRenderTableColumn('review_count')
        ->assertCanRenderTableColumn('decision')
        ->assertSee('AAM26-001')
        ->assertSee('Neurocritical care');
});

it('leaves out drafts and withdrawals', function () {
    $draft = Submission::factory()->for($this->conference)->create(['title' => 'Never submitted']);
    $withdrawn = Submission::factory()->for($this->conference)->withdrawn()->create(['title' => 'Taken back']);

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->assertCanNotSeeTableRecords([$draft, $withdrawn])
        ->assertCountTableRecords(3);

    // The status filter derives its options from this same list, so the two
    // cannot drift into offering an option that can only ever render an empty
    // table (Step 4). Asserted at the source, where the one definition lives.
    expect(RankedSubmissions::statuses())->not->toContain(SubmissionStatus::Draft->value)
        ->and(RankedSubmissions::statuses())->not->toContain(SubmissionStatus::Withdrawn->value)
        ->and(RankedSubmissions::statuses())->toContain(SubmissionStatus::Submitted->value);
});

it('keeps another conference out, even inside the same organization', function () {
    $other = Conference::factory()->for($this->organization)->closed()->create(['name' => 'Alpha Winter School']);
    $theirs = Submission::factory()->for($other)->scored(99.0)->create(['title' => 'Winter abstract']);

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('sorts by score, spread, review count and track', function () {
    $second = Track::factory()->for($this->conference)->create(['name' => 'Respiratory']);
    $this->middle->forceFill(['track_id' => $second->id])->save();

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->sortTable('score', 'asc')
        // Ascending puts the unscored abstract first on both drivers: NULL is
        // the smallest value in SQLite and sorts first ascending in MySQL.
        // Task 11 repeats this assertion against MySQL.
        ->assertCanSeeTableRecords([$this->unreviewed, $this->middle, $this->best], inOrder: true)
        ->sortTable('score_spread', 'desc')
        ->assertCanSeeTableRecords([$this->middle, $this->best], inOrder: true)
        ->sortTable('review_count', 'desc')
        ->assertCanSeeTableRecords([$this->best, $this->middle], inOrder: true)
        // Spec 5.6 names track among the sortable columns, and it is the only
        // one of the four Filament answers with a relationship subquery
        // (InteractsWithTableQuery::applySort -> RelationshipOrderer::buildSubquery,
        // vendor/filament/tables/src/Columns/Concerns/InteractsWithTableQuery.php:147-155)
        // rather than an ORDER BY on a column of `submissions` - so a dropped
        // ->sortable() here is the one sort the other three cannot catch.
        // assertCanSeeTableRecords(inOrder:) asserts relative order only, so the
        // track-less third row does not enter this assertion.
        ->sortTable('track.name', 'asc')
        ->assertCanSeeTableRecords([$this->best, $this->middle], inOrder: true);
});

it('filters by status, track, decision and by how few reviews there are', function () {
    $this->middle->forceFill([
        'decision' => Decision::Waitlisted,
        'status' => SubmissionStatus::Waitlisted,
    ])->save();

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->filterTable('status', SubmissionStatus::Waitlisted->value)
        ->assertCanSeeTableRecords([$this->middle])
        ->assertCanNotSeeTableRecords([$this->best]);

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->filterTable('track_id', $this->track->id)
        ->assertCanSeeTableRecords([$this->best])
        ->assertCanNotSeeTableRecords([$this->middle]);

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->filterTable('decision', Decision::Waitlisted->value)
        ->assertCanSeeTableRecords([$this->middle])
        ->assertCanNotSeeTableRecords([$this->best, $this->unreviewed]);

    // The one filter an organizer actually chases stragglers with.
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->filterTable('decision', 'none')
        ->assertCanSeeTableRecords([$this->best, $this->unreviewed])
        ->assertCanNotSeeTableRecords([$this->middle]);

    // A Filter with its own schema: filterTable() passes an array through
    // untouched (vendor/filament/tables/src/Testing/TestsFilters.php:48-52).
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->filterTable('under_reviewed', ['count' => 2])
        ->assertCanSeeTableRecords([$this->unreviewed])
        ->assertCanNotSeeTableRecords([$this->best, $this->middle]);
});

it('searches by reference and by title', function () {
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->searchTable('AAM26-002')
        ->assertCanSeeTableRecords([$this->middle])
        ->assertCanNotSeeTableRecords([$this->best]);

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->searchTable('mobilisation')
        ->assertCanSeeTableRecords([$this->best])
        ->assertCanNotSeeTableRecords([$this->middle]);
});

it('prints a summary strip of what the committee is looking at', function () {
    $this->middle->forceFill(['decision' => Decision::Waitlisted, 'status' => SubmissionStatus::Waitlisted])->save();
    $this->best->forceFill(['decision' => Decision::AcceptedOral, 'status' => SubmissionStatus::Accepted])->save();

    $summary = $this->conference->fresh()?->rankingSummary();

    expect($summary['total'])->toBe(3)
        ->and($summary['reviewed'])->toBe(2)
        ->and($summary['unreviewed'])->toBe(1)
        ->and($summary['decided'])->toBe(2)
        ->and($summary['undecided'])->toBe(1)
        // The mean of the abstracts that have one, not of all three.
        ->and($summary['mean_score'])->toBe(73.25)
        ->and($summary['by_decision'][Decision::AcceptedOral->value])->toBe(1)
        ->and($summary['by_decision'][Decision::AcceptedPoster->value])->toBe(0)
        ->and($summary['by_decision'][Decision::Waitlisted->value])->toBe(1)
        ->and($summary['by_decision'][Decision::Rejected->value])->toBe(0);

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->assertSee(__('decisions.summary.reviewed'))
        ->assertSee(__('decisions.summary.mean'))
        ->assertSee('73.25');
});

it('links each row to the submission view and back to the conference', function () {
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->assertSee(\App\Filament\Organizer\Resources\Submissions\SubmissionResource::getUrl('view', ['record' => $this->best]))
        ->assertActionExists('backToConference');
});

it('is reachable from the conference view once reviewing has started', function () {
    livewire(ViewConference::class, ['record' => $this->conference->getRouteKey()])
        ->assertActionVisible('ranking');

    $draft = Conference::factory()->for($this->organization)->create();

    // Before reviewing there is nothing to rank, and an empty ranking table on
    // a conference still collecting abstracts is a screen that answers a
    // question nobody asked.
    livewire(ViewConference::class, ['record' => $draft->getRouteKey()])
        ->assertActionHidden('ranking');
});

// --- Cross-tenant -------------------------------------------------------

it('404s the ranking of another organization conference', function () {
    $theirs = withoutTenant(fn (): Conference => Conference::factory()->create([
        'status' => ConferenceStatus::Reviewing,
    ]));

    // Through the route, not livewire(). Filament's InteractsWithRecord
    // ::resolveRecord() throws ModelNotFoundException when the tenant-scoped
    // query excludes the record (vendor/filament/filament/src/Resources/Pages/
    // Concerns/InteractsWithRecord.php:39-43), and Livewire's test harness
    // rethrows everything except HttpException and AuthorizationException
    // (vendor/livewire/livewire/src/Features/SupportTesting/RequestBroker.php:29),
    // so livewire(...)->assertNotFound() would ERROR rather than assert.
    get(ConferenceResource::getUrl('ranking', ['record' => $theirs]))->assertNotFound();
});

it('refuses the ranking to somebody with no role in this organization', function () {
    $outsider = User::factory()->create();
    $otherOrganization = Organization::factory()->approved()->create();
    $otherOrganization->addMember($outsider, OrganizationRole::Owner);

    actingAs($outsider);
    bootOrganizerPanel($otherOrganization);

    get(ConferenceResource::getUrl('ranking', ['record' => $this->conference], tenant: $this->organization))
        ->assertNotFound();
});
```

`tests/Feature/Organizer/ConferenceRankingPerformanceTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceRanking;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tokens\SubmissionToken;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/**
 * Spec section 10: "ranking table for 500 submissions in under 1 s."
 *
 * Two tests, doing two different jobs. The query-count one is the gate - it is
 * deterministic and it fails the moment somebody adds a per-row aggregate. The
 * wall-clock one is the budget itself and is skipped on CI, because a shared
 * runner's clock measures the runner.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->closed()->create([
        'status' => ConferenceStatus::Reviewing,
        'reviewers_per_submission' => 2,
        'review_deadline' => now()->addMonth(),
    ]);

    // A raw insert, not 500 factory calls: the factory would take longer than
    // the thing being measured and would make the test a measurement of
    // Faker. Every column the ranking reads is set here.
    $now = now()->toDateTimeString();
    $rows = [];

    for ($i = 1; $i <= 500; $i++) {
        $rows[] = [
            'ulid' => (string) Illuminate\Support\Str::ulid(),
            'conference_id' => $this->conference->id,
            'track_id' => null,
            'reference' => sprintf('PERF26-%03d', $i),
            'status' => SubmissionStatus::UnderReview->value,
            'title' => "Abstract number {$i}",
            'abstract' => 'A body of text that the ranking never reads.',
            'word_count' => 9,
            'presentation_preference' => 'oral',
            'contact_phone' => null,
            'custom_field_values' => null,
            'access_token_hash' => SubmissionToken::hash(SubmissionToken::generate()),
            'score' => 100 - ($i % 100),
            'score_spread' => $i % 17,
            'review_count' => 2,
            'scored_at' => $now,
            'decision' => null,
            'decision_notified_at' => null,
            'submitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('submissions')->insert($chunk);
    }
});

it('renders five hundred abstracts without touching the reviews table', function () {
    /** @var list<string> $queries */
    $queries = [];

    DB::listen(function (Illuminate\Database\Events\QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->set('tableRecordsPerPage', 'all')
        ->assertCountTableRecords(500);

    // THE assertion. Every number on this table is a column on `submissions`;
    // the moment one of them becomes `withAvg('reviews', 'score')` or a
    // per-row `$record->reviews()->count()`, this goes red - which is the whole
    // reason the columns exist.
    $touchesReviews = array_values(array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, '"reviews"')
            || str_contains($sql, '`reviews`')
            || str_contains($sql, '"review_answers"')
            || str_contains($sql, '`review_answers`'),
    ));

    expect($touchesReviews)->toBe([]);

    // A ceiling rather than an exact number: Filament's own boot issues a
    // handful (the user, the tenant, the session) and a Filament upgrade may
    // move that by one or two without anything being wrong. Twenty is far
    // below the 501 a per-row aggregate would produce, which is the failure
    // this bounds.
    expect(count($queries))->toBeLessThan(20);
});

it('renders five hundred abstracts inside the one-second budget of spec section 10', function () {
    $started = microtime(true);

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->set('tableRecordsPerPage', 'all')
        ->assertCountTableRecords(500);

    $elapsed = microtime(true) - $started;

    expect($elapsed)->toBeLessThan(1.0);
})->skip(
    fn (): bool => (bool) env('CI'),
    'Wall clock on a shared GitHub runner measures the runner, not the query: the same tree '
    .'has taken 0.4s and 2.1s an hour apart. The deterministic gate is the query-count test '
    .'above, which fails for the actual regression this budget exists to catch. Run this one '
    .'locally, and on the production host before launch (docs/runbooks/deploy-production.md).',
);
```

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferenceRankingTest.php tests/Feature/Organizer/ConferenceRankingPerformanceTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -20 /tmp/t.log
```

Expected: `rc=1`, naming `App\Filament\Organizer\Resources\Conferences\Pages\ConferenceRanking`.

- [ ] **Step 2: `RankedSubmissions` — the one definition of "under consideration"**

`app/Support/Scoring/RankedSubmissions.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Scoring;

use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Builder;

/**
 * **The** definition of which abstracts a conference's committee is looking at.
 *
 * Six things ask this question - the ranking table, the CSV export, the XLSX
 * export, SendDecisionEmails, MarkDecided::blockers() and the summary strip -
 * and six copies of a `whereIn('status', [...])` is how "under consideration"
 * comes to mean six things, the worst of which is a decision email sent to an
 * author who withdrew.
 *
 * The rule: everything except a draft and a withdrawal.
 *
 * - A **draft** was never submitted. Nobody reviewed it, it has no reference,
 *   and it is not in front of the committee.
 * - A **withdrawal** is the author's own exit. Spec 5.3 lets them take it back
 *   until the deadline, and an abstract that was withdrawn must never appear in
 *   a ranking, be decided, or receive a decision letter.
 * - Everything else stays: `submitted` and `under_review` are the work in
 *   progress, and `accepted`, `rejected` and `waitlisted` are rows that have
 *   already been decided and that the organizer still has to see - a ranking
 *   that hides its own decisions is a ranking you cannot check.
 *
 * The mirror of Plan 4's App\Support\Reviews\ReviewerScope, and deliberately a
 * different class: that one answers "what may this REVIEWER see", which also
 * depends on the conference status and on an assignment. This one answers "what
 * is this CONFERENCE deciding", which depends on nothing but the row.
 */
final class RankedSubmissions
{
    /** @return list<string> */
    public static function statuses(): array
    {
        return array_values(array_map(
            static fn (SubmissionStatus $status): string => $status->value,
            array_filter(
                SubmissionStatus::cases(),
                static fn (SubmissionStatus $status): bool => ! in_array(
                    $status,
                    [SubmissionStatus::Draft, SubmissionStatus::Withdrawn],
                    true,
                ),
            ),
        ));
    }

    /**
     * @return Builder<Submission>
     */
    public static function query(Conference $conference): Builder
    {
        return Submission::query()
            ->where('conference_id', $conference->getKey())
            ->whereIn('status', self::statuses());
    }

    /**
     * Narrow an existing builder instead of starting one, for the exports,
     * which are handed the table's own already-filtered query.
     *
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public static function constrain(Builder $query, Conference $conference): Builder
    {
        return $query
            ->where('conference_id', $conference->getKey())
            ->whereIn('status', self::statuses());
    }
}
```

- [ ] **Step 3: `Conference::rankingSummary()`**

`app/Models/Conference.php` — add after `submissionCounts()`:

```php
    /**
     * The summary strip above the ranking (spec 5.6). Two queries, whatever the
     * conference size: one grouped count by decision, one aggregate over the
     * scored rows.
     *
     * `mean_score` is the mean of the abstracts that HAVE a score, not of all
     * of them - an unreviewed abstract is not a zero, and averaging it in would
     * make the strip's number drift down every time a new abstract arrives.
     *
     * @return array{total: int, reviewed: int, unreviewed: int, mean_score: float|null, decided: int, undecided: int, by_decision: array<string, int>}
     */
    public function rankingSummary(): array
    {
        $base = RankedSubmissions::query($this);

        /** @var array<string, int> $byDecision */
        $byDecision = (clone $base)
            ->selectRaw('decision, count(*) as aggregate')
            ->groupBy('decision')
            ->pluck('aggregate', 'decision')
            ->all();

        /** @var object{total: int|null, reviewed: int|null, mean_score: float|string|null} $totals */
        $totals = (clone $base)
            ->selectRaw('count(*) as total')
            // count() over a nullable column counts the non-nulls, which is
            // exactly "how many have been reviewed at least once".
            ->selectRaw('count(score) as reviewed')
            ->selectRaw('avg(score) as mean_score')
            ->first();

        $total = (int) ($totals->total ?? 0);
        $reviewed = (int) ($totals->reviewed ?? 0);

        $counts = [];
        $decided = 0;

        foreach (Decision::inReportOrder() as $decision) {
            // The empty key matters: a decision with no rows must print 0
            // rather than disappear, or the strip silently changes shape.
            $counts[$decision->value] = (int) ($byDecision[$decision->value] ?? 0);
            $decided += $counts[$decision->value];
        }

        return [
            'total' => $total,
            'reviewed' => $reviewed,
            'unreviewed' => $total - $reviewed,
            // avg() comes back as a float on SQLite and a string on MySQL;
            // round() after an explicit cast so the strip prints the same
            // number in CI as it does here.
            'mean_score' => $totals->mean_score === null ? null : round((float) $totals->mean_score, 2),
            'decided' => $decided,
            'undecided' => $total - $decided,
            'by_decision' => $counts,
        ];
    }
```

with `use App\Enums\Decision;` and `use App\Support\Scoring\RankedSubmissions;` added to the imports.

- [ ] **Step 4: The page**

`app/Filament/Organizer/Resources/Conferences/Pages/ConferenceRanking.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Enums\Decision;
use App\Enums\SubmissionStatus;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\Track;
use App\Support\Scoring\RankedSubmissions;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Spec 5.6's ranking table, one conference at a time.
 *
 * **A resource page, not a second resource.** The ranking's every number is per
 * conference (its "fewer than N reviews" default is this conference's
 * `reviewers_per_submission`; its summary strip's denominator is this
 * conference's abstracts), SubmissionResource is already the panel-wide list,
 * and a second Resource over Submission would need a second copy of that
 * resource's `$isScopedToTenant = false` override and its HasOneThrough
 * warning. A page inherits ConferenceResource's real tenancy through
 * resolveRecord() and then scopes by conference_id, which needs no override.
 *
 * **Unlike ConferenceEmailTemplates, the table is query-backed.** `Table::query()`
 * rather than `Table::records()`, so sorting, filtering, searching, pagination
 * and bulk selection are all SQL (Filament takes the array branch only when
 * `! $table->hasQuery()`, vendor/filament/tables/src/Concerns/HasRecords.php:95).
 * That is what meets spec section 10's budget: every column here is a column on
 * `submissions`, so the page never touches `reviews`, and
 * tests/Feature/Organizer/ConferenceRankingPerformanceTest.php asserts exactly
 * that.
 */
class ConferenceRanking extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ConferenceResource::class;

    protected string $view = 'filament.organizer.pages.conference-ranking';

    public function mount(int|string $record): void
    {
        // resolveRecord() runs through ConferenceResource::getEloquentQuery(),
        // which carries the panel's tenancy global scope, so another
        // organization's conference is already a 404 before the policy is
        // consulted. The policy check is defence in depth, exactly as on
        // ConferenceEmailTemplates and ConferenceShortLink.
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 404);
    }

    public function getTitle(): string
    {
        return __('decisions.ranking.title');
    }

    public function getSubheading(): ?string
    {
        return __('decisions.ranking.subheading');
    }

    public function getConference(): Conference
    {
        /** @var Conference $conference */
        $conference = $this->getRecord();

        return $conference;
    }

    /**
     * The summary strip, computed once per render and handed to the view.
     *
     * @return array{total: int, reviewed: int, unreviewed: int, mean_score: float|null, decided: int, undecided: int, by_decision: array<string, int>}
     */
    public function summary(): array
    {
        return $this->getConference()->rankingSummary();
    }

    public function table(Table $table): Table
    {
        $conference = $this->getConference();

        // The configured default, FOLDED INTO the fixed ladder rather than
        // inserted into it. Filament renders $pageOptions verbatim
        // (vendor/filament/support/resources/views/components/pagination/index.blade.php
        // foreaches it with no dedupe), so an operator who sets
        // CASS_RANKING_PAGE_SIZE=100 - one of the three numbers already on
        // screen, and the likeliest value to pick - would otherwise get "100"
        // twice in the dropdown. array_unique() keeps first-occurrence order,
        // so sort() afterwards is what stops a 250 or a 500 leaving the ladder
        // out of order. max(1, ...) is the house rule for every read of a
        // count knob, and because the result is folded in rather than appended,
        // even CASS_RANKING_PAGE_SIZE=0 leaves defaultPaginationPageOption()
        // naming an option that is really in the list. 'all' stays out of the
        // sort: it is not a number, and array_unique over mixed types is not
        // worth the argument.
        $pageSize = max(1, (int) config('cass.decisions.page_size'));
        $pageOptions = array_unique([25, $pageSize, 100, 250]);
        sort($pageOptions);

        return $table
            ->query(fn (): Builder => RankedSubmissions::query($conference)->with('track'))
            // Best first. NULL sorts last descending on both drivers, so an
            // unreviewed abstract sinks to the bottom rather than heading the
            // list (verified in Task 11's MySQL run).
            ->defaultSort('score', 'desc')
            ->paginated([...$pageOptions, 'all'])
            ->defaultPaginationPageOption($pageSize)
            // The row goes to Plan 3's submission view, which is the one place
            // an organizer reads an abstract. Not a modal: the view page has the
            // files, the authors and the withdraw action already.
            ->recordUrl(fn (Submission $record): string => SubmissionResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('decisions.ranking.columns.reference'))
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('title')
                    ->label(__('decisions.ranking.columns.title'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(80),
                TextColumn::make('track.name')
                    ->label(__('decisions.ranking.columns.track'))
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('presentation_preference')
                    ->label(__('decisions.ranking.columns.preference'))
                    ->badge()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('score')
                    ->label(__('decisions.ranking.columns.score'))
                    ->numeric(2)
                    ->sortable()
                    ->weight('semibold')
                    // An em dash, not a zero: nobody has scored this yet, and a
                    // 0.00 in this column is a real and very different answer.
                    ->placeholder('—'),
                TextColumn::make('score_spread')
                    ->label(__('decisions.ranking.columns.spread'))
                    ->numeric(2)
                    ->sortable()
                    ->tooltip(__('decisions.ranking.columns.spread_help'))
                    ->placeholder('—'),
                TextColumn::make('review_count')
                    ->label(__('decisions.ranking.columns.reviews'))
                    ->badge()
                    ->sortable()
                    ->color(fn (Submission $record): string => $record->review_count
                        >= max(1, (int) $this->getConference()->reviewers_per_submission) ? 'success' : 'warning'),
                TextColumn::make('status')
                    ->label(__('decisions.ranking.columns.status'))
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('decision')
                    ->label(__('decisions.ranking.columns.decision'))
                    ->badge()
                    ->sortable()
                    ->placeholder(__('decisions.ranking.not_decided')),
                TextColumn::make('decision_notified_at')
                    ->label(__('decisions.ranking.columns.notified'))
                    ->dateTime('j M Y, H:i')
                    ->timezone($conference->timezone)
                    ->description($conference->timezone)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder(__('decisions.ranking.not_sent')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('decisions.ranking.columns.status'))
                    // Only the statuses this page can show, derived from the one
                    // definition rather than repeated. `SubmissionStatus::class`
                    // would offer draft and withdrawn, which RankedSubmissions
                    // has already excluded from the base query - an option that
                    // can only ever produce the "Nothing to rank yet" empty
                    // state reads as a bug, not as a rule.
                    ->options(fn (): array => collect(RankedSubmissions::statuses())
                        ->mapWithKeys(fn (string $value): array => [
                            $value => SubmissionStatus::from($value)->getLabel(),
                        ])
                        ->all())
                    ->multiple(),
                SelectFilter::make('track_id')
                    ->label(__('decisions.ranking.columns.track'))
                    // This conference's tracks only. `->relationship()` would
                    // query every track on the platform, because this page is
                    // not a tenant-scoped resource.
                    ->options(fn (): array => Track::query()
                        ->where('conference_id', $conference->getKey())
                        ->orderBy('sort')
                        ->pluck('name', 'id')
                        ->all()),
                SelectFilter::make('decision')
                    ->label(__('decisions.ranking.columns.decision'))
                    ->options(self::decisionFilterOptions())
                    // A custom query rather than the built-in equality: "not
                    // decided" is a NULL, and a SelectFilter cannot express one
                    // through its options alone.
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match (true) {
                            $value === null, $value === '' => $query,
                            $value === 'none' => $query->whereNull('decision'),
                            default => $query->where('decision', $value),
                        };
                    }),
                Filter::make('under_reviewed')
                    ->label(__('decisions.ranking.filters.under_reviewed'))
                    ->schema([
                        TextInput::make('count')
                            ->label(__('decisions.ranking.filters.under_reviewed_count'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(99)
                            // NOT ->default(). A filter schema's defaults are
                            // hydrated on boot
                            // ($this->getTableFiltersForm()->fill($this->tableFilters),
                            // vendor/filament/tables/src/Concerns/InteractsWithTable.php:81
                            // -> HasState::fill(null), schemas/src/Concerns/HasState.php:324-341)
                            // and, because filters are deferred by default, the
                            // next lines copy them straight back into
                            // $tableFilters (:83-84). A custom-schema Filter has
                            // no `isActive` key for apply() to skip on
                            // (Filters/Concerns/InteractsWithTableQuery.php:19-40),
                            // so a default here would silently reduce the WHOLE
                            // ranking to `review_count < 2` on first load - and
                            // the in-order listing test, assertCountTableRecords(3),
                            // the search and summary cases, the 500-row budget
                            // and both export tests would all be looking at a
                            // filtered table. The conference's own target is a
                            // hint instead.
                            ->placeholder((string) max(1, (int) $conference->reviewers_per_submission))
                            ->helperText(__('decisions.ranking.filters.under_reviewed_help', [
                                'count' => max(1, (int) $conference->reviewers_per_submission),
                            ])),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $count = $data['count'] ?? null;

                        return blank($count) ? $query : $query->where('review_count', '<', (int) $count);
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $count = $data['count'] ?? null;

                        return blank($count)
                            ? null
                            : __('decisions.ranking.filters.under_reviewed_indicator', ['count' => (int) $count]);
                    }),
            ])
            ->emptyStateHeading(__('decisions.ranking.empty_heading'))
            ->emptyStateDescription(__('decisions.ranking.empty_body'));
    }

    /**
     * The four decisions plus "not decided". Built here rather than inline so
     * the same list can be reused by Task 6's decide action without the two
     * drifting.
     *
     * @return array<string, string>
     */
    public static function decisionFilterOptions(): array
    {
        $options = ['none' => __('decisions.ranking.not_decided')];

        foreach (Decision::inReportOrder() as $decision) {
            $options[$decision->value] = $decision->getLabel();
        }

        return $options;
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToConference')
                ->label(__('decisions.ranking.back'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(fn (): string => ConferenceResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }
}
```

**Two notes for the implementer.** `defaultPaginationPageOption()` lives on `Filament\Tables\Table\Concerns\CanPaginateRecords` alongside `paginated()`; if the name differs in 5.8.1, drop the call — the first entry of `paginated()` is used as the default and the test does not depend on it. `TextColumn::weight()` and `tooltip()` are cosmetic; if either is missing, delete it rather than inventing a replacement.

- [ ] **Step 5: The view**

`resources/views/filament/organizer/pages/conference-ranking.blade.php`
```blade
<x-filament-panels::page>
    {{-- The panel loads no Tailwind utilities (backlog: the organizer theme),
         so every measurement here is an inline style, the same way
         ConferenceEmailTemplates and ConferenceShortLink do it. --}}
    @php($summary = $this->summary())

    <x-filament::section :heading="__('decisions.summary.heading')">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:1rem">
            <div>
                <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;opacity:0.7">{{ __('decisions.summary.total') }}</p>
                <p style="font-size:1.5rem;font-weight:600">{{ $summary['total'] }}</p>
            </div>
            <div>
                <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;opacity:0.7">{{ __('decisions.summary.reviewed') }}</p>
                <p style="font-size:1.5rem;font-weight:600">{{ $summary['reviewed'] }}</p>
                <p style="font-size:0.75rem;opacity:0.7">{{ __('decisions.summary.unreviewed', ['count' => $summary['unreviewed']]) }}</p>
            </div>
            <div>
                <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;opacity:0.7">{{ __('decisions.summary.mean') }}</p>
                <p style="font-size:1.5rem;font-weight:600">{{ $summary['mean_score'] !== null ? number_format($summary['mean_score'], 2) : '—' }}</p>
            </div>
            <div>
                <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;opacity:0.7">{{ __('decisions.summary.decided') }}</p>
                <p style="font-size:1.5rem;font-weight:600">{{ $summary['decided'] }}</p>
                <p style="font-size:0.75rem;opacity:0.7">{{ __('decisions.summary.undecided', ['count' => $summary['undecided']]) }}</p>
            </div>
            @foreach (\App\Enums\Decision::inReportOrder() as $decision)
                <div>
                    <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;opacity:0.7">{{ $decision->getLabel() }}</p>
                    <p style="font-size:1.5rem;font-weight:600">{{ $summary['by_decision'][$decision->value] }}</p>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <div style="display:flex;flex-direction:column;gap:1.5rem">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
```

- [ ] **Step 6: Register the page and the way in**

`app/Filament/Organizer/Resources/Conferences/ConferenceResource.php` — add to `getPages()`, between `short-link` and `emails`:

```php
            'ranking' => ConferenceRanking::route('/{record}/ranking'),
```

with `use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceRanking;` added to the imports.

`app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php` — add after `emails()`:

```php
    public static function ranking(): Action
    {
        return Action::make('ranking')
            ->label(__('decisions.ranking.action'))
            ->icon(Heroicon::OutlinedTrophy)
            ->color('gray')
            // Only once there is something to rank. Before `reviewing` every
            // number on that page is null and every decision action is refused,
            // which is a screen that answers a question nobody asked.
            ->visible(fn (Conference $record): bool => in_array(
                $record->status,
                [ConferenceStatus::Reviewing, ConferenceStatus::Decided, ConferenceStatus::Archived],
                true,
            ) && Gate::allows('view', $record))
            ->url(fn (Conference $record): string => ConferenceResource::getUrl('ranking', ['record' => $record]));
    }
```

and `all()` gains it. **Plan 4 Task 11 already rewrote `all()`**, so take what is on `main` and insert `static::ranking()` after `static::emails()`:

```php
        return [
            static::share(), static::emails(), static::ranking(), static::reviewers(), static::assignments(),
            static::remindReviewers(), static::publish(), static::startReviewing(),
            static::close(), static::archive(),
        ];
```

**Archived is in the visibility list on purpose:** archiving takes a conference off the public site and changes nothing about the committee's record of what it decided, and an organizer asked six months later "what did we accept" must still be able to look.

- [ ] **Step 7: The language file**

`lang/en/decisions.php` (created in Task 1 with the single `history` group; this task adds the `ranking` and `summary` groups, Tasks 6-9 append their own and Task 10 sweeps the lot). **Add the two groups; do not rewrite the file.** A second literal for a key the file already holds silently replaces the first, which is the failure Task 10 Step 2 exists to catch.

```php
<?php

declare(strict_types=1);

return [

    'ranking' => [
        'action' => 'Ranking and decisions',
        'title' => 'Ranking and decisions',
        'subheading' => 'Every abstract still under consideration, best score first. Scores are the weighted mean of the submitted reviews; the spread is how far apart the reviewers were.',
        'back' => 'Back to the conference',
        'not_decided' => 'Not decided',
        'not_sent' => 'Not sent',
        'empty_heading' => 'Nothing to rank yet',
        'empty_body' => 'Abstracts appear here once they have been submitted. Scores appear as reviews come in.',
        'columns' => [
            'reference' => 'Reference',
            'title' => 'Title',
            'track' => 'Track',
            'preference' => 'Preference',
            'score' => 'Score',
            'spread' => 'Spread',
            'spread_help' => 'Sample standard deviation of the review scores. A high number means the reviewers disagreed.',
            'reviews' => 'Reviews',
            'status' => 'Status',
            'decision' => 'Decision',
            'notified' => 'Letter sent',
        ],
        'filters' => [
            'under_reviewed' => 'Not enough reviews',
            'under_reviewed_count' => 'Fewer than this many reviews',
            'under_reviewed_help' => 'This conference asks for :count reviews per abstract.',
            'under_reviewed_indicator' => 'Fewer than :count reviews',
        ],
    ],

    'summary' => [
        'heading' => 'Where this conference stands',
        'total' => 'Abstracts',
        'reviewed' => 'Reviewed',
        'unreviewed' => ':count with no reviews yet',
        'mean' => 'Mean score',
        'decided' => 'Decided',
        'undecided' => ':count still open',
    ],

    // 'history' is already in this file from Task 1 - do not write it a second
    // time. PHP keeps the LAST literal for a duplicated key, so a second
    // 'history' => [...] here would silently replace Task 1's group.

];
```

- [ ] **Step 8: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferenceRankingTest.php tests/Feature/Organizer/ConferenceRankingPerformanceTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -10 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`; about 12 passed in the first two files and `baseline + 79` in the second. Two of those cases carry assertions that are easy to lose in a rewrite and are the point of them: `it('sorts by score, spread, review count and track')` ends on `->sortTable('track.name', 'asc')` — the only one of the four sorts Filament answers with a relationship subquery — and `it('leaves out drafts and withdrawals')` also asserts that `RankedSubmissions::statuses()` holds neither Draft nor Withdrawn, which is what the status filter's options are derived from.

**If `it('lists the abstracts under consideration, best first')` fails on the order**, check `defaultSort` before suspecting the driver: `assertCanSeeTableRecords(..., inOrder: true)` asserts `assertSeeHtmlInOrder` over the row keys (`vendor/filament/tables/src/Testing/TestsRecords.php:20-42`), so it is sensitive to the sort and to nothing else.

**If `it('lists the abstracts under consideration, best first')` sees only one row**, the `under_reviewed` filter has a `->default()` on its `TextInput` again: a filter schema's defaults are hydrated at boot and a custom-schema `Filter` has no `isActive` key for `apply()` to skip on, so the default silently filters the whole page.

**If the query-count test reports a query against `reviews`**, the cause is almost always a column that reached for a relation — `review_count` here is `$record->review_count`, the column, and never `$record->reviews()->count()`.

- [ ] **Step 9: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(organizer): the per-conference ranking table and its summary strip

A resource page with a query-backed table, so sorting, filters, search and
pagination are all SQL. Every column is a denormalised column on submissions, so
the page never touches reviews - and a test asserts exactly that over 500 rows,
which is the property spec section 10's one-second budget really depends on.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 5: The CSV and XLSX exports, and one copy of the formula guard

Spec 5.6: "export CSV and XLSX". Plan 3 shipped the submission list's CSV and recorded the rest in the backlog as "a writer, not a package" — openspout has been a direct dependency since then and writes both from the same `Row` objects.

**The formula guard moves, and that is the most important line in this task.**

`ExportSubmissionsCsv::guard()` prefixes an apostrophe to any value starting with `=`, `+`, `-`, `@`, a tab or a carriage return, because Excel executes such a cell. In CSV that is a defence against the *reader*. In XLSX it is a defence against **openspout itself**: `Cell::fromValue()` returns a `FormulaCell` when the first character is `=` (`vendor/openspout/openspout/src/Common/Entity/Cell.php:58-60`), so an unguarded title would be written into the workbook as an `<f>` element — a live formula, authored by whoever typed the abstract. Two copies of that rule is one copy too many, so the method moves to `App\Support\Export\SpreadsheetCell` and `ExportSubmissionsCsv` calls it. Plan 3's existing test, `it('exports the visible rows as csv and neutralises a spreadsheet formula')`, is **not edited**: its staying green untouched is the proof the move was behaviour-preserving.

**One row builder, two writers.** `App\Support\Scoring\RankingRows` owns the headers and turns one `Submission` into a list of already-guarded cells; `ExportRankingCsv` and `ExportRankingXlsx` own only the streaming. Numbers stay numbers — `score`, `score_spread` and `review_count` are passed as `float`/`int`, so openspout writes `NumericCell`s and the spreadsheet can sort and chart them, while every string goes through the guard. A number cannot be a formula, so the guard takes `?string` and nothing else.

**What the export contains.** The columns an organizer needs to argue about a programme: reference, title, track, presentation preference, score, spread, review count, status, decision, when the letter went out, the corresponding author's name and email, and the full author list. Not the abstract body — a 500-word paragraph per row makes a spreadsheet unreadable, and Plan 3's submission-list CSV already exports everything about the text. The two exports are deliberately different views and the runbook says which is which.

**Why XLSX is not row-streamed, said out loud.** openspout builds the workbook under `Options::getTempFolder()` (defaulting to `sys_get_temp_dir()`) and copies the finished zip to the stream at `close()` (fact 3). Memory stays flat; the file is assembled on disk. For the conference sizes this platform is for — the largest legacy conference is in the hundreds — that is a few hundred kilobytes and well inside php-fpm's 60-second budget. The temp folder is left at its default rather than pointed at `storage/`: `sys_get_temp_dir()` is `/tmp` in the runtime image, it is writable by the `app` user, and it is *not* on the `cass-storage` volume — which is what we want, because a half-written export must not survive a container restart.

**Files:**
- Create: `app/Support/Export/SpreadsheetCell.php`, `app/Support/Scoring/RankingRows.php`, `app/Actions/Submissions/ExportRankingCsv.php`, `app/Actions/Submissions/ExportRankingXlsx.php`
- Modify: `app/Actions/Submissions/ExportSubmissionsCsv.php` (delete one private method, change one call), `app/Filament/Organizer/Resources/Conferences/Pages/ConferenceRanking.php` (two **table** header actions and the private builder behind them), `lang/en/decisions.php`
- Test: `tests/Feature/Organizer/ConferenceRankingExportTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Organizer/ConferenceRankingExportTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Submissions\ExportRankingXlsx;
use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\OrganizationRole;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceRanking;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Support\Scoring\RankedSubmissions;
use Carbon\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->closed()->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
        'timezone' => 'Asia/Riyadh',
    ]);
    $this->track = Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);

    $this->submission = Submission::factory()->for($this->conference)->scored(91.25, 2.5, 3)
        ->withCorrespondingAuthor('sara@example.org', 'Dr Sara Al-Harbi')
        ->create(['title' => 'Early mobilisation after cardiac surgery', 'track_id' => $this->track->id]);
    $this->submission->forceFill([
        'reference' => 'AAM26-001',
        'decision' => Decision::AcceptedOral,
    ])->save();
});

it('exports the visible rows as csv, with the numbers and the authors', function () {
    $component = livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('exportCsv')
        ->assertFileDownloaded();

    $csv = base64_decode((string) data_get($component->effects, 'download.content'), true);

    expect($csv)->toContain('AAM26-001')
        ->toContain('Early mobilisation after cardiac surgery')
        ->toContain('Neurocritical care')
        // Numbers are written as NUMBERS, not as the decimal:2 strings the
        // casts hand back. SpreadsheetCell::number() gives openspout a real
        // float so the spreadsheet can sort and chart the column, and openspout
        // stringifies a NumericCell with `(string) $value->getValue()`
        // (vendor/openspout/openspout/src/Writer/CSV/Writer.php:69) - so 91.25
        // is written "91.25" and 2.50 is written "2.5". Asserting '2.50' here
        // would push the implementer into formatting the cell as text, which is
        // the one thing this column must not be.
        ->toContain('91.25')
        ->toContain(',2.5,')
        ->toContain('Dr Sara Al-Harbi')
        ->toContain('sara@example.org')
        ->toContain(Decision::AcceptedOral->getLabel())
        // The BOM, so Excel opens UTF-8 without mangling an Arabic affiliation.
        ->and(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF");
});

it('exports only the rows the filters left on screen', function () {
    $other = Submission::factory()->for($this->conference)->scored(40.0)->create(['title' => 'A different abstract']);
    $other->forceFill(['reference' => 'AAM26-002'])->save();

    $component = livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->filterTable('decision', Decision::AcceptedOral->value)
        ->callTableAction('exportCsv')
        ->assertFileDownloaded();

    $csv = base64_decode((string) data_get($component->effects, 'download.content'), true);

    expect($csv)->toContain('AAM26-001')->not->toContain('AAM26-002');
});

it('never exports another conference, whatever the filters say', function () {
    $other = Conference::factory()->for($this->organization)->closed()->create();
    $theirs = Submission::factory()->for($other)->scored(99.0)->create(['title' => 'Winter abstract']);

    $component = livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('exportCsv')
        ->assertFileDownloaded();

    $csv = base64_decode((string) data_get($component->effects, 'download.content'), true);

    expect($csv)->not->toContain('Winter abstract');
});

it('names both files after the conference and the moment they were taken', function () {
    Carbon::setTestNow('2026-09-13 08:30:00');

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('exportCsv')
        ->assertFileDownloaded('ranking-alpha-annual-meeting-2026-09-13-083000.csv');

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('exportXlsx')
        ->assertFileDownloaded('ranking-alpha-annual-meeting-2026-09-13-083000.xlsx');

    Carbon::setTestNow();
});

it('writes a real workbook whose scores are numbers and whose titles are not formulas', function () {
    // The case this whole guard exists for, and it is worse in XLSX than in
    // CSV: openspout's Cell::fromValue() returns a FormulaCell for a leading
    // `=` (Common/Entity/Cell.php:58-60), so an unguarded title would be
    // written as an <f> element - a live formula inside the workbook, authored
    // by whoever typed the abstract.
    $this->submission->forceFill(['title' => '=HYPERLINK("https://evil.example","click")'])->save();

    $response = app(ExportRankingXlsx::class)->handle(
        RankedSubmissions::query($this->conference),
        $this->conference,
        'ranking.xlsx',
    );

    $path = sys_get_temp_dir().'/cass-ranking-test-'.bin2hex(random_bytes(6)).'.xlsx';

    ob_start();
    $response->sendContent();
    file_put_contents($path, (string) ob_get_clean());

    try {
        expect(filesize($path))->toBeGreaterThan(0);

        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();

        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        expect($sheet)->not->toBe('')
            // Inline strings are openspout's XLSX default
            // (Writer/XLSX/Options.php:20), so the text is in the sheet itself.
            ->toContain('AAM26-001')
            // Guarded: the apostrophe is there and the cell is not a formula.
            ->toContain('&#039;=HYPERLINK')
            ->not->toContain('<f>')
            // The score is a number, not text, so the spreadsheet can sort it.
            ->toContain('<v>91.25</v>');
    } finally {
        @unlink($path);
    }
});

it('refuses both exports to somebody with no role in this organization', function () {
    $outsider = User::factory()->create();
    $otherOrganization = Organization::factory()->approved()->create();
    $otherOrganization->addMember($outsider, OrganizationRole::Owner);

    actingAs($outsider);
    // The tenant that OWNS this conference, not the outsider's own.
    // SubmissionPolicy::export() is export() -> viewAny()
    // (app/Policies/SubmissionPolicy.php:119-123 and :25-29), which asks "has
    // this user a role in the CURRENT tenant" - so it has to be asked inside
    // THIS conference's tenant. Booted into $otherOrganization, where
    // addMember() has just made them an Owner, it would answer TRUE, about
    // their own abstracts, which is not the question. bootOrganizerPanel() sets
    // the tenant unconditionally (tests/Pest.php:30-51), so booting a tenant
    // this user has no role in is both legal and exactly the case to pin. The
    // page itself is a 404 for this user (ConferenceRankingTest covers that);
    // this pins the second gate.
    bootOrganizerPanel($this->organization);

    expect(Gate::forUser($outsider)->allows('export', Submission::class))->toBeFalse();

    // And with no tenant at all, false rather than accidentally true.
    withoutTenant(function () use ($outsider): void {
        expect(Gate::forUser($outsider)->allows('export', Submission::class))->toBeFalse();
    });
});
```

with `use App\Models\Submission;` and `use Illuminate\Support\Facades\Gate;` in the import list (Pint sorts them).

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferenceRankingExportTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -20 /tmp/t.log
```

Expected: `rc=1`, naming `App\Actions\Submissions\ExportRankingXlsx`.

- [ ] **Step 2: The one copy of the guard**

`app/Support/Export/SpreadsheetCell.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Export;

/**
 * **The** rule for putting untrusted text into a spreadsheet.
 *
 * Excel and LibreOffice execute a cell that begins with `=`, `+`, `-`, `@`, a
 * tab or a carriage return, and every text column this application exports was
 * typed by an author nobody vetted. A leading apostrophe makes the cell text,
 * which is what it always was.
 *
 * In CSV that is a defence against the *reader*. In XLSX it is a defence
 * against the *writer*: openspout's Cell::fromValue() returns a FormulaCell
 * when the first character is `=`
 * (vendor/openspout/openspout/src/Common/Entity/Cell.php:58-60), so an
 * unguarded value is written into the workbook as an `<f>` element - a live
 * formula, authored by whoever typed the abstract. **The guard must therefore
 * run before Row::fromValues(), not after.**
 *
 * Moved here from ExportSubmissionsCsv::guard() unchanged, so there is one copy
 * rather than one per writer; that action's existing test
 * (tests/Feature/Organizer/SubmissionResourceTest.php:183) still passes
 * unedited, which is the proof the move changed nothing.
 */
final class SpreadsheetCell
{
    public static function text(?string $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * A number stays a number, so openspout writes a NumericCell and the
     * spreadsheet can sort, filter and chart it. A number cannot begin with `=`
     * so there is nothing to guard - and passing "91.25" as a string instead
     * would make every score column in the file left-aligned text.
     */
    public static function number(int|float|string|null $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        // `decimal:2` casts hand back a string on every driver; is_numeric
        // keeps a non-numeric surprise out of the sheet rather than coercing it
        // to 0.
        return is_numeric($value) ? (float) $value : null;
    }
}
```

`app/Actions/Submissions/ExportSubmissionsCsv.php` — **delete** the private `guard()` method entirely and change its one call site:

```php
        return array_map(SpreadsheetCell::text(...), [
```

with `use App\Support\Export\SpreadsheetCell;` added to the imports. Nothing else in that file changes, and `tests/Feature/Organizer/SubmissionResourceTest.php` is not edited.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/SubmissionResourceTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -4 /tmp/t.log
```

Expected: `rc=0`, with the same count as before the move. **Run this before writing anything else in this task** — it is the whole safety net for the refactor.

- [ ] **Step 3: The row builder**

`app/Support/Scoring/RankingRows.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Scoring;

use App\Models\Conference;
use App\Models\Submission;
use App\Support\Export\SpreadsheetCell;

/**
 * The headers and one row of the ranking export, shared by the CSV and the
 * XLSX writer so the two files cannot drift.
 *
 * **What is deliberately not here: the abstract body.** A 500-word paragraph
 * per row makes a spreadsheet unreadable, and Plan 3's submission-list export
 * (ExportSubmissionsCsv) already carries the full text, the affiliations, the
 * custom-field answers and the file names. These are two different views on
 * purpose, and the runbook says which is which.
 *
 * Strings go through SpreadsheetCell::text() - the formula guard, which must
 * run before Row::fromValues(). Numbers stay numbers, so the spreadsheet can
 * sort them.
 */
final class RankingRows
{
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

    /**
     * One row. The eager loads the caller must have applied are `track` and
     * `authors`; without them this is two queries per row, which is the one
     * thing an export of five hundred abstracts cannot afford.
     *
     * @return list<string|int|float|null>
     */
    public static function row(Submission $submission, Conference $conference): array
    {
        $corresponding = $submission->correspondingAuthor();
        $timezone = (string) ($conference->timezone ?: config('app.timezone'));

        return [
            SpreadsheetCell::text($submission->reference),
            SpreadsheetCell::text($submission->title),
            SpreadsheetCell::text($submission->track->name ?? ''),
            SpreadsheetCell::text($submission->presentation_preference?->getLabel() ?? ''),
            SpreadsheetCell::number($submission->score),
            SpreadsheetCell::number($submission->score_spread),
            (int) $submission->review_count,
            SpreadsheetCell::text($submission->status->getLabel()),
            SpreadsheetCell::text($submission->decision?->getLabel() ?? ''),
            // `->copy()` before `->setTimezone()`, the rule
            // Conference::deadlineInConferenceTimezone() sets:
            // Illuminate\Support\Carbon is mutable, so setting the zone on the
            // instance a cast handed out is a write, not a read.
            SpreadsheetCell::text($submission->decision_notified_at?->copy()->setTimezone($timezone)->format('Y-m-d H:i') ?? ''),
            SpreadsheetCell::text($corresponding->name ?? ''),
            SpreadsheetCell::text($corresponding->email ?? ''),
            SpreadsheetCell::text($submission->authors->pluck('name')->implode('; ')),
            SpreadsheetCell::text($submission->submitted_at?->copy()->setTimezone($timezone)->format('Y-m-d H:i') ?? ''),
        ];
    }

    /**
     * The file name both exports use, so the two differ only in extension:
     * `ranking-alpha-annual-meeting-2026-09-13-083000.xlsx`.
     */
    public static function fileName(Conference $conference, string $extension): string
    {
        return 'ranking-'.$conference->slug.'-'.now()->format('Y-m-d-His').'.'.$extension;
    }
}
```

- [ ] **Step 4: The two writers**

`app/Actions/Submissions/ExportRankingCsv.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\Conference;
use App\Models\Submission;
use App\Support\Scoring\RankedSubmissions;
use App\Support\Scoring\RankingRows;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spec 5.6's CSV half of the ranking export. The same streaming shape as
 * ExportSubmissionsCsv: openspout writes to `php://output` inside a
 * StreamedResponse, so a conference with five thousand abstracts costs one
 * chunk of rows in memory rather than the whole file, and Livewire turns a
 * StreamedResponse returned from an action into a download.
 *
 * The caller hands in the table's own already-filtered query, so the file
 * matches the screen - and this action re-applies the conference scope anyway
 * (RankedSubmissions::constrain), because "the file matches the screen" is a
 * convenience and "the file never contains another conference" is a rule.
 */
class ExportRankingCsv
{
    /**
     * @param  Builder<Submission>  $query
     */
    public function handle(Builder $query, Conference $conference, string $fileName): StreamedResponse
    {
        $scoped = RankedSubmissions::constrain($query, $conference);

        return response()->streamDownload(function () use ($scoped, $conference): void {
            $options = new Options;
            // Excel needs the BOM to open a UTF-8 file without mangling an
            // Arabic affiliation. It is openspout's default; stated so nobody
            // "tidies" it away.
            $options->SHOULD_ADD_BOM = true;

            $writer = new Writer($options);
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(RankingRows::headers()));

            $scoped
                ->with(['track', 'authors'])
                ->reorder()
                ->chunkById(200, function (Collection $submissions) use ($writer, $conference): void {
                    /** @var Submission $submission */
                    foreach ($submissions as $submission) {
                        $writer->addRow(Row::fromValues(RankingRows::row($submission, $conference)));
                    }
                });

            $writer->close();
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
```

`app/Actions/Submissions/ExportRankingXlsx.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\Conference;
use App\Models\Submission;
use App\Support\Scoring\RankedSubmissions;
use App\Support\Scoring\RankingRows;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spec 5.6's XLSX half. The same query, the same rows, a different writer.
 *
 * **This one is not streamed to the client row by row, and saying so matters.**
 * openspout assembles an XLSX under Options::getTempFolder() - which defaults
 * to sys_get_temp_dir() (Common/TempFolderOptionTrait.php:25-32) - and copies
 * the finished zip to the file pointer at close()
 * (Writer/Common/Helper/ZipHelper.php:128). Memory stays flat; the file is
 * built on disk and sent in one go. For the conference sizes this platform is
 * for that is a few hundred kilobytes, comfortably inside php-fpm's 60-second
 * budget (docker/php.ini).
 *
 * The temp folder is deliberately left at its default rather than pointed at
 * `storage/`: /tmp in the runtime image is writable by the `app` user and is
 * NOT on the cass-storage volume, so a half-written export cannot survive a
 * container restart or fill the volume the author uploads live on.
 *
 * `ext-zip` is required by openspout itself and is installed everywhere this
 * application runs (Dockerfile:25, .github/workflows/ci.yml:44 and :87).
 */
class ExportRankingXlsx
{
    /**
     * @param  Builder<Submission>  $query
     */
    public function handle(Builder $query, Conference $conference, string $fileName): StreamedResponse
    {
        $scoped = RankedSubmissions::constrain($query, $conference);

        return response()->streamDownload(function () use ($scoped, $conference): void {
            $writer = new Writer(new Options);
            $writer->openToFile('php://output');

            $header = (new Style)->setFontBold();
            $writer->addRow(Row::fromValues(RankingRows::headers(), $header));

            $scoped
                ->with(['track', 'authors'])
                ->reorder()
                ->chunkById(200, function (Collection $submissions) use ($writer, $conference): void {
                    /** @var Submission $submission */
                    foreach ($submissions as $submission) {
                        // RankingRows::row() has already run every string
                        // through SpreadsheetCell::text(), which is what stops
                        // Cell::fromValue() turning a title into a FormulaCell
                        // below.
                        $writer->addRow(Row::fromValues(RankingRows::row($submission, $conference)));
                    }
                });

            $writer->close();
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
```

- [ ] **Step 5: The two TABLE header actions**

**These go on the `Table`, not on the page.** `callTableAction()` / `assertTableAction*()` stamp `context['table'] = true` (`vendor/filament/tables/src/Testing/TestsActions.php:399`) and resolve through `$this->getTable()->getAction($name)` (`vendor/filament/actions/src/Concerns/InteractsWithActions.php:705` → `vendor/filament/tables/src/Table/Concerns/HasActions.php:20-23`), which reads only `Table::$flatActions` — the actions the *table* registered — and throws `ActionNotResolvableException("Action [exportCsv] not found on table.")` for anything else. A page header action is cached on the Livewire component instead and is reachable only through `callAction()`. Plan 3's export works for exactly this reason: it is a table header action (`app/Filament/Organizer/Resources/Submissions/Tables/SubmissionsTable.php:98-99`), and this task follows it so the whole suite keeps one idiom.

The page's own `getHeaderActions()` is therefore left exactly as Task 4 wrote it — `backToConference` alone, which `ConferenceRankingTest` drives with the page helper `assertActionExists('backToConference')`.

`app/Filament/Organizer/Resources/Conferences/Pages/ConferenceRanking.php` — in `table()`, after `->filters([...])` and before `->emptyStateHeading(...)`, add:

```php
            ->headerActions([
                // TABLE header actions. `table()` is an instance method on the
                // page, so `$this->exportAction(...)` resolves, and Filament
                // evaluates the closures without rebinding `$this`
                // (vendor/filament/support/src/Concerns/EvaluatesClosures.php:35),
                // so `$this->getFilteredTableQuery()` inside the action still
                // runs on the page.
                $this->exportAction(
                    'exportCsv',
                    __('decisions.ranking.export_csv'),
                    Heroicon::OutlinedArrowDownTray,
                    fn (Builder $query, Conference $conference): StreamedResponse => app(ExportRankingCsv::class)
                        ->handle($query, $conference, RankingRows::fileName($conference, 'csv')),
                ),
                $this->exportAction(
                    'exportXlsx',
                    __('decisions.ranking.export_xlsx'),
                    Heroicon::OutlinedTableCells,
                    fn (Builder $query, Conference $conference): StreamedResponse => app(ExportRankingXlsx::class)
                        ->handle($query, $conference, RankingRows::fileName($conference, 'xlsx')),
                ),
            ])
```

and the private builder below, unchanged, stays on the page:

```php
    /**
     * The two exports differ only in a name, an icon and a writer, and both
     * need the same four things: the export ability, the table's own filtered
     * query, a null guard on it, and the conference. One builder rather than
     * two near-identical closures, so a fix to the guard is a fix to both.
     *
     * @param  \Closure(Builder<Submission>, Conference): StreamedResponse  $writer
     */
    private function exportAction(string $name, string $label, Heroicon $icon, \Closure $writer): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color('gray')
            ->visible(fn (): bool => Gate::allows('export', Submission::class))
            ->action(function () use ($writer): ?StreamedResponse {
                Gate::authorize('export', Submission::class);

                // The table's own query: already scoped to this conference and
                // already carrying whatever filters and search the organizer is
                // looking at, so the file matches the screen. Filament types it
                // `?Builder` with no generic (Tables\Contracts\HasTable), which
                // Larastan level 6 will not hand to a `Builder<Submission>`
                // parameter - hence the annotation and the null guard.
                /** @var Builder<Submission>|null $query */
                $query = $this->getFilteredTableQuery();

                if ($query === null) {
                    Notification::make()->danger()->title(__('decisions.ranking.nothing_to_export'))->send();

                    return null;
                }

                return $writer($query, $this->getConference());
            });
    }
```

with `use App\Actions\Submissions\ExportRankingCsv;`, `use App\Actions\Submissions\ExportRankingXlsx;`, `use App\Support\Scoring\RankingRows;`, `use Closure;`, `use Filament\Notifications\Notification;`, `use Illuminate\Support\Facades\Gate;` and `use Symfony\Component\HttpFoundation\StreamedResponse;` added to the imports (and the `\Closure` in the signature written as `Closure`). `Action` and `Heroicon` are already imported by Task 4.

**Write the rule down where the next person will hit it.** These two are *table* header actions, so their tests use `callTableAction()` / `assertTableAction*()`. The page's `backToConference` is a *page* header action, so its test uses `assertActionExists()`. The two families resolve through different code paths (`InteractsWithActions::resolveAction()` vs `resolveTableAction()`) and are not interchangeable; Task 7's "Send decision emails" joins this same `->headerActions([...])` array for the same reason.

Append to `lang/en/decisions.php`, inside the `ranking` group:

```php
        'export_csv' => 'Export CSV',
        'export_xlsx' => 'Export Excel',
        'nothing_to_export' => 'Nothing to export',
```

- [ ] **Step 6: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferenceRankingExportTest.php tests/Feature/Organizer/SubmissionResourceTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -8 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`; about 30 passed in the first two files (`SubmissionResourceTest` carries its own Plan 3 cases, **unedited**) and `baseline + 85` in the second.

**If `it('writes a real workbook …')` reports `<f>` in the sheet**, the guard is running after `Row::fromValues` rather than before it, or `RankingRows::row()` is returning the raw title. **If it reports `<v>91.25</v>` missing**, `SpreadsheetCell::number()` is returning a string — check that the `decimal:2` cast's string is reaching `is_numeric` and being cast.

**If the CSV case fails on a trailing zero** — the file holds `2.5` where `2.50` was expected — that is openspout stringifying a float (`Writer/CSV/Writer.php:69`) and the cell is *correct*. Fix the expectation, never `SpreadsheetCell::number()`: making it return a formatted string turns every score column in both files into left-aligned text and re-opens the formula question for the numeric columns.

**If an export case dies with `Action [exportCsv] not found on table.`**, the two exports were registered in the page's `getHeaderActions()` instead of the table's `->headerActions([...])` (Step 5). Move the actions; do not change the tests to `callAction()` — the whole suite drives table actions with the `*Table*` family.

**If `ZipArchive` is not found**, `php -m | grep zip` on this machine; openspout's own `composer.json` requires it, so a missing extension means the local PHP is not the one `composer install` validated against.

- [ ] **Step 7: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(organizer): CSV and XLSX exports of the ranking, with one formula guard

openspout turns a leading = into a real FormulaCell, so the injection guard is
not cosmetic in XLSX: it is the difference between a text cell and a live
formula an author authored. The guard moves out of ExportSubmissionsCsv into
SpreadsheetCell and both writers call it; that action's own test is unedited.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 6: `ApplyDecision`, the bulk wrapper, and the row and bulk actions

Spec 5.6: "Decisions: `accepted_oral`, `accepted_poster`, `waitlisted`, `rejected`. Applied per row or in bulk. Each decision records who and when and appends to `SubmissionDecision`. Decision emails … are sent when the organizer clicks 'Send decision emails' (so decisions can be prepared quietly first)."

**The rule that the parenthesis in that sentence implies, written out.**

"Prepared quietly first" means a decision is not a promise to the author until the letter is sent. So:

- **Before the letter is sent** (`decision_notified_at` is null), a decision may be changed as often as the committee likes. Each change appends a row to the history — the committee's own record of how it got there — and nothing leaves the building.
- **After the letter is sent**, a plain "Decide" on that row is **refused**. The author has an email saying they were accepted; silently flipping the column to `rejected` would leave the application and the author's inbox disagreeing, with nothing on screen saying so.
- The only way through is an explicit **"Change decision and resend"**, which appends to the history, sets the new decision, and sets `decision_notified_at` back to null so the next send queues a new letter. The modal says, in one sentence, that a second email will go to the author.

**What the bulk action does with a row it cannot decide.** It reports it. `ApplyDecisions` returns `array{applied, unchanged, refused}` where `refused` is keyed by reference and carries the sentences, and the action renders "Decided 12. 3 already had this decision. 2 refused: AAM26-004 — this abstract has already been told…". Filament's own `authorizeIndividualRecords()` would collapse all of that into a count (fact 9), and "2 rows were skipped" is exactly the message that makes an organizer re-click until something breaks.

**Unchanged is not refused.** Applying `accepted_oral` to a row that is already `accepted_oral` does nothing at all: no history row, no timestamp change, no activity entry. It is counted separately and reported separately. A bulk decision over a filtered list will hit this constantly — the organizer selects "all accepted" and confirms — and treating it as an error would make the honest case look like a failure.

**Files:**
- Create: `app/Actions/Decisions/ApplyDecision.php`, `app/Actions/Decisions/ApplyDecisions.php`
- Create: `app/Filament/Organizer/Resources/Conferences/Tables/DecisionActions.php`
- Modify: `app/Filament/Organizer/Resources/Conferences/Pages/ConferenceRanking.php` (record actions and a toolbar action), `app/Filament/Organizer/Resources/Submissions/Schemas/SubmissionInfolist.php` (a read-only Decision section), `lang/en/decisions.php`
- Test: `tests/Unit/ApplyDecisionTest.php`, `tests/Feature/Organizer/DecisionsTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ApplyDecisionTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Decisions\ApplyDecision;
use App\Actions\Decisions\ApplyDecisions;
use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\SubmissionStatus;
use App\Exceptions\DecisionNotAcceptable;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->conference = Conference::factory()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
    ]);
    $this->submission = Submission::factory()->for($this->conference)->submitted()->create();
    $this->submission->forceFill(['reference' => 'AAM26-017'])->save();
});

it('applies a decision, moves the status and appends to the history', function () {
    $result = app(ApplyDecision::class)->handle($this->submission, Decision::AcceptedOral, $this->actor, 'Strong reviews.');

    expect($result->decision)->toBe(Decision::AcceptedOral)
        ->and($result->status)->toBe(SubmissionStatus::Accepted)
        // Prepared quietly: nothing is emailed by deciding.
        ->and($result->decision_notified_at)->toBeNull()
        ->and($result->decisions()->count())->toBe(1);

    $row = $result->currentDecision();

    expect($row?->decision)->toBe(Decision::AcceptedOral)
        ->and($row?->decided_by)->toBe($this->actor->id)
        ->and($row?->decided_at)->not->toBeNull()
        ->and($row?->note)->toBe('Strong reviews.')
        ->and($row?->letter_markdown)->toBeNull()
        ->and(Activity::query()->where('description', 'submission.decided')->count())->toBe(1);
});

it('maps every decision to the right status', function (Decision $decision, SubmissionStatus $status) {
    $result = app(ApplyDecision::class)->handle($this->submission, $decision, $this->actor);

    expect($result->status)->toBe($status);
})->with([
    'oral' => [Decision::AcceptedOral, SubmissionStatus::Accepted],
    'poster' => [Decision::AcceptedPoster, SubmissionStatus::Accepted],
    'waitlisted' => [Decision::Waitlisted, SubmissionStatus::Waitlisted],
    'rejected' => [Decision::Rejected, SubmissionStatus::Rejected],
]);

it('lets an undecided decision be changed as often as the committee likes', function () {
    $apply = app(ApplyDecision::class);

    $apply->handle($this->submission, Decision::AcceptedPoster, $this->actor);
    $apply->handle($this->submission, Decision::Waitlisted, $this->actor);
    $result = $apply->handle($this->submission, Decision::AcceptedOral, $this->actor);

    // Three rows, newest first, and the column agrees with the top one.
    expect($result->decision)->toBe(Decision::AcceptedOral)
        ->and($result->decisions()->count())->toBe(3)
        ->and($result->decisions()->pluck('decision')->all())
        ->toBe([Decision::AcceptedOral, Decision::Waitlisted, Decision::AcceptedPoster]);
});

it('does nothing at all when the decision is already the one asked for', function () {
    $apply = app(ApplyDecision::class);
    $apply->handle($this->submission, Decision::AcceptedOral, $this->actor);

    $before = $this->submission->fresh()?->currentDecision()?->decided_at;

    $apply->handle($this->submission->fresh() ?? $this->submission, Decision::AcceptedOral, $this->actor);

    // No second history row, no new timestamp, no second activity entry. A
    // bulk decision over a filtered list hits this constantly and it is not an
    // error.
    expect($this->submission->fresh()?->decisions()->count())->toBe(1)
        ->and($this->submission->fresh()?->currentDecision()?->decided_at?->equalTo($before))->toBeTrue()
        ->and(Activity::query()->where('description', 'submission.decided')->count())->toBe(1);
});

it('refuses to change a decision the author has already been told about', function () {
    $apply = app(ApplyDecision::class);
    $apply->handle($this->submission, Decision::AcceptedOral, $this->actor);
    $this->submission->forceFill(['decision_notified_at' => now()])->save();

    $submission = $this->submission->fresh() ?? $this->submission;

    expect($apply->blockers($submission, Decision::Rejected))->not->toBe([])
        ->and(fn () => $apply->handle($submission, Decision::Rejected, $this->actor))
        ->toThrow(DecisionNotAcceptable::class);

    expect($submission->fresh()?->decision)->toBe(Decision::AcceptedOral);
});

it('refuses a note-only edit on a row the author has already been told about', function () {
    // The guard is about "would this write change anything", not about the
    // decision VALUE. handle() clears decision_notified_at unconditionally, so
    // a same-decision-different-note write on a notified row would put it back
    // in the send queue and the next "Send decision emails" would mail the
    // author a SECOND letter - rotating their status token again, with no
    // change-and-resend modal and no warning. The bulk action is the live path:
    // decideSelected() has no per-row visible() the way decide() does.
    $apply = app(ApplyDecision::class);
    $apply->handle($this->submission, Decision::AcceptedOral, $this->actor, 'First note.');
    $this->submission->forceFill(['decision_notified_at' => now()])->save();
    $submission = $this->submission->fresh() ?? $this->submission;

    expect($apply->blockers($submission, Decision::AcceptedOral, note: 'Second note.'))->not->toBe([])
        ->and(fn () => $apply->handle($submission, Decision::AcceptedOral, $this->actor, 'Second note.'))
        ->toThrow(DecisionNotAcceptable::class);

    // The letter stays sent: nothing re-enters the send queue.
    expect($submission->fresh()?->decision_notified_at)->not->toBeNull()
        ->and($submission->fresh()?->currentDecision()?->note)->toBe('First note.');
});

it('still treats an identical re-apply on a notified row as unchanged, not refused', function () {
    // The other half of the same rule, and the reason the guard cannot simply
    // be "notified means refuse": re-applying the same decision with the same
    // note over a filtered list is the normal post-send case, and refusing it
    // is how an organizer learns to ignore the report.
    $apply = app(ApplyDecision::class);
    $apply->handle($this->submission, Decision::AcceptedOral, $this->actor, 'First note.');
    $this->submission->forceFill(['decision_notified_at' => now()])->save();
    $submission = $this->submission->fresh() ?? $this->submission;

    expect($apply->blockers($submission, Decision::AcceptedOral, note: 'First note.'))->toBe([])
        ->and($apply->handle($submission, Decision::AcceptedOral, $this->actor, 'First note.')->decision_notified_at)
        ->not->toBeNull()
        ->and($submission->fresh()?->decisions()->count())->toBe(1);
});

it('changes a notified decision when the caller says resend, and queues a new letter', function () {
    $apply = app(ApplyDecision::class);
    $apply->handle($this->submission, Decision::AcceptedOral, $this->actor);
    $this->submission->forceFill(['decision_notified_at' => now()])->save();

    $result = $apply->handle(
        $this->submission->fresh() ?? $this->submission,
        Decision::Rejected,
        $this->actor,
        'Programme was rebuilt after a withdrawal.',
        changeAfterSend: true,
    );

    expect($result->decision)->toBe(Decision::Rejected)
        ->and($result->status)->toBe(SubmissionStatus::Rejected)
        // Back to null, so the next send queues the new letter.
        ->and($result->decision_notified_at)->toBeNull()
        ->and($result->decisions()->count())->toBe(2)
        // The superseded row keeps its own letter columns untouched - that is
        // the audit.
        ->and($result->decisions()->get()->last()?->decision)->toBe(Decision::AcceptedOral);
});

it('refuses a draft, a withdrawal, and a conference that is not deciding', function () {
    $apply = app(ApplyDecision::class);

    $draft = Submission::factory()->for($this->conference)->create();
    $withdrawn = Submission::factory()->for($this->conference)->withdrawn()->create();

    expect($apply->blockers($draft, Decision::AcceptedOral))->not->toBe([])
        ->and($apply->blockers($withdrawn, Decision::AcceptedOral))->not->toBe([])
        ->and(fn () => $apply->handle($draft, Decision::AcceptedOral, $this->actor))
        ->toThrow(DecisionNotAcceptable::class);

    $early = Conference::factory()->closed()->create();
    $tooSoon = Submission::factory()->for($early)->submitted()->create();

    expect($apply->blockers($tooSoon, Decision::AcceptedOral))->not->toBe([]);
});

it('still allows a decision once the conference is decided', function () {
    // A conference in `decided` is one whose letters have gone out. A late
    // correction still has to be possible - a presenter withdraws and the
    // waiting list moves - and it goes through the same change-and-resend rule.
    $this->conference->forceFill(['status' => ConferenceStatus::Decided])->save();

    $result = app(ApplyDecision::class)->handle(
        $this->submission->fresh() ?? $this->submission,
        Decision::AcceptedPoster,
        $this->actor,
    );

    expect($result->decision)->toBe(Decision::AcceptedPoster);
});

it('reports every row of a bulk run, one line each', function () {
    $ok = Submission::factory()->for($this->conference)->submitted()->create();
    $ok->forceFill(['reference' => 'AAM26-002'])->save();

    $already = Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral)->create();
    $already->forceFill(['reference' => 'AAM26-003'])->save();

    $notified = Submission::factory()->for($this->conference)->decided(Decision::Rejected, notified: true)->create();
    $notified->forceFill(['reference' => 'AAM26-004'])->save();

    $withdrawn = Submission::factory()->for($this->conference)->withdrawn()->create();
    $withdrawn->forceFill(['reference' => 'AAM26-005'])->save();

    $report = app(ApplyDecisions::class)->handle(
        [$this->submission, $ok, $already, $notified, $withdrawn],
        Decision::AcceptedOral,
        $this->actor,
    );

    expect($report['applied'])->toBe(2)
        ->and($report['unchanged'])->toBe(1)
        ->and(array_keys($report['refused']))->toBe(['AAM26-004', 'AAM26-005'])
        ->and($report['refused']['AAM26-004'])->toBeArray()->not->toBeEmpty();
});

it('summarises a bulk run in one sentence', function () {
    // summarise() is what the bulk action actually shows the organizer, and
    // nothing else in this plan exercises it - a wording that dropped the
    // refused references would look fine on screen and tell nobody which rows
    // were skipped.
    $summary = ApplyDecisions::summarise([
        'applied' => 2,
        'unchanged' => 1,
        'refused' => ['AAM26-004' => ['This abstract has already been told.']],
    ]);

    expect($summary)->toBeString()
        ->toContain('2')
        ->toContain('AAM26-004')
        ->toContain('This abstract has already been told.');
});
```

`tests/Feature/Organizer/DecisionsTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceRanking;
use App\Filament\Organizer\Resources\Submissions\Pages\ViewSubmission;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->closed()->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
    ]);

    $this->first = Submission::factory()->for($this->conference)->scored(91.0)->create(['title' => 'The good one']);
    $this->first->forceFill(['reference' => 'AAM26-001'])->save();

    $this->second = Submission::factory()->for($this->conference)->scored(40.0)->create(['title' => 'The other one']);
    $this->second->forceFill(['reference' => 'AAM26-002'])->save();
});

it('decides one row from the ranking', function () {
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('decide', $this->first, [
            'decision' => Decision::AcceptedOral->value,
            'note' => 'Best reviewed abstract in the track.',
        ])
        ->assertHasNoTableActionErrors();

    expect($this->first->fresh()?->decision)->toBe(Decision::AcceptedOral)
        ->and($this->first->fresh()?->status)->toBe(SubmissionStatus::Accepted)
        ->and($this->first->fresh()?->currentDecision()?->note)->toBe('Best reviewed abstract in the track.');
});

it('decides a whole selection at once, skips what it may not touch, and says so', function () {
    $notified = Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral, notified: true)->create();
    $notified->forceFill(['reference' => 'AAM26-003'])->save();

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableBulkAction('decideSelected', [$this->first, $this->second, $notified], [
            'decision' => Decision::Rejected->value,
        ])
        ->assertNotified();

    expect($this->first->fresh()?->decision)->toBe(Decision::Rejected)
        ->and($this->second->fresh()?->decision)->toBe(Decision::Rejected)
        // Already emailed: refused by ApplyDecision, and the column is
        // untouched. The report is what tells the organizer which row - without
        // this assertion the refusal path and summarise() are both untested and
        // a bulk action that silently skipped rows would pass.
        ->and($notified->fresh()?->decision)->toBe(Decision::AcceptedOral)
        ->and($notified->fresh()?->decision_notified_at)->not->toBeNull();
});

it('ignores a foreign record id handed to the bulk decision', function () {
    // Filament resolves a selection with $table->getQuery()->whereKey($ids)
    // (vendor/filament/tables/src/Concerns/HasBulkActions.php:305), so the
    // conference scope in ConferenceRanking::table()'s query closure is the
    // whole of the isolation on this path - the ONE path where the client
    // chooses which primary keys the server acts on. This case pins that scope,
    // not the Gate: a foreign row never reaches the per-row Gate loop, because
    // it is dropped before the loop runs. It goes red the day somebody moves
    // the conference scope out of the query closure and into a filter.
    $theirs = withoutTenant(fn (): Submission => Submission::factory()->submitted()->create());
    $sameOrgOtherConference = Conference::factory()->for($this->organization)->closed()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
    ]);
    $neighbour = Submission::factory()->for($sameOrgOtherConference)->submitted()->create();

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableBulkAction('decideSelected', [$this->first, $theirs, $neighbour], [
            'decision' => Decision::Rejected->value,
        ]);

    expect($this->first->fresh()?->decision)->toBe(Decision::Rejected)
        ->and($theirs->fresh()?->decision)->toBeNull()
        ->and($theirs->fresh()?->decisions()->count())->toBe(0)
        // Another conference of the SAME organization passes the decide policy
        // (it is view(), i.e. membership) and is kept out by the table query
        // alone - which is exactly why the table query has to be the scope.
        ->and($neighbour->fresh()?->decision)->toBeNull()
        ->and($neighbour->fresh()?->decisions()->count())->toBe(0);
});

it('ignores a foreign record id handed to the row decision', function () {
    $theirs = withoutTenant(fn (): Submission => Submission::factory()->submitted()->create());

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('decide', $theirs, ['decision' => Decision::Rejected->value]);

    expect($theirs->fresh()?->decision)->toBeNull()
        ->and($theirs->fresh()?->decisions()->count())->toBe(0);
});

it('hides the plain decide action once the letter has gone, and offers the change instead', function () {
    $this->first->forceFill([
        'decision' => Decision::AcceptedOral,
        'status' => SubmissionStatus::Accepted,
        'decision_notified_at' => now(),
    ])->save();

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->assertTableActionHidden('decide', $this->first)
        ->assertTableActionVisible('changeDecision', $this->first)
        // And the row that has not been notified still has the plain one.
        ->assertTableActionVisible('decide', $this->second)
        ->assertTableActionHidden('changeDecision', $this->second);
});

it('changes a notified decision and puts the row back in the send queue', function () {
    $this->first->forceFill([
        'decision' => Decision::AcceptedOral,
        'status' => SubmissionStatus::Accepted,
        'decision_notified_at' => now(),
    ])->save();

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('changeDecision', $this->first, [
            'decision' => Decision::AcceptedPoster->value,
            'note' => 'Room change.',
        ])
        ->assertHasNoTableActionErrors();

    expect($this->first->fresh()?->decision)->toBe(Decision::AcceptedPoster)
        ->and($this->first->fresh()?->decision_notified_at)->toBeNull()
        ->and($this->first->fresh()?->decisions()->count())->toBe(2);
});

it('shows the decision and its history on the submission view', function () {
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('decide', $this->first, ['decision' => Decision::Waitlisted->value, 'note' => 'Second round.']);

    livewire(ViewSubmission::class, ['record' => $this->first->getRouteKey()])
        ->assertSee(Decision::Waitlisted->getLabel())
        ->assertSee('Second round.')
        ->assertSee($this->user->name);
});

it('refuses a decision to somebody with no role in this organization', function () {
    $outsider = User::factory()->create();
    $otherOrganization = Organization::factory()->approved()->create();
    $otherOrganization->addMember($outsider, OrganizationRole::Owner);

    actingAs($outsider);
    bootOrganizerPanel($otherOrganization);

    expect(Gate::forUser($outsider)->allows('decide', $this->first))->toBeFalse();

    // And the page itself is a 404, which ConferenceRankingTest already pins;
    // this is the second gate, on the action rather than on the route.
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $theirs = withoutTenant(fn (): Submission => Submission::factory()->submitted()->create());

    expect(Gate::forUser($this->user)->allows('decide', $theirs))->toBeFalse();
});

it('refuses to decide a withdrawn abstract even from the panel', function () {
    // The action is hidden for a withdrawn row - but the row is not on the
    // ranking at all (RankedSubmissions), so this pins the gate that matters:
    // the action re-checks the blockers before writing.
    $this->second->forceFill(['status' => SubmissionStatus::Withdrawn, 'withdrawn_at' => now()])->save();

    expect(app(\App\Actions\Decisions\ApplyDecision::class)
        ->blockers($this->second->fresh() ?? $this->second, Decision::AcceptedOral))
        ->not->toBe([]);
});
```

with `use Illuminate\Support\Facades\Gate;` in the imports (`ConferenceStatus`, `Conference` and `ConferenceRanking` are already there).

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ApplyDecisionTest.php tests/Feature/Organizer/DecisionsTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -20 /tmp/t.log
```

Expected: `rc=1`, naming `App\Actions\Decisions\ApplyDecision`.

- [ ] **Step 2: `ApplyDecision`**

`app/Actions/Decisions/ApplyDecision.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Decisions;

use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\SubmissionStatus;
use App\Exceptions\DecisionNotAcceptable;
use App\Models\Submission;
use App\Models\SubmissionDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Spec 5.6: "Applied per row or in bulk. Each decision records who and when and
 * appends to `SubmissionDecision`."
 *
 * Same shape as PublishConference and SubmitAbstract: `blockers()` is a pure
 * read the panel calls to decide whether to show an action and what to say when
 * it cannot, `handle()` re-checks and throws, because the panel is a
 * convenience and a hand-made Livewire call is not.
 *
 * **The change-after-send rule**, which is spec 5.6's "so decisions can be
 * prepared quietly first" read to the end:
 *
 * - `decision_notified_at` is null: change it as often as you like. Every
 *   change appends a history row, and nothing has left the building.
 * - `decision_notified_at` is set: a plain call is REFUSED. The author is
 *   holding an email that says something else, and flipping the column would
 *   leave the application and that inbox disagreeing with nothing on screen
 *   saying so.
 * - `$changeAfterSend` is the explicit way through, behind its own action and
 *   its own modal. It appends a row, writes the new decision, and puts
 *   `decision_notified_at` back to null so the next send queues a new letter.
 *   The superseded history row keeps its own letter columns, which is the
 *   audit.
 *
 * **Unchanged is not refused.** Applying the decision a row already has does
 * nothing at all - no row, no timestamp, no activity entry. A bulk decision
 * over a filtered list hits this constantly, and treating it as an error is how
 * an organizer learns to ignore the report.
 */
class ApplyDecision
{
    /**
     * @return list<string> empty when the decision may be applied
     */
    public function blockers(
        Submission $submission,
        Decision $decision,
        bool $changeAfterSend = false,
        ?string $note = null,
    ): array {
        $reasons = [];

        $conference = $submission->conference;

        if ($conference === null) {
            // Conference soft deletes and the foreign key only restricts a
            // *hard* delete, so this relation resolves to null while the row
            // survives - the case Submission::isOpenToAuthor() already guards.
            return [__('decisions.errors.no_conference')];
        }

        if (! in_array($conference->status, [ConferenceStatus::Reviewing, ConferenceStatus::Decided], true)) {
            $reasons[] = __('decisions.errors.wrong_conference_status', [
                'status' => mb_strtolower($conference->status->getLabel()),
            ]);
        }

        if ($submission->status === SubmissionStatus::Draft) {
            $reasons[] = __('decisions.errors.draft');
        }

        if ($submission->status === SubmissionStatus::Withdrawn) {
            $reasons[] = __('decisions.errors.withdrawn');
        }

        // ANY write to a row whose author has already been written to needs the
        // explicit change-and-resend path - including one that only edits the
        // committee note. handle() clears decision_notified_at unconditionally,
        // so a note-only edit would put the row back in the send queue and the
        // next "Send decision emails" would queue a SECOND letter, rotating the
        // author's status token again, with no modal and no warning. Guarding
        // on `decision !== $decision` misses exactly that case, and the bulk
        // action is the live path: decideSelected() has no per-row visible()
        // the way decide() does.
        //
        // A call that changes NOTHING is still not an error: re-applying the
        // same decision with the same note over a filtered list is the normal
        // post-send case, and refusing it is how an organizer learns to ignore
        // the report. isUnchanged() is the same predicate handle() uses to
        // return early, so the two cannot disagree.
        if ($submission->decision_notified_at !== null
            && ! $changeAfterSend
            && ! $this->isUnchanged($submission, $decision, $note)) {
            $reasons[] = __('decisions.errors.already_notified');
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Returns the refreshed submission. A call that changes nothing returns it
     * untouched, which is what makes the bulk report's `unchanged` count
     * meaningful.
     */
    public function handle(
        Submission $submission,
        Decision $decision,
        User $actor,
        ?string $note = null,
        bool $changeAfterSend = false,
    ): Submission {
        $reasons = $this->blockers($submission, $decision, $changeAfterSend, $note);

        if ($reasons !== []) {
            throw new DecisionNotAcceptable($reasons);
        }

        if ($this->isUnchanged($submission, $decision, $note)) {
            return $submission;
        }

        $previous = $submission->decision;

        DB::transaction(function () use ($submission, $decision, $actor, $note): void {
            $row = new SubmissionDecision;
            $row->forceFill([
                'submission_id' => $submission->getKey(),
                'decision' => $decision,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'note' => $note,
            ])->save();

            $submission->forceFill([
                'decision' => $decision,
                // Written together, always: two views of one fact, and a row
                // whose decision is accepted_oral while its status is still
                // under_review is a row every screen disagrees about.
                'status' => $decision->submissionStatus(),
                // Null whenever the decision changes, whether or not it had
                // been sent: an unsent row stays unsent, and a sent row goes
                // back into the send queue so the author hears the new answer.
                'decision_notified_at' => null,
            ])->save();
        });

        activity()
            ->performedOn($submission)
            ->causedBy($actor)
            ->withProperties([
                'decision' => $decision->value,
                'previous' => $previous?->value,
                // Never the note: it is the committee's own reasoning about a
                // named person's work, and the activity log is readable by the
                // platform admin. The note lives on the decision row, behind
                // SubmissionDecisionPolicy.
            ])
            ->log('submission.decided');

        return $submission->refresh();
    }

    /**
     * "Already this decision" means the decision AND the note agree: an
     * organizer who reopens the modal only to add a sentence of reasoning has
     * changed something, and that something belongs in the history.
     */
    private function isUnchanged(Submission $submission, Decision $decision, ?string $note): bool
    {
        if ($submission->decision !== $decision) {
            return false;
        }

        $current = $submission->currentDecision();

        return $current !== null && (string) $current->note === (string) $note;
    }
}
```

- [ ] **Step 3: `ApplyDecisions`**

`app/Actions/Decisions/ApplyDecisions.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Decisions;

use App\Enums\Decision;
use App\Exceptions\DecisionNotAcceptable;
use App\Models\Submission;
use App\Models\User;

/**
 * Spec 5.6's "or in bulk", with a per-row report.
 *
 * The report is the point. Filament's own `authorizeIndividualRecords()`
 * collapses every refusal into a count
 * (vendor/filament/actions/src/Concerns/InteractsWithSelectedRecords.php:77),
 * and "2 rows were skipped" is precisely the message that makes an organizer
 * re-click until something breaks. Here the three outcomes are three different
 * answers - applied, unchanged, refused with a sentence - and the action prints
 * the third by reference so the organizer knows which rows to go and look at.
 *
 * Every row goes through ApplyDecision, so the rules live in exactly one place;
 * this class is a loop, a counter and a report.
 */
class ApplyDecisions
{
    public function __construct(private readonly ApplyDecision $applyDecision) {}

    /**
     * @param  iterable<Submission>  $submissions
     * @return array{applied: int, unchanged: int, refused: array<string, list<string>>}
     */
    public function handle(
        iterable $submissions,
        Decision $decision,
        User $actor,
        ?string $note = null,
        bool $changeAfterSend = false,
    ): array {
        $applied = 0;
        $unchanged = 0;
        /** @var array<string, list<string>> $refused */
        $refused = [];

        foreach ($submissions as $submission) {
            // The row is keyed by its reference, which is what an organizer can
            // find on screen. A draft has none, so fall back to the ULID rather
            // than to an empty key that would collapse two rows into one.
            $key = (string) ($submission->reference ?? $submission->ulid);

            $before = $submission->decision;
            $beforeNote = (string) ($submission->currentDecision()?->note ?? '');

            try {
                $this->applyDecision->handle($submission, $decision, $actor, $note, $changeAfterSend);
            } catch (DecisionNotAcceptable $exception) {
                $refused[$key] = $exception->reasons;

                continue;
            }

            // ApplyDecision returns the row untouched when nothing changed, so
            // the difference is read from what was there before rather than
            // from a second return value nobody else needs.
            if ($before === $decision && $beforeNote === (string) $note) {
                $unchanged++;

                continue;
            }

            $applied++;
        }

        return ['applied' => $applied, 'unchanged' => $unchanged, 'refused' => $refused];
    }

    /**
     * The report as one sentence for a Filament notification. Built here so the
     * row action, the bulk action and any future caller say the same thing, and
     * so the escaping rule is applied once: Filament renders a notification
     * body through Str::sanitizeHtml(), whose shared config keeps `style` and
     * `class` on every element (Plan 2 fact 15), and a reference is
     * organizer-supplied text.
     *
     * @param  array{applied: int, unchanged: int, refused: array<string, list<string>>}  $report
     */
    public static function summarise(array $report): string
    {
        $parts = [__('decisions.bulk.applied', ['count' => $report['applied']])];

        if ($report['unchanged'] > 0) {
            $parts[] = __('decisions.bulk.unchanged', ['count' => $report['unchanged']]);
        }

        if ($report['refused'] !== []) {
            $lines = [];

            foreach ($report['refused'] as $reference => $reasons) {
                $lines[] = e($reference).' — '.e(implode(' ', $reasons));
            }

            $parts[] = __('decisions.bulk.refused', [
                'count' => count($report['refused']),
                'rows' => implode('; ', $lines),
            ]);
        }

        return implode(' ', $parts);
    }
}
```

- [ ] **Step 4: The panel actions**

`app/Filament/Organizer/Resources/Conferences/Tables/DecisionActions.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Tables;

use App\Actions\Decisions\ApplyDecision;
use App\Actions\Decisions\ApplyDecisions;
use App\Enums\Decision;
use App\Exceptions\DecisionNotAcceptable;
use App\Models\Submission;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/**
 * One definition of each decision action, the same pattern Plan 2's
 * ConferenceStatusActions and Plan 3's SubmissionActions established: the rules
 * must not drift between the row, the bulk selection and the submission view.
 */
class DecisionActions
{
    /**
     * The four choices plus a note, shared by the decide and change modals so
     * the second cannot quietly grow a fifth option.
     *
     * @return list<Radio|Textarea>
     */
    private static function schema(): array
    {
        return [
            Radio::make('decision')
                ->label(__('decisions.actions.decision'))
                ->options(self::options())
                ->required()
                // Not a Select: four options, each of which an organizer has to
                // read, and a radio group shows all four at once rather than
                // making "rejected" a scroll away from "accepted".
                ->inline(false),
            Textarea::make('note')
                ->label(__('decisions.actions.note'))
                ->helperText(__('decisions.actions.note_help'))
                ->rows(3)
                ->maxLength(2000),
        ];
    }

    /** @return array<string, string> */
    private static function options(): array
    {
        $options = [];

        foreach (Decision::inReportOrder() as $decision) {
            $options[$decision->value] = $decision->getLabel();
        }

        return $options;
    }

    /**
     * The plain decision, for a row whose author has not been told anything
     * yet. Hidden - not disabled - once the letter has gone, with
     * changeDecision() taking its place, so the two are never both on offer.
     *
     * `$mayDecide` is passed IN rather than asked per row. `decide` depends
     * only on the conference's organization, and User::roleIn() runs a query on
     * every call (app/Models/User.php:95-100), so a Gate::allows() inside a row
     * action's visible() is one query per RENDERED row - 500 rows is 500
     * queries and it breaks the query-count ceiling in
     * ConferenceRankingPerformanceTest. The page asks once and hands the answer
     * down (ConferenceRanking::mayDecide()). A per-request boolean, never a
     * static cache: with RefreshDatabase every test restarts ids at 1, so a
     * cached answer for conference 1 would silently answer a later test with a
     * different actor.
     */
    public static function decide(bool $mayDecide): Action
    {
        return Action::make('decide')
            ->label(__('decisions.actions.decide'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('primary')
            ->modalHeading(__('decisions.actions.decide_heading'))
            ->modalDescription(__('decisions.actions.decide_description'))
            ->modalSubmitActionLabel(__('decisions.actions.decide_submit'))
            ->schema(self::schema())
            ->fillForm(fn (Submission $record): array => [
                'decision' => $record->decision?->value,
                'note' => $record->currentDecision()?->note,
            ])
            ->visible(fn (Submission $record): bool => $record->decision_notified_at === null && $mayDecide)
            // The per-row Gate has not gone anywhere - it has moved to the
            // write path, where it runs once per click rather than once per
            // rendered row (self::apply() calls Gate::authorize()).
            ->action(function (Submission $record, array $data, ApplyDecision $apply): void {
                self::apply($record, $data, $apply, changeAfterSend: false);
            });
    }

    /**
     * Spec 5.6's "so decisions can be prepared quietly first", read to the end:
     * once the letter is out, changing the decision is a second email to a
     * person who is holding the first one, and it says so before it does it.
     *
     * Takes `$mayDecide` for the same reason decide() does.
     */
    public static function changeDecision(bool $mayDecide): Action
    {
        return Action::make('changeDecision')
            ->label(__('decisions.actions.change'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('decisions.actions.change_heading'))
            ->modalDescription(__('decisions.actions.change_description'))
            ->modalSubmitActionLabel(__('decisions.actions.change_submit'))
            ->schema(self::schema())
            ->fillForm(fn (Submission $record): array => [
                'decision' => $record->decision?->value,
                'note' => $record->currentDecision()?->note,
            ])
            ->visible(fn (Submission $record): bool => $record->decision_notified_at !== null && $mayDecide)
            ->action(function (Submission $record, array $data, ApplyDecision $apply): void {
                self::apply($record, $data, $apply, changeAfterSend: true);
            });
    }

    public static function decideSelected(): BulkAction
    {
        return BulkAction::make('decideSelected')
            ->label(__('decisions.actions.decide_selected'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('primary')
            ->modalHeading(__('decisions.actions.bulk_heading'))
            ->modalDescription(__('decisions.actions.bulk_description'))
            ->modalSubmitActionLabel(__('decisions.actions.decide_submit'))
            ->schema(self::schema())
            ->deselectRecordsAfterCompletion()
            ->action(function (BulkAction $action, array $data, ApplyDecisions $apply): void {
                /** @var User $actor */
                $actor = auth()->user();

                $decision = Decision::from((string) $data['decision']);
                $note = filled($data['note'] ?? null) ? (string) $data['note'] : null;

                // Authorization per row, through the same Gate the row action's
                // write path uses. It stays per row HERE, unlike the row
                // actions' visible(), because this loop runs once per click
                // over a selection rather than once per rendered row - the
                // query-count budget is untouched by it. Not
                // authorizeIndividualRecords(): the report below has to
                // distinguish "not yours" from "already emailed" from
                // "withdrawn", and Filament's helper counts rather than
                // explains (fact 9).
                $allowed = [];

                /** @var Submission $record */
                foreach ($action->getSelectedRecords() as $record) {
                    if (Gate::allows('decide', $record)) {
                        $allowed[] = $record;
                    }
                }

                $report = $apply->handle($allowed, $decision, $actor, $note);

                // Built in steps rather than one chain: Notification has no
                // color() in 5.8.1 (only status(), danger(), info(), success(),
                // warning() - vendor/filament/notifications/src/Concerns/
                // HasStatus.php:11-38) and persistent() takes NO argument
                // (Concerns/HasDuration.php:30), so `->persistent($condition)`
                // is a Larastan level 6 error even though PHP would tolerate
                // the extra argument.
                $notification = Notification::make()
                    ->title(__('decisions.bulk.title'))
                    ->body(ApplyDecisions::summarise($report));

                if ($report['refused'] === []) {
                    $notification->success();
                } else {
                    // A toast that vanishes takes the list of references with
                    // it, and those references are the whole point of the
                    // report.
                    $notification->warning()->persistent();
                }

                $notification->send();
            });
    }

    /**
     * The one write path for both single-row modals, so the refusal wording and
     * the success wording cannot drift.
     *
     * @param  array<string, mixed>  $data
     */
    private static function apply(Submission $record, array $data, ApplyDecision $apply, bool $changeAfterSend): void
    {
        Gate::authorize('decide', $record);

        /** @var User $actor */
        $actor = auth()->user();

        $decision = Decision::from((string) $data['decision']);
        $note = filled($data['note'] ?? null) ? (string) $data['note'] : null;

        try {
            $apply->handle($record, $decision, $actor, $note, $changeAfterSend);
        } catch (DecisionNotAcceptable $exception) {
            // Filament renders a notification body as sanitised HTML whose
            // shared config keeps `style` and `class` (Plan 2 fact 15). These
            // sentences are ours, but escaping them costs nothing and means a
            // later sentence that interpolates a title is already safe.
            Notification::make()
                ->danger()
                ->title(__('decisions.actions.refused'))
                ->body(e($exception->getMessage()))
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('decisions.actions.applied', ['decision' => $decision->getLabel()]))
            ->send();
    }

    /**
     * The record actions of the ranking table, in the order an organizer meets
     * them. The two are mutually exclusive by `visible()`, so only one decision
     * control is ever on a row.
     *
     * The authorization answers are arguments, computed once per page render:
     * see decide()'s docblock. Task 7 adds a second one for the resend.
     *
     * @return list<Action>
     */
    public static function rowActions(bool $mayDecide): array
    {
        return [static::decide($mayDecide), static::changeDecision($mayDecide)];
    }
}
```

**`BulkAction::deselectRecordsAfterCompletion()`** comes from `Filament\Actions\Concerns\CanDeselectRecordsAfterCompletion`; if the name differs in 5.8.1, drop the call rather than guessing — the tests do not depend on it. The `Table $table` import is unused unless you add a `Table::selectable()` call; delete it if Pint flags it.

`app/Filament/Organizer/Resources/Conferences/Pages/ConferenceRanking.php` — add to the table builder, after Task 5's `->headerActions([...])`:

```php
            ->recordActions(DecisionActions::rowActions($this->mayDecide()))
            ->toolbarActions([
                DecisionActions::decideSelected(),
            ])
```

and the one authorization answer the whole table shares, as a method on the page:

```php
    /**
     * One authorization answer for the whole table, asked once per render.
     *
     * `decide` depends only on the conference's organization
     * (SubmissionPolicy::decide() is view()), and User::roleIn() is a query, so
     * a Gate call inside a row action's visible() would be one query per
     * rendered row: 500 rows is 500 queries and it breaks the query-count
     * ceiling in ConferenceRankingPerformanceTest. A probe carrying the loaded
     * conference asks the real policy without a row, so the answer is the
     * policy's, not a re-implementation of it.
     */
    protected function mayDecide(): bool
    {
        $probe = new Submission;
        $probe->setRelation('conference', $this->getConference());

        return Gate::allows('decide', $probe);
    }
```

with `use App\Filament\Organizer\Resources\Conferences\Tables\DecisionActions;` added (`Submission` and `Gate` are already imported by Tasks 4 and 5).

- [ ] **Step 5: The decision on the submission view**

`app/Filament/Organizer/Resources/Submissions/Schemas/SubmissionInfolist.php` — add a section between `Extra answers` and `Files`:

```php
            Section::make(__('decisions.infolist.heading'))
                // Only when there is one. A "Decision: none" panel on every
                // abstract in an open conference is a row of empty furniture.
                ->visible(fn (Submission $record): bool => $record->decision !== null)
                ->columns(3)
                ->components([
                    TextEntry::make('decision')->label(__('decisions.infolist.decision'))->badge(),
                    TextEntry::make('decision_notified_at')
                        ->label(__('decisions.infolist.notified'))
                        ->dateTime('j M Y, H:i')
                        ->timezone(fn (Submission $record): string => $record->conference->timezone)
                        ->description(fn (Submission $record): string => $record->conference->timezone)
                        ->placeholder(__('decisions.infolist.not_sent')),
                    TextEntry::make('score')
                        ->label(__('decisions.infolist.score'))
                        ->numeric(2)
                        ->placeholder('—')
                        ->description(fn (Submission $record): string => __('decisions.infolist.reviews', [
                            'count' => (int) $record->review_count,
                        ])),
                    TextEntry::make('decision_history')
                        ->label(__('decisions.infolist.history'))
                        ->listWithLineBreaks()
                        ->columnSpanFull()
                        ->state(fn (Submission $record): array => $record->decisions()->with('decidedBy')->get()
                            ->map(fn (SubmissionDecision $row): string => trim(implode(' ', array_filter([
                                $row->decided_at?->copy()->setTimezone($record->conference->timezone)->format('j M Y, H:i'),
                                '—',
                                $row->decision->getLabel(),
                                '('.$row->actorName().')',
                                $row->note !== null && $row->note !== '' ? '· '.$row->note : null,
                            ]))))
                            ->all()),
                ]),
```

with `use App\Models\SubmissionDecision;` added (`Submission`, `Section` and `TextEntry` are already imported).

This section is **read-only on purpose**: the decision actions live on the ranking page, where an organizer has the score, the spread and the neighbouring rows in front of them. Deciding one abstract in isolation, from its own page, is how a programme ends up with fourteen orals in one track.

- [ ] **Step 6: The language keys**

Append to `lang/en/decisions.php`:

```php
    'actions' => [
        'decision' => 'Decision',
        'note' => 'Note for the committee',
        'note_help' => 'Only organizers see this. The author reads the decision letter, not this note.',
        'decide' => 'Decide',
        'decide_selected' => 'Decide selected',
        'decide_submit' => 'Save decision',
        'decide_heading' => 'Decide this abstract',
        'decide_description' => 'Nothing is emailed now. Decisions go out when you click "Send decision emails".',
        'change' => 'Change decision and resend',
        'change_heading' => 'Change a decision the author has already been told?',
        'change_description' => 'This author has already received a decision letter. Changing the decision queues a second letter with the new answer, and both are kept in the history.',
        'change_submit' => 'Change and queue a new letter',
        'bulk_heading' => 'Decide the selected abstracts',
        'bulk_description' => 'The same decision is applied to every selected abstract that can take it. Nothing is emailed now.',
        'applied' => 'Decision saved: :decision',
        'refused' => 'Not decided',
    ],

    'bulk' => [
        'title' => 'Decisions applied',
        'applied' => 'Decided :count.',
        'unchanged' => ':count already had that decision.',
        'refused' => ':count refused: :rows',
    ],

    'infolist' => [
        'heading' => 'Decision',
        'decision' => 'Decision',
        'notified' => 'Letter sent',
        'not_sent' => 'Not sent yet',
        'score' => 'Score',
        'reviews' => 'from :count review(s)',
        'history' => 'History',
    ],

    'errors' => [
        'no_conference' => 'This abstract has no conference any more, so it cannot be decided.',
        'wrong_conference_status' => 'Decisions can only be made while a conference is under review or decided; this one is :status.',
        'draft' => 'This abstract was never submitted, so there is nothing to decide.',
        'withdrawn' => 'This abstract was withdrawn by its author and cannot be decided.',
        'already_notified' => 'This author has already been sent a decision letter. Use "Change decision and resend" if the decision really has changed.',
    ],
```

- [ ] **Step 7: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ApplyDecisionTest.php tests/Feature/Organizer/DecisionsTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -10 /tmp/t.log && \
php artisan test tests/Feature/Organizer/ConferenceRankingPerformanceTest.php > /tmp/perf.log 2>&1; echo "perf rc=$?"; tail -5 /tmp/perf.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: all three `rc=0`; 24 passed in the first two files (`ApplyDecisionTest`'s twelve `it()` blocks, one of which is a four-row dataset, so fifteen; `DecisionsTest`'s nine) and `baseline + 109` in the third. Six of those cases are the ones this task's rules live or die by, and none of them is decoration: `it('refuses a note-only edit on a row the author has already been told about')` and `it('still treats an identical re-apply on a notified row as unchanged, not refused')` are the two halves of the change-after-send guard; `it('summarises a bulk run in one sentence')` is the only exercise of `ApplyDecisions::summarise()`; `it('decides a whole selection at once, skips what it may not touch, and says so')` pins the refusal path through the panel; and `it('ignores a foreign record id handed to the bulk decision')` and `it('ignores a foreign record id handed to the row decision')` are the cross-tenant negatives on the one path where the client supplies primary keys.

**The `perf rc=0` is a real gate in this task, not a leftover.** Task 6 puts two actions on every rendered row, and a `Gate::allows()` inside either `visible()` is one query per row — 500 rows breaks the `count($queries) < 20` ceiling. If it goes red, an action is authorizing per row: fix the action (pass the answer in, as Step 4 does), never raise the ceiling.

**If `callTableBulkAction('decideSelected', …)` throws `LogicException` about selected records**, the `BulkAction` lost its `accessSelectedRecords()` — that is `setUp()`'s job on `Filament\Actions\BulkAction` (`vendor/filament/actions/src/BulkAction.php:7-13`), so the cause is almost always a plain `Action` where a `BulkAction` was meant.

**If `it('shows the decision and its history on the submission view')` fails on the actor's name**, `SubmissionDecision::decidedBy` is not eager-loaded and `actorName()` fell back to the translated placeholder — check the `->with('decidedBy')` in the state closure.

- [ ] **Step 8: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(decisions): apply one or many, with a per-row report and a change-after-send rule

A decision is not a promise until the letter is sent: before that it changes
freely, after it only through an explicit change-and-resend that appends to the
history and re-queues the letter. The bulk action reports applied, unchanged and
refused by reference rather than collapsing all three into a count.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 7: `SendDecisionEmails` — the four `decision_*` keys, stored letters and a typed confirmation

Spec 5.6: "Decision emails use the matching template and are sent when the organizer clicks 'Send decision emails' (so decisions can be prepared quietly first)." Spec 5.9 names the four keys; Plan 3 shipped their defaults and their editor and sends none of them. This task sends them.

**One thing this task has to own, and it is not small: sending a decision email rotates the author's status link.**

`{{status_link}}` is one of the seven placeholders `EmailTemplateKey::DecisionAcceptedOral` declares, all four platform defaults use it, and the author needs it — the letter lives on the page it points at. But spec section 9 stores the access token as a SHA-256 hash and the plaintext exists only inside the emailed link (`submissions.access_token_hash`, `App\Support\Tokens\SubmissionToken`). Nothing in the database can reconstruct the token that was in the confirmation email. So there are exactly three options, and two of them are worse:

1. **Omit `{{status_link}}` from decision emails.** The author gets a letter with no way back to their abstract, and the four platform defaults — already written, already reviewed — all have to be rewritten. The decision letter becomes the one email in this application that is a dead end.
2. **Store the plaintext token.** Refused by spec section 9, which is the whole reason the column is a hash.
3. **Mint a new token when the letter is sent**, which is what `IssueSubmissionToken` does and what Plan 3's "Resend status link" already does for the same reason. The older confirmation-email link stops resolving; the decision email carries one that works.

This plan takes (3), **once per row**, and says so in three places: in `SendOneDecisionEmail`'s docblock, in the runbook's email section, and in the send-emails modal itself, so the organizer clicking the button knows what it does to the links they may have been told about. Every decided author receives a letter, so every decided author receives a live link. It is recorded in Task 11's backlog as an **owner question** with the two alternatives spelled out: a second, longer-lived "decision link" column, or accepting more than one live token per submission.

**Why the letter is rendered twice and stored once.** `SendTemplatedEmail::handle()` renders internally and returns an `EmailLog` whose `subject` is the *delivered* (and `/s/`-redacted) one, but it does not hand back the body — the body is the email. So `SendOneDecisionEmail` calls `RenderEmailTemplate::handle()` itself for the Markdown it stores and then `SendTemplatedEmail::handle()` for the send. Both renders read the same template row and the same value bag inside the same request, and `RenderEmailTemplate` is a pure function of those two, so **the stored letter is the delivered letter in every value but one**: `status_link` is deliberately withheld from the stored render, because the letter is kept for ever and the token in it is live until the next send. Option 2 above — "store the plaintext token" — is refused by spec section 9, and a `letter_markdown` carrying the live `/s/{64}` link *is* that refusal broken: `submission_decisions` would become a plaintext bearer-token store for every notified author, in a column no backup, no future admin screen and no log dump treats as a credential, while `SendTemplatedEmail` already redacts `/s/{64}` out of every logged *subject* for exactly this reason (`app/Actions/Mail/SendTemplatedEmail.php:52`). `RenderEmailTemplate::fill()` skips a null value with `isset()` (`app/Actions/Mail/RenderEmailTemplate.php:118-120`), so `{{status_link}}` is left literal in the stored copy, and Task 8 fills it in from the token the reader already holds in their own URL. The alternative — widening `SendTemplatedEmail`'s return type so every caller carries a body it does not want — is a change to the one pipeline every email in this application goes through, to serve one caller.

**Idempotence, and what "skipped" means.** The query is "this conference, under consideration, has a decision, `decision_notified_at` is null". Sending sets the timestamp, so a second click finds nothing. A row whose corresponding author has no usable address is **skipped and reported and left with a null timestamp**, so an organizer who fixes the address and clicks again reaches it — the same rule `SendSubmissionStatusLink` already applies, and the failure `Mailable::setAddress()` silently produces otherwise.

**Files:**
- Create: `app/Actions/Decisions/SendOneDecisionEmail.php`, `app/Actions/Decisions/SendDecisionEmails.php`
- Modify: `app/Filament/Organizer/Resources/Conferences/Tables/DecisionActions.php` (two new actions, and `rowActions()` gains a second argument), `app/Filament/Organizer/Resources/Conferences/Pages/ConferenceRanking.php` (one **table** header action, one row action), `app/Filament/Organizer/Resources/Conferences/Pages/ConferenceEmailTemplates.php` (four "Sent when" sentences), `lang/en/decisions.php`
- Test: `tests/Feature/Organizer/DecisionEmailsTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Organizer/DecisionEmailsTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Decisions\ApplyDecision;
use App\Actions\Decisions\SendDecisionEmails;
use App\Actions\Mail\SaveEmailTemplate;
use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\EmailTemplateKey;
use App\Enums\OrganizationRole;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceRanking;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;
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
        'status' => ConferenceStatus::Reviewing,
    ]);

    $this->accepted = Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral)
        ->withCorrespondingAuthor('sara@example.org', 'Dr Sara Al-Harbi')
        ->create(['title' => 'Early mobilisation after cardiac surgery']);
    $this->accepted->forceFill(['reference' => 'AAM26-001'])->save();

    $this->rejected = Submission::factory()->for($this->conference)->decided(Decision::Rejected)
        ->withCorrespondingAuthor('omar@example.org', 'Dr Omar Khan')
        ->create(['title' => 'A second abstract']);
    $this->rejected->forceFill(['reference' => 'AAM26-002'])->save();

    $this->undecided = Submission::factory()->for($this->conference)->submitted()
        ->withCorrespondingAuthor('nobody@example.org', 'Dr Nobody')
        ->create(['title' => 'Still waiting']);
    $this->undecided->forceFill(['reference' => 'AAM26-003'])->save();
});

it('sends one letter per decided abstract, using the matching template', function () {
    $report = app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    expect($report['sent'])->toBe(2)
        ->and($report['skipped'])->toBe([]);

    Mail::assertQueued(TemplatedMail::class, 2);

    // The right key per decision, which is the whole point of
    // Decision::templateKey().
    expect(EmailLog::query()->pluck('template_key')->sort()->values()->all())
        ->toBe([EmailTemplateKey::DecisionAcceptedOral->value, EmailTemplateKey::DecisionRejected->value])
        ->and(EmailLog::query()->pluck('to_email')->sort()->values()->all())
        ->toBe(['omar@example.org', 'sara@example.org']);

    // Nothing went to the abstract with no decision.
    Mail::assertNotQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('nobody@example.org'));
});

it('fills every placeholder the key declares, and links to a working status page', function () {
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    $decision = $this->accepted->fresh()?->currentDecision();

    expect($decision?->letter_markdown)->toBeString()
        ->toContain('Dr Sara Al-Harbi')
        ->toContain('Early mobilisation after cardiac surgery')
        ->toContain('AAM26-001')
        ->toContain('Alpha Annual Meeting')
        ->toContain(Decision::AcceptedOral->getLabel())
        // Every placeholder resolved except the one that is withheld on
        // purpose. RenderEmailTemplate leaves an unknown placeholder literal,
        // so a missing value shows up as `{{...}}` here rather than as a hole
        // in an author's letter.
        ->not->toContain('{{author_name}}')
        ->not->toContain('{{reference}}')
        ->not->toContain('{{conference}}')
        ->not->toContain('{{decision}}');

    // The STORED letter must not be a copy of the author's credential: it is
    // kept for ever, and the token in it is live until the next send. Task 8
    // fills the link in from the token the reader already has in their URL.
    expect((string) $decision?->letter_markdown)
        ->toContain('{{status_link}}')
        ->not->toMatch('#/s/[A-Za-z0-9]{64}#');

    // The link the AUTHOR received really opens the status page - which is the
    // whole reason the token is rotated when the letter is sent. The plaintext
    // now exists in exactly one place, the queued message, which is the point.
    $plain = null;

    Mail::assertQueued(TemplatedMail::class, function (TemplatedMail $mail) use (&$plain): bool {
        if (preg_match('#/s/([A-Za-z0-9]{64})#', $mail->body, $matches) === 1) {
            $plain = $matches[1];
        }

        return true;
    });

    expect($plain)->toBeString();

    $this->get('/s/'.$plain)->assertOk();
});

it('rotates the status token, which kills the older link', function () {
    $before = $this->accepted->access_token_hash;

    app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    // Spec section 9 stores the token hashed, so there is no way to put a
    // working link in a second email except to mint a new one - the same rule
    // Plan 3's "Resend status link" follows. Recorded in the runbook and as an
    // owner question.
    expect($this->accepted->fresh()?->access_token_hash)->not->toBe($before);
});

it('stamps the submission and the decision row, and is safe to run again', function () {
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    $submission = $this->accepted->fresh();

    expect($submission?->decision_notified_at)->not->toBeNull()
        ->and($submission?->currentDecision()?->notified_at)->not->toBeNull()
        ->and($submission?->currentDecision()?->letter_subject)->toBeString()->not->toBe('');

    $report = app(SendDecisionEmails::class)->handle($this->conference->fresh() ?? $this->conference, $this->user);

    expect($report['sent'])->toBe(0);
    Mail::assertQueued(TemplatedMail::class, 2);
});

it('sends each letter once even when two runs overlap on the same rows', function () {
    // The double-click / two-organizers case. The models are NOT refreshed
    // between the runs, so the second run sees exactly the state the first one
    // started from - which is what a second overlapping request sees too. The
    // conditional-UPDATE claim in SendOneDecisionEmail is the only thing that
    // makes this two letters and not four; without it both runs would mint a
    // token (IssueSubmissionToken REPLACES the hash) and the link in the first
    // pair of letters would already be dead.
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    Mail::assertQueued(TemplatedMail::class, 2);

    expect(EmailLog::query()->count())->toBe(2)
        ->and($this->accepted->fresh()?->decisions()->count())->toBe(1);
});

it('writes one audit entry per letter, naming who sent it', function () {
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    // Spec section 9: the decision trail is auditable, and sending is the step
    // that rotates an author's access token. The run has its own entry; each
    // letter has one too, so "who re-sent this letter and rotated this author's
    // link" has an answer.
    expect(Activity::query()->where('description', 'submission.decision_letter_sent')->count())->toBe(2)
        ->and(Activity::query()->where('description', 'conference.decisions_sent')->count())->toBe(1);

    $entry = Activity::query()
        ->where('description', 'submission.decision_letter_sent')
        ->latest('id')
        ->first();

    expect($entry?->causer_id)->toBe($this->user->id)
        ->and($entry?->getExtraProperty('token_rotated'))->toBeTrue()
        // Never the address and never the token: this log is readable by the
        // platform admin.
        ->and(json_encode($entry?->properties))->not->toContain('sara@example.org');
});

it('sends nothing once the conference is off the public site, and leaves the tokens alone', function () {
    $before = $this->accepted->access_token_hash;
    $this->conference->forceFill(['status' => ConferenceStatus::Archived])->save();

    // An archived conference's status page is a 404
    // (app/Livewire/Public/SubmissionStatus.php:65-74), so a letter carrying a
    // fresh {{status_link}} into it is a promise nothing keeps - and minting
    // that link would kill the one the author already has. The ranking page
    // stays visible for Archived on purpose; sending from it does not.
    $report = app(SendDecisionEmails::class)->handle($this->conference->fresh() ?? $this->conference, $this->user);

    expect($report['sent'])->toBe(0)
        ->and(array_keys($report['skipped']))->toBe(['AAM26-001', 'AAM26-002'])
        ->and($this->accepted->fresh()?->access_token_hash)->toBe($before)
        ->and($this->accepted->fresh()?->decision_notified_at)->toBeNull();

    Mail::assertNothingQueued();
});

it('queues a second letter after a decision is changed and resent', function () {
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);
    Mail::assertQueued(TemplatedMail::class, 2);

    // The change-after-send rule, end to end: ApplyDecision (Task 6) puts the
    // row back in the queue by nulling decision_notified_at, and the next click
    // picks it up because pending() filters on that column. Two tasks own the
    // two halves, so only this case proves the join - without it an
    // implementer who forgot the null-out, or who filtered pending() on the
    // decision ROW instead of the column, would ship a corrected decision that
    // is never re-sent and nothing would go red.
    app(ApplyDecision::class)->handle(
        $this->accepted->fresh() ?? $this->accepted,
        Decision::Rejected,
        $this->user,
        'The programme was rebuilt.',
        changeAfterSend: true,
    );

    $report = app(SendDecisionEmails::class)->handle($this->conference->fresh() ?? $this->conference, $this->user);

    expect($report['sent'])->toBe(1);
    Mail::assertQueued(TemplatedMail::class, 3);

    $submission = $this->accepted->fresh();

    expect($submission?->decisions()->count())->toBe(2)
        ->and($submission?->currentDecision()?->decision)->toBe(Decision::Rejected)
        ->and($submission?->currentDecision()?->letter_markdown)->toBeString()
        // decisions() is newest first, so last() is the superseded row - it
        // keeps the letter it announced, which is the audit.
        ->and($submission?->decisions()->get()->last()?->letter_markdown)->toBeString()
        ->and(EmailLog::query()->where('template_key', EmailTemplateKey::DecisionRejected->value)->count())->toBe(2);
});

it('uses the conference override when the organizer has written one', function () {
    app(SaveEmailTemplate::class)->handle(
        $this->conference,
        EmailTemplateKey::DecisionAcceptedOral,
        'Your abstract {{reference}} at {{conference}}',
        "Dear {{author_name}},\n\nThe committee's answer is **{{decision}}**.\n\n[Your abstract]({{status_link}})",
    );

    app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    $decision = $this->accepted->fresh()?->currentDecision();

    expect($decision?->letter_markdown)->toContain("The committee's answer is")
        ->and($decision?->letter_subject)->toBe('Your abstract AAM26-001 at Alpha Annual Meeting');
});

it('keeps the letter that was sent even after the template is rewritten', function () {
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);
    $sent = $this->accepted->fresh()?->currentDecision()?->letter_markdown;

    app(SaveEmailTemplate::class)->handle(
        $this->conference,
        EmailTemplateKey::DecisionAcceptedOral,
        'Completely different subject',
        'Completely different body for {{author_name}}.',
    );

    // The whole reason the Markdown is stored rather than re-rendered on read.
    expect($this->accepted->fresh()?->currentDecision()?->letter_markdown)->toBe($sent);
});

it('skips an abstract with no usable author address and leaves it in the queue', function () {
    SubmissionAuthor::query()->where('submission_id', $this->rejected->id)->update(['email' => '']);

    $report = app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    expect($report['sent'])->toBe(1)
        ->and(array_keys($report['skipped']))->toBe(['AAM26-002'])
        // Null, not stamped: fix the address and click again.
        ->and($this->rejected->fresh()?->decision_notified_at)->toBeNull();
});

it('stops at the chunk size and says how many are left', function () {
    config()->set('cass.decisions.send_chunk', 1);

    $report = app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    expect($report['sent'])->toBe(1)
        ->and($report['remaining'])->toBe(1);

    Mail::assertQueued(TemplatedMail::class, 1);
});

// --- The panel ----------------------------------------------------------

it('sends every letter from the ranking page behind a typed confirmation', function () {
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('sendDecisionEmails', data: ['confirm' => __('decisions.send.confirm_word')])
        ->assertHasNoTableActionErrors();

    Mail::assertQueued(TemplatedMail::class, 2);
});

it('refuses the send when the confirmation word is wrong', function () {
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('sendDecisionEmails', data: ['confirm' => 'yes please'])
        ->assertHasTableActionErrors(['confirm']);

    Mail::assertNothingQueued();
});

it('resends one letter from its own row', function () {
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);
    $firstLetter = $this->accepted->fresh()?->currentDecision()?->notified_at;

    $this->travel(1)->minutes();

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('resendDecision', $this->accepted->fresh())
        ->assertHasNoTableActionErrors();

    Mail::assertQueued(TemplatedMail::class, 3);

    // A resend does not append to the history - the decision has not changed -
    // it overwrites the letter on the row that is already current, because the
    // author is now holding the newer email.
    expect($this->accepted->fresh()?->decisions()->count())->toBe(1)
        ->and($this->accepted->fresh()?->currentDecision()?->notified_at?->greaterThan($firstLetter))->toBeTrue();
});

it('offers the send only to an owner or an admin', function () {
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    actingAs($member);
    bootOrganizerPanel($this->organization);

    // Spec section 4 lets a plain member decide; sending a letter to every
    // author in the conference is narrowed to owner/admin and recorded as an
    // owner question.
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->assertTableActionHidden('sendDecisionEmails')
        ->assertTableActionVisible('decide', $this->undecided);
});

it('sends nothing for another organization conference', function () {
    $theirs = withoutTenant(function (): Conference {
        $conference = Conference::factory()->create(['status' => ConferenceStatus::Reviewing]);
        $submission = Submission::factory()->for($conference)->decided(Decision::AcceptedOral)
            ->withCorrespondingAuthor('stranger@example.org')->create();
        $submission->forceFill(['reference' => 'XXX26-001'])->save();

        return $conference;
    });

    app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    Mail::assertNotQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('stranger@example.org'));
    expect($theirs->submissions()->first()?->decision_notified_at)->toBeNull();
});
```

**`$mail->hasTo(...)`** is `Illuminate\Mail\Mailable::hasTo()`; `TemplatedMail` extends `Mailable` and Plan 3's `tests/Feature/Mail/TemplatedMailTest.php` already asserts against it. If Plan 3 used a different idiom, copy that one. **`$mail->body`** is `TemplatedMail`'s public promoted property (`app/Mail/TemplatedMail.php:37`) — the delivered Markdown, which is where the plaintext `/s/` token now lives and the only place it lives.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/DecisionEmailsTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -20 /tmp/t.log
```

Expected: `rc=1`, naming `App\Actions\Decisions\SendDecisionEmails`.

- [ ] **Step 2: `SendOneDecisionEmail`**

`app/Actions/Decisions/SendOneDecisionEmail.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Decisions;

use App\Actions\Mail\RenderEmailTemplate;
use App\Actions\Mail\SendTemplatedEmail;
use App\Actions\Submissions\IssueSubmissionToken;
use App\Exceptions\DecisionNotAcceptable;
use App\Models\EmailLog;
use App\Models\Submission;
use App\Models\SubmissionDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One decision letter: claim the row, render it, queue it, store it.
 *
 * Split out of SendDecisionEmails because the per-row "Resend" needs exactly
 * this and nothing else, and a resend that took a different path from the bulk
 * send is how the stored letter and the delivered letter come apart.
 *
 * **This action rotates the author's status token, and that is not a side
 * effect - it is the only way to put a working link in the letter.** Spec
 * section 9 stores `submissions.access_token_hash` as a SHA-256 of a plaintext
 * that exists only inside an emailed link, so nothing here can reconstruct the
 * token from the confirmation email. IssueSubmissionToken mints a new one and
 * replaces the hash, which kills the older link - exactly as Plan 3's "Resend
 * status link" does, and for the same reason. Every decided author gets a
 * letter, so every decided author gets a live link. The send-emails modal says
 * so before the organizer clicks, and the runbook says so again.
 *
 * **The letter is rendered twice and stored once, and the two renders differ in
 * exactly one value.** SendTemplatedEmail renders internally and returns an
 * EmailLog carrying the delivered (and /s/-redacted) subject, but not the body -
 * the body IS the email. So the Markdown is rendered here for storage and the
 * subject is taken from the log, which is what the author actually saw.
 * RenderEmailTemplate is a pure function of the template row and the value bag,
 * both unchanged between the two calls inside one request.
 *
 * The one difference is `status_link`, which is withheld from the STORED render.
 * The delivered body carries the real link - that is the email. The stored body
 * must not: this task's own option 2, "store the plaintext token", is refused by
 * spec section 9, and a letter row holding the live /s/ token is that refusal
 * broken - submission_decisions would become a plaintext bearer-token store for
 * every notified author, kept for ever, in a column nothing treats as a
 * credential. RenderEmailTemplate::fill() skips a null value with isset()
 * (app/Actions/Mail/RenderEmailTemplate.php:118-120), so `{{status_link}}` stays
 * literal in the stored copy and Task 8 substitutes it from the token the reader
 * already has in their URL.
 *
 * Widening SendTemplatedEmail's return type to serve one caller would change the
 * one pipeline every email in this application goes through.
 */
class SendOneDecisionEmail
{
    public function __construct(
        private readonly RenderEmailTemplate $render,
        private readonly SendTemplatedEmail $send,
        private readonly IssueSubmissionToken $issueToken,
    ) {}

    /**
     * @return list<string> empty when the letter can be sent
     */
    public function blockers(Submission $submission): array
    {
        if ($submission->decision === null) {
            return [__('decisions.errors.not_decided')];
        }

        if ($submission->conference === null) {
            return [__('decisions.errors.no_conference')];
        }

        // The letter's whole point is the link in it, so the send is gated on
        // the exact condition the status page enforces rather than on a
        // hand-copied status list: SubmissionStatus::mount() aborts 404 unless
        // the conference is publicly visible AND its organization is approved
        // (app/Livewire/Public/SubmissionStatus.php:65-74, with
        // ConferenceStatus::isPublic() at app/Enums/ConferenceStatus.php:69-71
        // excluding Archived). Sending from an archived conference - or one
        // whose organization was suspended - rotates the author's token to
        // produce a link that does not open, and kills the old one on the way.
        // The ranking page is deliberately visible for Archived (Task 4) so a
        // committee can still read what it decided; SENDING from it is not, and
        // this is the same intent as ApplyDecision's own conference-status
        // window, expressed against the condition that actually breaks.
        if (! $submission->conference->isPubliclyVisible()
            || $submission->conference->organization?->isApproved() !== true) {
            return [__('decisions.errors.conference_not_sending', [
                'status' => mb_strtolower($submission->conference->status->getLabel()),
            ])];
        }

        if ($submission->currentDecision() === null) {
            // The denormalised column without a history row: only reachable by
            // a hand-written UPDATE, and a letter with no row to store itself
            // on would silently lose the audit.
            return [__('decisions.errors.no_history')];
        }

        $author = $submission->correspondingAuthor();

        // Not just null. Mailable::setAddress() silently drops an empty
        // address and the message then fails inside the queue worker with "An
        // email must have a To, Cc, or Bcc header", leaving an email_logs row
        // stuck at `queued` and nobody told - the exact failure
        // SendSubmissionStatusLink guards against.
        if ($author === null || filter_var((string) $author->email, FILTER_VALIDATE_EMAIL) === false) {
            return [__('decisions.errors.no_author_email')];
        }

        return [];
    }

    public function handle(Submission $submission, ?User $actor = null): EmailLog
    {
        $reasons = $this->blockers($submission);

        if ($reasons !== []) {
            // Every refusal happens HERE, before the claim below, so a skipped
            // row is left exactly as pending() found it and a second click
            // reaches it once the address (or the conference status) is fixed.
            throw new DecisionNotAcceptable($reasons);
        }

        /** @var \App\Enums\Decision $decision */
        $decision = $submission->decision;
        /** @var SubmissionDecision $row */
        $row = $submission->currentDecision();
        $conference = $submission->conference;
        $author = $submission->correspondingAuthor();

        $key = $decision->templateKey();

        // CLAIM the row before the token is rotated or anything is queued. A
        // conditional UPDATE is the only thing that makes a double click, two
        // organizers clicking at once, or a retried request idempotent:
        // SendDecisionEmails reads pending() and the stamp used to be written
        // only after the mail was queued, so two overlapping runs both saw the
        // same rows, both minted a token - IssueSubmissionToken REPLACES the
        // hash - and both queued a letter, leaving the author with two emails
        // of which the first one's link was already dead. The second caller now
        // matches zero rows and skips instead.
        $previousNotifiedAt = $submission->decision_notified_at;
        $claimedAt = now();

        $claimed = Submission::query()
            ->whereKey($submission->getKey())
            ->where('decision', $decision->value)
            ->when(
                $previousNotifiedAt === null,
                fn (Builder $query): Builder => $query->whereNull('decision_notified_at'),
                fn (Builder $query): Builder => $query->where('decision_notified_at', $previousNotifiedAt),
            )
            ->update(['decision_notified_at' => $claimedAt]);

        if ($claimed === 0) {
            // Somebody else is sending this row, or the decision changed under
            // us since pending() read it.
            throw new DecisionNotAcceptable([__('decisions.errors.already_sending')]);
        }

        try {
            $token = $this->issueToken->handle($submission);
            $values = self::placeholderValues($submission, (string) $author?->name, $decision, $token);

            // The DELIVERED body carries the real link - that is the email.
            $log = $this->send->handle($key, $conference, (string) $author?->email, $values, $submission);

            // The STORED body must not. `status_link => null` makes
            // RenderEmailTemplate::fill() skip the placeholder with isset()
            // (app/Actions/Mail/RenderEmailTemplate.php:118-120), so
            // `{{status_link}}` is left literal and Task 8 substitutes it from
            // the token the reader already holds in their own URL. A
            // letter_markdown carrying the live /s/{64} token would make
            // submission_decisions a plaintext bearer-token store for every
            // notified author - option 2 of this task's own three, refused by
            // spec section 9.
            $stored = $this->render->handle($key, $conference, ['status_link' => null] + $values);

            DB::transaction(function () use ($submission, $row, $stored, $log, $claimedAt): void {
                $row->forceFill([
                    // The DELIVERED subject: SendTemplatedEmail redacts any /s/
                    // token out of it before both the log row and the message
                    // (app/Actions/Mail/SendTemplatedEmail.php:52), so this is
                    // what the author saw in their inbox.
                    'letter_subject' => $log->subject,
                    'letter_markdown' => $stored->body,
                    'notified_at' => $claimedAt,
                ])->save();

                // The claim above already wrote decision_notified_at; re-saving
                // the stale in-memory model would clobber a concurrent claim.
                // syncOriginal() so a later line in the same request reads the
                // fresh value without a second query.
                $submission->forceFill(['decision_notified_at' => $claimedAt])->syncOriginal();
            });
        } catch (\Throwable $exception) {
            // Put the row back the way pending() found it. A claim that
            // outlives a failed send is a row nobody will ever be told about
            // again - worse than the duplicate it was guarding against.
            Submission::query()
                ->whereKey($submission->getKey())
                ->where('decision_notified_at', $claimedAt)
                ->update(['decision_notified_at' => $previousNotifiedAt]);

            throw $exception;
        }

        // One activity entry per letter, in the action that sends it, so the
        // bulk path and the resend path cannot record different things. Spec
        // section 9 wants the decision trail auditable, and this is the step
        // that rotates the author's access token - the most consequential thing
        // an organizer does to a person who has no account. The
        // conference-level `conference.decisions_sent` entry stays: that one is
        // the run, this one is the row. The actor is PASSED IN, never read from
        // auth() here, which is the rule every activity() call in app/ follows.
        activity()
            ->performedOn($submission)
            ->causedBy($actor)
            ->withProperties([
                'decision' => $decision->value,
                'email_log_ulid' => (string) $log->ulid,
                // Never the address and never the token: the activity log is
                // readable by the platform admin. email_logs already holds the
                // recipient, behind EmailLogPolicy.
                'token_rotated' => true,
            ])
            ->log('submission.decision_letter_sent');

        return $log;
    }

    /**
     * The spec 5.9 placeholder bag for the four decision keys, which declare
     * exactly: author_name, title, reference, conference, organization,
     * decision, status_link (app/Enums/EmailTemplateKey.php:71-74). One method
     * so a template that starts using one of them does not need a second call
     * site updated - the same shape as
     * SubmitAbstract::placeholderValues().
     *
     * @return array<string, string|null>
     */
    public static function placeholderValues(
        Submission $submission,
        string $authorName,
        \App\Enums\Decision $decision,
        string $token,
    ): array {
        $conference = $submission->conference;

        return [
            'author_name' => $authorName,
            'title' => (string) $submission->title,
            'reference' => $submission->reference,
            'conference' => (string) $conference?->name,
            'organization' => (string) $conference?->organization?->name,
            // The sentence fragment an author reads: "has been **accepted for
            // oral presentation**".
            'decision' => $decision->getLabel(),
            'status_link' => $submission->statusUrl($token),
        ];
    }
}
```

Pint will want `\App\Enums\Decision` and `\Throwable` imported; write `use App\Enums\Decision;` and `use Throwable;` and use the short names.

**The order inside `handle()` is load-bearing and is not a style choice.** `blockers()` first, so a refusal never stamps anything; then the conditional-`UPDATE` claim, so two overlapping clicks cannot both mint a token; then the send; then the stored render; and the `catch` releases the claim so a failure leaves the row exactly as `pending()` found it. Moving the claim after the token mint re-opens the double-send; dropping the `catch` turns a queue failure into a row nobody will ever be told about.

- [ ] **Step 3: `SendDecisionEmails`**

`app/Actions/Decisions/SendDecisionEmails.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Decisions;

use App\Enums\Decision;
use App\Exceptions\DecisionNotAcceptable;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\User;
use App\Support\Scoring\RankedSubmissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Spec 5.6: "Decision emails use the matching template and are sent when the
 * organizer clicks 'Send decision emails' (so decisions can be prepared quietly
 * first)."
 *
 * The whole action is one query and one loop:
 *
 *   under consideration + has a decision + has never been notified
 *
 * Sending stamps `decision_notified_at`, so a second click finds nothing: the
 * action is idempotent by its own query rather than by a flag somebody has to
 * remember to check. A change-after-send puts the timestamp back to null
 * (ApplyDecision), which is how a corrected decision re-enters this queue.
 *
 * A row whose corresponding author has no usable address is **skipped,
 * reported, and left unstamped**, so fixing the address and clicking again
 * reaches it.
 *
 * The run is bounded by `cass.decisions.send_chunk`, because the whole loop
 * happens inside one php-fpm request (docker/php.ini's 60-second budget) and
 * each row is a render, a token mint, an email_logs insert and a queue push.
 * The report says how many are left so the organizer clicks again rather than
 * wondering.
 */
class SendDecisionEmails
{
    public function __construct(private readonly SendOneDecisionEmail $sendOne) {}

    /**
     * The rows a click would send to, newest decisions last so the order is
     * stable across chunks.
     *
     * @return Builder<Submission>
     */
    public function pending(Conference $conference): Builder
    {
        return RankedSubmissions::query($conference)
            ->whereNotNull('decision')
            ->whereNull('decision_notified_at')
            ->orderBy('id');
    }

    /**
     * How many letters each decision would send, for the confirmation modal.
     * One grouped query.
     *
     * @return array<string, int>
     */
    public function counts(Conference $conference): array
    {
        /** @var array<string, int> $byDecision */
        $byDecision = $this->pending($conference)
            ->reorder()
            ->selectRaw('decision, count(*) as aggregate')
            ->groupBy('decision')
            ->pluck('aggregate', 'decision')
            ->all();

        $counts = [];

        foreach (Decision::inReportOrder() as $decision) {
            $counts[$decision->value] = (int) ($byDecision[$decision->value] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array{sent: int, skipped: array<string, list<string>>, remaining: int}
     */
    public function handle(Conference $conference, User $actor): array
    {
        $chunk = max(1, (int) config('cass.decisions.send_chunk'));

        $sent = 0;
        /** @var array<string, list<string>> $skipped */
        $skipped = [];

        /** @var Submission $submission */
        foreach ($this->pending($conference)->limit($chunk)->get() as $submission) {
            $key = (string) ($submission->reference ?? $submission->ulid);

            try {
                // The actor goes down with it: SendOneDecisionEmail writes one
                // `submission.decision_letter_sent` entry per letter, so the
                // bulk run and the per-row resend record the same thing about
                // the same act.
                $this->sendOne->handle($submission, $actor);
            } catch (DecisionNotAcceptable $exception) {
                $skipped[$key] = $exception->reasons;

                continue;
            }

            $sent++;
        }

        if ($sent > 0) {
            // The RUN, as one entry. The per-letter entries are written by
            // SendOneDecisionEmail; this one answers "who pressed the button".
            activity()
                ->performedOn($conference)
                ->causedBy($actor)
                ->withProperties(['sent' => $sent, 'skipped' => count($skipped)])
                ->log('conference.decisions_sent');
        }

        return [
            'sent' => $sent,
            'skipped' => $skipped,
            // Counted after the send, so it is "what is left", including the
            // rows this run skipped.
            'remaining' => $this->pending($conference)->count(),
        ];
    }

    /**
     * The report as one sentence for a Filament notification, escaped once
     * here for the reason Plan 2 fact 15 records.
     *
     * @param  array{sent: int, skipped: array<string, list<string>>, remaining: int}  $report
     */
    public static function summarise(array $report): string
    {
        $parts = [__('decisions.send.sent', ['count' => $report['sent']])];

        if ($report['skipped'] !== []) {
            $lines = [];

            foreach ($report['skipped'] as $reference => $reasons) {
                $lines[] = e($reference).' — '.e(implode(' ', $reasons));
            }

            $parts[] = __('decisions.send.skipped', [
                'count' => count($report['skipped']),
                'rows' => implode('; ', $lines),
            ]);
        }

        if ($report['remaining'] > 0) {
            $parts[] = __('decisions.send.remaining', ['count' => $report['remaining']]);
        }

        return implode(' ', $parts);
    }
}
```

- [ ] **Step 4: The two panel actions**

`app/Filament/Organizer/Resources/Conferences/Tables/DecisionActions.php` — add:

```php
    /**
     * Spec 5.6's button. A typed confirmation rather than a plain "are you
     * sure": this queues an email to a named person for every decided abstract
     * in the conference, it cannot be undone, and it rotates every one of those
     * authors' status links. The modal shows the count per decision, so the
     * organizer confirms a number they have read rather than a dialog they have
     * dismissed.
     */
    public static function sendDecisionEmails(Conference $conference): Action
    {
        return Action::make('sendDecisionEmails')
            ->label(__('decisions.send.action'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->modalHeading(__('decisions.send.heading'))
            ->modalDescription(fn (): string => __('decisions.send.description'))
            ->modalSubmitActionLabel(__('decisions.send.submit'))
            ->modalWidth('2xl')
            // `app(...)` inside the closure rather than a typed closure
            // parameter: service injection into an ACTION closure is proven in
            // this repo (SubmissionActions::resendLink takes
            // SendSubmissionStatusLink that way), but a SCHEMA closure is
            // evaluated by the schema and is not the same code path. One
            // resolve, no risk.
            ->schema(fn (): array => [
                Placeholder::make('counts')
                    ->label(__('decisions.send.counts'))
                    ->content(function () use ($conference): HtmlString {
                        $counts = app(SendDecisionEmails::class)->counts($conference);
                        $lines = [];

                        foreach (Decision::inReportOrder() as $decision) {
                            $lines[] = '<div>'.e($decision->getLabel()).': <strong>'.$counts[$decision->value].'</strong></div>';
                        }

                        return new HtmlString(implode('', $lines));
                    }),
                Placeholder::make('token_warning')
                    ->label(__('decisions.send.link_warning_label'))
                    ->content(__('decisions.send.link_warning')),
                TextInput::make('confirm')
                    ->label(__('decisions.send.confirm_label', ['word' => __('decisions.send.confirm_word')]))
                    ->required()
                    // Wrapped in a closure that RETURNS the rule. Filament
                    // evaluates a Closure rule as a callback
                    // (Forms\Components\Concerns\CanBeValidated::getRules()), so
                    // an unwrapped custom rule is called by the closure
                    // evaluator and throws BindingResolutionException on its
                    // `string $attribute` parameter - the idiom
                    // ConferenceEmailTemplates::editAction() already uses.
                    ->rule(fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                        if (mb_strtoupper(trim((string) $value)) !== mb_strtoupper(__('decisions.send.confirm_word'))) {
                            $fail(__('decisions.send.confirm_failed', ['word' => __('decisions.send.confirm_word')]));
                        }
                    }),
            ])
            // Hidden, not merely refused, when the conference is off the public
            // site: SubmissionStatus::mount() 404s on it, so every {{status_link}}
            // in the letters would be dead on arrival and the old links would
            // have been killed to produce them (SendOneDecisionEmail::blockers()
            // refuses the same case). The ranking page stays visible for an
            // archived conference on purpose; this button does not.
            //
            // `sendDecisions` is asked with the CONFERENCE, which is why it
            // lives on ConferencePolicy: Laravel resolves the policy from the
            // first argument's class.
            ->visible(fn (): bool => $conference->isPubliclyVisible()
                && Gate::allows('sendDecisions', $conference))
            ->action(function (SendDecisionEmails $send) use ($conference): void {
                Gate::authorize('sendDecisions', $conference);

                /** @var User $actor */
                $actor = auth()->user();

                $report = $send->handle($conference, $actor);

                $notification = Notification::make()
                    ->title(__('decisions.send.title'))
                    ->body(SendDecisionEmails::summarise($report));

                if ($report['skipped'] === []) {
                    $notification->success();
                } else {
                    $notification->warning()->persistent();
                }

                $notification->send();
            });
    }

    /**
     * One letter again, for the author who says it never arrived. Same path as
     * the bulk send - SendOneDecisionEmail - so the stored letter and the
     * delivered letter cannot come apart between the two, and so the audit
     * entry is the same one.
     *
     * `$maySend` is passed in for the reason decide() explains: a Gate call
     * inside a row action's visible() is one query per rendered row, and the
     * 500-row query-count ceiling bounds exactly that.
     */
    public static function resendDecision(bool $maySend): Action
    {
        return Action::make('resendDecision')
            ->label(__('decisions.send.resend'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('decisions.send.resend_heading'))
            ->modalDescription(__('decisions.send.resend_description'))
            // The same public-visibility condition the bulk send carries, and
            // for the same reason: a fresh link into a conference whose status
            // page 404s is worse than no letter.
            ->visible(fn (Submission $record): bool => $record->decision_notified_at !== null
                && $record->conference?->isPubliclyVisible() === true
                && $maySend)
            ->action(function (Submission $record, SendOneDecisionEmail $send): void {
                /** @var Conference $conference */
                $conference = $record->conference;
                Gate::authorize('sendDecisions', $conference);

                // The actor, resolved here and passed IN, which is how every
                // action in this file hands an actor to the layer below.
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    $log = $send->handle($record, $actor);
                } catch (DecisionNotAcceptable $exception) {
                    Notification::make()
                        ->danger()
                        ->title(__('decisions.send.nothing_sent'))
                        ->body(e($exception->getMessage()))
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('decisions.send.resent'))
                    // An author's address is author-supplied text and Filament
                    // sanitises rather than escapes a notification body.
                    ->body(__('decisions.send.resent_body', ['email' => e($log->to_email)]))
                    ->send();
            });
    }
```

with `use App\Actions\Decisions\SendDecisionEmails;`, `use App\Actions\Decisions\SendOneDecisionEmail;`, `use App\Models\Conference;`, `use Closure;`, `use Filament\Forms\Components\Placeholder;`, `use Filament\Forms\Components\TextInput;` and `use Illuminate\Support\HtmlString;` added, and `rowActions()` becoming:

```php
    /**
     * Both authorization answers are arguments, computed once per page render
     * rather than once per rendered row: see decide()'s docblock and the
     * query-count ceiling in ConferenceRankingPerformanceTest.
     *
     * @return list<Action>
     */
    public static function rowActions(bool $mayDecide, bool $maySend): array
    {
        return [static::decide($mayDecide), static::changeDecision($mayDecide), static::resendDecision($maySend)];
    }
```

`app/Filament/Organizer/Resources/Conferences/Pages/ConferenceRanking.php` — `table()`'s `->headerActions([...])` gains the send action **first**, so it sits where an organizer looks for it, before Task 5's two exports:

```php
                DecisionActions::sendDecisionEmails($conference),
```

and the record actions gain the second answer:

```php
            ->recordActions(DecisionActions::rowActions(
                $this->mayDecide(),
                Gate::allows('sendDecisions', $this->getConference()),
            ))
```

**A TABLE header action, not a page one.** `callTableAction()` and `assertTableAction*()` resolve only against `Table::$flatActions` (`vendor/filament/actions/src/Concerns/InteractsWithActions.php:705` → `vendor/filament/tables/src/Table/Concerns/HasActions.php:20-23`), so a page header action here would make every one of this task's panel tests throw `ActionNotResolvableException` instead of running. It joins the array Task 5 created, for the same reason.

**Note the argument.** A header action has no `$record`, and `Gate::allows('sendDecisions', $conference)` needs the conference — so it is passed in when the action is built. `$conference` is already the local at the top of `table()`, so no extra call is needed. The row actions take `Submission $record` from Filament as usual, and take their two authorization answers as arguments.

- [ ] **Step 5: The email-template editor stops saying "not sent yet"**

`app/Filament/Organizer/Resources/Conferences/Pages/ConferenceEmailTemplates.php` — `sendsWhen()`'s decision arm becomes four separate arms, because they no longer say the same thing:

```php
            EmailTemplateKey::DecisionAcceptedOral => 'To the corresponding author of an abstract accepted for oral presentation, when you send decision emails.',
            EmailTemplateKey::DecisionAcceptedPoster => 'To the corresponding author of an abstract accepted as a poster, when you send decision emails.',
            EmailTemplateKey::DecisionWaitlisted => 'To the corresponding author of a waitlisted abstract, when you send decision emails.',
            EmailTemplateKey::DecisionRejected => 'To the corresponding author of an abstract that was not accepted, when you send decision emails.',
```

(Plan 4 did the same to the three reviewer arms; if its wording differs, match its shape.)

- [ ] **Step 6: The language keys**

Append to `lang/en/decisions.php`:

```php
    'send' => [
        'action' => 'Send decision emails',
        'heading' => 'Send the decision letters?',
        'description' => 'One email goes to the corresponding author of every abstract that has a decision and has not been written to yet. Each letter uses this conference\'s template for that decision. This cannot be undone.',
        'counts' => 'What will be sent',
        'link_warning_label' => 'About the links',
        'link_warning' => 'Each letter carries a fresh private link to the author\'s abstract page, where the letter is also shown. Any older link that author is holding stops working - which is how this application keeps those links secret.',
        // One word, in one place, so an Arabic file changes both the field
        // label and the rule that checks it.
        'confirm_word' => 'SEND',
        'confirm_label' => 'Type :word to confirm',
        'confirm_failed' => 'Type :word exactly to send the letters.',
        'submit' => 'Send the letters',
        'title' => 'Decision letters',
        'sent' => 'Queued :count letter(s).',
        'skipped' => 'Skipped :count: :rows',
        'remaining' => ':count still to send - click again.',
        'resend' => 'Resend the letter',
        'resend_heading' => 'Send this letter again?',
        'resend_description' => 'The author gets the same decision again, rendered from the template as it stands now, and a fresh private link. Any older link they hold stops working.',
        'resent' => 'Letter sent again',
        'resent_body' => 'It is on its way to :email.',
        'nothing_sent' => 'Nothing sent',
    ],
```

and to the `errors` group:

```php
        'not_decided' => 'This abstract has no decision yet, so there is no letter to send.',
        'no_history' => 'This abstract has a decision with no history behind it. Decide it again before sending a letter.',
        'no_author_email' => 'This abstract has no corresponding author with a usable email address.',
        'conference_not_sending' => 'This conference is :status and is off the public site, so the link in the letter would not open. Letters cannot be sent from it.',
        'already_sending' => 'This letter is already being sent, or the decision changed while the run was under way.',
```

- [ ] **Step 7: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/DecisionEmailsTest.php tests/Feature/Organizer/ConferenceEmailTemplatesTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -10 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferenceRankingPerformanceTest.php > /tmp/perf.log 2>&1; echo "perf rc=$?"; tail -5 /tmp/perf.log
```

Expected: all three `rc=0`; about 31 passed in the first two files (`DecisionEmailsTest`'s seventeen new cases, plus `ConferenceEmailTemplatesTest`'s own Plan 3-4 ones) and `baseline + 126` in the second. Five of the new cases are the ones this task's rules live or die by: `it('sends each letter once even when two runs overlap on the same rows')` pins the conditional-`UPDATE` claim; `it('writes one audit entry per letter, naming who sent it')` pins spec section 9's trail across both the bulk and resend paths; `it('sends nothing once the conference is off the public site, and leaves the tokens alone')` pins the blocker that stops a letter carrying a dead link; `it('queues a second letter after a decision is changed and resent')` is the only test of the Task 6 / Task 7 join; and `it('fills every placeholder the key declares, and links to a working status page')` now asserts both halves of the stored-letter rule — `{{status_link}}` left literal in the database, and a real working link in the queued message.

**The `perf rc=0` is a gate here too**: Task 7 adds a third action to every rendered row, and a `Gate::allows()` in its `visible()` is one query per row against the `count($queries) < 20` ceiling. Pass the answer in, as Step 4 does; never raise the ceiling.

**`tests/Feature/Organizer/ConferenceEmailTemplatesTest.php` is the file to watch**: it asserts the "Sent when" sentences, and Step 5 changed four of them. If it goes red on a string, update the test to the new sentence — that is a deliberate change, not a regression.

**If `it('fills every placeholder the key declares…')` fails on an unresolved `{{author_name}}` or `{{reference}}`**, that placeholder is missing from the bag: `RenderEmailTemplate` leaves an unknown one literal on purpose, which is exactly what those assertions exist to surface. `{{status_link}}` staying literal in `letter_markdown` is **correct** and asserted — do not "fix" it by rendering the stored copy with the token.

**If the `/s/{token}` request 404s**, the token in the queued message is not the one that was stored on the submission — check that `IssueSubmissionToken` runs *before* `placeholderValues()`, which is the ordering the code above deliberately has.

**If `it('sends each letter once even when two runs overlap…')` queues four**, the claim is not a conditional `UPDATE` — a `forceFill()->save()` on the in-memory model always writes and never tells you whether it won.

- [ ] **Step 8: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(decisions): send the four decision letters, and keep each one

Each letter is rendered once, at send time, and stored on the decision it
announced, so a later template edit cannot rewrite what an author was told. The
send is idempotent through decision_notified_at, bounded by a chunk size, and
behind a typed confirmation that shows the count per decision - and it says out
loud that it rotates every notified author's status link, because a hashed token
leaves no other way to put a working one in the letter.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 8: The decision letter on `/s/{token}`

Spec 5.3 step 5: "Status page `/s/{token}` shows current status, files, and allows edit or withdraw until the deadline. **After decisions, it shows the decision letter.**" Plan 3 left one `@elseif` there with a comment naming this task, and its backlog entry says the same.

**Three rules the page follows, and the reason for each.**

**1. The letter shown is the one that was sent, not a fresh render.** `submission_decisions.letter_markdown` was written at send time (Task 7). An organizer who edits `decision_accepted_oral` next March must not silently change what an author was told in September, and an author who forwards the page to their head of department must find the same words that are in the email they forwarded last week.

**2. It is safe to render as HTML, and the reason is a chain that already exists.** `RenderEmailTemplate` escapes every substituted value with `VALUE_ESCAPES` **before** substitution and then escapes every `<` in the finished body (`app/Actions/Mail/RenderEmailTemplate.php:38`, `:96-99`), so the stored Markdown cannot contain a tag — not one an organizer typed into the editor, and not one an author typed into their own title. `Illuminate\Mail\Markdown::parse()` is then the same call `ConferenceEmailTemplates::preview()` already makes on the stored body (`:301-310`). Task 8 adds no new escaping decision; it reuses the one Plan 3 made and tested.

**3. A withdrawn abstract never shows a letter.** `Submission::decisionLetter()` (Task 1) returns null for it. An author who withdrew is not waiting for an answer, and a decision printed under a "Withdrawn" banner reads as a reversal of their own choice.

**The enum method gets a name that is true.** `SubmissionStatus::isDrivenInPlan3()` exists for exactly one `@elseif` in one Blade file (fact 23), and from this task on it is false. It becomes `isWithOrganizers()` — true for `under_review`, `accepted`, `rejected` and `waitlisted`, which is "the author has done their part and the organizers have it" — and the view asks it the right way round instead of negating it.

**Files:**
- Modify: `app/Enums/SubmissionStatus.php` (one method renamed), `app/Livewire/Public/SubmissionStatus.php` (one view variable), `resources/views/livewire/public/submission-status.blade.php` (one block), `lang/en/submission.php`
- Test: `tests/Feature/Public/SubmissionStatusPageTest.php` (appended)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Public/SubmissionStatusPageTest.php` (it already has a `beforeEach` building a conference, a submission and a plaintext token — reuse whatever it calls them; the cases below assume `$this->submission` and `$this->token` and a published, approved conference):

```php
// --- The decision letter (Plan 5) ---------------------------------------

it('shows nothing about a decision until the letter has been sent', function () {
    $this->submission->forceFill([
        'status' => SubmissionStatus::Accepted,
        'decision' => Decision::AcceptedOral,
        'decision_notified_at' => null,
    ])->save();

    SubmissionDecision::factory()->for($this->submission)->create([
        'decision' => Decision::AcceptedOral,
        'letter_subject' => 'Prepared but not sent',
        'letter_markdown' => 'This letter has not been sent yet.',
        'notified_at' => null,
    ]);

    // Spec 5.6's "so decisions can be prepared quietly first" reaches all the
    // way to the author's page: a decision that has not been emailed is not
    // visible to the person it is about.
    get('/s/'.$this->token)
        ->assertOk()
        ->assertDontSee('This letter has not been sent yet.')
        ->assertSee(__('submission.status.decision_pending'));
});

it('shows the letter that was sent, rendered', function () {
    $this->submission->forceFill([
        'status' => SubmissionStatus::Accepted,
        'decision' => Decision::AcceptedOral,
        'decision_notified_at' => now(),
    ])->save();

    SubmissionDecision::factory()->for($this->submission)->notified(
        "Dear Dr Sara Al-Harbi,\n\nWe are pleased to tell you that your abstract has been **accepted for oral presentation**.\n\n- **Reference:** AAM26-017",
    )->create(['decision' => Decision::AcceptedOral]);

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee(__('submission.status.decision.heading'))
        ->assertSee('We are pleased to tell you')
        // Rendered, not printed as source: the Markdown became HTML.
        ->assertSee('<strong>accepted for oral presentation</strong>', escape: false)
        ->assertDontSee('**accepted for oral presentation**')
        // ...and the neutral placeholder is gone.
        ->assertDontSee(__('submission.status.decision_pending'));
});

it('cannot be used to inject html through a letter', function () {
    // The letter is stored as RenderEmailTemplate produced it, which escapes
    // every `<` in the finished body (app/Actions/Mail/RenderEmailTemplate.php:96-99)
    // - so a tag cannot be in a real letter. This pins what happens if one ever
    // is: it is printed, not executed.
    $this->submission->forceFill([
        'status' => SubmissionStatus::Rejected,
        'decision' => Decision::Rejected,
        'decision_notified_at' => now(),
    ])->save();

    SubmissionDecision::factory()->for($this->submission)
        ->notified('&lt;script&gt;alert(1)&lt;/script&gt; and a real sentence.')
        ->create(['decision' => Decision::Rejected]);

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee('and a real sentence.')
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('never shows a letter on a withdrawn abstract', function () {
    $this->submission->forceFill([
        'status' => SubmissionStatus::Waitlisted,
        'decision' => Decision::Waitlisted,
        'decision_notified_at' => now(),
    ])->save();

    SubmissionDecision::factory()->for($this->submission)->notified('You are on the waiting list.')
        ->create(['decision' => Decision::Waitlisted]);

    $this->submission->forceFill([
        'status' => SubmissionStatus::Withdrawn,
        'withdrawn_at' => now(),
    ])->save();

    // An author who withdrew is not waiting for an answer, and a letter under a
    // "Withdrawn" banner reads as a reversal of their own choice.
    get('/s/'.$this->token)
        ->assertOk()
        ->assertDontSee('You are on the waiting list.')
        ->assertSee(__('submission.status.withdrawn_notice', [
            'date' => $this->submission->fresh()?->withdrawn_at?->copy()
                ->setTimezone($this->submission->conference->timezone)->format('j F Y, H:i'),
        ]));
});

it('shows the newest letter after a decision was changed and resent', function () {
    $this->submission->forceFill([
        'status' => SubmissionStatus::Accepted,
        'decision' => Decision::AcceptedPoster,
        'decision_notified_at' => now(),
    ])->save();

    SubmissionDecision::factory()->for($this->submission)->notified('The first answer was oral.')
        ->create(['decision' => Decision::AcceptedOral, 'decided_at' => now()->subDay()]);
    SubmissionDecision::factory()->for($this->submission)->notified('The programme changed; it is a poster.')
        ->create(['decision' => Decision::AcceptedPoster, 'decided_at' => now()]);

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee('The programme changed; it is a poster.')
        ->assertDontSee('The first answer was oral.');
});

it('fills the link in the stored letter from the token in the url', function () {
    // Task 7 stores the letter with `{{status_link}}` left literal, so no
    // database row ever holds a live bearer credential. The page fills it in
    // from the token this reader already has in their own URL.
    $this->submission->forceFill([
        'status' => SubmissionStatus::Accepted,
        'decision' => Decision::AcceptedOral,
        'decision_notified_at' => now(),
    ])->save();

    SubmissionDecision::factory()->for($this->submission)
        ->notified('Your abstract: [open it]({{status_link}}).')
        ->create(['decision' => Decision::AcceptedOral]);

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee($this->submission->statusUrl($this->token), escape: false)
        ->assertDontSee('{{status_link}}');
});

it('still lets the author read their abstract and files under a decision', function () {
    $this->submission->forceFill([
        'status' => SubmissionStatus::Accepted,
        'decision' => Decision::AcceptedOral,
        'decision_notified_at' => now(),
    ])->save();

    SubmissionDecision::factory()->for($this->submission)->notified()->create(['decision' => Decision::AcceptedOral]);

    // A decided abstract is closed to the author (SubmissionStatus::isOpenToAuthor()
    // is Draft and Submitted only), so the edit and withdraw buttons are gone -
    // but the page is not a dead end.
    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee($this->submission->title)
        ->assertDontSee(__('submission.status.edit'))
        ->assertDontSee(__('submission.status.withdraw'));
});
```

with `use App\Enums\Decision;` and `use App\Models\SubmissionDecision;` added to the file's imports (`SubmissionStatus`, `Submission` and `use function Pest\Laravel\get;` are already there — a second `use App\Enums\SubmissionStatus;` is a fatal, not a duplicate line to ignore).

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Public/SubmissionStatusPageTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -20 /tmp/t.log
```

Expected: `rc=1`, on the missing `submission.status.decision.heading` key and the letter text.

- [ ] **Step 2: Rename the enum method**

`app/Enums/SubmissionStatus.php` — replace `isDrivenInPlan3()`:

```php
    /**
     * The author has done their part and the organizers have it: under review,
     * or decided. The public status page shows the decision letter for these
     * once it has been sent, and a neutral "we are handling it" line until then.
     *
     * This was `isDrivenInPlan3()`, which named the plan that did not write
     * these statuses rather than what they mean - and from Plan 5 on it is
     * false for every case it was written to describe.
     */
    public function isWithOrganizers(): bool
    {
        return in_array(
            $this,
            [self::UnderReview, self::Accepted, self::Rejected, self::Waitlisted],
            true,
        );
    }
```

`grep -rn isDrivenInPlan3` must return nothing afterwards:

```bash
cd /c/Users/ahmed/Documents/CASS && grep -rn "isDrivenInPlan3" app/ resources/ tests/ database/ || echo "clean"
```

- [ ] **Step 3: The component passes the letter in**

`app/Livewire/Public/SubmissionStatus.php` — `render()` gains one key:

```php
        return view('livewire.public.submission-status', [
            'conference' => $conference,
            'organization' => $organization,
            'theme' => $theme,
            'authors' => $this->submission->authors()->get(),
            'files' => $this->submission->files()->get(),
            'canChange' => $this->submission->isOpenToAuthor(),
            // Spec 5.3 step 5. Null until the organizers have actually sent the
            // letter, and null for ever on a withdrawn abstract - both rules
            // live in Submission::decisionLetter() so this page and any later
            // reader cannot answer differently.
            'letter' => $this->submission->decisionLetter(),
        ])->layout('components.layouts.conference', [
```

- [ ] **Step 4: The view**

`resources/views/livewire/public/submission-status.blade.php` — replace the whole `@elseif (! $submission->status->isDrivenInPlan3())` arm (lines 44-51 on `main`) with two arms, keeping the Draft and Withdrawn arms above it exactly as they are:

```blade
    @elseif ($letter)
        {{-- The letter as it was SENT, not a fresh render: an organizer who
             edits the template next March must not rewrite what this author
             was told in September.

             Rendered rather than escaped, and that is safe because of a chain
             that already exists: RenderEmailTemplate escapes every substituted
             value and then every `<` in the finished body before the letter is
             stored, so no tag can be in one. This is the same
             Markdown::parse() call ConferenceEmailTemplates::preview() makes on
             the same text. --}}
        <section class="mt-6 rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-lg font-semibold">{{ __('submission.status.decision.heading') }}</h2>
            <p class="mt-1 text-sm text-slate-500">
                {{ __('submission.status.decision.sent_on', [
                    'date' => $letter->notified_at?->copy()->setTimezone($conference->timezone)->format('j F Y'),
                ]) }}
            </p>
            <div class="mt-4 text-slate-700 [&_p]:mt-3 [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:mt-3 [&_ol]:list-decimal [&_ol]:pl-5 [&_a]:text-[var(--org-primary)] [&_a]:underline [&_strong]:font-semibold">
                {{-- The stored letter deliberately withholds the token (Task 7):
                     `{{status_link}}` was left literal so submission_decisions
                     never holds a live bearer credential. It is filled in here
                     from the token in THIS reader's own URL, which they already
                     have. --}}
                {!! Illuminate\Mail\Markdown::parse(str_replace(
                    '{{status_link}}',
                    $submission->statusUrl($token),
                    $letter->letter_markdown ?? '',
                )) !!}
            </div>
        </section>
    @elseif ($submission->status->isWithOrganizers())
        <div class="mt-6 rounded-lg border border-slate-300 bg-slate-50 p-4 text-slate-700">
            {{ __('submission.status.decision_pending') }}
        </div>
    @endif
```

**On the Tailwind classes:** the arbitrary variants (`[&_p]:mt-3` and friends) style the HTML that `Markdown::parse()` produces, which has no classes of its own, and they avoid adding the typography plugin for one block. This file is already inside the Tailwind `@source` globs (`resources/css/app.css`), so `npm run build` picks them up — Task 10 runs the build and Task 11's final gate runs it again.

**On `{!! !!}`:** it is the only unescaped echo this plan adds, and rule 2 above is the whole justification. If a reviewer wants a second belt, the honest one is to store the letter's *rendered HTML* instead of its Markdown — which moves the same decision to send time and makes the stored column bigger; it does not remove the decision.

**On the `str_replace`:** `$token` is the component's `#[Locked] public string $token` and `Submission::statusUrl()` is `app/Models/Submission.php:194`, so the link is rebuilt from the credential this reader already holds in their address bar — nothing new is disclosed. The substitution runs *before* `Markdown::parse()`, so the result is a real link rather than a literal `{{status_link}}` in the middle of a sentence, and a letter that never used the placeholder is unaffected.

- [ ] **Step 5: The language keys**

`lang/en/submission.php` — inside the existing `status` group, after `decision_pending`:

```php
        'decision' => [
            'heading' => 'The organizers\' decision',
            'sent_on' => 'Sent to you on :date.',
        ],
```

The existing `decision_pending` line stays exactly as it is: it is now the "we have it, we have not answered yet" case rather than the placeholder it was, and its wording — "They will email you when there is a decision, and the letter will appear here" — becomes a promise the application keeps.

- [ ] **Step 6: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Public/SubmissionStatusPageTest.php tests/Feature/LanguageCoverageTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -10 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`; about 31 passed in the first two files (seven of them new here) and `baseline + 133` in the second. `it('fills the link in the stored letter from the token in the url')` is the new one, and it is the other half of Task 7's stored-letter rule: the token is withheld from the database and rebuilt here from the reader's own URL.

**`tests/Feature/LanguageCoverageTest.php` is the file to watch.** Its `it('leaves no visible english hardcoded in the pages plan 3 added')` compiles this Blade file and strips the PHP, so the rendered-letter `{!! !!}` disappears with it and only the two new `__()` calls remain — but a hardcoded word in the new section would fail it. If it does fail, the fix is a language key, never an entry in that test's `$allowed` list.

- [ ] **Step 7: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
npm run build > /tmp/build.log 2>&1; echo "build rc=$?" && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(public): the decision letter on the author status page

The letter shown is the one that was sent, stored at send time, so a later
template edit cannot rewrite history. Rendered rather than escaped, which is
safe because RenderEmailTemplate escapes every < before the letter is stored -
the same chain the panel preview already relies on. A withdrawn abstract never
shows one.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all four `rc=0`.

---
### Task 9: `Reviewing -> Decided`, decision counts on both panels, and the reviewer's read-only notice

The backlog item Plan 2 left and Plan 4 halved: "`ConferenceStatus::Decided` is in the enum and the transition table but nothing drives it: **Plan 5** moves Reviewing -> Decided."

**Why `MarkDecided` has a blocker list rather than a bare guard.** `PublishConference` set the shape in Plan 2 and `StartReviewing` repeated it in Plan 4: a transition an organizer will get wrong answers with sentences, not a validation code. Everything a conference needs before it can be called decided is knowable:

1. the conference is `reviewing` — `ConferenceStatus::allowedTransitions()` permits `Reviewing -> Decided`, and this action is that step and nothing else;
2. there is at least one abstract under consideration — "decided" over an empty conference means nothing;
3. **every abstract still in `submitted` or `under_review` has a decision.** This is the real blocker. `accepted`, `rejected` and `waitlisted` rows are decided by definition (their status came from `Decision::submissionStatus()`); a row still sitting in `under_review` is one the committee has not answered, and moving the conference on would leave that author waiting for an email that is never coming.

**Unsent letters are a warning, not a blocker, and that is a decision.** A conference may legitimately be marked decided while some letters are still queued — the organizer clicked "Send decision emails", the worker is working through them, and `decision_notified_at` is null for the tail. Blocking on it would make the transition depend on a queue draining. Instead the confirmation modal prints "N letters have not been sent yet" when there are any, with the send button one screen away. Recorded in the backlog so a later plan can tighten it if the owner would rather.

**What `Decided` actually changes.** Plan 4 already wired the consequences: `Conference::acceptsReviewWrites()` is `Reviewing` only, so `SaveReviewDraft`, `SubmitReview` and `ReopenReview` all refuse, and `ReviewSubmission::isReadOnly()` renders the form disabled — while `isOpenToReviewers()` still admits `Decided` and — **after this task's one widened clause in `ReviewerScope`** — a reviewer can still open a decided abstract they reviewed and read what they wrote. What is missing is that **nothing tells the reviewer why**, and that is the small dashboard change this task makes.

**Why `ReviewerScope` has to be widened, and why it is this task's job.** Plan 4's `ReviewerScope::constrain()` admits only `submitted` and `under_review` (`docs/superpowers/plans/2026-09-11-plan-4-reviewers.md:8669`), and it is the single gate behind `SubmissionPolicy::view()`, `SubmissionFilePolicy`, the reviewer queue query and `ReviewSubmission::mount()`'s `abort_unless`. Task 6's `ApplyDecision` writes `accepted`, `rejected` or `waitlisted` onto `submissions.status` — **while the conference is still `reviewing`**, long before `MarkDecided` runs. So from the moment the committee decides an abstract, that abstract and the reviewer's own submitted review of it drop out of every reviewer read path, and by the time `MarkDecided` can succeed *every* abstract has a decision: the reviewer's queue is empty and every review page is a 404, on the very screen this task makes say "you can still open your reviews". Plan 4's own test only covers the read-after-decided case for a submission still in `submitted`, so nothing goes red. One widened clause fixes it, and it is scoped to the reviewer's **own** reviews so a decided abstract nobody reviewed stays out of the pool.

**Files:**
- Create: `app/Actions/Conferences/MarkDecided.php`
- Modify: `app/Support/Reviews/ReviewerScope.php` (Plan 4's file — one widened status clause)
- Modify: `app/Models/Conference.php` (`decisionCounts()`), `app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php` (`markDecided()`), `app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceInfolist.php` (a Decisions section), `app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php` (two columns), `resources/views/filament/reviewer/pages/dashboard.blade.php` (one line), `lang/en/decisions.php`, `lang/en/reviewer.php`
- Test: `tests/Unit/MarkDecidedTest.php`, `tests/Unit/ReviewerScopeTest.php` (Plan 4's file, appended), `tests/Feature/Organizer/ConferenceTransitionsTest.php` (appended), `tests/Feature/Admin/ConferencesTest.php` (appended), `tests/Feature/Reviewer/ReviewerDashboardTest.php` (appended)

- [ ] **Step 0: The failing test for the reviewer scope**

Append to Plan 4's `tests/Unit/ReviewerScopeTest.php`. **Read that file's `beforeEach` first and use its own fixtures** — the case below builds everything it needs from factories so it does not depend on names this plan cannot see, but if the file already has a reviewer and a conference in `open_pool`/`reviewing`, reuse them and delete the duplicates rather than creating a second set.

```php
it('keeps a decided abstract readable for the reviewer who reviewed it, and for nobody else', function () {
    $conference = Conference::factory()->closed()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
    ]);
    $form = ReviewForm::factory()->for($conference)->create(['is_active' => true]);

    $reviewer = User::factory()->create();
    $other = User::factory()->create();
    ConferenceReviewer::factory()->for($conference)->create(['user_id' => $reviewer->id]);
    ConferenceReviewer::factory()->for($conference)->create(['user_id' => $other->id]);

    $submission = Submission::factory()->for($conference)->submitted()->create();

    Review::factory()->create([
        'submission_id' => $submission->getKey(),
        'reviewer_user_id' => $reviewer->getKey(),
        'review_form_id' => $form->getKey(),
        'status' => ReviewStatus::Submitted,
        'submitted_at' => now(),
    ]);

    // Plan 5's ApplyDecision writes submissions.status, which is exactly what
    // drops the row out of Plan 4's scope - and it happens while the conference
    // is still `reviewing`, not at MarkDecided.
    app(ApplyDecision::class)->handle($submission, Decision::Rejected, User::factory()->create());

    $decided = $submission->fresh() ?? $submission;

    expect(ReviewerScope::allows($reviewer, $decided))->toBeTrue()
        // ...while a decided abstract this reviewer never touched is gone from
        // the pool, which is the whole point of the narrowing.
        ->and(ReviewerScope::allows($other, $decided))->toBeFalse()
        ->and(ReviewerScope::submissions($other)->pluck('id')->all())->not->toContain($decided->id);
});
```

with `use App\Actions\Decisions\ApplyDecision;`, `use App\Enums\Decision;`, `use App\Enums\ReviewStatus;`, `use App\Models\Review;`, `use App\Models\ReviewForm;` and `use App\Models\ConferenceReviewer;` added as needed. **Adapt the `Review::factory()` call and `ReviewerScope::allows()` / `::submissions()` to Plan 4's real signatures on `main` if they differ** — the assertion is the point, not the spelling.

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ReviewerScopeTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -20 /tmp/t.log
```

Expected: `rc=1`, with `ReviewerScope::allows($reviewer, $decided)` false — the bug, reproduced, before it is fixed.

- [ ] **Step 0b: The one widened clause**

`app/Support/Reviews/ReviewerScope.php` — in `constrain()`, replace the opening `->whereIn('status', [...])` with:

```php
        $query
            ->where(function (Builder $status) use ($reviewer): void {
                $status
                    ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])
                    // Plan 5 writes accepted/rejected/waitlisted onto a decided
                    // abstract, while the conference is still `reviewing`.
                    // Without this arm the abstract - and the reviewer's own
                    // submitted review of it - leaves every read path the moment
                    // the committee decides, and by the time MarkDecided can
                    // succeed EVERY abstract has a decision, so the reviewer's
                    // queue is empty and every review page a 404 on the very
                    // screen that says otherwise. Scoped to the reviewer's OWN
                    // reviews, so a decided abstract nobody reviewed stays out
                    // of the pool. Writes are still refused by
                    // Conference::acceptsReviewWrites().
                    ->orWhere(fn (Builder $decided): Builder => $decided
                        ->whereIn('status', [
                            SubmissionStatus::Accepted->value,
                            SubmissionStatus::Rejected->value,
                            SubmissionStatus::Waitlisted->value,
                        ])
                        ->whereHas('reviews', fn (Builder $reviews): Builder => $reviews
                            ->where('reviewer_user_id', $reviewer->getKey())));
            })
            ->whereHas('conference', function (Builder $conferenceQuery) use ($reviewer): void {
```

keeping the rest of the method exactly as Plan 4 wrote it, and `use App\Enums\SubmissionStatus;` already being in that file.

**Safe for Plan 4's other callers, checked one at a time.** `QueueTable` prints no status and no decision column (plan-4:9038-9052), so no decision leaks to a reviewer through the queue. The reminder query (plan-4:13204) filters with `whereDoesntHave('reviews', ... reviewer_user_id ...)`, which excludes exactly the rows this arm adds, so no reminder is sent about a decided abstract. And every write path still goes through `Conference::acceptsReviewWrites()`, which this does not touch.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/MarkDecidedTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Conferences\MarkDecided;
use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\SubmissionStatus;
use App\Exceptions\ConferenceNotPublishable;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->conference = Conference::factory()->closed()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->subDay(),
    ]);
});

it('moves a fully decided conference to decided and logs it', function () {
    Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral, notified: true)->create();
    Submission::factory()->for($this->conference)->decided(Decision::Rejected, notified: true)->create();

    $conference = $this->conference->fresh() ?? $this->conference;

    expect(app(MarkDecided::class)->blockers($conference))->toBe([]);

    $result = app(MarkDecided::class)->handle($conference, $this->actor);

    expect($result->status)->toBe(ConferenceStatus::Decided)
        ->and(Activity::query()->where('description', 'conference.decided')->count())->toBe(1);
});

it('refuses while any abstract is still waiting for an answer', function () {
    Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral)->create();
    $waiting = Submission::factory()->for($this->conference)->submitted()->create();
    $waiting->forceFill(['status' => SubmissionStatus::UnderReview])->save();

    $conference = $this->conference->fresh() ?? $this->conference;
    $blockers = app(MarkDecided::class)->blockers($conference);

    expect($blockers)->not->toBe([])
        ->and(implode(' ', $blockers))->toContain('1')
        ->and(fn () => app(MarkDecided::class)->handle($conference, $this->actor))
        ->toThrow(ConferenceNotPublishable::class);

    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Reviewing);
});

it('ignores drafts and withdrawals when it counts what is still open', function () {
    Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral)->create();
    Submission::factory()->for($this->conference)->create();
    Submission::factory()->for($this->conference)->withdrawn()->create();

    // Neither is under consideration (App\Support\Scoring\RankedSubmissions),
    // so neither can hold the conference open.
    expect(app(MarkDecided::class)->blockers($this->conference->fresh() ?? $this->conference))->toBe([]);
});

it('refuses an empty conference and a conference in the wrong status', function () {
    expect(app(MarkDecided::class)->blockers($this->conference))->not->toBe([]);

    $closed = Conference::factory()->closed()->create();
    Submission::factory()->for($closed)->decided(Decision::AcceptedOral)->create();

    expect(app(MarkDecided::class)->blockers($closed->fresh() ?? $closed))->not->toBe([]);
});

it('allows the move while letters are still queued, and says how many', function () {
    Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral, notified: true)->create();
    Submission::factory()->for($this->conference)->decided(Decision::Rejected)->create();

    $conference = $this->conference->fresh() ?? $this->conference;

    // Not a blocker: the queue draining is not a property of the committee's
    // work, and blocking on it would tie a status transition to a worker.
    expect(app(MarkDecided::class)->blockers($conference))->toBe([])
        ->and(app(MarkDecided::class)->unsentLetters($conference))->toBe(1);
});

it('counts the decisions of a conference, one query, with the empty ones at zero', function () {
    Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral, notified: true)->create();
    Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral)->create();
    Submission::factory()->for($this->conference)->decided(Decision::Rejected)->create();
    Submission::factory()->for($this->conference)->submitted()->create();

    $counts = ($this->conference->fresh() ?? $this->conference)->decisionCounts();

    expect($counts['decided'])->toBe(3)
        ->and($counts['undecided'])->toBe(1)
        ->and($counts['notified'])->toBe(1)
        ->and($counts['by_decision'][Decision::AcceptedOral->value])->toBe(2)
        ->and($counts['by_decision'][Decision::AcceptedPoster->value])->toBe(0)
        ->and($counts['by_decision'][Decision::Rejected->value])->toBe(1);
});
```

Append to `tests/Feature/Organizer/ConferenceTransitionsTest.php` (it already has a tenant, an actor and a conference in its `beforeEach`; adapt the names to what is there):

```php
it('marks a conference decided from the panel and refuses when one is still open', function () {
    $conference = Conference::factory()->for($this->organization)->closed()->create([
        'status' => ConferenceStatus::Reviewing,
    ]);
    $open = Submission::factory()->for($conference)->submitted()->create();

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->assertActionVisible('markDecided')
        ->callAction('markDecided');

    // A blocker list is reported, not thrown - the same shape publish() uses.
    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Reviewing);

    $open->forceFill(['decision' => Decision::Rejected, 'status' => SubmissionStatus::Rejected])->save();

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->callAction('markDecided');

    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Decided);
});

it('hides mark-decided outside reviewing', function () {
    $closed = Conference::factory()->for($this->organization)->closed()->create();

    livewire(ViewConference::class, ['record' => $closed->getRouteKey()])
        ->assertActionHidden('markDecided');
});

it('shows the decision counts on the conference view once reviewing has started', function () {
    $conference = Conference::factory()->for($this->organization)->closed()->create([
        'status' => ConferenceStatus::Reviewing,
    ]);
    Submission::factory()->for($conference)->decided(Decision::AcceptedOral, notified: true)->create();

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->assertSee(__('decisions.conference.heading'))
        ->assertSee(Decision::AcceptedOral->getLabel());
});
```

with `use App\Enums\Decision;`, `use App\Enums\SubmissionStatus;` and `use App\Models\Submission;` added to that file's imports — all three are used by the block above and none of them is there on `main` (it imports only `ConferenceStatus`, `OrganizationRole`, `ConferenceResource`, `CreateConference`, `ListConferences`, `ViewConference`, `ConferenceStatusActions`, `CreateDefaultReviewForm`, `Conference`, `Organization`, `User` and `Notification`). `Conference`, `ConferenceStatus`, `ViewConference` and `livewire` are already there.

Append to `tests/Feature/Admin/ConferencesTest.php`:

```php
it('shows read-only decision counts on the admin conference list', function () {
    $conference = Conference::factory()->create(['status' => ConferenceStatus::Reviewing]);
    Submission::factory()->for($conference)->decided(Decision::AcceptedOral, notified: true)->create();
    Submission::factory()->for($conference)->decided(Decision::Rejected)->create();
    Submission::factory()->for($conference)->submitted()->create();

    // `AdminListConferences`, which is the name this file already imports the
    // page under (tests/Feature/Admin/ConferencesTest.php:7) - a bare
    // `ListConferences` here is an unimported global and the case errors before
    // it reaches a column.
    $component = livewire(AdminListConferences::class)
        ->assertCanRenderTableColumn('decided_count')
        ->assertCanRenderTableColumn('notified_count')
        ->assertCanSeeTableRecords([$conference]);

    // The counts as the TABLE computed them, read off the record Filament
    // actually loaded, so a wrong or missing ->counts() constraint fails here.
    // Re-deriving them with a second withCount() in the test would assert the
    // test's own query and pass for a column that renders nothing.
    $row = $component->instance()->getTableRecords()->firstWhere('id', $conference->id);

    expect((int) $row?->decided_count)->toBe(2)
        ->and((int) $row?->notified_count)->toBe(1);
});

it('gives the platform admin no way to decide anything from the conference list', function () {
    $conference = Conference::factory()->create(['status' => ConferenceStatus::Reviewing]);
    Submission::factory()->for($conference)->decided(Decision::AcceptedOral)->create();

    // Spec section 4's platform-admin cells are read-only here, exactly as
    // Plans 3 and 4 left submissions and reviews: the counts are visible and
    // nothing writes. The guard that can actually regress is the table's action
    // list, so that is what is asserted - not `class_exists()` on a class this
    // plan never creates, which is a placeholder assertion that can never be
    // shown failing first and would go red the day Plan 6 adds the read-only
    // admin SubmissionResource the backlog already schedules.
    $table = livewire(AdminListConferences::class)
        ->assertCanSeeTableRecords([$conference])
        ->instance()
        ->getTable();

    // array_merge, not `+`: `+` on two numerically-indexed arrays silently
    // drops every bulk-action name whose index already exists in the first.
    $actions = array_merge(
        array_keys($table->getFlatActions()),
        array_keys($table->getFlatBulkActions()),
    );

    expect($actions)->not->toContain('decide')
        ->not->toContain('changeDecision')
        ->not->toContain('decideSelected')
        ->not->toContain('sendDecisionEmails');
});
```

with `use App\Enums\ConferenceStatus;`, `use App\Enums\Decision;` and `use App\Models\Submission;` added to that file's imports — the list page is already imported there as `AdminListConferences`, which is the name to call, and `Conference` is already there too. No `Illuminate\Database\Eloquent\Builder` import is needed: the counts are read off the table rather than re-derived.

Append to `tests/Feature/Reviewer/ReviewerDashboardTest.php` (Plan 4's file; adapt to its `beforeEach`):

```php
it('tells a reviewer their reviews are read-only once the conference has decided', function () {
    $this->conference->forceFill(['status' => ConferenceStatus::Decided])->save();

    // Plan 4 already refuses every write in `decided`
    // (Conference::acceptsReviewWrites()) and already renders the review form
    // disabled. What was missing is that nothing said WHY, on the one screen a
    // reviewer starts from.
    livewire(Dashboard::class)
        ->assertSee(__('reviewer.dashboard.decided'))
        // Still listed, still openable: isOpenToReviewers() admits Decided so a
        // reviewer can read back what they wrote.
        ->assertSee($this->conference->name)
        ->assertSee(__('reviewer.dashboard.open_queue'));
});

it('does not say that while the conference is still under review', function () {
    livewire(Dashboard::class)->assertDontSee(__('reviewer.dashboard.decided'));
});
```

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/MarkDecidedTest.php tests/Unit/ReviewerScopeTest.php tests/Feature/Organizer/ConferenceTransitionsTest.php tests/Feature/Admin/ConferencesTest.php tests/Feature/Reviewer/ReviewerDashboardTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -20 /tmp/t.log
```

Expected: `rc=1`, naming `App\Actions\Conferences\MarkDecided` (Step 0b has already made `ReviewerScopeTest` green).

- [ ] **Step 2: `MarkDecided`**

`app/Actions/Conferences/MarkDecided.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\ConferenceStatus;
use App\Enums\SubmissionStatus;
use App\Exceptions\ConferenceNotPublishable;
use App\Models\Conference;
use App\Models\User;
use App\Support\Scoring\RankedSubmissions;

/**
 * The `Reviewing -> Decided` half of the lifecycle ConferenceStatus has
 * declared since Plan 2, and the last transition Plan 2's backlog was waiting
 * on. `Decided -> Archived` already exists (ArchiveConference).
 *
 * Same shape as PublishConference and Plan 4's StartReviewing, including the
 * exception type: an organizer meeting a transition they cannot make gets
 * sentences, and the panel action prints them rather than throwing.
 *
 * **What `Decided` means downstream, all of it already wired by Plan 4:**
 * Conference::acceptsReviewWrites() is Reviewing only, so SaveReviewDraft,
 * SubmitReview and ReopenReview all refuse; ReviewSubmission::isReadOnly()
 * renders the form disabled; and isOpenToReviewers() still admits Decided which,
 * together with this task's one widened clause in ReviewerScope::constrain(),
 * is what lets a reviewer open a decided abstract they reviewed and read what
 * they wrote. Decisions themselves
 * stay changeable (ApplyDecision allows Reviewing and Decided) because a
 * presenter withdrawing in week three is a real thing that happens after the
 * letters go out.
 *
 * **Unsent letters do not block.** A conference may be marked decided while the
 * queue is still draining: the organizer clicked send, the worker is working,
 * and decision_notified_at is null for the tail. Blocking would tie a status
 * transition to a worker. unsentLetters() exists so the confirmation modal can
 * say how many, which is the honest middle.
 */
class MarkDecided
{
    /** @return list<string> empty when the conference may be marked decided */
    public function blockers(Conference $conference): array
    {
        $reasons = [];

        if ($conference->status !== ConferenceStatus::Reviewing) {
            // Not canTransitionTo(): the graph also allows Reviewing -> Closed
            // and Reviewing -> Archived, and this action is the one step.
            $reasons[] = __('decisions.mark.errors.wrong_status', [
                'status' => mb_strtolower($conference->status->getLabel()),
            ]);
        }

        $ranked = RankedSubmissions::query($conference);

        if ((clone $ranked)->doesntExist()) {
            $reasons[] = __('decisions.mark.errors.nothing_to_decide');
        }

        // The real blocker. An `accepted`, `rejected` or `waitlisted` row is
        // decided by definition - its status came from
        // Decision::submissionStatus() - so the open ones are exactly those
        // still in `submitted` or `under_review`.
        $open = (clone $ranked)
            ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])
            ->whereNull('decision')
            ->count();

        if ($open > 0) {
            $reasons[] = __('decisions.mark.errors.undecided', ['count' => $open]);
        }

        return array_values(array_unique($reasons));
    }

    /** Decided abstracts whose letter has not been queued yet. A warning, not a blocker. */
    public function unsentLetters(Conference $conference): int
    {
        return RankedSubmissions::query($conference)
            ->whereNotNull('decision')
            ->whereNull('decision_notified_at')
            ->count();
    }

    public function handle(Conference $conference, User $actor): Conference
    {
        $reasons = $this->blockers($conference);

        if ($reasons !== []) {
            throw new ConferenceNotPublishable($reasons);
        }

        $conference->forceFill(['status' => ConferenceStatus::Decided])->save();

        activity()
            ->performedOn($conference)
            ->causedBy($actor)
            ->withProperties(['unsent_letters' => $this->unsentLetters($conference)])
            ->log('conference.decided');

        return $conference->refresh();
    }
}
```

- [ ] **Step 3: `Conference::decisionCounts()`**

`app/Models/Conference.php` — add after `rankingSummary()`:

```php
    /**
     * The decision numbers the conference view and the admin list print. One
     * grouped query plus two aggregates, and a zero for every decision with no
     * rows - a count that disappears when it is zero makes a panel change shape
     * as data arrives.
     *
     * Deliberately separate from rankingSummary(), which also carries the score
     * aggregates the ranking page needs: the conference view wants four numbers
     * and should not pay for an avg() over every abstract to get them.
     *
     * @return array{decided: int, undecided: int, notified: int, by_decision: array<string, int>}
     */
    public function decisionCounts(): array
    {
        $base = RankedSubmissions::query($this);

        /** @var array<string, int> $byDecision */
        $byDecision = (clone $base)
            ->selectRaw('decision, count(*) as aggregate')
            ->groupBy('decision')
            ->pluck('aggregate', 'decision')
            ->all();

        $counts = [];
        $decided = 0;

        foreach (Decision::inReportOrder() as $decision) {
            $counts[$decision->value] = (int) ($byDecision[$decision->value] ?? 0);
            $decided += $counts[$decision->value];
        }

        return [
            'decided' => $decided,
            'undecided' => (clone $base)->whereNull('decision')->count(),
            'notified' => (clone $base)->whereNotNull('decision_notified_at')->count(),
            'by_decision' => $counts,
        ];
    }
```

- [ ] **Step 4: The transition action**

`app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php` — add after Plan 4's `startReviewing()`:

```php
    public static function markDecided(): Action
    {
        return Action::make('markDecided')
            ->label(__('decisions.mark.action'))
            ->icon(Heroicon::OutlinedFlag)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('decisions.mark.heading'))
            ->modalDescription(function (Conference $record, MarkDecided $mark): string {
                $unsent = $mark->unsentLetters($record);

                // The warning that is deliberately not a blocker: say the
                // number, then let the organizer decide.
                return $unsent > 0
                    ? __('decisions.mark.description').' '.__('decisions.mark.unsent_warning', ['count' => $unsent])
                    : __('decisions.mark.description');
            })
            ->visible(fn (Conference $record): bool => $record->status === ConferenceStatus::Reviewing
                && Gate::allows('publish', $record))
            ->action(function (Conference $record, MarkDecided $mark): void {
                Gate::authorize('publish', $record);

                $blockers = $mark->blockers($record);

                if ($blockers !== []) {
                    // The same shape publish() and startReviewing() use:
                    // report, do not throw, and name every missing piece at
                    // once.
                    Notification::make()
                        ->danger()
                        ->title(__('decisions.mark.not_ready'))
                        ->body(implode(' ', array_map('e', $blockers)))
                        ->persistent()
                        ->send();

                    return;
                }

                /** @var User $actor */
                $actor = auth()->user();
                $mark->handle($record, $actor);

                Notification::make()->success()->title(__('decisions.mark.done'))->send();
            });
    }
```

with `use App\Actions\Conferences\MarkDecided;` added, and `all()` gaining `static::markDecided()` after `static::startReviewing()`:

```php
        return [
            static::share(), static::emails(), static::ranking(), static::reviewers(), static::assignments(),
            static::remindReviewers(), static::publish(), static::startReviewing(), static::markDecided(),
            static::close(), static::archive(),
        ];
```

**Why the ability is `publish` and not a new one.** `ConferencePolicy` gives `publish`, `close` and `archive` to every organization member (spec section 4's "Create / edit conferences" row), and `StartReviewing` already authorizes with `publish` for the same reason: these are lifecycle moves on a conference, not decisions about an abstract. `sendDecisions` — the one that emails every author — is the narrow one, and it is narrow already.

- [ ] **Step 5: The conference view's Decisions section**

`app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceInfolist.php` — add a third memoised accessor beside `blockers()` and `counts()` (and beside Plan 4's `progress()`):

```php
    /**
     * @var WeakMap<Conference, array{decided: int, undecided: int, notified: int, by_decision: array<string, int>}>|null
     */
    private static ?WeakMap $decisions = null;

    /** @return array{decided: int, undecided: int, notified: int, by_decision: array<string, int>} */
    private static function decisions(Conference $record): array
    {
        self::$decisions ??= new WeakMap;

        return self::$decisions[$record] ??= $record->decisionCounts();
    }
```

and a section after Plan 4's review-progress one:

```php
            Section::make(__('decisions.conference.heading'))
                ->visible(fn (Conference $record): bool => in_array(
                    $record->status,
                    [ConferenceStatus::Reviewing, ConferenceStatus::Decided, ConferenceStatus::Archived],
                    true,
                ))
                ->headerActions([
                    Action::make('openRanking')
                        ->label(__('decisions.conference.open'))
                        ->icon(Heroicon::OutlinedTrophy)
                        ->color('gray')
                        ->url(fn (Conference $record): string => ConferenceResource::getUrl('ranking', ['record' => $record])),
                ])
                ->columns(3)
                ->components([
                    TextEntry::make('decisions_decided')->label(__('decisions.conference.decided'))->badge()->color('success')
                        ->state(fn (Conference $record): string => self::decisions($record)['decided']
                            .' / '.(self::decisions($record)['decided'] + self::decisions($record)['undecided'])),
                    TextEntry::make('decisions_notified')->label(__('decisions.conference.notified'))->badge()->color('info')
                        ->state(fn (Conference $record): int => self::decisions($record)['notified']),
                    TextEntry::make('decisions_undecided')->label(__('decisions.conference.undecided'))->badge()->color('warning')
                        ->state(fn (Conference $record): int => self::decisions($record)['undecided']),
                    TextEntry::make('decisions_breakdown')->label(__('decisions.conference.breakdown'))
                        ->listWithLineBreaks()
                        ->columnSpanFull()
                        ->placeholder(__('decisions.conference.none'))
                        ->state(fn (Conference $record): array => array_values(array_map(
                            fn (Decision $decision): string => $decision->getLabel().' — '
                                .self::decisions($record)['by_decision'][$decision->value],
                            Decision::inReportOrder(),
                        ))),
                ]),
```

with `use App\Enums\Decision;` added (the file already imports `Action`, `Heroicon`, `TextEntry`, `Section`, `ConferenceStatus` and — from Plan 4 — `ConferenceResource`).

- [ ] **Step 6: The admin list's two read-only columns**

`app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php` — add after the existing `submissions_count` column:

```php
                // Read-only, like everything else on this screen. `counts()`
                // reaches the query as `$query->withCount(Arr::wrap(...))`
                // (vendor/filament/tables/src/Columns/Concerns/InteractsWithTableQuery.php:24-25),
                // and Arr::wrap leaves an associative array alone - so Laravel's
                // `['relation as alias' => Closure]` form works and both counts
                // ride along on the list's own query rather than costing a
                // query per row.
                TextColumn::make('decided_count')
                    ->counts(['submissions as decided_count' => fn (Builder $query): Builder => $query->whereNotNull('decision')])
                    ->label('Decided')
                    ->badge()
                    ->color('success')
                    ->sortable(),
                TextColumn::make('notified_count')
                    ->counts(['submissions as notified_count' => fn (Builder $query): Builder => $query->whereNotNull('decision_notified_at')])
                    ->label('Letters sent')
                    ->badge()
                    ->color('info')
                    ->sortable(),
```

with `use Illuminate\Database\Eloquent\Builder;` added. **No decision action is added here and none should be:** the platform-admin cells of spec section 4 are read-only in this codebase until Plan 6 builds the admin resources, exactly as Plans 3 and 4 left submissions and reviews.

The labels here are hardcoded English because every other column on this admin table is (`'Organization'`, `'Abstracts'`, `'Deadline'`, `'Created'`), and half-translating one table is worse than the existing backlog item that covers all of them.

- [ ] **Step 7: The reviewer's one line**

`resources/views/filament/reviewer/pages/dashboard.blade.php` — inside the `@if ($conference->isOpenToReviewers())` block Plan 4 Task 6 added, above the queue link:

```blade
                    @unless ($conference->acceptsReviewWrites())
                        <p style="margin-top:0.5rem;font-size:0.875rem;opacity:0.75">
                            {{ __('reviewer.dashboard.decided') }}
                        </p>
                    @endunless
```

Append to `lang/en/reviewer.php`'s `dashboard` group:

```php
        'decided' => 'The organizers have made their decisions for this conference, so reviews are now read-only. You can still open your reviews and read what you wrote.',
```

**That is the whole reviewer-side change**, and it is deliberately one sentence. Every *behaviour* it describes is already Plan 4's: `acceptsReviewWrites()` is `Reviewing` only, so `SaveReviewDraft`, `SubmitReview` and `ReopenReview` refuse and `ReviewSubmission::isReadOnly()` disables the form. Re-implementing any of that here would be a second answer to a question Plan 4 already answers in one place.

- [ ] **Step 8: The language keys**

Append to `lang/en/decisions.php`:

```php
    'mark' => [
        'action' => 'Mark decisions final',
        'heading' => 'Mark this conference decided?',
        'description' => 'The conference moves to "Decisions sent". Reviewers can still read their reviews but can no longer write them, and you can still correct a decision and send a new letter.',
        'unsent_warning' => ':count decision letter(s) have not been sent yet. You can send them from the ranking page before or after this.',
        'not_ready' => 'This conference is not ready to be marked decided',
        'done' => 'Decisions are final',
        'errors' => [
            'wrong_status' => 'Only a conference that is under review can be marked decided; this one is :status.',
            'nothing_to_decide' => 'This conference has no abstracts under consideration.',
            'undecided' => ':count abstract(s) still have no decision. Decide them, or the authors are waiting for an email that will never arrive.',
        ],
    ],

    'conference' => [
        'heading' => 'Decisions',
        'open' => 'Ranking and decisions',
        'decided' => 'Decided',
        'notified' => 'Letters sent',
        'undecided' => 'Still open',
        'breakdown' => 'By decision',
        'none' => 'No decisions yet',
    ],
```

- [ ] **Step 9: Run the tests, then the whole suite**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/MarkDecidedTest.php tests/Unit/ReviewerScopeTest.php tests/Feature/Organizer/ConferenceTransitionsTest.php tests/Feature/Admin/ConferencesTest.php tests/Feature/Reviewer/ReviewerDashboardTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -10 /tmp/t.log && \
php artisan test > /tmp/all.log 2>&1; echo "all rc=$?"; tail -4 /tmp/all.log
```

Expected: both `rc=0`; about 40 passed in the five files (fourteen of them new here; four of the files carry their own Plan 2-4 cases) and `baseline + 147` in the second. Two of the new cases carry this task's least obvious rules: `it('keeps a decided abstract readable for the reviewer who reviewed it, and for nobody else')` (Step 0) is the one that proves the dashboard's new sentence is true, and `it('gives the platform admin no way to decide anything from the conference list')` asserts the admin table's action list rather than the absence of a Plan 6 class.

**`tests/Feature/Organizer/ConferenceTransitionsTest.php` is the file to watch:** Plan 4 already appended to it, and `ConferenceStatusActions::all()` now returns eleven actions. If it has an `assertTableHeaderActionsExistInOrder` or a count assertion, update it to include `ranking` and `markDecided` in the order `all()` returns them — that is a deliberate change, not a regression.

- [ ] **Step 10: Pint, Larastan and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?"; tail -20 /tmp/stan.log && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "feat(conferences): Reviewing -> Decided, with counts on both panels

MarkDecided follows PublishConference and StartReviewing: every missing piece is
a sentence. The one real blocker is an abstract still waiting for an answer;
unsent letters are a warning, because blocking on them would tie a status
transition to a queue worker. A reviewer whose conference has decided is finally
told why their reviews are read-only.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: all three `rc=0`.

---
### Task 10: The language sweep, the environment file and the runbook

No behaviour. Spec section 10 ("all strings in language files so Arabic can be added without code changes"), spec section 9 (".env.example lists every variable") and the deployment runbook.

**Files:**
- Modify: `tests/Feature/LanguageCoverageTest.php`, `lang/en/decisions.php` (sweep only), `.env.example`, `docs/runbooks/deploy-production.md`
- No application code changes.

- [ ] **Step 1: Extend the language-coverage sweep to Plan 5's files**

`tests/Feature/LanguageCoverageTest.php` already sweeps Plan 3's public views and — after Plan 4 — a `$plan4Sources` list of organizer and reviewer files. **Read what is there first**, then add a third list and a third case in the same shape:

```php
/**
 * Every file Plan 5 added or rewrote that may contain a translated string. The
 * two *modified* files are in the list on purpose: most of Plan 5's
 * organizer-facing keys live in DecisionActions and ConferenceStatusActions
 * rather than in a view.
 */
$plan5Sources = [
    'app/Filament/Organizer/Resources/Conferences/Pages/ConferenceRanking.php',
    'app/Filament/Organizer/Resources/Conferences/Tables/DecisionActions.php',
    'app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php',
    'app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceInfolist.php',
    'app/Filament/Organizer/Resources/Submissions/Schemas/SubmissionInfolist.php',
    'app/Actions/Decisions/ApplyDecision.php',
    'app/Actions/Decisions/ApplyDecisions.php',
    'app/Actions/Decisions/SendDecisionEmails.php',
    'app/Actions/Decisions/SendOneDecisionEmail.php',
    'app/Actions/Conferences/MarkDecided.php',
    'app/Models/SubmissionDecision.php',
    'resources/views/filament/organizer/pages/conference-ranking.blade.php',
    'resources/views/livewire/public/submission-status.blade.php',
    'resources/views/filament/reviewer/pages/dashboard.blade.php',
];

it('resolves every translation key plan 5 uses', function () use ($plan5Sources) {
    $missing = [];

    foreach ($plan5Sources as $relative) {
        $path = base_path($relative);

        expect(file_exists($path))->toBeTrue("Expected {$relative} to exist.");

        preg_match_all(
            '/(?:__|@lang|trans)\(\s*[\'"]((?:submission|mail|members|reviewer|decisions)\.[a-z0-9_.]+)[\'"]/',
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

it('leaves no visible english hardcoded in the pages plan 5 added', function () use ($plan5Sources) {
    // The same compiler-based sweep the Plan 3 case uses, over the Blade files
    // of this list. Do NOT strip directives with hand-written regexes - the
    // reasoning is in the Plan 3 case above and all three failure shapes are in
    // this plan's own views too.
    $allowed = ['KB', 'MB'];
    $offenders = [];

    foreach ($plan5Sources as $relative) {
        if (! str_ends_with($relative, '.blade.php')) {
            continue;
        }

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

            if (str_word_count($line) >= 3) {
                $offenders[] = "{$relative}: {$line}";
            }
        }
    }

    expect($offenders)->toBe([]);
});
```

**If Plan 4 already widened the `(?:submission|mail|…)` alternation in its own case, widen that one too** rather than leaving two patterns that disagree — a key prefixed `decisions.` used inside a Plan 4 file would otherwise pass unchecked.

**What stays hardcoded English, on purpose and with the backlog entry Task 11 Step 4 adds** (the existing backlog items cover only the Plans 1-2 Blade views, the countdown script and the poster template — none of them mentions an enum, an export heading or the admin tables, which is why Task 11 writes a new line rather than pointing at an old one):
- `App\Enums\Decision::getLabel()`, exactly as every enum from Plans 1-4 is. Half-translating one enum is worse than translating none. Note that `getLabel()` is also `{{decision}}`'s value in an author's letter, so translating it is part of the bilingual-templates item of spec section 14, not a panel string.
- `App\Support\Scoring\RankingRows::headers()` — the fourteen column headings of both exports, matching Plan 3's `ExportSubmissionsCsv::HEADERS`, which is hardcoded for the same reason.
- The two new columns on `app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php`, because every other column on that table is hardcoded too.

All three are **outside** `$plan5Sources`, so the two cases above do not check them and do not pretend to. Task 11 Step 7's spec self-review says so in the same words.

- [ ] **Step 2: Sweep `lang/en/decisions.php`**

The file was created in Task 1 with the single `history` group and appended to in Tasks 4, 6, 7 and 9. Read it once, end to end, and check three things:

1. every group is in the order the organizer meets it — `ranking`, `summary`, `actions`, `bulk`, `send`, `mark`, `conference`, `infolist`, `history`, `errors`;
2. no key is defined twice (a later `'errors' => [...]` block silently replaces an earlier one in a PHP array literal — the one mistake this file is shaped to make);
3. every key the code uses resolves, which Step 1's test now proves.

```bash
cd /c/Users/ahmed/Documents/CASS && php -r "\$a = require 'lang/en/decisions.php'; echo implode(', ', array_keys(\$a)), PHP_EOL; echo count(\$a, COUNT_RECURSIVE), ' entries', PHP_EOL;"
```

Expected: the ten group names above, and no group missing. (A duplicate group would show as a *missing* one here, because the second literal wins.)

- [ ] **Step 3: `.env.example`**

Append after Plan 4's reviewer block:

```bash
# --- Decisions and ranking (Plan 5) ---
# Rows the per-conference ranking table shows before the organizer asks for
# more. It also offers 25, 100, 250 and "all"; spec section 10 budgets 500 rows
# in under a second, and the table reads only denormalised columns so that
# budget is about rendering rather than about querying.
CASS_RANKING_PAGE_SIZE=50
# How many decision letters one "Send decision emails" click queues before it
# stops and tells the organizer to click again. The whole run happens inside one
# php-fpm request (docker/php.ini's 60-second budget) and each row is a render,
# a token mint, an email_logs insert and a queue push. Raise it only after
# measuring on the real host.
CASS_DECISION_SEND_CHUNK=200
```

Then prove the file and the config agree:

```bash
cd /c/Users/ahmed/Documents/CASS && grep -c "CASS_RANKING_PAGE_SIZE\|CASS_DECISION_SEND_CHUNK" .env.example && \
php -r "require 'vendor/autoload.php'; \$c = require 'config/cass.php'; var_dump(\$c['decisions']);"
```

Expected: `2`, and an array with `page_size` and `send_chunk`.

- [ ] **Step 4: The runbook**

`docs/runbooks/deploy-production.md` — **two edits**: one paragraph appended inside the existing "Every release" step 3, and one new section after "Email triage" (the file's existing headings are `One-time setup`, `Every release`, `Poster PDF fonts`, `Cloudflare Turnstile`, `Author uploads and private storage`, `Author status links`, `Email triage`, `Brand assets`, `Custom domain for an organization`, `Rollback`, `Backups`, `Secret rotation`, `Trusted proxies`, `Known quirk: healthcheck Host header`).

**First**, "Every release" step 3 carries a paragraph per plan naming exactly what breaks between the image going live and `migrate --force` running (lines 33-42 on `main` carry the Plan 2 and Plan 3 ones). Append after the "Plan 3 is such a release" paragraph:

```markdown
   Plan 5 is such a release: it adds six columns to `submissions` (`score`,
   `score_spread`, `review_count`, `scored_at`, `decision`,
   `decision_notified_at`), one to `reviews` (`score`) and one table
   (`submission_decisions`). Until they exist, three screens that worked before
   the deploy return 500: the new ranking page; the organizer **conference view**
   of any conference in Reviewing, Decisions sent or Archived, whose Decisions
   section calls `Conference::decisionCounts()` (the section is `->visible()`-gated
   to those three statuses, so a conference in Draft or Open is unaffected); and
   the admin conference list `/admin/conferences`, whose two new count columns
   query `decision` and `decision_notified_at` for every row on every render,
   regardless of status. `/s/{token}` is the one thing that degrades quietly - a
   missing `decision_notified_at` reads as null and the page shows its neutral
   "no decision yet" block. Turn auto-deploy off for this release and run
   `migrate --force` the moment the new container is healthy, then run the
   `cass:rescore` backfill described under "Scoring, ranking and decisions".
```

**Second**, the new section:

```markdown
## Scoring, ranking and decisions

Every command in this section looks the container up the way the rest of this
runbook does and runs artisan as `app` via `su-exec`. The stack is deployed by
Coolify under its own project name with its own environment, so
`docker compose -f docker-compose.production.yml ...` from a checkout is a
*different* stack (or a failure on unset `${...}` variables), and running artisan
as root leaves root-owned files in `bootstrap/cache` and `storage/framework`.

### Where the numbers come from

`submissions.score`, `submissions.score_spread`, `submissions.review_count` and
`submissions.scored_at` are **denormalised**. Nothing computes them on read —
that is what lets the ranking table sort five hundred abstracts without touching
`reviews` — and exactly one class writes them, `App\Actions\Submissions\ComputeSubmissionScore`,
with exactly three callers:

| Caller | When |
|---|---|
| `App\Actions\Reviews\SubmitReview` | a reviewer submits a review |
| `App\Actions\Reviews\ReopenReview` | a reviewer reopens one |
| `cass:rescore {conference}` | you, deliberately |

`reviews.score` is written for drafts too; only submitted reviews reach the
submission's mean, spread and count.

### `cass:rescore`

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec -it "$C" su-exec app php artisan cass:rescore <CONFERENCE-ULID>
```

The argument is the ULID from the organizer panel URL (`/org/{org}/conferences/{ULID}`),
or the numeric `conferences.id` if you are working from the database.

**It is not scheduled, and it is not an organizer feature.** A conference's
review form locks the moment the first review is submitted (spec section 3), and
a question's *weight* is part of the question, so nothing an organizer can do
makes a stored score wrong. Run it in exactly four situations:

1. **immediately after the release that adds the score columns** (this one).
   The four columns land empty for every abstract that already exists, and
   nothing recomputes them on read — so a conference whose reviewers finished
   under the previous release would show an entirely unscored ranking until this
   runs. Rescore every conference that already has reviews:

   ```bash
   C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
   sudo docker exec "$C" su-exec app php artisan tinker --execute='
   App\Models\Conference::query()->whereHas("submissions.reviews")->pluck("ulid")->each(fn ($u) => print($u . PHP_EOL));'
   # then, one command per ULID printed:
   sudo docker exec -it "$C" su-exec app php artisan cass:rescore <ULID>
   ```

2. **after the legacy import** (`cass:import-legacy`, Plan 6), which inserts
   reviews and answers directly and has no `SubmitReview` to hook;
3. **after correcting data by hand** in the database;
4. **after deploying a fix to `App\Support\Scoring`** — then rescore every
   conference that already had reviews, one command each.

It is safe to run at any time: it recomputes a whole conference and writing the
same numbers twice changes nothing.

### Sending decision emails

The organizer clicks **Send decision emails** on a conference's Ranking and
decisions page. One click queues one `TemplatedMail` per abstract that has a
decision and has never been written to, capped at `CASS_DECISION_SEND_CHUNK`
(200) per click; the notification says how many are left, and clicking again
continues.

**Throughput.** One queue worker runs under supervisord. Each job is one SMTP
handshake and one send against the owner's mailbox, so budget roughly a second
per letter: 200 letters is about three minutes, 500 is about eight. Before the
first real batch, send one conference's letters with `CASS_DECISION_SEND_CHUNK`
left at 200 and watch `supervisorctl status` plus the `sent`/`failed` split; if
the provider throttles, lower the chunk and click again rather than raising the
worker count. Watch it in the admin panel's **Email log**
(`/admin/email-logs`), filtered by template key, or:

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec "$C" su-exec app php artisan tinker --execute="echo App\Models\EmailLog::query()->where('template_key','like','decision_%')->selectRaw('status, count(*) c')->groupBy('status')->pluck('c','status');"
```

A row stuck at `queued` long after the others finished is the failure described
in the **Email triage** section above.

**If a batch fails in the worker** — the provider throttled the burst, the
mailbox credentials expired — the rows show as `failed` in `/admin/email-logs`
and the jobs are in `failed_jobs`. **Fix the transport, then retry the jobs; do
not use the per-row "Resend the letter" action.** The queued message already
carries the rendered subject and body and the `email_logs` ULID, so a retry
delivers exactly the letter that was stored, changes no token and rewrites no
history:

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec "$C" su-exec app php artisan queue:failed
sudo docker exec "$C" su-exec app php artisan queue:retry all
```

`queue:retry all` retries every failed job in the table, not only the decision
letters — which is usually what you want after a transport outage.

**A retried row keeps reading `failed` in the email log even when the retry
delivers.** `RecordOutgoingEmail::sent()` only flips a row that is still
`queued`, and `TemplatedMail::failed()` already moved it to `failed` on the first
attempt. Judge a retry by the worker and by the mailbox, not by the log's status
column; the row's `error` is a record of the first attempt, not of the last.

"Resend the letter" is for one author who says nothing arrived: it re-renders
from the template **as it stands now**, overwrites the stored letter on that
decision, and mints that author a new status link. Retry first; resend only what
is still missing afterwards.

**Sending decision letters rotates every notified author's status link.** The
access token in `/s/{token}` is stored as a SHA-256 hash and the plaintext
exists only inside an emailed link (spec section 9), so the only way to put a
working link in a second email is to mint a new one — which is what
`IssueSubmissionToken` does, and what "Resend status link" has always done. The
consequence: after decision letters go out, the link in an author's *original
confirmation* email stops resolving and the link in the *decision* email works.
Every decided author receives a decision letter, so every decided author
receives a live link. If a support request arrives saying "my old link is dead",
this is why, and the answer is the decision email — or a **Resend status link**
from the submission row.

The plaintext token exists in exactly one place: the delivered email. The copy
of the letter stored on `submission_decisions.letter_markdown` deliberately
keeps `{{status_link}}` literal, so that column — which is kept for ever and
appears in every backup — is never a bearer-token store. The author's status
page substitutes the link from the token already in their own URL, so what they
see is the whole letter.

**Letters cannot be sent from a conference that is off the public site.** An
archived conference — or one whose organization has been suspended — makes
`/s/{token}` a 404, so a letter carrying a fresh link into it would be dead on
arrival *and* would kill the link the author already has. The send button is
hidden there and the action refuses per row with a sentence naming the status.
If an organizer archived a conference with letters still pending, move it back
to `reviewing` or `decided`, send, then archive again.

### Correcting a decision after the letters have gone

The plain **Decide** action disappears once an author has been written to. Use
**Change decision and resend**: it appends to the decision history, sets the new
decision, and puts the abstract back in the send queue so the next
**Send decision emails** click queues a second letter with the new answer. The
superseded history row keeps the letter it announced, and both are visible on
the submission view.

### Marking a conference decided

**Mark decisions final** moves `reviewing -> decided`. It refuses while any
abstract is still in `submitted` or `under_review` with no decision — those
authors would be waiting for an email that never arrives. It does **not** refuse
when letters are still queued; the confirmation says how many.

After `decided`: reviewers can still open and read their reviews but cannot
write them (`Conference::acceptsReviewWrites()`), and decisions can still be
corrected with change-and-resend, because a presenter withdrawing in week three
is a real thing.

### The 500-row budget

Spec section 10 budgets the ranking table at "500 submissions in under 1 s". The
deterministic guard is
`tests/Feature/Organizer/ConferenceRankingPerformanceTest.php`'s query-count
case, which runs in CI and fails if the table ever touches `reviews`.

The wall-clock case is skipped on CI (a shared runner's clock measures the
runner) and the production image carries no test runner at all — `composer
install --no-dev`, and `.dockerignore` excludes `tests/`, `phpunit.xml` and
`phpunit.browser.xml` — so there is nothing there to run it with. Measure the
query itself on the host instead, once before launch, against a conference that
already has a few hundred abstracts:

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec "$C" su-exec app php artisan tinker --execute='
$c = App\Models\Conference::query()->where("ulid", "<CONFERENCE-ULID>")->firstOrFail();
for ($i = 0; $i < 5; $i++) {
    $t = microtime(true);
    $rows = App\Support\Scoring\RankedSubmissions::query($c)->with("track")->orderByDesc("score")->limit(500)->get();
    printf("%d rows in %.3fs\n", $rows->count(), microtime(true) - $t);
}'
```

That is the same entry point the table itself uses. The first run warms OPcache
and the buffer pool; take the median of the rest. If it is anywhere near 1 s with
a few hundred rows, something has started reading `reviews` per row — which is
exactly what the CI query-count case bounds.

### Exports

The ranking page exports **CSV** and **Excel (XLSX)** of exactly the rows on
screen. The two are different from Plan 3's submission-list CSV on purpose: this
one carries scores, spreads, review counts and decisions; that one carries the
abstract text, affiliations, custom-field answers and file names.

The XLSX writer assembles the workbook in the container's `/tmp` before
streaming it (openspout builds a zip), so an export needs free space there
briefly — a few hundred kilobytes at these sizes. `/tmp` is deliberately not on
the `cass-storage` volume, so a half-written export cannot survive a restart.

Both writers prefix an apostrophe to any cell beginning with `=`, `+`, `-`, `@`,
a tab or a carriage return (`App\Support\Export\SpreadsheetCell`). In XLSX this
is not cosmetic: without it openspout writes a real formula cell.
```

- [ ] **Step 5: Run the whole suite and commit**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/LanguageCoverageTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -8 /tmp/t.log && \
./vendor/bin/pint > /tmp/pint.log 2>&1; echo "pint rc=$?" && \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/stan.log 2>&1; echo "stan rc=$?" && \
php artisan test > /tmp/all.log 2>&1 && echo "all rc=0 - the suite gates this commit" && \
git add -A && git commit -q -m "docs: language sweep, the two decision knobs and the runbook

The runbook says the thing an operator most needs to know and cannot infer:
sending decision letters rotates every notified author's status link, because a
hashed token leaves no other way to put a working one in a second email.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: every `rc=0`; `baseline + 149` on the suite.

---
### Task 11: MySQL, the backlog, the final gate and the pull request

**Files:**
- Modify: `docs/superpowers/plans/backlog.md`
- No application code changes.

- [ ] **Step 1: Run the whole suite against MySQL, not only SQLite**

Six things in this plan behave differently on MySQL and are the reason this run is the real gate, not a formality:

1. **`decimal(5,2)` reads back as a string on MySQL and as an int or a float on SQLite.** The `decimal:2` casts on `submissions.score`, `submissions.score_spread` and `reviews.score` are what make both drivers answer `'75.00'`, and a dozen assertions in `tests/Unit/ComputeSubmissionScoreTest.php` and `tests/Unit/DecisionModelTest.php` are written against that string. A cast that was dropped is invisible locally and red here.
2. **`avg(score)` in `Conference::rankingSummary()`** comes back as a float on SQLite and a string on MySQL. The explicit `(float)` before `round()` is what makes the summary strip print `73.25` in both places.
3. **NULL ordering.** `ORDER BY score DESC` must put the unreviewed abstracts last and `ASC` must put them first on *both* drivers — the sort every organizer will use first. `tests/Feature/Organizer/ConferenceRankingTest.php`'s `it('lists the abstracts under consideration, best first')` and `it('sorts by score, spread, review count and track')` are the assertions, and this run is where the MySQL half of fact 20 is actually verified rather than reasoned.
4. **`submission_decisions.submission_id` is `RESTRICT`**, which is a real constraint only here. `tests/Unit/DecisionModelTest.php`'s `it('restricts deleting a submission that has a decision')` asserts the `QueryException`.
5. **`decision` is `varchar(16)` under strict mode**, so a value longer than sixteen characters is an exception rather than a silent truncation — `accepted_poster` is fifteen, which is the closest this enum comes to the ceiling and the reason the column is not `varchar(12)`.
6. **`review_count` is `unsignedSmallInteger`**; a negative value is an exception on MySQL and a silently stored `-1` on SQLite. `SubmissionScorer::summarise()`'s `max(0, $submitted)` is what stops it arising, and this is where a regression would show.

```bash
cd /c/Users/ahmed/Documents/CASS && docker compose -f docker-compose.dev.yml up -d && sleep 15 && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php artisan test > /tmp/mysql.log 2>&1; echo "mysql rc=$?"; tail -4 /tmp/mysql.log
```

Expected: `mysql rc=0` and the same count as the SQLite run.

- [ ] **Step 2: Prove the two driver-dependent claims by hand**

The suite covers both, but each is one line of reasoning in a plan and one line of SQL in a database, and seeing the second is worth thirty seconds. Write `/tmp/nullsort.php`:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Step 1's `php artisan test` uses RefreshDatabase, so the schema survives the
// run but NO ROWS DO. Build exactly what this needs, then remove it again.
$conference = App\Models\Conference::factory()->create(['status' => App\Enums\ConferenceStatus::Reviewing]);

$high = App\Models\Submission::factory()->for($conference)->submitted()->create(['title' => 'high']);
$low = App\Models\Submission::factory()->for($conference)->submitted()->create(['title' => 'low']);
$none = App\Models\Submission::factory()->for($conference)->submitted()->create(['title' => 'none']);

$high->forceFill(['score' => 90, 'review_count' => 2])->save();
$low->forceFill(['score' => 10, 'review_count' => 2])->save();

$order = fn (string $direction): string => implode(', ', App\Support\Scoring\RankedSubmissions::query($conference)
    ->orderBy('score', $direction)
    ->pluck('title')
    ->all());

echo 'desc: ', $order('desc'), PHP_EOL;   // high, low, none
echo 'asc:  ', $order('asc'), PHP_EOL;    // none, low, high

// And the decimal cast, which is the other thing that differs by driver.
echo 'score cast: ', var_export($high->fresh()?->score, true), PHP_EOL; // '90.00'

// Leave the database as this script found it.
App\Models\Submission::query()->where('conference_id', $conference->id)->delete();
App\Models\Submission::query()->withTrashed()->where('conference_id', $conference->id)->forceDelete();
$conference->forceDelete();
```

```bash
cd /c/Users/ahmed/Documents/CASS && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php /tmp/nullsort.php
```

Expected exactly:

```
desc: high, low, none
asc:  none, low, high
score cast: '90.00'
```

(`php -` does not work on this machine, which is why this is a file.)

- [ ] **Step 3: Confirm the routes, the pages and the command this plan added**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan route:list --except-vendor | grep -E "ranking" && \
php artisan list 2>/dev/null | grep -E "cass:" && \
php artisan schedule:list
```

Expected:

- one organizer page route: `org/{tenant:slug}/conferences/{record}/ranking`;
- two `cass:` commands — `cass:reviewer-reminders` (Plan 4) and `cass:rescore` (this plan);
- **three** scheduled entries, unchanged from Plan 4: `queue:prune-failed`, `model:prune` and `cass:reviewer-reminders`. **`cass:rescore` must NOT be in that list** — a nightly rescore would silently rewrite numbers an organizer is looking at, and Task 3 argues why.

Then confirm the one thing a cached-config production image could still get wrong — that the ranking page is reachable and that nothing on it 500s under `route:cache`. The CI smoke job already boots the built image and asserts the three panels; add one line to its "Assertions" step, after Plan 4's `/review` checks in `.github/workflows/ci.yml`:

```yaml
          # The Plan 5 page inside the built image, under route:cache +
          # config:cache + opcache validate_timestamps=0. route:cache runs in the
          # entrypoint, so a page registered with the wrong parameters would
          # either have failed the boot or be missing from the cached table.
          # (A curl of /org would prove nothing here: that route resolves to
          # Filament's RedirectToTenantController and never reaches the ranking.)
          docker exec cass su-exec app php artisan route:list --except-vendor | tee /tmp/routes
          grep -q 'conferences/{record}/ranking' /tmp/routes
          # And a guest on it is redirected to the organizer login, not 500:
          # Filament's Authenticate middleware runs before IdentifyTenant
          # (vendor/filament/filament/routes/web.php:60 vs :119), so the tenant
          # slug is never resolved and need not exist.
          code=$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: localhost' http://127.0.0.1:8080/org/any-org/conferences/01ARZ3NDEKTSV4RRFFQ69G5FAV/ranking)
          echo "ranking page -> $code"; test "$code" = "302"
```

- [ ] **Step 4: Update `docs/superpowers/plans/backlog.md`**

**Remove or rewrite the five entries Plan 5 actually closed:**

- under `## Organizer features (Plan 2 onwards)`: "`ConferenceStatus::Decided` is in the enum and the transition table but nothing drives it: **Plan 5** moves Reviewing -> Decided…" (Plan 4 Task 13 rewrote it to this) — **delete**. Done, Task 9, `MarkDecided`.
- under `## Submissions (deferred by Plan 3)`: "**Decision letters on the status page** (spec 5.3 step 5)…" — **delete**. Done, Task 8.
- under the same heading: "**The four `decision_*` template keys are still not sent.**" (Plan 4 Task 13 rewrote it to this) — **delete**. Done, Task 7.
- under the same heading: "**XLSX export.** Spec 5.6 asks for CSV *and* XLSX on the ranking table…" — **delete**. Done, Task 5.
- under `## Reviewing (deferred by Plan 4)`: "**No reviewer sees another reviewer's review, in any panel, in Plan 4.** … Plan 5's ranking table is the first thing to read them, and is where 'should reviewers see each other's comments after decisions' has to be answered." — **rewrite**, because Plan 5 answered half of it and did not build the other half:

  ```markdown
  - **Nothing in any panel prints a review's *content*.** Plan 5's ranking table reads the scores, not the answers: `ReviewPolicy::view()` allows the conference's organization members, but no organizer screen shows what a reviewer actually wrote, which is a real gap between spec section 4's "View submissions" and the code. **Plan 6** adds the read-only review screen, in the organizer panel beside the submission view and in the admin panel beside the read-only `SubmissionResource`. **Decided (Plan 5):** no reviewer sees another reviewer's review, before or after decisions — the reviewer panel shows a reviewer their own work and nothing else. Revisit only if an owner asks for open peer review.
  ```

Then append a new section:

```markdown
## Decisions (deferred by Plan 5)

- **Open question for the owner: sending decision letters rotates every notified author's status link.** `/s/{token}`'s token is stored as a SHA-256 hash and the plaintext exists only inside an emailed link (spec section 9), so there is no way to put a working `{{status_link}}` in a decision email except to mint a new token — which kills the link in the author's original confirmation email. Plan 3 already accepted the same trade for "Resend status link". Every decided author receives a letter, so every decided author receives a live link, and the runbook says so; the alternatives, if the owner dislikes it, are a second longer-lived "decision link" column on `submissions`, or accepting more than one live token per submission (a `submission_tokens` table, which is also what a "share this with my co-author" feature would want). **Confirm before launch.**
- **Open question for the owner: who may send decision letters.** `SubmissionPolicy::decide()` follows spec section 4's "Invite reviewers, assign, decide" row and allows **every** organization member down to a plain `member`. `ConferencePolicy::sendDecisions()` deliberately does **not**: it is owner/admin only, because one click emails every author in the conference and cannot be un-sent. (It lives on `ConferencePolicy` rather than `SubmissionPolicy` because Laravel resolves a policy from the first argument's class and every call site passes the conference.) That is a narrowing of the spec table, argued in the policy's own docblock. Confirm, or widen it to match `decide()`.
- **Open question for the owner: a resend overwrites the stored letter.** A *changed* decision appends a new `submission_decisions` row and leaves the superseded row's letter alone. A plain *resend* of the same decision re-renders and overwrites `letter_subject`, `letter_markdown` and `notified_at` on the current row, because the author is now holding the newer email and `/s/{token}` has to show what is in their inbox. The individual send history is not lost — `email_logs` has a row per send — but the *text* of the superseded letter is. Keeping every send as its own row is one more table or one more column; confirm whether it is wanted.
- **Decided:** unsent decision letters do **not** block `Reviewing -> Decided`. Blocking would tie a status transition to a queue worker draining; the confirmation modal names the number instead. Tighten it only if an owner reports a conference marked decided with a full queue and nobody noticing.
- **Decided:** `review_count` counts *submitted reviews*, while `score` and `score_spread` are computed only from the reviews that produced a number. The two can disagree — three reviewers, two scores, because one conference's form has an unscored question set — and the ranking prints both rather than averaging the disagreement away.
- **Decided:** a question's `weight` is locked with the rest of the review form the moment the first review is submitted (spec section 3, `ReviewQuestion::booted()`). There is no "re-weight and rescore" organizer feature and there should not be one: a committee that changes the weights mid-round has to say so out loud. `cass:rescore` exists for the one-time backfill after the release that adds the score columns, for the legacy import, for hand-corrected data and for a fixed scorer, and is documented in the runbook under exactly those four headings.
- **Per-track quotas and per-track cut-offs.** Spec 5.6 says nothing about them and the programme builder is spec section 14. The ranking filters by track, which is the read-only half; "the top eight of each track" is still the organizer reading a filtered list. The natural home is a `tracks.quota` column plus a per-track summary strip.
- **Nothing computes a decision from a score.** No auto-accept threshold, no "accept everything above 75". Spec 5.6 describes a ranking and a person; an automatic rule would need a tie-break policy, a preview and an audit trail of its own.
- **The decision letter is one template per decision, not per track or per presentation type.** An organizer who wants a different letter for the two accepted kinds has two templates (`decision_accepted_oral`, `decision_accepted_poster`) and no more. Per-track wording is the bilingual-templates item of spec section 14 wearing a different hat.
- **`email_logs` still grows for ever** (raised by Plan 3, repeated by Plan 4, and Plan 5 adds one row per author per conference — the largest single contributor so far). `Prunable` plus a retention variable, before the table passes a few hundred thousand rows.
- **The hard purge (Plan 6) has one more table to delete.** `submission_decisions.submission_id` is `RESTRICT` on purpose, so a purge must delete the decision history in application code, in order, before the abstracts — and should count it in the confirmation the admin is shown, beside the reviews and the files.
- **A platform-admin view of decisions.** `SubmissionDecisionPolicy::before()` answers `true` for a platform admin and the admin conference list now carries two read-only count columns, but there is no screen that shows a decision or its letter — the same state Plan 3 left submissions and Plan 4 left reviews. **Plan 6**, alongside the read-only admin `SubmissionResource`. Note again what Plan 4 recorded: Laravel returns a non-null `before()` result without ever calling the ability, so the explicit `deleteAny(): false` on `SubmissionDecisionPolicy` does not apply to a platform admin, and refusing deletion on that screen is Plan 6's job.
- **The XLSX export is assembled on disk, not streamed.** openspout builds the zip under `sys_get_temp_dir()` and copies it to the stream at `close()`. At the conference sizes this platform is for that is a few hundred kilobytes and invisible; a conference with tens of thousands of abstracts would want a chunked, multi-file export or a queued job that emails a link.
- **The ranking's wall-clock budget is not enforced in CI.** `tests/Feature/Organizer/ConferenceRankingPerformanceTest.php` has two cases: a deterministic query-count gate that runs everywhere, and a one-second wall-clock case skipped on CI because a shared runner's clock measures the runner. The production image has no test runner at all (`composer install --no-dev`, `.dockerignore` excludes `tests/`), so the runbook asks for one manual `tinker` timing of `RankedSubmissions::query()` on the host before launch instead. If the budget ever needs to be defended continuously, the honest way is a dedicated performance job on a dedicated runner, not un-skipping this one.
- **The language sweep still stops at the file boundary.** `LanguageCoverageTest` proves the fourteen Plan 5 source files, but `App\Enums\Decision::getLabel()` (every enum from Plans 1-5 is the same), `App\Support\Scoring\RankingRows::headers()` and the columns of `app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php` are hardcoded English and no existing backlog item mentions any of them — the ones that are there cover the Plans 1-2 Blade views, the countdown script and the poster template. Extract enums, export headings and the admin tables together, with those views, before any Arabic work. `Decision::getLabel()` is an author-facing string (it is `{{decision}}`'s value in every decision letter), so it moves with the bilingual-templates item of spec section 14 rather than with the panel strings.
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

Expected: every `rc=0`. The browser suite is Plan 3's one end-to-end submission run; this plan adds no browser test and must not have broken that one. `npm run build` matters here specifically because Task 8 added Tailwind arbitrary variants to a public view.

- [ ] **Step 6: Commit the backlog and open the pull request**

Both suites gate this commit too, with `&&` rather than `;`, so the last commit of the plan cannot land on a red tree even if Step 5's output was skimmed.

```bash
cd /c/Users/ahmed/Documents/CASS && \
php artisan test > /tmp/sqlite.log 2>&1 && echo "sqlite rc=0" && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php artisan test > /tmp/mysql.log 2>&1 && echo "mysql rc=0" && \
git add -A && git commit -q -m "docs: record what plan 5 closed and what it deferred

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && \
git push -u origin plan-5-decisions && \
gh pr create --fill --title "Plan 5: scoring, the ranking table, decisions and decision letters" --body "$(cat <<'BODY'
Implements spec 5.6 in full, the four `decision_*` keys of 5.9, the "After
decisions, it shows the decision letter" half of 5.3 step 5, the
`Reviewing -> Decided` transition, and the "ranking table for 500 submissions in
under 1 s" budget of section 10.

## Scoring

- `App\Support\Scoring` is three pure classes: `AnswerNormaliser` (0-100 per
  answer, or null), `ReviewScorer` (weighted mean, or null), `SubmissionScorer`
  (mean, sample standard deviation, count). Exhaustive unit tests over every
  boundary the spec sentence leaves implicit: a value outside its own scale, a
  scale with no range, a zero weight, an all-text review, one review, no
  reviews.
- Four denormalised columns on `submissions` and one on `reviews`, written by
  exactly one class with exactly three callers: `SubmitReview`, `ReopenReview`
  and `cass:rescore`.

## For organizers

- **Ranking and decisions** on each conference: a summary strip, a sortable,
  filterable, searchable table of every abstract under consideration, and CSV
  and Excel exports of exactly the rows on screen.
- A decision per row or over a selection, with a per-row report that says which
  rows it refused and why.
- **Send decision emails**: one click, a typed confirmation, the count per
  decision, and one letter per decided abstract using this conference's own
  template. Idempotent, chunked, and it tells you what is left.
- Decisions are prepared quietly: before the letter goes out they change freely,
  after it only through **Change decision and resend**, which appends to the
  history and queues a new letter.
- **Mark decisions final** (`reviewing -> decided`), with a blocker list.

## For authors

- `/s/{token}` shows the decision letter — the letter that was *sent*, stored at
  send time, so a later template edit cannot rewrite it. Nothing before it is
  sent; nothing ever on a withdrawn abstract.

## For reviewers

- One sentence on the dashboard when a conference has decided, explaining why
  their reviews are now read-only. The behaviour was already Plan 4's.

## Platform

- Four migrations, one enum, one model, one policy, one console command. No new
  packages: openspout has written the submission-list CSV since Plan 3 and
  writes XLSX from the same rows.
- The spreadsheet formula guard now has one copy, in
  `App\Support\Export\SpreadsheetCell`, because in XLSX it is not cosmetic:
  openspout turns a leading `=` into a real formula cell.

## Verification

- `php artisan test` green on SQLite and on MySQL 8.4.
- `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse` (level 6) clean.
- The Plan 3 browser suite still green.
- 500 factory abstracts render the ranking with zero queries against `reviews`
  and under twenty queries in total; the wall-clock budget is measured locally
  and documented for one manual run on the host before launch.
- Cross-tenant negatives on the ranking page, both exports, both decision
  actions and the send.

## Deferred, with reasons

See `docs/superpowers/plans/backlog.md`, section "Decisions (deferred by
Plan 5)". Three of them are **questions for the owner**: that sending decision
letters rotates every notified author's status link (a consequence of hashing
the token, shared with "Resend status link"), whether a plain organization
member should be able to send those letters, and whether a resend should keep
the letter it replaced.
BODY
)"
```

- [ ] **Step 7: Self-review against the spec**

Read each row and confirm it against the code, not against this plan's prose.

**Section 5.6 — Scoring, ranking, decisions**

| Sentence | Where |
|---|---|
| "Each answer is normalised to 0-100: Likert `(value − min) / (max − min) × 100`" | Task 2: `AnswerNormaliser::likert()`, with the clamp and the zero-range guard |
| "boolean yes = 100, no = 0" | Task 2: `AnswerNormaliser::boolean()`, with `false` kept distinct from `null` |
| "select options carry an optional score" | Task 2: `AnswerNormaliser::select()` over `ReviewQuestion::optionScore()` (Plan 4), already declared 0-100 by the panel editor |
| "text answers do not score" | Task 2: the `Text` arm of `normalise()`, returning null even for a numeric-looking comment |
| "Review score = weighted mean of scored answers" | Task 2: `ReviewScorer::weightedMean()`, skipping non-positive weights |
| "Submission score = mean of submitted review scores" | Task 2/3: `SubmissionScorer::summarise()`, fed only submitted reviews by `ComputeSubmissionScore` |
| "spread = sample standard deviation" | Task 2: `SubmissionScorer::sampleStandardDeviation()`, `n − 1`, null below two |
| "review count shown alongside" | Task 1/4: `submissions.review_count`, its own column on the ranking |
| "Ranking table: sortable by score, spread, count, track" | Task 4: four `->sortable()` columns, all four exercised by `ConferenceRankingTest` — including `track.name`, the only one Filament answers with a relationship subquery |
| "filters by status and track" | Task 4: two `SelectFilter`s, plus decision and "fewer than N reviews" |
| "export CSV and XLSX" | Task 5: `ExportRankingCsv`, `ExportRankingXlsx`, one `RankingRows` |
| "Decisions: accepted_oral, accepted_poster, waitlisted, rejected" | Task 1: `App\Enums\Decision` |
| "Applied per row or in bulk" | Task 6: `DecisionActions::decide()` and `::decideSelected()` |
| "Each decision records who and when and appends to `SubmissionDecision`" | Task 1/6: the table, and `ApplyDecision`'s transaction |
| "Decision emails use the matching template" | Task 7: `Decision::templateKey()` |
| "sent when the organizer clicks 'Send decision emails'" | Task 7: `DecisionActions::sendDecisionEmails()` |
| "so decisions can be prepared quietly first" | Task 6: nothing is emailed by deciding; the change-after-send rule; Task 8: the author sees nothing until the letter is sent |

**Section 5.9 — Emails**: the four `decision_*` keys are sent (Task 7) with exactly the seven placeholders `EmailTemplateKey` declares for them; all mail is queued and logged to `email_logs` by Plan 3's `SendTemplatedEmail`, unchanged.

**Section 5.3 step 5**: "After decisions, it shows the decision letter" — Task 8.

**Section 4 — Roles and permissions**

| Cell | Where |
|---|---|
| "Invite reviewers, assign, **decide**" — every organization member | Task 1: `SubmissionPolicy::decide()` mirrors `view()` |
| Sending the letters | **Narrowed to owner/admin**, argued in `ConferencePolicy::sendDecisions()` — on `ConferencePolicy` because every call site passes the conference and Laravel resolves the policy from the first argument — and flagged as an owner question |
| Platform admin "See all organizations and conferences" | Task 9: two read-only count columns on the admin conference list. No decision UI; the policies answer through `before()` and Plan 6 builds the screens |
| Author (token) | Task 8: reads the letter, cannot act on it |

**Section 7 — Architecture**: `app/Support/Scoring` exists and holds exactly the scoring classes the spec's directory list implies; `ComputeSubmissionScore`, `ApplyDecision` and `SendDecisionEmails` are `app/Actions` classes with documented `handle()` signatures, as the spec's action list names them.

**Section 8 — Data storage**: every new foreign key is a real constraint with a decided `on delete` (Task 1's table); `submissions` gains four indexes, all `(conference_id, …)` composites matching the queries that exist.

**Section 9 — Security**: a policy on the new model with every ability Filament may call, plus `SubmissionPolicy::decide()` and `ConferencePolicy::sendDecisions()`; cross-tenant negatives on the ranking page, both exports, both decision actions (including a foreign record id handed to the bulk action, the one path where the client chooses the primary keys) and the send; audit entries for every decision, for **every individual letter** (`submission.decision_letter_sent`, with the actor, so "who re-sent this and rotated this author's link" has an answer) as well as for the run, and for the `Reviewing -> Decided` transition; the author token stays hashed, is rotated rather than stored, and is withheld from the stored letter so `submission_decisions` never holds a live bearer credential.

**Section 10 — Non-functional**: the 500-row budget is met by denormalisation and guarded by a query-count test; every timestamp is rendered in the conference's own timezone; every new organizer- and author-facing string **in the fourteen files of `$plan5Sources`** is in `lang/en/decisions.php` or `lang/en/submission.php`, proved by `LanguageCoverageTest`. **Three things are deliberately still hardcoded English**, listed in Task 10 Step 1 and carried into the backlog by Task 11 Step 4: `App\Enums\Decision::getLabel()` (which is also the value of `{{decision}}` in every decision letter, so translating it belongs with the bilingual-templates item of spec section 14), `App\Support\Scoring\RankingRows::headers()` (matching Plan 3's `ExportSubmissionsCsv::HEADERS`), and the two new columns on the admin conference table (matching every other column there).

**Section 12 — Testing**: unit tests for "scoring normalisation and weighting" as the spec names them, exhaustive over every boundary; feature tests for the ranking, both exports, decisions per row and in bulk, the emails, the letter on the status page and the transition; a cross-tenant negative on every new page and action.

**Explicit deviations from the brief, each argued where it is made**

1. **The letter lives on `submission_decisions`, not in a `decision_letters` table** (Task 1) — one parent, one lifetime, one purge entry; and `email_logs` already holds the per-send history.
2. **A resend overwrites the stored letter; only a changed decision appends** (Task 1, Task 7) — `/s/{token}` must show what is in the author's inbox. Flagged for the owner.
3. **`ConferencePolicy::sendDecisions()` is owner/admin, narrower than spec section 4's "decide" row** (Task 1) — one click, every author, cannot be un-sent. It sits on `ConferencePolicy` rather than `SubmissionPolicy` because the Gate resolves a policy from the first argument's class and every call site passes a `Conference`. Flagged for the owner.
4. **Sending decision letters rotates the author's status token** (Task 7) — the only alternative to omitting the link or storing a plaintext. Flagged for the owner, documented in the runbook.
5. **Unsent letters warn rather than block `Reviewing -> Decided`** (Task 9) — otherwise a status transition waits on a queue worker.
6. **The ranking is a resource *page*, not a second resource** (Task 4) — four reasons, all about the ranking being per conference and about not copying `SubmissionResource`'s tenancy override.
7. **`review_count` counts submitted reviews, not scored ones** (Task 2) — the two questions are different and the table prints both.
8. **The wall-clock performance case is skipped on CI** (Task 4) — a shared runner's clock measures the runner; the query-count case is the real gate. Argued in the skip message itself.
9. **`cass:rescore` is not scheduled** (Task 3) — a nightly rescore rewrites numbers an organizer is looking at, and the four real uses are all deliberate human acts.
10. **`SubmissionStatus::isDrivenInPlan3()` is renamed `isWithOrganizers()`** (Task 8) — it named a plan rather than a meaning, and from this plan on it was false for every case it described.
11. **The stored decision letter withholds `{{status_link}}`** (Task 7, Task 8) — the delivered email carries the real link; `submission_decisions.letter_markdown` keeps the placeholder literal so a row that is kept for ever is not a plaintext bearer-token store, and the status page fills it in from the token the reader already holds. The stored letter is otherwise the delivered letter.
12. **`Plan 4`'s `ReviewerScope::constrain()` is widened by one clause** (Task 9) — `ApplyDecision` writes `accepted`/`rejected`/`waitlisted` onto `submissions.status` while the conference is still `reviewing`, which would otherwise drop every decided abstract and the reviewer's own review of it out of every reviewer read path, making the new "you can still open your reviews" notice false.
13. **Every letter writes its own activity entry** (Task 7) — `submission.decision_letter_sent`, alongside the run's `conference.decisions_sent`, because the resend path rotates an author's access token and spec section 9 needs that to have an actor.

- [ ] **Step 8: Type and rule consistency check**

One last pass for the mismatches Larastan cannot see:

```bash
cd /c/Users/ahmed/Documents/CASS && \
echo "--- who writes the score columns ---" && \
grep -rn "'score'\s*=>\|'score_spread'\s*=>\|'review_count'\s*=>\|'scored_at'\s*=>" app/ --include=*.php | grep -v "app/Models/" && \
echo "--- who writes the decision columns ---" && \
grep -rn "'decision'\s*=>\|'decision_notified_at'\s*=>" app/ --include=*.php | grep -v "app/Models/" && \
echo "--- who decides what is under consideration ---" && \
grep -rn "SubmissionStatus::Draft\|SubmissionStatus::Withdrawn" app/ --include=*.php && \
echo "--- who reads a weight ---" && \
grep -rn "->weight" app/Support/ app/Actions/ --include=*.php
```

What each list must show:

- **the score columns**: only `ComputeSubmissionScore` writes them. `App\Support\Scoring\ReviewScorer::forReview()` and `SubmissionScorer::summarise()` appear in the list as well, because they build in-memory arrays with those same keys and hand them back — they write nothing, and they are the expected shape here. Any *other* name is a second writer, and a second writer is how a ranking comes to disagree with the reviews behind it.
- **the decision columns**: the only writers are `ApplyDecision` (both columns) and `SendOneDecisionEmail` (`decision_notified_at` three times — the conditional-`UPDATE` claim, the release in its `catch`, and the `syncOriginal()` that keeps the in-memory model honest). The same pattern also lists arrays that merely carry the key and write nothing — the `withProperties()` payloads in `ApplyDecision` and `SendOneDecisionEmail`, the placeholder array `SendOneDecisionEmail` hands `RenderEmailTemplate`, and `DecisionActions`' two `fillForm()` arrays and its `:decision` notification replacement — and those are expected too. Any other *write* is a path around the history. (The factory cannot appear at all: the grep is scoped to `app/`, and `database/factories/SubmissionFactory.php` is outside it.)
- **Draft/Withdrawn**: `RankedSubmissions::statuses()`, plus the places Plans 3 and 4 already read them (`SubmissionStatus` itself, `WithdrawSubmission`, `Submission::isReviewable()`). Any other literal pair — **including a convenience predicate on `Submission`** — means "under consideration" has started to mean two things. This plan deliberately ships no such predicate; if one is wanted later it must be written in terms of `RankedSubmissions::statuses()`.
- **`->weight`**: only `ReviewScorer::forReview()`, and it must be `(float) $question->weight` — the column is cast `decimal:2` and hands back a string on both drivers.

A hit outside those shapes is the bug this check exists to find.
