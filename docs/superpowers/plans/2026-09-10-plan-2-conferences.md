# CASS v2 Plan 2: Conferences, Review Forms, QR Codes and the Public Conference Page

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An approved organization can create a conference in the organizer panel, configure its dates, submission window, tracks, custom fields and weighted review form, publish it through an explicit gate, and get a short link, a QR code (SVG/PNG) and a printable A4/A3 poster PDF. The public gets a branded conference page at `/c/{org}/{conference}` and a counted short-link redirect at `/q/{code}`.

**Architecture:** Single Laravel app. `Conference` is the second tenant-owned model after `Organization`; every conference-owned model (`Track`, `CustomField`, `ReviewForm`, `ReviewQuestion`) hangs off it and is reached only through the tenant-scoped organizer panel. Business logic lives in `app/Actions`; Filament resources, relation managers and controllers validate and delegate. Public pages are plain Blade rendered by thin controllers (no Livewire: the conference page is read-only and must render in under 300 ms), with organization branding injected as CSS variables.

**Tech Stack:** PHP 8.4, Laravel 13.31, Filament 5.8.1, Livewire 4.4.4, Tailwind 4.3 (Vite), Pest 5.1 with Laravel and Livewire plugins, Larastan level 6, Pint (strict types), spatie/laravel-activitylog 5.1, chillerlan/php-qrcode 5.x (already present as a Filament dependency), barryvdh/laravel-dompdf 3.1.2+, MySQL 8.4 in CI and production, SQLite in-memory locally.

**Spec:** `docs/superpowers/specs/2026-09-10-cass-v2-design.md` sections 5.2, 5.7, 6, and the conference parts of 3, 4, 8, 9, 10, 12 and 13. Section 5.8 branding is consumed (not extended) here.

**Environment facts for every command below**

- Repo root: `C:\Users\ahmed\Documents\CASS` (Git Bash path `/c/Users/ahmed/Documents/CASS`). All commands are Git Bash.
- Composer: run `php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar <args>`. Do **not** use the `composer.bat` wrapper: it passes through cmd.exe and silently strips `^` from version constraints.
- PHP 8.4.23 at `C:\Users\ahmed\AppData\Local\php84\php.exe` with intl, gd (bundled 2.1.0, FreeType + PNG), mbstring, pdo_mysql, pdo_sqlite, zip, fileinfo loaded. `gd` is also compiled into the production image (see `Dockerfile`), so no image change is needed for QR rendering.
- Node 24, npm 11, Docker 29 (daemon running), git, `gh` (logged in as `ahmedsk2`).
- Baseline before this plan: `main` at commit `bc27aa6`, `php artisan test` = **53 passed**, Pint clean, Larastan level 6 clean.
- Commit after every task with the trailer `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- **Verify test runs by exit code**, never by reading piped output: `php artisan test > /tmp/t.log 2>&1; echo "rc=$?"; tail -5 /tmp/t.log`.

**What this plan does NOT build (kept honest):**

- **Plan 3 (submissions)** adds `/c/{organization}/{conference:slug}/submit` (the same explicit `:slug` binding field Task 4 registers for the page itself), the `Submission` / `SubmissionAuthor` / `SubmissionFile` models, reference numbers, the author status page `/s/{token}`, Turnstile, and the real "Submit abstract" link. In Plan 2 the public CTA renders with the correct four states and its `open` state points at `#`; Task 4 isolates that one line in `resources/views/public/partials/submit-cta.blade.php` so Plan 3 replaces exactly one `href`.
- **Plan 4 (reviewers and review)** adds `ReviewerInvitation`, `ConferenceReviewer`, `ReviewAssignment`, `Review`, `ReviewAnswer`, the reviewer panel, and the code that sets `review_forms.locked_at` when the first review is submitted. Plan 2 ships the column, the model guard and the tests; nothing sets it yet except factories.
- **Plan 5** adds scoring, ranking and decisions. `review_questions.weight`, the scale bounds and the optional per-choice score on a select question exist here only as stored configuration.
- **Plan 6** adds custom domains, legacy import and launch hardening (CSP, image digest pinning — see `docs/superpowers/plans/backlog.md`).
- **Organization member management** (spec 4 "Manage organization members", handed to this plan by Plan 1's self-review) moves to **Plan 4**, which builds the hashed-token invitation flow that member invitations share with reviewer invitations. Until then each organization has only its registering owner; the owner/admin/member rules in Task 7 are enforced and tested but cannot yet occur in production. Recorded in the backlog (Task 12 Step 4).
- **Per-conference email templates** (spec 5.2, 5.9) move to **Plan 3**, which sends the first conference-scoped emails (`submission_draft_saved`, `submission_received`). Recorded in the backlog (Task 12 Step 4).

---

## File structure created or changed by this plan

```
app/
  Actions/
    Conferences/
      ArchiveConference.php            # any non-archived -> archived (owner/admin)
      CloseSubmissions.php             # open -> closed
      CreateConference.php             # tenant + slug + default review form, one transaction
      CreateDefaultReviewForm.php      # idempotent; the nine legacy Likert questions
      GenerateConferencePoster.php     # A4/A3 poster PDF via dompdf
      GenerateConferenceQr.php         # SVG + PNG from the conference short link
      PublishConference.php            # blockers() + handle(); creates the short link
    ShortLinks/
      RecordShortLinkVisit.php         # clicks++ and one timestamp row
  Enums/
    ConferenceStatus.php               # draft|open|closed|reviewing|decided|archived
    CustomFieldType.php                # text|textarea|select|checkbox|number
    PosterSize.php                     # a4|a3
    ReviewMode.php                     # open_pool|assigned
    ReviewQuestionType.php             # likert|text|boolean|select
    SubmissionWindow.php               # not_configured|upcoming|open|closed
  Exceptions/
    ConferenceNotPublishable.php
    ReviewFormLocked.php
  Filament/Admin/
    Resources/Conferences/
      ConferenceResource.php               # read-only, platform admin sees every conference
      Pages/ListConferences.php
      Pages/ViewConference.php
      Tables/ConferencesTable.php
  Filament/Organizer/
    Resources/Conferences/
      ConferenceResource.php
      Pages/ConferenceShortLink.php    # QR downloads + scan counts
      Pages/CreateConference.php
      Pages/EditConference.php
      Pages/ListConferences.php
      Pages/ViewConference.php
      RelationManagers/CustomFieldsRelationManager.php
      RelationManagers/ReviewQuestionsRelationManager.php
      RelationManagers/TracksRelationManager.php
      Schemas/ConferenceForm.php
      Schemas/ConferenceInfolist.php
      Tables/ConferencesTable.php
      Tables/ConferenceStatusActions.php   # publish / close / archive, shared by table + pages
    Widgets/ConferencesOverview.php
  Http/Controllers/
    Organizer/ConferenceAssetController.php  # qr-svg, qr-png, poster/{size}
    Public/ConferenceController.php
    Public/ShortLinkController.php
  Models/
    Conference.php                       (also modified in Tasks 3, 4, 5)
    CustomField.php
    ReviewForm.php
    ReviewQuestion.php
    ShortLink.php
    ShortLinkVisit.php
    Track.php
    Organization.php                     (modified: conferences())
  Policies/
    ConferencePolicy.php
    CustomFieldPolicy.php
    ReviewFormPolicy.php
    ReviewQuestionPolicy.php
    TrackPolicy.php
  Support/
    Branding/OrganizationTheme.php     # --org-primary / --org-accent + readable text colours
    Html/RichText.php                  # restrictive Symfony sanitiser for organizer HTML
    Pdf/PosterFonts.php                # registers the bundled IBM Plex Sans TTFs with dompdf
    ShortCode.php                      # 8 chars, unambiguous alphabet
config/
  cass.php                             # + short_link_rate_limit, qr defaults
  dompdf.php                           # published, then trimmed
database/
  factories/{ConferenceFactory,CustomFieldFactory,ReviewFormFactory,ReviewQuestionFactory,
             ShortLinkFactory,TrackFactory}.php
  migrations/
    2026_09_11_000100_create_conferences_table.php
    2026_09_11_000200_create_tracks_table.php
    2026_09_11_000300_create_custom_fields_table.php
    2026_09_11_000400_create_review_forms_table.php
    2026_09_11_000500_create_review_questions_table.php
    2026_09_11_000600_create_short_links_table.php
    2026_09_11_000700_create_short_link_visits_table.php
resources/
  css/app.css                            (modified: --org-* defaults)
  fonts/{IBMPlexSans-Regular.ttf,IBMPlexSans-SemiBold.ttf,IBMPlexMono-SemiBold.ttf,LICENSE-IBM-Plex.txt}
  js/{app.js,countdown.js}
  views/
    components/layouts/conference.blade.php
    filament/organizer/pages/dashboard.blade.php        (modified)
    filament/organizer/resources/conferences/pages/short-link.blade.php
    pdf/conference-poster.blade.php
    public/conference.blade.php
    public/partials/submit-cta.blade.php
routes/web.php                          (modified)
bootstrap/app.php                       (modified: redirectGuestsTo)
app/Providers/AppServiceProvider.php    (modified: conference-assets rate limiter, Task 10)
composer.json, composer.lock            (modified: dompdf, php-qrcode, html-sanitizer)
Dockerfile                              (modified: storage/fonts, publish Filament/Livewire assets)
docker/nginx.conf                       (modified: static-extension fallback to index.php)
.github/workflows/ci.yml                (modified: smoke asserts panel assets and a dompdf render)
.gitattributes                          (modified: *.ttf binary)
.gitignore                              (modified: storage/fonts contents)
.env.example                            (modified)
docs/runbooks/deploy-production.md      (modified: Plan 2 migration, font notes, page timing)
docs/superpowers/plans/backlog.md       (modified)
storage/fonts/.gitignore                # dompdf font cache directory, tracked but empty
tests/
  Feature/PosterFontsTest.php
  Feature/Admin/ConferencesTest.php
  Feature/Organizer/
    ConferenceAssetsTest.php
    ConferenceRelationManagersTest.php
    ConferenceResourceTest.php
    ConferenceTransitionsTest.php
    ConferencesOverviewWidgetTest.php
    ReviewQuestionsRelationManagerTest.php
  Feature/Public/
    ConferencePageTest.php
    ShortLinkTest.php
  Unit/
    ConferenceQrTest.php
    ConferenceStatusTest.php
    ConferenceTest.php
    OrganizationThemeTest.php
    PublishConferenceTest.php
    ReviewFormTest.php
    ShortCodeTest.php
  Pest.php                              (modified: bootOrganizerPanel / withoutTenant helpers)
```

---

## Filament 5.8 / package facts verified in `vendor` before writing this plan

Read these before you doubt a name below; every one was checked against the installed code.

1. `filament/filament` v5.8.1 **requires `chillerlan/php-qrcode ^5.0`** (`composer.lock`). Requiring `^6.0` is a hard conflict. Version 5.0.5 is already installed; Task 1 only promotes it to a direct dependency.
2. `chillerlan/php-qrcode` v5 defaults `QROptions::$outputBase64` to **true**. Raw SVG/PNG bytes need `'outputBase64' => false`. Settings are `protected` properties reachable only through `SettingsContainerAbstract::__set`: assigning one after construction (`$options->scale = 25`) works at runtime but **Larastan level 6 reports `property.protected`**, so every setting must be passed in the constructor array. `QRCode::addByteSegment()`, `getQRMatrix()` and `renderMatrix()` let you size the PNG from the real module count in one pass (`renderMatrix()` accepts a matrix built by another `QRCode` instance, and the quiet zone is already in it). `QROptions::$drawLightModules` defaults to **true** and `$svgUseFillAttributes` must stay **true**: with it false, `QRMarkupSVG` emits `<path class="..." d="..."/>` with no `fill` and no `<style>`, so SVG's default fill paints the light modules and the quiet zone black and the code cannot be scanned.
3. `Repeater::orderColumn('sort')` exists and internally calls `reorderable($column)`. **`Repeater::relationship()` calls `reorderable(false)`**, so `->relationship()` must come *before* `->orderColumn('sort')`. The only Repeater in this plan is the review-question choices list in Task 9, which is a plain array field with no `->relationship()`, so the ordering rule above does not bite there; it is recorded because Plan 3 will use repeaters for the authors list. `Repeater::fake()` (a `configureUsing` closure from `Filament\Forms\Components\Concerns\CanGenerateUuids`, returning an undo closure) is what keeps its uuid keys out of the stored array in tests.
4. `RichEditor` exists in v5 (`Filament\Forms\Components\RichEditor`) and stores **HTML** unless `->json()` is called (`isJson()` defaults to false). Its own source warns the output is raw HTML that must be sanitised before rendering. `Str::sanitizeHtml()` is a **Filament macro** (`vendor/filament/support/src/SupportServiceProvider.php:320`) backed by `Symfony\Component\HtmlSanitizer` — and Filament's shared config **allows `style` and `class` on every element**. This plan therefore renders conference descriptions through its own sanitiser (`App\Support\Html\RichText`), which narrows Symfony's W3C "safe" set further: `class`, `id`, `name` and `style` are dropped everywhere (they let organizer HTML reuse the app's own compiled utility classes to spoof platform chrome), and `img`, `video`, `audio`, `source`, `track`, `picture`, `canvas`, `iframe`, `button` and `dialog` are removed (W3C marks them safe, but they load from any host or fake an affordance). Note that `HtmlSanitizerConfig::dropElement()` removes the element *and its children*, while `blockElement()` removes only the tag and keeps the text — and any element that is neither allowed nor blocked is dropped with its text, which is why this config starts from `allowSafeElements()` instead of an explicit element allowlist (the RichEditor's TipTap schema stores tables, block quotes, `h1`-`h6`, `sub`/`sup`, `mark` and `small` whatever the toolbar shows).
5. Tenancy is a **model global scope** registered in `Panel::boot()` for every tenant-scoped resource, plus a `creating` observer that calls `$relationship->associate($tenant)`. Consequences:
   - `Resource::getEloquentQuery()` does **not** add the tenant filter itself; it only *removes* the global scope when `isScopedToTenant()` is false. Overriding `getEloquentQuery()` therefore keeps tenant scoping automatically — do not re-add it by hand.
   - The scope and the observer are no-ops when `Filament::getCurrentPanel()` is not that panel, so public routes and asset routes are unscoped (verified: `getCurrentPanel()` returns `null`, not the default panel).
   - **Test gotcha:** while the organizer panel is booted with a tenant, `Conference::factory()->for($otherOrg)->create()` gets its `organization_id` overwritten with the current tenant. Task 7 Step 1 adds `bootOrganizerPanel()` and `withoutTenant()` to `tests/Pest.php`. Every fixture belonging to another organization, or created before switching tenants, is built inside `withoutTenant()` or after `bootOrganizerPanel()` for its own organization.
6. `Filament::getTenantOwnershipRelationshipName()` defaults to the tenant model's class basename in camel case, i.e. **`organization`** — exactly the relation name used here, so `$tenantOwnershipRelationshipName` never needs overriding. It is still declared explicitly on `ConferenceResource` as documentation.
7. Relation managers: `Filament\Resources\RelationManagers\RelationManager`, `protected static string $relationship`, `public function form(Schema $schema): Schema`, `public function table(Table $table): Table`. `canViewForRecord()` calls `Filament\authorize('viewAny', $model, shouldCheckPolicyExistence: true)`.
   **The failure mode is the opposite of the obvious one:** with `shouldCheckPolicyExistence` (the default) and a panel that is not in `strictAuthorization()` mode, `vendor/filament/filament/src/helpers.php:60-93` consults the policy only `if (filled($policy) && method_exists($policy, $actionValue))` — otherwise it runs the Gate before-callbacks and falls through to `return Response::allow()`. A missing policy, or a policy that is missing the one method Filament asks for, means **ALLOW**, not 403. Every ability Filament calls must therefore be defined explicitly, including the `*Any()` abilities: on a resource page `DeleteBulkAction` is authorized with **`deleteAny`** and never with the per-record `delete` unless `->authorizeIndividualRecords()` is set (`Resources/Pages/Page.php:319`, `actions/src/Concerns/CanBeAuthorized.php:282`).
8. `Filament\Forms\Components\Concerns\CanBeValidated` provides `scopedUnique()` / `scopedExists()` because Laravel's `unique` / `exists` rules bypass global scopes, and `rule()` accepts a closure returning a closure rule. A conference slug may be typed (spec 5.2) and is checked against trashed rows too, so Task 7 uses an explicit `rule()` closure rather than `scopedUnique()`; custom field keys and review question keys stay derived.
9. Verified names used below: `Filament\Schemas\Schema`, `Filament\Tables\Table`, `Table::recordActions()/headerActions()/toolbarActions()/reorderable(string $column)/defaultSort()`, `Filament\Actions\{Action,CreateAction,EditAction,ViewAction,DeleteAction,DeleteBulkAction,BulkActionGroup}`, `Action::schema([...])`, `Filament\Support\Icons\Heroicon` (`OutlinedCalendarDays`, `OutlinedQrCode`, `OutlinedTag`, `OutlinedListBullet`, `OutlinedClipboardDocumentList`, `OutlinedMegaphone`, `OutlinedLockClosed`, `OutlinedArchiveBox`, `OutlinedArrowDownTray`, `OutlinedPrinter`, `OutlinedEye`, `OutlinedCheckCircle` all exist), `Filament\Schemas\Components\{Section,Grid,Tabs}` and `Tabs\Tab`, `Filament\Schemas\Components\Utilities\{Get,Set}`, `Filament\Forms\Components\{TextInput,Select,Textarea,RichEditor,DateTimePicker,DatePicker,Toggle,CheckboxList,ColorPicker,FileUpload,Repeater}`, `DateTimePicker::timezone()/seconds()/native()/after()`, `HasStateBindingModifiers::live()`, `Filament\Widgets\{Widget,TableWidget}` with `protected static ?int $sort`, `Filament\Resources\Pages\{ListRecords,CreateRecord,EditRecord,ViewRecord,Page}` and `Pages\Concerns\InteractsWithRecord`, `CreateRecord::afterCreate()` hook, `Filament\Notifications\Notification::make()->danger()->title()->body()->persistent()->send()`, `Filament\Facades\Filament::{setCurrentPanel,setTenant,bootCurrentPanel,getTenant,getCurrentPanel}`.
10. `spatie/laravel-activitylog` **v5** namespaces (as already used by `app/Models/Organization.php`): `Spatie\Activitylog\Models\Concerns\LogsActivity`, `Spatie\Activitylog\Support\LogOptions`, `->dontLogEmptyChanges()`.
11. `barryvdh/laravel-dompdf`: provider `Barryvdh\DomPDF\ServiceProvider`, facade `Barryvdh\DomPDF\Facade\Pdf` (aliases `Pdf` and `PDF`), config `config/dompdf.php` with `options.font_dir` / `options.font_cache` defaulting to `storage_path('fonts')` and `options.chroot` to `realpath(base_path())`. **v3.1.2 is the first release declaring `illuminate/support: ^13.0`** — earlier 3.1.x stop at Laravel 12.
12. `dompdf/dompdf` `FontMetrics::registerFont(array $style, string $remoteFile, $context = null): bool` accepts a local path and expects `family`, `weight`, `style` keys. This plan registers the bundled TTFs that way instead of using an `@font-face` URL, because a Windows `file://C:\...` URL inside dompdf CSS is not portable.
13. Livewire 4 turns a `StreamedResponse`/`BinaryFileResponse` returned from an action into a download (`SupportFileDownloads`). This plan still uses plain authenticated routes for QR/poster downloads: they are trivially testable with `get()` and carry a real `Gate::authorize()` call.
14. `Select::options(SomeEnum::class)` calls `enum()` internally (`forms/src/Components/Concerns/HasOptions.php:24`), which adds an `EnumStateCast`. `Get::__invoke()` returns `$component->getState()`, and `getState()` applies the casts — so **`$get('review_mode')` returns the enum case, never the backing string**. Compare against `ReviewMode::Assigned`, not `ReviewMode::Assigned->value`. This matters beyond visibility: a hidden field is not dehydrated (`schemas/src/Components/Concerns/HasState.php:775`), so an always-false `visible()` closure silently drops the value on save.
15. `Notification::make()->title()` and `->body()` are rendered as **sanitised HTML** (`str($title)->sanitizeHtml()`, `notifications/src/Notification.php:397`), through the shared Filament config from fact 4 — which keeps `style` and `class` on every element. Any organizer-supplied value interpolated into a notification must be wrapped in `e()` first.
16. Filament resources build record URLs by handing the **model** to `route()` (`Resources/Resource/Concerns/CanGenerateUrls.php:77`), and a page route is a plain `{record}` with no binding field (`Resources/Pages/Page.php:136`), so Laravel inserts `$model->getRouteKey()`. Resolution then runs `resolveRouteBindingQuery($query, $key, static::getRecordRouteKeyName())`. **If `$recordRouteKeyName` disagrees with the model's `getRouteKeyName()`, every link Filament generates 404s** while every test that mounts a page with an explicit key passes. This plan keeps them in agreement: `Conference::getRouteKeyName()` is `ulid` (spec 3: ULIDs are the public identifiers) and no resource overrides `$recordRouteKeyName`; the public page binds `{conference:slug}` explicitly in `routes/web.php`.

---

### Task 1: Branch, packages, poster fonts, dompdf config, guest redirect

**Files:**
- Create: `resources/fonts/IBMPlexSans-Regular.ttf`, `resources/fonts/IBMPlexSans-SemiBold.ttf`, `resources/fonts/IBMPlexMono-SemiBold.ttf`, `resources/fonts/LICENSE-IBM-Plex.txt`, `storage/fonts/.gitignore`, `config/dompdf.php`, `app/Support/Pdf/PosterFonts.php`
- Modify: `composer.json`/`composer.lock` (via composer), `.gitattributes`, `.gitignore`, `.env.example`, `config/cass.php`, `bootstrap/app.php`, `Dockerfile`, `docker/nginx.conf`
- Test: `tests/Feature/PosterFontsTest.php`

- [ ] **Step 1: Branch from `main`**

```bash
cd /c/Users/ahmed/Documents/CASS && git checkout main && git pull --ff-only && git checkout -b plan-2-conferences && git status -sb | head -2
```

Expected: `## plan-2-conferences` and a clean tree.

- [ ] **Step 2: Write the failing test**

`tests/Feature/PosterFontsTest.php`
```php
<?php

declare(strict_types=1);

use App\Support\Pdf\PosterFonts;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('ships the IBM Plex TTFs the poster needs', function () {
    expect(PosterFonts::files())->toHaveCount(3);

    foreach (PosterFonts::files() as $file) {
        expect(is_file($file['path']))->toBeTrue()
            ->and(filesize($file['path']))->toBeGreaterThan(100_000);
    }
});

it('registers those fonts with a dompdf instance', function () {
    // dompdf persists every registered family into font_dir/installed-fonts.json
    // and FontMetrics::getFont() keeps a process-wide static cache, so a test
    // that used the real storage/fonts would keep passing after register()
    // stopped working. Give this one an empty directory of its own.
    $fontDir = storage_path('framework/testing/fonts-'.Str::random(8));
    File::ensureDirectoryExists($fontDir);

    try {
        $dompdf = new Dompdf([
            'fontDir' => $fontDir,
            'fontCache' => $fontDir,
            'chroot' => [base_path()],
            'tempDir' => sys_get_temp_dir(),
        ]);

        expect($dompdf->getFontMetrics()->getFontFamilies())->not->toHaveKey('ibm plex sans');

        PosterFonts::register($dompdf);

        expect($dompdf->getFontMetrics()->getFontFamilies()['ibm plex sans'] ?? [])->toHaveKeys(['normal', 'bold'])
            ->and($dompdf->getFontMetrics()->getFont('IBM Plex Mono', 'bold'))->not->toBeNull();
    } finally {
        File::deleteDirectory($fontDir);
    }
});

it('sends guests on plain auth routes to the organizer login', function () {
    // Task 10 puts the conference asset downloads behind Laravel's own `auth`
    // middleware. The app has no route named "login", so bootstrap/app.php
    // must name the organizer panel's login page explicitly or
    // Authenticate::redirectTo() throws RouteNotFoundException. Probing a real
    // `auth` route is what makes this fail before Step 9: asserting only that
    // route('filament.organizer.auth.login') exists passes already.
    Route::middleware(['web', 'auth'])->get('/__auth-probe', fn (): string => 'ok');

    $this->get('/__auth-probe')->assertRedirect('/org/login');
});
```

- [ ] **Step 3: Run it to verify it fails**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/PosterFontsTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found|does not exist|Unable to find component" /tmp/t.log | head -3
```

Expected: `rc=1`, error mentions `Class "App\Support\Pdf\PosterFonts" not found`.

- [ ] **Step 4: Install the three packages**

`chillerlan/php-qrcode` (5.0.5) and `symfony/html-sanitizer` (8.1.6) are already in `composer.lock` as `filament/*` dependencies; this promotes both to direct requirements so an upgrade cannot silently drop them. The sanitiser is load-bearing on the public page's XSS path (`App\Support\Html\RichText`, Task 4), which is exactly the reason the QR library is promoted. **`chillerlan/php-qrcode:^6.0` is not installable** — Filament 5.8.1 requires `^5.0`.

```bash
cd /c/Users/ahmed/Documents/CASS && \
php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar require \
  chillerlan/php-qrcode:"^5.0" barryvdh/laravel-dompdf:"^3.1.2" symfony/html-sanitizer:"^8.0" --no-interaction && \
php -r '$l=json_decode(file_get_contents("composer.lock"),true); foreach($l["packages"] as $p){ if(in_array($p["name"],["chillerlan/php-qrcode","barryvdh/laravel-dompdf","dompdf/dompdf","symfony/html-sanitizer"],true)) echo $p["name"]." ".$p["version"]."\n"; }'
```

Expected: four lines, `chillerlan/php-qrcode 5.0.x`, `barryvdh/laravel-dompdf v3.1.2` (or newer 3.x), `dompdf/dompdf v3.1.x`, `symfony/html-sanitizer v8.x`. If composer reports a conflict on `chillerlan/php-qrcode`, you asked for `^6.0` — go back and use `^5.0`.

- [ ] **Step 5: Fetch the three IBM Plex TTFs, pinned by commit**

IBM Plex is OFL-1.1; the `@fontsource/*` npm packages ship only WOFF/WOFF2, which dompdf cannot read, so the TTFs are committed to the repo (about 560 KB total). Commit `1da12f02587b630c07e92692d21492d722f53614` is the tag `@ibm/plex-sans@1.1.0`. Plex Mono comes from the same commit because spec 13 sets reference numbers and codes in IBM Plex Mono, and the short URL on the poster is the one code a reader types by hand. The single committed `LICENSE-IBM-Plex.txt` covers the whole family.

```bash
cd /c/Users/ahmed/Documents/CASS && mkdir -p resources/fonts && \
BASE="https://raw.githubusercontent.com/IBM/plex/1da12f02587b630c07e92692d21492d722f53614/packages/plex-sans/fonts/complete/ttf" && \
BASE_MONO="https://raw.githubusercontent.com/IBM/plex/1da12f02587b630c07e92692d21492d722f53614/packages/plex-mono/fonts/complete/ttf" && \
curl -fsSL "$BASE/IBMPlexSans-Regular.ttf"      -o resources/fonts/IBMPlexSans-Regular.ttf && \
curl -fsSL "$BASE/IBMPlexSans-SemiBold.ttf"     -o resources/fonts/IBMPlexSans-SemiBold.ttf && \
curl -fsSL "$BASE_MONO/IBMPlexMono-SemiBold.ttf" -o resources/fonts/IBMPlexMono-SemiBold.ttf && \
curl -fsSL "$BASE/license.txt"                  -o resources/fonts/LICENSE-IBM-Plex.txt && \
ls -l resources/fonts && \
php -r 'foreach(["IBMPlexSans-Regular.ttf"=>200500,"IBMPlexSans-SemiBold.ttf"=>202632,"IBMPlexMono-SemiBold.ttf"=>157552,"LICENSE-IBM-Plex.txt"=>4360] as $f=>$n){ $s=filesize("resources/fonts/$f"); echo "$f $s ".($s===$n?"OK":"MISMATCH")."\n"; }'
```

Expected: `IBMPlexSans-Regular.ttf 200500 OK`, `IBMPlexSans-SemiBold.ttf 202632 OK`, `IBMPlexMono-SemiBold.ttf 157552 OK`, `LICENSE-IBM-Plex.txt 4360 OK`. A MISMATCH means the download was truncated or Git Bash mangled it — delete and retry, do not proceed.

- [ ] **Step 6: Keep git and Docker from corrupting or dropping the fonts**

Append to `.gitattributes` (the repo's `* text=auto eol=lf` would otherwise rewrite bytes in a TTF on Windows):

```gitattributes
*.ttf binary
*.otf binary
*.woff binary
```

Append to `.gitignore`:

```gitignore
# dompdf writes converted font metrics here at runtime
/storage/fonts/*
!/storage/fonts/.gitignore
```

Create `storage/fonts/.gitignore` so the directory exists in git, in CI and in the image:

```gitignore
*
!.gitignore
```

In `Dockerfile`, replace the whole final `RUN` of the runtime stage with the block below. It adds `storage/fonts` so the dompdf metrics cache exists even on a fresh volume, and it publishes the panel's own assets: Filament's CSS/JS/fonts live under `public/css|js|fonts/filament`, which `.gitignore` excludes and which only `php artisan filament:assets` creates, and the vendor stage runs `composer install --no-scripts`, so today's image ships **none of them** (the deployed `/org/login` is unstyled and has no Livewire). The comment goes above the `RUN`:

```dockerfile
# Filament's CSS/JS/fonts and Livewire's script are git-ignored and composer
# runs with --no-scripts, so publish them into public/ here. Livewire serves
# /vendor/livewire/livewire.min.js once public/vendor/livewire/manifest.json exists.
RUN chmod +x /usr/local/bin/entrypoint.sh \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
      storage/app/private storage/app/public storage/fonts bootstrap/cache /run/nginx \
 && php artisan filament:assets \
 && php artisan vendor:publish --tag=livewire:assets --force \
 && chown -R app:app storage bootstrap/cache /run/nginx /var/lib/nginx /var/log/nginx \
 && chmod 755 /var/www/html
```

In `docker/nginx.conf`, change the last line of the static-extension location from `try_files $uri =404;` to:

```nginx
    location ~* \.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?)$ {
        add_header X-Content-Type-Options nosniff always;
        expires 30d;
        access_log off;
        # A regex location beats the `location /` prefix, so any URI ending in
        # one of these extensions was answered from disk and 404'd by nginx
        # before PHP saw it - which is why Livewire's route-served
        # /livewire-<hash>/livewire.min.js is 404 in production today. Fall
        # through to Laravel instead; real files still win via $uri.
        try_files $uri /index.php?$query_string;
    }
```

Task 10 also keeps the QR download routes extension-less (`/qr-svg`, `/qr-png`) so they never depend on this rule.

`.dockerignore` already lets `resources/` through, so the TTFs land in the image with `COPY --chown=app:app . .`. No change needed there. Before pushing, run `docker build .` once locally to confirm the two build-time `artisan` calls boot without a `.env` (they touch neither the database nor the encrypter).

- [ ] **Step 7: Publish and trim `config/dompdf.php`**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan vendor:publish --provider="Barryvdh\DomPDF\ServiceProvider" --no-interaction && ls -l config/dompdf.php
```

Then replace the whole file with this trimmed version (everything not listed keeps the package default):

```php
<?php

declare(strict_types=1);

return [
    /*
     * CASS renders exactly one document with dompdf: the conference poster.
     * It contains no user-supplied URLs - the organization logo and the QR
     * code are inlined as data: URIs by GenerateConferencePoster - so remote
     * fetching, PHP and JavaScript are all switched off. The only thing
     * dompdf reads from disk is the three bundled IBM Plex TTFs, and
     * those are registered programmatically by App\Support\Pdf\PosterFonts
     * rather than through an @font-face URL, because a Windows
     * "file://C:\..." URL is not portable.
     */
    'show_warnings' => false,
    'public_path' => null,
    'convert_entities' => true,

    'options' => [
        'font_dir' => storage_path('fonts'),
        'font_cache' => storage_path('fonts'),
        'temp_dir' => sys_get_temp_dir(),
        'chroot' => realpath(base_path()),
        'allowed_protocols' => [
            'data://' => ['rules' => []],
            'file://' => ['rules' => []],
        ],
        'enable_font_subsetting' => false,
        'pdf_backend' => 'CPDF',
        'default_media_type' => 'print',
        'default_paper_size' => 'a4',
        'default_paper_orientation' => 'portrait',
        'default_font' => 'sans-serif',
        'dpi' => 96,
        'enable_php' => false,
        'enable_javascript' => false,
        'enable_remote' => false,
        'allowed_remote_hosts' => null,
        'font_height_ratio' => 1.1,
        'enable_html5_parser' => true,
    ],
];
```

- [ ] **Step 8: Write `app/Support/Pdf/PosterFonts.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use Dompdf\Dompdf;

final class PosterFonts
{
    public const FAMILY = 'IBM Plex Sans';

    public const MONO_FAMILY = 'IBM Plex Mono';

    /**
     * The three faces the poster template uses, with the dompdf style keys
     * FontMetrics::registerFont() expects. The Mono face is registered as
     * `bold` because the poster's `.short-url` rule is `font-weight: bold`,
     * and dompdf does not fall back to another weight inside a named family:
     * FontMetrics::getFont() returns null when the family has no entry for the
     * requested subtype, and the next family in the CSS stack is used instead.
     *
     * @return list<array{family: string, weight: string, style: string, path: string}>
     */
    public static function files(): array
    {
        return [
            [
                'family' => self::FAMILY,
                'weight' => 'normal',
                'style' => 'normal',
                'path' => base_path('resources/fonts/IBMPlexSans-Regular.ttf'),
            ],
            [
                'family' => self::FAMILY,
                'weight' => 'bold',
                'style' => 'normal',
                'path' => base_path('resources/fonts/IBMPlexSans-SemiBold.ttf'),
            ],
            [
                'family' => self::MONO_FAMILY,
                'weight' => 'bold',
                'style' => 'normal',
                'path' => base_path('resources/fonts/IBMPlexMono-SemiBold.ttf'),
            ],
        ];
    }

