# CASS v2 Plan 11: Native Tenancy for Submissions (C5)

> **Status: DRAFT 2026-11-15, written against `main` at `2f1f78f`. Not reviewed and not prototyped: every fact below was read from the source (app, tests, and the main checkout's `vendor/`: Laravel 13.37.0, Filament 5.10.2). Implementation waits for the adversarial review and for `refactor/c6-one-membership-check` to merge into `main`; Task 1 begins by rebasing onto it.**

**Goal:** expert-brief row C5 (`docs/reviews/2026-10-16-expert-brief.md`, phase C, and Question 6 point 1). Every submission carries its own `organization_id`, always equal to its conference's. The organizer panel's `SubmissionResource` goes back to Filament tenancy on that column, so every Submission query in an organizer request is tenant-scoped by a mechanism instead of by one hand-written query, and the `1 = 0` fallback and the hand-scoped `whereHas` go. Done when (brief): the backfill is verified in the MySQL suite (CI's `Tests (MySQL)` step runs Task 1's backfill test); every cross-tenant test is green; the backlog entry on the hand-scoped resource is closed with the reason (at record time, not in this branch).

## Summary (review point 1)

- **Users see no change.** Organizers see the same abstracts as today. Inside the organizer panel, every query that reads abstracts is now filtered by the abstract's own organization.
- **One additive migration:** `2026_11_15_000100_add_organization_id_to_submissions_table` adds a nullable `submissions.organization_id` with an index on `(organization_id, submitted_at)` and a foreign key to `organizations.id` (ON DELETE RESTRICT). The same migration fills it from `conferences`. `NOT NULL` and a composite key to the conference wait for a later contract release.
- **Policies touched: none.** `SubmissionPolicy`, `SubmissionFilePolicy` and `SubmissionDecisionPolicy` keep reaching the tenant through the conference. That path is what makes an abstract of a deleted conference answer "no" (pinned by `TrashedTenantPolicyTest`).
- **Environment variables: none.**
- **Sensitive files:** the new migration only (`database/migrations/`).
- **Also changed:** `app/Models/Submission.php` and `app/Models/Conference.php` (model guards); the organizer `SubmissionResource` and three comment-only files in the admin and organizer panels; `cass:health` gains a `submissions` row (Task 6, which the reviewer may strike); and the runbook.
- **A migration release:** run `cass-backup` by hand first, and not within 48 hours of an open deadline. Between the new container starting and `migrate --force` finishing (well under a minute), organizer screens that read abstracts return 500, and so does a first draft saved on the public form.
- **Decide first:** open design choice 1. Filament 5.10.2 now tolerates the existing HasOneThrough under tenancy, so the column is a choice, not a necessity.

## Design

### Invariants, and what holds each

| # | Invariant | Held by | Tested in |
|---|---|---|---|
| I1 | `submissions.organization_id` equals its conference's `organization_id`, for every row, trashed rows included | the migration's backfill; `Submission::creating` (copies it from the conference and refuses any other value); `Submission::updating` (refuses any change of `conference_id` or `organization_id`); `Conference::updating` (refuses any change of `organization_id`); later, NOT NULL plus a composite key (contract release) | T1, T2, T3, T6 |
| I2 | A tenant never sees another tenant's abstract through the resource: the list, its search, filters and filter options, the row actions (view, resend link, withdraw), the export, the view page and its route binding, and global search | Filament's tenancy scope on Submission, which covers every Submission query in the panel; `whereHas('conference')`, which also carries the Conference model's own tenancy scope; `SubmissionPolicy` | the existing negatives, T4, T5 |
| I3 | No relation manager or widget of the resource reads across tenants | the resource has neither | T4 tripwire |
| I4 | An abstract of a deleted conference stays out of the organizer list and view page (today's behaviour) | `whereHas('conference')` | T4 |
| I5 | Nothing changes outside the organizer panel: the public form, the status page, the reviewer panel, the admin panel, the queue and the console | the scope applies only while the organizer panel is current and has a tenant (fact 3) | the existing suites |

### The migration

- The column is nullable, `after('conference_id')`, and named exactly as Filament's ownership relation expects (`organization`, a BelongsTo on `organization_id`).
- **Foreign key to `organizations.id`, ON DELETE RESTRICT**, as `conference_id` restricts. The purge deletes an organization's submissions (per conference) before the organization itself (`PurgeOrganization.php:109-143`), so RESTRICT adds no new failure. CASCADE would delete abstracts without unlinking their files, and SET NULL would break I1.
- **Index on `(organization_id, submitted_at)`.** The tenancy scope's equality plus the list's default sort (`submitted_at desc`). It is declared before the foreign key, so MySQL uses it for the key instead of creating a second index. SQLite creates no index for a foreign key, so declaring it keeps both drivers alike.
- **Backfill in the same migration.** One correlated UPDATE: `organization_id = (select organization_id from conferences where conferences.id = submissions.conference_id)`. It is portable (no `UPDATE … JOIN`) and runs through the query builder, so it ignores soft deletes on both tables and fills trashed rows too. It is idempotent. It is a public `backfill()` method, so the suite can run it on rows it has emptied (the suite migrates an empty database) and the runbook's rollback fix can call the same code.
- **A `Schema::hasColumn` guard around the DDL.** MySQL does not roll back DDL, so a failed backfill would leave the column without a row in `migrations`. The guard lets the next `migrate --force` finish the job instead of stopping on a duplicate column. A failure inside the DDL itself (not plausible: fact 12) is restored from the hand backup.
- **Deferred to a contract release:** NOT NULL, and a composite foreign key `(conference_id, organization_id)` referencing `conferences (id, organization_id)` (which needs a unique index on that pair). That key makes the database refuse a mismatched row and a conference that moves while it has abstracts. A CHECK constraint cannot reach another table, so the composite key is the cross-table check.
- **SQLite:** `after()` is ignored. Adding a foreign key rebuilds the table with `PRAGMA foreign_keys` off, and SQLite migrations run outside a transaction, so the pragma holds (fact 10).
- **MySQL 8.4:** ADD COLUMN is instant. ADD FOREIGN KEY with checks on copies the table, which takes well under a second at production's size.

### The write paths

| Path | Creates a row? | How the column is set |
|---|---|---|
| `SaveSubmissionDraft::handle()`: both public buttons, `UpdateSubmission`, `SeedDemo` | yes (`:52`) | the model hook, from the force-filled `conference_id` (`:70-74`) |
| `SubmitAbstract::handle()` | no: it moves an existing draft to submitted (`:124-170`) | untouched; the update guard sees no change |
| `ImportLegacy` | yes (`:742`, `new Submission([...])`) | the model hook; the file is not edited (fact 6) |
| `SubmissionFactory` | yes (tests) | the model hook, from `conference_id` (`:29`); no factory change |
| raw inserts | `ConferenceRankingPerformanceTest` only | the fixture writes the column itself (T5) |
| every update (`SubmitAbstract`, `WithdrawSubmission`, `ApplyDecision`, `ComputeSubmissionScore`, `SendOneDecisionEmail`, re-saves) | n/a | none of them changes `conference_id`: `SaveSubmissionDraft` refuses another conference (`:45-49`), and its same-value force-fill is not dirty |

### The model

- `organization()` changes from a HasOneThrough to a `BelongsTo<Organization>`. Only `tests/Unit/SubmissionTest.php:139-162` reads it, and its assertions stay. A kept HasOneThrough would add nothing: tests compare the column with the conference directly.
- `Submission::creating` copies `organization_id` from the conference row. It reads past every global scope, because inside the organizer panel the Conference model carries the tenancy scope. It throws `LogicException` when the conference does not exist, and also when a value that was already set disagrees. `organization_id` stays out of `$fillable`.
- `Submission::updating` refuses any change of `conference_id` or `organization_id`. `Conference::updating` refuses any change of `organization_id`. Nothing in `app/` writes either one after creation (fact 5).
- **If a conference's organization is changed anyway** (raw SQL, which the guard cannot see), its abstracts keep the old organization. Inside the organizer panel `whereHas('conference')` then fails for the old tenant and the column fails for the new one, so they vanish from both (fail closed) until the backfill runs again. Task 6's health row turns red until then.

### The resource

- `$isScopedToTenant = false` goes, and `$tenantOwnershipRelationshipName = 'organization'` arrives, as on `ConferenceResource`.
- `observeTenancyModelCreation()` is overridden as a no-op. Filament's `creating` observer would otherwise stamp the current tenant on any submission created while the panel is booted, whichever conference it belongs to, and the order of that observer and the model's own hook is not fixed (fact 3; `CreateConference.php:16-20` met the same problem). Nothing creates an abstract in this panel.
- `getEloquentQuery()` keeps only `whereHas('conference')` and the eager loads. The `1 = 0` fallback and the hand-scoped tenant match both go. Outside a tenant the resource is unscoped, like `ConferenceResource`; Filament's tenant middleware puts a tenant on every organizer request.
- The table's conference filter options stay `$tenant->conferences()`, and the track options stay hand-scoped (tracks have no organization column). Only their comments change.

### Tests, in short

- T1: the schema (column, key, index) and the backfill, as DML only, so the test stays inside RefreshDatabase's transaction on MySQL too. The suite has no MySQL-only pattern (fact 11); CI runs this file on both drivers.
- T2 and T3: every write path stamps the column, and the guards hold.
- T4: pins written against today's hand-scoped resource, one per screen the resource owns. They pass before and after the switch, which is their job.
- T5: two tests that are red first: the panel-wide scope, and creation inside a booted panel (red once tenancy is on, until the observer is switched off). T5 also adapts three existing tests whose meaning the new scope would change (fact 9). No test is weakened.

### What this plan does not do

It touches no policy. It adds no NOT NULL and no composite key. It changes no admin filter behaviour (comments only). It does not edit `app/Actions/Legacy/`. It adds no `Organization::submissions()`: Filament 5.10.2 never calls `getTenantRelationship()`.

## Facts verified before writing this plan

1. **Versions:** Laravel 13.37.0, Filament 5.10.2 (`vendor/` of the main checkout).
2. **The hand scoping.** `SubmissionResource.php:53` sets `$isScopedToTenant = false`. `getEloquentQuery()` at `:71-89` adds the eager loads, `whereRaw('1 = 0')` at `:82` when there is no tenant, and otherwise `whereHas('conference', organization_id = tenant)` at `:85-88`. `SubmissionsTable.php:84-90` is the track filter's option list, not the submission query; `:77-79` builds the conference options from `$tenant->conferences()`.
3. **Filament tenancy** (`vendor/filament/filament/src/`).
   - `Panel.php:85-100` calls `observeTenancyModelCreation()` and `registerTenancyModelGlobalScope()` for every resource when the panel boots.
   - The scope (`Resources/Resource/Concerns/BelongsToTenant.php:128-157`) applies only while that panel is current and has a tenant. For a BelongsTo it is `whereBelongsTo` (`:41-66`), which is `submissions.organization_id = ?`.
   - The `creating` observer associates the current tenant on a BelongsTo (`:159-187`).
   - The `created` observer returns early for BelongsTo, BelongsToThrough and `HasOneOrManyThrough` (`:189-207`). HasOneThrough extends HasOneOrManyThrough (`laravel/framework/.../Relations/HasOneThrough.php:23`), so the fatal described by the backlog and the resource comment no longer exists in 5.10.2.
   - An unscoped resource strips the scope from its own query (`Resource.php:88-105`).
   - `ConferenceResource.php:54` names `organization` as its ownership relation, and the panel calls `->tenant(Organization::class, slugAttribute: 'slug')` (`OrganizerPanelProvider.php:47`).
   - `tests/Pest.php:38-59` (`bootOrganizerPanel()`) boots the panel so that the scope is registered; `:74-96` is `withoutTenant()`.
4. **Records, actions and search.**
   - A row action resolves its record through the table's query and throws `ActionNotResolvableException` ("Record [..] no longer exists.") when the record is not there (`vendor/filament/actions/src/Concerns/InteractsWithActions.php:797-803`). `assertActionDoesNotExist()` counts that exception as "does not exist" (`Testing/TestsActions.php:174-215`).
   - Global search uses `getEloquentQuery()` (`HasGlobalSearch.php:261-264`) and drops any result the policy will not let the user view (`:87-123`). The organizer resource is globally searchable: its record title is `title`, and nothing sets `$isGloballySearchable = false`.
   - The resource overrides neither `getRelations()` nor `getWidgets()`.
5. **The model.**
   - `Submission.php:41-44` is the fillable list. `:72-77` is the `creating` hook that sets the ULID. `:91-119` is the HasOneThrough.
   - `Conference.organization_id` is not fillable (`Conference.php:40-46`). It is set on create by Filament's observer or by `CreateConference.php:37`, and nothing in `app/` writes it afterwards. The admin `ConferenceResource` has list and view pages only.
   - `ReviewQuestion.php:48-86` is the precedent for an invariant held in model events.
6. **Write paths.**
   - `SaveSubmissionDraft.php:52` is the public path's only `new Submission`. Both buttons go through it, as do `UpdateSubmission.php:31` and `SeedDemo.php:356`.
   - `SubmitAbstract.php:124-170` creates nothing.
   - `ImportLegacy.php:742` contains `new Submission([`, seen in a repository-wide grep. This session's permission settings deny `app/Actions/Legacy/`, so the importer was not read. Its coverage here is the model hook plus a new `ImportLegacyTest` case (`tests/Unit/ImportLegacyTest.php:292-312` shows how it runs).
   - Grep, which honours that deny, finds no `saveQuietly`, `withoutEvents`, `insert()` or `upsert()` anywhere else in `app/` or `database/`.
7. **Policies walk through the conference, all with null guards.** `SubmissionPolicy` (`view` at `:59-74`, `decide` at `:166-171`, `canManage` at `:180-186`), `SubmissionFilePolicy::view`, `SubmissionDecisionPolicy::view`, `ReviewPolicy.php:51` and `ReviewAssignmentPolicy.php:89` all reach the organization that way.
   - `TrashedTenantPolicyTest.php:40-58` pins that a trashed conference denies.
   - `ConferenceRanking.php:345-350` asks `decide` with a probe `new Submission` that carries only its conference.
   - Under the new scope, a foreign row's `submission` hop is null inside the organizer panel, and each of these policies already answers that with "no".
8. **Readers that need the conference.** The table's timezone column (`SubmissionsTable.php:64-66`) and the infolist (`SubmissionInfolist.php:63, 90, 97, 120, 163, 189-195`) read `$record->conference->…` with no null guard. `ExportSubmissionsCsv.php:97-103` documents that it relies on its caller's `whereHas('conference')`.
9. **Tests whose meaning the new scope would change** (each reads a foreign row through a scoped query inside a booted panel):
   - `DecisionEmailsTest.php:442`: `$theirs->submissions()->first()?->decision_notified_at` would read null from no row at all.
   - `ConferenceRankingExportTest.php:142`: it hands the writer `Submission::query()` as its "deliberately unscoped" builder.
   - `ConferenceRankingPerformanceTest.php:58-91` raw-inserts 500 rows without the column.
   - `fresh()` and `refresh()` use `newQueryWithoutScopes()`, so `->fresh()` reads stay meaningful.
10. **Laravel.** `Query\Builder::update()` takes a subquery value (`Builder.php:4368-4389`). On SQLite, adding a foreign key rebuilds the table with `PRAGMA foreign_keys` off (`SQLiteGrammar.php:34-42`, `:324-371`). SQLite declares no schema transactions (`Grammar.php:31`; `Migrator.php:448-449`). `Schema::getForeignKeys()` lowercases `on_delete` on both drivers (`MySqlProcessor.php:98`, `SQLiteProcessor.php:95`).
11. **CI** runs the whole suite twice, on SQLite and on MySQL 8.4 (`.github/workflows/ci.yml:171-181`), both under RefreshDatabase transactions. No test checks the driver. On MySQL, DDL inside a test would commit that transaction.
12. **Operations.**
   - The `cass:health` rows are listed at `HealthCommand.php:55-62`; its `migrations` row is red while anything is pending.
   - The deploy script backs up and migrates when something is pending, then runs `cass:health` (runbook "Every release", steps 2-3).
   - The old container stops before the new one starts, so pages that need the new schema return 500 until `migrate --force` has run.
   - "Rollback" (`deploy-production.md:1200-1212`) redeploys an image and leaves the schema alone.
   - `organizations.id` is a `bigIncrements`, matching `foreignId`.
13. **Backlog entry:** `docs/superpowers/plans/backlog.md:37` ("Decided: `SubmissionResource` sets `$isScopedToTenant = false` …"). Line 36 is the withdraw/resend decision.

## Environment and conventions for every task

- **`$REPO`** is `/c/Users/khawl/Desktop/Ahmed/claude/projects/CASS/.claude/worktrees/agent-a2e7a488411e31c3f`, on branch `feat/c5-native-submission-tenancy`, which has no upstream. Write the path itself at the start of every Bash call. If `php -v` fails: `export PATH="/c/Users/khawl/Desktop/Ahmed/claude/tools/php84:/c/Users/khawl/Desktop/Ahmed/claude/tools/composer:$PATH"`. `php -` hangs, so write scripts to files. Never call `composer.bat`.
- **Logs.** Filter runs go to `/tmp/t-F-N-red.log` and `/tmp/t-F-N-green.log`, where N is the task. A task's gates go to `/tmp/t-F-N.log`, `/tmp/stan-F-N.log` and `/tmp/pint-F-N.log`. Task 7 uses the common brief's `/tmp/t-task-F.log`, `/tmp/stan-task-F.log` and `/tmp/pint-task-F.log`. Judge each gate by `rc=` alone. Git Bash's `/tmp` is `C:\Users\khawl\AppData\Local\Temp` for the Read and Write tools.
- **Gates** (each its own command):
  ```bash
  php artisan test > /tmp/t-F-N.log 2>&1; echo "rc=$?"
  ./vendor/bin/phpstan analyse > /tmp/stan-F-N.log 2>&1; echo "rc=$?"
  ./vendor/bin/pint --test > /tmp/pint-F-N.log 2>&1; echo "rc=$?"
  git diff --check; echo "rc=$?"
  ```
  The full suite runs at the end of Tasks 2, 5 and 7: the two that change every write and every organizer read, and the final one. The other tasks run their filter and the test files they touch. Counts are a guide; `rc=0` is the gate.
- **Commits.** Imperative subjects, `git add` by name, and the trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never commit with a gate red. A message that names the host's remote shell, the deploy script or the deployment platform must be written with the Write tool to `C:\Users\khawl\AppData\Local\Temp\commit-F-N.txt` and committed with `git commit -F /tmp/commit-F-N.txt`, because the production hook refuses a command whose text names them.
- **Code and tests.** New PHP files begin `<?php`, a blank line, `declare(strict_types=1);`. Imports are alphabetical (Pint). Comments explain the code and never carry plan, task, brief-item or fact numbers; when you touch a comment that has one, replace it with the fact itself. New tests use Filament's `TestAction` (`callAction(TestAction::make('x')->table($record))`), not the table helpers D3 is removing. Global exception classes need no `use` in a test file.
- **Never:** edit `docs/HANDOVER.md` or `docs/superpowers/plans/backlog.md`; change a policy; open `app/Actions/Legacy/`; run anything on the host.

### Task 1: The column, its keys and the backfill

This task covers C5's "backfill verified in the MySQL suite". One migration adds the nullable column, its index and its foreign key, and fills every row from `conferences`. The backfill is a public method, so the suite can run it on rows it has emptied (DML only, inside RefreshDatabase's transaction on both drivers).

**Files:** create `database/migrations/2026_11_15_000100_add_organization_id_to_submissions_table.php` and `tests/Unit/SubmissionOrganizationMigrationTest.php`.

- [ ] **Step 0: Set up and take the baseline.** `git fetch origin && git rebase origin/main`. Confirm that C6 is in: `git log --oneline origin/main | head -5`. Copy the main checkout's `vendor/` and `public/build/` into `$REPO` (`cp -r`). Then `cp .env.example .env && php artisan key:generate --ansi`, and `php artisan test > /tmp/t-F-baseline.log 2>&1; echo "rc=$?"`. Expected: `rc=0`. Record the passed and skipped counts. If a class is missing because `composer.lock` moved since the main checkout was installed, run `php /c/Users/khawl/Desktop/Ahmed/claude/tools/composer/composer.phar install` instead.
- [ ] **Step 1: The failing test.** Imports: `App\Models\Conference`, `App\Models\Organization`, `App\Models\Submission`, `Illuminate\Foundation\Testing\RefreshDatabase`, `Illuminate\Support\Facades\DB`, `Illuminate\Support\Facades\Schema`.
```php
uses(RefreshDatabase::class);

it('gives submissions a nullable organization_id with a restricting key and a tenant index', function () {
    $column = collect(Schema::getColumns('submissions'))->firstWhere('name', 'organization_id');
    $key = collect(Schema::getForeignKeys('submissions'))->firstWhere('columns', ['organization_id']);
    $index = collect(Schema::getIndexes('submissions'))->firstWhere('columns', ['organization_id', 'submitted_at']);

    expect($column)->not->toBeNull()
        ->and($column['nullable'] ?? null)->toBeTrue()
        ->and($key['foreign_table'] ?? null)->toBe('organizations')
        ->and($key['foreign_columns'] ?? null)->toBe(['id'])
        ->and($key['on_delete'] ?? null)->toBe('restrict')
        ->and($index)->not->toBeNull();
});

it('fills every submission from its conference, trashed rows included, the same on a second run', function () {
    [$alpha, $beta] = Organization::factory()->count(2)->create()->all();
    $deletedConference = Conference::factory()->for($beta)->create();
    $expected = [];

    foreach ([Conference::factory()->for($alpha)->create(), Conference::factory()->for($beta)->create(), $deletedConference, Conference::factory()->for($alpha)->create()] as $conference) {
        $expected[Submission::factory()->for($conference)->create()->id] = $conference->organization_id;
    }

    Submission::query()->latest('id')->firstOrFail()->delete();
    $deletedConference->delete();

    // Every row as up() meets it in production: the column exists and is empty. The suite
    // migrates an empty database, so up() never meets a row here.
    DB::table('submissions')->update(['organization_id' => null]);

    $path = database_path('migrations/2026_11_15_000100_add_organization_id_to_submissions_table.php');
    expect(file_exists($path))->toBeTrue();
    $migration = require $path;
    $migration->backfill();
    $migration->backfill();

    $actual = DB::table('submissions')->pluck('organization_id', 'id')
        ->map(fn (mixed $id): ?int => $id === null ? null : (int) $id)->all();
    ksort($expected);
    ksort($actual);

    expect($actual)->toBe($expected);
});
```
- [ ] **Step 2: Run it red.** `php artisan test --compact --filter=SubmissionOrganizationMigrationTest > /tmp/t-F-1-red.log 2>&1; echo "rc=$?"`. Expected: `rc=1`, 2 failed: "Failed asserting that null is not null." and "Failed asserting that false is true." (`file_exists` guards the `require`, so a missing file fails one case instead of killing the run).
- [ ] **Step 3: The migration.** Imports: `Illuminate\Database\Migrations\Migration`, `Illuminate\Database\Schema\Blueprint`, `Illuminate\Support\Facades\DB`, `Illuminate\Support\Facades\Schema`.
```php
return new class extends Migration
{
    public function up(): void
    {
        // MySQL does not roll back DDL: if the backfill below fails, the column stays without a
        // row in `migrations`, and this lets the next migrate finish the job instead of failing.
        if (! Schema::hasColumn('submissions', 'organization_id')) {
            Schema::table('submissions', function (Blueprint $table) {
                // The conference's organization, copied so the organizer panel's tenancy scope is
                // one indexed comparison rather than a join through conferences. Nullable for now:
                // NOT NULL waits until no deployed image can write a row without it.
                $table->foreignId('organization_id')->nullable()->after('conference_id');
                // The tenant's list, newest first, is the panel's default view. Declared before the
                // key so MySQL uses it for the key instead of adding a second index.
                $table->index(['organization_id', 'submitted_at']);
                // RESTRICT, as conference_id restricts: the purge deletes an organization's
                // submissions, per conference, before the organization itself.
                $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            });
        }

        $this->backfill();
    }

    /**
     * Every row, trashed ones included, takes its conference's organization; a second run
     * changes nothing. Public so the suite can run it on rows whose column it has emptied, and
     * so a rollback across this release can be repaired with the same code (runbook "Rollback").
     */
    public function backfill(): int
    {
        return DB::table('submissions')->update([
            'organization_id' => DB::table('conferences')
                ->select('conferences.organization_id')
                ->whereColumn('conferences.id', 'submissions.conference_id'),
        ]);
    }

    public function down(): void
    {
        // Three calls, in this order: MySQL will not drop an index a foreign key still uses,
        // and on SQLite each call is its own table rebuild.
        Schema::table('submissions', fn (Blueprint $table) => $table->dropForeign(['organization_id']));
        Schema::table('submissions', fn (Blueprint $table) => $table->dropIndex(['organization_id', 'submitted_at']));
        Schema::table('submissions', fn (Blueprint $table) => $table->dropColumn('organization_id'));
    }
};
```
- [ ] **Step 4: Green, then up, down and up on a scratch SQLite file.** First the filter to `/tmp/t-F-1-green.log`: `rc=0`, 2 passed. Then:
```bash
cd $REPO && rm -f /tmp/c5-scratch.sqlite && touch /tmp/c5-scratch.sqlite
DB_CONNECTION=sqlite DB_DATABASE=C:/Users/khawl/AppData/Local/Temp/c5-scratch.sqlite php artisan migrate --force > /tmp/t-F-1-up.log 2>&1; echo "up rc=$?"
DB_CONNECTION=sqlite DB_DATABASE=C:/Users/khawl/AppData/Local/Temp/c5-scratch.sqlite php artisan migrate:rollback --step=1 --force > /tmp/t-F-1-down.log 2>&1; echo "down rc=$?"
DB_CONNECTION=sqlite DB_DATABASE=C:/Users/khawl/AppData/Local/Temp/c5-scratch.sqlite php artisan migrate --force > /tmp/t-F-1-up2.log 2>&1; echo "up2 rc=$?"
```
  All three must print `rc=0`.
- [ ] **Step 5: The same file on MySQL 8.4** (optional; CI's `Tests (MySQL)` step is the authority). This needs Docker Desktop to be running: `export PATH="/c/Users/khawl/AppData/Local/Programs/DockerDesktop/resources/bin:$PATH" && docker compose -f docker-compose.dev.yml up -d mysql`. Then run `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass php artisan test --compact --filter=SubmissionOrganizationMigrationTest > /tmp/t-F-1-mysql.log 2>&1; echo "rc=$?"`. If it cannot run, say so in the report.
- [ ] **Step 6: Gates, then commit.** Run Larastan, which reads `database/`; Pint; and the diff check. Commit the two files: `feat(db): submissions carry their conference's organization_id, backfilled`.

### Task 2: Every new submission carries its conference's organization

The model owns invariant I1 at creation for every path: the public form, the demo seeder, the importer and the factory. It copies the organization from the conference row, refuses a disagreeing value, and swaps the HasOneThrough for the BelongsTo Filament will scope on. No Action changes.

**Files:** modify `app/Models/Submission.php`, `tests/Unit/SubmissionTest.php`, `tests/Unit/SaveSubmissionDraftTest.php`, `tests/Feature/Public/SubmissionFormTest.php`, `tests/Unit/ImportLegacyTest.php` and `tests/Feature/Console/DemoSeedTest.php`.

- [ ] **Step 1: Check C6.** Run `grep -rnE "organization\(\)|->organization\b" app/Policies app/Support`. Every policy that reaches a submission's tenant must still walk `conference?->organization` (fact 7). Stop and report if C6 made any policy, trait or base class read `Submission::organization()`: this task turns that relation into a BelongsTo, a trashed conference would stop denying, and `TrashedTenantPolicyTest` would go red.
- [ ] **Step 2: The failing tests.** In `tests/Unit/SubmissionTest.php`:
  - Add `['organization_id', 1]` to the mass-assignment dataset, and change "These nine" to "These ten" in its comment.
  - Rename `'names its organization through its conference, lazily and eagerly'` to `'names its organization, the same as its conference does, lazily and eagerly'`. Replace its comment's first sentence with: "A belongsTo on the copied column. The decoy rows still give a wrong key something to land on: the conference id and the organization id differ for both submissions." Leave its assertions as they are.
  - Add:
```php
it('copies its conference organization when it is created, whoever creates it', function () {
    $organization = Organization::factory()->create();
    $conference = Conference::factory()->for($organization)->create();

    // The importer's shape: a bare model, the conference force-filled, no organization at all.
    $bare = new Submission(['title' => 'A bare abstract', 'abstract' => 'Text.']);
    $bare->forceFill(['conference_id' => $conference->id, 'status' => SubmissionStatus::Draft, 'access_token_hash' => str_repeat('b', 64)])->save();

    expect(Submission::factory()->for($conference)->create()->refresh()->organization_id)->toBe($organization->id)
        ->and($bare->refresh()->organization_id)->toBe($organization->id)
        ->and($bare->organization?->is($organization))->toBeTrue();
});

it('refuses an organization other than its conference organization, and a conference that does not exist', function () {
    $conference = Conference::factory()->create();

    expect(fn () => Submission::factory()->for($conference)->create(['organization_id' => Organization::factory()->create()->id]))
        ->toThrow(LogicException::class);
    expect(fn () => Submission::factory()->create(['conference_id' => 999999]))
        ->toThrow(LogicException::class);
    expect(Submission::query()->count())->toBe(0);
});
```
  In `SaveSubmissionDraftTest`, add:
```php
it('files a new draft under its conference organization', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());

    expect($link->submission->refresh()->organization_id)->toBe($this->conference->organization_id);
});
```
  In `SubmissionFormTest`, after `'saves a draft, emails the author and redirects to the status page'`, add:
```php
it('files a first draft from the public form under the conference organization', function () {
    fillForm(livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference]))
        ->call('saveDraft')->assertHasNoErrors();

    expect(Submission::query()->sole()->organization_id)->toBe($this->organization->id);
});
```
  In `ImportLegacyTest`, after `'imports every abstract with a reference, a token and its conference counter raised'`, add:
```php
it('files every imported abstract under the organization it adopts', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    expect(Submission::withTrashed()->count())->toBeGreaterThan(0)
        ->and(Submission::withTrashed()->pluck('organization_id')->unique()->values()->all())->toBe([$this->organization->id]);
});
```
  In `DemoSeedTest`, add:
```php
it('files every demo abstract under the demo organization', function () {
    $this->artisan('cass:demo-seed', ['--stage' => 'open', '--reviewer-password' => 'demo-password-1234'])->assertSuccessful();
    $organization = Organization::query()->where('slug', 'demo-society')->firstOrFail();

    expect(Submission::query()->count())->toBeGreaterThan(0)
        ->and(Submission::query()->pluck('organization_id')->unique()->values()->all())->toBe([$organization->id]);
});
```
- [ ] **Step 3: Run them red.** `php artisan test --compact tests/Unit/SubmissionTest.php tests/Unit/SaveSubmissionDraftTest.php tests/Feature/Public/SubmissionFormTest.php tests/Unit/ImportLegacyTest.php tests/Feature/Console/DemoSeedTest.php > /tmp/t-F-2-red.log 2>&1; echo "rc=$?"`. Expected: `rc=1`, 6 failed, every other case passing. Four fail on a null column. The refusal case fails with "Failed asserting that exception of type "LogicException" is thrown." The new dataset row passes: it pins what the fillable list already refuses.
- [ ] **Step 4: The model.** In `Submission.php`:
  - Import `LogicException`, and drop `HasOneThrough`.
  - In the `$fillable` docblock, "Nine attributes" becomes "Ten attributes", and `organization_id` (copied from the conference in booted()) is added after `conference_id`.
  - `booted()`'s `creating` closure gains, after the ULID line:
```php
            // The tenant is the conference's, copied here for every path that creates an
            // abstract, so the organizer panel can scope on one indexed column. Read past every
            // global scope: inside that panel the conference carries the tenancy scope, and an
            // import or a fixture may create an abstract for a conference that scope hides.
            $organizationId = Conference::query()->withoutGlobalScopes()
                ->whereKey($submission->conference_id)
                ->value('organization_id');

            if ($organizationId === null) {
                throw new LogicException('An abstract needs an existing conference.');
            }

            if ($submission->organization_id !== null && (int) $submission->organization_id !== (int) $organizationId) {
                throw new LogicException("An abstract belongs to its conference's organization and to no other.");
            }

            $submission->organization_id = (int) $organizationId;
```
  - `organization()` becomes:
```php
    /**
     * The tenant: the conference's organization, copied onto the row when it is created and
     * fixed afterwards (booted()). The organizer panel's tenancy scope filters on it; the
     * policies still walk through the conference, which is what makes an abstract of a
     * deleted conference answer "no".
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
```
- [ ] **Step 5: Green, the full suite, gates, commit.** The Step 3 command, writing to `/tmp/t-F-2-green.log`, must give `rc=0`. The full suite must give `rc=0` at baseline + 9. A red elsewhere is a real regression; read it before changing anything. Commit with `feat(submissions): every new abstract carries its conference's organization`.

### Task 3: Nothing moves an abstract or a conference to another organization

This task closes invariant I1 for updates. `SaveSubmissionDraft` already refuses another conference for an existing row, and Eloquent now refuses it on every path. A conference never changes organization either; nothing in `app/` does it today, and from now on nothing can through Eloquent.

**Files:** modify `app/Models/Submission.php`, `app/Models/Conference.php`, `tests/Unit/SubmissionTest.php` and `tests/Unit/ConferenceTest.php`.

- [ ] **Step 1: The failing tests.** In `SubmissionTest`:
```php
it('never moves an abstract to another conference or organization, and still saves anything else', function () {
    $submission = Submission::factory()->create();
    $elsewhere = Conference::factory()->create();

    expect(fn () => $submission->forceFill(['conference_id' => $elsewhere->id])->save())->toThrow(LogicException::class);
    expect(fn () => $submission->refresh()->forceFill(['organization_id' => $elsewhere->organization_id])->save())->toThrow(LogicException::class);

    $submission->refresh()->forceFill(['title' => 'Renamed'])->save();

    expect($submission->fresh()?->conference_id)->not->toBe($elsewhere->id)
        ->and($submission->fresh()?->organization_id)->not->toBe($elsewhere->organization_id)
        ->and($submission->fresh()?->title)->toBe('Renamed');
});
```
  In `ConferenceTest`:
```php
it('never moves a conference to another organization, and still saves anything else', function () {
    $conference = Conference::factory()->create();
    $other = Organization::factory()->create();

    expect(fn () => $conference->forceFill(['organization_id' => $other->id])->save())->toThrow(LogicException::class);

    $conference->refresh()->forceFill(['name' => 'Renamed'])->save();

    expect($conference->fresh()?->organization_id)->not->toBe($other->id)
        ->and($conference->fresh()?->name)->toBe('Renamed');
});
```
- [ ] **Step 2: Run them red.** `php artisan test --compact tests/Unit/SubmissionTest.php tests/Unit/ConferenceTest.php > /tmp/t-F-3-red.log 2>&1; echo "rc=$?"`. Expected: `rc=1`, 2 failed, both with "Failed asserting that exception of type "LogicException" is thrown."
- [ ] **Step 3: The guards.** In `Submission::booted()`, after the `creating` closure:
```php
        // An abstract never moves. SaveSubmissionDraft refuses another conference for an
        // existing row; this holds for every other path, and the organization is the
        // conference's, so it is fixed with it.
        static::updating(function (Submission $submission): void {
            if ($submission->isDirty(['conference_id', 'organization_id'])) {
                throw new LogicException('An abstract never moves to another conference or organization.');
            }
        });
```
  In `Conference::booted()`, after the `creating` closure (import `LogicException`):
```php
        // A conference never changes organization: every abstract copies its conference's
        // organization when it is created (Submission::booted()), and nothing in the
        // application moves a conference, so a change here can only be a mistake.
        static::updating(function (Conference $conference): void {
            if ($conference->isDirty('organization_id')) {
                throw new LogicException('A conference never moves to another organization.');
            }
        });
```
- [ ] **Step 4: Green, gates, commit.** The Step 2 command, to `/tmp/t-F-3-green.log`, must give `rc=0`. Then run the files that save conferences and submissions most: `php artisan test --compact tests/Unit tests/Feature/Organizer/ConferenceResourceTest.php tests/Feature/Organizer/ConferenceTransitionsTest.php > /tmp/t-F-3.log 2>&1; echo "rc=$?"`, which must give `rc=0`. Then Larastan, Pint and the diff check. Commit with `feat(models): an abstract or a conference never moves to another organization`.

### Task 4: Pin the cross-tenant negatives on every screen the resource owns

These are characterisation tests, written against today's hand-scoped resource. They pass now, and Task 5 must leave them passing: that is the proof the switch opened no screen. The existing negatives cover the list, the view page's 404, the file policy and the action policies. These cases add the search, the filters and their options, each row action, the filtered export, global search, the deleted-conference exclusion, and a tripwire for relation managers and widgets.

**Files:** modify `tests/Feature/Organizer/SubmissionResourceTest.php` (its `beforeEach` boots the panel for `$this->organization`; the abstract `AAM26-017` is titled "Early mobilisation after cardiac surgery"). Imports: add `Filament\Actions\Testing\TestAction`, `Filament\GlobalSearch\GlobalSearchResult` and `Filament\Tables\Filters\SelectFilter`.

- [ ] **Step 1: The pins,** under a new heading comment `// --- Cross-tenant: every screen this resource owns ---`:
```php
it('finds no other organization abstract through the search, the filters or their options', function () {
    [$theirConference, $theirTrack, $theirs] = withoutTenant(function (): array {
        $conference = Conference::factory()->published()->create(['name' => 'Beta Congress']);
        $track = Track::factory()->for($conference)->create(['name' => 'Beta track']);
        $submission = Submission::factory()->for($conference)->submitted()->withCorrespondingAuthor('victim@example.org')
            ->create(['title' => 'Somebody else entirely', 'track_id' => $track->id]);

        return [$conference, $track, $submission];
    });
    $ownOnly = fn (int $mine, int $theirsKey): Closure => fn (SelectFilter $filter): bool => array_key_exists($mine, $filter->getOptions())
        && ! array_key_exists($theirsKey, $filter->getOptions());

    livewire(ListSubmissions::class)
        ->searchTable('Somebody else')->assertCountTableRecords(0)
        ->searchTable('victim@example.org')->assertCountTableRecords(0)
        ->searchTable((string) $theirs->reference)->assertCountTableRecords(0)
        ->searchTable(null)
        ->filterTable('conference_id', $theirConference->getKey())->assertCountTableRecords(0)
        ->resetTableFilters()
        ->filterTable('track_id', $theirTrack->getKey())->assertCountTableRecords(0)
        ->resetTableFilters()
        ->assertTableFilterExists('conference_id', $ownOnly($this->conference->getKey(), $theirConference->getKey()))
        ->assertTableFilterExists('track_id', fn (SelectFilter $filter): bool => ! array_key_exists($theirTrack->getKey(), $filter->getOptions()))
        // The positive half: the same screen still finds this organization's abstract.
        ->searchTable('Early mobilisation')->assertCanSeeTableRecords([$this->submission]);
});

it('resolves no row action on another organization abstract', function (string $action) {
    $theirs = withoutTenant(fn (): Submission => Submission::factory()->submitted()->withCorrespondingAuthor('victim@example.org')->create());

    livewire(ListSubmissions::class)
        ->assertActionVisible(TestAction::make($action)->table($this->submission))
        ->assertActionDoesNotExist(TestAction::make($action)->table($theirs));
})->with(['view', 'resendLink', 'withdraw']);

it('exports no other organization abstract, even filtered to that organization conference', function () {
    $theirConference = withoutTenant(fn (): Conference => Conference::factory()->published()->create());
    withoutTenant(fn () => Submission::factory()->for($theirConference)->submitted()->create(['title' => 'Another tenant']));

    $component = livewire(ListSubmissions::class)
        ->filterTable('conference_id', $theirConference->getKey())
        ->callAction(TestAction::make('export')->table())
        ->assertFileDownloaded();
    $csv = (string) base64_decode((string) data_get($component->effects, 'download.content'), true);

    // Neither row: the filter took (this organization's abstract is not in it either).
    expect($csv)->not->toContain('Another tenant')->not->toContain('AAM26-017');
});

it('answers a global search with this organization abstracts only', function () {
    withoutTenant(fn () => Submission::factory()->submitted()->create(['title' => 'Early mobilisation in another society']));

    expect(SubmissionResource::getGlobalSearchResults('Early mobilisation')
        ->map(fn (GlobalSearchResult $result): string => (string) $result->title)->values()->all())
        ->toBe(['Early mobilisation after cardiac surgery']);
});

it('keeps an abstract of a deleted conference out of the list and the view page', function () {
    $kept = Submission::factory()->for(Conference::factory()->for($this->organization)->published())->submitted()->create();
    $this->conference->delete();

    livewire(ListSubmissions::class)
        ->assertCanSeeTableRecords([$kept])
        ->assertCanNotSeeTableRecords([$this->submission]);

    get(SubmissionResource::getUrl('view', ['record' => $this->submission->getRouteKey()], tenant: $this->organization))
        ->assertNotFound();
});

it('has no relation manager and no widget, either of which would need a negative of its own', function () {
    // Either would read its own model, which this resource's tenancy does not scope.
    expect(SubmissionResource::getRelations())->toBe([])
        ->and(SubmissionResource::getWidgets())->toBe([]);
});
```
- [ ] **Step 2: Run them; they pin.** `php artisan test --compact tests/Feature/Organizer/SubmissionResourceTest.php > /tmp/t-F-4-green.log 2>&1; echo "rc=$?"`. Expected: `rc=0`, the file's existing cases plus 8 new ones. A red here is a leak in today's code: stop and report it.
- [ ] **Step 3: Show that they bite** (recommended). Temporarily make `SubmissionResource::getEloquentQuery()` return `Submission::query()->withoutGlobalScopes()->with(['conference', 'track', 'authors'])`, then run the Step 2 command to `/tmp/t-F-4-red.log`. Expected: `rc=1`. Every case that mounts the list fails or errors, because a foreign row has no visible conference and its timezone column throws. The global search case stays green, because `SubmissionPolicy::view()` also refuses a foreign row its URL. The tripwire stays green. Restore the file (`git diff --stat` shows only the test file), then re-run green.
- [ ] **Step 4: Gates, commit.** Pint and the diff check. Commit with `test(organizer): pin the cross-tenant negatives on every submission screen`.

### Task 5: The resource returns to Filament tenancy

The resource drops its override and its hand-scoped query, and names its ownership relation. It switches off Filament's creating observer, which would re-stamp the current tenant on any abstract created while the panel is booted. Three existing tests are adapted where the new panel-wide scope would change what they prove (fact 9). The comments that described the old arrangement are corrected.

**Files:** create `tests/Feature/Organizer/SubmissionTenancyTest.php`. Modify `app/Filament/Organizer/Resources/Submissions/SubmissionResource.php`, `.../Submissions/Tables/SubmissionsTable.php`, `.../Submissions/Tables/SubmissionActions.php`, `app/Filament/Organizer/Resources/Conferences/Pages/ConferenceRanking.php`, `app/Filament/Admin/Resources/Submissions/SubmissionResource.php`, `.../Admin/Resources/Submissions/Tables/SubmissionsTable.php`, `.../Admin/Resources/Reviews/Tables/ReviewsTable.php`, `tests/Pest.php`, `tests/Feature/Organizer/ConferenceRankingPerformanceTest.php`, `tests/Feature/Organizer/DecisionEmailsTest.php` and `tests/Feature/Organizer/ConferenceRankingExportTest.php`.

- [ ] **Step 1: The failing tests.** Imports: `App\Enums\OrganizationRole`, `App\Filament\Organizer\Resources\Submissions\SubmissionResource`, `App\Models\Conference`, `App\Models\Organization`, `App\Models\Submission`, `App\Models\User`, and `use function Pest\Laravel\actingAs;`.
```php
/**
 * The organizer panel's tenancy on Submission is a mechanism for every query in the panel, not
 * a convention in one resource. The model boots before the panel on purpose: a `creating`
 * observer that the panel registered would then run after the model's own and have the last
 * word, which is an order a real request can meet them in.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $user = User::factory()->create();
    $this->organization->addMember($user, OrganizationRole::Owner);
    actingAs($user);

    $this->theirConference = Conference::factory()->for(Organization::factory()->approved())->published()->create();
    $this->mine = Submission::factory()->for(Conference::factory()->for($this->organization)->published())->submitted()->create();
    $this->theirs = Submission::factory()->for($this->theirConference)->submitted()->create();

    bootOrganizerPanel($this->organization);
});

it('scopes every submission query in the organizer panel to the tenant, through the column', function () {
    expect(SubmissionResource::isScopedToTenant())->toBeTrue()
        ->and(SubmissionResource::getTenantOwnershipRelationshipName())->toBe('organization')
        ->and(Submission::query()->pluck('id')->all())->toBe([$this->mine->id])
        ->and(Submission::query()->find($this->theirs->id))->toBeNull()
        // A page that reads abstracts through a conference is scoped as well.
        ->and($this->theirConference->submissions()->count())->toBe(0);
});

it('keeps the conference organization on a submission created while the panel is booted for another tenant', function () {
    $created = Submission::factory()->for($this->theirConference)->create();

    expect($created->fresh()?->organization_id)->toBe($this->theirConference->organization_id);
});
```
- [ ] **Step 2: Run them red.** `php artisan test --compact --filter=SubmissionTenancyTest > /tmp/t-F-5-red.log 2>&1; echo "rc=$?"`. Expected: `rc=1`. The first case fails ("Failed asserting that false is true."). The second passes, because no observer exists for Submission yet.
- [ ] **Step 3: Switch the resource, without the observer change.**
  - In `SubmissionResource`, delete `$isScopedToTenant = false` and its docblock, and add `protected static ?string $tenantOwnershipRelationshipName = 'organization';`.
  - Replace the "No $recordRouteKeyName" comment's provenance reference with the fact itself: the model's route key is the ULID, and Filament builds URLs from `getRouteKey()`.
  - Drop the imports of `Organization` and `Filament\Facades\Filament`.
  - Replace `getEloquentQuery()` with:
```php
    /**
     * Tenancy is the panel's scope, not this method. What stays here is the conference: an
     * abstract whose conference was deleted is neither listed nor opened, because the
     * timezone column and the infolist read the conference, and ExportSubmissionsCsv relies
     * on the same exclusion. Inside the panel this whereHas also carries the Conference
     * model's own tenancy scope, so it is a second filter on the tenant as well.
     *
     * @return Builder<Submission>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('conference')
            ->with(['conference', 'track', 'authors']);
    }
```
  - Change the class docblock to: "Every abstract of the tenant, under Filament's tenancy like ConferenceResource. The panel puts a global scope on Submission that matches `submissions.organization_id` to the tenant, so every Submission query in an organizer request is scoped: this list and its search, filters and export, the view page and its route binding, global search, and every ConferenceResource page that reads abstracts. SubmissionPolicy is the second, independent gate."
  - Re-run the Step 2 command to `/tmp/t-F-5-red2.log`. Expected: `rc=1`. The first case passes. The second fails, its organization now the tenant's: Filament's creating observer re-stamped it.
- [ ] **Step 4: Switch off the creating observer.** Import `Filament\Panel` and add:
```php
    /**
     * Not registered for this resource. Filament's tenancy also adds a `creating` observer that
     * stamps the current tenant on any submission created while this panel is booted, whichever
     * conference it belongs to. Nobody creates an abstract in this panel, and the model copies
     * the organization from the conference (Submission::booted()), so that observer could only
     * replace a right answer with a wrong one. The query scope is registered as usual.
     */
    public static function observeTenancyModelCreation(Panel $panel): void {}
```
  The filter, to `/tmp/t-F-5-green.log`, must give `rc=0`, 2 passed.
- [ ] **Step 5: Adapt the three tests the scope changes,** with no assertion weakened.
  - **`ConferenceRankingPerformanceTest`:** add `'organization_id' => $this->organization->id,` after `'conference_id'` in the raw rows, with the comment "A raw insert skips the model, which is what copies the organization; without it the panel's tenancy scope hides every row." Without this, both query-count cases fail on `assertCountTableRecords(500)`.
  - **`DecisionEmailsTest:442`** becomes:
```php
    // Read past the panel's tenancy scope: inside it another organization's abstract is not
    // there to read, and a null from no row would pass this for the wrong reason.
    $row = withoutTenant(fn () => $theirs->submissions()->first());
    expect($row)->not->toBeNull()->and($row?->decision_notified_at)->toBeNull();
```
  - **`ConferenceRankingExportTest:142`:** hand the writer `Submission::query()->withoutGlobalScopes()`, with the comment "Unscoped on purpose, the panel's tenancy scope included: the writer's own constraint is what this case is about." This keeps the stranger row testing the writer, not the scope.
- [ ] **Step 6: Correct the comments that described the old arrangement.** No code changes in this step.
  - Organizer `SubmissionsTable.php:22-26`: "Same narrowing as SubmissionResource::getEloquentQuery() and ConferenceForm." becomes "Same narrowing as ConferenceForm."
  - Organizer `SubmissionsTable.php:73-76` becomes "The tenant's conferences, asked of the tenant itself, so this option list never depends on how another resource is scoped."
  - Organizer `SubmissionsTable.php:84-90`: add a comment above the track options: "Tracks have no organization column: through the conference."
  - `SubmissionActions.php:100-102`: "already tenant-scoped by SubmissionResource::getEloquentQuery()" becomes "already tenant-scoped by the panel's tenancy scope".
  - `ConferenceRanking.php:40-46` becomes "…SubmissionResource is already the panel-wide list, and a second Resource over Submission would be a second list of the same rows. A page inherits ConferenceResource's tenancy through resolveRecord() and then scopes by conference_id."
  - Admin `SubmissionResource.php:38-41` becomes "No tenancy here: AdminPanelProvider never calls ->tenant(), so Filament registers no scope and no observer for this resource, and the organizer panel's scope on Submission does nothing while this panel is the current one."
  - Admin `SubmissionsTable.php:79-81` becomes "Through the conference, which leaves out an abstract whose conference was deleted although the list shows it; `submissions.organization_id` would find it."
  - Admin `ReviewsTable.php:68-70` becomes "Through the abstract: a review has no conference_id."
  - `tests/Pest.php:74-79` gains: "Reads too: inside a booted organizer panel every Conference and Submission query is tenant-scoped, so read another organization's rows in here, or with fresh() or refresh(), which skip global scopes."
- [ ] **Step 7: The full suite, gates, commit.** The full suite must give `rc=0` at baseline + 21. For any other red: if its own fixture reads another organization's abstract through a scoped query, read it inside `withoutTenant()` and also assert that the row exists, then list the test in the report. Any other red is a regression: stop and report it. Then Larastan (which also catches the dropped imports), Pint and the diff check. Commit with `feat(organizer): submissions return to Filament tenancy on organization_id`.

### Task 6: `cass:health` reports abstracts without their conference's organization (recommended; the reviewer may strike it)

For as long as the column may be null (the expand window), this row is the alarm for the one way invariant I1 can break silently. Rolling back below this release leaves new drafts without an organization, and SQL that goes around the model can do the same. The deploy script and the hourly heartbeat already run `cass:health`. If struck, Task 7 uses the read-only count instead. `HealthCommand` is also edited by C3 (a disk row): rebase onto it if it has merged, and keep both rows.

**Files:** modify `app/Console/Commands/HealthCommand.php`, `tests/Unit/HealthCommandTest.php` and `docs/runbooks/deploy-production.md` (the "Health" table).

- [ ] **Step 1: The failing test.** Imports: add `App\Models\Organization` and `App\Models\Submission`.
```php
it('fails when an abstract does not carry its conference organization', function () {
    $submission = Submission::factory()->create();
    artisan('cass:health')->expectsOutputToContain("every abstract carries its conference's organization")->assertExitCode(0);

    DB::table('submissions')->where('id', $submission->id)->update(['organization_id' => null]);
    artisan('cass:health')->expectsOutputToContain('1 abstract(s) without')->assertExitCode(1);

    DB::table('submissions')->where('id', $submission->id)->update(['organization_id' => Organization::factory()->create()->id]);
    artisan('cass:health')->assertExitCode(1);
});
```
  Run red: `php artisan test --compact --filter=HealthCommandTest > /tmp/t-F-6-red.log 2>&1; echo "rc=$?"`. Expected: `rc=1`, 1 failed, on the first output expectation.
- [ ] **Step 2: The row.** Import `Illuminate\Database\Query\Builder`. Add `$this->checkSubmissionOrganizations(),` after `$this->checkMigrations(),` in `handle()`. Add to the class docblock's list: "an abstract without its conference's organization is invisible to every organizer: written by an older image after a rollback, or by SQL that went around the model". Then add:
```php
    /**
     * An abstract shows in the organizer panel only to the organization on its row, so a row
     * without its conference's organization, or with another, is an abstract no organizer sees.
     * The fix is the backfill under the runbook's "Rollback".
     *
     * @return array{name: string, ok: bool, detail: string}
     */
    private function checkSubmissionOrganizations(): array
    {
        try {
            $wrong = DB::table('submissions')
                ->join('conferences', 'conferences.id', '=', 'submissions.conference_id')
                ->where(fn (Builder $query): Builder => $query
                    ->whereNull('submissions.organization_id')
                    ->orWhereColumn('submissions.organization_id', '!=', 'conferences.organization_id'))
                ->count();
        } catch (Throwable $e) {
            return ['name' => 'submissions', 'ok' => false, 'detail' => $e->getMessage()];
        }

        return $wrong === 0
            ? ['name' => 'submissions', 'ok' => true, 'detail' => "every abstract carries its conference's organization"]
            : ['name' => 'submissions', 'ok' => false, 'detail' => "{$wrong} abstract(s) without their conference's organization - run the backfill under Rollback in the runbook"];
    }
```
- [ ] **Step 3: The runbook's "Health" table** gains, after the `migrations` row: "| `submissions` | an abstract does not carry its conference's organization, written by an image older than that column after a rollback, or by SQL that went around the application. No organizer sees it in the panel. Run the backfill under **Rollback**. |"
- [ ] **Step 4: Green, gates, commit.** The filter, to `/tmp/t-F-6-green.log`, must give `rc=0`. Then Larastan, Pint and the diff check. Commit with `feat(health): report abstracts without their conference's organization`.

### Task 7: The runbook, the final gates and the pull request

**Files:** modify `docs/runbooks/deploy-production.md` ("Every release" step 3 and "Rollback").

- [ ] **Step 1: "Every release", step 3.** After the paragraph that begins "Plan 5 is such a release" (line 99) and before step 4, add the paragraph below, indented three spaces like its neighbours:
```markdown
   Native submission tenancy is such a release: one column on `submissions`, `organization_id` (an index with `submitted_at`, a foreign key to `organizations`), filled from `conferences` by the same migration. Until `migrate --force` has run, every organizer screen that reads abstracts returns 500 (the submission list and view, the conference view's counts, the ranking, the assignments, the decisions), and so does a first draft saved on the public form; a draft edited through its status link, the status page and the reviewer panel keep working. Afterwards `cass:health` shows its `submissions` row OK.
```
  If Task 6 was struck, replace the last sentence with "Afterwards the read-only count under **Rollback** prints 0".
- [ ] **Step 2: "Rollback".** After the first paragraph, add:
```markdown
Rolling back to an image older than native submission tenancy: the migration stays, but that image does not write `submissions.organization_id`, so an abstract first saved while it runs belongs to no organization, and after you roll forward again no organizer sees it until the column is filled again (`cass:health` shows its `submissions` row red). Once the newer image is back, run the migration's own backfill; rows already right stay as they are:
```
  Then add a top-level ```bash block (not nested inside the paragraph) with:
  - `C=$(cass_container app)`
  - `sudo docker exec "$C" su-exec app php artisan tinker --execute='echo (require database_path("migrations/2026_11_15_000100_add_organization_id_to_submissions_table.php"))->backfill(), PHP_EOL;'`
  - `sudo docker exec "$C" su-exec app php artisan cass:health`

  If Task 6 was struck, the last command becomes this read-only count, which must print 0: `sudo docker exec "$C" su-exec app php artisan tinker --execute='echo DB::table("submissions")->join("conferences", "conferences.id", "=", "submissions.conference_id")->where(fn ($q) => $q->whereNull("submissions.organization_id")->orWhereColumn("submissions.organization_id", "!=", "conferences.organization_id"))->count(), PHP_EOL;'`
- [ ] **Step 3: The final gates,** to the common brief's logs: `/tmp/t-task-F.log` (the suite at baseline + 22, or + 21 without Task 6), `/tmp/stan-task-F.log`, `/tmp/pint-task-F.log`, and `git diff --check`. All four must give `rc=0`. Optionally, if Docker is up, rerun Task 1 Step 5 with `--filter='SubmissionOrganizationMigrationTest|SubmissionTenancyTest'` to `/tmp/t-F-7-mysql.log`.
- [ ] **Step 4: Commit, push, open the pull request.** Commit with `docs(runbook): the native submission tenancy release and its rollback`. Push with `git push -u origin feat/c5-native-submission-tenancy`. Write the body with the Write tool to `C:\Users\khawl\AppData\Local\Temp\pr-F.md`, then run `gh pr create --base main --title "feat: submissions carry their organization; the organizer resource uses Filament tenancy" --body-file /tmp/pr-F.md`. The body follows the common brief:
  - one paragraph on what and why;
  - "Files changed";
  - "For review": the migration as the one sensitive file; the migration's name; no environment variables; the tests added; the three tests adapted and why (Task 5, Step 5); whether Task 6 was struck; the gate logs with their `rc`; whether Task 1 Step 5 ran;
  - and the attribution line.

  Report the lines in "Lines to record later" as `closes=`.

## Rollout (the owner, with the orchestrating session, after merge)

A migration release (`CLAUDE.md` "Production", runbook "Every release").

1. **When.** CI is green on `main` at the merge commit, in both test steps (the MySQL step runs the backfill test). No open conference has a submission or review deadline within 48 hours. Nothing else merges to `main` until `DEPLOY_DONE`.
2. **Hand backup first:** `sudo /usr/local/bin/cass-backup` on the host. Note the dump's name.
3. **Unlock and deploy** through the deploy script (runbook "Every release", step 2). Expected:
   - `migrate:status` lists `2026_11_15_000100_add_organization_id_to_submissions_table` as Pending. The script backs up again and runs `migrate --force`.
   - `migrate:status` then shows every migration as Ran, the new one in the newest batch, and nothing Pending.
   - The eight probes answer as expected.
   - `cass:health` shows every row OK, among them `migrations` ("N applied, none pending") and `submissions` ("every abstract carries its conference's organization"), plus C3's `disk` row if C3 has shipped. It exits 0.
   - The script prints `DEPLOY_DONE`.
4. **The window.** From the new container's first request until `migrate --force` ends, organizer screens that read abstracts and a first draft on the public form answer 500. That is a backup plus one migration over a few hundred rows: well under a minute.
5. **Check.** As an owner of a real organization, `/org/{slug}/submissions` lists as many abstracts as that organization's conference views total, and one conference in review shows its ranking. If Task 6 was struck, the read-only count from the runbook must print 0.
6. **Record** the production commit, the migration, the hand dump's name and the check in `docs/HANDOVER.md`.

**If it fails:**
- `DEPLOY_FAILED` at the migration (exit 5), with an error that names `update` on `submissions` (the backfill): run `migrate --force` again. The migration finds its column and only fills it.
- An error that names `alter table`: restore the hand backup first (runbook "Backups"), then ask.
- **A rollback to the previous image** is safe for the schema, but read the new "Rollback" note: abstracts saved under the older image need the backfill line after rolling forward.

## Lines to record later (not edited in this branch)

- **Backlog, close** the entry that begins "Decided: `SubmissionResource` sets `$isScopedToTenant = false`" (`backlog.md:37` today; C8 may move it, so find it by its text): "Closed by C5 (#NN, date). Submissions carry `organization_id`, copied from the conference by the model on create and backfilled by `2026_11_15_000100_…`. `SubmissionResource` uses Filament tenancy on `organization` (a BelongsTo), with Filament's creating observer switched off for it, and keeps `whereHas('conference')` only to leave out abstracts of a deleted conference. The fatal described here no longer existed: Filament 5.10.2's tenancy `created` observer returns early for `HasOneOrManyThrough`."
- **Backlog, add** "Contract `submissions.organization_id`": NOT NULL, a unique index on `conferences (id, organization_id)`, and a composite foreign key `(conference_id, organization_id)` referencing it. The database then refuses a mismatched abstract and a conference that moves while it has abstracts. Trigger: the release after C5, once `cass:health`'s `submissions` row has stayed OK since the deploy. After it, an image older than C5 cannot save a draft at all.
- **Backlog, add** "The admin organization filters go through the conference": `Admin/.../SubmissionsTable.php` and `ReviewsTable.php` miss an abstract, or its reviews, whose conference was deleted, although the list shows it. `submissions.organization_id` answers that directly. It is a behaviour change for the platform admin, so it is the owner's call.
- **Backlog, add, only if Task 6 was struck:** "Health row for abstracts without their organization", with trigger "until the contract release".
- **HANDOVER:**
  - The phase-C list: C5 merged (#NN, date), with a migration, so the next deploy is a migration release.
  - After the deploy: the Production and `main` rows, the migration, and the check result.
  - "Plans 1–10" becomes "Plans 1–11".
- **Decision file:** `docs/decisions/00NN-submissions-carry-their-organization.md` (take the next free number at record time, since C8 may add files). Context: the hand-scoped resource. Decision: a copied column held by the model, Filament tenancy, the creating observer off, the policies left on the conference path. Consequence: expand now and contract later, and a rollback below C5 needs the backfill line. Reversal: restore the hand-scoped query, then drop the column.

## Brief statements checked against the code

1. **"`docs/backlog.md:36`"** points to the wrong place: the backlog is `docs/superpowers/plans/backlog.md`, and the entry is line 37. Line 36 is the withdraw/resend decision.
2. **"Set in `SubmitAbstract` and draft creation":** `SubmitAbstract` creates nothing. It moves an existing draft to submitted (`SubmitAbstract.php:124-170`). The creation paths are `SaveSubmissionDraft` (both public buttons, `UpdateSubmission`, `SeedDemo`), `ImportLegacy` (`:742`) and the factory.
3. **"The policies simplify":** not without a behaviour change. The conference path is what makes a trashed conference deny (`TrashedTenantPolicyTest.php:40-58`), and `ConferenceRanking::mayDecide()` probes `decide` with a submission that carries only its conference. This plan leaves all three policies as they are.
4. **The task brief's "`Tables/SubmissionsTable.php:87`":** that line scopes the track filter's options (tracks have no organization column), not the submission query. It stays, with its comment corrected. The hand-scoped submission query is `SubmissionResource.php:71-89`.
5. **The premise behind the hand scoping no longer holds** (a backlog statement, not the brief's): the backlog and the resource comment say a HasOneThrough fatals on creation under tenancy, and in Filament 5.10.2 it does not (fact 3). See open choice 1.
6. **Correct:** the `1 = 0` fallback fails closed; the negative tests exist (`SubmissionResourceTest.php:66`, `:230`, `:295`, `:308`, `:344`); the model has an `organization()` HasOneThrough; the importer inserts submissions.

## Open design choices for the reviewer

1. **The column at all.** Because of fact 3, Filament tenancy could run on the existing HasOneThrough with no migration: drop the override, name `organization`, and delete the hand-scoped query. The relation's existence query already excludes trashed conferences.
   - Cost: a correlated EXISTS through `conferences` on every organizer Submission query, where the column needs an indexed equality.
   - Saved: no backfill, no deploy window, no invariant to keep, no rollback hazard.
   - This plan follows the brief: an indexed scope, independence from how Filament treats through-relations, and a key the database can enforce later.
   - Decide before Task 1. Choosing the alternative removes Tasks 1-3 and 6 and shrinks Task 5.
2. **The invariant lives in the model, not in each Action.** That covers every path, including the importer, which these sessions cannot open. The cost is a model event, which the "logic in Actions" rule tolerates for invariants (`ReviewQuestion` holds its lock that way); `CreateConference` chose the Action instead, for the listener-order reason.
3. **The `observeTenancyModelCreation()` no-op** is a public static hook, not documented API. If Filament renames it, the override stops applying, and Task 5's second case goes red.
4. **The policies are left alone,** although the brief expected them to simplify (brief statement 3).
5. **`whereHas('conference')` stays, and the `1 = 0` fallback goes,** as the brief asks. Keep a fail-closed guard for a tenant-less call instead?
6. **The index is `(organization_id, submitted_at)`,** where the foreign key alone would give a single-column one.
7. **One migration with a `hasColumn` guard,** rather than two (the DDL, then the backfill), which would make every failure re-runnable without a guard.
8. **Task 6's health row:** it is the expand-window alarm, and it touches the same command as C3.
9. **`LogicException` for the three guards,** which no user can reach, rather than dedicated exceptions like `ReviewFormLocked`.
10. **The three adapted tests** (Task 5, Step 5): check that each still proves what its comment says.
