# CASS v2 Plan 11: Submissions Under Filament Tenancy (C5)

> **Status: revised 2026-11-15 after the adversarial review (verdict: fix, then approve).** The coordinator chose open choice 1's alternative from the first version: no new column. The existing `Submission::organization()` HasOneThrough becomes Filament's ownership relation.
> The first version planned a copied `submissions.organization_id` with its migration, model events and health row; decision 0018's "To reverse" points at it. Its Tasks 1-3 and 6 are dropped; the remaining tasks keep their numbers, so logs and references stay stable.
> Written against `test/d3-table-action-helpers` at `2d94284` (PR #76, approved, not merged). That branch edits three of the test files below, so this one is stacked on it.
> Versions, from `composer.lock` and the installed `vendor/`: Laravel 13.40.0, Filament 5.11.1, Livewire 4.4.8.

**Goal:** expert-brief row C5 (phase C, and Question 6 point 1). `SubmissionResource` goes back to Filament tenancy through the submission's existing `organization()` relation. Every Submission query in an organizer request is then tenant-scoped by Filament's mechanism instead of by one hand-written query, and the `1 = 0` fallback and the hand-scoped `whereHas` go.

**Done when:**
- the existing cross-tenant negatives, Task 4's pins (with their bite check) and Task 5's tests are green on both drivers (CI runs the suite on SQLite and on MySQL 8.4);
- decision 0018 records why three of the brief's items were not followed: the column, its backfill, and "the policies simplify".

The backlog entry on the hand-scoped resource is closed, with the reason, in the record PR.

## Summary (review point 1)

- **Users see no change.** Organizers see exactly the abstracts they see today. Inside the organizer panel, every query that reads abstracts is now filtered by Filament's tenancy scope, which reaches the organization through the abstract's conference.
- **Migration: none. Policies touched: none. Environment variables: none. Sensitive files: none.**
- **Changed:**
  - Behaviour: `app/Filament/Organizer/Resources/Submissions/SubmissionResource.php` only.
  - Comments only: `app/Models/Submission.php`, the organizer `SubmissionsTable.php` and `SubmissionActions.php`, `ConferenceRanking.php`, the admin `SubmissionResource.php` and `SubmissionsTable.php`, `tests/Pest.php` and `tests/Unit/SubmissionTest.php`.
  - Tests: pins in `SubmissionResourceTest`, a new `SubmissionTenancyTest`, and three existing tests adapted so they keep their meaning.
  - New: `docs/decisions/0018-submissions-under-filament-tenancy.md`.
- **An ordinary release:** no migration, so no hand backup and no deploy window.

## Design

### Invariants, and what holds each

| # | Invariant | Held by | Tested in |
|---|---|---|---|
| I1 | A tenant never sees another tenant's abstract through the resource: the list and its search, filters and filter options; the row actions (view, resend link, withdraw); the export; the view page and its route binding; global search | Filament's tenancy scope on Submission, a `whereHas('organization', key = tenant)` on every Submission query in the panel; `whereHas('conference')`, which also carries the Conference model's own tenancy scope; `SubmissionPolicy` | existing negatives, T4, T5 |
| I2 | No relation manager or widget of the resource reads across tenants | the resource has neither | T4 tripwire |
| I3 | An abstract of a deleted conference stays out of the organizer list and the view page, as today | `whereHas('conference')`; the scope's own join also requires a live conference (fact 4) | T4 |
| I4 | Nothing changes outside the organizer panel | the scope applies only while that panel is current and has a tenant (fact 3) | the existing suites |
| I5 | Creating an abstract while the organizer panel is booted neither fails nor changes whose it is | Filament's `creating` observer associates only a BelongsTo, and its `created` observer returns early for a HasOneOrManyThrough (fact 3) | T5 |

There is no copied value to keep consistent: the scope reads the organization through `conference_id` every time.

### The resource

- `$isScopedToTenant = false` and its docblock go. `protected static ?string $tenantOwnershipRelationshipName = 'organization';` arrives, as on `ConferenceResource`.
- `getEloquentQuery()` keeps only `whereHas('conference')` and the eager loads. The `1 = 0` fallback and the hand-scoped tenant match go. Outside a tenant the resource is unscoped, like `ConferenceResource`; Filament's tenant middleware puts a tenant on every organizer request.
- `observeTenancyModelCreation()` is not overridden. Through a HasOneThrough, Filament's creation observers do nothing (fact 3); T5's second case pins that.
- In SQL the scope is `exists (select * from organizations inner join conferences on conferences.organization_id = organizations.id where submissions.conference_id = conferences.id and organizations.id = ? and organizations.deleted_at is null and conferences.deleted_at is null)`. That is a correlated EXISTS on two primary keys. It adds a predicate, not a query, so the ranking's query-count ceilings still hold.
- The table's filter options stay as they are: the tenant's own conferences, and tracks through the conference. Only their comments change.

### Why there is no column (decision 0018)

- The copied value would become load-bearing for every organizer path that reads abstracts through the scope (fact 11), among them `StartReviewing`, `MarkDecided`, `SendDecisionEmails`, `TrackPolicy`, `CustomFieldPolicy` and `UpdateConference`. A missing or wrong value would silently drop rows, from a decision run or from a referenced-row refusal. Keeping it right would also take a migration release and model events outside the Actions.
- The reason for hand-scoping is gone: Filament 5.11.1 handles the HasOneThrough under tenancy (fact 3).
- "The policies simplify" is dropped. The conference walk is what makes a deleted conference deny (`TrashedTenantPolicyTest`), and `ConferenceRanking::mayDecide()` probes `decide` with a submission that carries only its conference.

### Tests, in short

- **T4:** pins written against today's hand-scoped resource, one per screen the resource owns, plus a query-level global-search pin. They pass before the switch and after it. The bite check, which is mandatory, shows they fail once the resource's query is unscoped.
- **T5:** a red-first test of the panel-wide scope; a no-crash pin for creating an abstract inside a booted panel; and three existing tests adapted to keep their meaning (fact 9).

### What this plan does not do

It adds no migration. It changes no model code (one docblock only), no policy, no Action, no admin behaviour and no runbook text. It adds no `Organization::submissions()`, because nothing in Filament 5.11.1 calls `getTenantRelationship()`.

## Facts verified before writing this plan

Read at `2d94284`, Laravel 13.40.0, Filament 5.11.1. Vendor paths are under `vendor/`.

1. **Versions.** `composer.lock` and the installed `vendor/composer/installed.json` agree: Filament v5.11.1, Laravel v13.40.0, Livewire v4.4.8.
2. **The hand scoping.**
   - `SubmissionResource.php:53` sets `$isScopedToTenant = false`.
   - `SubmissionResource.php:71-89` is `getEloquentQuery()`: the `1 = 0` fallback at `:82`, the hand-scoped `whereHas` at `:85-88`.
   - `SubmissionsTable.php:84-90` builds the track filter's option list; `:77-79` builds the conference options from `$tenant->conferences()`.
3. **Filament tenancy** (`filament/filament/src/`).
   - `Panel.php:87-89` calls `bootTenancy()` (`:117-137`). It runs once per panel instance and calls `observeTenancyModelCreation()` and `registerTenancyModelGlobalScope()` for every resource (`:132-133`).
   - The global scope (`Resources/Resource/Concerns/BelongsToTenant.php:128-157`) applies only while that panel is current and has a tenant. For an ownership relation that is neither a BelongsTo nor a MorphTo, the scope is `whereHas($relation, whereKey(tenant))` (`:61-64`).
   - The `creating` observer associates only a BelongsTo (`:184-186`).
   - The `created` observer returns early for BelongsTo, BelongsToThrough and HasOneOrManyThrough (`:202-207`), before the `save($tenant)` at `:216`.
   - An unscoped resource strips the scope from its own query (`Resources/Resource.php:92-109`).
   - `ConferenceResource.php:54` names `organization` as its ownership relation. The panel declares `->tenant(Organization::class, slugAttribute: 'slug')` (`OrganizerPanelProvider.php:47`).
   - Nothing in Filament calls `getTenantRelationship()` (grep).
   - In the test helpers, `tests/Pest.php:38-59` is `bootOrganizerPanel()` and `:74-96` is `withoutTenant()`.
4. **Laravel.**
   - HasOneThrough extends HasOneOrManyThrough (`Database/Eloquent/Relations/HasOneThrough.php:23`).
   - The relation's existence query joins the through table. Because Conference soft deletes, it also requires `conferences.deleted_at is null` (`HasOneOrManyThrough.php:120-133`, `:837-851`). The through parent's other global scopes (Filament's scope on Conference among them) are not applied to that join.
   - `fresh()` and `refresh()` skip global scopes (`Model.php:2142-2168`).
5. **Records, actions and search.**
   - A row action whose record is missing from the table query throws "Record [..] no longer exists." (`actions/src/Concerns/InteractsWithActions.php:830`). `assertActionDoesNotExist()` counts that exception as "does not exist" (`actions/src/Testing/TestsActions.php:180-221`).
   - Global search uses `getEloquentQuery()` (`HasGlobalSearch.php:261-264`) and drops any result the policy will not let the user view (`:87-123`).
   - The organizer resource is globally searchable: its record title is `title`, and nothing sets `$isGloballySearchable = false`. It overrides neither `getRelations()` nor `getWidgets()`.
6. **The relation** is `Submission.php:91-119`. `tests/Unit/SubmissionTest.php:139-162` pins it lazily and eagerly, because a six-argument HasOneThrough with its keys inverted answers with the wrong tenant instead of failing.
7. **Policies walk through the conference, all with null guards:**
   - `SubmissionPolicy`: `view` (`:59-74`), `decide` (`:166-171`), `canManage` (`:180-186`);
   - `SubmissionFilePolicy::view` and `SubmissionDecisionPolicy::view`;
   - `ReviewPolicy.php:51` and `ReviewAssignmentPolicy.php:89`.

   `TrashedTenantPolicyTest.php:40-58` pins that a deleted conference denies. `ConferenceRanking.php:345-350` asks `decide` with a probe `new Submission` that carries only its conference. Under the new scope, a foreign row's submission hop is null inside the panel, and each of these policies already answers "no" to that.
8. **Readers that need the conference:** the timezone column (`SubmissionsTable.php:64-66`), the infolist (`SubmissionInfolist.php:63, 90, 97, 120, 163, 189-195`), and `ExportSubmissionsCsv.php:97-103`.
9. **Tests whose meaning the scope changes:**
   - `SubmissionResourceTest.php:453-470`: its orphan vanishes, because the scope's join needs a live conference.
   - `DecisionEmailsTest.php:443`: it would read null from no row at all.
   - `ConferenceRankingExportTest.php:143`: its "deliberately unscoped" builder now carries the scope.

   `ConferenceRankingPerformanceTest`'s raw insert needs nothing, because the scope reads `conference_id`.
10. **No organizer screen reaches a deleted conference.** `app/Filament/Organizer` has no TrashedFilter and no restore. `ConferenceForm.php:81` reads trashed rows only to keep slugs unique.
11. **Organizer paths whose Submission queries the scope now filters.** Each acts on a live conference of the tenant, so its rows do not change:
    - `StartReviewing.php:48`;
    - `MarkDecided.php:55-82` and `SendDecisionEmails.php:51`, both through `RankedSubmissions::query()`;
    - `SendOneDecisionEmail.php:157` and `:290`;
    - `TrackPolicy.php:52`, `CustomFieldPolicy.php:54` and `UpdateConference.php:93`;
    - `ConferenceAssignments.php:123`;
    - `Conference.php:343` and `:468`.
12. **CI** runs the whole suite on SQLite, then on MySQL 8.4 (`.github/workflows/ci.yml:171-181`). On this base, `docs/decisions/` ends at 0017.

## Environment and conventions for every task

- **`$REPO`** is `/c/Users/<user>/Desktop/Ahmed/claude/projects/CASS/.claude/worktrees/agent-a2e7a488411e31c3f`, on branch `feat/c5-native-submission-tenancy`, stacked on `origin/test/d3-table-action-helpers`, with no upstream until Task 7. Write the path itself at the start of every Bash call. If `php -v` fails: `export PATH="/c/Users/<user>/Desktop/Ahmed/claude/tools/php84:/c/Users/<user>/Desktop/Ahmed/claude/tools/composer:$PATH"`.
- **Setup, already done:**
  - `vendor/` and `public/build/` copied from the main checkout, whose `vendor/` matches the lock;
  - `.env` created from the example, and a key generated;
  - the baseline run, `php artisan test > /tmp/t-task-F-baseline.log 2>&1`.
- **Logs.** Filter runs go to `/tmp/t-task-F-N-red.log` and `/tmp/t-task-F-N-green.log`, where N is the task. The final gates, in Task 7, are `/tmp/t-task-F.log`, `/tmp/stan-task-F.log` and `/tmp/pint-task-F.log`, plus `git diff --check`. Judge each by `rc=` alone. Git Bash's `/tmp` is `C:\Users\<user>\AppData\Local\Temp` for the Read and Write tools.
- **Commits.** Imperative subjects, `git add` by name, and the trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never commit with a gate red.
- **Code and tests.**
  - Comments explain the code and never carry a plan, task, brief-item or fact number. When you touch a comment that has one, replace it with the fact itself.
  - Imports are alphabetical (Pint). A test file needs no `use` for a global exception class.
  - Tests use Filament's `TestAction` (`TestAction::make('x')->table($record)`), as #76 does.
- **Never:** edit `docs/HANDOVER.md` or `docs/superpowers/plans/backlog.md`; touch a policy, a migration or `app/Actions/Legacy/`; run anything on the host.

### Task 4: Pin the cross-tenant negatives on every screen the resource owns

These are characterisation tests, written against today's hand-scoped resource. They pass now, and Task 5 must leave them passing: that is the proof the switch opened no screen. The existing negatives already cover the list, the view page's 404, the file policy and the action policies. These pins add:
- the search, the filters and their options;
- each row action;
- the filtered export;
- global search, both its answer and its query;
- the exclusion of a deleted conference's abstracts;
- a tripwire for relation managers and widgets.

**Files:** modify `tests/Feature/Organizer/SubmissionResourceTest.php`. Its `beforeEach` boots the panel for `$this->organization` and creates `AAM26-017`, "Early mobilisation after cardiac surgery", in `$this->conference`. Imports to add: `Filament\GlobalSearch\GlobalSearchResult` and `Filament\Tables\Filters\SelectFilter` (`TestAction` is already imported).

- [ ] **Step 1: The pins.** Insert them before `// --- Counts on the conference page`, under the comment `// --- Cross-tenant: every screen this resource owns`:
```php
it('finds no other organization abstract through the search, the filters or their options', function () {
    [$theirConference, $theirTrack, $theirs] = withoutTenant(function (): array {
        $conference = Conference::factory()->published()->create(['name' => 'Beta Congress']);
        $track = Track::factory()->for($conference)->create(['name' => 'Beta track']);
        $submission = Submission::factory()->for($conference)->submitted()->withCorrespondingAuthor('victim@example.org')
            ->create(['title' => 'Somebody else entirely', 'track_id' => $track->id]);

        return [$conference, $track, $submission];
    });

    livewire(ListSubmissions::class)
        ->searchTable('Somebody else')->assertCountTableRecords(0)
        ->searchTable('victim@example.org')->assertCountTableRecords(0)
        ->searchTable((string) $theirs->reference)->assertCountTableRecords(0)
        ->searchTable(null)
        ->filterTable('conference_id', $theirConference->getKey())->assertCountTableRecords(0)
        ->resetTableFilters()
        ->filterTable('track_id', $theirTrack->getKey())->assertCountTableRecords(0)
        ->resetTableFilters()
        ->assertTableFilterExists('conference_id', fn (SelectFilter $filter): bool => array_key_exists($this->conference->getKey(), $filter->getOptions())
            && ! array_key_exists($theirConference->getKey(), $filter->getOptions()))
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

    // Neither row: the filter took, so this organization's abstract is not in the file either.
    expect($csv)->not->toContain('Another tenant')->not->toContain('AAM26-017');
});

it('answers a global search with this organization abstracts only', function () {
    $theirs = withoutTenant(fn (): Submission => Submission::factory()->submitted()->create(['title' => 'Early mobilisation in another society']));

    // The query itself, not only the answer: the answer also passes through the view policy,
    // which drops a foreign row whatever the query returned.
    expect(SubmissionResource::getGlobalSearchEloquentQuery()->whereKey($theirs)->exists())->toBeFalse()
        ->and(SubmissionResource::getGlobalSearchResults('Early mobilisation')
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
    // Either one would read its own model, which this resource's tenancy does not scope.
    expect(SubmissionResource::getRelations())->toBe([])
        ->and(SubmissionResource::getWidgets())->toBe([]);
});
```
- [ ] **Step 2: Run them; they pin.** `php artisan test --compact tests/Feature/Organizer/SubmissionResourceTest.php > /tmp/t-task-F-4-green.log 2>&1; echo "rc=$?"`. Expected: `rc=0`, with the file's existing cases plus 8 new ones. A red here is a leak in today's code: stop and report it.
- [ ] **Step 3: Show that they bite (mandatory).**
  1. Temporarily make `SubmissionResource::getEloquentQuery()` return `Submission::query()->withoutGlobalScopes()->with(['conference', 'track', 'authors'])`.
  2. Run the Step 2 command to `/tmp/t-task-F-4-red.log`. Expected: a non-zero `rc` (Pest exits 2 when errors are among the failures).
     - Every new case that mounts the list fails or errors (a foreign row has no visible conference, so its timezone column throws).
     - The global-search case fails on its query assertion.
     - Only the tripwire passes.
  3. Restore the file with `git checkout -- app/Filament/Organizer/Resources/Submissions/SubmissionResource.php`, confirm that `git status --short` shows only the test file, and re-run the green command.
- [ ] **Step 4: Gates, then commit.** Pint and the diff check. Commit with `test(organizer): pin the cross-tenant negatives on every submission screen`.

### Task 5: The resource returns to Filament tenancy

The resource drops its override and its hand-scoped query, and names the existing HasOneThrough as its ownership relation. Three existing tests are adapted where the new panel-wide scope would change what they prove (fact 9). The comments that described the old arrangement are then corrected in a commit of their own.

**Files, first commit:** create `tests/Feature/Organizer/SubmissionTenancyTest.php`; modify `app/Filament/Organizer/Resources/Submissions/SubmissionResource.php`, `tests/Feature/Organizer/SubmissionResourceTest.php`, `tests/Feature/Organizer/DecisionEmailsTest.php` and `tests/Feature/Organizer/ConferenceRankingExportTest.php`.

**Files, second commit (comments only):** `app/Models/Submission.php`, `app/Filament/Organizer/Resources/Submissions/Tables/SubmissionsTable.php`, `.../Submissions/Tables/SubmissionActions.php`, `app/Filament/Organizer/Resources/Conferences/Pages/ConferenceRanking.php`, `app/Filament/Admin/Resources/Submissions/SubmissionResource.php`, `.../Admin/Resources/Submissions/Tables/SubmissionsTable.php`, `tests/Pest.php` and `tests/Unit/SubmissionTest.php`.

- [ ] **Step 1: The failing tests.** Imports: `App\Enums\OrganizationRole`, `App\Filament\Organizer\Resources\Submissions\SubmissionResource`, `App\Models\Conference`, `App\Models\Organization`, `App\Models\Submission` and `App\Models\User`, plus `use function Pest\Laravel\actingAs;`.
```php
/**
 * The organizer panel's tenancy on Submission is a mechanism for every query in the panel, not
 * a convention in one resource. It reaches the organization through the abstract's conference,
 * so there is no copied value to keep right.
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

it('scopes every submission query in the organizer panel to the tenant, through the conference', function () {
    expect(SubmissionResource::isScopedToTenant())->toBeTrue()
        ->and(SubmissionResource::getTenantOwnershipRelationshipName())->toBe('organization')
        ->and(Submission::query()->pluck('id')->all())->toBe([$this->mine->id])
        ->and(Submission::query()->find($this->theirs->id))->toBeNull()
        // A page that reads abstracts through a conference is scoped as well.
        ->and($this->theirConference->submissions()->count())->toBe(0);
});

it('creates a submission while the panel is booted for another tenant, and leaves it that tenant', function () {
    // Filament's tenancy observers run on every creation in the booted panel. Through a
    // has-one-through they neither associate the current tenant nor try to save the relation,
    // which has no save(): this is the case that would fail first if either changed.
    $created = Submission::factory()->for($this->theirConference)->create();

    expect($created->fresh()?->conference_id)->toBe($this->theirConference->id)
        ->and($created->fresh()?->organization?->is($this->theirConference->organization))->toBeTrue();
});
```
- [ ] **Step 2: Run it red.** `php artisan test --compact --filter=SubmissionTenancyTest > /tmp/t-task-F-5-red.log 2>&1; echo "rc=$?"`. Expected: `rc=1`. The first case fails ("Failed asserting that false is true."). The second passes: it is a no-crash pin, and no observer exists for Submission yet.
- [ ] **Step 3: Switch the resource.** In `SubmissionResource`:
  - Delete `$isScopedToTenant = false` and its docblock, and add `protected static ?string $tenantOwnershipRelationshipName = 'organization';`.
  - Replace the "No $recordRouteKeyName" comment's provenance reference with the fact itself: the model's route key is the ULID, and Filament builds URLs from `getRouteKey()`.
  - Drop the now-unused imports `App\Models\Organization` and `Filament\Facades\Filament`.
  - Make the class docblock: "Every abstract of the tenant, under Filament's tenancy like ConferenceResource. The ownership relation is Submission::organization(), a has-one-through the conference, so the panel's global scope is a whereHas on every Submission query in an organizer request: this list and its search, filters and export, the view page and its route binding, global search, and every ConferenceResource page that reads abstracts. SubmissionPolicy is the second, independent gate."
  - Replace `getEloquentQuery()` with:
```php
    /**
     * Tenancy is the panel's scope, not this method. What stays here is the conference: an
     * abstract whose conference was deleted is neither listed nor opened, because the timezone
     * column and the infolist read the conference, and ExportSubmissionsCsv relies on the same
     * exclusion. Inside the panel this whereHas also carries the Conference model's own tenancy
     * scope, so it is a second filter on the tenant as well.
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
- [ ] **Step 4: Adapt the three tests the scope changes,** each keeping its meaning.
  - **`SubmissionResourceTest`, "exports a row whose conference is gone instead of dying mid-stream":** build and stream the export inside `withoutTenant()`, with the comment: "Outside the tenant: inside it, the panel's tenancy scope reaches the organization through the conference, so it leaves out an abstract whose conference is gone, and the plain query this case is about would find nothing." Keep the existing comment and assertion.
```php
    $csv = withoutTenant(function () use ($orphan): string {
        $response = app(ExportSubmissionsCsv::class)
            ->handle(Submission::query()->whereKey($orphan->getKey()), 'submissions.csv');

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    });
```
  - **`DecisionEmailsTest`, line 443**, becomes:
```php
    // Read past the panel's tenancy scope: inside it another organization's abstract is not
    // there to read, and a null from no row would pass this for the wrong reason.
    $row = withoutTenant(fn () => $theirs->submissions()->first());
    expect($row)->not->toBeNull()->and($row?->decision_notified_at)->toBeNull();
```
  - **`ConferenceRankingExportTest`, line 143:** hand the writer `Submission::query()->withoutGlobalScope(Filament::getPanel('organizer')->getTenancyScopeName())`, with the comment "Unscoped on purpose, the panel's tenancy scope included: the writer's own constraint is what this case is about." Remove only the tenancy scope, not every global scope, so the builder keeps the soft-delete scope it had before. The stranger row then still tests the writer, not the scope.
- [ ] **Step 5: Green, the full suite, and the gates.**
  - The Step 2 filter, to `/tmp/t-task-F-5-green.log`: `rc=0`, 2 passed.
  - The full suite, to `/tmp/t-task-F-5.log`: `rc=0`, at baseline + 10 (8 from Task 4, 2 from this task).
  - A red anywhere else: if its own fixture reads another organization's abstract, or a deleted conference's, through a scoped query, read it inside `withoutTenant()` and assert that the row exists, then list it in the report. Any other red is a regression: stop and report it.
  - Then Larastan, which also catches the dropped imports; Pint; and the diff check.
- [ ] **Step 6: Commit the switch:** the five files in "Files, first commit", with the subject `feat(organizer): submissions use Filament tenancy through their conference`.
- [ ] **Step 7: Correct the comments, in their own commit.** No code changes in these files.
  - `Submission.php:91-105`, the docblock of `organization()`: "The tenant, two hops away: a has-one-through read child-to-grandparent, `conference_id` on this row pointing at a conference whose `organization_id` points at the organization. The organizer panel's tenancy runs on it (SubmissionResource names it as the ownership relation), as a whereHas that joins conferences and so also requires a live conference. An inverted key pair in a six-argument hasOneThrough does not fail, it quietly answers with the wrong tenant, so tests/Unit/SubmissionTest.php pins it lazily and eagerly, and tests/Feature/Organizer/SubmissionTenancyTest.php through the scope."
  - Organizer `SubmissionsTable.php:22-26`: "Same narrowing as SubmissionResource::getEloquentQuery() and ConferenceForm." becomes "Same narrowing as ConferenceForm."
  - Organizer `SubmissionsTable.php:73-76` becomes: "The tenant's conferences, asked of the tenant itself, so this option list never depends on how another resource is scoped."
  - Above the track options at organizer `SubmissionsTable.php:84-90`, add: "Tracks have no organization of their own: through the conference."
  - `SubmissionActions.php:100-102`: "already tenant-scoped by SubmissionResource::getEloquentQuery()" becomes "already tenant-scoped by the panel's tenancy scope".
  - `ConferenceRanking.php:40-46` becomes: "SubmissionResource is already the panel-wide list, and a second Resource over Submission would be a second list of the same rows. A page inherits ConferenceResource's tenancy through resolveRecord() and then scopes by conference_id."
  - Admin `SubmissionResource.php:38-41` becomes: "No tenancy here: AdminPanelProvider never calls ->tenant(), so Filament registers no scope and no observer for this resource, and the organizer panel's scope on Submission does nothing while this panel is the current one."
  - Admin `SubmissionsTable.php:79-81`: "Through the conference: submissions have no organization_id, which is the same fact that made the organizer resource override tenancy." becomes "Through the conference: a submission has no organization_id of its own."
  - In `tests/Pest.php:74-79`, after "Wrap cross-tenant fixtures in this.", add: "A model reached through another (Submission, through its conference) is never re-parented, but reads need this too: inside a booted organizer panel every Conference and Submission query is tenant-scoped, so read another organization's rows in here, or with fresh() or refresh(), which skip global scopes."
  - In the comment of `tests/Unit/SubmissionTest.php:139-145`, "and nothing read it or tested it." becomes "and the organizer panel's tenancy scope runs on it."

  Check that nothing still describes the old arrangement: `grep -rn "isScopedToTenant\|HasOneThrough fatal\|not tenant-scoped by Filament" app tests` should list only the reviewer resource's own `$isScopedToTenant = false`. Then Larastan, Pint and the diff check. Commit with `docs(code): comments describe submissions under Filament tenancy`.

### Task 7: Decision 0018, the final gates and the pull request

**Files:** create `docs/decisions/0018-submissions-under-filament-tenancy.md`.

- [ ] **Step 1: The decision.** Write it in the folder's format (`docs/decisions/README.md`): a title line, then Context, Decision, Consequence and To reverse.
  - **Title:** "Submissions use Filament tenancy through their conference; no organization_id column".
  - **Context:** the organizer `SubmissionResource` was scoped by hand, with a `1 = 0` fallback, because a has-one-through ownership relation used to fail on creation under Filament's tenancy. The outside review asked for a copied `submissions.organization_id` instead, backfilled from `conferences`, after which "the policies simplify" (`docs/reviews/2026-10-16-expert-brief.md`, C5, and Question 6 point 1).
  - **Decision:** "Proposed by engineering on 2026-11-15 and accepted by the owner with the merge of #NN, which adds this file", as decision 0017 words it, so nothing changes after the merge.
    - There is no column. `Submission::organization()`, the existing has-one-through the conference, is the resource's ownership relation, which Filament 5.11.1 scopes with a whereHas. Its creating observer associates only a BelongsTo, and its created observer returns early for a has-one-or-many-through.
    - The column was not added because the copied value would become load-bearing for every organizer read of abstracts: `StartReviewing`, `MarkDecided`, `SendDecisionEmails`, `TrackPolicy`, `CustomFieldPolicy` and `UpdateConference` among them. A missing or wrong value would drop rows silently, and keeping it right would need a migration release and model events outside the Actions.
    - "The policies simplify" is dropped. The conference walk is what makes an abstract of a deleted conference answer no (`TrashedTenantPolicyTest`), and `ConferenceRanking::mayDecide()` asks `decide` with a probe that carries only its conference.
  - **Consequence:**
    - Every Submission query in an organizer request carries a correlated EXISTS through `conferences`, which also requires both the conference and the organization to be live (a deleted organization's tenant routes return 404 first).
    - Abstracts of a deleted conference are invisible to the organizer panel everywhere, not only in its list.
    - A query in that panel meant to see another organization's abstracts, or a deleted conference's, must leave the tenant first: tests do it with `withoutTenant()`.
  - **To reverse:** apply the dropped Tasks 1-3 of this plan's first version (`docs/superpowers/plans/2026-11-15-plan-11-native-submission-tenancy.md` at commit `254b7c6`): the column, the model events and the BelongsTo. Invert `tests/Feature/Organizer/SubmissionTenancyTest.php` accordingly.
- [ ] **Step 2: The final gates:**
  - `php artisan test > /tmp/t-task-F.log 2>&1; echo "rc=$?"`, at baseline + 10;
  - `./vendor/bin/phpstan analyse > /tmp/stan-task-F.log 2>&1; echo "rc=$?"`;
  - `./vendor/bin/pint --test > /tmp/pint-task-F.log 2>&1; echo "rc=$?"`;
  - `git diff --check; echo "rc=$?"`.

  All four must give `rc=0`.
- [ ] **Step 3: Commit, push and open the pull request.**
  - Commit the decision with `docs(decisions): 0018, submissions under Filament tenancy`, and push with `git push -u origin feat/c5-native-submission-tenancy`.
  - Write the PR body with the Write tool to `C:\Users\<user>\AppData\Local\Temp\pr-F.md`, in the common brief's format:
    - a paragraph of what and why, saying that the PR is stacked on #76 (`test/d3-table-action-helpers`) and will be retargeted to `main` once #76 merges;
    - "Files changed";
    - "For review": no sensitive file, no migration, no environment variable; the tests added; the three tests adapted, and why; the bite-check log; the four gate logs with their `rc`;
    - the attribution line.
  - Open it with `gh pr create --base test/d3-table-action-helpers --title "feat: submissions use Filament tenancy through their conference" --body-file /tmp/pr-F.md`.
- [ ] **Step 4: The PR number.** Replace `#NN` in decision 0018 with the new PR's number, commit with `docs(decisions): 0018 names its pull request`, and push. The decision then needs no edit after the merge.

## After merge: lines to record (not edited in this branch)

- **Backlog, the entry at line 37** ("Decided: `SubmissionResource` sets `$isScopedToTenant = false` …"; C8 may move it, so find it by its text). It closes in the record PR: "Closed by C5 (#80, date). `SubmissionResource` uses Filament tenancy on the existing has-one-through `organization()`: Filament 5.11.1 scopes it with a whereHas, its creating observer associates only a BelongsTo, and its created observer returns early for a has-one-or-many-through, so the fatal described here no longer happens. No column was added (decision 0018)."
- **HANDOVER:**
  - The phase C sentence at `docs/HANDOVER.md:73-75` ("Phases C (CI audits, build once and deploy that image, disk check, seeder hardening, native submission tenancy, …) and D (on touch) are in the brief."): record native submission tenancy as done in #80, not a migration release.
  - "Plans 1–10" becomes "Plans 1–11".
  - The brief's C5 "done when" was met without its backfill clause (decision 0018).
- **No runbook change**, because nothing changes for the deploy.

## Brief statements checked against the code

1. **"`docs/backlog.md:36`"** is the wrong place: the file is `docs/superpowers/plans/backlog.md`, and the entry is at line 37. Line 36 is the withdraw and resend decision.
2. **"Set in `SubmitAbstract` and draft creation":** `SubmitAbstract` creates nothing; it moves an existing draft to submitted (`SubmitAbstract.php:124-170`). The creation paths are `SaveSubmissionDraft` (both public buttons, `UpdateSubmission`, `SeedDemo`), `ImportLegacy` (`:742`) and the factory. This is now moot, since there is no column.
3. **"The policies simplify"** is wrong: they could not be simplified without changing what they allow (fact 7). Dropped; see decision 0018.
4. **The task brief's "`Tables/SubmissionsTable.php:87`"** points at the track filter's option list (tracks have no organization), not at the submission query. It stays, with a comment. The hand-scoped submission query is `SubmissionResource.php:71-89`.
5. **The reason behind the hand scoping** (stated in the backlog and the resource comment, not in the brief) is out of date. A HasOneThrough no longer fails on creation under tenancy: it does not in Filament 5.10.2 or in 5.11.1 (fact 3). That is what makes this plan possible.
6. **Correct:** the `1 = 0` fallback fails closed; the negative tests exist; the model has an `organization()` HasOneThrough; the importer inserts submissions.

## Open design choices that remain

1. **`whereHas('conference')` stays, although the scope's join already requires a live conference.** It keeps the list and the view page right even outside a tenant, and it is what `ExportSubmissionsCsv`'s comment relies on.
2. **The `1 = 0` fallback goes,** as the brief asks: outside a tenant the resource is unscoped, like `ConferenceResource`. Should a fail-closed guard stay instead?
3. **No `observeTenancyModelCreation()` override.** This relies on Filament treating a through-relation as inert on creation. Task 5's second case goes red first if Filament stops doing so.
4. **The three adapted tests** (Task 5, Step 4): check that each one still proves what its comment says.