    /**
     * dompdf caches the parsed metrics in options.font_dir, so this is cheap
     * after the first render on a given machine.
     */
    public static function register(Dompdf $dompdf): void
    {
        if (! is_dir($directory = storage_path('fonts'))) {
            mkdir($directory, 0o775, recursive: true);
        }

        $metrics = $dompdf->getFontMetrics();

        foreach (self::files() as $font) {
            $metrics->registerFont(
                ['family' => $font['family'], 'weight' => $font['weight'], 'style' => $font['style']],
                $font['path'],
            );
        }
    }
}
```

- [ ] **Step 9: Send guests on non-panel authenticated routes to the organizer login**

Task 10 adds `/conference-assets/...` routes behind Laravel's own `auth` middleware. There is no bare `login` route (each Filament panel owns its own), so `Authenticate::redirectTo()` would throw `RouteNotFoundException`. Add to `bootstrap/app.php` inside `->withMiddleware(...)`, after the `trustHosts(...)` line:

```php
        // Filament's own Authenticate middleware redirects panel routes to
        // that panel's login page. Plain `auth` routes (the conference
        // asset downloads) need an explicit target because the app has no
        // route named "login".
        $middleware->redirectGuestsTo(fn (): string => route('filament.organizer.auth.login'));
```

- [ ] **Step 10: Config and environment**

Append to `config/cass.php` inside the returned array, after `'turnstile' => [...]`:

```php
    // Scans counted per client IP per link per minute. The /q redirect itself
    // is never refused (spec 5.7); this only stops a script inflating the
    // counter, so a lecture hall behind one NAT still reaches the page.
    'short_link_rate_limit' => (int) env('CASS_SHORT_LINK_RATE_LIMIT', 60),
    'qr' => [
        // The PNG is rendered at whole-module scale, so the real width is the
        // smallest multiple of the module count that reaches this size.
        'png_min_size' => (int) env('CASS_QR_PNG_MIN_SIZE', 1024),
    ],
```

Append to `.env.example` under the `# --- CASS ---` block:

```ini
# Scans counted per client IP per link per minute (the redirect is never refused).
CASS_SHORT_LINK_RATE_LIMIT=60
CASS_QR_PNG_MIN_SIZE=1024
```

- [ ] **Step 11: Run the test to verify it passes**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/PosterFontsTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -4 /tmp/t.log
```

Expected: `rc=0`, `3 passed`. The guest-redirect test above uses a throwaway `auth` route; the end-to-end redirect through a real asset route is asserted in Task 10, where the route exists.

- [ ] **Step 12: Full suite, Pint, Larastan, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log && \
./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?"
```

Expected: `tests rc=0` with `56 passed`, `stan rc=0` with `[OK] No errors`.

```bash
cd /c/Users/ahmed/Documents/CASS && git add -A && git commit -q -m "chore: add dompdf and php-qrcode, bundle IBM Plex TTFs, publish panel assets in the image

chillerlan/php-qrcode is pinned to ^5.0 because filament/filament 5.8.1
requires ^5.0; ^6.0 is a hard conflict. barryvdh/laravel-dompdf ^3.1.2 is
the first release that declares Laravel 13 support. symfony/html-sanitizer
becomes a direct requirement because App\Support\Html\RichText uses it on
the public page's XSS path.

The image now runs filament:assets and publishes Livewire's script, and
nginx falls through to Laravel for URIs ending in a static extension, so
panel CSS/JS and route-served scripts stop 404ing in production.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline | head -1
```

---

### Task 2: Conference enums, table, model and factory

**Files:**
- Create: `app/Enums/ConferenceStatus.php`, `app/Enums/ReviewMode.php`, `app/Enums/SubmissionWindow.php`
- Create: `database/migrations/2026_09_11_000100_create_conferences_table.php`
- Create: `app/Models/Conference.php`, `database/factories/ConferenceFactory.php`
- Test: `tests/Unit/ConferenceTest.php`, `tests/Unit/ConferenceStatusTest.php`

- [ ] **Step 1: Write the failing unit tests**

`tests/Unit/ConferenceTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\ReviewMode;
use App\Enums\SubmissionWindow;
use App\Models\Conference;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a conference with a ulid, a derived slug and draft status', function () {
    $conference = Conference::factory()->create(['name' => 'Gulf Pediatric Critical Care 2026']);

    expect($conference->ulid)->toHaveLength(26)
        ->and($conference->slug)->toBe('gulf-pediatric-critical-care-2026')
        ->and($conference->status)->toBe(ConferenceStatus::Draft)
        ->and($conference->review_mode)->toBe(ReviewMode::OpenPool)
        ->and($conference->timezone)->toBe('Asia/Riyadh')
        ->and($conference->word_limit)->toBe(500)
        ->and($conference->max_files)->toBe(3)
        ->and($conference->allowed_file_types)->toBe(['pdf'])
        ->and($conference->presentation_types)->toBe(['oral', 'poster', 'either'])
        ->and($conference->blind_review)->toBeTrue();
});

it('makes the slug unique inside one organization but reusable across organizations', function () {
    $alpha = Organization::factory()->approved()->create();
    $beta = Organization::factory()->approved()->create();

    $first = Conference::factory()->for($alpha)->create(['name' => 'Annual Meeting']);
    $second = Conference::factory()->for($alpha)->create(['name' => 'Annual Meeting']);
    $other = Conference::factory()->for($beta)->create(['name' => 'Annual Meeting']);

    expect($first->slug)->toBe('annual-meeting')
        ->and($second->slug)->toBe('annual-meeting-2')
        ->and($other->slug)->toBe('annual-meeting');
});

it('keeps a soft-deleted slug reserved so old links never point at a different conference', function () {
    $organization = Organization::factory()->approved()->create();
    Conference::factory()->for($organization)->create(['name' => 'Winter School'])->delete();

    $replacement = Conference::factory()->for($organization)->create(['name' => 'Winter School']);

    expect($replacement->slug)->toBe('winter-school-2');
});

it('belongs to its organization and answers public visibility per status', function () {
    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->create();

    expect($conference->organization->is($organization))->toBeTrue()
        ->and($conference->isPubliclyVisible())->toBeFalse();

    foreach ([ConferenceStatus::Open, ConferenceStatus::Closed, ConferenceStatus::Reviewing, ConferenceStatus::Decided] as $status) {
        $conference->forceFill(['status' => $status])->save();
        expect($conference->isPubliclyVisible())->toBeTrue();
    }

    $conference->forceFill(['status' => ConferenceStatus::Archived])->save();
    expect($conference->isPubliclyVisible())->toBeFalse();
});

it('reports the submission window state from the dates and the status', function () {
    $conference = Conference::factory()->create([
        'submission_opens_at' => null,
        'submission_deadline' => null,
    ]);
    expect($conference->submissionWindow())->toBe(SubmissionWindow::NotConfigured);

    $conference->forceFill([
        'status' => ConferenceStatus::Open,
        'submission_opens_at' => now()->addDays(3),
        'submission_deadline' => now()->addDays(30),
    ])->save();
    expect($conference->submissionWindow())->toBe(SubmissionWindow::Upcoming);

    $conference->forceFill(['submission_opens_at' => now()->subDay()])->save();
    expect($conference->submissionWindow())->toBe(SubmissionWindow::Open)
        ->and($conference->acceptsSubmissions())->toBeTrue();

    $conference->forceFill(['submission_deadline' => now()->subHour()])->save();
    expect($conference->submissionWindow())->toBe(SubmissionWindow::Closed)
        ->and($conference->acceptsSubmissions())->toBeFalse();

    // A conference the organizer closed by hand is closed even inside the window.
    $conference->forceFill([
        'status' => ConferenceStatus::Closed,
        'submission_deadline' => now()->addDays(10),
    ])->save();
    expect($conference->submissionWindow())->toBe(SubmissionWindow::Closed);
});

it('presents deadlines in the conference timezone while storing utc', function () {
    $conference = Conference::factory()->create([
        'timezone' => 'Asia/Riyadh',
        'submission_deadline' => '2026-11-30 21:00:00',
    ]);

    expect($conference->submission_deadline->toDateTimeString())->toBe('2026-11-30 21:00:00')
        ->and($conference->deadlineInConferenceTimezone()?->format('Y-m-d H:i T'))->toBe('2026-12-01 00:00 +03');
});
```

`tests/Unit/ConferenceStatusTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;

it('labels and colours every status', function () {
    foreach (ConferenceStatus::cases() as $status) {
        expect($status->getLabel())->toBeString()->not->toBeEmpty()
            ->and($status->getColor())->toBeString()->not->toBeEmpty();
    }

    expect(ConferenceStatus::Open->getLabel())->toBe('Open for submissions')
        ->and(ConferenceStatus::Draft->getColor())->toBe('gray');
});

it('allows only the transitions plan 2 and later plans implement', function () {
    expect(ConferenceStatus::Draft->canTransitionTo(ConferenceStatus::Open))->toBeTrue()
        ->and(ConferenceStatus::Closed->canTransitionTo(ConferenceStatus::Open))->toBeTrue()
        ->and(ConferenceStatus::Open->canTransitionTo(ConferenceStatus::Closed))->toBeTrue()
        ->and(ConferenceStatus::Closed->canTransitionTo(ConferenceStatus::Reviewing))->toBeTrue()
        ->and(ConferenceStatus::Reviewing->canTransitionTo(ConferenceStatus::Decided))->toBeTrue()
        ->and(ConferenceStatus::Draft->canTransitionTo(ConferenceStatus::Archived))->toBeTrue()
        ->and(ConferenceStatus::Archived->canTransitionTo(ConferenceStatus::Open))->toBeFalse()
        ->and(ConferenceStatus::Draft->canTransitionTo(ConferenceStatus::Decided))->toBeFalse()
        ->and(ConferenceStatus::Open->canTransitionTo(ConferenceStatus::Open))->toBeFalse();
});

it('knows which statuses are public and which still take submissions', function () {
    expect(ConferenceStatus::Draft->isPublic())->toBeFalse()
        ->and(ConferenceStatus::Archived->isPublic())->toBeFalse()
        ->and(ConferenceStatus::Open->isPublic())->toBeTrue()
        ->and(ConferenceStatus::Decided->isPublic())->toBeTrue()
        ->and(ConferenceStatus::Open->acceptsSubmissions())->toBeTrue()
        ->and(ConferenceStatus::Closed->acceptsSubmissions())->toBeFalse();
});
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ConferenceTest.php tests/Unit/ConferenceStatusTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found|does not exist|Unable to find component" /tmp/t.log | head -3
```

Expected: `rc=1`. `ConferenceTest` runs first and its first line is `Conference::factory()`, so the message is `Class "App\Models\Conference" not found`.

- [ ] **Step 3: Write the enums**

`app/Enums/ConferenceStatus.php`
```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ConferenceStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case Reviewing = 'reviewing';
    case Decided = 'decided';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Open for submissions',
            self::Closed => 'Submissions closed',
            self::Reviewing => 'Under review',
            self::Decided => 'Decisions sent',
            self::Archived => 'Archived',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Open => 'success',
            self::Closed => 'warning',
            self::Reviewing => 'info',
            self::Decided => 'primary',
            self::Archived => 'danger',
        };
    }

    /**
     * The full lifecycle. Plan 2 drives Draft/Closed -> Open, Open -> Closed
     * and anything -> Archived; Plan 4 drives Closed -> Reviewing and Plan 5
     * drives Reviewing -> Decided. The table is written once, here, so the
     * later plans add behaviour rather than re-deciding the graph.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Open, self::Archived],
            self::Open => [self::Closed, self::Archived],
            self::Closed => [self::Open, self::Reviewing, self::Archived],
            self::Reviewing => [self::Decided, self::Closed, self::Archived],
            self::Decided => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /** Visible on `/c/{org}/{conference}` to anyone. */
    public function isPublic(): bool
    {
        return ! in_array($this, [self::Draft, self::Archived], true);
    }

    /** The status gate on new submissions; the date window is checked separately. */
    public function acceptsSubmissions(): bool
    {
        return $this === self::Open;
    }
}
```

`app/Enums/ReviewMode.php`
```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReviewMode: string implements HasLabel
{
    case OpenPool = 'open_pool';
    case Assigned = 'assigned';

    public function getLabel(): string
    {
        return match ($this) {
            self::OpenPool => 'Open pool - every reviewer sees every abstract',
            self::Assigned => 'Assigned - each abstract goes to named reviewers',
        };
    }

    public function needsReviewersPerSubmission(): bool
    {
        return $this === self::Assigned;
    }
}
```

`app/Enums/SubmissionWindow.php`
```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum SubmissionWindow: string
{
    case NotConfigured = 'not_configured';
    case Upcoming = 'upcoming';
    case Open = 'open';
    case Closed = 'closed';
}
```

- [ ] **Step 4: Write the migration**

`database/migrations/2026_09_11_000100_create_conferences_table.php`
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
        Schema::create('conferences', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // RESTRICT, not CASCADE: spec section 3 says deleting a conference
            // is soft-delete only and the platform-admin hard purge cascades in
            // application code. A database cascade would silently wipe the tree
            // (and, once Plan 3 lands, orphan files in private storage) without
            // running any of that code.
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();
            $table->string('venue')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 8)->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('timezone', 64)->default('Asia/Riyadh');
            $table->timestamp('submission_opens_at')->nullable();
            $table->timestamp('submission_deadline')->nullable();
            $table->timestamp('review_deadline')->nullable();
            $table->string('review_mode', 16)->default('open_pool');
            $table->boolean('blind_review')->default(true);
            $table->unsignedTinyInteger('reviewers_per_submission')->default(2);
            $table->unsignedSmallInteger('word_limit')->default(500);
            $table->unsignedTinyInteger('max_files')->default(3);
            $table->json('allowed_file_types');
            $table->json('presentation_types');
            $table->text('terms')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conferences');
    }
};
```

> Note on the unique index: soft-deleted rows keep their `(organization_id, slug)` pair, which is deliberate. `Conference::uniqueSlug()` looks at trashed rows too, so a deleted conference's public URL can never be silently reassigned to a different conference.

- [ ] **Step 5: Write the model**

`app/Models/Conference.php`
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConferenceStatus;
use App\Enums\ReviewMode;
use App\Enums\SubmissionWindow;
use Carbon\CarbonInterface;
use Database\Factories\ConferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Conference extends Model
{
    /** @use HasFactory<ConferenceFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    /**
     * `organization_id`, `ulid`, `slug`, `status` and `published_at` are
     * deliberately absent: the tenant comes from the panel, the slug is
     * derived, and the status only moves through the action classes with
     * forceFill(). Model::preventSilentlyDiscardingAttributes() is on
     * outside production, so a form field that is not listed here fails
     * loudly instead of being dropped.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name', 'short_description', 'description', 'venue', 'city', 'country',
        'starts_at', 'ends_at', 'timezone',
        'submission_opens_at', 'submission_deadline', 'review_deadline',
        'review_mode', 'blind_review', 'reviewers_per_submission',
        'word_limit', 'max_files', 'allowed_file_types', 'presentation_types', 'terms',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ConferenceStatus::class,
            'review_mode' => ReviewMode::class,
            'starts_at' => 'date',
            'ends_at' => 'date',
            'submission_opens_at' => 'datetime',
            'submission_deadline' => 'datetime',
            'review_deadline' => 'datetime',
            'published_at' => 'datetime',
            'blind_review' => 'boolean',
            'reviewers_per_submission' => 'integer',
            'word_limit' => 'integer',
            'max_files' => 'integer',
            'allowed_file_types' => 'array',
            'presentation_types' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Conference $conference): void {
            $conference->ulid ??= (string) Str::ulid();
            $conference->slug ??= static::uniqueSlug((int) $conference->organization_id, (string) $conference->name);
        });
    }

    /**
     * Scoped to one organization, and blocked by trashed rows as well, so a
     * published URL is never recycled.
     */
    public static function uniqueSlug(int $organizationId, string $name): string
    {
        $base = Str::slug($name) ?: 'conference';
        $slug = $base;
        $n = 2;

        while (static::withTrashed()->where('organization_id', $organizationId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    /**
     * Spec section 3 makes the ULID the public identifier, and Filament builds
     * every record URL from getRouteKey() while resolving it with the
     * resource's own key name, so these two must agree (fact 16). The public
     * page is the one place that wants the slug, and routes/web.php asks for
     * it explicitly with `{conference:slug}`.
     */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status->isPublic();
    }

    public function submissionWindow(): SubmissionWindow
    {
        if ($this->submission_opens_at === null || $this->submission_deadline === null) {
            return SubmissionWindow::NotConfigured;
        }

        if (! $this->status->acceptsSubmissions()) {
            return SubmissionWindow::Closed;
        }

        return match (true) {
            $this->submission_opens_at->isFuture() => SubmissionWindow::Upcoming,
            $this->submission_deadline->isPast() => SubmissionWindow::Closed,
            default => SubmissionWindow::Open,
        };
    }

    public function acceptsSubmissions(): bool
    {
        return $this->submissionWindow() === SubmissionWindow::Open;
    }

    /** Timestamps are stored UTC; organizers and authors read them locally. */
    public function deadlineInConferenceTimezone(): ?CarbonInterface
    {
        return $this->submission_deadline?->copy()->setTimezone($this->timezone);
    }

    public function opensAtInConferenceTimezone(): ?CarbonInterface
    {
        return $this->submission_opens_at?->copy()->setTimezone($this->timezone);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'status', 'submission_opens_at', 'submission_deadline', 'review_deadline', 'published_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
```

- [ ] **Step 6: Write the factory**

`database/factories/ConferenceFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConferenceStatus;
use App\Enums\ReviewMode;
use App\Models\Conference;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conference> */
class ConferenceFactory extends Factory
{
    protected $model = Conference::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory()->approved(),
            'name' => fake()->unique()->catchPhrase().' Conference',
            'short_description' => fake()->sentence(14),
            'description' => '<p>'.fake()->paragraph(4).'</p>',
            'venue' => fake()->company().' Convention Centre',
            'city' => 'Riyadh',
            'country' => 'SA',
            'starts_at' => now()->addMonths(6)->toDateString(),
            'ends_at' => now()->addMonths(6)->addDays(2)->toDateString(),
            'timezone' => 'Asia/Riyadh',
            'submission_opens_at' => null,
            'submission_deadline' => null,
            'review_deadline' => null,
            'review_mode' => ReviewMode::OpenPool,
            'blind_review' => true,
            'reviewers_per_submission' => 2,
            'word_limit' => 500,
            'max_files' => 3,
            'allowed_file_types' => ['pdf'],
            'presentation_types' => ['oral', 'poster', 'either'],
            'terms' => 'Presenting authors must register for the conference.',
            'status' => ConferenceStatus::Draft,
        ];
    }

    /** A conference with a valid, currently open submission window. */
    public function withSubmissionWindow(): static
    {
        return $this->state(fn () => [
            'submission_opens_at' => now()->subWeek(),
            'submission_deadline' => now()->addMonth(),
            'review_deadline' => now()->addMonths(2),
        ]);
    }

    public function upcomingWindow(): static
    {
        return $this->state(fn () => [
            'submission_opens_at' => now()->addWeek(),
            'submission_deadline' => now()->addMonths(2),
            'review_deadline' => now()->addMonths(3),
        ]);
    }

    public function published(): static
    {
        return $this->withSubmissionWindow()->state(fn () => [
            'status' => ConferenceStatus::Open,
            'published_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->withSubmissionWindow()->state(fn () => [
            'status' => ConferenceStatus::Closed,
            'published_at' => now()->subMonth(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => ConferenceStatus::Archived,
            'published_at' => now()->subYear(),
        ]);
    }

    public function assignedReview(): static
    {
        return $this->state(fn () => [
            'review_mode' => ReviewMode::Assigned,
            'reviewers_per_submission' => 3,
        ]);
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ConferenceTest.php tests/Unit/ConferenceStatusTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -4 /tmp/t.log
```

Expected: `rc=0`, `9 passed`.

- [ ] **Step 8: Pint, Larastan, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?" && \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log && \
git add -A && git commit -q -m "feat: conference model, status/review-mode enums, schema and factory

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

Expected: `stan rc=0`, `tests rc=0`, `65 passed`.

---

### Task 3: Tracks, custom fields, review forms and review questions

**Files:**
- Create: `app/Enums/CustomFieldType.php`, `app/Enums/ReviewQuestionType.php`
- Create: `app/Exceptions/ReviewFormLocked.php`
- Create: `database/migrations/2026_09_11_000200_create_tracks_table.php`, `..._000300_create_custom_fields_table.php`, `..._000400_create_review_forms_table.php`, `..._000500_create_review_questions_table.php`
- Create: `app/Models/Track.php`, `app/Models/CustomField.php`, `app/Models/ReviewForm.php`, `app/Models/ReviewQuestion.php`
- Create: `database/factories/{TrackFactory,CustomFieldFactory,ReviewFormFactory,ReviewQuestionFactory}.php`
- Modify: `app/Models/Conference.php` (relations)
- Test: `tests/Unit/ReviewFormTest.php`

- [ ] **Step 1: Write the failing unit test**

`tests/Unit/ReviewFormTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\CustomFieldType;
use App\Enums\ReviewQuestionType;
use App\Exceptions\ReviewFormLocked;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\Track;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('hangs tracks and custom fields off a conference in sort order', function () {
    $conference = Conference::factory()->create();

    Track::factory()->for($conference)->create(['name' => 'Neonatology', 'sort' => 2]);
    Track::factory()->for($conference)->create(['name' => 'Cardiology', 'sort' => 1]);

    expect($conference->tracks()->pluck('name')->all())->toBe(['Cardiology', 'Neonatology']);

    $field = CustomField::factory()->for($conference)->create([
        'label' => 'Funding source',
        'type' => CustomFieldType::Select,
        'options' => ['None', 'Institutional', 'Industry'],
        'required' => true,
    ]);

    expect($field->key)->toBe('funding_source')
        ->and($field->type)->toBe(CustomFieldType::Select)
        ->and($field->options)->toBe(['None', 'Institutional', 'Industry'])
        ->and($conference->customFields()->count())->toBe(1);
});

it('derives a unique custom field key per conference', function () {
    $conference = Conference::factory()->create();

    $first = CustomField::factory()->for($conference)->create(['label' => 'IRB approval']);
    $second = CustomField::factory()->for($conference)->create(['label' => 'IRB approval']);
    $elsewhere = CustomField::factory()->create(['label' => 'IRB approval']);

    expect($first->key)->toBe('irb_approval')
        ->and($second->key)->toBe('irb_approval_2')
        ->and($elsewhere->key)->toBe('irb_approval');
});

it('exposes one active review form and its questions from the conference', function () {
    $conference = Conference::factory()->create();
    $form = ReviewForm::factory()->for($conference)->create();
    ReviewQuestion::factory()->for($form)->create(['prompt' => 'Second', 'sort' => 2]);
    ReviewQuestion::factory()->for($form)->create(['prompt' => 'First', 'sort' => 1]);

    expect($conference->reviewForm?->is($form))->toBeTrue()
        ->and($conference->reviewForm?->questions()->pluck('prompt')->all())->toBe(['First', 'Second'])
        ->and($conference->reviewQuestions()->count())->toBe(2)
        ->and($form->isLocked())->toBeFalse();
});

it('ignores an inactive review form when resolving the active one', function () {
    $conference = Conference::factory()->create();
    ReviewForm::factory()->for($conference)->create(['name' => 'Old form', 'is_active' => false]);
    $active = ReviewForm::factory()->for($conference)->create(['name' => 'Current form']);

    expect($conference->reviewForm?->is($active))->toBeTrue()
        ->and($conference->reviewForms()->count())->toBe(2);
});

it('stores a likert question with its scale and weight', function () {
    $question = ReviewQuestion::factory()->create([
        'prompt' => 'Originality and Innovation: How original and innovative is the research presented in the abstract?',
        'type' => ReviewQuestionType::Likert,
        'scale_min' => 1,
        'scale_max' => 5,
        'weight' => '1.50',
    ]);

    // Read the weight back from storage: SQLite gives a decimal column NUMERIC
    // affinity and hands back int(1) / float(1.5), so only the `decimal:2` cast
    // makes this a two-decimal string on both drivers.
    expect($question->type)->toBe(ReviewQuestionType::Likert)
        ->and($question->scale_min)->toBe(1)
        ->and($question->scale_max)->toBe(5)
        ->and($question->fresh()?->weight)->toBe('1.50')
        ->and($question->isScored())->toBeTrue();
});

it('locks existing questions once the form is locked but still allows new ones', function () {
    $form = ReviewForm::factory()->locked()->create();
    $existing = ReviewQuestion::factory()->for($form)->create(['prompt' => 'Originality']);

    expect($form->isLocked())->toBeTrue();

    expect(fn () => $existing->update(['prompt' => 'Changed']))->toThrow(ReviewFormLocked::class);
    expect(fn () => $existing->delete())->toThrow(ReviewFormLocked::class);

    // Spec section 3: after the lock, questions may still be appended.
    $appended = ReviewQuestion::factory()->for($form)->create(['prompt' => 'Added later', 'sort' => 99]);
    expect($appended->exists)->toBeTrue()
        ->and($form->questions()->count())->toBe(2);
});

it('appends a new row to the end of its parent instead of sorting it first', function () {
    $conference = Conference::factory()->create();
    Track::factory()->for($conference)->create(['name' => 'Cardiology', 'sort' => 1]);
    Track::factory()->for($conference)->create(['name' => 'Neurology', 'sort' => 2]);
    CustomField::factory()->for($conference)->create(['label' => 'Funding', 'sort' => 4]);
    $form = ReviewForm::factory()->for($conference)->create();
    ReviewQuestion::factory()->for($form)->create(['prompt' => 'First', 'sort' => 1]);

    // Filament's CreateAction never fills the reorder column, so a row created
    // through a relation manager would otherwise take the DB default 0 and sort
    // above everything that already exists.
    $track = new Track(['name' => 'Respiratory']);
    $track->conference()->associate($conference);
    $track->save();

    $field = new CustomField(['label' => 'IRB approval', 'type' => CustomFieldType::Text]);
    $field->conference()->associate($conference);
    $field->save();

    $question = new ReviewQuestion(['prompt' => 'Added later', 'type' => ReviewQuestionType::Text]);
    $question->reviewForm()->associate($form);
    $question->save();

    expect($track->sort)->toBe(3)
        ->and($field->sort)->toBe(5)
        ->and($question->sort)->toBe(2)
        ->and($conference->tracks()->pluck('name')->last())->toBe('Respiratory')
        ->and($form->questions()->pluck('prompt')->last())->toBe('Added later');
});

it('leaves an unlocked form fully editable', function () {
    $form = ReviewForm::factory()->create();
    $question = ReviewQuestion::factory()->for($form)->create(['prompt' => 'Clarity', 'sort' => 1]);

    $question->update(['sort' => 4, 'prompt' => 'Clarity of presentation']);
    expect($question->fresh()?->sort)->toBe(4);

    $question->delete();
    expect($form->questions()->count())->toBe(0);
});

it('refuses to hard delete a conference that still has children', function () {
    // Spec section 3: deleting a conference is soft-delete only, and the
    // platform-admin hard purge cascades in application code. The database
    // must therefore RESTRICT rather than quietly removing the tree (which
    // would also skip the review-question lock hooks).
    $conference = Conference::factory()->create();
    Track::factory()->for($conference)->create();

    expect(fn () => $conference->forceDelete())->toThrow(QueryException::class)
        ->and(Track::count())->toBe(1)
        ->and(Conference::withTrashed()->count())->toBe(1);
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ReviewFormTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found|does not exist|Unable to find component" /tmp/t.log | head -3
```

Expected: `rc=1`. `Conference` already exists from Task 2, so the first unresolved class in the first test is `Track::factory()` and the message is `Class "App\Models\Track" not found`.

- [ ] **Step 3: Write the two enums and the exception**

`app/Enums/CustomFieldType.php`
```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CustomFieldType: string implements HasLabel
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Select = 'select';
    case Checkbox = 'checkbox';
    case Number = 'number';

    public function getLabel(): string
    {
        return match ($this) {
            self::Text => 'Single line of text',
            self::Textarea => 'Paragraph',
            self::Select => 'Choose one from a list',
            self::Checkbox => 'Yes / no checkbox',
            self::Number => 'Number',
        };
    }

    public function needsOptions(): bool
    {
        return $this === self::Select;
    }
}
```

`app/Enums/ReviewQuestionType.php`
```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReviewQuestionType: string implements HasLabel
{
    case Likert = 'likert';
    case Text = 'text';
    case Boolean = 'boolean';
    case Select = 'select';

    public function getLabel(): string
    {
        return match ($this) {
            self::Likert => 'Rating scale',
            self::Text => 'Free text comment',
            self::Boolean => 'Yes / no',
            self::Select => 'Choose one from a list',
        };
    }

    /** Text answers carry no score (spec 5.6); Plan 5 normalises the rest. */
    public function isScored(): bool
    {
        return $this !== self::Text;
    }

    public function needsScale(): bool
    {
        return $this === self::Likert;
    }

    public function needsOptions(): bool
    {
        return $this === self::Select;
    }
}
```

`app/Exceptions/ReviewFormLocked.php`
```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ReviewFormLocked extends RuntimeException
{
    public static function make(): self
    {
        return new self('This review form is locked because reviews have already been submitted. Existing questions can no longer be changed or removed; you can still add new questions.');
    }
}
```

- [ ] **Step 4: Write the four migrations**

`database/migrations/2026_09_11_000200_create_tracks_table.php`
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
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // RESTRICT everywhere in the conference tree; see the note on
            // conferences.organization_id.
            $table->foreignId('conference_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['conference_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
```

`database/migrations/2026_09_11_000300_create_custom_fields_table.php`
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
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('conference_id')->constrained()->restrictOnDelete();
            $table->string('key', 64);
            $table->string('label');
            $table->string('help_text', 500)->nullable();
            $table->string('type', 16);
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['conference_id', 'key']);
            $table->index(['conference_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};
```

`database/migrations/2026_09_11_000400_create_review_forms_table.php`
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
        Schema::create('review_forms', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('conference_id')->constrained()->restrictOnDelete();
            $table->string('name')->default('Review form');
            $table->boolean('is_active')->default(true);
            // Set by Plan 4 when the first review is submitted. Spec section 3:
            // from that moment questions are locked and only new ones may be
            // appended.
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->index(['conference_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_forms');
    }
};
```

`database/migrations/2026_09_11_000500_create_review_questions_table.php`
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
        Schema::create('review_questions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('review_form_id')->constrained()->restrictOnDelete();
            $table->text('prompt');
            $table->string('help_text', 500)->nullable();
            $table->string('type', 16);
            $table->unsignedTinyInteger('scale_min')->nullable();
            $table->unsignedTinyInteger('scale_max')->nullable();
            $table->json('options')->nullable();
            $table->decimal('weight', 5, 2)->default(1);
            $table->boolean('required')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['review_form_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_questions');
    }
};
```

- [ ] **Step 5: Write `app/Models/Track.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TrackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Track extends Model
{
    /** @use HasFactory<TrackFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'sort'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (Track $track): void {
            $track->ulid ??= (string) Str::ulid();
            // Filament's CreateAction only fills the form data and saves, so
            // nothing sets the reorder column and a new row would take the DB
            // default 0 and jump above every existing track. Append instead;
            // an explicit sort (the factories, the review-form template) wins.
            $track->sort ??= ((int) static::query()->where('conference_id', $track->conference_id)->max('sort')) + 1;
        });
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }
}
```

- [ ] **Step 6: Write `app/Models/CustomField.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomFieldType;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CustomField extends Model
{
    /** @use HasFactory<CustomFieldFactory> */
    use HasFactory;

    /**
     * `key` is derived from the label on creation and never re-derived: it is
     * the storage key for every answer already submitted (Plan 3).
     *
     * @var list<string>
     */
    protected $fillable = ['label', 'help_text', 'type', 'options', 'required', 'sort'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CustomFieldType::class,
            'options' => 'array',
            'required' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CustomField $field): void {
            $field->ulid ??= (string) Str::ulid();
            $field->key ??= static::uniqueKey((int) $field->conference_id, (string) $field->label);
            // Append rather than take the DB default 0; see Track::booted().
            $field->sort ??= ((int) static::query()->where('conference_id', $field->conference_id)->max('sort')) + 1;
        });
    }

    public static function uniqueKey(int $conferenceId, string $label): string
    {
        $base = Str::of($label)->slug('_')->limit(56, '')->toString() ?: 'field';
        $key = $base;
        $n = 2;

        while (static::query()->where('conference_id', $conferenceId)->where('key', $key)->exists()) {
            $key = "{$base}_{$n}";
            $n++;
        }

        return $key;
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }
}
```

- [ ] **Step 7: Write `app/Models/ReviewForm.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReviewFormFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ReviewForm extends Model
{
    /** @use HasFactory<ReviewFormFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ReviewForm $form): void {
            $form->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }

    /** @return HasMany<ReviewQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(ReviewQuestion::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * Plan 4 sets `locked_at` when the first review on this conference is
     * submitted. Until then this is always false, and the organizer may edit,
     * reorder and delete questions freely.
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
```

- [ ] **Step 8: Write `app/Models/ReviewQuestion.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewQuestionType;
use App\Exceptions\ReviewFormLocked;
use Database\Factories\ReviewQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ReviewQuestion extends Model
{
    /** @use HasFactory<ReviewQuestionFactory> */
    use HasFactory;

    /**
     * `options` holds the choices of a Select question as
     * `list<array{label: string, score: int|float|null}>` (spec 5.6: a select
     * option carries an optional score). It is null for every other type.
     *
     * @var list<string>
     */
    protected $fillable = ['prompt', 'help_text', 'type', 'scale_min', 'scale_max', 'options', 'weight', 'required', 'sort'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ReviewQuestionType::class,
            'options' => 'array',
            'scale_min' => 'integer',
            'scale_max' => 'integer',
            // Laravel gives a decimal column NUMERIC affinity on SQLite, which
            // returns int(1) / float(0.5), while MySQL returns "1.00" / "0.50".
            // The cast makes both a two-decimal string, and Filament's numeric
            // TextInput dehydrates a float that this normalises on the way back.
            'weight' => 'decimal:2',
            'required' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ReviewQuestion $question): void {
            $question->ulid ??= (string) Str::ulid();
            // Spec section 3 says a question added after the lock is *appended*.
            // Filament sets no sort on create, so without this the new question
            // would land above question 1 in the form reviewers fill in - and on
            // a locked form reordering is disabled, so it could never be moved.
            $question->sort ??= ((int) static::query()->where('review_form_id', $question->review_form_id)->max('sort')) + 1;
        });

        // The lock lives in the model, not only in the policy, so it holds for
        // every path: panel, console command, and the Plan 6 legacy import.
        // Creating stays allowed - spec section 3 permits appending questions
        // to a locked form.
        static::updating(function (ReviewQuestion $question): void {
            if ($question->reviewForm?->isLocked()) {
                throw ReviewFormLocked::make();
            }
        });

        static::deleting(function (ReviewQuestion $question): void {
            if ($question->reviewForm?->isLocked()) {
                throw ReviewFormLocked::make();
            }
        });
    }

    /** @return BelongsTo<ReviewForm, $this> */
    public function reviewForm(): BelongsTo
    {
        return $this->belongsTo(ReviewForm::class);
    }

    /**
     * Spec 5.6: a Likert or boolean answer always scores, but a select answer
     * scores only through the optional `score` on a choice, so a select
     * question whose choices are all unscored contributes nothing. Plan 5
     * reads this when it averages a review.
     */
    public function isScored(): bool
    {
        if (! $this->type->isScored()) {
            return false;
        }

        if ($this->type !== ReviewQuestionType::Select) {
            return true;
        }

        foreach ($this->options ?? [] as $option) {
            if (is_array($option) && ($option['score'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 9: Add the relations to `app/Models/Conference.php`**

Add these imports:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
```

and these methods immediately after `organization()`:

```php
    /** @return HasMany<Track, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<CustomField, $this> */
    public function customFields(): HasMany
    {
        return $this->hasMany(CustomField::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<ReviewForm, $this> */
    public function reviewForms(): HasMany
    {
        return $this->hasMany(ReviewForm::class);
    }

    /** A conference has exactly one active review form (spec section 3). */
    /** @return HasOne<ReviewForm, $this> */
    public function reviewForm(): HasOne
    {
        return $this->hasOne(ReviewForm::class)->where('is_active', true);
    }

    /**
     * Read-only path to every question of every form on this conference. It
     * exists so Filament's relation-manager authorization can resolve the
     * related model class; writes always go through the active form.
     *
     * @return HasManyThrough<ReviewQuestion, ReviewForm, $this>
     */
    public function reviewQuestions(): HasManyThrough
    {
        return $this->hasManyThrough(ReviewQuestion::class, ReviewForm::class);
    }
```

- [ ] **Step 10: Write the four factories**

`database/factories/TrackFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conference;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Track> */
class TrackFactory extends Factory
{
    protected $model = Track::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conference_id' => Conference::factory(),
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(8),
            'sort' => 0,
        ];
    }
}
```

`database/factories/CustomFieldFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomFieldType;
use App\Models\Conference;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomField> */
class CustomFieldFactory extends Factory
{
    protected $model = CustomField::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conference_id' => Conference::factory(),
            'label' => fake()->unique()->words(3, true),
            'help_text' => null,
            'type' => CustomFieldType::Text,
            'options' => null,
            'required' => false,
            'sort' => 0,
        ];
    }

    public function select(string ...$options): static
    {
        return $this->state(fn () => [
            'type' => CustomFieldType::Select,
            'options' => $options === [] ? ['Yes', 'No'] : array_values($options),
        ]);
    }
}
```

`database/factories/ReviewFormFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conference;
use App\Models\ReviewForm;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewForm> */
class ReviewFormFactory extends Factory
{
    protected $model = ReviewForm::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conference_id' => Conference::factory(),
            'name' => 'Review form',
            'is_active' => true,
            'locked_at' => null,
        ];
    }

    /** Stands in for "Plan 4 recorded the first submitted review". */
    public function locked(): static
    {
        return $this->state(fn () => ['locked_at' => now()]);
    }
}
```

`database/factories/ReviewQuestionFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReviewQuestionType;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewQuestion> */
class ReviewQuestionFactory extends Factory
{
    protected $model = ReviewQuestion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'review_form_id' => ReviewForm::factory(),
            'prompt' => fake()->sentence(10),
            'help_text' => null,
            'type' => ReviewQuestionType::Likert,
            'scale_min' => 1,
            'scale_max' => 5,
            'options' => null,
            'weight' => '1.00',
            'required' => true,
            'sort' => 0,
        ];
    }

    public function freeText(): static
    {
        return $this->state(fn () => [
            'type' => ReviewQuestionType::Text,
            'scale_min' => null,
            'scale_max' => null,
            'required' => false,
        ]);
    }
}
```

- [ ] **Step 11: Run the test to verify it passes**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ReviewFormTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -4 /tmp/t.log
```

Expected: `rc=0`, `9 passed`. If the RESTRICT test fails on SQLite, confirm `PRAGMA foreign_keys` is on — Laravel 13 enables it for the SQLite driver by default (`config/database.php` `foreign_key_constraints`); do not weaken the constraint to make the test pass.

- [ ] **Step 12: Pint, Larastan, full suite, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?" && \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log && \
git add -A && git commit -q -m "feat: tracks, custom fields, review forms and weighted review questions

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

Expected: `stan rc=0`, `tests rc=0`, `74 passed`.

---

### Task 4: The public conference page, branded with the organization's colours

Built before the short link so that `/q/{code}` (Task 5) has a real destination.

**Files:**
- Create: `app/Support/Branding/OrganizationTheme.php`, `app/Support/Html/RichText.php`
- Create: `app/Http/Controllers/Public/ConferenceController.php`
- Create: `resources/views/components/layouts/conference.blade.php`, `resources/views/public/conference.blade.php`, `resources/views/public/partials/submit-cta.blade.php`
- Create: `resources/js/countdown.js`
- Modify: `app/Models/Organization.php` (`conferences()`), `app/Models/Conference.php` (`publicUrl()`), `routes/web.php`, `resources/js/app.js`, `resources/css/app.css`
- Test: `tests/Unit/OrganizationThemeTest.php`, `tests/Feature/Public/ConferencePageTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/OrganizationThemeTest.php`
```php
<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Support\Branding\OrganizationTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reads the organization colours and picks readable text for each', function () {
    $organization = Organization::factory()->create([
        'primary_color' => '#0F4C8A',
        'accent_color' => '#B45309',
    ]);

    $theme = OrganizationTheme::for($organization);

    expect($theme->primary)->toBe('#0F4C8A')
        ->and($theme->accent)->toBe('#B45309')
        ->and($theme->onPrimary)->toBe('#FFFFFF')
        ->and($theme->onAccent)->toBe('#FFFFFF');
});

it('switches to dark text on a light brand colour', function () {
    $organization = Organization::factory()->create([
        'primary_color' => '#BFE0F7',
        'accent_color' => '#F8FAFC',
    ]);

    $theme = OrganizationTheme::for($organization);

    expect($theme->onPrimary)->toBe('#111827')
        ->and($theme->onAccent)->toBe('#111827');
});

it('renders the four css custom properties the public layout consumes', function () {
    $theme = new OrganizationTheme('#176BB8', '#0F4C8A', '#FFFFFF', '#FFFFFF');

    expect($theme->cssVariables())->toBe(
        '--org-primary:#176BB8;--org-accent:#0F4C8A;--org-on-primary:#FFFFFF;--org-on-accent:#FFFFFF'
    );
});
```

`tests/Feature/Public/ConferencePageTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create([
        'name' => 'Gulf Pediatric Society',
        'primary_color' => '#0F4C8A',
        'accent_color' => '#B45309',
    ]);
});

function conferenceUrl(Conference $conference): string
{
    return "/c/{$conference->organization->slug}/{$conference->slug}";
}

it('shows an open conference with its call, dates, tracks, terms and branding', function () {
    $conference = Conference::factory()->for($this->organization)->published()->create([
        'name' => 'Annual Pediatric Critical Care Meeting',
        'short_description' => 'Abstracts on paediatric intensive care are invited.',
        // Everything RichText exists to remove, in one string: a script, an
        // inline style, a utility class the app itself compiles, an event
        // handler and a third-party media element. The blockquote is there to
        // prove the sanitiser keeps the parts of the editor's schema that the
        // toolbar does not show but a paste can produce.
        'description' => '<p style="position:fixed;inset:0" class="fixed inset-0">Full call for abstracts.</p>'
            .'<blockquote>Abstracts must be in English.</blockquote>'
            .'<video src="https://tracker.example/v.mp4" poster="https://tracker.example/p.gif"></video>'
            .'<img src="x" onerror="alert(2)"><script>alert(1)</script>',
        'venue' => 'King Fahad Convention Centre',
        'city' => 'Riyadh',
        'terms' => 'Presenting authors must register.',
        'word_limit' => 400,
    ]);
    Track::factory()->for($conference)->create(['name' => 'Neurocritical care']);

    get(conferenceUrl($conference))
        ->assertOk()
        ->assertSee('Annual Pediatric Critical Care Meeting')
        ->assertSee('Abstracts on paediatric intensive care are invited.')
        ->assertSee('Full call for abstracts.', escape: false)
        ->assertSee('Abstracts must be in English.')
        ->assertDontSee('alert(1)', escape: false)
        ->assertDontSee('onerror', escape: false)
        ->assertDontSee('position:fixed', escape: false)
        ->assertDontSee('class="fixed inset-0"', escape: false)
        ->assertDontSee('tracker.example', escape: false)
        ->assertSee('King Fahad Convention Centre')
        ->assertSee('Neurocritical care')
        ->assertSee('Presenting authors must register.')
        ->assertSee('400 words')
        ->assertSee('--org-primary:#0F4C8A', escape: false)
        ->assertSee('--org-on-primary:#FFFFFF', escape: false)
        ->assertSee('Gulf Pediatric Society');
});

it('renders the public page with a bounded number of queries', function () {
    // Spec section 10 gives this page a 300 ms server budget, which is why it
    // is plain Blade. Bound the query count so a lazily loaded relation in the
    // layout, or an N+1 over tracks, fails here rather than in production.
    $conference = Conference::factory()->for($this->organization)->published()->create();
    Track::factory()->count(5)->for($conference)->create();
    $url = conferenceUrl($conference);

    DB::enableQueryLog();
    get($url)->assertOk();

    // organization binding, scoped conference binding, tracks.
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(4);
});

it('shows a countdown target for an open window', function () {
    $conference = Conference::factory()->for($this->organization)->published()->create();

    get(conferenceUrl($conference))
        ->assertOk()
        ->assertSee('data-countdown', escape: false)
        ->assertSee($conference->submission_deadline->toIso8601String(), escape: false)
        ->assertSee('Submit abstract');
});

it('says when submissions open instead of offering the form', function () {
    $conference = Conference::factory()->for($this->organization)->upcomingWindow()->published()->create([
        'submission_opens_at' => now()->addWeek(),
        'submission_deadline' => now()->addMonths(2),
    ]);

    get(conferenceUrl($conference))
        ->assertOk()
        ->assertSee('Submissions open on')
        ->assertDontSee('Submit abstract');
});

it('says submissions are closed for a closed conference', function () {
    $conference = Conference::factory()->for($this->organization)->closed()->create();

    get(conferenceUrl($conference))
        ->assertOk()
        ->assertSee('Submissions are closed')
        ->assertDontSee('Submit abstract');
});

// Every negative case below pairs its 404 with a request that must succeed on
// the same URL pattern. Without that control they all pass before Step 8
// registers the route, so they could never be shown failing.

it('hides a draft conference from the public until it is published', function () {
    $conference = Conference::factory()->for($this->organization)->create();

    get(conferenceUrl($conference))->assertNotFound();

    $conference->forceFill(['status' => ConferenceStatus::Open])->save();
    get(conferenceUrl($conference))->assertOk();
});

it('hides an archived conference from the public', function () {
    $conference = Conference::factory()->for($this->organization)->published()->create();

    get(conferenceUrl($conference))->assertOk();

    $conference->forceFill(['status' => ConferenceStatus::Archived])->save();
    get(conferenceUrl($conference))->assertNotFound();
});

it('lets an organization member preview a draft with a banner', function () {
    $conference = Conference::factory()->for($this->organization)->create();
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);

    actingAs($member)->get(conferenceUrl($conference))
        ->assertOk()
        ->assertSee('Preview')
        ->assertSee('not visible to the public');
});

it('does not let a member of another organization preview a draft', function () {
    $conference = Conference::factory()->for($this->organization)->create();
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    $outsider = User::factory()->create();
    Organization::factory()->approved()->create()->addMember($outsider, OrganizationRole::Owner);

    actingAs($member)->get(conferenceUrl($conference))->assertOk();
    actingAs($outsider)->get(conferenceUrl($conference))->assertNotFound();
});

it('does not let an unverified member preview a draft', function () {
    // The organizer panel runs Filament's EnsureEmailIsVerified on every tenant
    // route (spec section 9), and RegisterOrganization logs a new owner in
    // before they verify, so this preview must apply the same rule.
    $conference = Conference::factory()->for($this->organization)->create();
    $verified = User::factory()->create();
    $unverified = User::factory()->unverified()->create();
    $this->organization->addMember($verified, OrganizationRole::Member);
    $this->organization->addMember($unverified, OrganizationRole::Member);

    actingAs($verified)->get(conferenceUrl($conference))->assertOk();
    actingAs($unverified)->get(conferenceUrl($conference))->assertNotFound();
});

it('hides published conferences of an organization that is not approved', function () {
    $pending = Organization::factory()->create();
    $conference = Conference::factory()->for($pending)->published()->create();

    get(conferenceUrl($conference))->assertNotFound();

    $pending->forceFill(['status' => App\Enums\OrganizationStatus::Approved])->save();
    get(conferenceUrl($conference))->assertOk();
});

it('takes a suspended organization conference offline but keeps the member preview', function () {
    // Owner decision: suspension takes every public conference page offline,
    // because the suspension email already promises exactly that. Members keep
    // the preview so they can see what the public no longer can.
    $suspended = Organization::factory()->suspended()->create();
    $conference = Conference::factory()->for($suspended)->published()->create();
    $member = User::factory()->create();
    $suspended->addMember($member, OrganizationRole::Member);

    get(conferenceUrl($conference))->assertNotFound();

    actingAs($member)->get(conferenceUrl($conference))
        ->assertOk()
        ->assertSee('not visible to the public');
});

it('does not leak a conference through another organization slug', function () {
    $conference = Conference::factory()->for($this->organization)->published()->create();
    $other = Organization::factory()->approved()->create();

    get(conferenceUrl($conference))->assertOk();
    get("/c/{$other->slug}/{$conference->slug}")->assertNotFound();
});
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/OrganizationThemeTest.php tests/Feature/Public/ConferencePageTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found|does not exist|Unable to find component" /tmp/t.log | head -3
```

Expected: `rc=1`, error mentions `Class "App\Support\Branding\OrganizationTheme" not found`.

- [ ] **Step 3: Write `app/Support/Branding/OrganizationTheme.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support\Branding;

use App\Models\Organization;

final readonly class OrganizationTheme
{
    public function __construct(
        public string $primary,
        public string $accent,
        public string $onPrimary,
        public string $onAccent,
    ) {}

    public static function for(Organization $organization): self
    {
        $primary = strtoupper($organization->primary_color);
        $accent = strtoupper($organization->accent_color);

        return new self(
            primary: $primary,
            accent: $accent,
            onPrimary: Contrast::textOn($primary),
            onAccent: Contrast::textOn($accent),
        );
    }

    /**
     * Emitted into a `style` attribute on the page wrapper. Tailwind 4 reads
     * these through `bg-[var(--org-primary)]` and friends, so an organization
     * can rebrand its public pages without a rebuild.
     */
    public function cssVariables(): string
    {
        return implode(';', [
            "--org-primary:{$this->primary}",
            "--org-accent:{$this->accent}",
            "--org-on-primary:{$this->onPrimary}",
            "--org-on-accent:{$this->onAccent}",
        ]);
    }
}
```

- [ ] **Step 4: Write `app/Support/Html/RichText.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support\Html;

use Illuminate\Support\HtmlString;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Filament's shared `Str::sanitizeHtml()` macro allows `style` and `class` on
 * every element, which lets an organizer paste CSS - or reuse the app's own
 * compiled utility classes - to restyle a public page. Conference descriptions
 * go through this stricter config instead.
 *
 * It starts from Symfony's W3C "safe" set rather than an explicit element
 * allowlist, because the RichEditor stores whatever its TipTap schema accepts
 * (a pasted call for abstracts can contain tables, block quotes, h1-h6,
 * sub/sup, mark and small) and any element that is neither allowed nor blocked
 * is dropped *with its text*. What it then removes from that set:
 *
 * - `class`, `id`, `name` and `style` on every element: the first two let
 *   organizer HTML spoof platform chrome with classes the app already
 *   compiles, and `id`/`name` can shadow elements the page's own scripts read.
 * - every media, canvas, button and dialog element: W3C calls them safe, but
 *   they load from any host (there is no CSP until Plan 6, so a `poster` URL
 *   would beacon every visitor's IP - which the scan counter deliberately does
 *   not store) or fake an affordance. `button`/`dialog` are *blocked*, not
 *   dropped, so their text survives without the affordance.
 */
final class RichText
{
    /** Removed with their children; none of them carries readable text. */
    private const DROPPED = ['img', 'video', 'audio', 'source', 'track', 'picture', 'canvas', 'iframe', 'object', 'embed'];

    /** Tag removed, text kept. */
    private const BLOCKED = ['button', 'dialog', 'form', 'input', 'select', 'textarea'];

    public static function sanitize(?string $html): HtmlString
    {
        if ($html === null || trim($html) === '') {
            return new HtmlString('');
        }

        return new HtmlString(self::sanitizer()->sanitize($html));
    }

    private static function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->dropAttribute('class', droppedElements: '*')
            ->dropAttribute('id', droppedElements: '*')
            ->dropAttribute('name', droppedElements: '*')
            ->dropAttribute('style', droppedElements: '*')
            ->forceAttribute('a', 'rel', 'nofollow noopener noreferrer')
            ->forceAttribute('a', 'target', '_blank')
            ->withMaxInputLength(100_000);

        foreach (self::DROPPED as $element) {
            $config = $config->dropElement($element);
        }

        foreach (self::BLOCKED as $element) {
            $config = $config->blockElement($element);
        }

        return new HtmlSanitizer($config);
    }
}
```

- [ ] **Step 5: Add `conferences()` to `app/Models/Organization.php`**

Laravel's scoped route binding resolves `{conference:slug}` through `$organization->conferences()`, so the relation name matters. Add the import `use Illuminate\Database\Eloquent\Relations\HasMany;` and this method after `owners()`:

```php
    /** @return HasMany<Conference, $this> */
    public function conferences(): HasMany
    {
        return $this->hasMany(Conference::class);
    }
```

- [ ] **Step 6: Add `publicUrl()` to `app/Models/Conference.php`**

Add after `deadlineInConferenceTimezone()`:

```php
    public function publicUrl(): string
    {
        return route('conference.show', [
            'organization' => $this->organization,
            'conference' => $this,
        ]);
    }
```

- [ ] **Step 7: Write `app/Http/Controllers/Public/ConferenceController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\User;
use App\Support\Branding\OrganizationTheme;
use Illuminate\Contracts\View\View;

class ConferenceController extends Controller
{
    public function show(Organization $organization, Conference $conference): View
    {
        $isPreview = ! $this->isPublic($organization, $conference);

        abort_if($isPreview && ! $this->canPreview($organization), 404);

        $conference->load('tracks');

        return view('public.conference', [
            'organization' => $organization,
            'conference' => $conference,
            'theme' => OrganizationTheme::for($organization),
            'isPreview' => $isPreview,
        ]);
    }

    /**
     * Draft and archived conferences are not public, and neither is anything
     * belonging to an organization the platform has not approved (or has
     * suspended).
     */
    private function isPublic(Organization $organization, Conference $conference): bool
    {
        return $organization->isApproved() && $conference->isPubliclyVisible();
    }

    /**
     * The organizer panel refuses an unverified account on every tenant route
     * (spec section 9), and registration signs a new owner in before they
     * verify, so an unpublished conference is not readable here either until
     * the address is confirmed.
     */
    private function canPreview(Organization $organization): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasVerifiedEmail()
            && $user->roleIn($organization) !== null;
    }
}
```

- [ ] **Step 8: Register the route in `routes/web.php`**

Add the imports `use App\Http\Controllers\Public\ConferenceController;` and `use Illuminate\Session\Middleware\AuthenticateSession;`, then this at the end:

```php
// The model's route key is the ULID (panel URLs use it), so the slug is asked
// for explicitly here. Scoped binding resolves {conference:slug} through
// $organization->conferences(), which is what makes a slug that is unique only
// per organization safe in a URL. Plan 3 registers
// /c/{organization}/{conference:slug}/submit with the same binding field.
//
// AuthenticateSession is on this public route because of the member preview of
// an unpublished conference: a session stolen before a password change must
// not keep reading drafts. It is a no-op for guests.
Route::get('/c/{organization}/{conference:slug}', [ConferenceController::class, 'show'])
    ->scopeBindings()
    ->middleware(AuthenticateSession::class)
    ->name('conference.show');
```

`Conference::publicUrl()` needs no change: Laravel's URL generator emits the value of the binding field, so passing the model still produces the slug.

- [ ] **Step 9: Write `resources/views/components/layouts/conference.blade.php`**

```blade
@props(['organization', 'conference', 'theme'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $conference->name }} · {{ $organization->name }}</title>
    <meta name="description" content="{{ Str::limit((string) $conference->short_description, 155) }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex flex-col" style="{{ $theme->cssVariables() }}">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4">
            <div class="flex items-center gap-3">
                @if ($organization->logo_path)
                    <img src="{{ Storage::disk('branding')->url($organization->logo_path) }}"
                         alt="{{ $organization->name }}" class="h-10 w-auto">
                @endif
                <span class="text-lg font-semibold tracking-tight">{{ $organization->name }}</span>
            </div>
            @if ($organization->contact_email)
                <a href="mailto:{{ $organization->contact_email }}"
                   class="text-sm font-medium text-[var(--org-primary)] hover:underline">Contact the organizers</a>
            @endif
        </div>
    </header>

    <main class="flex-1">
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl flex-col gap-2 px-4 py-6 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            <p>{{ $organization->name }} · powered by <a href="{{ route('landing') }}" class="hover:underline">CASS</a></p>
            <p class="flex gap-4">
                <a href="{{ route('privacy') }}" class="hover:underline">Privacy</a>
                <a href="{{ route('terms') }}" class="hover:underline">Terms</a>
            </p>
        </div>
    </footer>
</body>
</html>
```

- [ ] **Step 10: Write `resources/views/public/partials/submit-cta.blade.php`**

```blade
{{--
    The four submission-window states. Plan 3 replaces exactly one thing in
    this file: the `href="#"` on the Open branch becomes
    route('conference.submit', [$conference->organization, $conference]).
    Nothing else here changes.
--}}
@php use App\Enums\SubmissionWindow; @endphp

<div class="mt-8">
    @switch($conference->submissionWindow())
        @case(SubmissionWindow::Open)
            <a href="#"
               class="inline-flex items-center rounded-lg bg-[var(--org-primary)] px-6 py-3 text-base font-semibold text-[var(--org-on-primary)] shadow-sm hover:opacity-90">
                Submit abstract
            </a>
            <p class="mt-3 text-sm text-slate-600">
                Deadline
                <time datetime="{{ $conference->submission_deadline->toIso8601String() }}"
                      data-countdown
                      data-deadline="{{ $conference->submission_deadline->toIso8601String() }}">
                    {{ $conference->deadlineInConferenceTimezone()?->format('j F Y, H:i') }} ({{ $conference->timezone }})
                </time>
            </p>
            @break

        @case(SubmissionWindow::Upcoming)
            <p class="inline-flex items-center rounded-lg border border-slate-300 px-6 py-3 text-base font-semibold text-slate-600">
                Submissions open on {{ $conference->opensAtInConferenceTimezone()?->format('j F Y, H:i') }} ({{ $conference->timezone }})
            </p>
            @break

        @case(SubmissionWindow::Closed)
            <p class="inline-flex items-center rounded-lg border border-slate-300 px-6 py-3 text-base font-semibold text-slate-600">
                Submissions are closed
            </p>
            @break

        @default
            <p class="inline-flex items-center rounded-lg border border-slate-300 px-6 py-3 text-base font-semibold text-slate-600">
                Submission dates have not been announced yet
            </p>
    @endswitch
</div>
```

- [ ] **Step 11: Write `resources/views/public/conference.blade.php`**

```blade
@php use App\Support\Html\RichText; @endphp

<x-layouts.conference :organization="$organization" :conference="$conference" :theme="$theme">
    @if ($isPreview)
        <div class="bg-amber-50 text-amber-900">
            <div class="mx-auto max-w-5xl px-4 py-3 text-sm">
                <span class="font-semibold">Preview.</span>
                This conference is {{ $conference->status->getLabel() }} and is not visible to the public. Only members of {{ $organization->name }} can see this page.
            </div>
        </div>
    @endif

    <section class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-5xl px-4 py-12">
            <p class="text-sm font-semibold uppercase tracking-wide text-[var(--org-accent)]">Call for abstracts</p>
            <h1 class="mt-2 text-4xl font-semibold tracking-tight">{{ $conference->name }}</h1>

            @if ($conference->short_description)
                <p class="mt-4 max-w-3xl text-lg text-slate-600">{{ $conference->short_description }}</p>
            @endif

            <dl class="mt-8 grid gap-6 sm:grid-cols-3">
                @if ($conference->starts_at)
                    <div>
                        <dt class="text-sm font-medium text-slate-500">Conference dates</dt>
                        <dd class="mt-1 font-medium">
                            {{ $conference->starts_at->format('j M Y') }}@if ($conference->ends_at && ! $conference->ends_at->isSameDay($conference->starts_at)) – {{ $conference->ends_at->format('j M Y') }}@endif
                        </dd>
                    </div>
                @endif
                @if ($conference->venue || $conference->city)
                    <div>
                        <dt class="text-sm font-medium text-slate-500">Venue</dt>
                        <dd class="mt-1 font-medium">{{ collect([$conference->venue, $conference->city])->filter()->implode(', ') }}</dd>
                    </div>
                @endif
                @if ($conference->submission_deadline)
                    <div>
                        <dt class="text-sm font-medium text-slate-500">Submission deadline</dt>
                        <dd class="mt-1 font-medium">{{ $conference->deadlineInConferenceTimezone()?->format('j M Y, H:i') }} ({{ $conference->timezone }})</dd>
                    </div>
                @endif
            </dl>

            @include('public.partials.submit-cta', ['conference' => $conference])
        </div>
    </section>

    <div class="mx-auto grid max-w-5xl gap-10 px-4 py-12 md:grid-cols-3">
        <div class="md:col-span-2">
            @if ($conference->description)
                <div class="prose prose-slate max-w-none">
                    {!! RichText::sanitize($conference->description) !!}
                </div>
            @endif

            @if ($conference->terms)
                <section class="mt-10">
                    <h2 class="text-xl font-semibold tracking-tight">Terms</h2>
                    <p class="mt-3 whitespace-pre-line text-slate-600">{{ $conference->terms }}</p>
                </section>
            @endif
        </div>

        <aside class="space-y-8">
            @if ($conference->tracks->isNotEmpty())
                <section>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Tracks</h2>
                    <ul class="mt-3 space-y-2">
                        @foreach ($conference->tracks as $track)
                            <li class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                                <p class="font-medium">{{ $track->name }}</p>
                                @if ($track->description)
                                    <p class="mt-1 text-sm text-slate-600">{{ $track->description }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">What to prepare</h2>
                <ul class="mt-3 space-y-2 text-sm text-slate-600">
                    <li>Abstract of up to <span class="font-medium text-slate-900">{{ $conference->word_limit }} words</span>.</li>
                    <li>Up to <span class="font-medium text-slate-900">{{ $conference->max_files }}</span> file(s) ({{ Str::upper(implode(', ', $conference->allowed_file_types)) }}).</li>
                    <li>Presentation preference: {{ implode(', ', $conference->presentation_types) }}.</li>
                </ul>
            </section>
        </aside>
    </div>
</x-layouts.conference>
```

- [ ] **Step 12: Write `resources/js/countdown.js` and import it**

`resources/js/countdown.js`
```js
// Progressive enhancement: the server already prints the deadline in the
// conference timezone, this only appends a live "x days, y hours" counter.
function remaining(deadline) {
    const ms = deadline.getTime() - Date.now();

    if (ms <= 0) {
        return null;
    }

    const minutes = Math.floor(ms / 60000);
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);

    if (days > 0) {
        return `${days} day${days === 1 ? '' : 's'}, ${hours} hour${hours === 1 ? '' : 's'} left`;
    }

    return `${hours} hour${hours === 1 ? '' : 's'}, ${minutes % 60} minute${minutes % 60 === 1 ? '' : 's'} left`;
}

function start(element) {
    const deadline = new Date(element.dataset.deadline);

    if (Number.isNaN(deadline.getTime())) {
        return;
    }

    const badge = document.createElement('span');
    badge.className = 'ml-2 font-medium text-[var(--org-accent)]';
    element.after(badge);

    const tick = () => {
        const text = remaining(deadline);

        if (text === null) {
            badge.remove();
            window.clearInterval(timer);

            return;
        }

        badge.textContent = `· ${text}`;
    };

    tick();
    const timer = window.setInterval(tick, 60000);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-countdown]').forEach(start);
});
```

`resources/js/app.js` becomes:
```js
import './bootstrap';
import './countdown';
```

- [ ] **Step 13: Let Tailwind see the new colours**

`resources/css/app.css` already has `@source '../views';`, which covers the new Blade files. Add the organization variables next to the existing `:root` block so the page still renders sensibly if the inline `style` attribute is stripped by a future CSP:

```css
:root {
    --org-primary: var(--color-brand-500);
    --org-accent: var(--color-brand-700);
    --org-on-primary: #ffffff;
    --org-on-accent: #ffffff;
}
```

(Replace the existing two-line `:root` block with this four-line one.)

- [ ] **Step 14: Build assets and run the tests**

```bash
cd /c/Users/ahmed/Documents/CASS && npm run build 2>&1 | tail -3 && \
php artisan test tests/Unit/OrganizationThemeTest.php tests/Feature/Public/ConferencePageTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -4 /tmp/t.log
```

Expected: Vite prints `✓ built in …`, then `rc=0`, `16 passed`.

- [ ] **Step 15: Pint, Larastan, full suite, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?" && \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log && \
git add -A && git commit -q -m "feat: public conference page with organization branding and window states

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

Expected: `stan rc=0`, `tests rc=0`, `90 passed`.

---

### Task 5: Short links, the `/q/{code}` redirect and scan counting

**Files:**
- Create: `app/Support/ShortCode.php`
- Create: `database/migrations/2026_09_11_000600_create_short_links_table.php`, `..._000700_create_short_link_visits_table.php`
- Create: `app/Models/ShortLink.php`, `app/Models/ShortLinkVisit.php`, `database/factories/ShortLinkFactory.php`
- Create: `app/Actions/ShortLinks/RecordShortLinkVisit.php`, `app/Http/Controllers/Public/ShortLinkController.php`
- Modify: `app/Models/Conference.php` (`shortLink()`), `routes/web.php`
- Test: `tests/Unit/ShortCodeTest.php`, `tests/Feature/Public/ShortLinkTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ShortCodeTest.php`
```php
<?php

declare(strict_types=1);

use App\Models\Conference;
use App\Models\ShortLink;
use App\Support\ShortCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates eight characters from an unambiguous uppercase alphabet', function () {
    foreach (range(1, 200) as $ignored) {
        $code = ShortCode::generate();

        expect($code)->toHaveLength(8)
            ->and($code)->toMatch('/^['.ShortCode::ALPHABET.']{8}$/');
    }
});

it('excludes every character a person could misread', function () {
    expect(ShortCode::ALPHABET)->not->toContain('0')
        ->and(ShortCode::ALPHABET)->not->toContain('1')
        ->and(ShortCode::ALPHABET)->not->toContain('I')
        ->and(ShortCode::ALPHABET)->not->toContain('L')
        ->and(ShortCode::ALPHABET)->not->toContain('O')
        ->and(ShortCode::ALPHABET)->not->toContain('U')
        ->and(strlen(ShortCode::ALPHABET))->toBe(30);
});

it('does not repeat itself over a large sample', function () {
    $codes = collect(range(1, 500))->map(fn (): string => ShortCode::generate());

    expect($codes->unique())->toHaveCount(500);
});

it('creates one short link per conference and reuses it', function () {
    $conference = Conference::factory()->create();

    $first = ShortLink::forTarget($conference);
    $second = ShortLink::forTarget($conference);

    expect($first->is($second))->toBeTrue()
        ->and(ShortLink::count())->toBe(1)
        ->and($first->target->is($conference))->toBeTrue()
        ->and($conference->fresh()?->shortLink?->is($first))->toBeTrue()
        ->and($first->url())->toBe(url('/q/'.$first->code));
});

it('retries until the generated code is free', function () {
    // 30^8 is about 6.6e11 codes, so drawing a taken one at random never
    // happens: without a seam this test would pass even if forTarget() had no
    // uniqueness loop at all.
    ShortLink::factory()->create(['code' => 'ABCDEFGH']);
    $codes = ['ABCDEFGH', 'ABCDEFGH', 'ZYXWVTS2'];
    ShortLink::generateCodesUsing(function () use (&$codes): string {
        return (string) array_shift($codes);
    });

    try {
        expect(ShortLink::forTarget(Conference::factory()->create())->code)->toBe('ZYXWVTS2')
            ->and(ShortLink::count())->toBe(2);
    } finally {
        ShortLink::generateCodesUsing(null);
    }
});

it('zero fills the daily visit counts for the last n days', function () {
    // Freeze the clock: the test writes visits with now(), the model reads
    // CarbonImmutable::now(), and the assertions build their keys with now()
    // again - a run that crossed midnight would read an empty day.
    $this->travelTo(now()->setTime(12, 0));

    $link = ShortLink::factory()->create();
    $link->visits()->create(['visited_at' => now()]);
    $link->visits()->create(['visited_at' => now()]);
    $link->visits()->create(['visited_at' => now()->subDays(2)]);
    $link->visits()->create(['visited_at' => now()->subDays(60)]);

    $counts = $link->dailyVisitCounts(30);

    expect($counts)->toHaveCount(30)
        ->and($counts[now()->toDateString()])->toBe(2)
        ->and($counts[now()->subDays(2)->toDateString()])->toBe(1)
        ->and($counts[now()->subDay()->toDateString()])->toBe(0)
        ->and(array_sum($counts))->toBe(3);
});

it('buckets the daily counts by the day in the requested timezone', function () {
    // 22:30 UTC is already the next day in Riyadh (+03), which is the timezone
    // the sharing page labels the chart with.
    $this->travelTo(CarbonImmutable::parse('2026-11-30 22:30:00', 'UTC'));

    $link = ShortLink::factory()->create();
    $link->visits()->create(['visited_at' => now()]);

    expect($link->dailyVisitCounts(30)['2026-11-30'])->toBe(1)
        ->and($link->dailyVisitCounts(30, 'Asia/Riyadh')['2026-12-01'])->toBe(1);
});
```

`tests/Feature/Public/ShortLinkTest.php`
```php
<?php

declare(strict_types=1);

use App\Models\Conference;
use App\Models\Organization;
use App\Models\ShortLink;
use App\Models\ShortLinkVisit;

use function Pest\Laravel\get;

it('redirects to the conference page and counts the scan', function () {
    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->published()->create();
    $link = ShortLink::forTarget($conference);

    get('/q/'.$link->code)
        ->assertRedirect($conference->publicUrl());

    $link->refresh();
    expect($link->clicks)->toBe(1)
        ->and(ShortLinkVisit::count())->toBe(1)
        ->and(ShortLinkVisit::first()?->visited_at)->not->toBeNull();
});

it('records nothing beyond a timestamp', function () {
    $conference = Conference::factory()->for(Organization::factory()->approved())->published()->create();
    $link = ShortLink::forTarget($conference);

    get('/q/'.$link->code);

    $columns = array_keys(ShortLinkVisit::first()?->getAttributes() ?? []);
    sort($columns);

    expect($columns)->toBe(['id', 'short_link_id', 'visited_at']);
});

it('accepts a lower case code from a hand-typed url', function () {
    $conference = Conference::factory()->for(Organization::factory()->approved())->published()->create();
    $link = ShortLink::forTarget($conference);

    get('/q/'.strtolower($link->code))->assertRedirect($conference->publicUrl());
});

it('returns 404 for an unknown code without creating a visit', function () {
    get('/q/ZZZZZZZZ')->assertNotFound();

    expect(ShortLinkVisit::count())->toBe(0);
});

it('does not redirect to or count a conference the public cannot see', function (Conference $conference) {
    $link = ShortLink::forTarget($conference);

    get('/q/'.$link->code)->assertNotFound();

    expect($link->fresh()?->clicks)->toBe(0)
        ->and(ShortLinkVisit::count())->toBe(0);
})->with([
    // One row per branch of the controller's guard. Without the archived and
    // organization-status rows, dropping the isApproved() clause would keep
    // counting scans for a page that is 404 and nothing would fail.
    'draft' => fn () => Conference::factory()->for(Organization::factory()->approved())->create(),
    'archived' => fn () => Conference::factory()->for(Organization::factory()->approved())->archived()->create(),
    'pending organization' => fn () => Conference::factory()->for(Organization::factory())->published()->create(),
    'suspended organization' => fn () => Conference::factory()->for(Organization::factory()->suspended())->published()->create(),
]);

it('does not redirect to a soft-deleted conference', function () {
    $conference = Conference::factory()->for(Organization::factory()->approved())->published()->create();
    $link = ShortLink::forTarget($conference);
    $conference->delete();

    get('/q/'.$link->code)->assertNotFound();

    expect($link->fresh()?->clicks)->toBe(0)
        ->and(ShortLinkVisit::count())->toBe(0);
});

it('caps counting per client ip but never refuses the redirect', function () {
    // Spec 5.7: every scan redirects and every visit is counted. A poster in a
    // lecture hall is scanned by a hundred people behind one NAT address, so
    // the cap may only stop *counting*, never answer 429 to a real visitor.
    config(['cass.short_link_rate_limit' => 2]);

    $conference = Conference::factory()->for(Organization::factory()->approved())->published()->create();
    $link = ShortLink::forTarget($conference);

    foreach (range(1, 3) as $ignored) {
        get('/q/'.$link->code, ['CF-Connecting-IP' => '203.0.113.10'])
            ->assertRedirect($conference->publicUrl());
    }

    // A different client has its own budget, so one scanner cannot spend the
    // whole venue's.
    get('/q/'.$link->code, ['CF-Connecting-IP' => '198.51.100.20'])
        ->assertRedirect($conference->publicUrl());

    expect($link->fresh()?->clicks)->toBe(3)
        ->and(ShortLinkVisit::count())->toBe(3);
});
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ShortCodeTest.php tests/Feature/Public/ShortLinkTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found|does not exist|Unable to find component" /tmp/t.log | head -3
```

Expected: `rc=1`, error mentions `Class "App\Support\ShortCode" not found`.

- [ ] **Step 3: Write `app/Support/ShortCode.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Random\RandomException;

final class ShortCode
{
    /**
     * Crockford-style: no 0/O, no 1/I/L, no U. Codes are printed on posters
     * and read aloud, so every remaining character survives a bad photocopy.
     */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LENGTH = 8;

    /**
     * 30^8 is about 6.6e11 codes, so a collision needs a retry roughly never;
     * ShortLink::forTarget() still loops until the insert is unique.
     *
     * @throws RandomException
     */
    public static function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    public static function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }
}
```

- [ ] **Step 4: Write the two migrations**

`database/migrations/2026_09_11_000600_create_short_links_table.php`
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
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            // Polymorphic from day one so v2 can point a short link at a single
            // abstract (spec 5.7) without a migration.
            $table->morphs('target');
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_links');
    }
};
```

`database/migrations/2026_09_11_000700_create_short_link_visits_table.php`
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
        // A timestamp and nothing else: no IP, no user agent, no referrer.
        // Spec 5.7 asks for a scan count, not analytics, and this table is
        // therefore outside the scope of any personal-data request.
        Schema::create('short_link_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('short_link_id')->constrained()->cascadeOnDelete();
            $table->timestamp('visited_at');

            $table->index(['short_link_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_link_visits');
    }
};
```

- [ ] **Step 5: Write `app/Models/ShortLink.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ShortCode;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\ShortLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ShortLink extends Model
{
    /** @use HasFactory<ShortLinkFactory> */
    use HasFactory;

    /**
     * Codes, counters and the target are never mass assigned: a printed code
     * must not be repointable, and the scan count must not be settable, by a
     * future `ShortLink::create($validated)`. Everything here is written by
     * property assignment in forTarget() or by increment().
     */
    protected $guarded = ['*'];

    /**
     * Test seam for the uniqueness retry in forTarget(). `self::` and not
     * `static::`: Larastan reports "unsafe access to private property through
     * static::" on a non-final class.
     *
     * @var (Closure(): string)|null
     */
    private static ?Closure $codeGenerator = null;

    /** Replaces ShortCode::generate() until it is reset with null. */
    public static function generateCodesUsing(?Closure $generator): void
    {
        self::$codeGenerator = $generator;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['clicks' => 'integer'];
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<ShortLinkVisit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(ShortLinkVisit::class);
    }

    /**
     * Idempotent: publishing a conference twice reuses the printed code so a
     * poster already in circulation keeps working.
     */
    public static function forTarget(Model $target): self
    {
        $existing = static::query()
            ->where('target_type', $target->getMorphClass())
            ->where('target_id', $target->getKey())
            ->first();

        if ($existing instanceof self) {
            return $existing;
        }

        do {
            $code = self::$codeGenerator !== null ? (self::$codeGenerator)() : ShortCode::generate();
        } while (static::query()->where('code', $code)->exists());

        $link = new self;
        $link->code = $code;
        $link->target()->associate($target);
        $link->save();

        return $link;
    }

    public function url(): string
    {
        return route('shortlink.show', ['code' => $this->code]);
    }

    /**
     * Zero-filled daily counts, newest day last, keyed by Y-m-d. Grouping in
     * PHP rather than SQL keeps this identical on SQLite and MySQL; a
     * conference poster produces thousands of rows, not millions.
     *
     * Timestamps are stored UTC (spec section 10), so the caller passes the
     * timezone the days should be cut in - the sharing page passes the
     * conference's own, otherwise a scan at 01:00 in Riyadh would be counted
     * on the previous day next to a deadline printed in local time.
     *
     * @return array<string, int>
     */
    public function dailyVisitCounts(int $days = 30, string $timezone = 'UTC'): array
    {
        $start = CarbonImmutable::now($timezone)->subDays($days - 1)->startOfDay();

        $counts = [];
        for ($i = 0; $i < $days; $i++) {
            $counts[$start->addDays($i)->toDateString()] = 0;
        }

        $this->visits()
            // The query builder formats a Carbon binding without converting it,
            // so the local start of day has to be sent as UTC explicitly.
            ->where('visited_at', '>=', $start->utc())
            ->get(['visited_at'])
            ->each(function (ShortLinkVisit $visit) use (&$counts, $timezone): void {
                $day = $visit->visited_at->setTimezone($timezone)->toDateString();

                if (array_key_exists($day, $counts)) {
                    $counts[$day]++;
                }
            });

        return $counts;
    }
}
```

- [ ] **Step 6: Write `app/Models/ShortLinkVisit.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShortLinkVisit extends Model
{
    /** There is no created_at/updated_at pair: `visited_at` is the whole row. */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['visited_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['visited_at' => 'datetime'];
    }

    /** @return BelongsTo<ShortLink, $this> */
    public function shortLink(): BelongsTo
    {
        return $this->belongsTo(ShortLink::class);
    }
}
```

- [ ] **Step 7: Write `database/factories/ShortLinkFactory.php`**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conference;
use App\Models\ShortLink;
use App\Support\ShortCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShortLink> */
class ShortLinkFactory extends Factory
{
    protected $model = ShortLink::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => ShortCode::generate(),
            'target_type' => (new Conference)->getMorphClass(),
            'target_id' => Conference::factory(),
            'clicks' => 0,
        ];
    }
}
```

- [ ] **Step 8: Add `shortLink()` to `app/Models/Conference.php`**

Add the import `use Illuminate\Database\Eloquent\Relations\MorphOne;` and this method after `reviewQuestions()`:

```php
    /** @return MorphOne<ShortLink, $this> */
    public function shortLink(): MorphOne
    {
        return $this->morphOne(ShortLink::class, 'target');
    }
```

- [ ] **Step 9: Write `app/Actions/ShortLinks/RecordShortLinkVisit.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\ShortLinks;

use App\Models\ShortLink;
use Illuminate\Support\Facades\RateLimiter;

class RecordShortLinkVisit
{
    /**
     * One counter bump plus one timestamp row. The counter is what the panel
     * shows at a glance; the rows are what the 30-day breakdown reads.
     *
     * The cap lives here, not on the route: spec 5.7 says /q/{code} redirects
     * and counts every visit, so a route-level throttle would answer 429 to
     * the 61st person scanning a poster in a lecture hall behind one NAT
     * address - exactly the moment the QR code exists for. Capping the count
     * still stops a script inflating the total, and the visitor always reaches
     * the page.
     */
    public function handle(ShortLink $shortLink, string $clientIp): void
    {
        $key = 'short-link-count:'.$shortLink->id.':'.$clientIp;

        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('cass.short_link_rate_limit')))) {
            return;
        }

        RateLimiter::hit($key, 60);

        $shortLink->increment('clicks');
        $shortLink->visits()->create(['visited_at' => now()]);
    }
}
```

- [ ] **Step 10: Write `app/Http/Controllers/Public/ShortLinkController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\ShortLinks\RecordShortLinkVisit;
use App\Http\Controllers\Controller;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\ShortLink;
use App\Support\ClientIp;
use App\Support\ShortCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShortLinkController extends Controller
{
    public function __invoke(Request $request, string $code, RecordShortLinkVisit $recordVisit): RedirectResponse
    {
        $shortLink = ShortLink::query()
            ->where('code', ShortCode::normalise($code))
            ->with('target')
            ->first();

        abort_if($shortLink === null, 404);

        $target = $shortLink->target;

        // Only conferences carry short links in v1. A draft, archived or
        // unapproved-organization conference behaves exactly like an unknown
        // code: no redirect, and no scan recorded.
        abort_unless($target instanceof Conference, 404);
        abort_unless($target->isPubliclyVisible() && $target->organization instanceof Organization && $target->organization->isApproved(), 404);

        $recordVisit->handle($shortLink, ClientIp::from($request));

        return redirect()->to($target->publicUrl(), 302);
    }
}
```

- [ ] **Step 11: Register the route**

In `routes/web.php`, add the import `use App\Http\Controllers\Public\ShortLinkController;` and this route:

```php
// No throttle middleware: the cap lives in RecordShortLinkVisit and limits
// counting only, because spec 5.7 requires the redirect itself to always work
// (a hall full of people scanning one poster shares a single NAT address).
Route::get('/q/{code}', ShortLinkController::class)
    ->where('code', '[A-Za-z0-9]{8}')
    ->name('shortlink.show');
```

- [ ] **Step 12: Run the tests to verify they pass**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ShortCodeTest.php tests/Feature/Public/ShortLinkTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -4 /tmp/t.log
```

Expected: `rc=0`, `17 passed` (the "cannot see" case is a four-row dataset, so it reports as four tests).

- [ ] **Step 13: Pint, Larastan, full suite, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?" && \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log && \
git add -A && git commit -q -m "feat: short links with an unambiguous 8-character code and counted /q redirect

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

Expected: `stan rc=0`, `tests rc=0`, `107 passed`.

---

### Task 6: Conference actions — default review form, publish gate, close, archive

**Files:**
- Create: `app/Exceptions/ConferenceNotPublishable.php`
- Create: `app/Actions/Conferences/CreateDefaultReviewForm.php`, `CreateConference.php`, `PublishConference.php`, `CloseSubmissions.php`, `ArchiveConference.php`
- Test: `tests/Unit/PublishConferenceTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/PublishConferenceTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Conferences\ArchiveConference;
use App\Actions\Conferences\CloseSubmissions;
use App\Actions\Conferences\CreateConference;
use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Actions\Conferences\PublishConference;
use App\Enums\ConferenceStatus;
use App\Enums\ReviewQuestionType;
use App\Exceptions\ConferenceNotPublishable;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\ShortLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function publishableConference(): Conference
{
    $conference = Conference::factory()
        ->for(Organization::factory()->approved())
        ->withSubmissionWindow()
        ->create();

    app(CreateDefaultReviewForm::class)->handle($conference);

    return $conference->fresh() ?? $conference;
}

it('creates the nine legacy questions as a likert 1 to 5 template', function () {
    $conference = Conference::factory()->create();

    $form = app(CreateDefaultReviewForm::class)->handle($conference);

    expect($form->questions()->count())->toBe(9)
        ->and($form->is_active)->toBeTrue()
        ->and($form->isLocked())->toBeFalse();

    $questions = $form->questions()->get();

    expect($questions->pluck('prompt')->first())
        ->toBe('Originality and Innovation: How original and innovative is the research presented in the abstract?')
        ->and($questions->pluck('prompt')->last())
        ->toBe('Do you recommend this abstract for oral presentation? (1 = do not recommend, 5 = strongly recommend)')
        ->and($questions->pluck('sort')->all())->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9]);

    $questions->each(function ($question): void {
        expect($question->type)->toBe(ReviewQuestionType::Likert)
            ->and($question->scale_min)->toBe(1)
            ->and($question->scale_max)->toBe(5)
            ->and($question->weight)->toBe('1.00')
            ->and($question->required)->toBeTrue();
    });
});

it('is idempotent so a second call does not duplicate the template', function () {
    $conference = Conference::factory()->create();

    $first = app(CreateDefaultReviewForm::class)->handle($conference);
    $second = app(CreateDefaultReviewForm::class)->handle($conference);

    expect($second->is($first))->toBeTrue()
        ->and($first->questions()->count())->toBe(9);
});

it('creates a conference under the tenant with a derived slug and a review form', function () {
    $organization = Organization::factory()->approved()->create();

    $conference = app(CreateConference::class)->handle($organization, [
        'name' => 'Winter Pediatric Symposium',
        'timezone' => 'Asia/Riyadh',
        'word_limit' => 350,
        'max_files' => 2,
        'allowed_file_types' => ['pdf'],
        'presentation_types' => ['oral', 'poster'],
    ]);

    expect($conference->organization->is($organization))->toBeTrue()
        ->and($conference->slug)->toBe('winter-pediatric-symposium')
        ->and($conference->status)->toBe(ConferenceStatus::Draft)
        ->and($conference->word_limit)->toBe(350)
        ->and($conference->reviewForm?->questions()->count())->toBe(9);
});

it('reports no blockers for a conference that satisfies the gate', function () {
    expect(app(PublishConference::class)->blockers(publishableConference()))->toBe([]);
});

it('blocks publishing until the organization is approved', function () {
    $conference = Conference::factory()->for(Organization::factory())->withSubmissionWindow()->create();
    app(CreateDefaultReviewForm::class)->handle($conference);

    expect(app(PublishConference::class)->blockers($conference->fresh() ?? $conference))
        ->toContain('Your organization is still waiting for platform approval. You can publish as soon as it is approved.');
});

it('blocks publishing for a suspended organization', function () {
    $conference = Conference::factory()->for(Organization::factory()->suspended())->withSubmissionWindow()->create();
    app(CreateDefaultReviewForm::class)->handle($conference);

    expect(app(PublishConference::class)->blockers($conference->fresh() ?? $conference))
        ->toContain('This organization is suspended, so its conferences cannot be published.');
});

it('blocks publishing without a submission window', function () {
    $conference = Conference::factory()->for(Organization::factory()->approved())->create();
    app(CreateDefaultReviewForm::class)->handle($conference);

    expect(app(PublishConference::class)->blockers($conference->fresh() ?? $conference))
        ->toContain('Set both a submission opening date and a submission deadline.');
});

it('blocks publishing when the deadline has passed or precedes the opening date', function () {
    $conference = publishableConference();

    $conference->forceFill(['submission_deadline' => now()->subDay()])->save();
    expect(app(PublishConference::class)->blockers($conference->fresh() ?? $conference))
        ->toContain('The submission deadline is in the past. Choose a future date and time.');

    $conference->forceFill([
        'submission_opens_at' => now()->addMonths(2),
        'submission_deadline' => now()->addMonth(),
    ])->save();
    expect(app(PublishConference::class)->blockers($conference->fresh() ?? $conference))
        ->toContain('The submission deadline must come after the submission opening date.');
});

it('blocks publishing without an active review form that has questions', function () {
    $conference = Conference::factory()->for(Organization::factory()->approved())->withSubmissionWindow()->create();

    expect(app(PublishConference::class)->blockers($conference))
        ->toContain('The review form has no questions yet. Add at least one before publishing.');

    $form = app(CreateDefaultReviewForm::class)->handle($conference);
    $form->questions()->delete();

    expect(app(PublishConference::class)->blockers($conference->fresh() ?? $conference))
        ->toContain('The review form has no questions yet. Add at least one before publishing.');
});

it('blocks publishing an archived conference', function () {
    $conference = publishableConference();
    $conference->forceFill(['status' => ConferenceStatus::Archived])->save();

    expect(app(PublishConference::class)->blockers($conference->fresh() ?? $conference))
        ->toContain('An archived conference cannot be published again.');
});

it('publishes, stamps the time, creates the short link and logs the change', function () {
    $conference = publishableConference();
    $actor = User::factory()->create();
    $conference->organization->addMember($actor, App\Enums\OrganizationRole::Owner);

    $published = app(PublishConference::class)->handle($conference, $actor);

    expect($published->status)->toBe(ConferenceStatus::Open)
        ->and($published->published_at)->not->toBeNull()
        ->and($published->shortLink)->not->toBeNull()
        ->and(strlen((string) $published->shortLink?->code))->toBe(8)
        ->and(Activity::query()->where('description', 'conference.published')->count())->toBe(1);
});

it('reuses the printed short code when a conference is republished', function () {
    $conference = publishableConference();
    $actor = User::factory()->create();

    $code = app(PublishConference::class)->handle($conference, $actor)->shortLink?->code;
    app(CloseSubmissions::class)->handle($conference->fresh() ?? $conference, $actor);
    $republished = app(PublishConference::class)->handle($conference->fresh() ?? $conference, $actor);

    expect($republished->shortLink?->code)->toBe($code)
        ->and(ShortLink::count())->toBe(1);
});

it('refuses to publish when the gate is not satisfied', function () {
    $conference = Conference::factory()->for(Organization::factory())->create();
    $actor = User::factory()->create();

    expect(fn () => app(PublishConference::class)->handle($conference, $actor))
        ->toThrow(ConferenceNotPublishable::class);

    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Draft);
});

it('closes submissions on an open conference and refuses otherwise', function () {
    $conference = publishableConference();
    $actor = User::factory()->create();
    app(PublishConference::class)->handle($conference, $actor);

    $closed = app(CloseSubmissions::class)->handle($conference->fresh() ?? $conference, $actor);
    expect($closed->status)->toBe(ConferenceStatus::Closed)
        ->and(Activity::query()->where('description', 'conference.closed')->count())->toBe(1);

    expect(fn () => app(CloseSubmissions::class)->handle($closed, $actor))
        ->toThrow(InvalidArgumentException::class);
});

it('archives any live conference and refuses to archive twice', function () {
    $conference = publishableConference();
    $actor = User::factory()->create();

    $archived = app(ArchiveConference::class)->handle($conference, $actor);
    expect($archived->status)->toBe(ConferenceStatus::Archived)
        ->and(Activity::query()->where('description', 'conference.archived')->count())->toBe(1);

    expect(fn () => app(ArchiveConference::class)->handle($archived, $actor))
        ->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/PublishConferenceTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found|does not exist|Unable to find component" /tmp/t.log | head -3
```

Expected: `rc=1`. The first test resolves the action out of the container, so the message is `Target class [App\Actions\Conferences\CreateDefaultReviewForm] does not exist.` — note it contains neither "Error" nor "not found", which is why the grep above is wider than the obvious one.

- [ ] **Step 3: Write `app/Exceptions/ConferenceNotPublishable.php`**

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ConferenceNotPublishable extends RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('This conference cannot be published yet: '.implode(' ', $reasons));
    }
}
```

- [ ] **Step 4: Write `app/Actions/Conferences/CreateDefaultReviewForm.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\ReviewQuestionType;
use App\Models\Conference;
use App\Models\ReviewForm;
use Illuminate\Support\Facades\DB;

class CreateDefaultReviewForm
{
    /**
     * The eight scoring questions from the legacy CASS review form verbatim,
     * plus the legacy recommendation question reworded for clarity (the
     * original reads "Do you recommend this abstract for oral presentation (1
     * is dont recommend & 5 Strongly recommend)"), all Likert 1-5 with equal
     * weight. Organizers edit, reorder and delete them freely until the first
     * review is submitted (Plan 4 sets review_forms.locked_at then).
     *
     * @var list<string>
     */
    public const TEMPLATE = [
        'Originality and Innovation: How original and innovative is the research presented in the abstract?',
        'Relevance to the Field: How relevant is the research to the field or theme of the conference?',
        'Methodological Rigor: How rigorous and appropriate are the methods used in the research?',
        'Clarity of Presentation: How clear and well-organized is the abstract?',
        'Results and Conclusions: How compelling and well-supported are the results and conclusions presented?',
        'Potential Impact: What is the potential impact of the research on the field?',
        'Interdisciplinary Appeal: Does the research have appeal or implications beyond its immediate field?',
        'Engagement Potential: How likely is the abstract to engage the audience during the presentation?',
        'Do you recommend this abstract for oral presentation? (1 = do not recommend, 5 = strongly recommend)',
    ];

    /**
     * Idempotent: returns the existing active form untouched if there is one,
     * so it is safe to call from the create page, from a relation manager and
     * from the Plan 6 legacy import.
     */
    public function handle(Conference $conference): ReviewForm
    {
        $existing = $conference->reviewForm()->first();

        if ($existing instanceof ReviewForm) {
            return $existing;
        }

        return DB::transaction(function () use ($conference): ReviewForm {
            $form = new ReviewForm(['name' => 'Review form']);
            $form->conference()->associate($conference);
            // Eloquent never reads column defaults back after an INSERT, and
            // `is_active` is not fillable, so without this the returned
            // instance reports null and every caller that trusts it (the
            // publish gate, the relation manager) sees an inactive form.
            $form->is_active = true;
            $form->save();

            foreach (self::TEMPLATE as $index => $prompt) {
                $form->questions()->create([
                    'prompt' => $prompt,
                    'type' => ReviewQuestionType::Likert,
                    'scale_min' => 1,
                    'scale_max' => 5,
                    'weight' => '1.00',
                    'required' => true,
                    'sort' => $index + 1,
                ]);
            }

            $conference->setRelation('reviewForm', $form);

            return $form;
        });
    }
}
```

- [ ] **Step 5: Write `app/Actions/Conferences/CreateConference.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\ConferenceStatus;
use App\Models\Conference;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class CreateConference
{
    public function __construct(private readonly CreateDefaultReviewForm $createDefaultReviewForm) {}

    /**
     * The tenant, the slug and the starting status are set here rather than in
     * a model event so the result does not depend on the order in which
     * Filament's tenancy `creating` observer and the model's own `booted()`
     * listener happen to be registered.
     *
     * `slug` is optional (spec 5.2 lets the organizer choose the public
     * address) and is not fillable, so it is taken out of $data before fill()
     * - Model::preventSilentlyDiscardingAttributes() is on outside production
     * and would throw on it.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Organization $organization, array $data): Conference
    {
        return DB::transaction(function () use ($organization, $data): Conference {
            $slug = is_string($data['slug'] ?? null) && $data['slug'] !== '' ? $data['slug'] : null;
            unset($data['slug']);

            $conference = new Conference;
            $conference->fill($data);
            $conference->organization()->associate($organization);
            $conference->slug = $slug ?? Conference::uniqueSlug($organization->id, (string) ($data['name'] ?? ''));
            // Eloquent does not read column defaults back after an INSERT, so
            // an unset status would be null on the instance this returns -
            // and $conference->status->canTransitionTo() in the publish gate
            // would then be a call on null.
            $conference->status = ConferenceStatus::Draft;
            $conference->save();

            $this->createDefaultReviewForm->handle($conference);

            // Loads the remaining column defaults (review_mode, blind_review,
            // reviewers_per_submission, ...) for the keys $data omitted.
            return $conference->refresh();
        });
    }
}
```

- [ ] **Step 6: Write `app/Actions/Conferences/PublishConference.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\ConferenceStatus;
use App\Enums\OrganizationStatus;
use App\Exceptions\ConferenceNotPublishable;
use App\Models\Conference;
use App\Models\ShortLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PublishConference
{
    /**
     * Spec 5.2: publishing requires an approved organization, a submission
     * window whose deadline is still in the future, and an active review form
     * with at least one question. Every failure is a sentence the organizer
     * can act on, not a validation code.
     *
     * @return list<string> empty when the conference may be published
     */
    public function blockers(Conference $conference): array
    {
        $reasons = [];

        $organization = $conference->organization;

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

        return $reasons;
    }

    public function handle(Conference $conference, User $actor): Conference
    {
        $reasons = $this->blockers($conference);

        if ($reasons !== []) {
            throw new ConferenceNotPublishable($reasons);
        }

        return DB::transaction(function () use ($conference, $actor): Conference {
            $conference->forceFill([
                'status' => ConferenceStatus::Open,
                'published_at' => $conference->published_at ?? now(),
            ])->save();

            // Idempotent, so a republished conference keeps the code already
            // printed on posters and in emails (spec 5.7).
            ShortLink::forTarget($conference);

            activity()->performedOn($conference)->causedBy($actor)->log('conference.published');

            return $conference->refresh();
        });
    }
}
```

- [ ] **Step 7: Write `app/Actions/Conferences/CloseSubmissions.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\ConferenceStatus;
use App\Models\Conference;
use App\Models\User;
use InvalidArgumentException;

class CloseSubmissions
{
    public function handle(Conference $conference, User $actor): Conference
    {
        if (! $conference->status->canTransitionTo(ConferenceStatus::Closed)) {
            throw new InvalidArgumentException(
                'Only a conference that is open for submissions can be closed; this one is '.strtolower($conference->status->getLabel()).'.'
            );
        }

        $conference->forceFill(['status' => ConferenceStatus::Closed])->save();

        activity()->performedOn($conference)->causedBy($actor)->log('conference.closed');

        return $conference->refresh();
    }
}
```

- [ ] **Step 8: Write `app/Actions/Conferences/ArchiveConference.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\ConferenceStatus;
use App\Models\Conference;
use App\Models\User;
use InvalidArgumentException;

class ArchiveConference
{
    /**
     * Archiving takes the conference off the public site without deleting
     * anything: submissions, reviews and decisions stay readable in the panel.
     * It is one-way; the status graph has no edge back out of Archived.
     */
    public function handle(Conference $conference, User $actor): Conference
    {
        if (! $conference->status->canTransitionTo(ConferenceStatus::Archived)) {
            throw new InvalidArgumentException('This conference is already archived.');
        }

        $conference->forceFill(['status' => ConferenceStatus::Archived])->save();

        activity()->performedOn($conference)->causedBy($actor)->log('conference.archived');

        return $conference->refresh();
    }
}
```

- [ ] **Step 9: Run the test to verify it passes**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/PublishConferenceTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -4 /tmp/t.log
```

Expected: `rc=0`, `15 passed`.

- [ ] **Step 10: Pint, Larastan, full suite, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?" && \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log && \
git add -A && git commit -q -m "feat: conference lifecycle actions with the spec 5.2 publishing gate

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

Expected: `stan rc=0`, `tests rc=0`, `122 passed`.

---

### Task 7: Policies and the conference resource (list, create, edit)

**Files:**
- Create: `app/Policies/{ConferencePolicy,TrackPolicy,CustomFieldPolicy,ReviewFormPolicy,ReviewQuestionPolicy}.php`
- Create: `app/Filament/Organizer/Resources/Conferences/ConferenceResource.php`, `Schemas/ConferenceForm.php`, `Tables/ConferencesTable.php`, `Pages/{ListConferences,CreateConference,EditConference}.php`
- Create: `app/Filament/Admin/Resources/Conferences/ConferenceResource.php`, `Tables/ConferencesTable.php`, `Pages/{ListConferences,ViewConference}.php`
- Modify: `tests/Pest.php`
- Test: `tests/Feature/Organizer/ConferenceResourceTest.php`, `tests/Feature/Admin/ConferencesTest.php`

- [ ] **Step 1: Add the two panel-test helpers to `tests/Pest.php`**

```php
<?php

declare(strict_types=1);

use App\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Put the request into the organizer panel for one tenant, the way the panel's
 * middleware does in a real request.
 */
function bootOrganizerPanel(Organization $organization): void
{
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();
}

/**
 * Filament registers a `creating` observer on every tenant-scoped model that
 * associates the current tenant, so a fixture belonging to another
 * organization would silently be re-parented while the panel is booted. Wrap
 * cross-tenant fixtures in this.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function withoutTenant(Closure $callback): mixed
{
    $tenant = Filament::getTenant();
    Filament::setTenant(null, isQuiet: true);

    try {
        return $callback();
    } finally {
        Filament::setTenant($tenant, isQuiet: true);
    }
}
```

- [ ] **Step 2: Write the failing feature test**

`tests/Feature/Organizer/ConferenceResourceTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewMode;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Pages\CreateConference;
use App\Filament\Organizer\Resources\Conferences\Pages\EditConference;
use App\Filament\Organizer\Resources\Conferences\Pages\ListConferences;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);
});

it('lists only the conferences of the current tenant', function () {
    $mine = Conference::factory()->for($this->organization)->create(['name' => 'Alpha Annual Meeting']);
    $theirs = withoutTenant(fn () => Conference::factory()->create(['name' => 'Beta Annual Meeting']));

    livewire(ListConferences::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('creates a conference under the tenant with the default review form', function () {
    livewire(CreateConference::class)
        ->fillForm([
            'name' => 'Alpha Pediatric Congress',
            'short_description' => 'A three-day congress in Riyadh.',
            'starts_at' => now()->addMonths(7)->toDateString(),
            'ends_at' => now()->addMonths(7)->addDays(2)->toDateString(),
            'timezone' => 'Asia/Riyadh',
            'venue' => 'Riyadh Front',
            'city' => 'Riyadh',
            'country' => 'SA',
            'submission_opens_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'submission_deadline' => now()->addMonths(2)->format('Y-m-d H:i:s'),
            'review_deadline' => now()->addMonths(3)->format('Y-m-d H:i:s'),
            'review_mode' => ReviewMode::OpenPool->value,
            'blind_review' => true,
            'word_limit' => 450,
            'max_files' => 2,
            'allowed_file_types' => ['pdf'],
            'presentation_types' => ['oral', 'poster'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $conference = Conference::query()->where('name', 'Alpha Pediatric Congress')->firstOrFail();

    expect($conference->organization_id)->toBe($this->organization->id)
        ->and($conference->slug)->toBe('alpha-pediatric-congress')
        ->and($conference->status)->toBe(ConferenceStatus::Draft)
        ->and($conference->word_limit)->toBe(450)
        ->and($conference->reviewForm?->questions()->count())->toBe(9);
});

it('requires a name and rejects a deadline before the opening date', function () {
    livewire(CreateConference::class)
        ->fillForm([
            'name' => '',
            'submission_opens_at' => now()->addMonths(2)->format('Y-m-d H:i:s'),
            'submission_deadline' => now()->addMonth()->format('Y-m-d H:i:s'),
        ])
        ->call('create')
        ->assertHasFormErrors(['name', 'submission_deadline']);
});

it('takes an optional web address and refuses one already used', function () {
    livewire(CreateConference::class)
        ->fillForm(['name' => 'Gulf Pediatric Critical Care 2026', 'slug' => 'gpcc26'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Conference::query()->where('name', 'Gulf Pediatric Critical Care 2026')->firstOrFail()->slug)
        ->toBe('gpcc26');

    // The second half of the name: the same web address cannot be taken twice
    // within one organization.
    livewire(CreateConference::class)
        ->fillForm(['name' => 'Gulf Pediatric Critical Care 2027', 'slug' => 'gpcc26'])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('refuses a web address a soft-deleted conference still holds', function () {
    Conference::factory()->for($this->organization)->create(['name' => 'Winter School'])->delete();

    livewire(CreateConference::class)
        ->fillForm(['name' => 'Winter School 2027', 'slug' => 'winter-school'])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('asks for reviewers per submission only in assigned mode', function () {
    livewire(CreateConference::class)
        ->fillForm(['review_mode' => ReviewMode::OpenPool->value])
        ->assertFormFieldIsHidden('reviewers_per_submission')
        ->fillForm(['review_mode' => ReviewMode::Assigned->value])
        ->assertFormFieldIsVisible('reviewers_per_submission');
});

it('stores reviewers per submission for an assigned-mode conference', function () {
    // A hidden field is not dehydrated, so an always-false visible() closure
    // (comparing $get('review_mode') with ->value instead of the enum case)
    // would silently drop this value rather than fail loudly.
    livewire(CreateConference::class)
        ->fillForm([
            'name' => 'Assigned Review Meeting',
            'review_mode' => ReviewMode::Assigned->value,
            'reviewers_per_submission' => 3,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Conference::query()->where('name', 'Assigned Review Meeting')->firstOrFail()->reviewers_per_submission)
        ->toBe(3);
});

it('edits a conference and keeps the slug fixed', function () {
    $conference = Conference::factory()->for($this->organization)->create(['name' => 'Alpha Annual Meeting']);

    livewire(EditConference::class, ['record' => $conference->getRouteKey()])
        ->fillForm([
            'name' => 'Alpha Annual Scientific Meeting',
            'venue' => 'King Saud University',
            'word_limit' => 600,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $conference->refresh();
    expect($conference->name)->toBe('Alpha Annual Scientific Meeting')
        ->and($conference->venue)->toBe('King Saud University')
        ->and($conference->word_limit)->toBe(600)
        ->and($conference->slug)->toBe('alpha-annual-meeting');
});

it('hides the conference resource from a user with no membership', function () {
    $stranger = User::factory()->create();
    Organization::factory()->approved()->create()->addMember($stranger, OrganizationRole::Owner);

    actingAs($stranger)->get(ConferenceResource::getUrl('index', tenant: $this->organization))
        ->assertNotFound();
});

it('refuses to open a conference belonging to another organization', function () {
    $theirs = withoutTenant(fn () => Conference::factory()->create());

    get(ConferenceResource::getUrl('edit', ['record' => $theirs->getRouteKey()], tenant: $this->organization))
        ->assertNotFound();
});

it('opens the pages behind the URLs Filament generates', function () {
    // Filament builds every record URL by handing the model to route(), which
    // uses getRouteKey(), and resolves it with the resource's own key name. If
    // those two disagree, the redirect after create, the table's row actions
    // and every breadcrumb 404 while tests that pass an explicit key pass.
    $conference = Conference::factory()->for($this->organization)->create();

    foreach (['edit'] as $page) {
        get(ConferenceResource::getUrl($page, ['record' => $conference]))->assertOk();
    }
});

it('lets a plain member create and edit conferences', function () {
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    actingAs($member);

    livewire(CreateConference::class)
        ->fillForm(['name' => 'Member Created Meeting'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Conference::query()->where('name', 'Member Created Meeting')->exists())->toBeTrue();
});

it('offers delete only to owners and admins', function () {
    $conference = Conference::factory()->for($this->organization)->create();
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);

    expect($this->user->can('delete', $conference))->toBeTrue();
    expect($member->can('delete', $conference))->toBeFalse();
    expect($member->can('update', $conference))->toBeTrue();
});

it('offers bulk delete to owners but not to plain members', function () {
    // Filament authorizes DeleteBulkAction with deleteAny() and treats a
    // missing policy method as "allow", so without deleteAny() a plain member
    // sees "Delete selected" and can soft-delete every conference of the
    // organization while `can('delete', $conference)` still answers false.
    $conference = Conference::factory()->for($this->organization)->create();
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);

    actingAs($member);
    livewire(ListConferences::class)->assertTableBulkActionHidden('delete');
    expect($member->can('deleteAny', Conference::class))->toBeFalse();

    actingAs($this->user);
    livewire(ListConferences::class)
        ->assertTableBulkActionVisible('delete')
        ->callTableBulkAction('delete', [$conference]);

    expect($conference->fresh()?->trashed())->toBeTrue();
});
```

`tests/Feature/Admin/ConferencesTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Admin\Resources\Conferences\ConferenceResource as AdminConferenceResource;
use App\Filament\Admin\Resources\Conferences\Pages\ListConferences as AdminListConferences;
use App\Models\Conference;
use App\Models\Organization;
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
});

it('shows the platform admin every organization conference', function () {
    // Spec section 4 gives the platform admin "see all organizations and
    // conferences"; the organizer panel admits members only, so without this
    // read-only resource nobody outside an organization can look at one.
    $first = Conference::factory()->create(['name' => 'Alpha Annual Meeting']);
    $second = Conference::factory()->create(['name' => 'Beta Annual Meeting']);

    livewire(AdminListConferences::class)
        ->assertCanSeeTableRecords([$first, $second])
        ->assertSee($first->organization->name)
        ->assertSee($second->organization->name);
});

it('opens the right conference when two organizations share a slug', function () {
    $first = Conference::factory()->create(['name' => 'Annual Meeting']);
    $second = Conference::factory()->create(['name' => 'Annual Meeting']);

    expect($first->slug)->toBe($second->slug);

    get(AdminConferenceResource::getUrl('view', ['record' => $second], panel: 'admin'))
        ->assertOk()
        ->assertSee($second->organization->name);
});

it('lets the platform admin restore a soft-deleted conference', function () {
    // The organizer-facing delete modal promises the platform team can restore
    // a conference; this is where that promise is kept.
    $conference = Conference::factory()->create();
    $conference->delete();

    livewire(AdminListConferences::class)
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$conference])
        ->callTableAction('restore', $conference);

    expect($conference->fresh()?->trashed())->toBeFalse();
});

it('refuses the admin conference list to an organization owner', function () {
    $owner = User::factory()->create();
    Organization::factory()->approved()->create()->addMember($owner, OrganizationRole::Owner);

    actingAs($owner)->get(AdminConferenceResource::getUrl('index', panel: 'admin'))
        ->assertForbidden();
});
```

- [ ] **Step 3: Run it to verify it fails**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferenceResourceTest.php tests/Feature/Admin/ConferencesTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found|does not exist|Unable to find component" /tmp/t.log | head -3
```

Expected: `rc=1`. The first test mounts a Livewire component that does not exist yet, so the message is `Unable to find component: [App\Filament\Organizer\Resources\Conferences\Pages\ListConferences]` (a `ComponentNotFoundException`, which is why the grep matches on more than "not found").

- [ ] **Step 4: Write `app/Policies/ConferencePolicy.php`**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Conference;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

class ConferencePolicy
{
    /**
     * Spec section 4 gives the platform admin every conference capability, and
     * conferences otherwise live only inside the tenant-scoped organizer
     * panel. Note this also makes `forceDelete` true for a platform admin: no
     * ForceDeleteAction may be added to either panel until the
     * application-code purge exists (backlog, Plan 6).
     */
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    /**
     * Spec section 4: every organization member (owner, admin or member) can
     * create and edit conferences; only owners and admins can archive or
     * delete one.
     */
    public function viewAny(User $user): bool
    {
        return $this->isMemberOfCurrentTenant($user);
    }

    public function create(User $user): bool
    {
        return $this->isMemberOfCurrentTenant($user);
    }

    public function view(User $user, Conference $conference): bool
    {
        return $user->roleIn($conference->organization) !== null;
    }

    public function update(User $user, Conference $conference): bool
    {
        return $this->view($user, $conference);
    }

    public function publish(User $user, Conference $conference): bool
    {
        return $this->view($user, $conference);
    }

    public function close(User $user, Conference $conference): bool
    {
        return $this->view($user, $conference);
    }

    public function archive(User $user, Conference $conference): bool
    {
        return $this->canManage($user, $conference);
    }

    public function delete(User $user, Conference $conference): bool
    {
        return $this->canManage($user, $conference);
    }

    public function restore(User $user, Conference $conference): bool
    {
        return $this->canManage($user, $conference);
    }

    /** Hard purge is a platform-admin action (spec section 3), never an organizer one. */
    public function forceDelete(User $user, Conference $conference): bool
    {
        return false;
    }

    /**
     * Filament authorizes a resource page's `DeleteBulkAction` with
     * `deleteAny()` and never with the per-record `delete()`, and it treats a
     * *missing* policy method as ALLOW (fact 7), so this has to mirror
     * delete() or every member could bulk-delete the whole organization.
     */
    public function deleteAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization
            && ($user->roleIn($tenant)?->canManageOrganization() ?? false);
    }

    /** No bulk restore or purge UI exists in either panel; keep both closed. */
    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function canManage(User $user, Conference $conference): bool
    {
        return $user->roleIn($conference->organization)?->canManageOrganization() ?? false;
    }

    private function isMemberOfCurrentTenant(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }
}
```

- [ ] **Step 5: Write the four child policies**

`app/Policies/TrackPolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\Track;
use App\Models\User;
use Filament\Facades\Filament;

class TrackPolicy
{
    public function viewAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Track $track): bool
    {
        return $user->roleIn($track->conference->organization) !== null;
    }

    public function update(User $user, Track $track): bool
    {
        return $this->view($user, $track);
    }

    public function delete(User $user, Track $track): bool
    {
        return $this->view($user, $track);
    }

    public function reorder(User $user): bool
    {
        return $this->viewAny($user);
    }
}
```

`app/Policies/CustomFieldPolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomField;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

class CustomFieldPolicy
{
    public function viewAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, CustomField $customField): bool
    {
        return $user->roleIn($customField->conference->organization) !== null;
    }

    public function update(User $user, CustomField $customField): bool
    {
        return $this->view($user, $customField);
    }

    public function delete(User $user, CustomField $customField): bool
    {
        return $this->view($user, $customField);
    }

    public function reorder(User $user): bool
    {
        return $this->viewAny($user);
    }
}
```

`app/Policies/ReviewFormPolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\ReviewForm;
use App\Models\User;
use Filament\Facades\Filament;

class ReviewFormPolicy
{
    public function viewAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }

    public function view(User $user, ReviewForm $reviewForm): bool
    {
        return $user->roleIn($reviewForm->conference->organization) !== null;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ReviewForm $reviewForm): bool
    {
        return $this->view($user, $reviewForm) && ! $reviewForm->isLocked();
    }

    public function delete(User $user, ReviewForm $reviewForm): bool
    {
        return false;
    }
}
```

`app/Policies/ReviewQuestionPolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\ReviewQuestion;
use App\Models\User;
use Filament\Facades\Filament;

class ReviewQuestionPolicy
{
    public function viewAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }

    /**
     * Spec section 3: once the first review is submitted the form is locked and
     * only new questions may be appended, so `create` ignores the lock while
     * `update` and `delete` respect it. `reorder` receives no record and cannot
     * see the lock, so the relation manager enforces that with
     * `reorderable(null)`. The model enforces update/delete for non-panel paths.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, ReviewQuestion $reviewQuestion): bool
    {
        return $user->roleIn($reviewQuestion->reviewForm->conference->organization) !== null;
    }

    public function update(User $user, ReviewQuestion $reviewQuestion): bool
    {
        return $this->view($user, $reviewQuestion) && ! $reviewQuestion->reviewForm->isLocked();
    }

    public function delete(User $user, ReviewQuestion $reviewQuestion): bool
    {
        return $this->update($user, $reviewQuestion);
    }

    public function reorder(User $user): bool
    {
        return $this->viewAny($user);
    }
}
```

> Laravel 13 discovers `App\Policies\<Model>Policy` automatically for `App\Models\<Model>`; no `Gate::policy()` registration is needed. Filament's relation managers call `viewAny` with `shouldCheckPolicyExistence: true`, which is exactly why all four exist.

- [ ] **Step 6: Write `app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceForm.php`**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Schemas;

use App\Enums\ReviewMode;
use App\Models\Conference;
use App\Models\Organization;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ConferenceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make('Details')->components([
                    Section::make()->columns(2)->components([
                        TextInput::make('name')->required()->minLength(3)->maxLength(180)->columnSpanFull(),
                        // Spec 5.2 lets the organizer choose the public address
                        // (`/c/gps/gpcc26` rather than the derived
                        // `/c/gps/gulf-pediatric-critical-care-2026`). Left
                        // empty it is derived from the name. Uniqueness is
                        // checked against trashed rows too, because
                        // Conference::uniqueSlug() reserves them so an old
                        // printed link can never point at a new conference -
                        // Laravel's `unique` rule would ignore them.
                        TextInput::make('slug')
                            ->label('Web address')
                            ->maxLength(80)
                            // One closure rule rather than ->regex() plus a
                            // separate uniqueness rule: the field is optional,
                            // and `nullable` does not skip an empty *string*,
                            // so a bare regex would reject "leave it blank".
                            ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                if (! is_string($value) || $value === '') {
                                    return; // Empty means "derive it from the name".
                                }

                                if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) !== 1) {
                                    $fail('Use lower-case letters, numbers and single hyphens, for example gpcc26.');

                                    return;
                                }

                                $tenant = Filament::getTenant();

                                if ($tenant instanceof Organization
                                    && Conference::withTrashed()
                                        ->where('organization_id', $tenant->getKey())
                                        ->where('slug', $value)
                                        ->exists()) {
                                    $fail('Another conference of this organization already uses this address.');
                                }
                            })
                            ->helperText('Optional. Derived from the name when empty. Fixed after creation so printed links and QR codes keep working.')
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->columnSpanFull(),
                        Textarea::make('short_description')->rows(2)->maxLength(500)->columnSpanFull()
                            ->label('One-line summary')
                            ->helperText('Shown under the title on the public page and in search results.'),
                        RichEditor::make('description')
                            ->label('Call for abstracts')
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'link'],
                                ['h2', 'h3'],
                                ['bulletList', 'orderedList'],
                                ['undo', 'redo'],
                            ])
                            ->columnSpanFull()
                            ->helperText('Rendered on the public page. Formatting is kept; styles, classes, images and embedded media are removed.'),
                    ]),
                    Section::make('When and where')->columns(2)->components([
                        DatePicker::make('starts_at')->label('First day')->native(false),
                        DatePicker::make('ends_at')->label('Last day')->native(false)->afterOrEqual('starts_at'),
                        TextInput::make('venue')->maxLength(180),
                        TextInput::make('city')->maxLength(120),
                        Select::make('country')->options(config('cass.countries'))->searchable(),
                        Select::make('timezone')
                            ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->searchable()->required()->default('Asia/Riyadh')
                            ->helperText('Deadlines are shown to authors in this timezone.'),
                    ]),
                ]),

                Tab::make('Submissions')->components([
                    Section::make('Window')->columns(2)->components([
                        DateTimePicker::make('submission_opens_at')
                            ->label('Submissions open')->seconds(false)->native(false)
                            ->timezone(fn (Get $get): string => (string) ($get('timezone') ?: 'Asia/Riyadh')),
                        DateTimePicker::make('submission_deadline')
                            ->label('Submission deadline')->seconds(false)->native(false)
                            ->timezone(fn (Get $get): string => (string) ($get('timezone') ?: 'Asia/Riyadh'))
                            ->after('submission_opens_at'),
                        DateTimePicker::make('review_deadline')
                            ->label('Review deadline')->seconds(false)->native(false)
                            ->timezone(fn (Get $get): string => (string) ($get('timezone') ?: 'Asia/Riyadh'))
                            ->after('submission_deadline')
                            ->helperText('Reviewers are reminded 7, 3 and 1 days before this date.'),
                    ]),
                    Section::make('What authors may send')->columns(2)->components([
                        TextInput::make('word_limit')->numeric()->required()->minValue(50)->maxValue(5000)->default(500)
                            ->helperText('Counted live in the submission form.'),
                        TextInput::make('max_files')->numeric()->required()->minValue(0)->maxValue(10)->default(3),
                        CheckboxList::make('allowed_file_types')
                            ->options(['pdf' => 'PDF', 'doc' => 'Word (.doc)', 'docx' => 'Word (.docx)'])
                            ->default(['pdf'])->required()->bulkToggleable(),
                        CheckboxList::make('presentation_types')
                            ->options(['oral' => 'Oral', 'poster' => 'Poster', 'either' => 'Either'])
                            ->default(['oral', 'poster', 'either'])->required()->bulkToggleable(),
                        Textarea::make('terms')->rows(4)->maxLength(2000)->columnSpanFull()
                            ->helperText('Shown on the public page and above the agreement checkbox.'),
                    ]),
                ]),

                Tab::make('Review')->components([
                    Section::make()->columns(2)->components([
                        Select::make('review_mode')
                            ->options(ReviewMode::class)->required()->live()
                            ->default(ReviewMode::OpenPool->value)->columnSpanFull(),
                        // `options(ReviewMode::class)` registers an
                        // EnumStateCast, so $get() hands back the enum case and
                        // never the backing string (fact 14). Comparing with
                        // ->value here would hide the field for ever, and a
                        // hidden field is not dehydrated - the value would be
                        // silently dropped on save instead of failing loudly.
                        TextInput::make('reviewers_per_submission')
                            ->numeric()->minValue(1)->maxValue(10)->default(2)
                            ->required(fn (Get $get): bool => $get('review_mode') === ReviewMode::Assigned)
                            ->visible(fn (Get $get): bool => $get('review_mode') === ReviewMode::Assigned),
                        Toggle::make('blind_review')->default(true)
                            ->label('Hide author names and affiliations from reviewers'),
                    ]),
                ]),
            ]),
        ]);
    }
}
```

- [ ] **Step 7: Write `app/Filament/Organizer/Resources/Conferences/Tables/ConferencesTable.php`**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Tables;

use App\Enums\ConferenceStatus;
use App\Models\Conference;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ConferencesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()
                    ->description(fn (Conference $record): string => $record->slug),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('starts_at')->label('Dates')->date('j M Y')->sortable(),
                // Timestamps are stored UTC and Filament formats a date-time in
                // config('app.timezone') (UTC) unless told otherwise, so
                // without this the deadline reads three hours early for a
                // Riyadh conference (spec section 10).
                TextColumn::make('submission_deadline')->label('Deadline')->dateTime('j M Y, H:i')->sortable()
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->description(fn (Conference $record): string => $record->timezone)
                    ->placeholder('Not set'),
                TextColumn::make('tracks_count')->counts('tracks')->label('Tracks')->badge()->color('gray'),
                TextColumn::make('shortLink.clicks')->label('Scans')->badge()->color('gray')->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')->options(ConferenceStatus::class)->multiple(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->icon(Heroicon::OutlinedArchiveBox)
                    ->modalDescription('Soft delete: the conference and everything under it stay in the database and can be restored by the platform team.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Filament authorizes this with ConferencePolicy::deleteAny()
                    // and would otherwise delete every selected row without a
                    // per-record check; authorizeIndividualRecords() makes it
                    // run delete() on each one as well.
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->emptyStateHeading('No conferences yet')
            ->emptyStateDescription('Create one to set your dates, review form and submission window. You can publish it once it is ready.');
    }
}
```

- [ ] **Step 8: Write the resource**

`app/Filament/Organizer/Resources/Conferences/ConferenceResource.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences;

use App\Filament\Organizer\Resources\Conferences\Pages\CreateConference;
use App\Filament\Organizer\Resources\Conferences\Pages\EditConference;
use App\Filament\Organizer\Resources\Conferences\Pages\ListConferences;
use App\Filament\Organizer\Resources\Conferences\Schemas\ConferenceForm;
use App\Filament\Organizer\Resources\Conferences\Tables\ConferencesTable;
use App\Models\Conference;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filament's Resource is generic (`@template TModel of Model = Model`), so
 * without this the inherited getEloquentQuery() is a Builder<Model> and the
 * narrowed override below fails Larastan level 6 with return.type.
 *
 * @extends Resource<Conference>
 */
class ConferenceResource extends Resource
{
    protected static ?string $model = Conference::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'name';

    // No $recordRouteKeyName: Filament builds record URLs from the model's own
    // getRouteKey() (the ULID) and resolves them with this key name, so
    // overriding one without the other 404s every generated link (fact 16).

    /**
     * The default already resolves to `organization`; stating it means a
     * rename of the relation breaks loudly here instead of silently
     * disabling tenant scoping.
     */
    protected static ?string $tenantOwnershipRelationshipName = 'organization';

    public static function form(Schema $schema): Schema
    {
        return ConferenceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConferencesTable::configure($table);
    }

    /**
     * Tenant scoping is a global scope the panel registers, so this only adds
     * the eager loads the table columns need. Do not re-add a tenant filter.
     *
     * @return Builder<Conference>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('shortLink');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConferences::route('/'),
            'create' => CreateConference::route('/create'),
            'edit' => EditConference::route('/{record}/edit'),
        ];
    }
}
```

- [ ] **Step 9: Write the three pages**

`app/Filament/Organizer/Resources/Conferences/Pages/ListConferences.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConferences extends ListRecords
{
    protected static string $resource = ConferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New conference'),
        ];
    }
}
```

`app/Filament/Organizer/Resources/Conferences/Pages/CreateConference.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Actions\Conferences\CreateConference as CreateConferenceAction;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateConference extends CreateRecord
{
    protected static string $resource = ConferenceResource::class;

    /**
     * Delegating to the action keeps the slug derivation and the default
     * review form in one place, shared with the console and the Plan 6
     * legacy import.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var Organization $organization */
        $organization = Filament::getTenant();

        return app(CreateConferenceAction::class)->handle($organization, $data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
```

`app/Filament/Organizer/Resources/Conferences/Pages/EditConference.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditConference extends EditRecord
{
    protected static string $resource = ConferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
```

- [ ] **Step 10: Write the read-only admin conference resource**

Spec section 4 gives the platform admin "see all organizations and conferences", but conferences otherwise exist only inside the tenant-scoped organizer panel, which admits members of the tenant and nobody else. Without this resource the platform admin cannot look at a conference at all, and the organizer-facing delete modal's promise that "the platform team can restore it" has nowhere to happen.

`app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Conferences\Tables;

use App\Enums\ConferenceStatus;
use App\Models\Conference;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ConferencesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()
                    ->description(fn (Conference $record): string => $record->slug),
                TextColumn::make('organization.name')->label('Organization')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('submission_deadline')->label('Deadline')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->description(fn (Conference $record): string => $record->timezone)
                    ->placeholder('Not set'),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ConferenceStatus::class)->multiple(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                // Restores the soft delete an organizer made. There is no
                // ForceDeleteAction anywhere: ConferencePolicy::before() makes
                // forceDelete true for a platform admin, and the hard purge has
                // to cascade in application code (spec section 3, backlog).
                RestoreAction::make(),
            ]);
    }
}
```

`app/Filament/Admin/Resources/Conferences/ConferenceResource.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Conferences;

use App\Filament\Admin\Resources\Conferences\Pages\ListConferences;
use App\Filament\Admin\Resources\Conferences\Pages\ViewConference;
use App\Filament\Admin\Resources\Conferences\Tables\ConferencesTable;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Models\Conference;
use App\Models\User;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** @extends Resource<Conference> */
class ConferenceResource extends Resource
{
    protected static ?string $model = Conference::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Read-only on purpose: the platform admin looks and restores, the
     * organizer edits. No `create` or `edit` page is registered, and no
     * $recordRouteKeyName is set - the model's ULID route key is what the
     * generated URLs carry, which also keeps this correct when two
     * organizations happen to use the same conference slug.
     */
    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->is_platform_admin;
    }

    public static function table(Table $table): Table
    {
        return ConferencesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Conference')->columns(2)->components([
                // The admin OrganizationResource binds by id while
                // Organization::getRouteKeyName() is the slug, so this passes
                // the id itself rather than the model (fact 16).
                TextEntry::make('organization.name')->label('Organization')
                    ->url(fn (Conference $record): string => OrganizationResource::getUrl(
                        'view', ['record' => $record->organization_id], panel: 'admin'
                    )),
                TextEntry::make('name'),
                TextEntry::make('status')->badge(),
                TextEntry::make('ulid')->label('Public id'),
                TextEntry::make('slug')->label('Web address')
                    ->url(fn (Conference $record): string => $record->publicUrl())
                    ->openUrlInNewTab()
                    ->formatStateUsing(fn (Conference $record): string => $record->publicUrl()),
                TextEntry::make('published_at')->dateTime('j M Y, H:i')->placeholder('Not published'),
                TextEntry::make('deleted_at')->label('Deleted')->dateTime('j M Y, H:i')->placeholder('-'),
            ]),

            Section::make('Dates')->columns(3)->components([
                TextEntry::make('starts_at')->date('j M Y')->placeholder('Not set'),
                TextEntry::make('ends_at')->date('j M Y')->placeholder('Not set'),
                TextEntry::make('timezone'),
                TextEntry::make('submission_opens_at')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)->placeholder('Not set'),
                TextEntry::make('submission_deadline')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)->placeholder('Not set'),
                TextEntry::make('review_deadline')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)->placeholder('Not set'),
            ]),
        ]);
    }

    /**
     * The admin panel has no tenancy, so nothing scopes this. The soft-delete
     * scope is dropped so a trashed conference can still be opened and
     * restored; TrashedFilter hides them from the default listing.
     *
     * @return Builder<Conference>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with('organization');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConferences::route('/'),
            'view' => ViewConference::route('/{record}'),
        ];
    }
}
```

`app/Filament/Admin/Resources/Conferences/Pages/ListConferences.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Conferences\Pages;

use App\Filament\Admin\Resources\Conferences\ConferenceResource;
use Filament\Resources\Pages\ListRecords;

class ListConferences extends ListRecords
{
    protected static string $resource = ConferenceResource::class;
}
```

`app/Filament/Admin/Resources/Conferences/Pages/ViewConference.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Conferences\Pages;

use App\Filament\Admin\Resources\Conferences\ConferenceResource;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;

class ViewConference extends ViewRecord
{
    protected static string $resource = ConferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RestoreAction::make(),
        ];
    }
}
```

- [ ] **Step 11: Run the tests to verify they pass**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferenceResourceTest.php tests/Feature/Admin/ConferencesTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -4 /tmp/t.log
```

Expected: `rc=0`, `18 passed` (14 organizer, 4 admin).

- [ ] **Step 12: Pint, Larastan, full suite, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?" && \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log && \
git add -A && git commit -q -m "feat: conference resource in the organizer panel with policies for the whole tree

Adds a read-only admin conference resource as well, so the platform admin can
see and restore any organization's conferences (spec section 4).

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

Expected: `stan rc=0`, `tests rc=0`, `140 passed`.

---

### Task 8: Publish, close and archive in the panel, plus the conference view page

**Files:**
- Create: `app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php`, `Schemas/ConferenceInfolist.php`, `Pages/ViewConference.php`
- Modify: `ConferenceResource.php` (infolist + `view` page), `Tables/ConferencesTable.php` (view + status actions), `Pages/EditConference.php` (header actions)
- Test: `tests/Feature/Organizer/ConferenceTransitionsTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Organizer/ConferenceTransitionsTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Pages\CreateConference;
use App\Filament\Organizer\Resources\Conferences\Pages\ListConferences;
use App\Filament\Organizer\Resources\Conferences\Pages\ViewConference;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\User;
use Filament\Notifications\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->owner = User::factory()->create();
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
    actingAs($this->owner);
    bootOrganizerPanel($this->organization);
});

function readyConference(Organization $organization): Conference
{
    $conference = Conference::factory()->for($organization)->withSubmissionWindow()->create();
    app(CreateDefaultReviewForm::class)->handle($conference);

    return $conference->fresh() ?? $conference;
}

it('publishes a ready conference from the view page and shows the short link', function () {
    $conference = readyConference($this->organization);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->callAction('publish')
        ->assertNotified();

    $conference->refresh();
    expect($conference->status)->toBe(ConferenceStatus::Open)
        ->and($conference->published_at)->not->toBeNull()
        ->and($conference->shortLink)->not->toBeNull();
});

it('opens the pages behind the URLs Filament generates', function () {
    $conference = readyConference($this->organization);

    foreach (['view', 'edit'] as $page) {
        get(ConferenceResource::getUrl($page, ['record' => $conference]))->assertOk();
    }
});

it('refuses to publish and names every blocker', function () {
    $conference = Conference::factory()->for($this->organization)->create();

    // The full notification, not just its title: assertNotified('...') compares
    // the title alone, so dropping ->body() would go unnoticed.
    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->callAction('publish')
        ->assertNotified(
            Notification::make()
                ->danger()
                ->title('This conference is not ready to publish')
                ->body('Set both a submission opening date and a submission deadline. The review form has no questions yet. Add at least one before publishing.')
                ->persistent()
        );

    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Draft);
});

it('escapes the conference name in the publish notification', function () {
    // Filament renders a notification title as sanitised HTML whose shared
    // config keeps `style` on every element, so an unescaped name could paint a
    // full-viewport phishing link over the panel of whoever clicks Publish.
    $conference = readyConference($this->organization);
    $conference->forceFill(['name' => '<a href="https://evil.example" style="position:fixed;inset:0">Sign in</a>'])->save();

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->callAction('publish')
        ->assertNotified(e($conference->name).' is live');
});

it('refuses to publish while the organization is only pending', function () {
    $pending = Organization::factory()->create();
    $owner = User::factory()->create();
    $pending->addMember($owner, OrganizationRole::Owner);

    // Switch tenant *before* building the fixture: Filament's tenancy
    // `creating` observer re-parents any conference created while another
    // tenant is current (fact 5), which would silently make this an approved
    // organization's conference and the assertions meaningless.
    actingAs($owner);
    bootOrganizerPanel($pending);
    $conference = readyConference($pending);

    expect($conference->organization_id)->toBe($pending->id);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->callAction('publish')
        ->assertNotified('This conference is not ready to publish');

    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Draft);
});

it('closes submissions and offers to reopen afterwards', function () {
    $conference = readyConference($this->organization);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->callAction('publish')
        ->assertActionHidden('publish')
        ->assertActionVisible('close')
        ->callAction('close')
        ->assertNotified();

    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Closed);

    livewire(ViewConference::class, ['record' => $conference->fresh()?->getRouteKey()])
        ->assertActionVisible('publish')
        ->assertActionHidden('close');
});

it('drops the publishing checklist once the conference is live', function () {
    // blockers() also reports why a status cannot move to Open, so a live or
    // archived conference has a non-empty list - the red "Publishing checklist"
    // section must not appear on those.
    $conference = readyConference($this->organization);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->assertDontSee('Publishing checklist')
        ->callAction('publish')
        ->assertDontSee('Publishing checklist');

    livewire(ViewConference::class, ['record' => $conference->fresh()?->getRouteKey()])
        ->assertDontSee('Publishing checklist');
});

it('archives a conference and stops offering any further transition', function () {
    $conference = readyConference($this->organization);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->callAction('archive')
        ->assertNotified();

    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Archived);

    livewire(ViewConference::class, ['record' => $conference->fresh()?->getRouteKey()])
        ->assertActionHidden('publish')
        ->assertActionHidden('close')
        ->assertActionHidden('archive');
});

it('hides archive from a plain member but keeps publish', function () {
    $conference = readyConference($this->organization);
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    actingAs($member);

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->assertActionVisible('publish')
        ->assertActionHidden('archive');
});

it('stores a deadline typed in the conference timezone as utc and shows it back locally', function () {
    // Spec section 10: timestamps are stored UTC and every conference has its
    // own timezone. The form converts on save; the view page and the table have
    // to convert back, or a Riyadh deadline reads three hours early next to the
    // "Timezone: Asia/Riyadh" line beside it.
    $local = now('Asia/Riyadh')->addMonths(2)->setTime(0, 0);

    livewire(CreateConference::class)
        ->fillForm([
            'name' => 'Timezone Meeting',
            'timezone' => 'Asia/Riyadh',
            'submission_opens_at' => $local->copy()->subMonth()->format('Y-m-d H:i:s'),
            'submission_deadline' => $local->format('Y-m-d H:i:s'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $conference = Conference::query()->where('name', 'Timezone Meeting')->firstOrFail();

    expect($conference->getRawOriginal('submission_deadline'))
        ->toBe($local->copy()->utc()->format('Y-m-d H:i:s'));

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->assertSee($local->format('j M Y, H:i'));

    livewire(ListConferences::class)->assertSee($local->format('j M Y, H:i'));
});

it('publishes from the list table too', function () {
    $conference = readyConference($this->organization);

    livewire(ListConferences::class)
        ->callTableAction('publish', $conference)
        ->assertNotified();

    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Open);
});

it('shows the publishing checklist on the view page of a draft', function () {
    $conference = Conference::factory()->for($this->organization)->create();

    livewire(ViewConference::class, ['record' => $conference->getRouteKey()])
        ->assertSee('Publishing checklist')
        ->assertSee('Set both a submission opening date and a submission deadline.')
        ->assertSee('The review form has no questions yet. Add at least one before publishing.');
});

it('does not open the view page for another organization conference', function () {
    $theirs = withoutTenant(fn () => Conference::factory()->create());

    $this->get(ConferenceResource::getUrl(
        'view', ['record' => $theirs->getRouteKey()], tenant: $this->organization
    ))->assertNotFound();
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferenceTransitionsTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found|does not exist|Unable to find component" /tmp/t.log | head -3
```

Expected: `rc=1`, message `Unable to find component: [App\Filament\Organizer\Resources\Conferences\Pages\ViewConference]`.

- [ ] **Step 3: Write `app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php`**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Tables;

use App\Actions\Conferences\ArchiveConference;
use App\Actions\Conferences\CloseSubmissions;
use App\Actions\Conferences\PublishConference;
use App\Enums\ConferenceStatus;
use App\Models\Conference;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * One definition of each status transition, reused by the table row actions
 * and by the header of the view and edit pages, so the rules cannot drift
 * between the two places an organizer meets them.
 */
class ConferenceStatusActions
{
    public static function publish(): Action
    {
        return Action::make('publish')
            ->label(fn (Conference $record): string => $record->published_at === null ? 'Publish' : 'Reopen submissions')
            ->icon(Heroicon::OutlinedMegaphone)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Publish this conference?')
            ->modalDescription('The public page goes live and a short link and QR code are generated. You can close submissions again at any time.')
            ->visible(fn (Conference $record): bool => $record->status->canTransitionTo(ConferenceStatus::Open)
                && Gate::allows('publish', $record))
            ->action(function (Conference $record, PublishConference $publish): void {
                Gate::authorize('publish', $record);

                $blockers = $publish->blockers($record);

                if ($blockers !== []) {
                    Notification::make()
                        ->danger()
                        ->title('This conference is not ready to publish')
                        ->body(implode(' ', $blockers))
                        ->persistent()
                        ->send();

                    return;
                }

                /** @var User $actor */
                $actor = auth()->user();
                $published = $publish->handle($record, $actor);

                // Filament renders a notification's title and body through
                // Str::sanitizeHtml(), whose shared config keeps `style` and
                // `class` on every element (fact 15). The conference name is
                // organizer-supplied and every member can edit it, so escape
                // it before it reaches the owner's screen.
                Notification::make()
                    ->success()
                    ->title(e($published->name).' is live')
                    ->body('Short link: '.e($published->shortLink?->url() ?? ''))
                    ->send();
            });
    }

    public static function close(): Action
    {
        return Action::make('close')
            ->label('Close submissions')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Close submissions?')
            ->modalDescription('Authors can no longer submit or edit. The public page stays online and shows that submissions are closed.')
            ->visible(fn (Conference $record): bool => $record->status->canTransitionTo(ConferenceStatus::Closed)
                && Gate::allows('close', $record))
            ->action(function (Conference $record, CloseSubmissions $close): void {
                Gate::authorize('close', $record);

                /** @var User $actor */
                $actor = auth()->user();
                $close->handle($record, $actor);

                Notification::make()->warning()->title('Submissions are closed')->send();
            });
    }

    public static function archive(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Archive this conference?')
            ->modalDescription('The public page and the short link stop working. Nothing is deleted and you keep full access here. This cannot be undone.')
            ->visible(fn (Conference $record): bool => $record->status->canTransitionTo(ConferenceStatus::Archived)
                && Gate::allows('archive', $record))
            ->action(function (Conference $record, ArchiveConference $archive): void {
                Gate::authorize('archive', $record);

                /** @var User $actor */
                $actor = auth()->user();
                $archive->handle($record, $actor);

                Notification::make()->success()->title('Conference archived')->send();
            });
    }

    /** @return list<Action> */
    public static function all(): array
    {
        return [static::publish(), static::close(), static::archive()];
    }
}
```

- [ ] **Step 4: Write `app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceInfolist.php`**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Schemas;

use App\Actions\Conferences\PublishConference;
use App\Enums\ConferenceStatus;
use App\Models\Conference;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ConferenceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Publishing checklist')
                ->description('Everything below must be true before this conference can go live.')
                // blockers() is written for the publish action, so it also
                // reports "a conference that is open for submissions cannot be
                // opened for submissions". Ask first whether going live is even
                // on the table, or every live and archived conference shows a
                // red go-live checklist it can do nothing about.
                ->visible(fn (Conference $record): bool => $record->status->canTransitionTo(ConferenceStatus::Open)
                    && app(PublishConference::class)->blockers($record) !== [])
                ->components([
                    TextEntry::make('publishing_blockers')
                        ->hiddenLabel()
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->color('danger')
                        ->state(fn (Conference $record): array => app(PublishConference::class)->blockers($record)),
                ]),

            Section::make('Conference')->columns(2)->components([
                TextEntry::make('name'),
                TextEntry::make('status')->badge(),
                TextEntry::make('slug')->label('Public address')
                    ->url(fn (Conference $record): string => $record->publicUrl())
                    ->openUrlInNewTab()
                    ->formatStateUsing(fn (Conference $record): string => $record->publicUrl()),
                TextEntry::make('published_at')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->placeholder('Not published'),
                TextEntry::make('short_description')->columnSpanFull()->placeholder('-'),
            ]),

            // Every date-time entry names the conference timezone explicitly:
            // Filament otherwise formats in config('app.timezone'), which is
            // UTC, and the organizer would read a deadline three hours early
            // right next to the "Asia/Riyadh" line below it (spec section 10).
            Section::make('Dates')->columns(3)->components([
                TextEntry::make('starts_at')->date('j M Y')->placeholder('Not set'),
                TextEntry::make('ends_at')->date('j M Y')->placeholder('Not set'),
                TextEntry::make('timezone'),
                TextEntry::make('submission_opens_at')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->placeholder('Not set'),
                TextEntry::make('submission_deadline')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->placeholder('Not set'),
                TextEntry::make('review_deadline')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->placeholder('Not set'),
            ]),

            Section::make('Rules')->columns(3)->components([
                TextEntry::make('review_mode')->badge(),
                TextEntry::make('reviewers_per_submission')->label('Reviewers per abstract'),
                IconEntry::make('blind_review')->boolean()->label('Blind review'),
                TextEntry::make('word_limit')->suffix(' words'),
                TextEntry::make('max_files')->label('Files allowed'),
                TextEntry::make('allowed_file_types')->badge(),
                TextEntry::make('presentation_types')->badge()->columnSpanFull(),
                TextEntry::make('terms')->columnSpanFull()->placeholder('-'),
            ]),

            Section::make('Sharing')
                ->visible(fn (Conference $record): bool => $record->shortLink !== null)
                ->columns(2)
                ->components([
                    TextEntry::make('shortLink.code')->label('Short link')
                        ->formatStateUsing(fn (Conference $record): string => $record->shortLink?->url() ?? '-')
                        ->url(fn (Conference $record): ?string => $record->shortLink?->url())
                        ->openUrlInNewTab(),
                    TextEntry::make('shortLink.clicks')->label('Total scans')->badge(),
                ]),
        ]);
    }
}
```

- [ ] **Step 5: Write `app/Filament/Organizer/Resources/Conferences/Pages/ViewConference.php`**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Tables\ConferenceStatusActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewConference extends ViewRecord
{
    protected static string $resource = ConferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ...ConferenceStatusActions::all(),
        ];
    }
}
```

- [ ] **Step 6: Wire the view page and the infolist into the resource**

In `ConferenceResource.php` add the imports:

```php
use App\Filament\Organizer\Resources\Conferences\Pages\ViewConference;
use App\Filament\Organizer\Resources\Conferences\Schemas\ConferenceInfolist;
```

add this method after `form()`:

```php
    public static function infolist(Schema $schema): Schema
    {
        return ConferenceInfolist::configure($schema);
    }
```

and change `getPages()` to (the `view` route must come after `create` so `/create` is not swallowed by `/{record}`):

```php
    public static function getPages(): array
    {
        return [
            'index' => ListConferences::route('/'),
            'create' => CreateConference::route('/create'),
            'view' => ViewConference::route('/{record}'),
            'edit' => EditConference::route('/{record}/edit'),
        ];
    }
```

- [ ] **Step 7: Add the actions to the table and the edit page header**

In `Tables/ConferencesTable.php` add the imports `use Filament\Actions\ViewAction;` and `use App\Filament\Organizer\Resources\Conferences\Tables\ConferenceStatusActions;` is unnecessary (same namespace), then replace `recordActions([...])` with:

```php
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ConferenceStatusActions::publish(),
                ConferenceStatusActions::close(),
                ConferenceStatusActions::archive(),
                DeleteAction::make()->icon(Heroicon::OutlinedArchiveBox)
                    ->modalDescription('Soft delete: the conference and everything under it stay in the database and can be restored by the platform team.'),
            ])
```

In `Pages/EditConference.php` replace `getHeaderActions()` with:

```php
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ...ConferenceStatusActions::all(),
            DeleteAction::make(),
        ];
    }
```

adding the imports `use App\Filament\Organizer\Resources\Conferences\Tables\ConferenceStatusActions;` and `use Filament\Actions\ViewAction;`.

- [ ] **Step 8: Run the test to verify it passes**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferenceTransitionsTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -4 /tmp/t.log
```

Expected: `rc=0`, `13 passed`.

- [ ] **Step 9: Pint, Larastan, full suite, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?" && \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log && \
git add -A && git commit -q -m "feat: publish, close and archive actions with a publishing checklist on the view page

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

Expected: `stan rc=0`, `tests rc=0`, `153 passed`.

---

### Task 9: Tracks, custom fields and review questions as relation managers

**Files:**
- Create: `app/Filament/Organizer/Resources/Conferences/RelationManagers/{TracksRelationManager,CustomFieldsRelationManager,ReviewQuestionsRelationManager}.php`
- Modify: `ConferenceResource.php` (`getRelations()`)
- Test: `tests/Feature/Organizer/ConferenceRelationManagersTest.php`, `tests/Feature/Organizer/ReviewQuestionsRelationManagerTest.php`

- [ ] **Step 1: Write the two failing tests**

`tests/Feature/Organizer/ConferenceRelationManagersTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\CustomFieldType;
use App\Enums\OrganizationRole;
use App\Filament\Organizer\Resources\Conferences\Pages\EditConference;
use App\Filament\Organizer\Resources\Conferences\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Organizer\Resources\Conferences\RelationManagers\ReviewQuestionsRelationManager;
use App\Filament\Organizer\Resources\Conferences\RelationManagers\TracksRelationManager;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Track;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);
    $this->conference = Conference::factory()->for($this->organization)->create();
});

it('lists, creates, edits and reorders tracks', function () {
    $first = Track::factory()->for($this->conference)->create(['name' => 'Cardiology', 'sort' => 1]);
    $second = Track::factory()->for($this->conference)->create(['name' => 'Neurology', 'sort' => 2]);

    livewire(TracksRelationManager::class, [
        'ownerRecord' => $this->conference,
        'pageClass' => EditConference::class,
    ])
        ->assertCanSeeTableRecords([$first, $second])
        ->callTableAction('create', data: ['name' => 'Respiratory', 'description' => 'Airways and ventilation'])
        ->assertHasNoTableActionErrors()
        ->callTableAction('edit', $first, data: ['name' => 'Cardiology and haemodynamics'])
        ->assertHasNoTableActionErrors()
        ->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($this->conference->tracks()->pluck('name')->all())
        ->toContain('Respiratory')
        ->and($first->fresh()?->name)->toBe('Cardiology and haemodynamics')
        ->and($this->conference->tracks()->first()?->is($second))->toBeTrue();
});

it('deletes a track', function () {
    $track = Track::factory()->for($this->conference)->create();

    livewire(TracksRelationManager::class, [
        'ownerRecord' => $this->conference,
        'pageClass' => EditConference::class,
    ])->callTableAction('delete', $track);

    expect(Track::count())->toBe(0);
});

it('never shows another organization tracks', function () {
    $theirConference = withoutTenant(fn () => Conference::factory()->create());
    $theirTrack = withoutTenant(fn () => Track::factory()->for($theirConference)->create());
    $mine = Track::factory()->for($this->conference)->create();

    livewire(TracksRelationManager::class, [
        'ownerRecord' => $this->conference,
        'pageClass' => EditConference::class,
    ])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirTrack]);
});

it('refuses every relation manager mounted on a conference of another organization', function () {
    // Mounting the Livewire component directly bypasses the resource page's
    // tenant-scoped route binding, so canViewForRecord() has to check the
    // owner record itself - the child policies only ask whether the user
    // belongs to the *current* tenant. Filament aborts before mount when it
    // returns false, which Livewire::test() cannot observe, so assert the hook
    // directly on all three managers.
    $theirConference = withoutTenant(fn () => Conference::factory()->create());

    foreach ([TracksRelationManager::class, CustomFieldsRelationManager::class, ReviewQuestionsRelationManager::class] as $manager) {
        expect($manager::canViewForRecord($theirConference, EditConference::class))->toBeFalse()
            ->and($manager::canViewForRecord($this->conference, EditConference::class))->toBeTrue();
    }
});

it('cannot act on another organization tracks or fields from a mounted manager', function () {
    // The listing tests below can only ever pass, because the relationship
    // query is already scoped to this conference. The real surface is a
    // Livewire payload carrying a foreign record key, or a reorder over
    // foreign ids: both must resolve through the relationship and find
    // nothing.
    [$theirConference, $theirTrack, $theirField] = withoutTenant(function (): array {
        $conference = Conference::factory()->create();

        return [
            $conference,
            Track::factory()->for($conference)->create(['name' => 'Theirs', 'sort' => 7]),
            CustomField::factory()->for($conference)->create(['label' => 'Theirs']),
        ];
    });
    $mine = Track::factory()->for($this->conference)->create();

    livewire(TracksRelationManager::class, ['ownerRecord' => $this->conference, 'pageClass' => EditConference::class])
        ->mountTableAction('delete', $theirTrack)
        ->assertActionNotMounted()
        ->call('reorderTable', [$theirTrack->getKey(), $mine->getKey()]);

    livewire(CustomFieldsRelationManager::class, ['ownerRecord' => $this->conference, 'pageClass' => EditConference::class])
        ->mountTableAction('edit', $theirField)
        ->assertActionNotMounted();

    expect($theirTrack->fresh()?->sort)->toBe(7)
        ->and($theirTrack->fresh()?->name)->toBe('Theirs')
        ->and($theirField->fresh()?->label)->toBe('Theirs')
        ->and($theirConference->tracks()->count())->toBe(1);
});

it('never shows another organization custom fields', function () {
    $theirConference = withoutTenant(fn () => Conference::factory()->create());
    $theirField = withoutTenant(fn () => CustomField::factory()->for($theirConference)->create());
    $mine = CustomField::factory()->for($this->conference)->create();

    livewire(CustomFieldsRelationManager::class, [
        'ownerRecord' => $this->conference,
        'pageClass' => EditConference::class,
    ])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirField]);
});

it('keeps a sibling conference of the same organization out of each manager', function () {
    // Spec section 9 asks for cross-*conference* isolation as well as
    // cross-tenant: the tenancy scope does nothing here, only the relationship
    // does.
    $sibling = Conference::factory()->for($this->organization)->create();
    $siblingTrack = Track::factory()->for($sibling)->create();
    $siblingField = CustomField::factory()->for($sibling)->create();

    livewire(TracksRelationManager::class, ['ownerRecord' => $this->conference, 'pageClass' => EditConference::class])
        ->assertCanNotSeeTableRecords([$siblingTrack]);

    livewire(CustomFieldsRelationManager::class, ['ownerRecord' => $this->conference, 'pageClass' => EditConference::class])
        ->assertCanNotSeeTableRecords([$siblingField]);
});

it('creates a custom field and derives its storage key', function () {
    livewire(CustomFieldsRelationManager::class, [
        'ownerRecord' => $this->conference,
        'pageClass' => EditConference::class,
    ])
        ->callTableAction('create', data: [
            'label' => 'Funding source',
            'type' => CustomFieldType::Select->value,
            'options' => ['None', 'Institutional', 'Industry'],
            'required' => true,
        ])
        ->assertHasNoTableActionErrors();

    $field = CustomField::query()->firstOrFail();

    expect($field->key)->toBe('funding_source')
        ->and($field->conference_id)->toBe($this->conference->id)
        ->and($field->options)->toBe(['None', 'Institutional', 'Industry'])
        ->and($field->required)->toBeTrue();
});

it('keeps the custom field key fixed when the label changes', function () {
    $field = CustomField::factory()->for($this->conference)->create(['label' => 'Funding source']);

    livewire(CustomFieldsRelationManager::class, [
        'ownerRecord' => $this->conference,
        'pageClass' => EditConference::class,
    ])->callTableAction('edit', $field, data: ['label' => 'Source of funding']);

    $field->refresh();
    expect($field->label)->toBe('Source of funding')
        ->and($field->key)->toBe('funding_source');
});
```

`tests/Feature/Organizer/ReviewQuestionsRelationManagerTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Enums\OrganizationRole;
use App\Enums\ReviewQuestionType;
use App\Filament\Organizer\Resources\Conferences\Pages\EditConference;
use App\Filament\Organizer\Resources\Conferences\RelationManagers\ReviewQuestionsRelationManager;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\ReviewQuestion;
use App\Models\User;
use Filament\Forms\Components\Repeater;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);
    $this->conference = Conference::factory()->for($this->organization)->create();
    $this->form = app(CreateDefaultReviewForm::class)->handle($this->conference);
});

function reviewQuestionsManager(Conference $conference): object
{
    return livewire(ReviewQuestionsRelationManager::class, [
        'ownerRecord' => $conference,
        'pageClass' => EditConference::class,
    ]);
}

it('shows the nine template questions in order', function () {
    reviewQuestionsManager($this->conference)
        ->assertCanSeeTableRecords($this->form->questions()->get())
        ->assertSee('Originality and Innovation: How original and innovative is the research presented in the abstract?');
});

it('adds a question to the active form', function () {
    reviewQuestionsManager($this->conference)
        ->callTableAction('create', data: [
            'prompt' => 'Is the abstract free of identifying information?',
            'type' => ReviewQuestionType::Boolean->value,
            'weight' => '0.50',
            'required' => false,
        ])
        ->assertHasNoTableActionErrors();

    $question = ReviewQuestion::query()->where('prompt', 'Is the abstract free of identifying information?')->firstOrFail();

    expect($question->review_form_id)->toBe($this->form->id)
        ->and($question->type)->toBe(ReviewQuestionType::Boolean)
        ->and($question->weight)->toBe('0.50')
        // Appended, not first: Filament sets no sort on create, so the model
        // hook has to (spec section 3).
        ->and($question->sort)->toBe(10)
        ->and($this->form->questions()->count())->toBe(10);
});

it('stores the likert scale and the scored choices of a select question', function () {
    // Both fields are behind a `visible()` closure that reads $get('type').
    // Comparing that with ->value instead of the enum case would keep them
    // hidden for ever, and a hidden field is not dehydrated - the questions
    // would be saved with NULL scale bounds and NULL choices, which Plan 5
    // could not score and a locked form could never repair.
    $undo = Repeater::fake();

    try {
        reviewQuestionsManager($this->conference)
            ->callTableAction('create', data: [
                'prompt' => 'Overall quality',
                'type' => ReviewQuestionType::Likert->value,
                'weight' => '2.00',
                'scale_min' => 1,
                'scale_max' => 7,
                'required' => true,
            ])
            ->assertHasNoTableActionErrors()
            ->callTableAction('create', data: [
                'prompt' => 'Preferred format',
                'type' => ReviewQuestionType::Select->value,
                'weight' => '1.00',
                'options' => [
                    ['label' => 'Oral', 'score' => 100],
                    ['label' => 'Poster', 'score' => 50],
                    ['label' => 'Either', 'score' => null],
                ],
                'required' => true,
            ])
            ->assertHasNoTableActionErrors();
    } finally {
        $undo();
    }

    $likert = ReviewQuestion::query()->where('prompt', 'Overall quality')->firstOrFail();
    $select = ReviewQuestion::query()->where('prompt', 'Preferred format')->firstOrFail();

    expect($likert->scale_min)->toBe(1)
        ->and($likert->scale_max)->toBe(7)
        ->and($likert->weight)->toBe('2.00')
        ->and(array_column($select->options ?? [], 'label'))->toBe(['Oral', 'Poster', 'Either'])
        ->and((int) ($select->options[0]['score'] ?? 0))->toBe(100)
        ->and($select->options[2]['score'] ?? 'missing')->toBeNull()
        ->and($select->isScored())->toBeTrue();
});

it('edits, reorders and deletes questions while the form is unlocked', function () {
    $questions = $this->form->questions()->get();
    $first = $questions->first();
    $second = $questions->get(1);

    reviewQuestionsManager($this->conference)
        ->callTableAction('edit', $first, data: ['prompt' => 'Originality of the work'])
        ->assertHasNoTableActionErrors()
        ->call('reorderTable', [$second->getKey(), $first->getKey()])
        ->callTableAction('delete', $questions->last());

    expect($first->fresh()?->prompt)->toBe('Originality of the work')
        ->and($this->form->questions()->first()?->is($second))->toBeTrue()
        ->and($this->form->questions()->count())->toBe(8);
});

it('creates the form on demand for a conference that has none', function () {
    $bare = Conference::factory()->for($this->organization)->create();
    $bare->reviewForms()->delete();

    reviewQuestionsManager($bare)->assertSee('Review form');

    expect($bare->fresh()?->reviewForm?->questions()->count())->toBe(9);
});

it('locks editing, reordering and deleting once reviews exist', function () {
    $this->form->forceFill(['locked_at' => now()])->save();
    $question = $this->form->questions()->first();

    reviewQuestionsManager($this->conference->fresh())
        ->assertSee('Locked')
        ->assertTableActionHidden('edit', $question)
        ->assertTableActionHidden('delete', $question)
        ->assertTableActionVisible('create');

    expect($this->user->can('update', $question))->toBeFalse()
        ->and($this->user->can('delete', $question))->toBeFalse()
        ->and($this->user->can('create', ReviewQuestion::class))->toBeTrue();
});

it('refuses to reorder questions once the form is locked', function () {
    // Filament reorders with a query-builder UPDATE, which fires no model
    // events, so ReviewQuestion's `updating` guard never runs and
    // ReviewQuestionPolicy::reorder() cannot see the lock either (it gets no
    // record). `reorderable(null)` is the only thing holding this.
    $this->form->forceFill(['locked_at' => now()])->save();
    [$first, $second] = $this->form->questions()->take(2)->get()->all();

    reviewQuestionsManager($this->conference->fresh())
        ->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($first->fresh()?->sort)->toBe(1)
        ->and($second->fresh()?->sort)->toBe(2);
});

it('still appends a question to a locked form', function () {
    $this->form->forceFill(['locked_at' => now()])->save();

    reviewQuestionsManager($this->conference->fresh())
        ->callTableAction('create', data: [
            'prompt' => 'Any additional comment for the committee?',
            'type' => ReviewQuestionType::Text->value,
            'required' => false,
        ])
        ->assertHasNoTableActionErrors();

    // Appended at the end, where a reviewer expects a new question - and where
    // it has to be, because reordering is disabled on a locked form.
    expect($this->form->questions()->count())->toBe(10)
        ->and($this->form->questions()->get()->last()?->prompt)->toBe('Any additional comment for the committee?')
        ->and($this->form->questions()->get()->last()?->sort)->toBe(10);
});

it('does not show or mount the questions of another organization conference', function () {
    $theirConference = withoutTenant(function (): Conference {
        $conference = Conference::factory()->create();
        app(CreateDefaultReviewForm::class)->handle($conference);

        return $conference;
    });

    $mine = $this->form->questions()->first();
    $theirs = $theirConference->reviewForm?->questions()->first();

    reviewQuestionsManager($this->conference)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->mountTableAction('edit', $theirs)
        ->assertActionNotMounted()
        ->mountTableAction('delete', $theirs)
        ->assertActionNotMounted();

    expect(ReviewQuestionsRelationManager::canViewForRecord($theirConference, EditConference::class))->toBeFalse()
        ->and($theirs?->fresh()?->prompt)->toBe($theirs?->prompt);
});
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferenceRelationManagersTest.php tests/Feature/Organizer/ReviewQuestionsRelationManagerTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found|does not exist|Unable to find component" /tmp/t.log | head -3
```

Expected: `rc=1`, message `Unable to find component: [App\Filament\Organizer\Resources\Conferences\RelationManagers\TracksRelationManager]`.

- [ ] **Step 3: Write `RelationManagers/TracksRelationManager.php`**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\RelationManagers;

use App\Models\Conference;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TracksRelationManager extends RelationManager
{
    protected static string $relationship = 'tracks';

    protected static ?string $title = 'Tracks';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedTag;

    /**
     * A relation manager's Livewire component can be mounted directly, which
     * bypasses the resource page's tenant-scoped route binding, so the owner
     * record's own organization is checked here as well as the model policy.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $ownerRecord instanceof Conference
            && $user->roleIn($ownerRecord->organization) !== null
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120),
            Textarea::make('description')->rows(2)->maxLength(500)
                ->helperText('Shown next to the track name on the public page.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('description')->limit(60)->placeholder('-'),
            ])
            ->headerActions([
                CreateAction::make()->label('Add track'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No tracks')
            ->emptyStateDescription('Tracks are optional. Add them if authors should pick a theme when they submit.');
    }
}
```

- [ ] **Step 4: Write `RelationManagers/CustomFieldsRelationManager.php`**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\RelationManagers;

use App\Enums\CustomFieldType;
use App\Models\Conference;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CustomFieldsRelationManager extends RelationManager
{
    protected static string $relationship = 'customFields';

    protected static ?string $title = 'Extra submission fields';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClipboardDocumentList;

    /** Same direct-mount guard as TracksRelationManager. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $ownerRecord instanceof Conference
            && $user->roleIn($ownerRecord->organization) !== null
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')->required()->maxLength(120)
                ->helperText('What the author sees above the field.'),
            TextInput::make('key')->disabled()->dehydrated(false)->visibleOn('edit')
                ->helperText('The storage key. Fixed after creation so answers already submitted keep their meaning.'),
            Select::make('type')->options(CustomFieldType::class)->required()->live()
                ->default(CustomFieldType::Text->value),
            // `options(CustomFieldType::class)` casts the state, so $get()
            // returns the enum case (fact 14). Comparing with ->value would
            // hide this for ever and the choices would be saved as NULL.
            TagsInput::make('options')->label('Choices')
                ->required(fn (Get $get): bool => $get('type') === CustomFieldType::Select)
                ->visible(fn (Get $get): bool => $get('type') === CustomFieldType::Select)
                ->helperText('Press Enter after each choice.'),
            TextInput::make('help_text')->maxLength(500)->columnSpanFull(),
            Toggle::make('required')->label('Authors must answer this'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('label')->searchable(),
                TextColumn::make('key')->fontFamily(FontFamily::Mono)->color('gray'),
                TextColumn::make('type')->badge(),
                IconColumn::make('required')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->label('Add field'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Answers already given for this field stay in the database but stop being displayed.'),
            ])
            ->emptyStateHeading('No extra fields')
            ->emptyStateDescription('Title, abstract, authors and files are always collected. Add a field here only for something else you need.');
    }
}
```

- [ ] **Step 5: Write `RelationManagers/ReviewQuestionsRelationManager.php`**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\RelationManagers;

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Enums\ReviewQuestionType;
use App\Models\Conference;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewQuestionsRelationManager extends RelationManager
{
    /**
     * Only used to resolve the related model class for authorization
     * (`Conference::reviewQuestions()` is a read-only HasManyThrough); every
     * read and write goes through getRelationship() below.
     */
    protected static string $relationship = 'reviewQuestions';

    protected static ?string $title = 'Review form';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedListBullet;

    /** Same direct-mount guard as TracksRelationManager. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $ownerRecord instanceof Conference
            && $user->roleIn($ownerRecord->organization) !== null
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    /**
     * Writes must land on the conference's single active review form, so the
     * relation manager works against that HasMany rather than the
     * HasManyThrough. A conference created outside the panel (a factory, the
     * Plan 6 import) may not have a form yet; CreateDefaultReviewForm is
     * idempotent and gives it the standard template.
     *
     * The return type is narrowed to the concrete relation (covariant with the
     * parent's `Relation|Builder`) because the parent has no PHPDoc to inherit
     * and a bare `Relation` fails Larastan level 6 with missingType.generics.
     *
     * @return HasMany<ReviewQuestion, ReviewForm>
     */
    public function getRelationship(): HasMany
    {
        return $this->activeReviewForm()->questions();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('prompt')->required()->rows(3)->maxLength(1000)->columnSpanFull(),
            TextInput::make('help_text')->maxLength(500)->columnSpanFull()
                ->helperText('Optional guidance shown under the question.'),
            Select::make('type')->options(ReviewQuestionType::class)->required()->live()
                ->default(ReviewQuestionType::Likert->value),
            TextInput::make('weight')->numeric()->required()->default('1.00')
                ->minValue(0)->maxValue(999.99)->step(0.25)
                ->helperText('Relative importance when the score is averaged. Text answers are never scored.'),
            // Every closure below compares against the enum *case*: the Select
            // above casts its state, so $get('type') is never the backing
            // string (fact 14), and a permanently hidden field is not
            // dehydrated - Likert questions would be stored with NULL scale
            // bounds and select questions with NULL choices.
            TextInput::make('scale_min')->numeric()->minValue(0)->maxValue(10)->default(1)
                ->required(fn (Get $get): bool => $get('type') === ReviewQuestionType::Likert)
                ->visible(fn (Get $get): bool => $get('type') === ReviewQuestionType::Likert),
            TextInput::make('scale_max')->numeric()->minValue(1)->maxValue(10)->default(5)
                ->required(fn (Get $get): bool => $get('type') === ReviewQuestionType::Likert)
                ->visible(fn (Get $get): bool => $get('type') === ReviewQuestionType::Likert)
                ->gt('scale_min'),
            // Spec 5.6 gives each select choice an optional score, which a
            // plain TagsInput cannot hold - and once Plan 4 locks the form,
            // existing questions can never be edited to add one.
            Repeater::make('options')->label('Choices')
                ->schema([
                    TextInput::make('label')->required()->maxLength(200),
                    TextInput::make('score')->numeric()->minValue(0)->maxValue(100)
                        ->helperText('Optional, 0-100. A choice without a score does not count towards the review score.'),
                ])
                ->columns(2)
                ->minItems(2)
                ->defaultItems(2)
                ->reorderable()
                ->required(fn (Get $get): bool => $get('type') === ReviewQuestionType::Select)
                ->visible(fn (Get $get): bool => $get('type') === ReviewQuestionType::Select)
                ->columnSpanFull(),
            Toggle::make('required')->default(true)->label('Reviewers must answer this'),
        ]);
    }

    public function table(Table $table): Table
    {
        $locked = $this->activeReviewForm()->isLocked();

        return $table
            ->recordTitleAttribute('prompt')
            ->heading($locked ? 'Review form (Locked)' : 'Review form')
            ->description($locked
                ? 'Reviews have been submitted, so existing questions can no longer be changed, reordered or removed. You can still add a new question.'
                : 'These questions are what every reviewer answers. Edit, reorder or replace them before reviews start.')
            ->defaultSort('sort')
            ->reorderable($locked ? null : 'sort')
            ->columns([
                TextColumn::make('prompt')->wrap()->limit(120)->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('scale')->label('Scale')
                    ->state(fn (ReviewQuestion $record): string => $record->type === ReviewQuestionType::Likert
                        ? "{$record->scale_min}-{$record->scale_max}"
                        : '-'),
                TextColumn::make('weight'),
                IconColumn::make('required')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->label('Add question'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No questions')
            ->emptyStateDescription('A conference cannot be published until the review form has at least one question.');
    }

    private function activeReviewForm(): ReviewForm
    {
        /** @var Conference $conference */
        $conference = $this->getOwnerRecord();

        $form = $conference->reviewForm()->first();

        if ($form instanceof ReviewForm) {
            return $form;
        }

        return app(CreateDefaultReviewForm::class)->handle($conference);
    }
}
```

> `EditAction` and `DeleteAction` hide themselves when `ReviewQuestionPolicy::update()` / `delete()` return false, which is exactly what the lock does. `reorderable(null)` removes the drag handles **and** makes `reorderTable()` a no-op on the server (`Table::isReorderable()` requires a filled reorder column); it is the only lock on reordering, because Filament reorders with a query-builder `update()` that fires no model events, and `ReviewQuestionPolicy::reorder()` receives no record so it cannot see the lock either. `ReviewQuestion`'s `updating`/`deleting` model hooks cover edits and deletes made outside the panel.

- [ ] **Step 6: Register the relation managers**

In `ConferenceResource.php` add the imports:

```php
use App\Filament\Organizer\Resources\Conferences\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Organizer\Resources\Conferences\RelationManagers\ReviewQuestionsRelationManager;
use App\Filament\Organizer\Resources\Conferences\RelationManagers\TracksRelationManager;
```

and this method after `getEloquentQuery()`:

```php
    public static function getRelations(): array
    {
        return [
            TracksRelationManager::class,
            CustomFieldsRelationManager::class,
            ReviewQuestionsRelationManager::class,
        ];
    }
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferenceRelationManagersTest.php tests/Feature/Organizer/ReviewQuestionsRelationManagerTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -4 /tmp/t.log
```

Expected: `rc=0`, `18 passed`.

- [ ] **Step 8: Pint, Larastan, full suite, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?" && \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log && \
git add -A && git commit -q -m "feat: tracks, custom fields and review question relation managers with the form lock

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

Expected: `stan rc=0`, `tests rc=0`, `171 passed`.

---

### Task 10: QR code, poster PDF, download routes and the sharing page

**Files:**
- Create: `app/Enums/PosterSize.php`, `app/Actions/Conferences/GenerateConferenceQr.php`, `app/Actions/Conferences/GenerateConferencePoster.php`
- Create: `resources/views/pdf/conference-poster.blade.php`
- Create: `app/Http/Controllers/Organizer/ConferenceAssetController.php`
- Create: `app/Filament/Organizer/Resources/Conferences/Pages/ConferenceShortLink.php`, `resources/views/filament/organizer/resources/conferences/pages/short-link.blade.php`
- Modify: `routes/web.php`, `app/Providers/AppServiceProvider.php` (asset rate limiter), `ConferenceResource.php` (`short-link` page), `Tables/ConferenceStatusActions.php` (`share()`, `all()`), `Tables/ConferencesTable.php` (share row action)
- Test: `tests/Unit/ConferenceQrTest.php`, `tests/Feature/Organizer/ConferenceAssetsTest.php`

`Pages/ViewConference.php` needs no change: it already spreads `ConferenceStatusActions::all()`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ConferenceQrTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Conferences\GenerateConferencePoster;
use App\Actions\Conferences\GenerateConferenceQr;
use App\Enums\PosterSize;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\ShortLink;
use chillerlan\QRCode\QRCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

uses(RefreshDatabase::class);

function publishedConferenceWithLink(): Conference
{
    $conference = Conference::factory()
        ->for(Organization::factory()->approved())
        ->published()
        ->create(['name' => 'Gulf Pediatric Critical Care 2026']);

    ShortLink::forTarget($conference);

    return $conference->fresh() ?? $conference;
}

it('renders an svg that encodes the short link', function () {
    $conference = publishedConferenceWithLink();

    $svg = app(GenerateConferenceQr::class)->svg($conference);

    // The fill attributes are the whole point: chillerlan draws the light
    // modules and the quiet zone too, so an SVG without them is a solid black
    // square that no phone can read - and every other assertion here still
    // passes.
    expect($svg)->toStartWith('<?xml')
        ->and($svg)->toContain('<svg')
        ->and($svg)->toContain('viewBox')
        ->and($svg)->toContain('fill="#000"')
        ->and($svg)->toContain('fill="#fff"')
        ->and($svg)->not->toContain('base64');
});

it('renders a png of at least the configured size', function () {
    $conference = publishedConferenceWithLink();

    $png = app(GenerateConferenceQr::class)->png($conference, 1024);
    $size = getimagesizefromstring($png);

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and($size)->not->toBeFalse()
        ->and($size[0])->toBeGreaterThanOrEqual(1024)
        ->and($size[0])->toBe($size[1]);
});

it('encodes exactly the short link in the png', function () {
    // Decode what a phone would decode: without this, encoding the long
    // conference URL (or the wrong conference) would pass every other test.
    $conference = publishedConferenceWithLink();

    $png = app(GenerateConferenceQr::class)->png($conference, 1024);

    expect((string) (new QRCode)->readFromBlob($png))->toBe($conference->shortLink?->url());
});

it('encodes the absolute short link url', function () {
    $conference = publishedConferenceWithLink();

    expect(app(GenerateConferenceQr::class)->url($conference))
        ->toBe($conference->shortLink?->url())
        ->toStartWith(config('app.url'));
});

it('refuses to render a qr for a conference with no short link', function () {
    $conference = Conference::factory()->create();

    expect(fn () => app(GenerateConferenceQr::class)->svg($conference))
        ->toThrow(RuntimeException::class);
});

it('renders an a4 and an a3 poster pdf carrying the logo and the deadline', function () {
    Storage::fake('branding');
    $conference = publishedConferenceWithLink();
    $path = UploadedFile::fake()->image('logo.png', 400, 200)->store('logos', 'branding');
    $conference->organization->forceFill(['logo_path' => $path])->save();

    foreach ([PosterSize::A4, PosterSize::A3] as $size) {
        $pdf = app(GenerateConferencePoster::class)->handle($conference->fresh() ?? $conference, $size);

        expect(substr($pdf, 0, 5))->toBe('%PDF-')
            ->and(strlen($pdf))->toBeGreaterThan(5_000);
    }
});

it('renders a poster for an organization that has no logo', function () {
    Storage::fake('branding');
    $conference = publishedConferenceWithLink();

    $pdf = app(GenerateConferencePoster::class)->handle($conference, PosterSize::A4);

    expect(substr($pdf, 0, 5))->toBe('%PDF-');
});

it('puts the logo, name, call to action, short url and deadline on the poster', function () {
    // A PDF header and a byte count say nothing about the content, so capture
    // what the template was actually given and render it as HTML (spec 5.7
    // names each of these).
    Storage::fake('branding');
    $conference = publishedConferenceWithLink();
    $path = UploadedFile::fake()->image('logo.png', 400, 200)->store('logos', 'branding');
    $conference->organization->forceFill(['logo_path' => $path])->save();
    $conference = $conference->fresh() ?? $conference;

    $data = null;
    View::composer('pdf.conference-poster', function ($view) use (&$data): void {
        $data = $view->getData();
    });

    app(GenerateConferencePoster::class)->handle($conference, PosterSize::A4);
    $html = view('pdf.conference-poster', $data)->render();

    expect($data['logoDataUri'])->toStartWith('data:image/jpeg;base64,')
        ->and($html)->toContain($conference->name)
        ->and($html)->toContain('Submit your abstract')
        ->and($html)->toContain((string) $conference->shortLink?->url())
        ->and($html)->toContain((string) $conference->deadlineInConferenceTimezone()?->format('j F Y, H:i'))
        ->and($html)->toContain('IBM Plex Mono');
});

it('normalises a large transparent logo before dompdf sees it', function () {
    // dompdf has no imagick here, so every PNG with an alpha channel goes
    // through CPDF::addImagePngAlpha(), which decodes at full size with GD and
    // loops over every pixel in PHP (about 0.8 s and tens of MB per megapixel).
    // A 2 MB flat-colour logo can be many megapixels, which is a 500 on the
    // 256 MB php-fpm worker every tenant shares.
    Storage::fake('branding');
    $conference = publishedConferenceWithLink();

    // 1600x800 is enough to prove the downscale; GD needs about 8 bytes per
    // pixel to decode a PNG, so a 4000x2000 fixture would exhaust the local
    // 128M memory_limit inside the test itself.
    $image = imagecreatetruecolor(1600, 800);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    unset($image);

    Storage::disk('branding')->put('logos/big.png', $png);
    $conference->organization->forceFill(['logo_path' => 'logos/big.png'])->save();

    $uri = (string) app(GenerateConferencePoster::class)->logoDataUri($conference->fresh() ?? $conference);
    $size = getimagesizefromstring((string) base64_decode(substr($uri, strlen('data:image/jpeg;base64,'))));

    // IHDR colour type 6 = RGBA, the case that triggers the slow path.
    expect(ord($png[25]))->toBe(6)
        ->and($uri)->toStartWith('data:image/jpeg;base64,')
        ->and($size[0] ?? 0)->toBe(660)
        ->and($size[1] ?? 0)->toBe(330);
});

it('names the download files after the conference slug', function () {
    $conference = publishedConferenceWithLink();

    expect(app(GenerateConferenceQr::class)->fileName($conference, 'svg'))
        ->toBe('gulf-pediatric-critical-care-2026-qr.svg')
        ->and(app(GenerateConferencePoster::class)->fileName($conference, PosterSize::A3))
        ->toBe('gulf-pediatric-critical-care-2026-poster-a3.pdf');
});
```

`tests/Feature/Organizer/ConferenceAssetsTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceShortLink;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\ShortLink;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Member);
    $this->conference = Conference::factory()->for($this->organization)->published()->create();
    ShortLink::forTarget($this->conference);
    $this->conference->refresh();
});

dataset('conference asset routes', [
    'qr svg' => ['conference-assets.qr.svg', []],
    'qr png' => ['conference-assets.qr.png', []],
    'poster a4' => ['conference-assets.poster', ['size' => 'a4']],
    'poster a3' => ['conference-assets.poster', ['size' => 'a3']],
]);

it('sends guests on every asset route to the organizer login', function (string $name, array $extra) {
    get(route($name, ['conference' => $this->conference, ...$extra]))
        ->assertRedirect('/org/login');
})->with('conference asset routes');

it('refuses every asset route to a member of another organization', function (string $name, array $extra) {
    // Each controller action calls authorizeDownload() separately, so testing
    // only the SVG would let a missing Gate call on the PNG or the poster ship.
    $outsider = User::factory()->create();
    Organization::factory()->approved()->create()->addMember($outsider, OrganizationRole::Owner);

    actingAs($outsider)->get(route($name, ['conference' => $this->conference, ...$extra]))
        ->assertForbidden();
})->with('conference asset routes');

it('sends an unverified member to the email verification prompt', function () {
    // The organizer panel runs Filament's EnsureEmailIsVerified on every tenant
    // route; these downloads live outside the panel and repeat it.
    $unverified = User::factory()->unverified()->create();
    $this->organization->addMember($unverified, OrganizationRole::Member);

    actingAs($unverified)->get(route('conference-assets.qr.svg', $this->conference))
        ->assertRedirect('/org/email-verification/prompt');
});

it('rate limits asset downloads per account', function () {
    // Every poster is a synchronous dompdf render on the four-worker php-fpm
    // pool that serves every tenant, so one member must not be able to occupy
    // it. Ten cheap SVG requests spend the budget without rendering a PDF.
    actingAs($this->user);

    foreach (range(1, 10) as $ignored) {
        get(route('conference-assets.qr.svg', $this->conference))->assertOk();
    }

    get(route('conference-assets.poster', ['conference' => $this->conference, 'size' => 'a4']))
        ->assertStatus(429);
});

it('serves the svg, the png and both poster sizes to a member', function () {
    actingAs($this->user);

    get(route('conference-assets.qr.svg', $this->conference))
        ->assertOk()
        ->assertHeader('content-type', 'image/svg+xml');

    get(route('conference-assets.qr.png', $this->conference))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');

    foreach (['a4', 'a3'] as $size) {
        get(route('conference-assets.poster', ['conference' => $this->conference, 'size' => $size]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
});

it('offers the files as downloads named after the conference', function () {
    actingAs($this->user);

    get(route('conference-assets.qr.png', $this->conference))
        ->assertDownload($this->conference->slug.'-qr.png');
});

it('returns 404 before the conference is published and has a short link', function () {
    $draft = Conference::factory()->for($this->organization)->create();

    actingAs($this->user)->get(route('conference-assets.qr.svg', $draft))->assertNotFound();
});

it('rejects a poster size that is not a4 or a3', function () {
    actingAs($this->user);

    get('/conference-assets/'.$this->conference->ulid.'/poster/a4')->assertOk();
    get('/conference-assets/'.$this->conference->ulid.'/poster/a5')->assertNotFound();
});

it('shows total scans and a thirty day breakdown on the sharing page', function () {
    // A bare assertSee('2') matches almost anything on a Filament page (icon
    // path data, dates, Livewire ids), so assert the rendered markup instead.
    $this->travelTo(now()->setTime(12, 0));
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $link = $this->conference->shortLink;
    $link->visits()->create(['visited_at' => now()]);
    $link->visits()->create(['visited_at' => now()->subDays(3)]);
    $link->forceFill(['clicks' => 4817])->save();

    livewire(ConferenceShortLink::class, ['record' => $this->conference->getRouteKey()])
        ->assertSee($link->code)
        ->assertSee('Total scans')
        ->assertSee('4,817')
        ->assertSee('Last 30 days')
        ->assertSeeHtml('title="'.now()->toDateString().': 1"')
        ->assertSeeHtml('title="'.now()->subDays(3)->toDateString().': 1"')
        ->assertSeeHtml('title="'.now()->subDay()->toDateString().': 0"');
});

it('opens the sharing page through the URL Filament generates', function () {
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    get(ConferenceResource::getUrl('short-link', ['record' => $this->conference]))->assertOk();
});

it('does not open the sharing page for another organization conference', function () {
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $theirs = withoutTenant(fn () => Conference::factory()->published()->create());

    $this->get(ConferenceResource::getUrl(
        'short-link', ['record' => $theirs->getRouteKey()], tenant: $this->organization
    ))->assertNotFound();
});
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ConferenceQrTest.php tests/Feature/Organizer/ConferenceAssetsTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found|does not exist|Unable to find component" /tmp/t.log | head -3
```

Expected: `rc=1`. The first test resolves the QR action out of the container, so the message is `Target class [App\Actions\Conferences\GenerateConferenceQr] does not exist.`

- [ ] **Step 3: Write `app/Enums/PosterSize.php`**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PosterSize: string implements HasLabel
{
    case A4 = 'a4';
    case A3 = 'a3';

    public function getLabel(): string
    {
        return match ($this) {
            self::A4 => 'A4 poster (210 x 297 mm)',
            self::A3 => 'A3 poster (297 x 420 mm)',
        };
    }

    /** dompdf paper name. */
    public function paper(): string
    {
        return $this->value;
    }

    /**
     * Point sizes for the poster type scale, so an A3 print is not just an A4
     * scaled by the printer driver.
     *
     * @return array{title: int, lead: int, body: int, qr: int}
     */
    public function typeScale(): array
    {
        return match ($this) {
            self::A4 => ['title' => 30, 'lead' => 16, 'body' => 11, 'qr' => 250],
            self::A3 => ['title' => 44, 'lead' => 23, 'body' => 15, 'qr' => 360],
        };
    }
}
```

- [ ] **Step 4: Write `app/Actions/Conferences/GenerateConferenceQr.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Models\Conference;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use RuntimeException;

class GenerateConferenceQr
{
    /**
     * The QR encodes the short link, never the long conference URL: it keeps
     * the code sparse enough to survive a phone camera at poster distance, and
     * it means every scan is counted.
     */
    public function url(Conference $conference): string
    {
        $shortLink = $conference->shortLink;

        if ($shortLink === null) {
            throw new RuntimeException('This conference has no short link yet. Publish it first.');
        }

        return $shortLink->url();
    }

    /**
     * `svgUseFillAttributes` stays at its default (true): with it false every
     * layer is a `<path class="..." d="..."/>` with no fill and no `<style>`,
     * and because `drawLightModules` also defaults to true the light modules
     * and the quiet zone would be painted black by SVG's default fill - a
     * solid black square no phone can read. This file is the print master.
     */
    public function svg(Conference $conference): string
    {
        /** @var string $svg */
        $svg = (new QRCode($this->options(QROutputInterface::MARKUP_SVG, ['cssClass' => 'cass-qr'])))
            ->render($this->url($conference));

        return $svg;
    }

    /**
     * chillerlan renders whole modules only, so the result is the smallest
     * whole-module square that reaches $minSize (typically 1025-1100 px for a
     * 1024 px request). Resampling to exactly 1024 would blur the modules for
     * no benefit, and print drivers scale vectors from the SVG anyway.
     *
     * The matrix is built once and re-rendered by a second QRCode instance at
     * the computed scale; `getQRMatrix()` has already added the quiet zone.
     */
    public function png(Conference $conference, ?int $minSize = null): string
    {
        $minSize ??= (int) config('cass.qr.png_min_size');

        $matrix = (new QRCode($this->options(QROutputInterface::GDIMAGE_PNG)))
            ->addByteSegment($this->url($conference))
            ->getQRMatrix();

        $scale = max(1, (int) ceil($minSize / $matrix->getSize()));

        /** @var string $png */
        $png = (new QRCode($this->options(QROutputInterface::GDIMAGE_PNG, ['scale' => $scale])))
            ->renderMatrix($matrix);

        return $png;
    }

    public function fileName(Conference $conference, string $extension): string
    {
        return "{$conference->slug}-qr.{$extension}";
    }

    /**
     * Every setting goes in the constructor array. QROptions properties are
     * protected and reachable only through SettingsContainerAbstract::__set,
     * so assigning one afterwards works at runtime but fails Larastan level 6
     * with property.protected (fact 2).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function options(string $outputType, array $overrides = []): QROptions
    {
        return new QROptions([
            'outputType' => $outputType,
            // Default true in v5: it would return a data: URI instead of bytes.
            'outputBase64' => false,
            // M survives a printed poster with a fingerprint on it and still
            // keeps a 37-character URL inside a small version.
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 4,
            'scale' => 10,
            'imageTransparent' => false,
            ...$overrides,
        ]);
    }
}
```

- [ ] **Step 5: Write `app/Actions/Conferences/GenerateConferencePoster.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\PosterSize;
use App\Models\Conference;
use App\Support\Branding\OrganizationTheme;
use App\Support\Pdf\PosterFonts;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class GenerateConferencePoster
{
    public function __construct(private readonly GenerateConferenceQr $qr) {}

    /**
     * Returns the PDF bytes. Both images are inlined as data: URIs, so dompdf
     * reads nothing from disk except the two bundled TTFs, and the file is
     * self-contained when it is emailed to a print shop.
     */
    public function handle(Conference $conference, PosterSize $size): string
    {
        $pdf = Pdf::loadView('pdf.conference-poster', [
            'conference' => $conference,
            'organization' => $conference->organization,
            'theme' => OrganizationTheme::for($conference->organization),
            'size' => $size,
            'scale' => $size->typeScale(),
            'qrDataUri' => 'data:image/png;base64,'.base64_encode($this->qr->png($conference, 1200)),
            'logoDataUri' => $this->logoDataUri($conference),
            'shortUrl' => $this->qr->url($conference),
        ]);

        PosterFonts::register($pdf->getDomPDF());

        return $pdf->setPaper($size->paper(), 'portrait')->output();
    }

    public function fileName(Conference $conference, PosterSize $size): string
    {
        return "{$conference->slug}-poster-{$size->value}.pdf";
    }

    /**
     * Public so a test can assert what dompdf is handed.
     *
     * The upload rules accept any PNG or JPEG up to 2 MB with no dimension
     * limit, and the branding help text recommends a transparent PNG - but
     * dompdf's CPDF backend has no Imagick here, so every PNG with an alpha
     * channel goes through addImagePngAlpha(), which decodes at full size with
     * GD and then loops over every pixel in PHP (roughly 0.8 s and tens of MB
     * per megapixel). A flat-colour 6000x3500 logo fits inside 2 MB and would
     * blow the 256 MB php-fpm worker that every tenant's pages share.
     *
     * So the logo is normalised here instead: refused above 16 MP, scaled to
     * the height the template uses, flattened onto white and handed over as
     * JPEG, which dompdf embeds with addJpegFromFile and never decodes.
     */
    public function logoDataUri(Conference $conference): ?string
    {
        $path = $conference->organization->logo_path;

        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('branding');

        if (! $disk->exists($path)) {
            return null;
        }

        $bytes = (string) $disk->get($path);
        $size = getimagesizefromstring($bytes);

        // 16 MP is already about 128 MB of GD buffers to decode; a logo that
        // big is a mistake, and the poster is better off without it than
        // returning a 500.
        if ($size === false || ($size[0] * $size[1]) > 16_000_000) {
            return null;
        }

        $source = imagecreatefromstring($bytes);

        if ($source === false) {
            return null;
        }

        $targetHeight = min(330, imagesy($source));
        $targetWidth = max(1, (int) round(imagesx($source) * $targetHeight / imagesy($source)));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($source), imagesy($source));
        imagedestroy($source);

        ob_start();
        imagejpeg($canvas, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($canvas);

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }
}
```

- [ ] **Step 6: Write `resources/views/pdf/conference-poster.blade.php`**

dompdf has no flexbox or grid, so the layout is a single centred column with fixed point sizes.

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body {
            margin: 0;
            font-family: 'IBM Plex Sans', 'DejaVu Sans', sans-serif;
            color: #111827;
            text-align: center;
        }
        .sheet { padding: 48px 40px; }
        .rule { height: 10px; background: {{ $theme->primary }}; }
        .logo { max-height: {{ $size === App\Enums\PosterSize::A3 ? 110 : 78 }}px; margin-bottom: 18px; }
        .organization { font-size: {{ $scale['body'] }}pt; letter-spacing: 1px; text-transform: uppercase; color: #6b7280; }
        .title { font-size: {{ $scale['title'] }}pt; font-weight: bold; line-height: 1.15; margin: 14px 0 8px; }
        .lead { font-size: {{ $scale['lead'] }}pt; color: #374151; margin: 0 0 22px; }
        .cta {
            display: inline-block;
            background: {{ $theme->primary }};
            color: {{ $theme->onPrimary }};
            font-size: {{ $scale['lead'] }}pt;
            font-weight: bold;
            padding: 12px 28px;
            border-radius: 8px;
        }
        .qr { width: {{ $scale['qr'] }}px; height: {{ $scale['qr'] }}px; margin: 26px auto 10px; }
        /* Spec 13 sets codes in IBM Plex Mono, and this is the one code a
           reader types by hand. PosterFonts registers the Mono face as `bold`
           to match the weight below: dompdf does not fall back to another
           weight inside a named family, it falls through to the next family. */
        .short-url {
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-size: {{ $scale['lead'] }}pt;
            font-weight: bold;
            letter-spacing: 1px;
        }
        .deadline { font-size: {{ $scale['body'] }}pt; color: #374151; margin-top: 18px; }
        .deadline strong { color: {{ $theme->accent }}; }
        .meta { font-size: {{ $scale['body'] }}pt; color: #6b7280; margin-top: 8px; }
        .footer { font-size: {{ max(8, $scale['body'] - 2) }}pt; color: #9ca3af; margin-top: 26px; }
    </style>
</head>
<body>
    <div class="rule"></div>
    <div class="sheet">
        @if ($logoDataUri)
            <img class="logo" src="{{ $logoDataUri }}" alt="">
        @endif

        <div class="organization">{{ $organization->name }}</div>
        <h1 class="title">{{ $conference->name }}</h1>

        @if ($conference->short_description)
            <p class="lead">{{ $conference->short_description }}</p>
        @endif

        <p><span class="cta">Submit your abstract</span></p>

        <img class="qr" src="{{ $qrDataUri }}" alt="">
        <div class="short-url">{{ $shortUrl }}</div>

        @if ($conference->submission_deadline)
            <p class="deadline">
                Deadline
                <strong>{{ $conference->deadlineInConferenceTimezone()?->format('j F Y, H:i') }}</strong>
                ({{ $conference->timezone }})
            </p>
        @endif

        @if ($conference->starts_at || $conference->venue || $conference->city)
            <p class="meta">
                {{ collect([
                    $conference->starts_at?->format('j M Y'),
                    $conference->venue,
                    $conference->city,
                ])->filter()->implode(' · ') }}
            </p>
        @endif

        <p class="footer">Scan the code or type the address above.</p>
    </div>
</body>
</html>
```

- [ ] **Step 7: Write `app/Http/Controllers/Organizer/ConferenceAssetController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizer;

use App\Actions\Conferences\GenerateConferencePoster;
use App\Actions\Conferences\GenerateConferenceQr;
use App\Enums\PosterSize;
use App\Http\Controllers\Controller;
use App\Models\Conference;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Downloads live on plain authenticated routes rather than Filament actions so
 * they can be linked from anywhere (an email, the panel, a bookmark) and so
 * the authorization is one explicit Gate call per request.
 */
class ConferenceAssetController extends Controller
{
    public function svg(Conference $conference, GenerateConferenceQr $qr): Response
    {
        $this->authorizeDownload($conference);

        return $this->download($qr->svg($conference), $qr->fileName($conference, 'svg'), 'image/svg+xml');
    }

    public function png(Conference $conference, GenerateConferenceQr $qr): Response
    {
        $this->authorizeDownload($conference);

        return $this->download($qr->png($conference), $qr->fileName($conference, 'png'), 'image/png');
    }

    public function poster(Conference $conference, string $size, GenerateConferencePoster $poster): Response
    {
        $this->authorizeDownload($conference);

        $posterSize = PosterSize::tryFrom($size);
        abort_if($posterSize === null, 404);

        return $this->download(
            $poster->handle($conference, $posterSize),
            $poster->fileName($conference, $posterSize),
            'application/pdf',
        );
    }

    private function authorizeDownload(Conference $conference): void
    {
        Gate::authorize('view', $conference);

        // Nothing to download until publishing has created the short link.
        abort_if($conference->shortLink === null, 404);
    }

    private function download(string $body, string $fileName, string $contentType): Response
    {
        return response($body, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
```

- [ ] **Step 8: Register the routes**

First register the limiter. In `app/Providers/AppServiceProvider.php` add the imports:

```php
use App\Support\ClientIp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
```

and this at the end of `boot()`:

```php
        // Each poster is a synchronous dompdf render (A4/A3 layout, a 1200 px
        // QR PNG, TTF metrics) on the four-worker php-fpm pool that also
        // serves every tenant's public pages and panels, and nothing caches
        // the result. Cap how often one account can ask for a download.
        RateLimiter::for('conference-assets', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('user:'.($request->user()?->getAuthIdentifier() ?? ClientIp::from($request))));
```

In `routes/web.php` add the import `use App\Http\Controllers\Organizer\ConferenceAssetController;` and:

```php
// Authenticated but outside the Filament panel, so the URLs are stable and
// short. Binding is by ULID because the conference slug is only unique inside
// one organization.
//
// No .svg/.png/.pdf suffix on these paths: docker/nginx.conf answers any URI
// ending in a static-file extension from disk, so such a route would 404 in
// production while passing every test (Task 1 Step 6 fixes the fallback as
// well, but a download route should not depend on it). The saved file name
// comes from Content-Disposition.
//
// The panel runs Filament's AuthenticateSession and EnsureEmailIsVerified on
// every tenant route; these downloads live outside the panel, so they repeat
// both (spec section 9), and throttle the expensive render.
Route::middleware([
    'auth',
    AuthenticateSession::class,
    'verified:filament.organizer.auth.email-verification.prompt',
    'throttle:conference-assets',
])
    ->prefix('conference-assets/{conference:ulid}')
    ->name('conference-assets.')
    ->group(function (): void {
        Route::get('/qr-svg', [ConferenceAssetController::class, 'svg'])->name('qr.svg');
        Route::get('/qr-png', [ConferenceAssetController::class, 'png'])->name('qr.png');
        Route::get('/poster/{size}', [ConferenceAssetController::class, 'poster'])
            ->where('size', 'a4|a3')
            ->name('poster');
    });
```

(`AuthenticateSession` is already imported by Task 4 Step 8.)

- [ ] **Step 9: Write the sharing page**

`app/Filament/Organizer/Resources/Conferences/Pages/ConferenceShortLink.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Enums\PosterSize;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Models\Conference;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

class ConferenceShortLink extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ConferenceResource::class;

    protected string $view = 'filament.organizer.resources.conferences.pages.short-link';

    /**
     * resolveRecord() runs through ConferenceResource::getEloquentQuery(),
     * which carries the panel's tenancy global scope, so a conference from
     * another organization is already a 404 here. The explicit policy check
     * is defence in depth.
     */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 404);
    }

    public function getTitle(): string
    {
        return 'Share and print';
    }

    public function getConference(): Conference
    {
        /** @var Conference $conference */
        $conference = $this->getRecord();

        return $conference;
    }

    /**
     * Days are cut in the conference's own timezone, so the chart lines up
     * with the deadline printed beside it rather than with UTC.
     *
     * @return array<string, int>
     */
    public function getDailyScans(): array
    {
        $conference = $this->getConference();

        return $conference->shortLink?->dailyVisitCounts(30, $conference->timezone) ?? [];
    }

    protected function getHeaderActions(): array
    {
        $conference = $this->getConference();

        if ($conference->shortLink === null) {
            return [];
        }

        return [
            Action::make('download_svg')
                ->label('QR (SVG)')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(route('conference-assets.qr.svg', $conference)),
            Action::make('download_png')
                ->label('QR (PNG)')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(route('conference-assets.qr.png', $conference)),
            Action::make('download_poster_a4')
                ->label('Poster A4')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(route('conference-assets.poster', ['conference' => $conference, 'size' => PosterSize::A4->value])),
            Action::make('download_poster_a3')
                ->label('Poster A3')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(route('conference-assets.poster', ['conference' => $conference, 'size' => PosterSize::A3->value])),
        ];
    }
}
```

`resources/views/filament/organizer/resources/conferences/pages/short-link.blade.php`
```blade
@php
    $conference = $this->getConference();
    $link = $conference->shortLink;
    $daily = $this->getDailyScans();
    $peak = max(1, ...array_values($daily ?: [0]));
@endphp

<x-filament-panels::page>
    @if ($link === null)
        <x-filament::section heading="Not shared yet">
            <p style="font-size:0.875rem">
                Publishing this conference creates a short link and a QR code you can print. Publish it from the conference page when the dates and review form are ready.
            </p>
        </x-filament::section>
    @else
        <x-filament::section heading="Short link">
            <p style="font-family:ui-monospace,monospace;font-size:1.125rem">
                <x-filament::link :href="$link->url()" target="_blank" rel="noopener">{{ $link->url() }}</x-filament::link>
            </p>
            <p style="margin-top:0.5rem;font-size:0.875rem">
                Code <span style="font-family:ui-monospace,monospace;font-weight:600">{{ $link->code }}</span>. It points at
                <span style="font-family:ui-monospace,monospace">{{ $conference->publicUrl() }}</span> and never changes, so a printed poster keeps working after you close and reopen submissions.
            </p>
        </x-filament::section>

        {{--
            Inline styles, not Tailwind utilities: a Filament panel loads only
            Filament's precompiled CSS, which contains theme variables and
            fi-* component classes and no general utilities, and this panel has
            no custom theme (`->viteTheme()`). `flex`, `h-24`, `items-end` and
            `bg-primary-500` would all be no-ops here, so the spec 5.7
            sparkline would render as invisible full-width blocks.
            `--primary-500` is a real colour value Filament emits on the page.
        --}}
        <x-filament::section heading="Scans">
            <div style="display:flex;align-items:baseline;gap:0.75rem">
                <span style="font-size:1.875rem;font-weight:600">{{ number_format($link->clicks) }}</span>
                <span class="fi-text-sm">Total scans</span>
            </div>

            <h3 style="margin-top:1.5rem;font-size:0.875rem;font-weight:500">Last 30 days</h3>
            <div style="display:flex;align-items:flex-end;gap:2px;height:96px;margin-top:0.75rem">
                @foreach ($daily as $day => $count)
                    <div title="{{ $day }}: {{ $count }}"
                         style="flex:1;height:{{ $count === 0 ? 2 : (int) round($count / $peak * 96) }}px;background:var(--primary-500);border-radius:2px 2px 0 0"></div>
                @endforeach
            </div>
            <p style="margin-top:0.5rem;font-size:0.75rem;opacity:0.7">
                Only a timestamp is stored for each scan. No IP address, device or location is recorded.
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
```

- [ ] **Step 10: Register the page and link to it**

In `ConferenceResource.php` add the import `use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceShortLink;` and add to `getPages()` after `view`:

```php
            'short-link' => ConferenceShortLink::route('/{record}/share'),
```

Add a sharing action to `Tables/ConferenceStatusActions.php` — append this method to the class and add it to `all()`:

```php
    public static function share(): Action
    {
        return Action::make('share')
            ->label('Share and print')
            ->icon(Heroicon::OutlinedQrCode)
            ->color('gray')
            ->visible(fn (Conference $record): bool => $record->shortLink !== null && Gate::allows('view', $record))
            ->url(fn (Conference $record): string => ConferenceResource::getUrl('short-link', ['record' => $record]));
    }
```

with the import `use App\Filament\Organizer\Resources\Conferences\ConferenceResource;`, and change `all()` to:

```php
    /** @return list<Action> */
    public static function all(): array
    {
        return [static::share(), static::publish(), static::close(), static::archive()];
    }
```

`ViewConference` and `EditConference` spread `all()`, so they pick this up automatically. The table lists the transitions one by one and does **not** call `all()`, so add the action there explicitly — in `Tables/ConferencesTable.php`, insert it into `recordActions()` after `EditAction::make(),`:

```php
                ConferenceStatusActions::share(),
```

- [ ] **Step 11: Run the tests to verify they pass**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/ConferenceQrTest.php tests/Feature/Organizer/ConferenceAssetsTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -4 /tmp/t.log
```

Expected: `rc=0`, `27 passed` (10 unit, 17 feature — the two asset-route datasets report four tests each). The first poster render writes the converted font metrics into `storage/fonts/`; that directory is git-ignored except for its `.gitignore`, so nothing new should appear in `git status`.

- [ ] **Step 12: Pint, Larastan, full suite, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?" && \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log && \
git status --short && \
git add -A && git commit -q -m "feat: conference QR codes, A4/A3 poster PDF and the sharing page with scan counts

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

Expected: `stan rc=0`, `tests rc=0`, `198 passed`, and `git status --short` shows no `storage/fonts/*` entries.

---

### Task 11: Conferences overview on the organizer dashboard

**Files:**
- Create: `app/Filament/Organizer/Widgets/ConferencesOverview.php`
- Modify: `resources/views/filament/organizer/pages/dashboard.blade.php`
- Test: `tests/Feature/Organizer/ConferencesOverviewWidgetTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Organizer/ConferencesOverviewWidgetTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Organizer\Widgets\ConferencesOverview;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\ShortLink;
use App\Models\Track;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);
});

it('lists this tenant conferences with status, deadline and counts', function () {
    // 4817, not 12: a Filament page is full of icon path data and dates, so
    // assertSee('12') passes whatever the scan count is.
    $conference = Conference::factory()->for($this->organization)->published()->create(['name' => 'Alpha Annual Meeting']);
    Track::factory()->for($conference)->create();
    Track::factory()->for($conference)->create();
    ShortLink::forTarget($conference)->forceFill(['clicks' => 4817])->save();

    livewire(ConferencesOverview::class)
        ->assertCanSeeTableRecords([$conference])
        ->assertSee('Alpha Annual Meeting')
        ->assertSee('Open for submissions')
        ->assertSee('4817')
        ->assertSee($conference->deadlineInConferenceTimezone()?->format('j M Y, H:i'));
});

it('never shows another organization conferences', function () {
    $mine = Conference::factory()->for($this->organization)->create();
    $theirs = withoutTenant(fn () => Conference::factory()->create());

    livewire(ConferencesOverview::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('replaces the placeholder card on the dashboard', function () {
    // Widgets are lazy by default, so the table heading is not in the first
    // response - and after Task 7 the sidebar always says "Conferences", which
    // is why that assertion would prove nothing. Assert the component itself.
    get("/org/{$this->organization->slug}")
        ->assertOk()
        ->assertDontSee('Conference management arrives in the next release')
        ->assertSeeLivewire(ConferencesOverview::class);
});

it('keeps the pending banner above the conferences widget', function () {
    // Spec 5.1 lets a pending organization draft a conference (it just cannot
    // publish one), so the widget stays; only the banner is extra.
    $pending = Organization::factory()->create();
    $owner = User::factory()->create();
    $pending->addMember($owner, OrganizationRole::Owner);

    actingAs($owner)->get("/org/{$pending->slug}")
        ->assertOk()
        ->assertSee('awaiting approval')
        ->assertSeeLivewire(ConferencesOverview::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferencesOverviewWidgetTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found|does not exist|Unable to find component" /tmp/t.log | head -3
```

Expected: `rc=1`, message `Unable to find component: [App\Filament\Organizer\Widgets\ConferencesOverview]`.

- [ ] **Step 3: Write `app/Filament/Organizer/Widgets/ConferencesOverview.php`**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Widgets;

use App\Enums\ConferenceStatus;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Models\Conference;
use App\Models\Organization;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ConferencesOverview extends TableWidget
{
    protected static ?int $sort = -2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Conferences')
            ->description('Everything this organization is running, newest first.')
            ->query($this->query())
            ->defaultSort('created_at', 'desc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('name')->searchable()
                    ->description(fn (Conference $record): string => $record->slug)
                    ->url(fn (Conference $record): string => ConferenceResource::getUrl('view', ['record' => $record])),
                TextColumn::make('status')->badge(),
                // ->timezone() as well as the description: without it Filament
                // formats in config('app.timezone') (UTC) and the row would
                // label a UTC time with the conference's timezone.
                TextColumn::make('submission_deadline')->label('Deadline')
                    ->dateTime('j M Y, H:i')->placeholder('Not set')
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->description(fn (Conference $record): string => $record->timezone),
                TextColumn::make('tracks_count')->label('Tracks')->badge()->color('gray'),
                TextColumn::make('review_questions_count')->label('Questions')->badge()->color('gray'),
                TextColumn::make('shortLink.clicks')->label('Scans')->badge()->color('gray')->placeholder('0'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Conference $record): string => ConferenceResource::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([
                Action::make('create')
                    ->label('New conference')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->url(ConferenceResource::getUrl('create')),
            ])
            ->emptyStateHeading('No conferences yet')
            ->emptyStateDescription('Create one to set your dates, review form and submission window.');
    }

    /**
     * The tenancy global scope already limits this to the current tenant while
     * the organizer panel is current; the explicit whereBelongsTo makes the
     * widget safe to reuse anywhere and documents the intent.
     *
     * @return Builder<Conference>
     */
    private function query(): Builder
    {
        /** @var Organization $organization */
        $organization = Filament::getTenant();

        return Conference::query()
            ->whereBelongsTo($organization)
            ->whereNot('status', ConferenceStatus::Archived)
            ->withCount(['tracks', 'reviewQuestions'])
            ->with('shortLink');
    }
}
```

- [ ] **Step 4: Replace the placeholder card in the dashboard view**

`resources/views/filament/organizer/pages/dashboard.blade.php` — replace only the `@else` branch:

```blade
    @else
        {{-- <x-filament::section>, not Tailwind utilities: a panel loads only
             Filament's precompiled CSS, which has no general utilities, so
             `rounded-xl border bg-white p-4` renders as nothing (the existing
             pending and suspended banners above have the same problem - see
             the backlog item about an organizer panel theme). --}}
        <x-filament::section>
            <p style="font-weight:600">Welcome to {{ $this->getOrganization()->name }}.</p>
            <p style="margin-top:0.25rem;font-size:0.875rem">
                Create a conference, set its dates and review form, then publish it to get a public page, a short link and a printable QR poster.
            </p>
        </x-filament::section>
    @endif
```

Everything above the `@else` (the pending and suspended banners) and the trailing
`<x-filament-widgets::widgets :widgets="$this->getVisibleWidgets()" :columns="$this->getColumns()" />`
line stays exactly as it is: the widget renders through that call because the organizer panel already discovers `app/Filament/Organizer/Widgets`.

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferencesOverviewWidgetTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -4 /tmp/t.log
```

Expected: `rc=0`, `4 passed`.

- [ ] **Step 6: Pint, Larastan, full suite, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?" && \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log && \
git add -A && git commit -q -m "feat: conferences overview widget replaces the dashboard placeholder card

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

Expected: `stan rc=0`, `tests rc=0`, `202 passed`.

---

### Task 12: MySQL run, runbook, backlog, push, CI, pull request

**Files:**
- Modify: `docs/runbooks/deploy-production.md`, `docs/superpowers/plans/backlog.md`, `.github/workflows/ci.yml`
- No application code changes.

- [ ] **Step 1: Run the whole suite against MySQL, not only SQLite**

The JSON columns (`allowed_file_types`, `presentation_types`, `options`) and the `(organization_id, slug)` unique index behave differently on MySQL, so this run is the real gate.

```bash
cd /c/Users/ahmed/Documents/CASS && docker compose -f docker-compose.dev.yml up -d && sleep 15 && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php artisan test > /tmp/mysql.log 2>&1; echo "mysql rc=$?"; tail -4 /tmp/mysql.log
```

Expected: `mysql rc=0`, `202 passed`. If a JSON default fails on MySQL, fix the migration (MySQL 8 rejects a literal `DEFAULT` on a JSON column, which is why `allowed_file_types` and `presentation_types` are written by the factory and the form rather than by the schema).

- [ ] **Step 2: Confirm the routes this plan added**

Laravel 13's `route:list` has no `--columns` option (it exits with `The "--columns" option does not exist.`), so filter the plain listing:

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan route:list --except-vendor | grep -E "conference|shortlink"
```

Expected twelve `GET|HEAD` lines:

- `c/{organization}/{conference:slug}` → `conference.show`
- `q/{code}` → `shortlink.show`
- `conference-assets/{conference:ulid}/qr-svg`, `.../qr-png`, `.../poster/{size}`
- five Filament resource routes under `org/{tenant:slug}/conferences`: index, `create`, `{record}`, `{record}/edit`, `{record}/share`
- two admin resource routes: `admin/conferences` and `admin/conferences/{record}`

`--except-vendor` still lists all of these because their action classes live in `app/`.

- [ ] **Step 3: Update `docs/runbooks/deploy-production.md`**

Replace step 3 of `## Every release` (currently "If the release adds migrations, run step 6 above after the container is healthy") with:

````markdown
3. If the release adds migrations, apply them as soon as Coolify reports the new `app` container healthy. The migration files exist only in the new image, and Coolify's Docker Compose deploys stop the old container before the new one starts (no rolling update), so there is no moment at which the old container could run them — `migrate` there just prints "Nothing to migrate". Turn auto-deploy off for such a release so you are at the terminal when the new container goes live. Run the migration only, **not** step 6's `db:seed`: the seeder resets the platform admin's password back to `CASS_ADMIN_PASSWORD`.

   ```bash
   C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
   sudo docker exec -it "$C" su-exec app php artisan migrate --force
   sudo docker exec -it "$C" su-exec app php artisan migrate:status
   ```

   Nothing should be Pending. Plan 2 is such a release: it adds seven tables (`conferences`, `tracks`, `custom_fields`, `review_forms`, `review_questions`, `short_links`, `short_link_visits`), and until they exist every organizer dashboard (the Conferences widget queries `conferences`) and every `/org/{tenant}/conferences`, `/c/...` and `/q/...` page returns 500.
````

Extend step 4 of the same list (currently "Check https://cass.towardpcc.com/up returns 200, then open the landing page and `/org/login`"):

```markdown
4. Check https://cass.towardpcc.com/up returns 200, then open the landing page and `/org/login`. **`/org/login` must be styled**: this release is the first image that runs `filament:assets` and publishes Livewire's script, so `/css/filament/filament/app.css` and `/vendor/livewire/livewire.min.js` should both return 200. Cloudflare may still be serving the old 404s — purge `/css/filament/*`, `/js/filament/*`, `/fonts/filament/*` and `/vendor/livewire/*` if so.
```

Add step 5 to the same list:

````markdown
5. Time the public conference page after the deploy. Spec section 10 gives it a 300 ms server budget, which is the reason it is plain Blade instead of Livewire.

   ```bash
   C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
   for i in 1 2 3 4 5; do
     sudo docker exec "$C" php -r '$c = stream_context_create(["http" => ["header" => "Host: cass.towardpcc.com\r\n"]]); $t = microtime(true); file_get_contents("http://127.0.0.1:8080/c/<org>/<conference>", false, $c); printf("%.3f\n", microtime(true) - $t);'
   done
   ```

   The median must stay under 0.300 s. The first run after a deploy warms OPcache and may be slower. The `Host` header is required because `TrustHosts` only accepts the `APP_URL` host, and the probe runs *inside* the container because the compose file publishes no ports on the host.
````

and add this section after `## Every release`:

````markdown
## Poster PDF fonts

The poster PDF is rendered by dompdf using three IBM Plex TTFs committed at `resources/fonts/` (Sans regular, Sans semibold, Mono semibold for the short URL). dompdf converts them to its own metrics format on first use and caches the result in `storage/fonts/`, which must be writable by the `app` user — the Dockerfile creates it and chowns it. Nothing is downloaded at runtime.

If a poster download returns a 500, look for `Failed to open stream: Permission denied` on a path under `/var/www/html/storage/fonts/`. dompdf's font library writes the metrics file first, so the usual line is `fopen(/var/www/html/storage/fonts/ibm_plex_sans_normal_<hash>.ufm): Failed to open stream: Permission denied`; if the directory itself is missing it is `mkdir(): Permission denied`. The directory lives in the container layer, not on a volume (`cass-storage` is mounted at `storage/app` only), so a redeploy recreates it with the right owner. To repair the running container:

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec -it "$C" sh -c 'mkdir -p storage/fonts && chown -R app:app storage/fonts'
```
````

- [ ] **Step 4: Update `docs/superpowers/plans/backlog.md`**

Under `## Organizer features (Plan 2 onwards)`, remove nothing, and append:

```markdown
- Conference status `reviewing` and `decided` are in the enum and the transition table but nothing drives them yet: Plan 4 moves Closed -> Reviewing, Plan 5 moves Reviewing -> Decided.
- `review_forms.locked_at` is written by nothing except factories until Plan 4's `SubmitReview` sets it.
- **Decided (owner):** suspending an organization takes every one of its public conference pages offline immediately — 404 to the public, still previewable by members — because the suspension email already promises exactly that. Tested in `ConferencePageTest` and `ShortLinkTest`; no further confirmation needed.
- **Decided (owner):** the QR PNG is the smallest whole-module square at or above 1024 px rather than exactly 1024 px, because chillerlan renders whole modules and resampling would blur them. The SVG is the print master. Revisit only if a print shop asks for an exact pixel size.
- `ShortLink::dailyVisitCounts()` groups in PHP. Move it to a grouped SQL query if a single conference ever passes roughly 100k scans.
- Organization member management (spec 4 "Manage organization members") was handed to Plan 2 by Plan 1 and moves on to **Plan 4**, which builds the hashed-token invitation flow that member and reviewer invitations share. Until then every organization has only its registering owner, so the owner/admin/member distinctions Plan 2 enforces cannot occur in production.
- Per-conference email templates (spec 5.2, 5.9) are not built in Plan 2. **Plan 3** adds `EmailTemplate`, the platform defaults for every template key in spec 5.9, the per-conference editor with placeholders and the placeholder-rendering unit test, because Plan 3 sends the first conference-scoped emails (`submission_draft_saved`, `submission_received`).
- Platform-admin hard purge of a conference (spec 3): the foreign keys are `RESTRICT`, and `ConferencePolicy::before()` already returns true for a platform admin, so a `ForceDeleteAction` must not be added to either panel until an action class deletes the tree in application code (and, from Plan 3 on, the files in private storage). Plan 6.
- Organizer panel theme: `php artisan make:filament-theme organizer`, `->viteTheme(...)`, add the file to the `vite.config.js` input, and move the Dockerfile's `vendor` stage above the `assets` stage so `vendor/filament` can be copied in (the theme's `@import` reaches into it, and `.dockerignore` excludes `vendor`). Until then Tailwind utility classes in panel Blade views do nothing — Plan 1's dashboard banners are already unstyled, and Plan 2 uses inline styles and `<x-filament::section>` instead.
- Spec section 10 requires every string to live in language files so Arabic can be added without code changes. Plans 1 and 2 hardcode English in the public Blade views, the countdown script and the poster template; extract them to `lang/en/*.php` before any Arabic work.
- Admin `OrganizationResource` has the route-key mismatch described in fact 16: `Organization::getRouteKeyName()` is the slug while the resource sets `$recordRouteKeyName = 'id'`, so its table View action and row link generate `/admin/organizations/{slug}` and resolve `where id = '{slug}'` — a 404. (The approval email builds an id URL by hand and works.) Do **not** fix it by changing the model's route key: the public `/c/{organization}` route depends on the slug. Bind the admin resource by slug, or build the View URL from the id.
- Reject or downscale organization logos above 16 MP at upload (`EditOrganizationProfile`), so `GenerateConferencePoster::logoDataUri()` never has to drop one silently.
```

Under `## Public site polish (Plan 2 or 3)`, append:

```markdown
- The public conference page CTA links to `#` in its open state. Plan 3 replaces that one `href` in `resources/views/public/partials/submit-cta.blade.php` with `route('conference.submit', ...)`, and registers `/c/{organization}/{conference:slug}/submit` — the same explicit `:slug` binding field `conference.show` uses, because the model's route key is the ULID.
- Conference `og:image` (the QR poster or the organization logo) for link previews when a conference is shared on social media.
```

- [ ] **Step 5: Teach the smoke job about the new image contents**

Nothing in `image` or `smoke` can currently see a missing panel asset, a nginx rule that eats a route, or a dompdf render that cannot write its font cache, because the job only requests `/up`, `/` and `POST /register` — and the Pest suite runs on Ubuntu as the runner user with the fonts inside the checkout, not on Alpine as `app`. Append to the `Assertions` step of the `smoke` job in `.github/workflows/ci.yml`:

```yaml
          # The panel is unusable without these; they are git-ignored and only
          # exist because the image publishes them at build time.
          curl -sf -o /dev/null -H 'Host: localhost' http://127.0.0.1:8080/css/filament/filament/app.css
          curl -sf -o /dev/null -H 'Host: localhost' http://127.0.0.1:8080/js/filament/filament/app.js
          curl -sf -o /dev/null -H 'Host: localhost' http://127.0.0.1:8080/vendor/livewire/livewire.min.js
          # A guest on an authenticated asset route reaches PHP and is sent to
          # the organizer login (redirectGuestsTo in bootstrap/app.php). This
          # URI has no dot extension, so the static-extension location never
          # sees it - it proves the route and the redirect, nothing about nginx.
          code=$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: localhost' http://127.0.0.1:8080/conference-assets/01ARZ3NDEKTSV4RRFFQ69G5FAV/qr-svg)
          echo "asset route -> $code"; test "$code" = "302"
          # A URI that DOES end in a static extension with no file on disk must
          # fall through to Laravel instead of being 404'd from disk by the
          # regex location in docker/nginx.conf - the bug that kept Livewire's
          # /livewire-<hash>/livewire.min.js 404 in production. Both answers are
          # a 404, so tell them apart by a header only Laravel sends:
          # X-Content-Type-Options is no use because nginx sends it as well.
          curl -sI -H 'Host: localhost' http://127.0.0.1:8080/nonexistent-probe.css | tee /tmp/fallthrough
          grep -qi '^x-frame-options: DENY' /tmp/fallthrough
          # dompdf inside the production image as the php-fpm user: bundled
          # TTFs present, storage/fonts writable by app, GD/CPDF work on Alpine.
          docker exec cass su-exec app php -r '
            require "vendor/autoload.php";
            $app = require "bootstrap/app.php";
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $pdf = Barryvdh\DomPDF\Facade\Pdf::loadHTML("<p style=\"font-family: IBM Plex Sans; font-weight: bold\">CASS</p>");
            App\Support\Pdf\PosterFonts::register($pdf->getDomPDF());
            exit(str_starts_with($pdf->output(), "%PDF-") ? 0 : 1);'
          docker exec cass test -s storage/fonts/installed-fonts.json
```

- [ ] **Step 6: Final local gate**

```bash
cd /c/Users/ahmed/Documents/CASS && \
./vendor/bin/pint --test; echo "pint rc=$?"; \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?"; \
npm run build > /tmp/build.log 2>&1; echo "build rc=$?"; \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log
```

Expected: `pint rc=0`, `stan rc=0`, `build rc=0`, `tests rc=0`.

- [ ] **Step 7: Commit the documentation and push the branch**

```bash
cd /c/Users/ahmed/Documents/CASS && git add -A && git commit -q -m "docs: Plan 2 runbook and backlog updates, smoke asserts for panel assets and dompdf

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && \
git push -u origin plan-2-conferences && git log --oneline main..plan-2-conferences | wc -l
```

Expected: the push succeeds and the count is 12 (one commit per task).

- [ ] **Step 8: Open the pull request**

```bash
cd /c/Users/ahmed/Documents/CASS && gh pr create --base main --head plan-2-conferences \
  --title "Plan 2: conferences, review forms, QR codes and the public conference page" \
  --body "$(cat <<'BODY'
Implements Plan 2 (`docs/superpowers/plans/2026-09-10-plan-2-conferences.md`), covering spec sections 5.2, 5.7, 6 and the conference parts of 3, 4, 8, 9, 10, 12 and 13.

## Organizer

- `Conference` with a slug the organizer may choose or leave to be derived (unique per organization, trashed rows included), soft deletes, ULID public id and route key, and the full status graph (draft, open, closed, reviewing, decided, archived).
- `ConferenceResource` in the organizer panel: list, create, view (with a publishing checklist), edit and a share-and-print page. A read-only `ConferenceResource` in the admin panel gives the platform admin the "see all conferences" and restore rights of spec section 4.
- Tracks, extra submission fields and the review form as reorderable relation managers, each with its own policy.
- The review form starts from the nine legacy Likert questions and locks existing questions once `review_forms.locked_at` is set (Plan 4 sets it); new questions can still be appended.
- Publish / close submissions / archive as explicit actions with the spec 5.2 gate: approved organization, a submission window whose deadline is in the future, and a review form with at least one question. Every failure is a sentence the organizer can act on.
- Publishing generates an 8-character short link from an unambiguous alphabet, plus SVG and PNG QR downloads and an A4/A3 poster PDF with the organization logo, the conference name, "Submit your abstract", the QR, the short URL and the deadline.
- Conferences overview widget replaces the placeholder card on the organizer dashboard.

## Public

- `/c/{organization}/{conference:slug}` with the organization's colours as CSS variables and readable text picked by the WCAG contrast helper. Draft and archived conferences, and everything belonging to an organization that is not approved, are 404 to the public and previewable by verified organization members.
- `/q/{code}` always redirects and counts the scan (timestamp only, no personal data); the per-client-IP cap limits counting only, so a hall full of people scanning one poster behind a single NAT address is never refused.

## Also in this branch

- The production image now runs `filament:assets` and publishes Livewire's script, and nginx falls through to Laravel for URIs ending in a static extension. Without both, the deployed panel has no CSS and no Livewire — check `/org/login` is styled after deploying, and purge Cloudflare if it still serves the old 404s.

## Deferred on purpose

- The submission CTA links to `#` in its open state; Plan 3 replaces that single `href` and registers `/c/{organization}/{conference:slug}/submit`.
- Per-conference email templates (spec 5.2, 5.9) go with Plan 3, which sends the first conference-scoped emails.
- Organization member management (spec 4), handed here by Plan 1, goes with Plan 4's invitation flow.
- Reviewer invitation, assignment and review are Plan 4; scoring and decisions are Plan 5. The platform-admin hard purge stays Plan 6.

## Verification

- Pest: SQLite in memory and MySQL 8.4, both green.
- Larastan level 6 and Pint (strict types) clean.
- Cross-tenant negative cases on the resource, all three relation managers, the sharing page and every asset route, plus cross-conference cases inside one organization.
- `smoke` now asserts the panel assets load, that an asset route reaches PHP, and that dompdf renders inside the image as the `app` user.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
BODY
)"
```

- [ ] **Step 9: Watch CI to green**

```bash
cd /c/Users/ahmed/Documents/CASS && gh pr checks --watch --interval 20; echo "checks rc=$?"
```

Expected: `checks rc=0` with `test`, `image` and `smoke` all passing. If `image` or `smoke` fails, read the log with `gh run view --log-failed`:

- the build-time `php artisan filament:assets` failed → something in `bootstrap/app.php` or a service provider now needs a `.env` at build time;
- the panel-asset `curl` 404s → the publish step did not run, or `.dockerignore` grew an entry that removed `public/`;
- the asset-route probe returned something other than 302 → `redirectGuestsTo` is gone from `bootstrap/app.php`, or the route lost its `auth` middleware, or (on a 404) the route itself is missing. It cannot be the nginx static-extension location: `/conference-assets/{ulid}/qr-svg` has no dot before `svg`, so the `\.(css|js|...)$` regex never matches it;
- the `/nonexistent-probe.css` probe found no `x-frame-options` header → nginx answered that 404 from disk, so the static-extension location is back to `try_files $uri =404` (verified: with the fallthrough the 404 carries Laravel's `SecurityHeaders`, without it only nginx's own `X-Content-Type-Options`). If nginx is unchanged, check `SecurityHeaders` is still global middleware rather than web-group — `tests/Feature/Public/LandingPageTest.php` guards exactly that;
- the dompdf probe failed at the `fopen(... .ufm)` line → `storage/fonts` is not writable by `app`; if the TTFs themselves are missing, check `.dockerignore` did not gain a `resources` entry.

- [ ] **Step 10: Report and stop**

Print the PR URL and stop. **Do not merge and do not deploy** — the owner merges and runs the migrations.

```bash
cd /c/Users/ahmed/Documents/CASS && gh pr view --json url,state,title --jq '"\(.state) \(.title)\n\(.url)"'
```

---

## Self-review against the spec

### Section 5.2 — Conference setup

| Requirement | Where |
|---|---|
| Name, slug, dates, venue, timezone | Task 2 (schema, model, slug derivation), Task 7 (form; the slug is an optional "Web address" field that falls back to the derived value and is checked against trashed rows) |
| Description (rich text), one-line summary | Task 2 (columns), Task 7 (`RichEditor`), Task 4 (sanitised render) |
| Submission window, review deadline | Task 2, Task 7 (timezone-aware `DateTimePicker`) |
| Review mode, blind-review flag, reviewers per submission | Task 2 (`ReviewMode`), Task 7 (conditional field) |
| Word limit 500, max files 3, allowed types `['pdf']` | Task 2 (defaults), Task 7 (form) |
| Presentation types offered | Task 2 (default `['oral','poster','either']`), Task 7 |
| Optional tracks | Task 3 (model), Task 9 (reorderable relation manager) |
| Optional custom fields | Task 3 (model, derived key), Task 9 (relation manager) |
| Review form from the default template, editable | Task 6 (`CreateDefaultReviewForm`: the eight legacy prompts verbatim plus a reworded legacy recommendation question), Task 9 (add / edit / reorder / delete; new questions are appended, never sorted first) |
| Select review question options carry an optional score (5.6) | Task 9 (`Repeater` of label + score), Task 3 (`ReviewQuestion::isScored()` requires at least one scored choice) |
| Publishing requires an approved organization, an open submission window, an active review form with ≥1 question | Task 6 (`PublishConference::blockers()`), Task 8 (checklist on the view page + notification) |
| Publishing sets `open` and generates the short link and QR | Task 6 (`handle()` creates the `ShortLink`), Task 10 (QR from that link) |
| Email templates from platform defaults, editable per conference | **Deferred to Plan 3**, which sends the first conference-scoped emails (`submission_draft_saved`, `submission_received`) and therefore adds `EmailTemplate`, the platform defaults for every key in spec 5.9, the per-conference editor with placeholders and the placeholder-rendering unit test. Recorded in the backlog (Task 12 Step 4) and in "What this plan does NOT build". |

### Section 5.7 — QR codes and short links

| Requirement | Where |
|---|---|
| 8-character code, unambiguous alphabet, `/q/{code}` | Task 5 (`ShortCode`, 30-character alphabet, `ShortLinkController`) |
| QR encodes that URL | Task 10 (`GenerateConferenceQr::url()`) |
| SVG and PNG (1024 px) downloads | Task 10. **Deviation, decided by the owner:** the PNG is the smallest whole-module square at or above 1024 px (typically 1025–1100 px), because chillerlan renders whole modules and resampling to exactly 1024 would blur them. The SVG is the print master, and its fill attributes are asserted so it cannot regress into a solid black square. |
| Printable poster PDF, A4 and A3, with logo, name, "Submit your abstract", QR, short URL and deadline | Task 10 (`GenerateConferencePoster`, `PosterSize`, `resources/views/pdf/conference-poster.blade.php`) |
| Every visit counted, timestamp only, no personal data | Task 5 (`short_link_visits` has exactly `id`, `short_link_id`, `visited_at`; asserted by a test that reads the column list) |
| Panel shows total scans and a 30-day sparkline | Task 10 (`ConferenceShortLink` page: total plus a 30-day zero-filled bar strip in Blade, no chart library; inline styles because a Filament panel loads no Tailwind utilities, days cut in the conference timezone, asserted by rendered markup rather than a bare number) |
| Polymorphic targets so v2 can serve per-abstract QR codes | Task 5 (`short_links.target_type/target_id`, `ShortLink::forTarget(Model)`) |

### Section 3 — Domain model (conference parts)

| Requirement | Where |
|---|---|
| `Conference` statuses draft → archived | Task 2 (`ConferenceStatus` with the whole transition table) |
| `Track`, `CustomField`, `ReviewForm`, `ReviewQuestion` (weighted) | Task 3 |
| Exactly one active review form per conference | Task 3 (`Conference::reviewForm()` filtered by `is_active`), Task 6 (idempotent creation) |
| Questions editable until the first review; afterwards locked, appending still allowed | Task 3 (`ReviewQuestion` `updating`/`deleting` hooks + `ReviewForm::isLocked()`), Task 7 (`ReviewQuestionPolicy`), Task 9 (UI). Plan 4 sets `locked_at`. |
| Real InnoDB foreign keys everywhere | Task 2, Task 3 (`constrained()->restrictOnDelete()` across the conference tree), Task 5 (`cascadeOnDelete()` on `short_link_visits` only). RESTRICT asserted in Task 3 |
| Soft delete on conferences; hard purge cascades in application code, platform-admin only | Task 2 (`SoftDeletes`), Task 7 (`ConferencePolicy::forceDelete()` false for organizers; the admin resource offers restore and no force delete). **The purge action itself is deferred to Plan 6** and recorded in the backlog — the RESTRICT keys are what stop a stray `forceDelete()` from silently emptying the tree in the meantime |
| ULID public ids, bigint primary keys | Task 2, Task 3 (`ulid` unique on every table except the visit log; `Conference::getRouteKeyName()` is the ULID, so panel and asset URLs never expose the sequence number) |

### Section 4 — Roles and permissions

| Requirement | Where |
|---|---|
| Every org member can create and edit conferences and forms | Task 7 (`ConferencePolicy::create/update`), test "lets a plain member create and edit conferences" |
| Only owner/admin archive or delete | Task 7 (`canManage()`, plus `deleteAny()` because Filament authorizes bulk delete with that alone and treats a missing method as allow), Task 8 (archive action hidden from members), tests "offers delete only to owners and admins" and "offers bulk delete to owners but not to plain members" |
| Platform admin sees all conferences and can restore one | Task 7 (`ConferencePolicy::before()`, read-only admin `ConferenceResource` with a trashed filter and a restore action, `tests/Feature/Admin/ConferencesTest.php`) |
| Manage organization members | **Not built here.** Plan 1's self-review handed this to Plan 2; it moves on to **Plan 4**, which builds the hashed-token invitation flow that member and reviewer invitations share. Until then an organization has only its registering owner, so the role distinctions above are enforced and tested but cannot yet arise in production. Recorded in the backlog and in "What this plan does NOT build" |
| Policies on every model | Task 7 (five policies over the conference tree: `Conference`, `Track`, `CustomField`, `ReviewForm`, `ReviewQuestion`; Filament relation managers require them). `ShortLink` and `ShortLinkVisit` have none: no panel resource, relation manager or route exposes them except through a conference the user may already view |
| Filament tenancy scoping on every panel query | Task 7 (`ConferenceResource` scoped by the panel's global scope; `getEloquentQuery()` only adds eager loads) |
| Cross-tenant tests on every resource and relation manager | Tasks 7, 8, 9, 10, 11. Every fixture from another organization is built inside `withoutTenant()` (or after switching tenant) so Filament's tenant-association observer cannot re-parent it. Task 9 goes past listing: it mounts actions with foreign record keys and reorders foreign ids, asserts `canViewForRecord()` on all three relation managers, and adds a cross-*conference* case inside one organization |

### Section 6 — URL scheme

| Route | Where |
|---|---|
| `/c/{org}/{conference}` | Task 4 (`conference.show`, registered as `/c/{organization}/{conference:slug}` with scoped binding, because the model's route key is the ULID) |
| `/q/{code}` | Task 5 (`shortlink.show`; never throttled at the route, the cap is on counting) |
| `/org/{tenant}/conferences/...` | Task 7 (resource), Task 8 (view), Task 10 (share) |
| `/conference-assets/{conference:ulid}/{qr-svg,qr-png,poster/{size}}` | Task 10. Extension-less on purpose: `docker/nginx.conf` answers any URI ending in a static-file extension from disk |
| `/admin/conferences`, `/admin/conferences/{record}` | Task 7 (read-only platform-admin resource) |
| `/c/{org}/{conference}/submit`, `/s/{token}`, `/invite/{token}`, `/files/{ulid}` | **Plans 3 and 4.** Not registered here; the public CTA points at `#` in its open state so nothing links to a route that does not exist. Plan 3's submit route must use the same explicit `{conference:slug}` binding field. |

### Section 8 — Data storage

| Requirement | Where |
|---|---|
| Index on every foreign key | Task 2, 3, 5 (`constrained()` creates one; explicit composites added on top) |
| Unique `(organization_id, slug)` | Task 2 |
| Unique `short_links.code` | Task 5 |
| `(conference_id, status)` | **Not applicable yet:** that index is for `submissions`, which Plan 3 creates. The conference-level analogue `(organization_id, status)` is in Task 2. |
| JSON columns behave on MySQL | Task 12 (the whole suite runs against MySQL 8.4) |

### Section 9 — Security

| Requirement | Where |
|---|---|
| Policies plus tenancy scoping on every panel query | Task 7 |
| Feature tests for cross-tenant access on every resource | Tasks 7–11 |
| Audit log for conference status changes | Task 2 (`LogsActivity` on `Conference`), Task 6 (explicit `conference.published/closed/archived` entries with the causer) |
| No stored personal data in scan counting | Task 5 |
| Organizer-supplied HTML cannot inject styles, classes, scripts or third-party media into a public page | Task 4 (`App\Support\Html\RichText` — narrower than Filament's shared macro, which permits `style` and `class` on every element) |
| Email verification on organizer accounts reaches everything organizer-facing | Task 4 (draft preview requires a verified address), Task 10 (`verified:` and `AuthenticateSession` on the asset routes, which live outside the panel that would otherwise enforce both) |
| Downloads require membership | Task 10 (`Gate::authorize('view', $conference)` in every controller action, with guest and cross-tenant datasets over all four routes, plus unpublished and unverified negative tests) |

**Additions beyond the spec** (recorded here so a reviewer knows they were deliberate):

- **Counting cap on `/q/{code}`** (Task 5). Spec section 9 asks for no rate limit here and spec 5.7 requires every visit to redirect and be counted, so the redirect is never refused; only the *counting* is capped per client IP per link, which stops a script inflating the total.
- **Throttle on the asset downloads** (Task 10, `throttle:conference-assets`, 10/minute per account). A poster render is a synchronous dompdf pass on a four-worker pool shared by every tenant.
- **Logo normalisation before dompdf** (Task 10). Without it a large transparent PNG runs dompdf's per-pixel PHP alpha path and can exhaust the 256 MB worker.

### Section 10 — Non-functional (conference parts)

| Requirement | Where |
|---|---|
| Timestamps stored UTC, every conference has its own timezone | Task 2 (column + casts), Task 7 (timezone-aware `DateTimePicker` on save) |
| Times shown to a human are shown in that timezone | Task 8 (`->timezone()` on every date-time entry and the table column), Task 10 (the scan chart cuts days in the conference timezone), Task 11 (widget column). Asserted end to end by "stores a deadline typed in the conference timezone as utc and shows it back locally" |
| Public conference page renders in under 300 ms of server time | Task 4 (plain Blade, one eager load, and a bounded-query test), Task 12 Step 3 (a timing loop in the runbook's release checklist) |
| WCAG AA contrast on organization colours | Task 4 (`OrganizationTheme` + Plan 1's `Contrast`), `OrganizationThemeTest` |
| All strings in language files so Arabic can be added without code changes | **Not done.** Plans 1 and 2 hardcode English in the public views, the countdown script and the poster. Recorded in the backlog (Task 12 Step 4) as a prerequisite for any Arabic work |

### Section 13 — Brand

| Requirement | Where |
|---|---|
| IBM Plex Sans for text, IBM Plex Mono for reference numbers and codes | Task 1 (three TTFs committed and registered with dompdf), Task 10 (the poster's short URL is set in Plex Mono, registered at the `bold` weight the rule asks for) |
| Organization colours drive the public page, with readable text picked automatically | Task 4 (`OrganizationTheme::cssVariables()`, consumed by the layout and the poster) |

### Section 12 — Testing

| Requirement | Where |
|---|---|
| Unit: short code alphabet | Task 5 |
| Unit: slug derivation and per-organization uniqueness | Task 2 |
| Unit: publish gate rules | Task 6 |
| Unit: QR and poster produce real files | Task 10 |
| Unit: contrast on branding | Task 4 (`OrganizationThemeTest`; `Contrast` itself is covered by Plan 1's `ContrastTest`) |
| Feature: resource CRUD within a tenant, cross-tenant isolation | Tasks 7, 9 |
| Feature: transitions and their validation messages | Tasks 6, 8 (the blocker notification is asserted as a whole `Notification`, title and body) |
| Feature: default review form, question reorder and lock | Tasks 6, 9 (including that a locked form refuses `reorderTable()` and that a new question is appended) |
| Feature: public page states and member preview | Task 4 (each negative case paired with a request that must succeed on the same URL, so none of them can pass before the route exists) |
| Feature: short link redirect, counting and the counting cap | Task 5 (a dataset over every branch of the guard, plus two client IPs) |
| Feature: QR/PDF downloads require membership | Task 10 (datasets over all four routes; the PNG is decoded to prove what it encodes, and the poster template's contents are asserted rather than its byte count) |
| No placeholder assertions | Tasks 10, 11 use rendered markup or distinctive numbers, never `assertSee('2')`, and the dashboard test asserts the widget component rather than a word the sidebar also prints |
| Test-first, shown failing, exit code is the gate | Every task: failing test, expected failure text, implementation, passing run |
| Larastan level 6 and Pint | Every task's last step, plus Task 12 |

### Explicit deferrals

1. **Submission form and everything author-facing** (spec 5.3): Plan 3. The public CTA in `resources/views/public/partials/submit-cta.blade.php` renders the correct state text in all four window states; its open state points at `#` and Plan 3 replaces exactly that one `href`. No route named `conference.submit` is registered here, so nothing 404s.
2. **Reviewer invitation, assignment, review** (spec 5.4, 5.5): Plan 4. Plan 2 ships the review-form *structure* and the lock mechanism only. `ConferenceStatus::Reviewing` exists in the enum and the transition table but nothing drives it.
3. **Scoring, ranking, decisions** (spec 5.6): Plan 5. `review_questions.weight`, `scale_min` and `scale_max` are stored configuration here; nothing reads them yet.
4. **Per-conference email templates** (spec 5.2, 5.9): Plan 3, with `submission_draft_saved` and `submission_received`, the first conference-scoped emails.
5. **Organization member management** (spec 4): Plan 4, with the hashed-token invitation flow it shares with reviewer invitations. Plan 1's self-review handed this to Plan 2; moving it on is recorded in the backlog rather than left implicit.
6. **Platform-admin hard purge** (spec 3): Plan 6. Plan 2 ships the RESTRICT foreign keys and a restore action, but no force delete anywhere.
7. **Custom domains** (spec 5.8) and **legacy import** (spec 5.10): Plan 6.
8. **Content-Security-Policy** for the new public page: still Plan 6 per the backlog. The branded page uses one inline `style` attribute for the CSS variables, and the sharing page uses inline styles for the scan chart; the eventual nonce strategy must allow both (or the variables move to a per-organization stylesheet route). Until a CSP exists, `RichText` is what keeps third-party media out of a public page.
9. **Reference number prefix** on the conference (`CPDS26-...`, spec 5.3): Plan 3 adds the column and the generator, because the format only matters once submissions exist.
10. **An organizer panel theme** (`->viteTheme()`): Tailwind utility classes do nothing inside a Filament panel, so Plan 2 uses inline styles and `<x-filament::section>`. Recorded in the backlog together with the Dockerfile change a theme would need.

### Type consistency check

- `CreateConference::handle(Organization, array): Conference` — called by `Pages\CreateConference::handleRecordCreation()` and by `PublishConferenceTest`.
- `CreateDefaultReviewForm::handle(Conference): ReviewForm` — called by `CreateConference`, by `ReviewQuestionsRelationManager::activeReviewForm()` and by three tests.
- `PublishConference::blockers(Conference): list<string>` and `handle(Conference, User): Conference` — called by `ConferenceStatusActions::publish()` and `ConferenceInfolist`.
- `CloseSubmissions::handle(Conference, User): Conference`, `ArchiveConference::handle(Conference, User): Conference` — called by `ConferenceStatusActions`.
- `GenerateConferenceQr::{url,svg,png,fileName}` and `GenerateConferencePoster::{handle,fileName,logoDataUri}` — called by `ConferenceAssetController` and `ConferenceShortLink`; `logoDataUri(Conference): ?string` is public only so `ConferenceQrTest` can assert what dompdf is handed.
- `ShortLink::forTarget(Model): ShortLink`, `ShortLink::url(): string`, `ShortLink::dailyVisitCounts(int $days = 30, string $timezone = 'UTC'): array<string,int>`, `ShortLink::generateCodesUsing(?Closure): void` — called by `PublishConference`, `GenerateConferenceQr`, `ConferenceShortLink` (which passes `$conference->timezone`), `ShortLinkController` and `ShortCodeTest`.
- `RecordShortLinkVisit::handle(ShortLink, string $clientIp): void` — called by `ShortLinkController` with `ClientIp::from($request)`.
- `OrganizationTheme::for(Organization): self` and `cssVariables(): string` — called by `ConferenceController`, the conference layout and `GenerateConferencePoster`.
- `RichText::sanitize(?string): HtmlString` — called by `resources/views/public/conference.blade.php`.
- `PosterFonts::{files,register}` — called by `GenerateConferencePoster` and `PosterFontsTest`.
- `Conference::{uniqueSlug,isPubliclyVisible,submissionWindow,acceptsSubmissions,deadlineInConferenceTimezone,opensAtInConferenceTimezone,publicUrl,getRouteKeyName}` — used by the controller, the Blade views, the policies and the actions. `getRouteKeyName()` returns `ulid`, which is what every Filament-generated URL and every `route('conference-assets.*', $conference)` carries; only `conference.show` asks for the slug, and it does so explicitly.
- `ReviewQuestion::isScored(): bool` — reads `options[*]['score']` for a Select question; Plan 5 consumes it.
- Route names referenced anywhere in this plan and defined in it: `conference.show` (Task 4), `shortlink.show` (Task 5), `conference-assets.qr.svg`, `conference-assets.qr.png`, `conference-assets.poster` (Task 10 — the *names* keep the dotted form even though the paths lost their extensions). Route names referenced from Plan 1 and already defined there: `landing`, `privacy`, `terms`, `filament.organizer.auth.login`, `filament.organizer.auth.email-verification.prompt`.
