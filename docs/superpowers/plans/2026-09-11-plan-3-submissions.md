# CASS v2 Plan 3: Author Submissions, the Status Page, Files and Templated Email

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An author with no account reaches `/c/{org}/{conference}/submit` from the public conference page, fills one branded form (title, abstract with a live word count, track, presentation preference, co-authors, contact phone, the conference's own custom fields, PDF files, the agreement), and either saves a draft or submits. Submitting assigns a reference like `GPCC26-017`, emails the corresponding author a confirmation with a link to `/s/{token}`, and notifies the organization members who opted in. The author edits or withdraws from the status page until the deadline. Organizers read every submission in a tenant-scoped Filament resource, download its files through 30-minute signed URLs, export the list as CSV, resend a status link and withdraw on the author's behalf. Every template key in spec 5.9 has a platform default that an organizer may override per conference, and every outgoing message is written to `email_logs`.

**Architecture:** Single Laravel app. `Submission` is the first conference-owned model that a person without an account can create, so it carries its own bearer credential: a 64-character random token, stored only as a SHA-256 hash, whose plaintext is persisted nowhere: it exists in the link in the email, and the access log that would otherwise capture it is redacted. Business logic lives in `app/Actions/Submissions` and `app/Actions/Mail`; the public Livewire components and the Filament resource validate and delegate. Files never touch the public disk: they are content-addressed on the private `local` disk and reachable only through a signed, expiring route. Email is one pipeline — a template (platform default or per-conference override) rendered by `RenderEmailTemplate`, queued as `TemplatedMail` in the branded mail layout, and logged to `email_logs` by a mail-event listener that also catches Plan 1's notifications.

**Tech Stack:** PHP 8.4, Laravel 13.31, Filament 5.8.1, Livewire 4.4.4, Tailwind 4.3 (Vite), Pest 5.1.4 with the Laravel, Livewire and (new) Browser plugins, Larastan level 6, Pint (strict types), openspout/openspout 4.32 (CSV), spatie/laravel-activitylog 5.1, MySQL 8.4 in CI and production, SQLite in-memory locally.

**Spec:** `docs/superpowers/specs/2026-09-10-cass-v2-design.md` sections 5.3, 5.9, the `/c/{org}/{conference}/submit`, `/s/{token}` and `/files/{ulid}` rows of section 6, section 8 (files and indexes), section 9 (tokens, rate limits, signed URLs, policies), section 10 (language files), section 12 (unit, feature, panel and the one browser test), and the submission parts of 3, 4, 7 and 13.

**Environment facts for every command below**

- Repo root: `C:\Users\ahmed\Documents\CASS` (Git Bash path `/c/Users/ahmed/Documents/CASS`). All commands are Git Bash.
- Composer: run `php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar <args>`. Do **not** use the `composer.bat` wrapper: it passes through cmd.exe and silently strips `^` from version constraints.
- PHP 8.4.23 at `C:\Users\ahmed\AppData\Local\php84\php.exe`. Loaded: `bcmath calendar ctype curl date dom fileinfo filter gd hash iconv intl json libxml mbstring mysqlnd openssl pcre PDO pdo_mysql pdo_sqlite Phar random readline Reflection session SimpleXML SPL sqlite3 standard tokenizer xml xmlreader xmlwriter OPcache zip zlib`. **No `sodium`, no `exif`** — so tokens are `bin2hex(random_bytes(32))` and `hash('sha256', ...)`, never `sodium_*`, and file type checks are `finfo` (through `fileinfo`, which *is* loaded), never `exif_imagetype`. **No `sockets` either** — see fact 2 below; Task 13 turns it on with one line in `php.ini`.
- `php -` (reading a script from stdin) does not work on this machine. Every ad-hoc PHP snippet below is written to a file first and run as `php <file>`.
- The production image (`Dockerfile`) builds `intl gd pdo_mysql zip opcache bcmath pcntl` on `php:8.4-fpm-alpine`. `dom`, `libxml`, `xmlreader`, `filter` and `fileinfo` are compiled into that base image, so **no Dockerfile change is needed for this plan**: openspout's extension list is already satisfied and nothing else new is added to the runtime.
- Node 24.15.0, npm 11.12.1, Docker 29 (daemon running), git, `gh` (logged in as `ahmedsk2`).
- Baseline before this plan: branch `plan-3-submissions`, created from `main` at commit `1a107aa` (Plans 1-2 plus the hummingbird brand), `php artisan test` = **243 passed**, Pint clean, Larastan level 6 clean.
- Commit after every task with the trailer `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- **Verify test runs by exit code**, never by reading piped output: `php artisan test > /tmp/t.log 2>&1; echo "rc=$?"; tail -5 /tmp/t.log`.
- **The test *counts* in every "Expected" line below are approximate** — they were computed by counting `it()` blocks and dataset rows while writing the plan, and a dataset that grows by one case moves them. The `rc=0` in the same line is the gate; a count that is off by a few is not a failure, a count that is off by *dozens* means a file did not run. The one place a count is exact and load-bearing is Task 13 Step 7, where `php artisan test` must report the **same** number before and after the browser suite exists.

**Packages added by this plan**

| Package | Where | Command | Why |
|---|---|---|---|
| `openspout/openspout ^4` | `require` | `php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar require openspout/openspout:"^4"` | Spec section 7 names it for CSV and XLSX export. **It is already installed at v4.32.0** as a transitive dependency of `filament/actions` v5.8.1 (`composer why openspout/openspout`), so this only promotes it to a direct dependency — the same move Plan 2 Task 1 made for `chillerlan/php-qrcode`. Writing `use OpenSpout\...` against a package nobody declared is exactly how a Filament minor release silently breaks a build. |
| `pestphp/pest-plugin-browser ^5` | `require-dev` | `php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar require --dev pestphp/pest-plugin-browser:"^5"` | Spec section 12 requires one end-to-end browser run of the public submission form in CI. |
| `playwright ^1.62` | `devDependencies` (npm) | `npm i -D playwright && npx playwright install --with-deps chromium` | The plugin shells out to `./node_modules/.bin/playwright run-server` (fact 4). |

No Turnstile package. The siteverify call is one `Http::asForm()->post()` and twenty lines of `App\Support\Turnstile`, and every third-party wrapper for it adds a middleware and a Blade component this app does not want — the widget lives inside a Livewire form and the verification happens in that component's own action, not in middleware.

**What this plan does NOT build (kept honest):**

- **Reviewers and reviewing** (spec 5.4, 5.5) stay Plan 4: `ReviewerInvitation`, `ConferenceReviewer`, `ReviewAssignment`, `Review`, `ReviewAnswer`, the reviewer panel, the code that sets `review_forms.locked_at`, and the three reviewer template keys (`reviewer_invitation`, `reviewer_reminder`, `reviewer_overdue`). Plan 3 ships the **defaults and the editor for all eleven keys in spec 5.9** because the editor is one screen and splitting it across three plans guarantees drift — but it *sends* only `submission_draft_saved` and `submission_received`. Nothing in Plan 3 reads a reviewer key.
- **Scoring, ranking and decisions** (spec 5.6) stay Plan 5, including the four `decision_*` template keys and the decision letter on the status page. `SubmissionStatus` declares `under_review`, `accepted`, `rejected` and `waitlisted` so Plan 4 and Plan 5 add behaviour instead of migrating the enum, and the status page renders a neutral status block for them today.
- **Reviewer visibility of submission files** (spec 4, "View submissions and files … assigned or pool only") is Plan 4. `SubmissionFilePolicy` here answers for organization members and the platform admin only.
- **A platform-admin view of submissions and files** (spec section 4, "View submissions and files | Platform admin ✔"). `SubmissionPolicy::before()` and `SubmissionFilePolicy::before()` return true for a platform admin, but there is no screen behind them: the only submission UI is the tenant-scoped organizer panel, and `User::canAccessPanel('organizer')` requires `organizations()->exists()` while `canAccessTenant()` requires membership, so a platform admin who is not a member of the organization cannot reach it. Plan 3 gives the admin panel submission *counts* on the conference list (Task 11) and nothing more. **Plan 6** adds a read-only `App\Filament\Admin\Resources\Submissions\SubmissionResource` (index + view, `$isScopedToTenant = false`, `canAccess()` = `is_platform_admin`), which is also what the hard purge needs to act on.
- **Organization member management** (spec 4) is still Plan 4. Until it lands every organization has exactly its registering owner, so "notify every member with `notify_on_submission` true" reaches one mailbox in production. The column, the cast and the opt-out are all exercised by Task 5's tests with extra members created directly.
- **Custom domains** (5.8), **legacy import** (5.10) and **launch hardening** (CSP, image digest pinning, the platform-admin hard purge that must now also delete files from private storage) stay Plan 6.
- **Per-abstract QR codes and public abstract pages** are spec section 14 (v2 backlog). `short_links.target_type/target_id` is already polymorphic; nothing in Plan 3 points a short link at a `Submission`.
- **XLSX export.** Spec 5.6 asks for CSV *and* XLSX on the *ranking* table, which is Plan 5. Plan 3's submission list exports CSV only; openspout writes both, so Plan 5 adds a writer, not a package.
- **An organizer panel theme.** Still the backlog item from Plan 2: Tailwind utility classes do nothing inside a Filament panel, so anything visual in the panel here uses `<x-filament::section>` and inline styles.

---

## File structure created or changed by this plan

```
app/
  Actions/
    Mail/
      RenderEmailTemplate.php          # placeholders -> subject + markdown body, escaped first
      ResetEmailTemplate.php           # delete the per-conference override
      SaveEmailTemplate.php            # create or update the per-conference override
      SendTemplatedEmail.php           # renders, writes the email_logs row, queues TemplatedMail
    Submissions/
      AllocateReference.php            # lockForUpdate on conferences.submission_counter
      DeleteSubmissionFile.php
      ExportSubmissionsCsv.php         # openspout streamed download
      IssueSubmissionToken.php         # 64 hex chars, sha256 stored, plaintext returned once
      SaveSubmissionDraft.php
      SendSubmissionStatusLink.php     # new token + the matching template; used by "Resend"
      StoreSubmissionFile.php
      SubmitAbstract.php               # blockers() + handle(); reference, emails, notices
      UpdateSubmission.php
      WithdrawSubmission.php
  Enums/
    EmailLogStatus.php                 # queued|sent|failed
    EmailTemplateKey.php               # the eleven keys of spec 5.9 and their placeholders
    PresentationPreference.php         # oral|poster|either
    SubmissionStatus.php               # draft|submitted|withdrawn|under_review|accepted|rejected|waitlisted
  Exceptions/
    SubmissionFileRejected.php
    SubmissionNotAcceptable.php        # list<string> reasons, mirrors ConferenceNotPublishable
  Filament/Admin/
    Resources/Conferences/Tables/ConferencesTable.php   (modified: submissions_count column)
    Resources/EmailLogs/
      EmailLogResource.php             # read-only
      Pages/ListEmailLogs.php
      Pages/ViewEmailLog.php
      Schemas/EmailLogInfolist.php
      Tables/EmailLogsTable.php
  Filament/Organizer/
    Resources/Conferences/
      ConferenceResource.php           (modified: the email-templates page)
      Pages/ConferenceEmailTemplates.php                # all eleven keys, edit/preview/reset
      Schemas/ConferenceForm.php       (modified: reference prefix field)
      Schemas/ConferenceInfolist.php   (modified: submission counts and a link)
    Resources/Submissions/
      Pages/ListSubmissions.php
      Pages/ViewSubmission.php
      Schemas/SubmissionInfolist.php
      SubmissionResource.php
      Tables/SubmissionActions.php     # withdraw / resend / export, shared by table and page
      Tables/SubmissionsTable.php
  Http/Controllers/Public/
    SubmissionFileController.php       # /files/{ulid}, signed
  Listeners/
    RecordOutgoingEmail.php            # MessageSending + MessageSent -> email_logs
  Livewire/Public/
    SubmissionForm.php                 # /c/{org}/{conf}/submit and the status page's edit mode
    SubmissionStatus.php               # /s/{token}
  Mail/
    TemplatedMail.php                  # queued, branded, carries X-CASS-* headers, failed() hook
  Models/
    Conference.php                     (modified: reference fields, submissions/emailTemplates)
    EmailLog.php
    EmailTemplate.php
    Submission.php
    SubmissionAuthor.php
    SubmissionFile.php
  Notifications/
    NewSubmissionNotice.php            # plain queued notification to opted-in members
  Policies/
    EmailLogPolicy.php
    EmailTemplatePolicy.php
    SubmissionFilePolicy.php
    SubmissionPolicy.php
  Support/
    Files/SniffedMimeType.php          # finfo from content plus extension agreement
    Mail/DefaultTemplates.php          # reads lang/en/mail.php, one accessor per key
    Mail/RenderedTemplate.php          # readonly subject + body
    Submissions/ReferencePrefix.php    # GPCC26 from a name and a start date
    Submissions/SubmissionLink.php     # readonly submission + the one-time plaintext token
    Text/WordCounter.php
    Tokens/SubmissionToken.php
    Turnstile.php
config/
  cass.php                             (modified: submission limits, token and link lifetimes)
database/
  factories/{SubmissionFactory,SubmissionAuthorFactory,SubmissionFileFactory,
             EmailTemplateFactory,EmailLogFactory}.php
  migrations/
    2026_09_11_001000_add_reference_fields_to_conferences_table.php
    2026_09_11_001100_create_submissions_table.php
    2026_09_11_001200_create_submission_authors_table.php
    2026_09_11_001300_create_submission_files_table.php
    2026_09_11_001400_create_email_templates_table.php
    2026_09_11_001500_create_email_logs_table.php
lang/
  en/mail.php                          # subject and body for all eleven template keys
  en/submission.php                    # every string of the public submit and status pages
resources/
  js/{app.js,word-count.js}
  views/
    filament/organizer/pages/email-templates.blade.php
    livewire/public/partials/{authors.blade.php,custom-fields.blade.php,files.blade.php}
    livewire/public/submission-form.blade.php
    livewire/public/submission-status.blade.php
    mail/templated.blade.php           # the branded wrapper around a rendered template
    public/partials/submit-cta.blade.php   (modified: exactly one href)
routes/web.php                         (modified: conference.submit, submission.status, files.download)
app/Providers/AppServiceProvider.php   (modified: two rate limiters, two mail listeners)
config/livewire.php                    # the temporary-upload block only, the app's first public write surface
docker/nginx.conf                      (modified: 110m body size, redacted access log)
docker/php.ini                         (modified: post_max_size=110M)
composer.json, composer.lock           (modified: openspout promoted, pest browser plugin)
package.json, package-lock.json        (modified: playwright)
phpunit.xml                            (modified: TURNSTILE_SECRET_KEY, CASS_SUBMISSION_MIN_SECONDS)
phpunit.browser.xml                    # the browser suite, deliberately outside phpunit.xml
.github/workflows/ci.yml               (modified: sockets extension, new `browser` job)
.env.example                           (modified)
docs/runbooks/deploy-production.md     (modified: Turnstile, private storage, status links, email-log triage)
docs/superpowers/plans/backlog.md      (modified)
tests/
  Browser/SubmitAbstractTest.php
  Fixtures/abstract.pdf                # a real 615-byte PDF, generated in Task 2
  Fixtures/not-really.pdf              # a real PNG with a .pdf name
  Feature/Admin/EmailLogsTest.php
  Feature/LanguageCoverageTest.php
  Feature/Mail/EmailLogPipelineTest.php
  Feature/Mail/TemplatedMailTest.php
  Feature/Organizer/ConferenceEmailTemplatesTest.php
  Feature/Organizer/SubmissionResourceTest.php
  Feature/Organizer/ConferenceResourceTest.php   (modified: the reference-prefix case)
  Feature/Public/ConferencePageTest.php          (modified: the CTA href cases)
  Feature/Public/SubmissionFileDownloadTest.php
  Feature/Public/SubmissionFormTest.php
  Feature/Public/SubmissionStatusPageTest.php
  Unit/AllocateReferenceTest.php
  Unit/ReferencePrefixTest.php
  Unit/RenderEmailTemplateTest.php
  Unit/SaveSubmissionDraftTest.php
  Unit/SniffedMimeTypeTest.php
  Unit/SubmissionTest.php
  Unit/SubmissionTokenTest.php
  Unit/SubmitAbstractTest.php
  Unit/TurnstileTest.php
  Unit/WordCounterTest.php
  Pest.php                             (modified: the Browser suite binding)
.gitattributes                         (modified: tests/Fixtures/*.pdf binary)
.gitignore                             (modified: tests/Browser/Screenshots)
```

Files this plan *modifies* that are easy to miss in the tree above: `app/Http/Controllers/Public/ConferenceController.php` (one `setRelation` line, Task 7), `resources/views/components/layouts/conference.blade.php` (a `noindex` prop, Task 8), `app/Filament/Organizer/Resources/Conferences/Tables/ConferencesTable.php` and `Tables/ConferenceStatusActions.php` (the email-templates action, Task 10), and `app/Models/Submission.php`, which is written in Task 1 and extended again in Tasks 2 (`findByPlainToken`) and 9 (`organization`).

---

## Facts verified in `vendor`, in `php -m` and against packagist before writing this plan

Read these before you doubt a name or a claim below. Every one was checked on this machine on 2026-09-11.

1. **`openspout/openspout` v4.32.0 is already in `vendor/`.** `composer why openspout/openspout` answers `filament/actions v5.8.1 requires openspout/openspout (^4.23)`. Task 9 therefore *promotes* it; it does not install it, and `composer.lock` should barely move. Its platform requirements are `php ~8.3|~8.4|~8.5`, `ext-dom`, `ext-fileinfo`, `ext-filter`, `ext-libxml`, `ext-xmlreader`, `ext-zip` — all present locally and all in `php:8.4-fpm-alpine` plus the `zip` the Dockerfile already installs. The CSV API used here is `OpenSpout\Writer\CSV\Writer`, `OpenSpout\Writer\CSV\Options` (public properties `$FIELD_DELIMITER`, `$FIELD_ENCLOSURE`, `$SHOULD_ADD_BOM = true`, `$FLUSH_THRESHOLD`), `Writer::openToFile(string)`, `Writer::addRow(Row)`, `Writer::close()`, and `OpenSpout\Common\Entity\Row::fromValues(array)`.
2. **`pestphp/pest-plugin-browser` v5.0.1 requires `ext-sockets`,** along with `php ^8.4`, `pestphp/pest ^5.0.4` (installed: 5.1.4), `amphp/http-server ^3.4.6`, `amphp/websocket-client ^2.0.2` and `symfony/process ^8.1`. **`sockets` is not enabled on this machine** — it is absent from `php -m` — but `php_sockets.dll` *does* ship in `C:\Users\ahmed\AppData\Local\php84\ext\`, and `php -d extension=php_sockets.dll -r 'var_dump(extension_loaded("sockets"));'` prints `true`. Line 944 of `C:\Users\ahmed\AppData\Local\php84\php.ini` is `;extension=sockets`. Task 13 Step 1 uncomments it, because once the plugin is in `require-dev` a plain `composer install` on this machine fails the platform check without it. Do **not** work around it with `--ignore-platform-req=ext-sockets`: that flag then has to be repeated on every future `composer install` by every future session, and it would be recorded nowhere.
3. **The plugin starts Playwright at test-*collection* time, not at test-*run* time.** `Pest\Browser\Filters\UsesBrowserTestCaseMethodFilter::accept()` calls `ServerManager::instance()->playwright()->start()`, and `Pest\Repositories\TestRepository::set()` runs every registered filter as each test file is *loaded* — which happens before PHPUnit applies `--group` / `--exclude-group`. **A group tag alone therefore does not keep Playwright out of a local `php artisan test` run**: the browser test file must be outside the test suite that run loads. Task 13 puts it in `tests/Browser/`, leaves that directory out of `phpunit.xml` entirely, and adds `phpunit.browser.xml` whose only `<testsuite>` is `tests/Browser`. The `->group('browser')` tag is still applied so `--group=browser` works if the file is ever moved, but the config file is the mechanism.
4. **How the plugin launches the browser.** `Pest\Browser\ServerManager::playwright()` builds `PlaywrightNpmServer::create(PackageJsonDirectory::find(), './node_modules/.bin/playwright run-server --host %s --port %d --mode launchServer', ...)` and waits for the string `Listening on`. If the process is not running it runs `./node_modules/.bin/playwright run-server --version` and throws `PlaywrightNotInstalledException` ("Please run [npm install playwright && npx playwright install]") or `PlaywrightOutdatedException`. So `playwright` must be a real entry in `package.json` — `npx playwright` alone leaves `node_modules/.bin/playwright` absent — and `npx playwright install --with-deps chromium` must have downloaded the browser binary.
5. **The app is served in-process.** `Pest\Browser\Drivers\LaravelHttpServer` is an `Amp\Http\Server\SocketHttpServer` whose request handler boots the Laravel HTTP kernel inside the test process. There is no `php artisan serve`, no second process and no second database: `RefreshDatabase` against the SQLite `:memory:` connection from `phpunit.xml` is visible to the browser's requests, and a fixture created in `beforeEach` is there when Chromium asks for the page.
6. **Browser API surface used in Task 13** (all verified in `src/Api/Concerns/` at v5.0.1): `visit(string $url)`, then `->type(string $field, string $value)`, `->fill()`, `->select(string $field, array|string|int $option)`, `->check(string $field, ?string $value = null)`, `->radio(string $field, string $value)`, `->click(string $text)`, `->press(string $button)`, `->assertSee()`, `->assertDontSee()`, `->assertSeeIn(string $selector, string $text)`, `->assertValue()`, `->waitForText(string $text)`. The URL assertions live in `src/Api/Concerns/MakesUrlAssertions.php` and the whole set is `assertUrlIs`, `assertSchemeIs(Not)`, `assertHostIs(Not)`, `assertPortIs(Not)`, `assertPathBeginsWith`, `assertPathEndsWith`, `assertPathContains`, `assertPathIs(Not)`, `assertRoute`, `assertQueryStringHas/Missing` and `assertFragmentIs/BeginsWith/IsNot` — Task 13 uses `assertPathEndsWith()` and `assertPathBeginsWith()`. **`->attach(string $field, string $path)` exists but is unusable here**: it only calls Playwright's `setInputFiles` (`src/Api/Concerns/InteractsWithElements.php`), and the plugin's in-process server drops uploaded files on the floor — `Pest\Browser\Drivers\LaravelHttpServer::handleRequest()` passes a literal `[]` for the files argument of `Request::create()` (with a `// @TODO files...` comment) and parses a body into parameters only for `application/x-www-form-urlencoded`. Task 13 therefore attaches nothing; uploads are proved in `tests/Feature`.
7. **`MessageSent` hands back the same message object `MessageSending` saw.** `Illuminate\Mail\Events\MessageSent::__get('message')` returns `$this->sent->getOriginalMessage()`, and `Mailer::send()` passes `$message->getSymfonyMessage()` to `MessageSending` and wraps that very instance in the `SentMessage`. A header added while sending is therefore readable when sent — which is how `RecordOutgoingEmail` correlates the two halves without keeping state between them.
8. **A queued mailable has a failure hook.** `Illuminate\Mail\SendQueuedMailable::failed($e)` calls `$this->mailable->failed($e)` when the method exists. `TemplatedMail::failed()` is what flips an `email_logs` row to `failed` with the exception message. A *notification* has no equivalent hook, so a transport failure on Plan 1's `OrganizationApproved` leaves its row at `queued` — stated in the runbook and visible through the admin panel's status filter rather than pretended away.
9. **Custom headers are first-class.** `Mailable::headers()` returning `new Illuminate\Mail\Mailables\Headers(text: ['X-CASS-Template' => '...'])` is applied at `vendor/laravel/framework/src/Illuminate/Mail/Mailable.php:1756-1768` with `addTextHeader($key, $value)`. For a *notification*, the equivalent is `MailMessage::withSymfonyMessage(fn (Email $message) => $message->getHeaders()->addTextHeader(...))`.
10. **Filament 5.8 tables accept array records.** `Filament\Tables\Table::records(?Closure $dataSource)` sets a data source; `Filament\Tables\Concerns\HasRecords::getTableRecords()` takes the `hasQuery() === false` branch, evaluates the closure, and `mapWithKeys()` stamps each array record with `Filament\Support\ArrayRecord::getKeyName()` (`'__key'`) from the collection key when the array does not carry one; `getTableRecordKey(Model|array $record)` reads that key back. This is what lets `ConferenceEmailTemplates` list all **eleven** template keys when only the overridden ones have database rows. Row actions receive the record as `array $record`, not as a model.
11. **Why the email templates screen is a resource *page*, not a relation manager.** A `RelationManager`'s table is bound to its `$relationship` query, and `email_templates` only ever holds *overrides* (spec 5.2: defaults live in code). Listing eleven keys from a table that holds two rows means an array-backed table (fact 10), which a relation manager cannot provide without fighting its own query. `ConferenceEmailTemplates` therefore follows the page pattern this codebase already uses for `ConferenceShortLink` (`conferences/{record}/share`), registered at `conferences/{record}/emails`. Everything the brief asks a relation manager for — all eleven keys, "platform default" vs "customised", an edit action with the placeholder legend, subject and Markdown body, a live preview with sample values, "Reset to default", and a cross-tenant negative test — is on that page. This is a conscious deviation, recorded again in the self-review.
12. **`Model::preventSilentlyDiscardingAttributes()` is on outside production** (`app/Providers/AppServiceProvider.php`). Every `$fillable` below is exact; anything a form sends that is not listed throws instead of being dropped. `Submission` in particular keeps `status`, `ulid`, `reference`, `access_token_hash`, `submitted_at`, `withdrawn_at`, `last_edited_at`, `word_count` and `conference_id` **out** of `$fillable`; all nine are written with `forceFill()` inside the actions.
13. **`organization_members.notify_on_submission` already exists** (boolean, default `true`, `2026_09_10_000300_create_organization_members_table.php`), is cast on `App\Models\OrganizationMember`, and is in the `withPivot([...])` list of both `Organization::members()` and `User::organizations()`. Plan 3 reads it and adds no migration for it.
14. **Turnstile config already exists.** `config/cass.php` has `turnstile.site_key` and `turnstile.secret_key` from `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY`, both present in `.env.example`, and `phpunit.xml` already pins `TURNSTILE_SITE_KEY` to the empty string. Task 7 adds the matching `TURNSTILE_SECRET_KEY` line to `phpunit.xml` so the widget is off by default in tests, and a test that wants it on sets both with `config()->set()`.
15. **Private storage already persists.** `docker-compose.production.yml` mounts the named volume `cass-storage` at `/var/www/html/storage/app`, and the `local` disk's root is `storage_path('app/private')` (`config/filesystems.php`), which is inside it. No compose change, and `storage/app/private` is already created and chowned by the Dockerfile.
16. **`/files/{ulid}` must not end in a file extension.** `docker/nginx.conf` has a regex location matching `\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?)$` that is tried before `location /`. Plan 2 fixed it to fall through to `index.php`, but a download route should not depend on that fallback, so the route path carries the bare ULID and the file name comes from `Content-Disposition` — exactly the rule the conference asset routes follow.
17. **Plan 2 fact 7, still load-bearing here:** a *missing* policy, or a policy missing the one method Filament asks for, means **ALLOW**, not 403 (`vendor/filament/filament/src/helpers.php:60-93`). Every ability Filament may call is spelled out on all four new policies, including `deleteAny`, `restoreAny` and `forceDeleteAny`, and `DeleteBulkAction` is authorized with `deleteAny` unless `->authorizeIndividualRecords()` is set.
18. **Heroicon cases used below, all confirmed present** in `vendor/filament/support/src/Icons/Heroicon.php`: `OutlinedDocumentText`, `OutlinedClipboardDocumentList`, `OutlinedEnvelope`, `OutlinedEnvelopeOpen`, `OutlinedArrowDownTray`, `OutlinedArrowPath`, `OutlinedNoSymbol`, `OutlinedPaperClip`, `OutlinedInbox`, `OutlinedInboxStack`, `OutlinedExclamationTriangle`, `OutlinedCheckCircle`, `OutlinedEye`, `OutlinedIdentification`, `OutlinedUserGroup`, `OutlinedHashtag`, `OutlinedChatBubbleLeftRight`.
19. **`Filament\Infolists\Components\RepeatableEntry` exists** (`vendor/filament/infolists/src/Components/RepeatableEntry.php`) and is what renders the authors and files blocks on the submission view page.
20. **Livewire 4.4.4 file uploads:** the trait is `Livewire\WithFileUploads` and an uploaded file arrives as `Livewire\Features\SupportFileUploads\TemporaryUploadedFile extends Illuminate\Http\UploadedFile`. Its `getRealPath()` points at the temporary-upload disk, which is why `StoreSubmissionFile` reads the *stream* rather than assuming a local path.
21. **`lang/` does not exist.** Task 3 Step 3 writes `lang/en/mail.php` (which is what first creates `lang/en`) and Task 6 Step 7 writes `lang/en/submission.php`, both by hand; Tasks 7, 8 and 12 only append to them. `php artisan lang:publish` publishes the *framework's* validation strings and is deliberately not run — nothing here overrides a framework message.
22. **Plan 2 fact 14 still applies:** `Select::options(SomeEnum::class)` registers an `EnumStateCast`, so inside a Filament schema `$get('status')` hands back the **enum case**, never the backing string. Compare against `SubmissionStatus::Submitted`, not `->value`. The public Livewire form is the opposite: it is plain HTML, `wire:model` carries the backing string, and the component casts on the way into the action.
23. **Plan 2 fact 16 still applies:** Filament builds record URLs from `$model->getRouteKey()` and resolves them with the resource's `$recordRouteKeyName`. `Submission::getRouteKeyName()` returns `ulid` and `SubmissionResource` sets no `$recordRouteKeyName`, so the two agree. The public routes ask for what they want explicitly: `{conference:slug}` on `/c/...`, a bare `{token}` on `/s/...`, a bare `{ulid}` on `/files/...`.
24. **`Markdown::withSecuredEncoding()` is on** (`AppServiceProvider`), so `Illuminate\Mail\Markdown::parse()` HTML-escapes before parsing. That is *not* enough on its own for template bodies: an organizer's Markdown must still render as Markdown while the *placeholder values* must not, which is why `RenderEmailTemplate` escapes each value with `e()` **before** substitution and the mail view renders the finished body through the Markdown component.

---

### Task 1: Schema, enums, models, factories and policies

Everything the rest of the plan writes to. Nothing here has behaviour beyond casts, relations and authorization: the actions arrive in Tasks 2-5.

`App\Support\Submissions\ReferencePrefix` lives in this task rather than in Task 2 because `Conference::booted()` calls it while creating a row, and a migration plus a model hook that cannot run is not a finishable task. Task 2 owns the *number* half of references (`AllocateReference`, the counter and the `GPCC26-017` format).

**Files:**
- Create: `database/migrations/2026_09_11_001000_add_reference_fields_to_conferences_table.php`, `..._001100_create_submissions_table.php`, `..._001200_create_submission_authors_table.php`, `..._001300_create_submission_files_table.php`, `..._001400_create_email_templates_table.php`, `..._001500_create_email_logs_table.php`
- Create: `app/Enums/{SubmissionStatus,PresentationPreference,EmailTemplateKey,EmailLogStatus}.php`
- Create: `app/Models/{Submission,SubmissionAuthor,SubmissionFile,EmailTemplate,EmailLog}.php`
- Create: `app/Support/Submissions/ReferencePrefix.php`
- Create: `app/Support/Text/WordCounter.php` (stub), `app/Support/Tokens/SubmissionToken.php`
- Create: `app/Policies/{SubmissionPolicy,SubmissionFilePolicy,EmailTemplatePolicy,EmailLogPolicy}.php`
- Create: `database/factories/{SubmissionFactory,SubmissionAuthorFactory,SubmissionFileFactory,EmailTemplateFactory,EmailLogFactory}.php`
- Modify: `app/Models/Conference.php`, `app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceForm.php`, `config/cass.php`
- Test: `tests/Unit/SubmissionTest.php`, `tests/Unit/ReferencePrefixTest.php`, `tests/Feature/Organizer/ConferenceResourceTest.php` (one added case)

- [ ] **Step 1: Confirm the branch and the baseline**

```bash
cd /c/Users/ahmed/Documents/CASS && git status -sb | head -2 && git log --oneline -1 && \
php artisan test > /tmp/t.log 2>&1; echo "rc=$?"; tail -3 /tmp/t.log
```

Expected: `## plan-3-submissions...`, `4961530 Plan 2: ...`, `rc=0`, `Tests: 232 passed`.

- [ ] **Step 2: Write the failing tests**

`tests/Unit/ReferencePrefixTest.php`
```php
<?php

declare(strict_types=1);

use App\Support\Submissions\ReferencePrefix;
use Carbon\CarbonImmutable;

it('takes the initials of the words in the name and the two-digit year', function (string $name, ?string $startsAt, string $expected) {
    expect(ReferencePrefix::derive($name, $startsAt === null ? null : CarbonImmutable::parse($startsAt)))->toBe($expected);
})->with([
    // The spec's own example shape: four initials plus the year of the first day.
    ['Gulf Pediatric Critical Care 2026', '2026-11-03', 'GPCC26'],
    // Digits and short joining words are not initials.
    ['The Annual Meeting of the Saudi Society', '2027-02-01', 'AMSS27'],
    // Six letters is the cap, so a seven-word name loses its tail.
    ['Alpha Beta Gamma Delta Epsilon Zeta Eta', '2026-05-05', 'ABGDEZ26'],
    // One word gives one initial, which nobody could recognise on a badge, so
    // the fallback is the first four letters instead.
    ['Symposium', '2026-09-09', 'SYMP26'],
    // Non-latin names have no A-Z initials at all; fall back to a fixed stem so
    // the reference is still printable and unique per conference.
    ['ملتقى الرعاية الحرجة', '2026-04-04', 'CASS26'],
    // No start date: the year the conference row is created.
    ['Winter School', null, 'WS'.date('y')],
]);

it('never exceeds the twelve characters the column allows and is upper-case alphanumeric', function () {
    $prefix = ReferencePrefix::derive('Alpha Beta Gamma Delta Epsilon Zeta Eta Theta Iota', CarbonImmutable::parse('2026-01-01'));

    expect(strlen($prefix))->toBeLessThanOrEqual(12)
        ->and($prefix)->toMatch('/^[A-Z0-9]+$/');
});
```

`tests/Unit/SubmissionTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\SubmissionFile;
use App\Models\Track;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores a submission with a ulid, draft status and the casts the panel reads', function () {
    $submission = Submission::factory()->create([
        'title' => 'Early mobilisation after cardiac surgery',
        'presentation_preference' => PresentationPreference::Poster,
        'custom_field_values' => ['funding_source' => 'None'],
    ]);

    // Read it back: the factory's in-memory attributes satisfy every assertion
    // below even with the casts removed, so only a round trip exercises them.
    $submission->refresh();

    expect($submission->ulid)->toHaveLength(26)
        ->and($submission->status)->toBe(SubmissionStatus::Draft)
        ->and($submission->presentation_preference)->toBe(PresentationPreference::Poster)
        ->and($submission->custom_field_values)->toBe(['funding_source' => 'None'])
        ->and($submission->word_count)->toBeInt()
        ->and($submission->reference)->toBeNull()
        ->and($submission->submitted_at)->toBeNull()
        ->and(strlen((string) $submission->access_token_hash))->toBe(64);
});

it('refuses to mass assign the fields only the actions may write', function (string $attribute, mixed $value) {
    // Model::preventSilentlyDiscardingAttributes() is on outside production, so
    // a form field that reaches fill() by accident throws here instead of being
    // silently dropped. These nine are the whole guarded surface.
    expect(fn () => (new Submission)->fill([$attribute => $value]))
        ->toThrow(MassAssignmentException::class);
})->with([
    ['status', 'submitted'],
    ['ulid', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['reference', 'GPCC26-001'],
    ['access_token_hash', str_repeat('a', 64)],
    ['submitted_at', '2026-09-11 10:00:00'],
    ['withdrawn_at', '2026-09-11 10:00:00'],
    ['last_edited_at', '2026-09-11 10:00:00'],
    ['word_count', 250],
    ['conference_id', 1],
]);

it('orders authors and files by sort and finds the corresponding author', function () {
    $submission = Submission::factory()->create();

    SubmissionAuthor::factory()->for($submission)->create(['sort' => 2, 'name' => 'Second', 'email' => 'second@example.org']);
    SubmissionAuthor::factory()->for($submission)->corresponding()->create(['sort' => 1, 'name' => 'First', 'email' => 'first@example.org']);
    SubmissionFile::factory()->for($submission)->create(['sort' => 2, 'original_name' => 'appendix.pdf']);
    SubmissionFile::factory()->for($submission)->create(['sort' => 1, 'original_name' => 'abstract.pdf']);

    expect($submission->authors->pluck('name')->all())->toBe(['First', 'Second'])
        ->and($submission->files->pluck('original_name')->all())->toBe(['abstract.pdf', 'appendix.pdf'])
        ->and($submission->correspondingAuthor()?->email)->toBe('first@example.org');
});

it('keeps a track optional and detaches it rather than deleting the submission', function () {
    $conference = Conference::factory()->create();
    $track = Track::factory()->for($conference)->create();
    $submission = Submission::factory()->for($conference)->create(['track_id' => $track->id]);

    expect($submission->track?->is($track))->toBeTrue();

    $track->delete();

    expect($submission->refresh()->track_id)->toBeNull()
        ->and(Submission::query()->whereKey($submission->getKey())->exists())->toBeTrue();
});

it('derives the reference prefix when a conference is created and leaves an explicit one alone', function () {
    $derived = Conference::factory()->create([
        'name' => 'Gulf Pediatric Critical Care',
        'starts_at' => '2026-11-03',
    ]);
    $chosen = Conference::factory()->create(['reference_prefix' => 'KSAU30']);

    expect($derived->refresh()->reference_prefix)->toBe('GPCC26')
        ->and($derived->submission_counter)->toBe(0)
        ->and($chosen->refresh()->reference_prefix)->toBe('KSAU30');
});

it('counts submissions by status for the conference view', function () {
    $conference = Conference::factory()->create();
    Submission::factory()->count(2)->for($conference)->create();
    Submission::factory()->count(3)->for($conference)->submitted()->create();
    Submission::factory()->for($conference)->withdrawn()->create();

    expect($conference->submissions()->count())->toBe(6)
        ->and($conference->submissionCounts())->toBe([
            'total' => 6,
            'draft' => 2,
            'submitted' => 3,
            'withdrawn' => 1,
        ]);
});

it('stores a per-conference email template override and an email log row', function () {
    $conference = Conference::factory()->create();
    $template = EmailTemplate::factory()->for($conference)->create(['key' => 'submission_received']);
    $log = EmailLog::factory()->for($conference)->create(['to_email' => 'author@example.org']);

    expect($conference->emailTemplates()->count())->toBe(1)
        ->and($template->conference->is($conference))->toBeTrue()
        ->and($log->refresh()->status->value)->toBe('queued')
        ->and($log->ulid)->toHaveLength(26);
});
```

- [ ] **Step 3: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/SubmissionTest.php tests/Unit/ReferencePrefixTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found|does not exist" /tmp/t.log | head -3
```

Expected: `rc=1`, `Class "App\Support\Submissions\ReferencePrefix" not found`.

- [ ] **Step 4: Write the six migrations**

`database/migrations/2026_09_11_001000_add_reference_fields_to_conferences_table.php`
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
            // Nullable rather than defaulted: the value is derived from the
            // name and the start date, which a column default cannot express,
            // and conferences created before this migration have to keep
            // working. Conference::referencePrefix() derives one on the fly for
            // those; the creating hook fills it for everything new.
            $table->string('reference_prefix', 12)->nullable()->after('terms');
            // The per-conference sequence behind GPCC26-017. Incremented under
            // lockForUpdate() inside SubmitAbstract's transaction (Task 2), so
            // two authors pressing Submit at the same second cannot draw the
            // same number.
            $table->unsignedInteger('submission_counter')->default(0)->after('reference_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('conferences', function (Blueprint $table) {
            $table->dropColumn(['reference_prefix', 'submission_counter']);
        });
    }
};
```

`database/migrations/2026_09_11_001100_create_submissions_table.php`
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
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // RESTRICT for the same reason conferences restrict organizations:
            // spec section 3 makes deletion a soft delete, and the platform
            // admin's hard purge has to cascade in application code because it
            // also has to unlink files from private storage (backlog, Plan 6).
            $table->foreignId('conference_id')->constrained()->restrictOnDelete();
            // A deleted track must not take submissions with it. The author
            // picked a theme; losing the theme is not losing the abstract.
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            // Null until the abstract is actually submitted: a draft has no
            // number, and numbers are not handed out to rows that may never
            // come back.
            $table->string('reference', 32)->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('title');
            // Plain text, not HTML: the abstract is typed into a textarea, the
            // word count has to match what the author sees, and nothing on the
            // public or panel side ever renders it unescaped.
            $table->text('abstract');
            $table->unsignedInteger('word_count')->default(0);
            $table->string('presentation_preference', 16)->nullable();
            $table->string('contact_phone', 40)->nullable();
            // Keyed by custom_fields.key, which CustomField derives once and
            // never re-derives precisely so that these stay readable.
            $table->json('custom_field_values')->nullable();
            // SHA-256 hex of the author's access token (spec section 9). The
            // plaintext exists only inside the emailed link. `unique` is what
            // makes the /s/{token} lookup a single indexed read.
            $table->char('access_token_hash', 64)->unique();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('last_edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Spec section 8. The reference is unique inside one conference,
            // not globally: two organizations may both print GPCC26-001.
            $table->unique(['conference_id', 'reference']);
            $table->index(['conference_id', 'status']);
            // The admin panel and the nightly reports filter on status alone.
            $table->index('status');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
```

`database/migrations/2026_09_11_001200_create_submission_authors_table.php`
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
        Schema::create('submission_authors', function (Blueprint $table) {
            $table->id();
            // CASCADE, unlike everything else in this plan: an author row has
            // no meaning without its submission, carries no file and is never
            // referenced from anywhere else, so the purge in Plan 6 does not
            // need application code to reach it.
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('name', 180);
            $table->string('email');
            $table->string('affiliation')->nullable();
            $table->boolean('is_presenter')->default(false);
            $table->boolean('is_corresponding')->default(false);
            $table->timestamps();

            $table->index(['submission_id', 'sort']);
            // The organizer table searches on author email (spec 5.6 asks for
            // it on the ranking table; the submission list needs it first).
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_authors');
    }
};
```

`database/migrations/2026_09_11_001300_create_submission_files_table.php`
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
        Schema::create('submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->ulid('ulid')->unique();
            $table->string('original_name');
            // Content-addressed: {first 2 of sha256}/{ulid}.{ext} on the
            // private `local` disk (spec section 8). The prefix directory keeps
            // any single directory from growing past a few thousand entries on
            // the ext4 volume.
            $table->string('path');
            $table->string('mime', 128);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            // Dedupe inside one submission: re-uploading the same PDF is a
            // mistake, not a second file, and this turns it into a clean
            // constraint violation StoreSubmissionFile can answer for.
            $table->unique(['submission_id', 'sha256']);
            $table->index(['submission_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_files');
    }
};
```

`database/migrations/2026_09_11_001400_create_email_templates_table.php`
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
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            // CASCADE: a template override is configuration of one conference
            // and is worthless without it.
            $table->foreignId('conference_id')->constrained()->cascadeOnDelete();
            // Not an enum column: EmailTemplateKey grows in Plans 4 and 5, and
            // a string keeps the migration out of that change. Rows whose key
            // no longer resolves are ignored by DefaultTemplates rather than
            // breaking a send.
            $table->string('key', 48);
            $table->string('subject');
            $table->text('body');
            $table->timestamps();

            // A row exists only when an organizer overrides a platform default,
            // so this is both the uniqueness rule and the lookup index.
            $table->unique(['conference_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
```

`database/migrations/2026_09_11_001500_create_email_logs_table.php`
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
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            // The correlation id that travels in the X-CASS-Log header of the
            // real message (Task 3). A ULID rather than the primary key, so an
            // outgoing header never leaks how many emails the platform has
            // sent, and so it matches spec section 3 on public identifiers.
            $table->ulid('ulid')->unique();
            // All three are nullable and nullOnDelete: the log outlives what it
            // refers to. A platform-wide email (Plan 1's organization
            // notifications, a password reset) has all three null.
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conference_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_key', 48)->nullable();
            $table->string('mailable');
            $table->string('to_email');
            $table->string('subject');
            $table->string('status', 16)->default('queued');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('to_email');
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
```

- [ ] **Step 5: Write the four enums**

`app/Enums/SubmissionStatus.php`
```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The whole lifecycle, written once. Plan 3 drives only the first three:
 * Draft, Submitted and Withdrawn. Plan 4 moves Submitted -> UnderReview and
 * Plan 5 moves UnderReview -> Accepted / Rejected / Waitlisted. Declaring them
 * now means those plans add behaviour instead of migrating an enum that is
 * already in a `status` column and in three indexes.
 */
enum SubmissionStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Withdrawn = 'withdrawn';
    case UnderReview = 'under_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Waitlisted = 'waitlisted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Withdrawn => 'Withdrawn',
            self::UnderReview => 'Under review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Not accepted',
            self::Waitlisted => 'Waitlisted',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'info',
            self::Withdrawn => 'danger',
            self::UnderReview => 'warning',
            self::Accepted => 'success',
            self::Rejected => 'danger',
            self::Waitlisted => 'warning',
        };
    }

    /**
     * The author may still edit and withdraw. A withdrawn abstract stays
     * withdrawn, and once reviewing has started the text is frozen because
     * reviewers are already scoring it.
     */
    public function isOpenToAuthor(): bool
    {
        return in_array($this, [self::Draft, self::Submitted], true);
    }

    /** Statuses this plan actually writes; the rest render read-only. */
    public function isDrivenInPlan3(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::Withdrawn], true);
    }

    /** Counted as "in the pile" by the organizer's conference view. */
    public function countsAsReceived(): bool
    {
        return ! in_array($this, [self::Draft, self::Withdrawn], true);
    }
}
```

`app/Enums/PresentationPreference.php`
```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The values match the strings stored in `conferences.presentation_types`
 * (Plan 2's CheckboxList options), so a conference offering only oral and
 * poster is filtered with a plain in_array against the backing values.
 */
enum PresentationPreference: string implements HasLabel
{
    case Oral = 'oral';
    case Poster = 'poster';
    case Either = 'either';

    public function getLabel(): string
    {
        return match ($this) {
            self::Oral => 'Oral presentation',
            self::Poster => 'Poster',
            self::Either => 'Either is fine',
        };
    }
}
```

`app/Enums/EmailTemplateKey.php`
```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The eleven keys of spec 5.9, and which of the ten placeholders each one may
 * use. The placeholder list is what the editor prints as a legend, what the
 * preview fills with sample values, and what the unit test checks a default
 * body never exceeds - an unknown placeholder is left literal at render time
 * rather than blanked, so a typo shows up in the preview instead of in an
 * author's inbox.
 */
enum EmailTemplateKey: string implements HasLabel
{
    case SubmissionReceived = 'submission_received';
    case SubmissionDraftSaved = 'submission_draft_saved';
    case ReviewerInvitation = 'reviewer_invitation';
    case ReviewerReminder = 'reviewer_reminder';
    case ReviewerOverdue = 'reviewer_overdue';
    case DecisionAcceptedOral = 'decision_accepted_oral';
    case DecisionAcceptedPoster = 'decision_accepted_poster';
    case DecisionWaitlisted = 'decision_waitlisted';
    case DecisionRejected = 'decision_rejected';
    case OrganizationApproved = 'organization_approved';
    case OrganizationRejected = 'organization_rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::SubmissionReceived => 'Abstract received',
            self::SubmissionDraftSaved => 'Draft saved',
            self::ReviewerInvitation => 'Reviewer invitation',
            self::ReviewerReminder => 'Reviewer reminder',
            self::ReviewerOverdue => 'Reviewer overdue',
            self::DecisionAcceptedOral => 'Decision: accepted for oral presentation',
            self::DecisionAcceptedPoster => 'Decision: accepted for poster',
            self::DecisionWaitlisted => 'Decision: waitlisted',
            self::DecisionRejected => 'Decision: not accepted',
            self::OrganizationApproved => 'Organization approved',
            self::OrganizationRejected => 'Organization rejected',
        };
    }

    /**
     * The two organization keys are platform-wide: they are sent once, to an
     * organization owner, by Plan 1's OrganizationApproved / OrganizationRejected
     * notifications, at a moment when no conference exists. They appear in the
     * per-conference editor (so the list of template keys an organizer sees
     * matches the spec) but cannot be overridden there, because a
     * conference-scoped override of them would never be read. Moving those two
     * notifications onto this pipeline is Plan 6.
     */
    public function isConferenceScoped(): bool
    {
        return ! in_array($this, [self::OrganizationApproved, self::OrganizationRejected], true);
    }

    /** @return list<string> */
    public function placeholders(): array
    {
        return match ($this) {
            self::SubmissionReceived => ['author_name', 'title', 'reference', 'conference', 'organization', 'deadline', 'status_link'],
            self::SubmissionDraftSaved => ['author_name', 'title', 'conference', 'organization', 'deadline', 'status_link'],
            self::ReviewerInvitation,
            self::ReviewerReminder,
            self::ReviewerOverdue => ['reviewer_name', 'conference', 'organization', 'deadline', 'review_link'],
            self::DecisionAcceptedOral,
            self::DecisionAcceptedPoster,
            self::DecisionWaitlisted,
            self::DecisionRejected => ['author_name', 'title', 'reference', 'conference', 'organization', 'decision', 'status_link'],
            self::OrganizationApproved,
            self::OrganizationRejected => ['organization', 'status_link'],
        };
    }

    /**
     * The placeholders a SUBJECT may use: every one except the two that expand
     * to a link. A rendered subject is stored verbatim in `email_logs.subject`,
     * listed and shown in the admin panel, and carried in a clear-text SMTP
     * header - so `{{status_link}}`, which expands to the author's bearer
     * credential, must not be substitutable into one. No platform default puts
     * a link in a subject, so this narrows nothing that exists today; it stops
     * an organizer typing one into the editor.
     *
     * @return list<string>
     */
    public function subjectPlaceholders(): array
    {
        return array_values(array_diff($this->placeholders(), ['status_link', 'review_link']));
    }

    /**
     * Sample values for the editor's live preview, so an organizer sees the
     * finished sentence rather than a wall of braces.
     *
     * @return array<string, string>
     */
    public function sampleValues(): array
    {
        $samples = [
            'author_name' => 'Dr Sara Al-Harbi',
            'reviewer_name' => 'Dr Omar Khan',
            'title' => 'Early mobilisation after cardiac surgery',
            'reference' => 'GPCC26-017',
            'conference' => 'Gulf Pediatric Critical Care 2026',
            'organization' => 'Gulf Pediatric Society',
            'deadline' => '3 November 2026, 23:59 (Asia/Riyadh)',
            'status_link' => 'https://cass.towardpcc.com/s/0123456789abcdef',
            'review_link' => 'https://cass.towardpcc.com/review',
            'decision' => 'Accepted for oral presentation',
        ];

        return array_intersect_key($samples, array_flip($this->placeholders()));
    }
}
```

`app/Enums/EmailLogStatus.php`
```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EmailLogStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Sent => 'success',
            self::Failed => 'danger',
        };
    }
}
```

- [ ] **Step 6: Write `app/Support/Submissions/ReferencePrefix.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use Carbon\CarbonInterface;

/**
 * The letters in front of a reference number: GPCC26 for "Gulf Pediatric
 * Critical Care" starting in 2026. Printed on badges and read out loud, so the
 * rules are deliberately dull - initials of the words in the name that a human
 * would read out, with the joining words (a, an, and, at, for, in, of, on, the,
 * to, with) dropped, capped at six letters, plus the two-digit year of the
 * first day.
 *
 * Pure, so it is unit-testable without a database, and separate from
 * AllocateReference (Task 2) which owns the counter and the final format.
 */
final class ReferencePrefix
{
    public const MAX_LETTERS = 6;

    /** Used when a name has no usable A-Z letters at all. */
    public const FALLBACK = 'CASS';

    /** Joining words a human would not read out as an initial. English only. */
    private const SKIP = ['a', 'an', 'and', 'at', 'for', 'in', 'of', 'on', 'the', 'to', 'with'];

    public static function derive(string $name, ?CarbonInterface $startsAt): string
    {
        return self::letters($name).($startsAt?->format('y') ?? date('y'));
    }

    private static function letters(string $name): string
    {
        // Transliteration is not attempted: a name with no latin letters gets
        // the fallback stem rather than a machine-made approximation that the
        // organizer never chose. The prefix is editable in the form.
        $words = preg_split('/[^A-Za-z]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // "The Annual Meeting of the Saudi Society" is AMSS, not TAMOTS: a
        // joining word contributes nothing anyone would recognise on a badge.
        $significant = array_values(array_filter(
            $words,
            static fn (string $word): bool => ! in_array(strtolower($word), self::SKIP, true),
        ));

        // A name made only of joining words keeps them rather than vanishing.
        if ($significant === []) {
            $significant = $words;
        }

        $initials = '';
        foreach ($significant as $word) {
            $initials .= strtoupper($word[0]);
        }

        if (strlen($initials) >= 2) {
            return substr($initials, 0, self::MAX_LETTERS);
        }

        // One word (or one usable letter) makes a prefix nobody recognises, so
        // use the start of the word itself: "Symposium" becomes SYMP, not S.
        if ($significant !== []) {
            return strtoupper(substr($significant[0], 0, 4));
        }

        return self::FALLBACK;
    }
}
```

- [ ] **Step 7: Write the five models**

`app/Models/Submission.php`
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    /**
     * Nine attributes are deliberately absent, and every one of them is written
     * with forceFill() inside an action: `conference_id` (set by the route, not
     * the form), `ulid`, `status`, `reference`, `access_token_hash`,
     * `submitted_at`, `withdrawn_at`, `last_edited_at` and `word_count` (which
     * is recomputed from `abstract` server-side, never trusted from the page).
     *
     * @var list<string>
     */
    protected $fillable = [
        'title', 'abstract', 'track_id', 'presentation_preference',
        'contact_phone', 'custom_field_values',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'presentation_preference' => PresentationPreference::class,
            'custom_field_values' => 'array',
            'word_count' => 'integer',
            'submitted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'last_edited_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Submission $submission): void {
            $submission->ulid ??= (string) Str::ulid();
        });
    }

    /** Spec section 3: ULIDs are the public identifiers (fact 23). */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }

    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /** @return HasMany<SubmissionAuthor, $this> */
    public function authors(): HasMany
    {
        return $this->hasMany(SubmissionAuthor::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<SubmissionFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * Exactly one author carries the flag - SubmitAbstract and
     * SaveSubmissionDraft both enforce that - so first() is the answer, not a
     * guess. Reads the loaded collection when there is one so the organizer
     * table does not issue a query per row.
     */
    public function correspondingAuthor(): ?SubmissionAuthor
    {
        if ($this->relationLoaded('authors')) {
            return $this->authors->firstWhere('is_corresponding', true);
        }

        return $this->authors()->where('is_corresponding', true)->first();
    }

    /** The author may still change this abstract, window permitting. */
    public function isOpenToAuthor(): bool
    {
        return $this->status->isOpenToAuthor() && $this->conference->acceptsSubmissions();
    }

    /**
     * Built from the plaintext token, which exists only for the moment
     * SaveSubmissionDraft or IssueSubmissionToken returns it. Nothing stored on
     * this row can reconstruct it.
     *
     * The `submission.status` route this resolves is registered in Task 4,
     * ahead of the component behind it, precisely because this method is called
     * from Task 5 onwards.
     */
    public function statusUrl(string $plainToken): string
    {
        return route('submission.status', ['token' => $plainToken]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // Never `abstract` and never `access_token_hash`: the activity log
            // is readable by the platform admin, and neither belongs there.
            ->logOnly(['status', 'reference', 'title', 'submitted_at', 'withdrawn_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
```

`app/Models/SubmissionAuthor.php`
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SubmissionAuthorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionAuthor extends Model
{
    /** @use HasFactory<SubmissionAuthorFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'affiliation', 'is_presenter', 'is_corresponding', 'sort'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_presenter' => 'boolean',
            'is_corresponding' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
```

`app/Models/SubmissionFile.php`
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SubmissionFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class SubmissionFile extends Model
{
    /** @use HasFactory<SubmissionFileFactory> */
    use HasFactory;

    /**
     * Every column is written by StoreSubmissionFile from the sniffed stream,
     * never from the request: a `path`, a `mime` or a `sha256` that a caller
     * could mass assign is a path-traversal and a content-type spoof in one.
     */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size' => 'integer', 'sort' => 'integer'];
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

    /**
     * Spec section 8: downloads only through signed routes. Thirty minutes is
     * long enough to click a link in an email client that prefetches, short
     * enough that a forwarded URL is dead by the time it is forwarded.
     */
    public function temporaryUrl(?int $minutes = null): string
    {
        return URL::temporarySignedRoute(
            'files.download',
            now()->addMinutes($minutes ?? (int) config('cass.file_url_minutes')),
            ['ulid' => $this->ulid],
        );
    }

    public function extension(): string
    {
        return strtolower(pathinfo((string) $this->original_name, PATHINFO_EXTENSION));
    }

    public function readStream(): mixed
    {
        return Storage::disk('local')->readStream((string) $this->path);
    }
}
```

`app/Models/EmailTemplate.php`
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailTemplateKey;
use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplate extends Model
{
    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory;

    /**
     * `key` and `conference_id` identify the row and are set by
     * SaveEmailTemplate; only the two editable fields are fillable.
     *
     * @var list<string>
     */
    protected $fillable = ['subject', 'body'];

    /**
     * `key` is not cast to EmailTemplateKey. A row whose key no longer resolves
     * (a template retired by a later plan) must still load and still be
     * deletable; an enum cast would throw on hydration instead.
     */
    public function templateKey(): ?EmailTemplateKey
    {
        return EmailTemplateKey::tryFrom((string) $this->key);
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }
}
```

`app/Models/EmailLog.php`
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailLogStatus;
use Database\Factories\EmailLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailLog extends Model
{
    /** @use HasFactory<EmailLogFactory> */
    use HasFactory;

    /** Written only by SendTemplatedEmail and RecordOutgoingEmail. */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EmailLogStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EmailLog $log): void {
            $log->ulid ??= (string) Str::ulid();
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

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
```

- [ ] **Step 8: Extend `app/Models/Conference.php`**

Add the imports `use App\Enums\SubmissionStatus;` and `use App\Support\Submissions\ReferencePrefix;`, then:

1. Add `'reference_prefix'` to `$fillable` (after `'terms'`). `submission_counter` stays out: it is the sequence, and only `AllocateReference` may move it.
2. Add `'submission_counter' => 'integer'` to `casts()`.
3. Extend the `creating` hook:

```php
    protected static function booted(): void
    {
        static::creating(function (Conference $conference): void {
            $conference->ulid ??= (string) Str::ulid();
            $conference->slug ??= static::uniqueSlug((int) $conference->organization_id, (string) $conference->name);
            // Derived once, on create, so an organizer who renames a
            // conference after the first abstract arrives does not silently
            // change the prefix printed on every reference already issued.
            // The form lets them change it by hand while the conference is
            // still a draft.
            $conference->reference_prefix ??= ReferencePrefix::derive(
                (string) $conference->name,
                $conference->starts_at,
            );
        });
    }
```

4. Add the relations and the helpers after `shortLink()`:

```php
    /** @return HasMany<Submission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /** @return HasMany<EmailTemplate, $this> */
    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class);
    }

    /**
     * Rows created before the reference columns existed have none, and the
     * organizer may have cleared the field; derive rather than return null, so
     * AllocateReference never has to handle a prefix-less conference.
     */
    public function referencePrefix(): string
    {
        return $this->reference_prefix !== null && $this->reference_prefix !== ''
            ? $this->reference_prefix
            : ReferencePrefix::derive((string) $this->name, $this->starts_at);
    }

    /**
     * One grouped query for the four numbers the conference view prints.
     *
     * @return array{total: int, draft: int, submitted: int, withdrawn: int}
     */
    public function submissionCounts(): array
    {
        /** @var array<string, int> $byStatus */
        $byStatus = $this->submissions()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        return [
            'total' => array_sum($byStatus),
            'draft' => (int) ($byStatus[SubmissionStatus::Draft->value] ?? 0),
            'submitted' => (int) ($byStatus[SubmissionStatus::Submitted->value] ?? 0),
            'withdrawn' => (int) ($byStatus[SubmissionStatus::Withdrawn->value] ?? 0),
        ];
    }
```

5. Add `'reference_prefix'` to the `logOnly([...])` list in `getActivitylogOptions()`, so a late change to the prefix is attributable.

- [ ] **Step 9: Write the five factories**

`database/factories/SubmissionFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
use App\Support\Text\WordCounter;
use App\Support\Tokens\SubmissionToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Submission> */
class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $abstract = fake()->paragraphs(3, true);

        return [
            'conference_id' => Conference::factory()->published(),
            'track_id' => null,
            'title' => ucfirst(fake()->words(6, true)),
            'abstract' => $abstract,
            // Factories run inside Model::unguarded(), so the guarded columns
            // above can be set here; they still throw through fill().
            'word_count' => WordCounter::count($abstract),
            'presentation_preference' => PresentationPreference::Oral,
            'contact_phone' => '+966500000000',
            'custom_field_values' => null,
            'status' => SubmissionStatus::Draft,
            'access_token_hash' => SubmissionToken::hash(SubmissionToken::generate()),
            'submitted_at' => null,
            'withdrawn_at' => null,
            'last_edited_at' => now(),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubmissionStatus::Submitted,
            'submitted_at' => now(),
            'reference' => strtoupper(fake()->bothify('????##')).'-'.fake()->numerify('###'),
        ]);
    }

    public function withdrawn(): static
    {
        return $this->submitted()->state(fn () => [
            'status' => SubmissionStatus::Withdrawn,
            'withdrawn_at' => now(),
        ]);
    }

    /**
     * The shape every flow test needs: one corresponding, presenting author.
     * `afterCreating` rather than a state, because the author is a second row.
     */
    public function withCorrespondingAuthor(string $email = 'author@example.org', string $name = 'Dr Sara Al-Harbi'): static
    {
        return $this->afterCreating(function (Submission $submission) use ($email, $name): void {
            SubmissionAuthorFactory::new()
                ->for($submission)
                ->corresponding()
                ->create(['name' => $name, 'email' => $email, 'sort' => 1]);
        });
    }
}
```

`database/factories/SubmissionAuthorFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Submission;
use App\Models\SubmissionAuthor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubmissionAuthor> */
class SubmissionAuthorFactory extends Factory
{
    protected $model = SubmissionAuthor::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'sort' => 1,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'affiliation' => fake()->company(),
            'is_presenter' => false,
            'is_corresponding' => false,
        ];
    }

    public function corresponding(): static
    {
        return $this->state(fn () => ['is_corresponding' => true, 'is_presenter' => true]);
    }
}
```

`database/factories/SubmissionFileFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Submission;
use App\Models\SubmissionFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SubmissionFile> */
class SubmissionFileFactory extends Factory
{
    protected $model = SubmissionFile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $ulid = (string) Str::ulid();
        $sha = hash('sha256', $ulid);

        return [
            'submission_id' => Submission::factory(),
            'ulid' => $ulid,
            'original_name' => 'abstract.pdf',
            // The same layout StoreSubmissionFile writes, so a factory-made row
            // and a real upload are indistinguishable to the download route.
            'path' => substr($sha, 0, 2).'/'.$ulid.'.pdf',
            'mime' => 'application/pdf',
            'size' => 12_345,
            'sha256' => $sha,
            'sort' => 1,
        ];
    }
}
```

`database/factories/EmailTemplateFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmailTemplateKey;
use App\Models\Conference;
use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmailTemplate> */
class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conference_id' => Conference::factory(),
            'key' => EmailTemplateKey::SubmissionReceived->value,
            'subject' => 'We have your abstract, {{author_name}}',
            'body' => "Dear {{author_name}},\n\nYour abstract **{{title}}** is reference {{reference}}.\n",
        ];
    }
}
```

`database/factories/EmailLogFactory.php`
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmailLogStatus;
use App\Mail\TemplatedMail;
use App\Models\EmailLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmailLog> */
class EmailLogFactory extends Factory
{
    protected $model = EmailLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'conference_id' => null,
            'submission_id' => null,
            'template_key' => null,
            'mailable' => TemplatedMail::class,
            'to_email' => fake()->unique()->safeEmail(),
            'subject' => 'A message from CASS',
            'status' => EmailLogStatus::Queued,
            'error' => null,
            'sent_at' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => ['status' => EmailLogStatus::Sent, 'sent_at' => now()]);
    }

    public function failed(string $error = 'Connection could not be established with host "smtp.example.org"'): static
    {
        return $this->state(fn () => ['status' => EmailLogStatus::Failed, 'error' => $error]);
    }
}
```

`SubmissionFactory` references `App\Support\Text\WordCounter` and `App\Support\Tokens\SubmissionToken`, which Task 2 writes. Create both as stubs now so this task's tests can run, and let Task 2 replace the bodies test-first:

`app/Support/Text/WordCounter.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Text;

final class WordCounter
{
    public static function count(string $text): int
    {
        return 0;
    }
}
```

`app/Support/Tokens/SubmissionToken.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Tokens;

final class SubmissionToken
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
```

The token stub is already the real implementation - there is only one way to write it and Task 2 adds the tests that pin it. `WordCounter::count()` returning 0 is a deliberate placeholder: Task 2 Step 2 shows it failing before the real body lands.

- [ ] **Step 10: Write the four policies**

`app/Policies/SubmissionPolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Spec section 4: every organization member can view submissions and files;
 * nobody in a panel creates or edits an abstract, because the author owns the
 * text and reaches it with a token. `withdraw` and `resendLink` are the two
 * custom abilities the organizer does have, and both are logged.
 */
class SubmissionPolicy
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

    public function view(User $user, Submission $submission): bool
    {
        return $user->roleIn($submission->conference->organization) !== null;
    }

    /**
     * Authors submit; organizers do not type abstracts for them. Filament
     * treats a missing method as ALLOW (fact 17), so both are spelled out.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Submission $submission): bool
    {
        return false;
    }

    /** Withdrawal is the organizer's tool; deletion is not, in any panel. */
    public function delete(User $user, Submission $submission): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Submission $submission): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Submission $submission): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function withdraw(User $user, Submission $submission): bool
    {
        return $this->view($user, $submission);
    }

    public function resendLink(User $user, Submission $submission): bool
    {
        return $this->view($user, $submission);
    }

    /** Exporting is reading every row at once, so it needs the list right. */
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }
}
```

`app/Policies/SubmissionFilePolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\SubmissionFile;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Governs where a signed download URL may be *minted* inside a panel. The
 * download route itself is authorized by the signature (spec section 8), which
 * is the capability; this keeps an organizer from generating one for another
 * tenant's file in the first place.
 *
 * Plan 4 adds the reviewer case ("assigned or pool only", spec section 4).
 * Today the answer is organization members and the platform admin.
 */
class SubmissionFilePolicy
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

    public function view(User $user, SubmissionFile $file): bool
    {
        return $user->roleIn($file->submission->conference->organization) !== null;
    }

    /** Files are attached and removed by the author, through the status page. */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SubmissionFile $file): bool
    {
        return false;
    }

    public function delete(User $user, SubmissionFile $file): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
```

`app/Policies/EmailTemplatePolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Spec section 4 puts "create / edit conferences, forms, templates" in reach of
 * every organization member, so this mirrors ConferencePolicy's membership rule
 * rather than its owner/admin rule.
 */
class EmailTemplatePolicy
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

    public function view(User $user, EmailTemplate $template): bool
    {
        return $user->roleIn($template->conference->organization) !== null;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, EmailTemplate $template): bool
    {
        return $this->view($user, $template);
    }

    /** "Reset to default" is a delete of the override row. */
    public function delete(User $user, EmailTemplate $template): bool
    {
        return $this->view($user, $template);
    }

    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
```

`app/Policies/EmailLogPolicy.php`
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmailLog;
use App\Models\User;

/**
 * Platform-admin only, and read-only even for them. The log spans every tenant
 * by design (a platform-wide password reset has no organization), so there is
 * no tenant-scoped view of it and no `before()` shortcut - the one rule is
 * stated on every method.
 */
class EmailLogPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_platform_admin;
    }

    public function view(User $user, EmailLog $log): bool
    {
        return (bool) $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EmailLog $log): bool
    {
        return false;
    }

    public function delete(User $user, EmailLog $log): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
```

Laravel 13 discovers `App\Policies\{Model}Policy` by convention, so nothing is registered by hand - the same way `ConferencePolicy` is found today.

- [ ] **Step 11: Add the reference prefix to the conference form**

In `app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceForm.php`, inside the `Tab::make('Submissions')` -> `Section::make('What authors may send')` component list, before `Textarea::make('terms')`:

```php
                        TextInput::make('reference_prefix')
                            ->label('Reference prefix')
                            ->maxLength(12)
                            ->placeholder('Derived from the name, for example GPCC26')
                            // Upper-case letters and digits only: the value is
                            // printed on badges, read down a phone line and
                            // pasted into spreadsheets. \z rather than $ for
                            // the same reason the slug rule uses it - `$` also
                            // matches before a trailing newline.
                            ->rule('regex:/^[A-Z0-9]{2,12}\z/')
                            ->validationMessages([
                                'regex' => 'Use 2 to 12 upper-case letters and digits, for example GPCC26.',
                            ])
                            // Frozen once the conference is live: every
                            // reference already emailed starts with this.
                            // draft is the only status where no abstract can
                            // exist, so it is the only status where changing it
                            // is safe.
                            ->disabled(fn (?Conference $record): bool => $record !== null
                                && $record->status !== ConferenceStatus::Draft)
                            ->helperText('References look like GPCC26-017. Leave empty to derive it from the conference name. Fixed once the conference is published.'),
```

Add `use App\Enums\ConferenceStatus;` to the imports. `Conference` is already imported.

Add this case to `tests/Feature/Organizer/ConferenceResourceTest.php`:

```php
it('derives a reference prefix on create and freezes it once published', function () {
    livewire(CreateConference::class)
        ->fillForm(['name' => 'Gulf Pediatric Critical Care', 'starts_at' => '2026-11-03'])
        ->call('create')
        ->assertHasNoFormErrors();

    $conference = Conference::query()->where('name', 'Gulf Pediatric Critical Care')->firstOrFail();
    expect($conference->reference_prefix)->toBe('GPCC26');

    livewire(EditConference::class, ['record' => $conference->getRouteKey()])
        ->fillForm(['reference_prefix' => 'gpcc'])
        ->call('save')
        ->assertHasFormErrors(['reference_prefix']);

    livewire(EditConference::class, ['record' => $conference->getRouteKey()])
        ->fillForm(['reference_prefix' => 'GPCC27'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($conference->refresh()->reference_prefix)->toBe('GPCC27');

    $conference->forceFill(['status' => ConferenceStatus::Open])->save();

    livewire(EditConference::class, ['record' => $conference->getRouteKey()])
        ->assertFormFieldIsDisabled('reference_prefix');
});
```

- [ ] **Step 12: Add `cass.file_url_minutes` to `config/cass.php`**

`SubmissionFile::temporaryUrl()` reads it. Add next to the `qr` block:

```php
    // Spec section 8: downloads only through signed, expiring routes. Long
    // enough for a mail client that prefetches links, short enough that a
    // forwarded URL is dead on arrival.
    'file_url_minutes' => (int) env('CASS_FILE_URL_MINUTES', 30),
```

- [ ] **Step 13: Migrate, run the tests, Pint, Larastan, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan migrate --database=sqlite --pretend > /tmp/m.log 2>&1; echo "pretend rc=$?"; grep -c "create table\|alter table" /tmp/m.log
```

Expected: `pretend rc=0` and at least 6 statements. (`--pretend` only prints SQL; the suite builds the schema itself through `RefreshDatabase`.)

```bash
cd /c/Users/ahmed/Documents/CASS && \
./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?"; \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log
```

Expected: `stan rc=0`, `tests rc=0`, `255 passed` (232 + 22 new unit cases + 1 new panel case — `ReferencePrefixTest` is 1 case plus a 6-row dataset, `SubmissionTest` is 6 cases plus a 9-row dataset, and Pest counts one case per dataset row).

```bash
cd /c/Users/ahmed/Documents/CASS && git add -A && git commit -q -m "feat(submissions): schema, enums, models, factories and policies

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

---

### Task 2: Word counting, tokens, reference numbers and MIME sniffing

The four pure-ish pieces everything else leans on. Each is unit-tested against the cases that actually bite: a hyphenated word, a `p<0.05`, a non-breaking space, a renamed PNG, two submissions racing for the same number.

**Files:**
- Create: `app/Actions/Submissions/AllocateReference.php`, `app/Actions/Submissions/IssueSubmissionToken.php`, `app/Support/Files/SniffedMimeType.php`
- Create: `tests/Fixtures/abstract.pdf`, `tests/Fixtures/not-really.pdf`
- Modify: `app/Support/Text/WordCounter.php` (real body), `app/Models/Submission.php` (`findByPlainToken`)
- Test: `tests/Unit/WordCounterTest.php`, `tests/Unit/SubmissionTokenTest.php`, `tests/Unit/AllocateReferenceTest.php`, `tests/Unit/SniffedMimeTypeTest.php`

- [ ] **Step 1: Build the two file fixtures**

`php -` does not work on this machine, so the generator is a file. Write `/tmp/fixtures.php`:

```php
<?php

declare(strict_types=1);

$dir = 'C:\\Users\\ahmed\\Documents\\CASS\\tests\\Fixtures';

if (! is_dir($dir)) {
    mkdir($dir, 0o755, true);
}

// A minimal but genuinely valid PDF 1.4: catalog, pages, one A4 page, one
// content stream printing a line of text with a base-14 font. finfo reads the
// %PDF- magic at byte 0 and answers application/pdf. It is about 600 bytes,
// which keeps it comfortable in git and instant to upload in the browser test.
$objects = [
    "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
    "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
    "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
    "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
];

$stream = "BT /F1 18 Tf 72 760 Td (CASS test abstract) Tj ET\n";
$objects[] = "5 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n".$stream."endstream\nendobj\n";

$pdf = "%PDF-1.4\n";
$offsets = [];
foreach ($objects as $object) {
    $offsets[] = strlen($pdf);
    $pdf .= $object;
}

$xref = strlen($pdf);
$pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
foreach ($offsets as $offset) {
    $pdf .= sprintf("%010d 00000 n \n", $offset);
}
$pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";

file_put_contents($dir.DIRECTORY_SEPARATOR.'abstract.pdf', $pdf);

// A real PNG wearing a .pdf name. This is the attack StoreSubmissionFile's
// sniffing exists for, and the only fixture that can prove the check runs on
// content rather than on the extension.
$image = imagecreatetruecolor(4, 4);
imagefill($image, 0, 0, imagecolorallocate($image, 23, 107, 184));
imagepng($image, $dir.DIRECTORY_SEPARATOR.'not-really.pdf');
imagedestroy($image);

echo 'abstract.pdf: '.filesize($dir.DIRECTORY_SEPARATOR.'abstract.pdf')." bytes\n";
echo 'not-really.pdf: '.filesize($dir.DIRECTORY_SEPARATOR.'not-really.pdf')." bytes\n";
$finfo = finfo_open(FILEINFO_MIME_TYPE);
echo 'sniffed abstract.pdf: '.finfo_file($finfo, $dir.DIRECTORY_SEPARATOR.'abstract.pdf')."\n";
echo 'sniffed not-really.pdf: '.finfo_file($finfo, $dir.DIRECTORY_SEPARATOR.'not-really.pdf')."\n";
```

```bash
cd /c/Users/ahmed/Documents/CASS && php /tmp/fixtures.php
```

Expected, exactly:

```
abstract.pdf: 615 bytes
not-really.pdf: 78 bytes
sniffed abstract.pdf: application/pdf
sniffed not-really.pdf: image/png
```

(The byte counts may differ by a few if PHP's PNG encoder changes; the two sniffed lines are the assertions that matter.) Then make git treat them as binary so a checkout on Windows cannot rewrite the PDF's line endings and break the xref offsets:

```bash
cd /c/Users/ahmed/Documents/CASS && printf 'tests/Fixtures/*.pdf binary\n' >> .gitattributes && tail -3 .gitattributes
```

- [ ] **Step 2: Write the failing tests**

`tests/Unit/WordCounterTest.php`
```php
<?php

declare(strict_types=1);

use App\Support\Text\WordCounter;

it('counts the words an author would count', function (string $text, int $expected) {
    expect(WordCounter::count($text))->toBe($expected);
})->with([
    'empty' => ['', 0],
    'whitespace only' => ["  \n\t ", 0],
    'plain sentence' => ['Early mobilisation after cardiac surgery', 5],
    // A hyphenated term is one word to a human and to every abstract limit
    // anyone has ever been held to.
    'hyphenated compound' => ['A double-blind placebo-controlled trial', 4],
    // An em dash on its own is punctuation, not a word.
    'bare punctuation is not a word' => ['Results — significant', 2],
    'statistics are words' => ['The difference was significant (p<0.05).', 5],
    'numbers count' => ['We enrolled 120 children in 3 centres', 7],
    'collapses runs of whitespace' => ["Two\n\n\nlines   apart", 3],
    // A non-breaking space is what arrives when an author pastes from Word.
    // PCRE's \s does not match U+00A0, so the pattern lists it explicitly.
    'non-breaking space splits words' => ["Riyadh\u{00A0}Saudi\u{00A0}Arabia", 3],
    'narrow no-break space splits words' => ["120\u{202F}children enrolled", 3],
    'arabic counts like any other script' => ['الرعاية الحرجة للأطفال', 3],
    'emoji alone is not a word' => ['Results 🎉 improved', 2],
]);

it('is not fooled by leading or trailing whitespace', function () {
    expect(WordCounter::count("   Early mobilisation   \n"))->toBe(2);
});
```

`tests/Unit/SubmissionTokenTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Submissions\IssueSubmissionToken;
use App\Models\Submission;
use App\Support\Tokens\SubmissionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates 64 url-safe characters that match the status route constraint', function () {
    $token = SubmissionToken::generate();

    expect(strlen($token))->toBe(64)
        // The /s/{token} route is constrained to [A-Za-z0-9]{64}; a token that
        // needed escaping would 404 on its own link.
        ->and($token)->toMatch('/^[A-Za-z0-9]{64}$/')
        ->and($token)->not->toBe(SubmissionToken::generate());
});

it('stores only the sha-256 hash and never the plaintext', function () {
    $submission = Submission::factory()->create();

    $plain = app(IssueSubmissionToken::class)->handle($submission);

    $stored = (string) $submission->refresh()->access_token_hash;

    expect(strlen($stored))->toBe(64)
        ->and($stored)->toBe(hash('sha256', $plain))
        ->and($stored)->not->toBe($plain)
        // Spec section 9: the plaintext appears only in the emailed link, so
        // it must not be recoverable from any column on the row.
        ->and(json_encode($submission->refresh()->getAttributes()))->not->toContain($plain);
});

it('finds a submission from its plaintext token and only from the right one', function () {
    $mine = Submission::factory()->create();
    $theirs = Submission::factory()->create();

    $plain = app(IssueSubmissionToken::class)->handle($mine);
    app(IssueSubmissionToken::class)->handle($theirs);

    expect(Submission::findByPlainToken($plain)?->is($mine))->toBeTrue()
        ->and(Submission::findByPlainToken(str_repeat('0', 64)))->toBeNull()
        ->and(Submission::findByPlainToken(''))->toBeNull();
});

it('replaces the old hash when a link is reissued', function () {
    $submission = Submission::factory()->create();

    $first = app(IssueSubmissionToken::class)->handle($submission);
    $second = app(IssueSubmissionToken::class)->handle($submission);

    expect($first)->not->toBe($second)
        ->and(Submission::findByPlainToken($second)?->is($submission))->toBeTrue()
        // The point of "Resend status link": the old link stops working.
        ->and(Submission::findByPlainToken($first))->toBeNull();
});
```

`tests/Unit/AllocateReferenceTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Submissions\AllocateReference;
use App\Models\Conference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('hands out one number per call, zero padded to three digits', function () {
    $conference = Conference::factory()->create(['reference_prefix' => 'GPCC26']);
    $allocate = app(AllocateReference::class);

    // Two calls inside one transaction is the sequential stand-in for two
    // concurrent submits: the second must see the first one's write, which is
    // exactly what the lockForUpdate() re-read guarantees on MySQL. SQLite
    // ignores the lock clause and serialises writes anyway, so this asserts the
    // re-read, not the lock - the MySQL run in Task 14 asserts the lock.
    [$first, $second] = DB::transaction(fn (): array => [
        $allocate->handle($conference),
        $allocate->handle($conference),
    ]);

    expect($first)->toBe('GPCC26-001')
        ->and($second)->toBe('GPCC26-002')
        ->and($conference->refresh()->submission_counter)->toBe(2);
});

it('does not reuse a number after a stale in-memory copy', function () {
    // The bug this prevents: SubmitAbstract holds a Conference loaded by the
    // route binder minutes earlier. Allocating from that stale instance must
    // still read the counter from the database.
    $conference = Conference::factory()->create(['reference_prefix' => 'KSAU30']);
    $stale = Conference::query()->whereKey($conference->getKey())->firstOrFail();

    app(AllocateReference::class)->handle($conference);
    $second = app(AllocateReference::class)->handle($stale);

    expect($second)->toBe('KSAU30-002');
});

it('grows past 999 without changing shape', function () {
    $conference = Conference::factory()->create(['reference_prefix' => 'GPCC26']);
    $conference->forceFill(['submission_counter' => 999])->save();

    expect(app(AllocateReference::class)->handle($conference))->toBe('GPCC26-1000');
});

it('derives a prefix for a conference that has none', function () {
    $conference = Conference::factory()->create([
        'name' => 'Winter School of Critical Care',
        'starts_at' => '2027-01-10',
    ]);
    $conference->forceFill(['reference_prefix' => null])->save();

    expect(app(AllocateReference::class)->handle($conference->refresh()))->toBe('WSCC27-001');
});

it('keeps sequences separate per conference', function () {
    $alpha = Conference::factory()->create(['reference_prefix' => 'AAA26']);
    $beta = Conference::factory()->create(['reference_prefix' => 'BBB26']);
    $allocate = app(AllocateReference::class);

    expect($allocate->handle($alpha))->toBe('AAA26-001')
        ->and($allocate->handle($beta))->toBe('BBB26-001')
        ->and($allocate->handle($alpha))->toBe('AAA26-002');
});
```

`tests/Unit/SniffedMimeTypeTest.php`
```php
<?php

declare(strict_types=1);

use App\Support\Files\SniffedMimeType;

it('reads the real type of the pdf fixture', function () {
    expect(SniffedMimeType::forPath(base_path('tests/Fixtures/abstract.pdf')))->toBe('application/pdf');
});

it('sees through a png wearing a pdf name', function () {
    $path = base_path('tests/Fixtures/not-really.pdf');

    expect(SniffedMimeType::forPath($path))->toBe('image/png')
        ->and(SniffedMimeType::matches(SniffedMimeType::forPath($path), 'pdf'))->toBeFalse();
});

it('accepts each type the conference form offers', function (string $extension, string $mime) {
    expect(SniffedMimeType::matches($mime, $extension))->toBeTrue();
})->with([
    ['pdf', 'application/pdf'],
    ['doc', 'application/msword'],
    // Old magic databases answer application/CDFV2 for an OLE2 container.
    ['doc', 'application/CDFV2'],
    ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    // A .docx *is* a zip, and plenty of magic databases stop there.
    ['docx', 'application/zip'],
    // Case and extension case must not matter.
    ['PDF', 'APPLICATION/PDF'],
]);

it('rejects an unknown extension, an unknown mime and a null sniff', function () {
    expect(SniffedMimeType::matches('application/pdf', 'exe'))->toBeFalse()
        ->and(SniffedMimeType::matches('application/x-dosexec', 'pdf'))->toBeFalse()
        ->and(SniffedMimeType::matches(null, 'pdf'))->toBeFalse();
});

it('sniffs a stream without consuming it for the caller', function () {
    $stream = fopen(base_path('tests/Fixtures/abstract.pdf'), 'rb');

    expect(SniffedMimeType::forStream($stream))->toBe('application/pdf')
        // StoreSubmissionFile hashes and copies the same handle afterwards, so
        // the sniff has to rewind.
        ->and(ftell($stream))->toBe(0);

    fclose($stream);
});

it('lists exactly the extensions the conference form offers', function () {
    expect(SniffedMimeType::allowedExtensions())->toBe(['pdf', 'doc', 'docx']);
});
```

- [ ] **Step 3: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/WordCounterTest.php tests/Unit/SubmissionTokenTest.php tests/Unit/AllocateReferenceTest.php tests/Unit/SniffedMimeTypeTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found|Failed asserting" /tmp/t.log | head -4
```

Expected: `rc=1`, with `Class "App\Support\Files\SniffedMimeType" not found` and `Failed asserting that 0 is identical to 5` (the `WordCounter` stub from Task 1).

- [ ] **Step 4: Write the real `app/Support/Text/WordCounter.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support\Text;

/**
 * The number an author is held to by `conferences.word_limit`. It has to agree
 * with the live counter in the browser (resources/js/word-count.js uses the
 * same two rules), because the only thing worse than a limit is a limit that
 * counts differently on each side of the Submit button.
 *
 * Two rules, both chosen to match what a human counts:
 *
 * 1. Words are whitespace separated, and "whitespace" includes the characters
 *    a paste from Word brings with it. PCRE's `\s` does *not* match U+00A0 or
 *    U+202F even in UTF mode, so they are listed. A hyphenated compound is one
 *    word because no abstract limit in the world has ever meant otherwise.
 * 2. A token counts only if it contains a letter or a digit in any script, so
 *    a stray em dash or a lone emoji is punctuation, and Arabic counts exactly
 *    like English.
 */
final class WordCounter
{
    /** Everything PCRE calls space, plus the ones it does not. */
    private const SEPARATORS = '/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u';

    public static function count(string $text): int
    {
        $tokens = preg_split(self::SEPARATORS, trim($text), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false || $tokens === []) {
            return 0;
        }

        return count(array_filter(
            $tokens,
            static fn (string $token): bool => preg_match('/[\p{L}\p{N}]/u', $token) === 1,
        ));
    }
}
```

`trim()` only removes ASCII whitespace, which is why `PREG_SPLIT_NO_EMPTY` is still needed: a string starting with a non-breaking space would otherwise produce an empty leading token.

- [ ] **Step 5: Write `app/Actions/Submissions/IssueSubmissionToken.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\Submission;
use App\Support\Tokens\SubmissionToken;

/**
 * Spec section 9: author access tokens are stored as SHA-256 hashes and the
 * plaintext appears only in the emailed link.
 *
 * Calling this a second time is the whole of "Resend status link": a new
 * plaintext is minted, the stored hash is replaced, and every link already in
 * circulation stops resolving. That is deliberate - a resend is what support
 * does when an author says the link leaked or was lost, and leaving the old one
 * alive would defeat both reasons.
 */
class IssueSubmissionToken
{
    /** @return string the plaintext token; the caller must use it immediately */
    public function handle(Submission $submission): string
    {
        $plain = SubmissionToken::generate();

        $submission->forceFill(['access_token_hash' => SubmissionToken::hash($plain)])->save();

        return $plain;
    }
}
```

And pin `app/Support/Tokens/SubmissionToken.php` with the documentation the tests now assert:

```php
<?php

declare(strict_types=1);

namespace App\Support\Tokens;

use Random\RandomException;

/**
 * 32 random bytes rendered as 64 hex characters. Hex rather than base64url
 * because the value travels in a path segment constrained to
 * [A-Za-z0-9]{64} - no padding, no `-`, no `_`, nothing a mail client can
 * mangle when it decides where a link ends.
 *
 * sodium is not built into this machine's PHP or into the production image, so
 * the comparison in findByPlainToken() is an indexed lookup on the stored hash
 * rather than a constant-time compare. That is the right shape anyway: the
 * secret is 256 bits of entropy behind a database index, and a timing oracle on
 * an index probe is not a route to guessing one.
 */
final class SubmissionToken
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

Add the lookup to `app/Models/Submission.php`, after `correspondingAuthor()` (import `use App\Support\Tokens\SubmissionToken;`):

```php
    /**
     * One indexed read on the unique `access_token_hash`. An empty or
     * wrong-length token still hashes to something, so it simply does not
     * match - there is no separate "invalid format" branch and therefore no
     * shape of answer that tells an attacker which of the two happened.
     */
    public static function findByPlainToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        return static::query()->where('access_token_hash', SubmissionToken::hash($token))->first();
    }
```

- [ ] **Step 6: Write `app/Actions/Submissions/AllocateReference.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\Conference;

/**
 * The `GPCC26-017` half of a reference (the `GPCC26` half is
 * App\Support\Submissions\ReferencePrefix, decided when the conference is
 * created).
 *
 * **Call this inside a transaction.** `lockForUpdate()` outside one is a no-op
 * that releases the row lock immediately, and two authors pressing Submit in
 * the same second would then draw the same number and the second insert would
 * hit the unique (conference_id, reference) index. SubmitAbstract is the only
 * caller and it opens the transaction.
 */
class AllocateReference
{
    public function handle(Conference $conference): string
    {
        // Re-read rather than trust the instance handed in: the route binder
        // loaded it when the author opened the form, which can be an hour and
        // a hundred submissions ago.
        /** @var Conference $locked */
        $locked = Conference::query()
            ->whereKey($conference->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $next = (int) $locked->submission_counter + 1;

        $locked->forceFill(['submission_counter' => $next])->save();

        // Keep the caller's copy honest so a second allocation in the same
        // request does not read a stale counter off it.
        $conference->setAttribute('submission_counter', $next);

        return self::format($locked->referencePrefix(), $next);
    }

    /**
     * Three digits is what a printed badge and a spoken "oh one seven" want.
     * Past 999 the number simply grows: GPCC26-1000 is still sortable as text
     * within a conference because every reference in one conference has the
     * same prefix, and str_pad never truncates.
     */
    public static function format(string $prefix, int $number): string
    {
        return $prefix.'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 7: Write `app/Support/Files/SniffedMimeType.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support\Files;

use finfo;

/**
 * Spec section 8: uploads are validated by "extension and sniffed MIME". Both,
 * and they have to agree - an extension alone is a claim the uploader makes,
 * and a sniffed type alone would let `payload.exe` through as long as its
 * bytes looked like a PDF to something downstream.
 *
 * `fileinfo` is loaded on this machine and compiled into php:8.4-fpm-alpine, so
 * this is finfo and not exif (which is absent here) and not
 * UploadedFile::getMimeType() (which asks the *client's* Content-Type first on
 * some paths).
 */
final class SniffedMimeType
{
    /**
     * Extension to the MIME types a truthful file of that extension may sniff
     * as. The keys are exactly the options of the `allowed_file_types`
     * CheckboxList in ConferenceForm; a conference cannot offer anything else.
     *
     * @var array<string, list<string>>
     */
    public const EXPECTED = [
        'pdf' => ['application/pdf'],
        // An OLE2 compound document. Older magic databases stop at the
        // container and answer application/CDFV2 or application/vnd.ms-office.
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2'],
        // A .docx is a zip. Most magic databases recognise the OOXML marker
        // inside it; the ones that do not answer application/zip, and refusing
        // every author on such a host would be worse than accepting a zip whose
        // name ends in .docx and which nothing on this platform ever executes.
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
    ];

    /** @return list<string> */
    public static function allowedExtensions(): array
    {
        return array_keys(self::EXPECTED);
    }

    public static function forPath(string $path): ?string
    {
        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            return null;
        }

        try {
            return self::forStream($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Reads the head of the stream and rewinds it, because the caller
     * (StoreSubmissionFile) hashes and copies the same handle straight
     * afterwards. 4 KiB is far more than any magic number needs and small
     * enough that a 10 MB upload is not read twice.
     *
     * @param  resource  $stream
     */
    public static function forStream(mixed $stream): ?string
    {
        if (! is_resource($stream)) {
            return null;
        }

        $position = ftell($stream);
        rewind($stream);
        $head = fread($stream, 4096);
        rewind($stream);

        if ($position !== false && $position !== 0) {
            fseek($stream, $position);
        }

        if ($head === false || $head === '') {
            return null;
        }

        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($head);

        return $type === false ? null : $type;
    }

    public static function matches(?string $mime, string $extension): bool
    {
        if ($mime === null) {
            return false;
        }

        $expected = self::EXPECTED[strtolower($extension)] ?? null;

        if ($expected === null) {
            return false;
        }

        return in_array(strtolower($mime), array_map('strtolower', $expected), true);
    }
}
```

- [ ] **Step 8: Run the tests, Pint, Larastan, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && \
php artisan test tests/Unit > /tmp/t.log 2>&1; echo "unit rc=$?"; tail -3 /tmp/t.log && \
./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?"; \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log
```

Expected: `unit rc=0`, `stan rc=0`, `tests rc=0`, `285 passed`.

If Larastan complains about `forStream(mixed $stream)`, keep the `mixed` parameter and the `is_resource()` guard: PHP has no `resource` type declaration, and a `@param resource` alone would make level 6 assume the guard is redundant on a caller that passes `false`.

```bash
cd /c/Users/ahmed/Documents/CASS && git add -A && git commit -q -m "feat(submissions): word counting, access tokens, reference numbers and MIME sniffing

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

---

### Task 3: Templated email, platform defaults and the `email_logs` pipeline

Everything about outgoing mail, built before anything that sends. Spec 5.9 in one task: eleven template keys with platform defaults, a per-conference override, placeholder rendering, a branded queued mailable, and a log row for **every** message the application sends — including Plan 1's notifications, which predate this pipeline.

**Where the platform defaults live, and why.** They are PHP arrays in `lang/en/mail.php`, read through one accessor (`App\Support\Mail\DefaultTemplates`). The alternative was a `match` inside `App\Support\Mail\DefaultTemplates` with the strings inline. The language file wins for one reason that is a hard requirement rather than a preference: spec section 10 says every string lives in a language file so Arabic can be added without code changes, and a default email template is the most user-facing string in the product. Putting them anywhere else would mean translating them twice — once in code for the default and once in the database for every conference that never overrode it. `DefaultTemplates` stays as the accessor so `RenderEmailTemplate` never has to know that `__()` is involved, and so a missing key is a typed failure instead of a Blade string reading `mail.templates.typo.subject`.

**Files:**
- Create: `lang/en/mail.php`
- Create: `app/Support/Mail/RenderedTemplate.php`, `app/Support/Mail/DefaultTemplates.php`
- Create: `app/Actions/Mail/RenderEmailTemplate.php`, `app/Actions/Mail/SendTemplatedEmail.php`, `app/Actions/Mail/SaveEmailTemplate.php`, `app/Actions/Mail/ResetEmailTemplate.php`
- Create: `app/Mail/TemplatedMail.php`, `resources/views/mail/templated.blade.php`
- Create: `app/Listeners/RecordOutgoingEmail.php`
- Create: `app/Notifications/NewSubmissionNotice.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/RenderEmailTemplateTest.php`, `tests/Feature/Mail/TemplatedMailTest.php`, `tests/Feature/Mail/EmailLogPipelineTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/RenderEmailTemplateTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Mail\RenderEmailTemplate;
use App\Enums\EmailTemplateKey;
use App\Models\Conference;
use App\Models\EmailTemplate;
use App\Support\Mail\DefaultTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has a platform default for every key in spec 5.9', function () {
    foreach (EmailTemplateKey::cases() as $key) {
        $default = DefaultTemplates::for($key);

        expect($default->subject)->not->toBe('')
            ->and($default->body)->not->toBe('')
            // A default must not use a placeholder the key does not declare,
            // because the editor's legend and the preview are built from
            // placeholders() and an undeclared one would render literally in a
            // real email.
            ->and(placeholdersIn($default->subject.' '.$default->body))
            ->each->toBeIn($key->placeholders());
    }
});

it('keeps link placeholders out of every subject line', function () {
    foreach (EmailTemplateKey::cases() as $key) {
        // A rendered subject is stored in email_logs.subject, shown in the
        // admin panel and carried in a clear-text SMTP header, so a bearer
        // credential must never be substitutable into one.
        expect($key->subjectPlaceholders())->not->toContain('status_link')
            ->and($key->subjectPlaceholders())->not->toContain('review_link');

        // No platform default uses one either, so this narrows nothing that
        // ships - it constrains what an organizer may type in the editor.
        expect(placeholdersIn(DefaultTemplates::for($key)->subject))
            ->each->toBeIn($key->subjectPlaceholders());
    }
});

it('substitutes declared placeholders in the subject and the body', function () {
    $rendered = app(RenderEmailTemplate::class)->handle(
        EmailTemplateKey::SubmissionReceived,
        null,
        [
            'author_name' => 'Dr Sara Al-Harbi',
            'title' => 'Early mobilisation after cardiac surgery',
            'reference' => 'GPCC26-017',
            'conference' => 'Gulf Pediatric Critical Care 2026',
            'organization' => 'Gulf Pediatric Society',
            'deadline' => '3 November 2026, 23:59 (Asia/Riyadh)',
            'status_link' => 'https://cass.towardpcc.com/s/'.str_repeat('a', 64),
        ],
    );

    expect($rendered->subject)->toContain('GPCC26-017')
        ->and($rendered->body)->toContain('Dr Sara Al-Harbi')
        ->and($rendered->body)->toContain('Early mobilisation after cardiac surgery')
        ->and($rendered->body)->toContain('https://cass.towardpcc.com/s/')
        ->and($rendered->body)->not->toContain('{{');
});

it('leaves an unknown placeholder literal instead of blanking it', function () {
    $conference = Conference::factory()->create();
    EmailTemplate::factory()->for($conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Hello {{author_name}}',
        // A typo an organizer will make. Blanking it silently produces "Your
        // abstract  is received"; leaving it produces something they can see in
        // the preview and fix.
        'body' => 'Your abstract {{titel}} is reference {{reference}}.',
    ]);

    $rendered = app(RenderEmailTemplate::class)->handle(
        EmailTemplateKey::SubmissionReceived,
        $conference,
        ['author_name' => 'Sara', 'reference' => 'GPCC26-001'],
    );

    expect($rendered->subject)->toBe('Hello Sara')
        ->and($rendered->body)->toBe('Your abstract {{titel}} is reference GPCC26-001.');
});

it('escapes a value so it cannot become markdown or html', function () {
    $conference = Conference::factory()->create();
    EmailTemplate::factory()->for($conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => '{{title}}',
        'body' => 'Title: {{title}} — organizer **bold** and [a real link](https://example.org).',
    ]);

    $rendered = app(RenderEmailTemplate::class)->handle(
        EmailTemplateKey::SubmissionReceived,
        $conference,
        ['title' => '<script>alert(1)</script> [CLICK HERE](https://evil.example)'],
    );

    expect($rendered->body)->not->toContain('<script>')
        ->and($rendered->body)->toContain('&lt;script&gt;')
        // The bracket is escaped, so CommonMark renders literal brackets
        // instead of an anchor pointing at evil.example.
        ->and($rendered->body)->toContain('\[CLICK HERE]')
        // The organizer's own markdown is untouched.
        ->and($rendered->body)->toContain('**bold**')
        ->and($rendered->body)->toContain('[a real link](https://example.org)');
});

it('neutralises raw html the organizer typed while keeping their markdown', function () {
    $conference = Conference::factory()->create();
    EmailTemplate::factory()->for($conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Received',
        'body' => "<script>fetch('https://evil.example')</script>\n\n> A quote\n\n- one\n- two",
    ]);

    $rendered = app(RenderEmailTemplate::class)->handle(EmailTemplateKey::SubmissionReceived, $conference, []);

    expect($rendered->body)->not->toContain('<script>')
        // Only `<` is escaped, so a blockquote and a list still work: an email
        // template that cannot use markdown is not a markdown template.
        ->and($rendered->body)->toContain('> A quote')
        ->and($rendered->body)->toContain('- one');
});

it('strips newlines from a subject so a value cannot inject a header', function () {
    $rendered = app(RenderEmailTemplate::class)->handle(
        EmailTemplateKey::SubmissionDraftSaved,
        null,
        // The injection has to go through a placeholder this key's *subject*
        // actually uses. `submission_draft_saved`'s default subject is
        // 'Your draft abstract for {{conference}} is saved' and has no
        // {{title}}, so feeding the CRLF through `title` would never reach the
        // subject and the test would pass with or without the strip.
        ['conference' => "GPCC\r\nBcc: attacker@evil.example", 'author_name' => 'Sara'],
    );

    expect($rendered->subject)->not->toContain("\n")
        ->and($rendered->subject)->not->toContain("\r")
        // And the value really did land in the subject, so it is
        // renderSubject()'s preg_replace that makes the two assertions above
        // pass rather than an unused placeholder.
        ->and($rendered->subject)->toContain('Bcc:');
});

it('prefers a per-conference override over the platform default', function () {
    $conference = Conference::factory()->create();
    $plain = app(RenderEmailTemplate::class)->handle(EmailTemplateKey::SubmissionReceived, $conference, ['reference' => 'X-1']);

    EmailTemplate::factory()->for($conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Custom subject {{reference}}',
        'body' => 'Custom body',
    ]);

    $overridden = app(RenderEmailTemplate::class)->handle(EmailTemplateKey::SubmissionReceived, $conference, ['reference' => 'X-1']);

    expect($plain->subject)->not->toBe('Custom subject X-1')
        ->and($overridden->subject)->toBe('Custom subject X-1')
        ->and($overridden->body)->toBe('Custom body');
});

it('does not let one conference override reach another', function () {
    $mine = Conference::factory()->create();
    $theirs = Conference::factory()->create();
    EmailTemplate::factory()->for($theirs)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Their subject',
        'body' => 'Their body',
    ]);

    $rendered = app(RenderEmailTemplate::class)->handle(EmailTemplateKey::SubmissionReceived, $mine, []);

    expect($rendered->subject)->not->toBe('Their subject')
        ->and($rendered->body)->not->toBe('Their body');
});

/** @return list<string> */
function placeholdersIn(string $text): array
{
    preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $matches);

    return array_values(array_unique($matches[1]));
}
```

`tests/Feature/Mail/TemplatedMailTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Mail\SendTemplatedEmail;
use App\Enums\EmailLogStatus;
use App\Enums\EmailTemplateKey;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\Submission;
use Illuminate\Support\Facades\Mail;

it('queues a branded mailable and writes a queued log row', function () {
    Mail::fake();

    $organization = Organization::factory()->approved()->create(['name' => 'Gulf Pediatric Society']);
    $conference = Conference::factory()->for($organization)->published()->create(['name' => 'GPCC 2026']);
    $submission = Submission::factory()->for($conference)->submitted()->create();

    $log = app(SendTemplatedEmail::class)->handle(
        EmailTemplateKey::SubmissionReceived,
        $conference,
        'author@example.org',
        ['author_name' => 'Sara', 'reference' => 'GPCC26-017', 'title' => 'A title', 'conference' => 'GPCC 2026'],
        $submission,
    );

    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('author@example.org')
        && $mail->logUlid === $log->ulid
        && $mail->templateKey === EmailTemplateKey::SubmissionReceived->value);

    expect($log->refresh())
        ->status->toBe(EmailLogStatus::Queued)
        ->organization_id->toBe($organization->id)
        ->conference_id->toBe($conference->id)
        ->submission_id->toBe($submission->id)
        ->template_key->toBe('submission_received')
        ->to_email->toBe('author@example.org')
        ->and($log->subject)->toContain('GPCC26-017');
});

it('renders the organization logo, colour and the body into the branded layout', function () {
    $organization = Organization::factory()->approved()->create([
        'name' => 'Gulf Pediatric Society',
        'primary_color' => '#0F4C8A',
        'logo_path' => 'logos/gps.png',
    ]);
    $conference = Conference::factory()->for($organization)->published()->create();

    $html = (string) (new TemplatedMail(
        logUlid: (string) \Illuminate\Support\Str::ulid(),
        subjectLine: 'We have your abstract',
        body: 'Dear **Sara**, your reference is GPCC26-017.',
        organization: $organization,
        templateKey: EmailTemplateKey::SubmissionReceived->value,
    ))->render();

    expect($html)->toContain('Gulf Pediatric Society')
        ->and($html)->toContain('#0F4C8A')
        ->and($html)->toContain('logos/gps.png')
        // The markdown is parsed, so the body arrives as real emphasis rather
        // than as literal asterisks.
        ->and($html)->toContain('<strong>Sara</strong>');
});

it('escapes a quote in the organization name instead of injecting an attribute', function () {
    $organization = Organization::factory()->approved()->create([
        'name' => 'Gulf " onerror="alert(1)',
        'logo_path' => 'logos/gps.png',
    ]);

    $html = (string) (new TemplatedMail(
        logUlid: (string) \Illuminate\Support\Str::ulid(),
        subjectLine: 'x',
        body: 'x',
        organization: $organization,
        templateKey: EmailTemplateKey::SubmissionReceived->value,
    ))->render();

    // Secured markdown encoding replaces only [ < and >, so the quote has to be
    // escaped by e() in the view or it closes alt="..." in the raw <img> block.
    expect($html)->not->toContain('onerror="alert(1)"');
});

it('marks its log row failed when the queued job fails', function () {
    Mail::fake();

    $conference = Conference::factory()->published()->create();
    $log = app(SendTemplatedEmail::class)->handle(
        EmailTemplateKey::SubmissionDraftSaved,
        $conference,
        'author@example.org',
        ['author_name' => 'Sara'],
    );

    $mail = new TemplatedMail(
        logUlid: (string) $log->ulid,
        subjectLine: 'x',
        body: 'x',
        organization: $conference->organization,
        templateKey: EmailTemplateKey::SubmissionDraftSaved->value,
    );

    // What SendQueuedMailable::failed() does (fact 8).
    $mail->failed(new RuntimeException('Connection could not be established with host "smtp.example.org"'));

    expect($log->refresh())
        ->status->toBe(EmailLogStatus::Failed)
        ->and($log->error)->toContain('smtp.example.org');
});

it('never writes a second log row for one templated send', function () {
    // MAIL_MAILER=array and QUEUE_CONNECTION=sync in phpunit.xml, so this runs
    // the real mailer: MessageSending fires, sees the X-CASS-Log header that
    // SendTemplatedEmail already minted, and must not create a duplicate.
    $conference = Conference::factory()->published()->create();

    $log = app(SendTemplatedEmail::class)->handle(
        EmailTemplateKey::SubmissionDraftSaved,
        $conference,
        'author@example.org',
        ['author_name' => 'Sara'],
    );

    expect(EmailLog::query()->count())->toBe(1)
        ->and($log->refresh()->status)->toBe(EmailLogStatus::Sent)
        ->and($log->sent_at)->not->toBeNull();
});

it('never stores an author token in a log subject, whatever the template said', function () {
    Mail::fake();

    $conference = Conference::factory()->published()->create();

    // A row written straight into the table, because the editor's own rule
    // (EmailTemplateKey::subjectPlaceholders()) is what stops an organizer
    // typing this - and email_logs is listed in the admin panel and never
    // pruned, so the write path needs its own guard too.
    $template = new EmailTemplate;
    $template->fill([
        'subject' => 'Your abstract {{status_link}}',
        'body' => 'Open {{status_link}} to edit it.',
    ]);
    $template->conference()->associate($conference);
    $template->key = EmailTemplateKey::SubmissionDraftSaved->value;
    $template->save();

    $log = app(SendTemplatedEmail::class)->handle(
        EmailTemplateKey::SubmissionDraftSaved,
        $conference,
        'author@example.org',
        ['author_name' => 'Sara', 'status_link' => 'https://cass.towardpcc.com/s/'.str_repeat('a', 64)],
    );

    expect($log->subject)->not->toMatch('#/s/[A-Za-z0-9]{64}#')
        ->and($log->subject)->toContain('/s/[redacted]');
});
```

`tests/Feature/Mail/EmailLogPipelineTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\EmailLogStatus;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\NewSubmissionNotice;
use App\Notifications\OrganizationApproved;
use Illuminate\Support\Facades\Notification;

// No Mail::fake() and no Notification::fake() anywhere in this file: the whole
// point is the mail *events*, and both fakes short-circuit the mailer before
// MessageSending is ever dispatched. phpunit.xml pins MAIL_MAILER=array and
// QUEUE_CONNECTION=sync, so a queued notification is delivered into the array
// transport inside the same request and the events fire for real.

it('logs a plan 1 notification that knows nothing about templates', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create(['email' => 'owner@example.org']);
    $organization->addMember($owner, App\Enums\OrganizationRole::Owner);

    $owner->notify(new OrganizationApproved($organization));

    $log = EmailLog::query()->firstOrFail();

    expect($log->to_email)->toBe('owner@example.org')
        ->and($log->mailable)->toBe(OrganizationApproved::class)
        ->and($log->template_key)->toBeNull()
        ->and($log->status)->toBe(EmailLogStatus::Sent)
        ->and($log->sent_at)->not->toBeNull()
        // No conference exists at approval time; the columns are nullable for
        // exactly this message.
        ->and($log->conference_id)->toBeNull();
});

it('carries organization, conference and submission context on the member notice', function () {
    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->published()->create();
    $submission = Submission::factory()->for($conference)->submitted()->create();
    $member = User::factory()->create(['email' => 'member@example.org']);
    $organization->addMember($member, App\Enums\OrganizationRole::Member);

    $member->notify(new NewSubmissionNotice($submission));

    $log = EmailLog::query()->where('to_email', 'member@example.org')->firstOrFail();

    expect($log->mailable)->toBe(NewSubmissionNotice::class)
        ->and($log->organization_id)->toBe($organization->id)
        ->and($log->conference_id)->toBe($conference->id)
        ->and($log->submission_id)->toBe($submission->id)
        ->and($log->status)->toBe(EmailLogStatus::Sent);
});

it('names the abstract and links the panel without leaking the author token', function () {
    $conference = Conference::factory()->published()->create(['name' => 'GPCC 2026']);
    $submission = Submission::factory()->for($conference)->submitted()->withCorrespondingAuthor()->create([
        'title' => 'Early mobilisation after cardiac surgery',
    ]);
    $submission->forceFill(['reference' => 'GPCC26-017'])->save();
    $member = User::factory()->create();

    $mail = (new NewSubmissionNotice($submission))->toMail($member);
    $html = (string) $mail->render();

    expect($html)->toContain('GPCC26-017')
        ->toContain('Early mobilisation after cardiac surgery')
        ->toContain('GPCC 2026')
        // The organizer link is the panel, never /s/{token}: a member notice is
        // forwarded around an organizing committee and must not hand anyone the
        // author's editing credential.
        ->and($html)->not->toContain('/s/')
        ->and($html)->toContain('/org/');
});

it('reaches only the members who opted in', function () {
    Notification::fake();

    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->published()->create();
    $submission = Submission::factory()->for($conference)->submitted()->create();

    $wants = User::factory()->create();
    $doesNot = User::factory()->create();
    $organization->addMember($wants, App\Enums\OrganizationRole::Owner);
    $organization->addMember($doesNot, App\Enums\OrganizationRole::Member);
    $organization->members()->updateExistingPivot($doesNot->id, ['notify_on_submission' => false]);

    // The pivot query written out, not SubmitAbstract::notifiableMembers() -
    // that method is Task 5's, and a task never commits a red suite.
    // Organization::members() already carries
    // withPivot(['role', 'notify_on_submission']), so this is the same query
    // the action runs, and Task 5 adds `selects exactly the members who opted
    // in` to pin the shared definition against this one.
    $members = $organization->members()->wherePivot('notify_on_submission', true)->get();

    Notification::send($members, new NewSubmissionNotice($submission));

    Notification::assertSentTo($wants, NewSubmissionNotice::class);
    Notification::assertNotSentTo($doesNot, NewSubmissionNotice::class);
});
```

This file tests the pipeline — the listener, the log rows and the notice — and nothing in it reaches forward into a class a later task writes.

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/RenderEmailTemplateTest.php tests/Feature/Mail > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found" /tmp/t.log | head -3
```

Expected: `rc=1`, `Class "App\Support\Mail\DefaultTemplates" not found`.

- [ ] **Step 3: Write `lang/en/mail.php`**

```php
<?php

declare(strict_types=1);

/*
 * Platform default email templates, one entry per App\Enums\EmailTemplateKey.
 *
 * `body` is Markdown. `{{placeholder}}` values are substituted by
 * App\Actions\Mail\RenderEmailTemplate, which escapes each value before
 * substitution - so a placeholder may appear anywhere, including inside a
 * markdown link's text, without letting the value become markup.
 *
 * A key may use only the placeholders EmailTemplateKey::placeholders() declares
 * for it; tests/Unit/RenderEmailTemplateTest.php enforces that. Adding Arabic
 * is copying this file to lang/ar/mail.php - no code changes (spec section 10).
 */

return [
    'templates' => [

        'submission_received' => [
            'subject' => 'Abstract {{reference}} received — {{conference}}',
            'body' => <<<'MARKDOWN'
            Dear {{author_name}},

            Thank you. We have received your abstract for **{{conference}}**.

            - **Reference:** {{reference}}
            - **Title:** {{title}}

            Keep the reference: it is how we will refer to your abstract in every message from now on.

            You can review, edit or withdraw your abstract until the submission deadline ({{deadline}}) here:

            {{status_link}}

            This link is personal. Anyone who has it can edit your abstract, so please do not forward it.

            {{organization}}
            MARKDOWN,
        ],

        'submission_draft_saved' => [
            'subject' => 'Your draft abstract for {{conference}} is saved',
            'body' => <<<'MARKDOWN'
            Dear {{author_name}},

            Your draft abstract **{{title}}** is saved for **{{conference}}**. It has **not** been submitted yet.

            Continue and submit it here:

            {{status_link}}

            The submission deadline is {{deadline}}. A draft that is never submitted is not considered.

            This link is personal. Anyone who has it can edit your abstract, so please do not forward it.

            {{organization}}
            MARKDOWN,
        ],

        'reviewer_invitation' => [
            'subject' => 'Invitation to review abstracts for {{conference}}',
            'body' => <<<'MARKDOWN'
            Dear {{reviewer_name}},

            {{organization}} invites you to review abstracts submitted to **{{conference}}**.

            Accept the invitation and see your queue here:

            {{review_link}}

            Reviews are due by {{deadline}}.

            Thank you for giving your time to this.

            {{organization}}
            MARKDOWN,
        ],

        'reviewer_reminder' => [
            'subject' => 'Reminder: your reviews for {{conference}} are due {{deadline}}',
            'body' => <<<'MARKDOWN'
            Dear {{reviewer_name}},

            A reminder that your reviews for **{{conference}}** are due by {{deadline}}.

            Your queue is here:

            {{review_link}}

            If you can no longer review, please tell us so we can reassign the abstracts.

            {{organization}}
            MARKDOWN,
        ],

        'reviewer_overdue' => [
            'subject' => 'Your reviews for {{conference}} are overdue',
            'body' => <<<'MARKDOWN'
            Dear {{reviewer_name}},

            The review deadline for **{{conference}}** ({{deadline}}) has passed and some of your reviews are still outstanding.

            Please complete them as soon as you can:

            {{review_link}}

            If you can no longer review, please tell us so we can reassign the abstracts.

            {{organization}}
            MARKDOWN,
        ],

        'decision_accepted_oral' => [
            'subject' => '{{reference}} accepted for oral presentation — {{conference}}',
            'body' => <<<'MARKDOWN'
            Dear {{author_name}},

            We are pleased to tell you that your abstract has been **{{decision}}** for **{{conference}}**.

            - **Reference:** {{reference}}
            - **Title:** {{title}}

            Details of your session will follow. You can see your abstract here:

            {{status_link}}

            Congratulations, and we look forward to your presentation.

            {{organization}}
            MARKDOWN,
        ],

        'decision_accepted_poster' => [
            'subject' => '{{reference}} accepted as a poster — {{conference}}',
            'body' => <<<'MARKDOWN'
            Dear {{author_name}},

            We are pleased to tell you that your abstract has been **{{decision}}** for **{{conference}}**.

            - **Reference:** {{reference}}
            - **Title:** {{title}}

            Poster dimensions and the display schedule will follow. You can see your abstract here:

            {{status_link}}

            Congratulations, and we look forward to seeing your poster.

            {{organization}}
            MARKDOWN,
        ],

        'decision_waitlisted' => [
            'subject' => '{{reference}} is on the waiting list — {{conference}}',
            'body' => <<<'MARKDOWN'
            Dear {{author_name}},

            Your abstract **{{title}}** ({{reference}}) has been **{{decision}}** for **{{conference}}**.

            The programme is full, but places do become available. We will contact you as soon as we know more.

            You can see your abstract here:

            {{status_link}}

            Thank you for submitting to {{conference}}.

            {{organization}}
            MARKDOWN,
        ],

        'decision_rejected' => [
            'subject' => 'Decision on abstract {{reference}} — {{conference}}',
            'body' => <<<'MARKDOWN'
            Dear {{author_name}},

            Thank you for submitting **{{title}}** ({{reference}}) to **{{conference}}**.

            The review panel received many more abstracts than the programme can hold, and yours was **{{decision}}** on this occasion.

            We know this is disappointing. We hope you will submit again next year.

            {{status_link}}

            {{organization}}
            MARKDOWN,
        ],

        'organization_approved' => [
            'subject' => '{{organization}} is approved on CASS',
            'body' => <<<'MARKDOWN'
            **{{organization}}** has been approved. You can now create and publish conferences.

            {{status_link}}
            MARKDOWN,
        ],

        'organization_rejected' => [
            'subject' => 'About your CASS registration for {{organization}}',
            'body' => <<<'MARKDOWN'
            We were not able to approve **{{organization}}** at this time.

            If you think this is a mistake, reply to this email and we will look again.

            {{status_link}}
            MARKDOWN,
        ],

    ],
];
```

The `organization_*` bodies are short on purpose: nothing sends them yet. Plan 1's `OrganizationApproved` / `OrganizationRejected` notifications carry their own copy and keep doing so until Plan 6 moves them onto this pipeline; these two entries exist so the editor's list of keys matches spec 5.9 and so the move is a deletion rather than a piece of writing.

- [ ] **Step 4: Write `app/Support/Mail/RenderedTemplate.php` and `DefaultTemplates.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * A subject line and a Markdown body, either as an organizer typed them or as
 * RenderEmailTemplate finished them. Readonly so nothing downstream can edit a
 * rendered message in place and leave the `email_logs` subject disagreeing with
 * what was actually sent.
 */
final readonly class RenderedTemplate
{
    public function __construct(
        public string $subject,
        public string $body,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\EmailTemplateKey;

/**
 * The platform defaults, read from lang/en/mail.php (spec section 10: every
 * string in a language file, so Arabic is a new file and not a new branch).
 *
 * One accessor so RenderEmailTemplate never touches __() and so a key with no
 * entry fails here, loudly, instead of producing an email whose subject reads
 * "mail.templates.whatever.subject".
 */
final class DefaultTemplates
{
    public static function for(EmailTemplateKey $key): RenderedTemplate
    {
        $subject = __('mail.templates.'.$key->value.'.subject');
        $body = __('mail.templates.'.$key->value.'.body');

        if (! is_string($subject) || ! is_string($body) || str_starts_with($subject, 'mail.templates.')) {
            throw new \RuntimeException("No platform default email template for [{$key->value}]. Add it to lang/en/mail.php.");
        }

        return new RenderedTemplate(subject: $subject, body: $body);
    }

    /**
     * Every default, for the editor's list and for the test that checks each
     * one only uses placeholders its key declares.
     *
     * @return array<string, RenderedTemplate>
     */
    public static function all(): array
    {
        $templates = [];

        foreach (EmailTemplateKey::cases() as $key) {
            $templates[$key->value] = self::for($key);
        }

        return $templates;
    }
}
```

- [ ] **Step 5: Write `app/Actions/Mail/RenderEmailTemplate.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Enums\EmailTemplateKey;
use App\Models\Conference;
use App\Support\Mail\DefaultTemplates;
use App\Support\Mail\RenderedTemplate;

/**
 * Turns a template key plus a bag of values into the finished subject and
 * Markdown body.
 *
 * Three rules, each of which exists because of a specific way this goes wrong:
 *
 * 1. **Values are escaped before substitution, not after.** Escaping the
 *    finished string would also neuter the organizer's own markdown, and
 *    escaping nothing would let an author's own title - which they type, and
 *    which nobody reviews - become a link in an email the organizers trust. The
 *    replacement set is the one Laravel's own EncodedHtmlString uses when
 *    Markdown::withSecuredEncoding() is on (`[`, `<`, `>`), which is exactly
 *    what tests/Feature/NotificationMarkdownTest.php already pins for Plan 1's
 *    notifications.
 * 2. **`<` is escaped across the whole finished body.** Illuminate\Mail\Markdown
 *    parses with CommonMark's default `html_input`, which is *allow*, so raw
 *    HTML an organizer typed into the editor would reach the recipient. Only
 *    `<` is escaped, never `>`, so a markdown blockquote still renders.
 * 3. **An unknown placeholder is left literal.** Blanking it turns a typo into a
 *    sentence with a hole in it that nobody notices until an author asks what
 *    "your abstract  is received" means. Left literal, the organizer sees
 *    `{{titel}}` in the live preview.
 */
class RenderEmailTemplate
{
    /** Matches Laravel's EncodedHtmlString replacements. */
    private const VALUE_ESCAPES = ['[' => '\[', '<' => '&lt;', '>' => '&gt;'];

    /**
     * @param  array<string, string|null>  $values
     */
    public function handle(EmailTemplateKey $key, ?Conference $conference, array $values): RenderedTemplate
    {
        $template = $this->template($key, $conference);

        return new RenderedTemplate(
            subject: $this->renderSubject($template->subject, $values),
            body: $this->renderBody($template->body, $values),
        );
    }

    /**
     * The stored override if the organizer made one, otherwise the platform
     * default. Only conference-scoped keys can be overridden (see
     * EmailTemplateKey::isConferenceScoped()).
     */
    public function template(EmailTemplateKey $key, ?Conference $conference): RenderedTemplate
    {
        if ($conference !== null && $key->isConferenceScoped()) {
            $override = $conference->emailTemplates()->where('key', $key->value)->first();

            if ($override !== null) {
                return new RenderedTemplate(subject: (string) $override->subject, body: (string) $override->body);
            }
        }

        return DefaultTemplates::for($key);
    }

    /**
     * A subject is a message header, not markup: no escaping (which would put
     * `&amp;` in front of a reader) but every CR and LF removed, because a
     * value reaching a header unfiltered is header injection.
     *
     * @param  array<string, string|null>  $values
     */
    private function renderSubject(string $subject, array $values): string
    {
        $rendered = $this->substitute($subject, $values, escape: false);

        return trim((string) preg_replace('/[\r\n]+/', ' ', $rendered));
    }

    /** @param  array<string, string|null>  $values */
    private function renderBody(string $body, array $values): string
    {
        return str_replace('<', '&lt;', $this->substitute($body, $values, escape: true));
    }

    /** @param  array<string, string|null>  $values */
    private function substitute(string $text, array $values, bool $escape): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            function (array $match) use ($values, $escape): string {
                $name = $match[1];

                // Not array_key_exists: a null value is as much "we do not have
                // this" as a missing key, and both should show the organizer
                // that the placeholder did not resolve.
                if (! isset($values[$name])) {
                    return $match[0];
                }

                $value = (string) $values[$name];

                return $escape ? strtr($value, self::VALUE_ESCAPES) : $value;
            },
            $text,
        );
    }
}
```

- [ ] **Step 6: Write `app/Mail/TemplatedMail.php` and its view**

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\EmailLogStatus;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Support\Branding\OrganizationTheme;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every conference-scoped email. The subject and the Markdown body arrive
 * already rendered from RenderEmailTemplate, so this class decides nothing
 * about wording - only about branding, headers and what happens when the send
 * fails.
 *
 * The property is `$subjectLine`, not `$subject`: Mailable already owns
 * `$subject`, and shadowing it makes envelope() fight the parent.
 */
class TemplatedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $logUlid,
        public string $subjectLine,
        public string $body,
        public Organization $organization,
        public string $templateKey,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    /**
     * X-CASS-Log is the correlation id RecordOutgoingEmail reads back when
     * MessageSent fires (fact 7). It stays on the delivered message on purpose:
     * the runbook's triage section uses it to match an SMTP log line to a row
     * in email_logs, and a ULID reveals nothing about volume.
     */
    public function headers(): Headers
    {
        return new Headers(text: [
            'X-CASS-Log' => $this->logUlid,
            'X-CASS-Template' => $this->templateKey,
            'X-CASS-Organization' => (string) $this->organization->getKey(),
        ]);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.templated', with: [
            'theme' => OrganizationTheme::for($this->organization),
        ]);
    }

    /**
     * Called by Illuminate\Mail\SendQueuedMailable::failed() (fact 8). An
     * update() rather than a find-and-save: the row may have been written by a
     * different process, and there is nothing on the model worth loading.
     */
    public function failed(Throwable $exception): void
    {
        EmailLog::query()->where('ulid', $this->logUlid)->update([
            'status' => EmailLogStatus::Failed->value,
            'error' => Str::limit($exception->getMessage(), 2000, ''),
            'updated_at' => now(),
        ]);
    }
}
```

`resources/views/mail/templated.blade.php`
```blade
{{--
    The body is already-escaped markdown from RenderEmailTemplate, so it is
    echoed raw: `{{ }}` here would run Laravel's EncodedHtmlString over it (the
    app calls Markdown::withSecuredEncoding()) and the organizer's own markdown
    would arrive as literal asterisks and brackets. Every value inside it was
    escaped at substitution time, and every `<` in the whole string was escaped
    after it, which is what makes echoing it raw safe.

    The header block below is raw HTML on purpose: Illuminate\Mail\Markdown
    parses with CommonMark's default html_input (allow), so a block written
    here - not by an organizer - passes through and is then inlined by
    CssToInlineStyles. It must be one block with a blank line after it or
    CommonMark will treat the following paragraph as part of the HTML.
--}}
<x-mail::message>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-bottom: 3px solid {{ $theme->primary }}; margin-bottom: 24px;">
<tr>
<td style="padding-bottom: 12px;">
@if ($organization->logo_path)
{{-- e() inside a raw echo, not {{ }}: in a markdown mail view Laravel's secured
     encoding (Markdown::withSecuredEncoding, on in AppServiceProvider) replaces
     only [ < and >, so a double quote in an organization name would otherwise
     close alt="..." and inject attributes into this tag - which CommonMark
     passes through untouched, because the block is deliberately raw HTML.
     {!! !!} is compiled by compileRawEchos, which usingEchoFormat does not
     wrap, so e() is the only encoder that runs here. --}}
<img src="{!! e(Storage::disk('branding')->url($organization->logo_path)) !!}" alt="{!! e($organization->name) !!}" height="40" style="height:40px;width:auto;vertical-align:middle;margin-right:10px">
@endif
<span style="font-size:16px;font-weight:600;color:{{ $theme->primary }};vertical-align:middle;">{{ $organization->name }}</span>
</td>
</tr>
</table>

{!! $body !!}
</x-mail::message>
```

Add `use Illuminate\Support\Facades\Storage;` is not needed in a Blade view — `Storage` resolves through the framework's Blade aliases, the same way `resources/views/components/layouts/conference.blade.php` already uses it.

- [ ] **Step 7: Write `app/Actions/Mail/SendTemplatedEmail.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Enums\EmailLogStatus;
use App\Enums\EmailTemplateKey;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Submission;
use Illuminate\Support\Facades\Mail;

/**
 * The one way this application sends a conference-scoped email.
 *
 * The `email_logs` row is written **here**, before the mailable is queued,
 * rather than in the MessageSending listener, for two reasons: this is the only
 * place that knows the organization, the conference, the submission and the
 * template key, and TemplatedMail::failed() needs a row id to mark failed when
 * the transport throws - at which point MessageSending has fired but
 * MessageSent never will.
 */
class SendTemplatedEmail
{
    public function __construct(private readonly RenderEmailTemplate $render) {}

    /**
     * @param  array<string, string|null>  $values
     */
    public function handle(
        EmailTemplateKey $key,
        Conference $conference,
        string $toEmail,
        array $values,
        ?Submission $submission = null,
    ): EmailLog {
        $rendered = $this->render->handle($key, $conference, $values);

        $log = new EmailLog;
        $log->forceFill([
            'organization_id' => $conference->organization_id,
            'conference_id' => $conference->getKey(),
            'submission_id' => $submission?->getKey(),
            'template_key' => $key->value,
            'mailable' => TemplatedMail::class,
            'to_email' => $toEmail,
            // Never store an author's bearer token, whatever a template said.
            // EmailTemplateKey::subjectPlaceholders() keeps `status_link` out of
            // the editor's subject field; this is the belt to that braces, for
            // any path that does not go through the editor at all. The row is
            // listed in the admin panel and is never pruned.
            'subject' => (string) preg_replace('#/s/[A-Za-z0-9]{64}#', '/s/[redacted]', $rendered->subject),
            'status' => EmailLogStatus::Queued,
        ]);
        $log->save();

        Mail::to($toEmail)->queue(new TemplatedMail(
            logUlid: (string) $log->ulid,
            subjectLine: $rendered->subject,
            body: $rendered->body,
            organization: $conference->organization,
            templateKey: $key->value,
        ));

        return $log;
    }
}
```

- [ ] **Step 8: Write the override actions**

`app/Actions/Mail/SaveEmailTemplate.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Enums\EmailTemplateKey;
use App\Models\Conference;
use App\Models\EmailTemplate;
use InvalidArgumentException;

class SaveEmailTemplate
{
    public function handle(Conference $conference, EmailTemplateKey $key, string $subject, string $body): EmailTemplate
    {
        if (! $key->isConferenceScoped()) {
            // The page hides the action for these two, so reaching here means a
            // hand-made Livewire call. Fail rather than store a row nothing
            // will ever read.
            throw new InvalidArgumentException("[{$key->value}] is a platform-wide template and cannot be overridden per conference.");
        }

        // Not firstOrNew(['key' => ...]): HasOneOrMany::firstOrNew() builds the
        // new row with newInstance($attributes), which runs fill() - and `key`
        // is not fillable (it identifies the row), so with
        // Model::preventSilentlyDiscardingAttributes() on outside production
        // that is a MassAssignmentException on the very first override rather
        // than a saved row.
        $template = $conference->emailTemplates()->where('key', $key->value)->first() ?? new EmailTemplate;

        $template->fill(['subject' => $subject, 'body' => $body]);
        $template->conference()->associate($conference);
        $template->key = $key->value;
        $template->save();

        return $template;
    }
}
```

`app/Actions/Mail/ResetEmailTemplate.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Enums\EmailTemplateKey;
use App\Models\Conference;

/**
 * "Reset to default" is a delete: with no override row, RenderEmailTemplate
 * falls back to DefaultTemplates, which is the platform default in the
 * recipient's language. Storing a copy of the default instead would freeze
 * today's English wording into the database for ever.
 */
class ResetEmailTemplate
{
    public function handle(Conference $conference, EmailTemplateKey $key): void
    {
        $conference->emailTemplates()->where('key', $key->value)->delete();
    }
}
```

- [ ] **Step 9: Write `app/Notifications/NewSubmissionNotice.php`**

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * Spec 5.3 step 4: "notification email to organization members who opted in".
 *
 * Deliberately *not* a templated email. It is internal, it is not addressed to
 * an author, and there is no reason an organizer should be able to edit the
 * wording of the message that tells their own committee that work has arrived.
 * It still lands in `email_logs` - RecordOutgoingEmail picks it up and reads
 * the context headers added below.
 */
class NewSubmissionNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Submission $submission) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @param  User  $notifiable */
    public function toMail(object $notifiable): MailMessage
    {
        $conference = $this->submission->conference;
        $author = $this->submission->correspondingAuthor();

        return (new MailMessage)
            ->subject("New abstract for {$conference->name}: {$this->submission->reference}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A new abstract has been submitted to **{$conference->name}**.")
            ->line("**Reference:** {$this->submission->reference}")
            ->line("**Title:** {$this->submission->title}")
            ->line('**Corresponding author:** '.($author?->name ?? 'not recorded').' ('.($author?->email ?? 'not recorded').')')
            // The panel, never /s/{token}: a committee forwards this mail, and
            // the author's status link is an editing credential.
            //
            // This points at the *conference* page because SubmissionResource
            // does not exist until Task 9. Task 9 Step 8 replaces exactly this
            // one call with SubmissionResource::getUrl('view', ['record' =>
            // $this->submission], panel: 'organizer', tenant: $conference->organization)
            // and changes nothing else in this file - the same discipline Plan 2
            // used for the public CTA's single href.
            ->action('Open the conference in the organizer panel', ConferenceResource::getUrl(
                'view',
                ['record' => $conference],
                panel: 'organizer',
                tenant: $conference->organization,
            ))
            ->line('You are receiving this because "Email me about new submissions" is on for your membership.')
            ->withSymfonyMessage(function (Email $message) use ($conference): void {
                // Read by RecordOutgoingEmail so this row is filterable in the
                // admin panel by organization and conference.
                $headers = $message->getHeaders();
                $headers->addTextHeader('X-CASS-Organization', (string) $conference->organization_id);
                $headers->addTextHeader('X-CASS-Conference', (string) $conference->getKey());
                $headers->addTextHeader('X-CASS-Submission', (string) $this->submission->getKey());
            });
    }
}
```

The explicit `tenant:` argument is not optional: the queue worker has no current tenant, and the organizer panel's routes are `/org/{tenant:slug}/...`, so `getUrl()` without it throws while building the mail inside the worker — a failure that never shows up in a `Notification::fake()` test.

- [ ] **Step 10: Write `app/Listeners/RecordOutgoingEmail.php`**

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\EmailLogStatus;
use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Email;

/**
 * Spec 5.9: "All mail is queued, logged to `email_logs` with status and error".
 *
 * Two halves of one send. MessageSending writes the row for anything that does
 * not already have one (Plan 1's notifications, Filament's password reset and
 * verification mail) and stamps the correlation header; MessageSent flips the
 * row to `sent`. The same Symfony Email instance reaches both events (fact 7),
 * which is what makes a header the correlation key rather than a static map
 * that would leak across a long-running queue worker.
 *
 * Failures: a templated send is marked failed by TemplatedMail::failed(). A
 * *notification* has no such hook, so a transport failure leaves its row at
 * `queued`. That is a known, documented gap (runbook, "Email triage"), not an
 * oversight: a row stuck at `queued` with no `sent_at` is exactly the signal
 * the admin panel's status filter exists to surface.
 */
class RecordOutgoingEmail
{
    public const LOG_HEADER = 'X-CASS-Log';

    private const CONTEXT_HEADERS = [
        'organization_id' => 'X-CASS-Organization',
        'conference_id' => 'X-CASS-Conference',
        'submission_id' => 'X-CASS-Submission',
        'template_key' => 'X-CASS-Template',
    ];

    public function sending(MessageSending $event): void
    {
        $headers = $event->message->getHeaders();

        // SendTemplatedEmail already wrote the row and knows far more about it
        // than this listener could reconstruct.
        if ($headers->has(self::LOG_HEADER)) {
            return;
        }

        $log = new EmailLog;
        $log->forceFill([
            ...$this->context($event->message),
            'mailable' => $this->source($event->data),
            'to_email' => $this->firstRecipient($event->message),
            'subject' => (string) $event->message->getSubject(),
            'status' => EmailLogStatus::Queued,
        ]);
        $log->save();

        $headers->addTextHeader(self::LOG_HEADER, (string) $log->ulid);
    }

    public function sent(MessageSent $event): void
    {
        $header = $event->message->getHeaders()->get(self::LOG_HEADER);

        if ($header === null) {
            return;
        }

        EmailLog::query()
            ->where('ulid', $header->getBodyAsString())
            ->where('status', EmailLogStatus::Queued->value)
            ->update([
                'status' => EmailLogStatus::Sent->value,
                'sent_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * `template_key` stays a string; the three ids are cast to int or null so a
     * header that is somehow not numeric does not become a foreign key of 0.
     *
     * @return array<string, int|string|null>
     */
    private function context(Email $message): array
    {
        $context = [];

        foreach (self::CONTEXT_HEADERS as $column => $header) {
            $value = $message->getHeaders()->get($header)?->getBodyAsString();

            $context[$column] = match (true) {
                $value === null || $value === '' => null,
                $column === 'template_key' => $value,
                ctype_digit($value) => (int) $value,
                default => null,
            };
        }

        return $context;
    }

    /**
     * Laravel puts the originating class in the message data:
     * `__laravel_mailable` for a Mailable (Mailable.php:400) and
     * `__laravel_notification` for a Notification (MailChannel.php:157).
     * Anything else - a raw Mail::raw() - is recorded as such rather than left
     * blank, because "which code sent this" is the first question triage asks.
     *
     * @param  array<string, mixed>  $data
     */
    private function source(array $data): string
    {
        $source = $data['__laravel_mailable'] ?? $data['__laravel_notification'] ?? null;

        return is_string($source) ? $source : 'raw';
    }

    private function firstRecipient(Email $message): string
    {
        $to = $message->getTo();

        return $to === [] ? 'unknown' : $to[0]->getAddress();
    }
}
```

- [ ] **Step 11: Register the listeners in `app/Providers/AppServiceProvider.php`**

Add the imports `use App\Listeners\RecordOutgoingEmail;`, `use Illuminate\Mail\Events\MessageSending;`, `use Illuminate\Mail\Events\MessageSent;` and `use Illuminate\Support\Facades\Event;`, then at the end of `boot()`:

```php
        // Registered by hand rather than by Laravel 13's listener discovery:
        // discovery matches one class to one event by the type hint of a
        // `handle()` method, and this listener deliberately has two entry
        // points for two events so the pair cannot drift apart in two files.
        Event::listen(MessageSending::class, [RecordOutgoingEmail::class, 'sending']);
        Event::listen(MessageSent::class, [RecordOutgoingEmail::class, 'sent']);
```

- [ ] **Step 12: Run the tests**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/RenderEmailTemplateTest.php tests/Feature/Mail > /tmp/t.log 2>&1; echo "rc=$?"; tail -20 /tmp/t.log
```

Expected: `rc=0`. Nothing in this file reaches forward into a later task, so there is no known failure to tolerate here: anything red is red because of this task.

Then the whole suite, because a task never commits on a red one:

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test > /tmp/t.log 2>&1; echo "rc=$?"; tail -3 /tmp/t.log
```

Expected: `rc=0`.

- [ ] **Step 13: Pint, Larastan, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && \
./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?" && \
git add -A && git commit -q -m "feat(mail): templated email, platform defaults and the email_logs pipeline

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

Expected: `stan rc=0`.

---

### Task 4: Private file storage and the signed `/files/{ulid}` download

Spec section 8 in one task: 10 MB per file, count and extension from the conference's own configuration, MIME sniffed from the content, a content-addressed path on the private disk, and downloads only through a signed, expiring route.

**One infrastructural change is needed.** `docker/php.ini` sets `upload_max_filesize=12M` and `post_max_size=40M`, `docker/nginx.conf` sets `client_max_body_size 40m`, and Livewire 4's default temporary-upload rule is `max:12288` (KB, `vendor/livewire/livewire/src/Features/SupportFileUploads/FileUploadConfiguration.php:116`) — all comfortable for one 10 MB file. But Livewire posts **every** file of a `multiple` input in a single request (`uploadMultiple()` appends each file as `files[]` to one FormData, `vendor/livewire/livewire/dist/livewire.js:777`), and `conferences.max_files` is organizer-settable up to 10 (`app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceForm.php:140`), so four 10 MB files already exceed both 40 MB ceilings and nginx returns 413 before PHP is reached — the blank, message-less failure the application-level cap exists to avoid. Raise both to cover the maximum the organizer form allows:

- `docker/nginx.conf`: `client_max_body_size 110m;`
- `docker/php.ini`: `post_max_size=110M`

`upload_max_filesize=12M` stays as the per-file ceiling just above the 10 MB application cap, and PHP's default `max_file_uploads=20` already covers ten parts. Uploaded parts stream to disk (nginx `client_body_temp_path` under `/var/lib/nginx`, already chowned to `app` in the Dockerfile, then PHP's `upload_tmp_dir`), not into the PHP heap, so this does not move the 768 MB container limit or the 256 MB `memory_limit`. The 10 MB per-file cap in `StoreSubmissionFile` is still the refusal an author actually meets — which is the point — and Task 7 pins Livewire's own temporary-upload rule to the same number in `config/livewire.php`.

**Files:**
- Create: `app/Exceptions/SubmissionFileRejected.php`, `app/Actions/Submissions/StoreSubmissionFile.php`, `app/Actions/Submissions/DeleteSubmissionFile.php`
- Create: `app/Http/Controllers/Public/SubmissionFileController.php`
- Modify: `routes/web.php`, `app/Providers/AppServiceProvider.php`, `config/cass.php`, `.env.example`, `docker/nginx.conf`, `docker/php.ini`
- Test: `tests/Feature/Public/SubmissionFileDownloadTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Public/SubmissionFileDownloadTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Submissions\DeleteSubmissionFile;
use App\Actions\Submissions\StoreSubmissionFile;
use App\Exceptions\SubmissionFileRejected;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\SubmissionFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\get;

beforeEach(function () {
    Storage::fake('local');

    $this->conference = Conference::factory()->published()->create([
        'max_files' => 2,
        'allowed_file_types' => ['pdf'],
    ]);
    $this->submission = Submission::factory()->for($this->conference)->create();
});

function uploadedPdf(string $name = 'abstract.pdf'): UploadedFile
{
    // A copy of the real fixture, so finfo sees a real %PDF- header.
    // UploadedFile::fake()->create() writes zero bytes, which sniffs as
    // application/x-empty and would make every MIME assertion here vacuous.
    return new UploadedFile(base_path('tests/Fixtures/abstract.pdf'), $name, 'application/pdf', null, true);
}

it('stores a pdf content-addressed on the private disk', function () {
    $file = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf());

    $sha = hash_file('sha256', base_path('tests/Fixtures/abstract.pdf'));

    expect($file->original_name)->toBe('abstract.pdf')
        ->and($file->mime)->toBe('application/pdf')
        ->and($file->sha256)->toBe($sha)
        ->and($file->size)->toBe(filesize(base_path('tests/Fixtures/abstract.pdf')))
        ->and($file->sort)->toBe(1)
        ->and($file->path)->toBe(substr($sha, 0, 2).'/'.$file->ulid.'.pdf');

    Storage::disk('local')->assertExists($file->path);
    // The private disk's root is storage/app/private and nothing serves it;
    // the public disk must never see a submission file.
    Storage::disk('public')->assertMissing($file->path);
});

it('appends rather than colliding on sort', function () {
    $first = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf('one.pdf'));
    // A second, genuinely different file: the same bytes would be a duplicate.
    $second = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdfWithSuffix('two.pdf', 'second'));

    expect([$first->sort, $second->sort])->toBe([1, 2]);
});

it('sees through a png wearing a pdf name', function () {
    $png = new UploadedFile(base_path('tests/Fixtures/not-really.pdf'), 'abstract.pdf', 'application/pdf', null, true);

    expect(fn () => app(StoreSubmissionFile::class)->handle($this->submission, $png))
        ->toThrow(SubmissionFileRejected::class, 'does not look like a PDF');

    expect($this->submission->files()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses an extension the conference does not allow', function () {
    $doc = new UploadedFile(base_path('tests/Fixtures/abstract.pdf'), 'abstract.docx', 'application/pdf', null, true);

    expect(fn () => app(StoreSubmissionFile::class)->handle($this->submission, $doc))
        ->toThrow(SubmissionFileRejected::class, 'PDF');
});

it('refuses a file over the size cap', function () {
    config()->set('cass.max_file_bytes', 100);

    expect(fn () => app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf()))
        ->toThrow(SubmissionFileRejected::class, 'larger than');
});

it('refuses more files than the conference allows', function () {
    app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf('one.pdf'));
    app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdfWithSuffix('two.pdf', 'second'));

    expect(fn () => app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdfWithSuffix('three.pdf', 'third')))
        ->toThrow(SubmissionFileRejected::class, '2 file');
});

it('refuses the same bytes twice in one submission but allows them in another', function () {
    app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf());

    expect(fn () => app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf('renamed.pdf')))
        ->toThrow(SubmissionFileRejected::class, 'already attached');

    $other = Submission::factory()->for($this->conference)->create();
    $second = app(StoreSubmissionFile::class)->handle($other, uploadedPdf());

    expect($second->sha256)->toBe($this->submission->files()->first()?->sha256)
        // Same content, different object: the ULID in the file name means one
        // submission deleting its copy can never remove another's.
        ->and($second->path)->not->toBe($this->submission->files()->first()?->path);
});

it('deletes the row and the object', function () {
    $file = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf());
    $path = (string) $file->path;

    app(DeleteSubmissionFile::class)->handle($file);

    Storage::disk('local')->assertMissing($path);
    expect(SubmissionFile::query()->count())->toBe(0);
});

it('serves a signed url as an attachment and nothing else', function () {
    $file = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf());

    $response = get($file->temporaryUrl());

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('x-content-type-options', 'nosniff');

    expect($response->headers->get('content-disposition'))->toStartWith('attachment;')
        ->toContain('abstract.pdf')
        ->and($response->headers->get('cache-control'))->toContain('no-store');
});

it('refuses an unsigned, an expired and a tampered url', function () {
    $file = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf());
    $other = Submission::factory()->for($this->conference)->create();
    $otherFile = app(StoreSubmissionFile::class)->handle($other, uploadedPdfWithSuffix('other.pdf', 'other'));

    // No signature at all.
    get('/files/'.$file->ulid)->assertForbidden();

    // A signature that has run out.
    $expired = URL::temporarySignedRoute('files.download', now()->subMinute(), ['ulid' => $file->ulid]);
    get($expired)->assertForbidden();

    // The signature of one file, pointed at another: the ULID is inside the
    // signed payload, so swapping it invalidates the whole URL.
    $tampered = str_replace((string) $file->ulid, (string) $otherFile->ulid, $file->temporaryUrl());
    get($tampered)->assertForbidden();
});

it('404s a valid signature over a file or submission that is gone', function () {
    $file = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf());
    $url = $file->temporaryUrl();

    get($url)->assertOk();

    $this->submission->delete();

    // The signature is still valid; there is simply nothing to serve. A
    // soft-deleted submission is gone to everyone outside the admin panel.
    get($url)->assertNotFound();
});

it('404s a well-formed ulid that was never a file', function () {
    $url = URL::temporarySignedRoute('files.download', now()->addMinutes(30), ['ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);

    get($url)->assertNotFound();
});

it('refuses a ulid that is not a ulid before reaching the controller', function () {
    // The route constraint is Crockford base32: no I, L, O or U. A path that
    // cannot be a ULID must not reach the database at all.
    get('/files/not-a-ulid')->assertNotFound();
    get('/files/01ARZ3NDEKTSV4RRFFQ69G5FAU')->assertNotFound();
});

/** A distinct PDF, so the sha256 differs from the fixture's. */
function uploadedPdfWithSuffix(string $name, string $suffix): UploadedFile
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$suffix.'-'.$name;
    file_put_contents($path, file_get_contents(base_path('tests/Fixtures/abstract.pdf')).'%% '.$suffix."\n");

    return new UploadedFile($path, $name, 'application/pdf', null, true);
}
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Public/SubmissionFileDownloadTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found" /tmp/t.log | head -3
```

Expected: `rc=1`, `Class "App\Actions\Submissions\StoreSubmissionFile" not found`.

- [ ] **Step 3: Write `app/Exceptions/SubmissionFileRejected.php`**

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Every refusal an author can meet while attaching a file, each with a sentence
 * they can act on. The Livewire component turns the message into a field error;
 * nothing about the storage layout, the disk or the sniffed type leaks into it.
 */
class SubmissionFileRejected extends RuntimeException
{
    /** @param  list<string>  $allowed */
    public static function extension(string $extension, array $allowed): self
    {
        $list = strtoupper(implode(', ', $allowed));

        return new self($extension === ''
            ? "This conference accepts {$list} files. Please rename your file so it ends in the right extension."
            : "This conference accepts {$list} files, not .{$extension}.");
    }

    public static function contentMismatch(string $extension): self
    {
        // Never says what it *is*: an uploader learning "we think this is a
        // PNG" is learning how the check works.
        return new self('That file does not look like a '.strtoupper($extension).' inside. Please upload the original document.');
    }

    public static function tooLarge(int $bytes, int $limit): self
    {
        return new self(sprintf(
            'That file is %.1f MB, which is larger than the %d MB limit.',
            $bytes / 1_048_576,
            (int) round($limit / 1_048_576),
        ));
    }

    public static function tooMany(int $limit): self
    {
        return new self($limit === 0
            ? 'This conference does not accept file attachments.'
            : 'You can attach at most '.$limit.' file'.($limit === 1 ? '' : 's').'. Remove one first.');
    }

    public static function duplicate(string $originalName): self
    {
        return new self("That file is already attached to this abstract (as \"{$originalName}\").");
    }

    public static function unreadable(): self
    {
        return new self('That file could not be read. Please try uploading it again.');
    }
}
```

- [ ] **Step 4: Write `app/Actions/Submissions/StoreSubmissionFile.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Exceptions\SubmissionFileRejected;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Support\Files\SniffedMimeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Spec section 8: "Uploads are validated by size (10 MB per file), count,
 * extension and sniffed MIME, and are never executed or served directly."
 *
 * The order of the checks is deliberate: everything that can be decided without
 * reading the file comes first, so a 10 MB upload from a conference that
 * accepts no files at all is refused before a single byte is hashed.
 */
class StoreSubmissionFile
{
    public function handle(Submission $submission, UploadedFile $file): SubmissionFile
    {
        $conference = $submission->conference;

        $maxFiles = (int) $conference->max_files;
        if ($submission->files()->count() >= $maxFiles) {
            throw SubmissionFileRejected::tooMany($maxFiles);
        }

        /** @var list<string> $allowed */
        $allowed = array_values(array_map('strtolower', $conference->allowed_file_types ?? ['pdf']));
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $allowed, true)) {
            throw SubmissionFileRejected::extension($extension, $allowed);
        }

        $limit = (int) config('cass.max_file_bytes');
        $size = (int) $file->getSize();

        if ($size > $limit) {
            throw SubmissionFileRejected::tooLarge($size, $limit);
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw SubmissionFileRejected::unreadable();
        }

        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw SubmissionFileRejected::unreadable();
        }

        try {
            // Content, not the client's Content-Type header and not the name.
            $mime = SniffedMimeType::forStream($stream);

            if (! SniffedMimeType::matches($mime, $extension)) {
                throw SubmissionFileRejected::contentMismatch($extension);
            }

            $sha = hash_file('sha256', $path);

            if ($sha === false) {
                throw SubmissionFileRejected::unreadable();
            }

            $duplicate = $submission->files()->where('sha256', $sha)->first();

            if ($duplicate !== null) {
                throw SubmissionFileRejected::duplicate((string) $duplicate->original_name);
            }

            $ulid = (string) Str::ulid();
            // {first 2 of sha256}/{ulid}.{ext}: the prefix keeps any one
            // directory to a few thousand entries, and the ULID in the name
            // means two submissions holding identical bytes still own separate
            // objects - so one of them deleting its copy cannot break the other.
            $storedPath = substr($sha, 0, 2).'/'.$ulid.'.'.$extension;

            Storage::disk('local')->writeStream($storedPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $record = new SubmissionFile;
        $record->forceFill([
            'submission_id' => $submission->getKey(),
            'ulid' => $ulid,
            'original_name' => $this->safeName($file->getClientOriginalName(), $extension),
            'path' => $storedPath,
            'mime' => (string) $mime,
            'size' => $size,
            'sha256' => $sha,
            // Append: ((int) max) + 1 rather than count() + 1, so removing the
            // middle file of three does not give the next upload a taken sort.
            'sort' => ((int) $submission->files()->max('sort')) + 1,
        ]);
        $record->save();

        return $record;
    }

    /**
     * The original name is shown to organizers and put into a
     * Content-Disposition header. Keep it recognisable, drop anything that
     * could be read as a path, and keep the extension the content was checked
     * against - not whatever the name happened to end in.
     */
    private function safeName(string $name, string $extension): string
    {
        $base = pathinfo(str_replace(['\\', '/'], '-', $name), PATHINFO_FILENAME);
        $base = trim(preg_replace('/[^\p{L}\p{N} ._-]+/u', '', $base) ?? '');
        $base = Str::limit($base === '' ? 'attachment' : $base, 120, '');

        return $base.'.'.$extension;
    }
}
```

- [ ] **Step 5: Write `app/Actions/Submissions/DeleteSubmissionFile.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\SubmissionFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The object first, the row second, both in one transaction: a row without an
 * object is a broken download link the organizer cannot explain, while an
 * object without a row is an orphan nothing will ever reference (content
 * addressing means every object belongs to exactly one row - see
 * StoreSubmissionFile) and which the Plan 6 purge can sweep.
 */
class DeleteSubmissionFile
{
    public function handle(SubmissionFile $file): void
    {
        DB::transaction(function () use ($file): void {
            Storage::disk('local')->delete((string) $file->path);
            $file->delete();
        });
    }
}
```

- [ ] **Step 6: Write `app/Http/Controllers/Public/SubmissionFileController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\SubmissionFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spec section 6 and 8: `/files/{ulid}`, signed and expiring, streaming from
 * the private disk.
 *
 * There is no policy call here and no token check. The signature *is* the
 * capability: it was minted by the status page (which the author reached with
 * their token) or by the organizer panel (which checked SubmissionFilePolicy
 * before rendering the link). Adding a session check on top would break the
 * author case, and adding a token parameter would put the author's editing
 * credential into a URL that gets forwarded to a printer.
 *
 * What is still checked: the file exists, its submission has not been deleted,
 * and the object is really on disk.
 */
class SubmissionFileController extends Controller
{
    public function __invoke(string $ulid): StreamedResponse
    {
        $file = SubmissionFile::query()->where('ulid', $ulid)->first();

        abort_if($file === null, 404);

        // The `submission` relation applies Submission's SoftDeletes scope, so
        // a deleted abstract makes this null and the download stops - the
        // signed URL in an old email does not outlive the abstract.
        abort_if($file->submission === null, 404);

        abort_unless(Storage::disk('local')->exists((string) $file->path), 404);

        return Storage::disk('local')->download(
            (string) $file->path,
            (string) $file->original_name,
            [
                // The sniffed type from upload, never a guess from the
                // extension at download time.
                'Content-Type' => (string) $file->mime,
                // Belt and braces: SecurityHeaders sets this globally, and this
                // is the one response in the application whose body is
                // attacker-supplied bytes.
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }
}
```

`Storage::download()` builds the `Content-Disposition` with Symfony's `HeaderUtils::makeDisposition()`, which handles quoting and the RFC 5987 `filename*` form for a non-ASCII name — one more reason not to hand-build that header.

- [ ] **Step 7: Register the two public routes and their limiters**

In `routes/web.php`, add `use App\Http\Controllers\Public\SubmissionFileController;` and `use App\Livewire\Public\SubmissionStatus;`, and, after the `/q/{code}` route:

```php
// Spec sections 6 and 8. The signature is the capability, so no auth and no
// token here; the throttle is what stops a leaked URL being used to hammer the
// disk.
//
// No file extension in the path, for the same reason the conference asset
// routes have none: docker/nginx.conf answers any URI ending in a static-file
// extension from disk before PHP sees it. The saved file name comes from
// Content-Disposition.
//
// The constraint is the Crockford base32 alphabet a ULID uses - no I, L, O or
// U - so a path that could not be a ULID never reaches the database.
Route::get('/files/{ulid}', SubmissionFileController::class)
    ->middleware(['signed', 'throttle:file-download'])
    ->where('ulid', '[0-9A-HJKMNP-TV-Z]{26}')
    ->name('files.download');

// Registered here rather than with the component (Task 8) because
// Submission::statusUrl() resolves this name from Task 5 onwards, and every
// Task 5 test that touches SubmissionLink::url(), SubmitAbstract's placeholders
// or SendSubmissionStatusLink would otherwise die on RouteNotFoundException.
// `use` and `::class` are compile-time only and Livewire's
// routeActionIsAPageComponent() runs at dispatch, so naming a class Task 8
// writes never autoloads it here.
//
// No AuthenticateSession and no auth: there is no account here at all.
// The throttle is spec section 9's 20/min/IP, and it is on the route rather
// than in the component because a page *view* has to be counted too - guessing
// tokens is a GET loop, not a Livewire action.
Route::get('/s/{token}', SubmissionStatus::class)
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:submission-status')
    ->name('submission.status');
```

In `app/Providers/AppServiceProvider.php`, next to the `conference-assets` limiter:

```php
        // A signed URL is a bearer capability with a 30-minute life. If one
        // leaks, this is what keeps it from being used to stream a 10 MB PDF a
        // thousand times off a four-worker pool.
        RateLimiter::for('file-download', fn (Request $request): Limit => Limit::perMinute(60)
            ->by(ClientIp::from($request)));

        // Spec section 9: the author status page, 20/min/IP. The key is the
        // client address only - not the token - so that enumerating tokens
        // counts against one budget instead of getting a fresh one per guess.
        RateLimiter::for('submission-status', fn (Request $request): Limit => Limit::perMinute(
            (int) config('cass.status_page_rate_limit')
        )->by(ClientIp::from($request)));
```

- [ ] **Step 8: Add the size cap, the status-page limit and the body-size ceilings**

`config/cass.php`, next to `file_url_minutes`:

```php
    // Spec section 8: 10 MB per file. Under docker/php.ini's
    // upload_max_filesize=12M and Livewire's own default max:12288, so the
    // refusal an author meets is this one - with a sentence - rather than a
    // blank 413 from PHP.
    'max_file_bytes' => (int) env('CASS_MAX_FILE_BYTES', 10 * 1024 * 1024),

    // Spec section 9: /s/{token} is 20 GETs per minute per address. It lives
    // here rather than with the submission knobs in Task 7 because the limiter
    // that reads it is registered in this task, alongside the route.
    'status_page_rate_limit' => (int) env('CASS_STATUS_PAGE_RATE_LIMIT', 20),
```

`.env.example`, in the CASS block:

```
# Author uploads: 10 MB per file, and how long a signed download link lives.
CASS_MAX_FILE_BYTES=10485760
CASS_FILE_URL_MINUTES=30
# Spec section 9: GETs per minute per address on the author status page.
CASS_STATUS_PAGE_RATE_LIMIT=20
```

`docker/nginx.conf`, replacing `client_max_body_size 40m;`:

```nginx
# Ten files at 10 MB is the most a conference may allow, and Livewire posts all
# of them in one multipart request. Sized for that, not for one file.
client_max_body_size 110m;
```

`docker/php.ini`, replacing `post_max_size=40M`:

```ini
post_max_size=110M
```

`upload_max_filesize=12M` is left alone: it is the per-file ceiling, and it already sits just above the 10 MB application cap.

- [ ] **Step 9: Run the tests, Pint, Larastan, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && \
php artisan test tests/Feature/Public/SubmissionFileDownloadTest.php > /tmp/t.log 2>&1; echo "files rc=$?"; tail -4 /tmp/t.log && \
php artisan route:list --except-vendor | grep -E "files|s/\{token\}"
```

Expected: `files rc=0`, `13 passed`, one line `GET|HEAD  files/{ulid} ... files.download`, and one line `GET|HEAD  s/{token} ... submission.status`. The second route has no component behind it until Task 8 — it is registered now because `Submission::statusUrl()` resolves its **name** from Task 5 onwards, and a name that is not registered is a `RouteNotFoundException` in every action that renders a status link.

```bash
cd /c/Users/ahmed/Documents/CASS && \
./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?"; \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log
```

Expected: `stan rc=0`, `tests rc=0`. Anything red here is a regression from this task.

```bash
cd /c/Users/ahmed/Documents/CASS && git add -A && git commit -q -m "feat(submissions): private content-addressed file storage and signed downloads

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

---

### Task 5: Save, submit, update and withdraw

The whole of spec 5.3 steps 3 and 4, with no user interface anywhere near it. The Livewire components in Tasks 6-8 and the panel actions in Task 9 all call these five classes and add nothing but field-level messages.

**The contract, stated once so Tasks 6-9 cannot drift from it:**

| Class | Signature |
|---|---|
| `SaveSubmissionDraft` | `handle(Conference $conference, array<string,mixed> $data, ?Submission $existing = null): SubmissionLink` |
| `SubmitAbstract` | `blockers(Submission $submission, bool $agreed): list<string>` and `handle(Submission $submission, bool $agreed, ?string $plainToken = null): Submission` |
| `SubmitAbstract` | `static notifiableMembers(Conference $conference): Collection<int, User>` |
| `UpdateSubmission` | `handle(Submission $submission, array<string,mixed> $data): Submission` |
| `WithdrawSubmission` | `handle(Submission $submission, ?User $actor = null): Submission` |
| `SendSubmissionStatusLink` | `handle(Submission $submission, ?string $plainToken = null): EmailLog` |

`$data` is always the same shape, and it is the only shape any caller may pass:

```
[
  'title' => string,
  'abstract' => string,
  'track_id' => int|null,
  'presentation_preference' => string|null,   // a PresentationPreference backing value
  'contact_phone' => string|null,
  'custom_field_values' => array<string, mixed>|null,
  'authors' => list<array{name: string, email: string, affiliation: string|null,
                          is_presenter: bool, is_corresponding: bool}>,
]
```

**Why `handle()` takes the plaintext token.** The author's link must survive from the draft email to the confirmation email: an author who bookmarks the link in "your draft is saved" has to find the same abstract there after they submit. So the token is issued once, when the row is created, and `SubmitAbstract` is *given* the plaintext by whoever already holds it (the form that just created the draft, or the status page the author arrived on). Passing `null` — which is what an organizer-side call does — reissues, and every link already in circulation dies. That is the right behaviour for "Resend status link" and the wrong behaviour for a normal submit, which is why it is a parameter and not a policy.

**Files:**
- Create: `app/Support/Submissions/SubmissionLink.php`, `app/Exceptions/SubmissionNotAcceptable.php`
- Create: `app/Actions/Submissions/{SaveSubmissionDraft,SubmitAbstract,UpdateSubmission,WithdrawSubmission,SendSubmissionStatusLink}.php`
- Test: `tests/Unit/SaveSubmissionDraftTest.php`, `tests/Unit/SubmitAbstractTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/SaveSubmissionDraftTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Submissions\SaveSubmissionDraft;
use App\Actions\Submissions\UpdateSubmission;
use App\Actions\Submissions\WithdrawSubmission;
use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\Submission;
use App\Models\Track;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->conference = Conference::factory()->published()->create(['word_limit' => 300]);
});

function draftData(array $overrides = []): array
{
    return array_replace([
        'title' => 'Early mobilisation after cardiac surgery',
        'abstract' => 'Background. Methods. Results. Conclusion.',
        'track_id' => null,
        'presentation_preference' => PresentationPreference::Oral->value,
        'contact_phone' => '+966500000000',
        'custom_field_values' => null,
        'authors' => [
            ['name' => 'Dr Sara Al-Harbi', 'email' => 'sara@example.org', 'affiliation' => 'KFSH', 'is_presenter' => true, 'is_corresponding' => true],
        ],
    ], $overrides);
}

it('creates a draft with a token, a word count and its authors', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());

    $submission = $link->submission->refresh();

    expect($submission->status)->toBe(SubmissionStatus::Draft)
        ->and($submission->conference_id)->toBe($this->conference->id)
        ->and($submission->reference)->toBeNull()
        // Four tokens: "Background.", "Methods.", "Results.", "Conclusion."
        ->and($submission->word_count)->toBe(4)
        ->and($submission->last_edited_at)->not->toBeNull()
        ->and($submission->authors)->toHaveCount(1)
        ->and($submission->correspondingAuthor()?->email)->toBe('sara@example.org')
        ->and($link->token)->toMatch('/^[A-Za-z0-9]{64}$/')
        ->and($link->url())->toBe(route('submission.status', ['token' => $link->token]));
});

it('writes the real hash with the insert, never a shared placeholder', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());

    // A fixed placeholder in a UNIQUE char(64) would make every concurrent
    // first save queue on one index record for the length of the transaction.
    // SQLite serialises writes and MySQL blocks rather than erroring, so no
    // test here can observe the stall - this asserts the shape that prevents it.
    expect($link->submission->access_token_hash)->not->toBe(str_repeat('0', 64))
        ->and(Submission::findByPlainToken((string) $link->token)?->is($link->submission))->toBeTrue();
});

it('recomputes the word count instead of trusting the caller', function () {
    // 'word_count' is not in $fillable and is not part of the accepted $data
    // shape, so an extra key must be ignored rather than stored.
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'abstract' => 'One two three',
        'word_count' => 1,
    ]));

    expect($link->submission->refresh()->word_count)->toBe(3);
});

it('reuses the row and does not mint a second token on a second save', function () {
    $first = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());
    $hash = $first->submission->access_token_hash;

    $second = app(SaveSubmissionDraft::class)->handle(
        $this->conference,
        draftData(['title' => 'A better title']),
        $first->submission,
    );

    expect($second->submission->is($first->submission))->toBeTrue()
        ->and($second->token)->toBeNull()
        ->and($second->url())->toBeNull()
        ->and($second->submission->refresh()->title)->toBe('A better title')
        ->and($second->submission->access_token_hash)->toBe($hash);
});

it('replaces the author list wholesale rather than appending', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'authors' => [
            ['name' => 'A', 'email' => 'a@example.org', 'affiliation' => null, 'is_presenter' => true, 'is_corresponding' => true],
            ['name' => 'B', 'email' => 'b@example.org', 'affiliation' => null, 'is_presenter' => false, 'is_corresponding' => false],
        ],
    ]));

    app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'authors' => [
            ['name' => 'C', 'email' => 'c@example.org', 'affiliation' => null, 'is_presenter' => true, 'is_corresponding' => true],
        ],
    ]), $link->submission);

    expect($link->submission->refresh()->authors->pluck('email')->all())->toBe(['c@example.org']);
});

it('numbers the authors from one in the order they were given', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'authors' => [
            ['name' => 'First', 'email' => 'a@example.org', 'affiliation' => null, 'is_presenter' => false, 'is_corresponding' => true],
            ['name' => 'Second', 'email' => 'b@example.org', 'affiliation' => null, 'is_presenter' => true, 'is_corresponding' => false],
            ['name' => 'Third', 'email' => 'c@example.org', 'affiliation' => null, 'is_presenter' => false, 'is_corresponding' => false],
        ],
    ]));

    expect($link->submission->authors->pluck('sort')->all())->toBe([1, 2, 3])
        ->and($link->submission->authors->pluck('name')->all())->toBe(['First', 'Second', 'Third']);
});

it('keeps only one corresponding author even when the caller ticks two', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'authors' => [
            ['name' => 'A', 'email' => 'a@example.org', 'affiliation' => null, 'is_presenter' => false, 'is_corresponding' => true],
            ['name' => 'B', 'email' => 'b@example.org', 'affiliation' => null, 'is_presenter' => false, 'is_corresponding' => true],
        ],
    ]));

    expect($link->submission->authors->where('is_corresponding', true))->toHaveCount(1)
        ->and($link->submission->correspondingAuthor()?->email)->toBe('a@example.org');
});

it('promotes the first author when the caller ticks none', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'authors' => [
            ['name' => 'A', 'email' => 'a@example.org', 'affiliation' => null, 'is_presenter' => false, 'is_corresponding' => false],
        ],
    ]));

    expect($link->submission->correspondingAuthor()?->email)->toBe('a@example.org');
});

it('drops a custom field value the conference does not define', function () {
    CustomField::factory()->for($this->conference)->create(['label' => 'Funding source']);

    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'custom_field_values' => ['funding_source' => 'None', 'is_admin' => true],
    ]));

    expect($link->submission->refresh()->custom_field_values)->toBe(['funding_source' => 'None']);
});

it('refuses a track that belongs to another conference', function () {
    $foreign = Track::factory()->for(Conference::factory()->published())->create();

    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData(['track_id' => $foreign->id]));

    // A draft accepts nearly anything, but never a cross-conference foreign
    // key: that would put another organization's track name on this page.
    expect($link->submission->refresh()->track_id)->toBeNull();
});

it('lets the author edit a submitted abstract without changing its status', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());
    $submission = $link->submission;
    $submission->forceFill(['status' => SubmissionStatus::Submitted, 'reference' => 'X-001', 'submitted_at' => now()])->save();

    Carbon::setTestNow(now()->addHour());

    $updated = app(UpdateSubmission::class)->handle($submission, draftData(['title' => 'Revised title']));

    expect($updated->status)->toBe(SubmissionStatus::Submitted)
        ->and($updated->reference)->toBe('X-001')
        ->and($updated->title)->toBe('Revised title')
        ->and($updated->last_edited_at?->toDateTimeString())->toBe(now()->toDateTimeString());

    Carbon::setTestNow();
});

it('refuses an edit once the window has closed', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());

    Carbon::setTestNow($this->conference->submission_deadline->copy()->addMinute());

    expect(fn () => app(UpdateSubmission::class)->handle($link->submission, draftData(['title' => 'Too late'])))
        ->toThrow(App\Exceptions\SubmissionNotAcceptable::class);

    Carbon::setTestNow();
});

it('withdraws and refuses to withdraw twice', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());
    $submission = $link->submission;
    $submission->forceFill(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()])->save();

    $withdrawn = app(WithdrawSubmission::class)->handle($submission);

    expect($withdrawn->status)->toBe(SubmissionStatus::Withdrawn)
        ->and($withdrawn->withdrawn_at)->not->toBeNull()
        // The reference is kept: an organizer who already printed a programme
        // needs the number to still mean something.
        ->and($withdrawn->submitted_at)->not->toBeNull();

    expect(fn () => app(WithdrawSubmission::class)->handle($withdrawn))
        ->toThrow(App\Exceptions\SubmissionNotAcceptable::class);
});

it('refuses an author withdrawal after the deadline but allows the organizer one', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());
    $link->submission->forceFill(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()])->save();

    Carbon::setTestNow($this->conference->submission_deadline->copy()->addMinute());

    // No actor: this is the author, holding their token, after the deadline.
    // SubmissionStatus::isOpenToAuthor() is still true for Submitted, so only
    // the window check stops this.
    expect(fn () => app(WithdrawSubmission::class)->handle($link->submission))
        ->toThrow(App\Exceptions\SubmissionNotAcceptable::class);

    expect($link->submission->refresh()->status)->toBe(SubmissionStatus::Submitted);

    // An actor is an organizer, and an author who emails after the deadline
    // still has to be taken off the programme.
    $organizer = App\Models\User::factory()->create();

    expect(app(WithdrawSubmission::class)->handle($link->submission, $organizer)->status)
        ->toBe(SubmissionStatus::Withdrawn);

    Carbon::setTestNow();
});

it('records who withdrew an abstract in the activity log', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());
    $actor = App\Models\User::factory()->create();

    app(WithdrawSubmission::class)->handle($link->submission, $actor);

    $activity = Spatie\Activitylog\Models\Activity::query()->where('description', 'submission.withdrawn')->firstOrFail();

    expect($activity->causer_id)->toBe($actor->id)
        ->and($activity->subject_id)->toBe($link->submission->id);
});
```

`tests/Unit/SubmitAbstractTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Submissions\SaveSubmissionDraft;
use App\Actions\Submissions\SendSubmissionStatusLink;
use App\Actions\Submissions\SubmitAbstract;
use App\Enums\EmailTemplateKey;
use App\Enums\OrganizationRole;
use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use App\Exceptions\SubmissionNotAcceptable;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Notifications\NewSubmissionNotice;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Gulf Pediatric Society']);
    $this->conference = Conference::factory()->for($this->organization)->published()->create([
        'name' => 'Gulf Pediatric Critical Care 2026',
        'word_limit' => 10,
        'reference_prefix' => 'GPCC26',
        'presentation_types' => ['oral', 'poster'],
    ]);
});

/** A saved draft that is ready to submit, plus the plaintext token. */
function readyDraft(Conference $conference, array $overrides = []): App\Support\Submissions\SubmissionLink
{
    return app(SaveSubmissionDraft::class)->handle($conference, array_replace([
        'title' => 'Early mobilisation after cardiac surgery',
        'abstract' => 'Background methods results and a short conclusion here now',
        'track_id' => null,
        'presentation_preference' => PresentationPreference::Oral->value,
        'contact_phone' => '+966500000000',
        'custom_field_values' => null,
        'authors' => [
            ['name' => 'Dr Sara Al-Harbi', 'email' => 'sara@example.org', 'affiliation' => 'KFSH', 'is_presenter' => true, 'is_corresponding' => true],
        ],
    ], $overrides));
}

it('assigns a reference, stamps submitted_at and emails the corresponding author', function () {
    Mail::fake();
    Notification::fake();

    $link = readyDraft($this->conference);

    $submission = app(SubmitAbstract::class)->handle($link->submission, agreed: true, plainToken: $link->token);

    expect($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($submission->reference)->toBe('GPCC26-001')
        ->and($submission->submitted_at)->not->toBeNull();

    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('sara@example.org')
        && $mail->templateKey === EmailTemplateKey::SubmissionReceived->value
        // The same link the draft email carried: the author's bookmark keeps
        // working across the submit.
        && str_contains($mail->body, $link->token));

    $log = EmailLog::query()->firstOrFail();
    expect($log->template_key)->toBe('submission_received')
        ->and($log->submission_id)->toBe($submission->id)
        ->and($log->conference_id)->toBe($this->conference->id);
});

it('numbers submissions in order within a conference and separately across conferences', function () {
    Mail::fake();
    Notification::fake();

    $other = Conference::factory()->for($this->organization)->published()->create(['reference_prefix' => 'KSAU30', 'word_limit' => 10]);

    $a = readyDraft($this->conference);
    $b = readyDraft($this->conference);
    $c = readyDraft($other);

    expect(app(SubmitAbstract::class)->handle($a->submission, true, $a->token)->reference)->toBe('GPCC26-001')
        ->and(app(SubmitAbstract::class)->handle($b->submission, true, $b->token)->reference)->toBe('GPCC26-002')
        ->and(app(SubmitAbstract::class)->handle($c->submission, true, $c->token)->reference)->toBe('KSAU30-001');
});

it('notifies only the members who opted in', function () {
    Mail::fake();
    Notification::fake();

    $wants = User::factory()->create();
    $doesNot = User::factory()->create();
    $this->organization->addMember($wants, OrganizationRole::Owner);
    $this->organization->addMember($doesNot, OrganizationRole::Member);
    $this->organization->members()->updateExistingPivot($doesNot->id, ['notify_on_submission' => false]);

    $link = readyDraft($this->conference);
    app(SubmitAbstract::class)->handle($link->submission, true, $link->token);

    Notification::assertSentTo($wants, NewSubmissionNotice::class);
    Notification::assertNotSentTo($doesNot, NewSubmissionNotice::class);
});

it('selects exactly the members who opted in', function () {
    // The same query the pipeline case in tests/Feature/Mail/EmailLogPipelineTest.php
    // writes out by hand. This is the one that pins the shared definition, so
    // that file stays a test of the pipeline rather than of this method.
    $wants = User::factory()->create();
    $doesNot = User::factory()->create();
    $this->organization->addMember($wants, OrganizationRole::Owner);
    $this->organization->addMember($doesNot, OrganizationRole::Member);
    $this->organization->members()->updateExistingPivot($doesNot->id, ['notify_on_submission' => false]);

    expect(SubmitAbstract::notifiableMembers($this->conference)->pluck('id')->all())->toBe([$wants->id]);
});

it('reissues the token when the caller has no plaintext', function () {
    Mail::fake();
    Notification::fake();

    $link = readyDraft($this->conference);
    $oldHash = $link->submission->access_token_hash;

    app(SubmitAbstract::class)->handle($link->submission, agreed: true, plainToken: null);

    expect($link->submission->refresh()->access_token_hash)->not->toBe($oldHash)
        ->and(Submission::findByPlainToken((string) $link->token))->toBeNull();
});

it('lists every blocker in spec 5.3 rather than stopping at the first', function (array $mutate, string $expected) {
    $link = readyDraft($this->conference, $mutate['data'] ?? []);

    if (isset($mutate['then'])) {
        $mutate['then']($link->submission, $this->conference);
    }

    $blockers = app(SubmitAbstract::class)->blockers($link->submission->refresh(), $mutate['agreed'] ?? true);

    expect(implode(' | ', $blockers))->toContain($expected);
})->with([
    'over the word limit' => [[
        'data' => ['abstract' => 'one two three four five six seven eight nine ten eleven'],
    ], '10 words'],
    'no title' => [['data' => ['title' => '   ']], 'title'],
    'empty abstract' => [['data' => ['abstract' => '  ']], 'abstract'],
    'no authors' => [[
        'then' => fn (Submission $s) => $s->authors()->delete(),
    ], 'at least one author'],
    'presentation type the conference does not offer' => [[
        'data' => ['presentation_preference' => PresentationPreference::Either->value],
    ], 'presentation'],
    'agreement not ticked' => [['agreed' => false], 'terms'],
    'deadline passed' => [[
        'then' => fn (Submission $s, Conference $c) => Carbon::setTestNow($c->submission_deadline->copy()->addMinute()),
    ], 'closed'],
]);

afterEach(fn () => Carbon::setTestNow());

it('refuses a required custom field that is missing, and accepts it once given', function () {
    Mail::fake();
    Notification::fake();

    CustomField::factory()->for($this->conference)->create([
        'label' => 'Ethics approval number',
        'type' => App\Enums\CustomFieldType::Text,
        'required' => true,
    ]);

    $link = readyDraft($this->conference);

    expect(implode(' ', app(SubmitAbstract::class)->blockers($link->submission, true)))
        ->toContain('Ethics approval number');

    $link->submission->forceFill(['custom_field_values' => ['ethics_approval_number' => 'IRB-2026-14']])->save();

    expect(app(SubmitAbstract::class)->blockers($link->submission->refresh(), true))->toBe([]);
});

it('refuses a select answer that is not one of the offered options', function () {
    CustomField::factory()->for($this->conference)->create([
        'label' => 'Study design',
        'type' => App\Enums\CustomFieldType::Select,
        'options' => ['Randomised', 'Observational'],
        'required' => true,
    ]);

    $link = readyDraft($this->conference);
    $link->submission->forceFill(['custom_field_values' => ['study_design' => 'Made up']])->save();

    expect(implode(' ', app(SubmitAbstract::class)->blockers($link->submission->refresh(), true)))
        ->toContain('Study design');
});

it('refuses a track from another conference at submit time too', function () {
    $link = readyDraft($this->conference);
    $foreign = Track::factory()->for(Conference::factory()->published())->create();
    // Straight into the column, past SaveSubmissionDraft's own filter: the
    // submit gate must not assume the draft path was the only writer.
    $link->submission->forceFill(['track_id' => $foreign->id])->save();

    expect(implode(' ', app(SubmitAbstract::class)->blockers($link->submission->refresh(), true)))
        ->toContain('track');
});

it('throws with every reason and changes nothing when it refuses', function () {
    Mail::fake();

    $link = readyDraft($this->conference, ['title' => '  ']);

    expect(fn () => app(SubmitAbstract::class)->handle($link->submission, agreed: false))
        ->toThrow(SubmissionNotAcceptable::class);

    expect($link->submission->refresh()->status)->toBe(SubmissionStatus::Draft)
        ->and($link->submission->reference)->toBeNull()
        ->and($this->conference->refresh()->submission_counter)->toBe(0);

    Mail::assertNothingQueued();
});

it('refuses to submit an abstract twice', function () {
    Mail::fake();
    Notification::fake();

    $link = readyDraft($this->conference);
    app(SubmitAbstract::class)->handle($link->submission, true, $link->token);

    expect(fn () => app(SubmitAbstract::class)->handle($link->submission->refresh(), true))
        ->toThrow(SubmissionNotAcceptable::class);

    expect($this->conference->refresh()->submission_counter)->toBe(1);
});

it('resends a status link with a fresh token and the template that matches the status', function () {
    Mail::fake();

    $link = readyDraft($this->conference);

    $draftLog = app(SendSubmissionStatusLink::class)->handle($link->submission);

    expect($draftLog->template_key)->toBe(EmailTemplateKey::SubmissionDraftSaved->value)
        ->and(Submission::findByPlainToken((string) $link->token))->toBeNull();

    $link->submission->forceFill([
        'status' => SubmissionStatus::Submitted,
        'reference' => 'GPCC26-009',
        'submitted_at' => now(),
    ])->save();

    $submittedLog = app(SendSubmissionStatusLink::class)->handle($link->submission);

    expect($submittedLog->template_key)->toBe(EmailTemplateKey::SubmissionReceived->value)
        ->and($submittedLog->to_email)->toBe('sara@example.org');

    Mail::assertQueuedCount(2);
});

it('refuses to resend when there is nobody to send to', function () {
    $link = readyDraft($this->conference);
    $link->submission->authors()->delete();

    expect(fn () => app(SendSubmissionStatusLink::class)->handle($link->submission->refresh()))
        ->toThrow(SubmissionNotAcceptable::class);
});
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/SaveSubmissionDraftTest.php tests/Unit/SubmitAbstractTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found" /tmp/t.log | head -3
```

Expected: `rc=1`, `Class "App\Actions\Submissions\SaveSubmissionDraft" not found`.

- [ ] **Step 3: Write the value object and the exception**

`app/Support/Submissions/SubmissionLink.php`
```php
<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Models\Submission;

/**
 * A submission plus - only when one was just minted - the plaintext access
 * token for it. The token is null on every save after the first, because the
 * plaintext of an existing token does not exist anywhere to return.
 *
 * It is a separate object rather than a second return value or a transient
 * model attribute so that a caller cannot accidentally persist it: there is
 * exactly one property holding a secret, it is readonly, and it never touches
 * the model.
 */
final readonly class SubmissionLink
{
    public function __construct(
        public Submission $submission,
        public ?string $token,
    ) {}

    public function url(): ?string
    {
        return $this->token === null ? null : $this->submission->statusUrl($this->token);
    }
}
```

`app/Exceptions/SubmissionNotAcceptable.php`
```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Mirrors App\Exceptions\ConferenceNotPublishable from Plan 2: a list of
 * sentences an author (or an organizer) can act on, not a validation code.
 * SubmitAbstract::blockers() is the read-only half; this is what handle()
 * throws when a caller ignored it.
 */
class SubmissionNotAcceptable extends RuntimeException
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

- [ ] **Step 4: Write `app/Actions/Submissions/SaveSubmissionDraft.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
use App\Support\Submissions\SubmissionLink;
use App\Support\Text\WordCounter;
use App\Support\Tokens\SubmissionToken;
use Illuminate\Support\Facades\DB;

/**
 * The one write path for an abstract's own fields. Both public buttons go
 * through it: "Save draft" calls it and stops, "Submit" calls it and then calls
 * SubmitAbstract.
 *
 * It is permissive by design - spec 5.3 says a draft needs only a title and a
 * corresponding address - but it is not credulous: the three things it refuses
 * to store are a cross-conference track, a custom-field key the conference does
 * not define, and anything the caller invented for a guarded column. Those are
 * not validation failures to report, they are values that must never reach the
 * database, so they are dropped silently here and reported properly by
 * SubmitAbstract::blockers() where the author can still act on them.
 */
class SaveSubmissionDraft
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Conference $conference, array $data, ?Submission $existing = null): SubmissionLink
    {
        return DB::transaction(function () use ($conference, $data, $existing): SubmissionLink {
            $submission = $existing ?? new Submission;
            $isNew = ! $submission->exists;

            $abstract = trim((string) ($data['abstract'] ?? ''));

            $submission->fill([
                'title' => trim((string) ($data['title'] ?? '')),
                'abstract' => $abstract,
                'track_id' => $this->trackId($conference, $data['track_id'] ?? null),
                'presentation_preference' => $data['presentation_preference'] ?? null,
                'contact_phone' => $this->nullIfBlank($data['contact_phone'] ?? null),
                'custom_field_values' => $this->customFieldValues($conference, $data['custom_field_values'] ?? null),
            ]);

            // Guarded columns, all of them written here and nowhere else on
            // this path. word_count is recomputed rather than taken from the
            // page: the live counter in the browser is a convenience, not a
            // source of truth.
            $submission->forceFill([
                'conference_id' => $conference->getKey(),
                'word_count' => WordCounter::count($abstract),
                'last_edited_at' => now(),
            ]);

            // The real hash goes in with the INSERT. A shared placeholder in a
            // UNIQUE char(64) makes every concurrent first save queue on one
            // index record for the length of this transaction - which also
            // inserts every author row - so the hour before a deadline, when
            // the most authors are saving at once, is exactly when it costs the
            // most: lock-wait timeouts on MySQL for a query SQLite serialises
            // anyway, so no test in this suite would ever show it.
            $token = null;

            if ($isNew) {
                $token = SubmissionToken::generate();

                $submission->forceFill([
                    'status' => SubmissionStatus::Draft,
                    'access_token_hash' => SubmissionToken::hash($token),
                ]);
            }

            $submission->save();

            $this->syncAuthors($submission, is_array($data['authors'] ?? null) ? $data['authors'] : []);

            return new SubmissionLink($submission->refresh()->load('authors'), $token);
        });
    }

    /**
     * Replaced wholesale, not merged. The form sends the complete list every
     * time, and merging would resurrect an author the author deleted.
     *
     * Exactly one corresponding author always comes out: the first one ticked,
     * or the first author if nobody was ticked. spec 5.3 makes the
     * corresponding address the only way to reach this person, so a draft
     * without one would be a draft nobody could ever be told about.
     *
     * @param  list<array<string, mixed>>  $authors
     */
    private function syncAuthors(Submission $submission, array $authors): void
    {
        $submission->authors()->delete();

        $rows = [];
        $sort = 0;

        foreach ($authors as $author) {
            $email = mb_strtolower(trim((string) ($author['email'] ?? '')));
            $name = trim((string) ($author['name'] ?? ''));

            if ($email === '' && $name === '') {
                continue; // An empty row the author added and never filled in.
            }

            $rows[] = [
                'sort' => ++$sort,
                'name' => $name,
                'email' => $email,
                'affiliation' => $this->nullIfBlank($author['affiliation'] ?? null),
                'is_presenter' => (bool) ($author['is_presenter'] ?? false),
                'is_corresponding' => (bool) ($author['is_corresponding'] ?? false),
            ];
        }

        $corresponding = null;
        foreach ($rows as $index => $row) {
            if ($row['is_corresponding']) {
                $corresponding = $corresponding ?? $index;
            }
            $rows[$index]['is_corresponding'] = false;
        }

        if ($rows !== []) {
            $rows[$corresponding ?? array_key_first($rows)]['is_corresponding'] = true;
        }

        foreach ($rows as $row) {
            $submission->authors()->create($row);
        }

        $submission->unsetRelation('authors');
    }

    /** A track must belong to this conference or be absent. */
    private function trackId(Conference $conference, mixed $trackId): ?int
    {
        if (! is_numeric($trackId)) {
            return null;
        }

        return $conference->tracks()->whereKey((int) $trackId)->exists() ? (int) $trackId : null;
    }

    /**
     * Only keys the conference actually defines survive. The column is JSON and
     * is rendered back into a form, so an arbitrary key from a hand-made
     * request would otherwise be stored for ever and shown to organizers.
     *
     * @return array<string, mixed>|null
     */
    private function customFieldValues(Conference $conference, mixed $values): ?array
    {
        if (! is_array($values) || $values === []) {
            return null;
        }

        /** @var list<string> $keys */
        $keys = $conference->customFields()->pluck('key')->all();

        $filtered = array_intersect_key($values, array_flip($keys));

        return $filtered === [] ? null : $filtered;
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }
}
```

- [ ] **Step 5: Write `app/Actions/Submissions/SubmitAbstract.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Actions\Mail\SendTemplatedEmail;
use App\Enums\CustomFieldType;
use App\Enums\EmailTemplateKey;
use App\Enums\SubmissionStatus;
use App\Exceptions\SubmissionNotAcceptable;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\NewSubmissionNotice;
use App\Support\Text\WordCounter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Spec 5.3 step 3 and 4. The gate and the transition, in the same shape as
 * Plan 2's PublishConference: blockers() is a pure read that the form calls to
 * paint errors, handle() throws if a caller ignored it.
 *
 * Every rule here is re-checked against the *stored* row, never against the
 * request that produced it - the page is a convenience and a hand-made Livewire
 * call is not, and spec 5.3 says "Submit validates everything server-side".
 */
class SubmitAbstract
{
    public function __construct(
        private readonly AllocateReference $allocateReference,
        private readonly IssueSubmissionToken $issueToken,
        private readonly SendTemplatedEmail $sendTemplatedEmail,
    ) {}

    /**
     * @return list<string> empty when the abstract may be submitted
     */
    public function blockers(Submission $submission, bool $agreed): array
    {
        $conference = $submission->conference;
        $reasons = [];

        if ($submission->status !== SubmissionStatus::Draft) {
            $reasons[] = $submission->status === SubmissionStatus::Submitted
                ? 'This abstract has already been submitted.'
                : 'An abstract that is '.strtolower($submission->status->getLabel()).' cannot be submitted.';
        }

        if (! $conference->acceptsSubmissions()) {
            $reasons[] = 'Submissions for this conference are closed.';
        }

        if (trim((string) $submission->title) === '') {
            $reasons[] = 'Give your abstract a title.';
        }

        $abstract = trim((string) $submission->abstract);

        if ($abstract === '') {
            $reasons[] = 'Write the abstract itself.';
        } else {
            $words = WordCounter::count($abstract);
            $limit = (int) $conference->word_limit;

            if ($words > $limit) {
                $reasons[] = "The abstract is {$words} words. The limit is {$limit} words.";
            }
        }

        if ($submission->track_id !== null && ! $conference->tracks()->whereKey($submission->track_id)->exists()) {
            $reasons[] = 'Choose a track offered by this conference.';
        }

        /** @var list<string> $offered */
        $offered = $conference->presentation_types ?? [];
        $preference = $submission->presentation_preference?->value;

        if ($offered !== [] && ($preference === null || ! in_array($preference, $offered, true))) {
            $reasons[] = 'Choose a presentation preference this conference offers.';
        }

        $authors = $submission->authors()->get();

        if ($authors->isEmpty()) {
            $reasons[] = 'Add at least one author.';
        } else {
            $corresponding = $authors->where('is_corresponding', true);

            if ($corresponding->count() !== 1) {
                $reasons[] = 'Mark exactly one author as the corresponding author.';
            } elseif (filter_var($corresponding->first()?->email, FILTER_VALIDATE_EMAIL) === false) {
                $reasons[] = 'The corresponding author needs a valid email address.';
            }

            foreach ($authors as $author) {
                if (trim((string) $author->name) === '' || filter_var($author->email, FILTER_VALIDATE_EMAIL) === false) {
                    $reasons[] = 'Every author needs a name and a valid email address.';
                    break;
                }
            }
        }

        foreach ($this->customFieldBlockers($conference, $submission) as $reason) {
            $reasons[] = $reason;
        }

        if (! $agreed) {
            $reasons[] = 'You have to accept the terms before submitting.';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  string|null  $plainToken the author's existing token, when the caller has it.
     *                                  Passing null mints a new one and kills every
     *                                  link already in circulation.
     */
    public function handle(Submission $submission, bool $agreed, ?string $plainToken = null): Submission
    {
        $reasons = $this->blockers($submission, $agreed);

        if ($reasons !== []) {
            throw new SubmissionNotAcceptable($reasons);
        }

        $conference = $submission->conference;

        // One transaction around the counter and the row, so a failure between
        // them cannot burn a reference number. The emails are deliberately
        // queued *after* it commits: a queue worker that picks the job up
        // before the commit lands would read a submission with no reference.
        $submission = DB::transaction(function () use ($submission, $conference): Submission {
            $reference = $this->allocateReference->handle($conference);

            $submission->forceFill([
                'status' => SubmissionStatus::Submitted,
                'reference' => $reference,
                'submitted_at' => now(),
                'last_edited_at' => now(),
            ])->save();

            return $submission;
        });

        $token = $plainToken ?? $this->issueToken->handle($submission);

        $author = $submission->correspondingAuthor();

        if ($author !== null) {
            $this->sendTemplatedEmail->handle(
                EmailTemplateKey::SubmissionReceived,
                $conference,
                (string) $author->email,
                self::placeholderValues($submission, (string) $author->name, $token),
                $submission,
            );
        }

        Notification::send(self::notifiableMembers($conference), new NewSubmissionNotice($submission));

        activity()->performedOn($submission)->log('submission.submitted');

        return $submission->refresh();
    }

    /**
     * The organization members who asked to hear about new abstracts
     * (`organization_members.notify_on_submission`, default true).
     *
     * Static and public because SendSubmissionStatusLink, the panel and the
     * tests all need the same definition, and duplicating a `wherePivot` is how
     * an opt-out quietly stops working.
     *
     * @return Collection<int, User>
     */
    public static function notifiableMembers(Conference $conference): Collection
    {
        /** @var Collection<int, User> $members */
        $members = $conference->organization
            ->members()
            ->wherePivot('notify_on_submission', true)
            ->get();

        return $members;
    }

    /**
     * The spec 5.9 placeholder bag for the two author-facing keys. One method
     * so a template that starts using `{{deadline}}` does not need a second
     * call site updated.
     *
     * @return array<string, string|null>
     */
    public static function placeholderValues(Submission $submission, string $authorName, string $token): array
    {
        $conference = $submission->conference;

        return [
            'author_name' => $authorName,
            'title' => (string) $submission->title,
            'reference' => $submission->reference,
            'conference' => (string) $conference->name,
            'organization' => (string) $conference->organization->name,
            'deadline' => $conference->deadlineInConferenceTimezone()?->format('j F Y, H:i').' ('.$conference->timezone.')',
            'status_link' => $submission->statusUrl($token),
        ];
    }

    /** @return list<string> */
    private function customFieldBlockers(Conference $conference, Submission $submission): array
    {
        /** @var array<string, mixed> $values */
        $values = $submission->custom_field_values ?? [];
        $reasons = [];

        /** @var CustomField $field */
        foreach ($conference->customFields()->get() as $field) {
            $value = $values[$field->key] ?? null;
            $missing = $value === null || $value === '' || ($field->type === CustomFieldType::Checkbox && $value === false);

            if ($field->required && $missing) {
                $reasons[] = "\"{$field->label}\" is required.";

                continue;
            }

            if ($missing) {
                continue;
            }

            $valid = match ($field->type) {
                CustomFieldType::Number => is_numeric($value),
                CustomFieldType::Select => in_array($value, array_values((array) ($field->options ?? [])), true),
                CustomFieldType::Checkbox => is_bool($value),
                CustomFieldType::Text, CustomFieldType::Textarea => is_string($value) && mb_strlen($value) <= 5000,
            };

            if (! $valid) {
                $reasons[] = "\"{$field->label}\" does not have a valid answer.";
            }
        }

        return $reasons;
    }
}
```

- [ ] **Step 6: Write `UpdateSubmission`, `WithdrawSubmission` and `SendSubmissionStatusLink`**

`app/Actions/Submissions/UpdateSubmission.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Exceptions\SubmissionNotAcceptable;
use App\Models\Submission;

/**
 * Spec 5.3 step 5: the author edits until the deadline. The status does not
 * move - editing a submitted abstract leaves it submitted, and editing a draft
 * leaves it a draft - so this is SaveSubmissionDraft plus one gate.
 */
class UpdateSubmission
{
    public function __construct(private readonly SaveSubmissionDraft $save) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Submission $submission, array $data): Submission
    {
        if (! $submission->isOpenToAuthor()) {
            throw SubmissionNotAcceptable::because(
                $submission->status->isOpenToAuthor()
                    ? 'Submissions for this conference are closed, so this abstract can no longer be changed.'
                    : 'An abstract that is '.strtolower($submission->status->getLabel()).' can no longer be changed.',
            );
        }

        return $this->save->handle($submission->conference, $data, $submission)->submission;
    }
}
```

`app/Actions/Submissions/WithdrawSubmission.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\SubmissionStatus;
use App\Exceptions\SubmissionNotAcceptable;
use App\Models\Submission;
use App\Models\User;

/**
 * Withdrawal is the author's own exit and the organizer's tool for an author
 * who emailed instead of clicking. Nothing is deleted: the reference and
 * `submitted_at` stay, because an organizer who has already printed a
 * programme needs `GPCC26-017` to keep meaning something.
 *
 * `$actor` is the organizer when the panel calls it, and null when the author
 * does it from their status page (there is no account to attribute it to). The
 * activity-log entry records the difference, which is what a support question
 * six weeks later actually needs - and it is also what decides whether the
 * submission window applies, because the two callers are not the same person.
 */
class WithdrawSubmission
{
    public function handle(Submission $submission, ?User $actor = null): Submission
    {
        if ($submission->status === SubmissionStatus::Withdrawn) {
            throw SubmissionNotAcceptable::because('This abstract has already been withdrawn.');
        }

        if (! $submission->status->isOpenToAuthor()) {
            throw SubmissionNotAcceptable::because(
                'An abstract that is '.strtolower($submission->status->getLabel()).' cannot be withdrawn here. Contact the organizers.',
            );
        }

        // The author's own withdrawal closes with the submission window; the
        // organizer's (SubmissionActions::withdraw, which passes an actor)
        // deliberately does not - an author who emails after the deadline still
        // has to be taken off the programme by hand. Note this is the *model's*
        // rule, not the enum's: SubmissionStatus::isOpenToAuthor() above is
        // true for Draft and Submitted whatever the date, which is why a check
        // on the enum alone let a token holder withdraw after the deadline.
        if ($actor === null && ! $submission->conference->acceptsSubmissions()) {
            throw SubmissionNotAcceptable::because(
                'The submission window for this conference has closed. Contact the organizers.',
            );
        }

        $submission->forceFill([
            'status' => SubmissionStatus::Withdrawn,
            'withdrawn_at' => now(),
        ])->save();

        $activity = activity()->performedOn($submission);

        if ($actor !== null) {
            $activity = $activity->causedBy($actor);
        }

        $activity
            ->withProperties(['by' => $actor === null ? 'author' : 'organizer'])
            ->log('submission.withdrawn');

        return $submission->refresh();
    }
}
```

`app/Actions/Submissions/SendSubmissionStatusLink.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Actions\Mail\SendTemplatedEmail;
use App\Enums\EmailTemplateKey;
use App\Enums\SubmissionStatus;
use App\Exceptions\SubmissionNotAcceptable;
use App\Models\EmailLog;
use App\Models\Submission;

/**
 * Two jobs, one class: the email an author gets when a draft is first saved,
 * and the "Resend status link" an organizer clicks when the author says the
 * link never arrived.
 *
 * `$plainToken` follows exactly the rule SubmitAbstract::handle() follows: pass
 * the token you already hold and the author's existing link keeps working; pass
 * null and a new one is minted, which kills every link already in circulation.
 * The public form passes the token SaveSubmissionDraft just returned, so the
 * "your draft is saved" email and the page the author is redirected to are the
 * same URL. The organizer's "Resend status link" passes null, because support
 * resends a link precisely when the old one was lost or forwarded and leaving
 * it alive would defeat both reasons.
 */
class SendSubmissionStatusLink
{
    public function __construct(
        private readonly IssueSubmissionToken $issueToken,
        private readonly SendTemplatedEmail $sendTemplatedEmail,
    ) {}

    public function handle(Submission $submission, ?string $plainToken = null): EmailLog
    {
        $author = $submission->correspondingAuthor();

        // Not just null. Mailable::setAddress() silently drops an empty
        // address, and the message then fails at send time inside the queue
        // worker with "An email must have a To, Cc, or Bcc header", leaving an
        // email_logs row stuck at `queued` and nobody told. The form's own
        // rule - the ticked author's address is required - is what stops this
        // arising; this is the guard that makes it impossible.
        if ($author === null || filter_var((string) $author->email, FILTER_VALIDATE_EMAIL) === false) {
            throw SubmissionNotAcceptable::because('This abstract has no corresponding author with a usable email address to send a link to.');
        }

        $token = $plainToken ?? $this->issueToken->handle($submission);

        // The wording has to match what the author will actually see when they
        // follow the link: "your draft is saved" over a submitted abstract
        // would make them submit it again.
        $key = $submission->status === SubmissionStatus::Draft
            ? EmailTemplateKey::SubmissionDraftSaved
            : EmailTemplateKey::SubmissionReceived;

        return $this->sendTemplatedEmail->handle(
            $key,
            $submission->conference,
            (string) $author->email,
            SubmitAbstract::placeholderValues($submission, (string) $author->name, $token),
            $submission,
        );
    }
}
```

- [ ] **Step 7: Run the tests**

```bash
cd /c/Users/ahmed/Documents/CASS && \
php artisan test tests/Unit/SaveSubmissionDraftTest.php tests/Unit/SubmitAbstractTest.php tests/Feature/Mail > /tmp/t.log 2>&1; echo "rc=$?"; tail -4 /tmp/t.log
```

Expected: `rc=0`. Four cases in this run are new in this task and are the ones to check by name if the count looks wrong: `writes the real hash with the insert, never a shared placeholder`, `selects exactly the members who opted in`, `refuses an author withdrawal after the deadline but allows the organizer one`, and the corrected `word_count` of 4 in `creates a draft with a token, a word count and its authors`.

```bash
cd /c/Users/ahmed/Documents/CASS && \
./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?"; \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log
```

Expected: `stan rc=0`, `tests rc=0`, `340 passed`.

```bash
cd /c/Users/ahmed/Documents/CASS && git add -A && git commit -q -m "feat(submissions): save, submit, update and withdraw actions

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

---

### Task 6: The public submission form — route, structure, word count, authors and custom fields

Spec 5.3 step 2. This task builds the form and everything about *what* an author types. Task 7 adds the parts about *who* is typing: files, the honeypot, the throttle, Turnstile and the CTA that links here.

**Files:**
- Create: `app/Livewire/Public/SubmissionForm.php`
- Create: `resources/views/livewire/public/submission-form.blade.php`, `resources/views/livewire/public/partials/authors.blade.php`, `resources/views/livewire/public/partials/custom-fields.blade.php`
- Create: `lang/en/submission.php` (Step 7)
- Create: `resources/js/word-count.js`
- Modify: `routes/web.php`, `resources/js/app.js`
- Test: `tests/Feature/Public/SubmissionFormTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Public/SubmissionFormTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\CustomFieldType;
use App\Enums\OrganizationRole;
use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use App\Livewire\Public\SubmissionForm;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Mail::fake();
    Notification::fake();

    $this->organization = Organization::factory()->approved()->create([
        'name' => 'Gulf Pediatric Society',
        'primary_color' => '#0F4C8A',
        'accent_color' => '#B45309',
    ]);
    $this->conference = Conference::factory()->for($this->organization)->published()->create([
        'name' => 'Gulf Pediatric Critical Care 2026',
        'reference_prefix' => 'GPCC26',
        'word_limit' => 250,
        'presentation_types' => ['oral', 'poster'],
        'terms' => 'Presenting authors must register for the conference.',
    ]);
});

function submitUrl(Conference $conference): string
{
    return "/c/{$conference->organization->slug}/{$conference->slug}/submit";
}

/** The full form state a valid submission needs. */
function fillForm(\Livewire\Features\SupportTesting\Testable $component): \Livewire\Features\SupportTesting\Testable
{
    return $component
        ->set('title', 'Early mobilisation after cardiac surgery')
        ->set('abstract', 'Background. Methods. Results. Conclusion.')
        ->set('presentation_preference', PresentationPreference::Oral->value)
        ->set('contact_phone', '+966500000000')
        ->set('authors.0.name', 'Dr Sara Al-Harbi')
        ->set('authors.0.email', 'sara@example.org')
        ->set('authors.0.affiliation', 'King Fahad Specialist Hospital')
        ->set('authors.0.is_presenter', true)
        ->set('agreed', true);
}

it('renders the branded form with the conference rules on it', function () {
    Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);

    get(submitUrl($this->conference))
        ->assertOk()
        ->assertSee('Gulf Pediatric Critical Care 2026')
        ->assertSee('Gulf Pediatric Society')
        ->assertSee('Neurocritical care')
        ->assertSee('250')
        ->assertSee('Presenting authors must register for the conference.')
        ->assertSee('--org-primary:#0F4C8A', escape: false)
        ->assertSeeLivewire(SubmissionForm::class);
});

it('offers only the presentation types the conference chose', function () {
    get(submitUrl($this->conference))
        ->assertOk()
        ->assertSee(PresentationPreference::Oral->getLabel())
        ->assertSee(PresentationPreference::Poster->getLabel())
        ->assertDontSee(PresentationPreference::Either->getLabel());
});

it('hides the track field entirely when the conference has no tracks', function () {
    get(submitUrl($this->conference))->assertOk()->assertDontSee('Track');

    Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);

    get(submitUrl($this->conference))->assertOk()->assertSee('Track');
});

it('starts with one author row, prefilled as corresponding', function () {
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->assertCount('authors', 1)
        ->assertSet('authors.0.is_corresponding', true);
});

it('adds and removes author rows and never removes the last one', function () {
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->call('addAuthor')
        ->call('addAuthor')
        ->assertCount('authors', 3)
        ->call('removeAuthor', 1)
        ->assertCount('authors', 2)
        ->call('removeAuthor', 0)
        ->assertCount('authors', 1)
        ->call('removeAuthor', 0)
        ->assertCount('authors', 1);
});

it('moves the corresponding flag rather than allowing two', function () {
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->call('addAuthor')
        ->call('makeCorresponding', 1)
        ->assertSet('authors.0.is_corresponding', false)
        ->assertSet('authors.1.is_corresponding', true);
});

it('keeps a corresponding author after the corresponding row is removed', function () {
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->call('addAuthor')
        ->call('makeCorresponding', 1)
        ->call('removeAuthor', 1)
        ->assertSet('authors.0.is_corresponding', true);
});

it('saves a draft, emails the author and redirects to the status page', function () {
    $component = fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))->call('saveDraft')->assertHasNoErrors()->assertRedirectContains('/s/');

    $submission = Submission::query()->firstOrFail();

    expect($submission->status)->toBe(SubmissionStatus::Draft)
        ->and($submission->reference)->toBeNull();

    $token = null;

    Mail::assertQueued(TemplatedMail::class, function (TemplatedMail $mail) use (&$token): bool {
        preg_match('#/s/([A-Za-z0-9]{64})#', $mail->body, $matches);
        $token = $matches[1] ?? null;

        return $mail->hasTo('sara@example.org') && $mail->templateKey === 'submission_draft_saved';
    });

    // The link in the email and the page the author lands on are the same URL,
    // so a bookmark from the email works and the draft is not orphaned behind a
    // token nobody holds.
    expect($token)->not->toBeNull()
        ->and(Submission::findByPlainToken((string) $token)?->is($submission))->toBeTrue();

    $component->assertRedirectContains((string) $token);
});

it('needs only a title and a corresponding address to save a draft', function () {
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->call('saveDraft')
        ->assertHasErrors(['title', 'authors.0.email']);

    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->set('title', 'A work in progress')
        ->set('authors.0.email', 'sara@example.org')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(Submission::query()->count())->toBe(1);
});

it('submits, assigns a reference and redirects with a success flash', function () {
    // The notice goes to members whose organization_members.notify_on_submission
    // is true (the column's default). OrganizationFactory creates none, and
    // NotificationFake::assertSentTo() throws Exception('No notifiable given.')
    // on an empty collection - so without this the assertion below would error
    // rather than prove anything.
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Owner);

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))->call('submit')->assertHasNoErrors()->assertRedirectContains('/s/');

    $submission = Submission::query()->firstOrFail();

    expect($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($submission->reference)->toBe('GPCC26-001')
        ->and(session('status'))->toContain('GPCC26-001');

    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->templateKey === 'submission_received');
    Notification::assertSentTo($member, App\Notifications\NewSubmissionNotice::class);
});

it('requires an address on the row that is actually ticked as corresponding', function () {
    // Row zero has an address; the ticked row does not. SaveSubmissionDraft
    // promotes the first *ticked* row, so validating authors.0.email only would
    // save an abstract whose corresponding address is the empty string - and
    // nobody could ever be told it exists.
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->set('title', 'A work in progress')
        ->set('authors.0.email', 'sara@example.org')
        ->call('addAuthor')
        ->set('authors.1.name', 'Dr Omar Khan')
        ->call('makeCorresponding', 1)
        ->call('saveDraft')
        ->assertHasErrors(['authors.1.email']);

    expect(Submission::query()->count())->toBe(0);
});

it('renders the submission form with a bounded number of queries', function () {
    // Spec section 10 gives this page the same 300 ms server budget as the
    // conference page, and it is the only page on that budget that is Livewire
    // rather than plain Blade. Bound the query count so an N+1 over tracks or
    // custom fields fails here rather than on the host.
    Track::factory()->count(5)->for($this->conference)->create();
    CustomField::factory()->count(5)->for($this->conference)->create();
    $url = submitUrl($this->conference);

    DB::enableQueryLog();
    get($url)->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // organization + conference bindings, tracks, custom fields, with headroom.
    // customFields() and tracks() are memoised per request for this reason.
    expect($queries)->toBeLessThanOrEqual(6);
});

it('reports the word limit as a field error rather than a page crash', function () {
    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('abstract', implode(' ', array_fill(0, 251, 'word')))
        ->call('submit')
        ->assertHasErrors(['abstract']);

    expect(Submission::query()->count())->toBe(0);
});

it('refuses a presentation preference the conference does not offer', function () {
    // The client-side rules reject it: presentationOptions() is built from the
    // conference's own list, so `either` is not a value this form will accept.
    // (The mapping from an action sentence back to a field - reportBlockers() -
    // is proved in tests/Feature/Public/SubmissionStatusPageTest.php, with the
    // one refusal no form rule can make.)
    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('presentation_preference', PresentationPreference::Either->value)
        ->call('submit')
        ->assertHasErrors(['presentation_preference']);
});

it('requires the agreement', function () {
    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('agreed', false)
        ->call('submit')
        ->assertHasErrors(['agreed']);
});

it('renders every custom field type and stores the answers under their keys', function () {
    CustomField::factory()->for($this->conference)->create(['label' => 'Ethics approval number', 'type' => CustomFieldType::Text, 'required' => true]);
    CustomField::factory()->for($this->conference)->create(['label' => 'Study design', 'type' => CustomFieldType::Select, 'options' => ['Randomised', 'Observational'], 'required' => true]);
    CustomField::factory()->for($this->conference)->create(['label' => 'Number of centres', 'type' => CustomFieldType::Number, 'required' => false]);
    CustomField::factory()->for($this->conference)->create(['label' => 'Previously presented', 'type' => CustomFieldType::Checkbox, 'required' => false]);
    CustomField::factory()->for($this->conference)->create(['label' => 'Funding statement', 'type' => CustomFieldType::Textarea, 'required' => false]);

    get(submitUrl($this->conference))
        ->assertOk()
        ->assertSee('Ethics approval number')
        ->assertSee('Study design')
        ->assertSee('Randomised')
        ->assertSee('Number of centres')
        ->assertSee('Previously presented')
        ->assertSee('Funding statement');

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('custom.ethics_approval_number', 'IRB-2026-14')
        ->set('custom.study_design', 'Randomised')
        ->set('custom.number_of_centres', '3')
        ->set('custom.previously_presented', true)
        ->call('submit')
        ->assertHasNoErrors();

    expect(Submission::query()->firstOrFail()->custom_field_values)->toBe([
        'ethics_approval_number' => 'IRB-2026-14',
        'study_design' => 'Randomised',
        'number_of_centres' => '3',
        'previously_presented' => true,
    ]);
});

it('refuses to submit without a required custom field', function () {
    CustomField::factory()->for($this->conference)->create(['label' => 'Ethics approval number', 'type' => CustomFieldType::Text, 'required' => true]);

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->call('submit')
        ->assertHasErrors(['custom.ethics_approval_number']);
});

it('offers only this conference tracks and refuses another conference track', function () {
    $mine = Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);
    $theirs = Track::factory()->for(Conference::factory()->published())->create(['name' => 'Somebody else']);

    get(submitUrl($this->conference))->assertOk()->assertSee('Neurocritical care')->assertDontSee('Somebody else');

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('track_id', $theirs->id)
        ->call('submit')
        ->assertHasErrors(['track_id']);

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('track_id', $mine->id)
        ->call('submit')
        ->assertHasNoErrors();
});

// --- Who may reach this page at all -------------------------------------
// Each negative case is paired with a request that must succeed on the same
// URL, so none of them can pass just because the route does not exist yet.

it('404s a draft conference for the public and previews it for a member', function () {
    $conference = Conference::factory()->for($this->organization)->create();

    get(submitUrl($conference))->assertNotFound();

    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);

    actingAs($member)->get(submitUrl($conference))
        ->assertOk()
        ->assertSee('not visible to the public');

    // A preview is read-only. Saving from it would create a real abstract, burn
    // a reference number off conferences.submission_counter and queue branded
    // email for a conference the public cannot see - and /s/{token} would 404,
    // so the author would never find out.
    fillForm(livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $conference]))
        ->call('saveDraft')
        ->assertHasErrors();

    expect(Submission::query()->count())->toBe(0);
});

it('404s an archived conference and one of a suspended organization', function () {
    get(submitUrl($this->conference))->assertOk();

    $this->conference->forceFill(['status' => ConferenceStatus::Archived])->save();
    get(submitUrl($this->conference))->assertNotFound();

    $this->conference->forceFill(['status' => ConferenceStatus::Open])->save();
    $this->organization->forceFill(['status' => App\Enums\OrganizationStatus::Suspended])->save();
    get(submitUrl($this->conference))->assertNotFound();
});

it('shows a closed state instead of the form once the deadline has passed', function () {
    Carbon::setTestNow($this->conference->submission_deadline->copy()->addMinute());

    get(submitUrl($this->conference))
        ->assertOk()
        ->assertSee('Submissions are closed')
        ->assertDontSee('Save draft');

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))->call('submit')->assertHasErrors();

    expect(Submission::query()->count())->toBe(0);

    Carbon::setTestNow();
});

it('shows an upcoming state before the window opens', function () {
    Carbon::setTestNow($this->conference->submission_opens_at->copy()->subDay());

    get(submitUrl($this->conference))
        ->assertOk()
        ->assertSee('Submissions open on')
        ->assertDontSee('Save draft');

    Carbon::setTestNow();
});

it('does not leak a conference through another organization slug', function () {
    $other = Organization::factory()->approved()->create();

    get(submitUrl($this->conference))->assertOk();
    get("/c/{$other->slug}/{$this->conference->slug}/submit")->assertNotFound();
});
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Public/SubmissionFormTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found|Unable to find" /tmp/t.log | head -3
```

Expected: `rc=1`, `Class "App\Livewire\Public\SubmissionForm" not found`.

- [ ] **Step 3: Register the route**

In `routes/web.php`, add `use App\Livewire\Public\SubmissionForm;` and put this immediately after `conference.show` so the two bindings sit together:

```php
// The same explicit {conference:slug} binding and the same ->scopeBindings()
// as conference.show above, for the same reason: the model's route key is the
// ULID, and a slug that is only unique per organization is only safe when it is
// resolved through that organization's relation.
//
// No AuthenticateSession here either. The page is public; the only signed-in
// visitor it cares about is a member previewing an unpublished conference, and
// SubmissionForm::mount() checks membership and email verification itself.
Route::get('/c/{organization}/{conference:slug}/submit', SubmissionForm::class)
    ->scopeBindings()
    ->name('conference.submit');
```

- [ ] **Step 4: Write `app/Livewire/Public/SubmissionForm.php`**

This is the whole component *except* the parts Task 7 adds (files, the honeypot, the throttle and Turnstile); every place Task 7 touches carries a `// Task 7 …` marker.

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Actions\Submissions\SaveSubmissionDraft;
use App\Actions\Submissions\SendSubmissionStatusLink;
use App\Actions\Submissions\SubmitAbstract;
use App\Actions\Submissions\UpdateSubmission;
use App\Enums\CustomFieldType;
use App\Enums\PresentationPreference;
use App\Enums\SubmissionWindow;
use App\Exceptions\SubmissionNotAcceptable;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Support\Branding\OrganizationTheme;
use App\Support\Text\WordCounter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Spec 5.3 step 2: one form, used twice. It is the page at
 * /c/{org}/{conference}/submit for a new abstract, and it is mounted again by
 * the status page with an existing submission for "Edit". The only difference
 * between the two is which action the buttons call, which is why it is one
 * component and not two.
 *
 * The layout is chosen in render() with the ->layout() view macro rather than
 * with the #[Layout] attribute, because components/layouts/conference.blade.php
 * takes three props (organization, conference, theme) and an attribute's
 * parameters have to be compile-time constants. The macro sets
 * PageComponentConfig::$type to 'component', so the layout is rendered as a
 * Blade component and its @props receive these values as attributes - exactly
 * as they do from <x-layouts.conference> on the public conference page.
 */
class SubmissionForm extends Component
{
    /** Route-bound, and never re-resolved from the request after mount. */
    #[Locked]
    public Organization $organization;

    #[Locked]
    public Conference $conference;

    /**
     * Set only in edit mode. Locked so a crafted Livewire payload cannot point
     * the form at somebody else's abstract between requests - without this, the
     * token check in mount() would be a check on a value the client can change.
     */
    #[Locked]
    public ?Submission $submission = null;

    /**
     * The author's plaintext access token, when the status page handed one over.
     * It is in the component snapshot, which is signed but not encrypted - and
     * that is acceptable here and nowhere else, because the author reached this
     * component through a URL that already contains the same token in the
     * address bar. It is never rendered into the page and never logged.
     */
    #[Locked]
    public ?string $token = null;

    #[Locked]
    public bool $isPreview = false;

    public string $title = '';

    public string $abstract = '';

    public ?int $track_id = null;

    public string $presentation_preference = '';

    public string $contact_phone = '';

    /** @var list<array<string, mixed>> */
    public array $authors = [];

    /** @var array<string, mixed> */
    public array $custom = [];

    public bool $agreed = false;

    // Task 7 adds: public array $uploads = [], public string $website_confirm = '',
    // public int $openedAt = 0, public string $turnstileToken = '',
    // public bool $humanVerified = false.

    public function mount(Organization $organization, Conference $conference, ?Submission $submission = null, ?string $token = null): void
    {
        $isPublic = $organization->isApproved() && $conference->isPubliclyVisible();

        abort_if(! $isPublic && ! $this->canPreview($organization), 404);

        $this->organization = $organization;
        $this->conference = $conference;
        $this->isPreview = ! $isPublic;
        $this->submission = $submission;
        $this->token = $token;

        if ($submission !== null) {
            $this->fillFromSubmission($submission);

            return;
        }

        $this->authors = [$this->blankAuthor(isCorresponding: true)];
        $this->presentation_preference = $this->presentationOptions()->keys()->first() ?? '';
    }

    // --- Author rows ----------------------------------------------------

    public function addAuthor(): void
    {
        $this->authors[] = $this->blankAuthor(isCorresponding: false);
    }

    public function removeAuthor(int $index): void
    {
        // There is always at least one row. An author with no rows cannot be
        // told anything, and the form would have no email field at all.
        if (count($this->authors) <= 1 || ! isset($this->authors[$index])) {
            return;
        }

        $wasCorresponding = (bool) ($this->authors[$index]['is_corresponding'] ?? false);

        unset($this->authors[$index]);
        $this->authors = array_values($this->authors);

        if ($wasCorresponding) {
            $this->makeCorresponding(0);
        }
    }

    /** Exactly one at a time - the radio behaviour a checkbox column cannot give. */
    public function makeCorresponding(int $index): void
    {
        foreach (array_keys($this->authors) as $i) {
            $this->authors[$i]['is_corresponding'] = $i === $index;
        }
    }

    // --- The two buttons ------------------------------------------------

    /**
     * Spec 5.3 step 3: a draft needs a title and somewhere to send the link.
     * Everything else can wait, because the whole point of a draft is that the
     * author is not finished.
     */
    public function saveDraft(SaveSubmissionDraft $save, UpdateSubmission $update, SendSubmissionStatusLink $sendLink): mixed
    {
        // Task 7 inserts the honeypot, the minimum-fill-time check, the
        // per-IP throttle and the Turnstile verification into this guard.
        if (! $this->isWritable() || ! $this->windowIsOpen()) {
            return null;
        }

        // The corresponding author is whichever row is ticked, not row zero -
        // SaveSubmissionDraft::syncAuthors() promotes the first ticked row, and
        // an empty address there is an abstract nobody can ever be told about.
        $ticked = collect($this->authors)
            ->search(fn (array $author): bool => (bool) ($author['is_corresponding'] ?? false));
        $index = $ticked === false ? 0 : (int) $ticked;

        $this->validate([
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'authors.*.email' => ['nullable', 'email:rfc'],
            "authors.{$index}.email" => ['required', 'email:rfc'],
        ], [], $this->validationAttributes());

        if ($this->submission !== null) {
            $update->handle($this->submission, $this->payload());

            // Task 7 stores pending uploads here, on this branch too - the
            // status-page edit form has the same files section, and returning
            // before it would drop an attachment behind a success flash.
            session()->flash('status', __('submission.flash.draft_updated'));

            return $this->redirect(route('submission.status', ['token' => $this->token]), navigate: false);
        }

        $link = $save->handle($this->conference, $this->payload());

        // Adopt the row immediately. Task 7 inserts an upload gate below this
        // line that can still refuse, and a retry must edit this draft rather
        // than mint a second abstract with a second token. Both properties are
        // #[Locked], so the server may write them and the client may not.
        $this->submission = $link->submission;
        $this->token = $link->token;

        // The same token the redirect uses, so the emailed link and the page
        // the author lands on are one URL.
        try {
            $sendLink->handle($link->submission, $link->token);
        } catch (SubmissionNotAcceptable) {
            // The draft is saved and the redirect below carries the same token,
            // so the author still lands on their abstract; only the email is
            // lost. The corresponding-author rule above is what stops this
            // happening at all - this catch exists because saveDraft() is a
            // public, unauthenticated entry point and an uncaught throw here
            // would be a 500 on top of a row that was written successfully.
        }

        session()->flash('status', __('submission.flash.draft_saved'));

        return $this->redirect($link->url() ?? route('conference.show', [$this->organization, $this->conference]), navigate: false);
    }

    public function submit(SaveSubmissionDraft $save, UpdateSubmission $update, SubmitAbstract $submitAbstract): mixed
    {
        // Task 7 inserts the honeypot, the minimum-fill-time check, the
        // per-IP throttle and the Turnstile verification into this guard.
        if (! $this->isWritable() || ! $this->windowIsOpen()) {
            return null;
        }

        $this->validate($this->submitRules(), [], $this->validationAttributes());

        if ($this->submission !== null) {
            $update->handle($this->submission, $this->payload());
            $submission = $this->submission->refresh();
            $token = $this->token;
        } else {
            $link = $save->handle($this->conference, $this->payload());
            $submission = $link->submission;
            $token = $link->token;

            // Adopt the draft immediately. Everything below this line can still
            // refuse - SubmitAbstract's own blockers, and the upload gate Task 7
            // inserts - and a retry must edit this row rather than create a
            // second abstract with a second token and a second copy of every
            // file. Both properties are #[Locked]: the server writes them, the
            // client cannot.
            $this->submission = $submission;
            $this->token = $token;
        }

        try {
            // The action re-checks everything against the stored row. Anything
            // it still refuses becomes a field error rather than an exception
            // page, because the author can usually fix it.
            $submission = $submitAbstract->handle($submission, $this->agreed, $token);
        } catch (SubmissionNotAcceptable $exception) {
            $this->reportBlockers($exception->reasons);

            return null;
        }

        session()->flash('status', __('submission.flash.submitted', ['reference' => $submission->reference]));

        return $this->redirect(
            $token === null
                ? route('conference.show', [$this->organization, $this->conference])
                : route('submission.status', ['token' => $token]),
            navigate: false,
        );
    }

    // --- Rendering ------------------------------------------------------

    public function render(): mixed
    {
        $theme = OrganizationTheme::for($this->organization);

        return view('livewire.public.submission-form', [
            'theme' => $theme,
            'window' => $this->conference->submissionWindow(),
            'tracks' => $this->tracks(),
            'customFields' => $this->customFields(),
            'presentationOptions' => $this->presentationOptions(),
            'wordCount' => WordCounter::count($this->abstract),
        ])->layout('components.layouts.conference', [
            'organization' => $this->organization,
            'conference' => $this->conference,
            'theme' => $theme,
        ]);
    }

    // --- Internals ------------------------------------------------------

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'title' => $this->title,
            'abstract' => $this->abstract,
            'track_id' => $this->track_id,
            'presentation_preference' => $this->presentation_preference === '' ? null : $this->presentation_preference,
            'contact_phone' => $this->contact_phone,
            'custom_field_values' => $this->custom,
            'authors' => $this->authors,
        ];
    }

    /** @return array<string, mixed> */
    private function submitRules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'abstract' => [
                'required', 'string',
                // The server-side word limit, expressed where the author sees
                // it. SubmitAbstract checks it again against the stored row.
                function (string $attribute, mixed $value, callable $fail): void {
                    $words = WordCounter::count((string) $value);
                    $limit = (int) $this->conference->word_limit;

                    if ($words > $limit) {
                        $fail(__('submission.errors.word_limit', ['words' => $words, 'limit' => $limit]));
                    }
                },
            ],
            'track_id' => ['nullable', Rule::in($this->tracks()->pluck('id')->all())],
            'presentation_preference' => ['required', Rule::in($this->presentationOptions()->keys()->all())],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'authors' => ['array', 'min:1'],
            'authors.*.name' => ['required', 'string', 'max:180'],
            'authors.*.email' => ['required', 'email:rfc', 'max:255'],
            'authors.*.affiliation' => ['nullable', 'string', 'max:255'],
            'agreed' => ['accepted'],
        ];

        foreach ($this->customFields() as $field) {
            $rules['custom.'.$field->key] = $this->customFieldRules($field);
        }

        return $rules;
    }

    /** @return list<mixed> */
    private function customFieldRules(CustomField $field): array
    {
        $rules = [$field->required ? 'required' : 'nullable'];

        return [...$rules, ...match ($field->type) {
            CustomFieldType::Number => ['numeric'],
            CustomFieldType::Select => [Rule::in(array_values((array) ($field->options ?? [])))],
            // `accepted` rather than `boolean` for a required checkbox: a
            // required yes/no question means yes.
            CustomFieldType::Checkbox => $field->required ? ['accepted'] : ['boolean'],
            CustomFieldType::Text => ['string', 'max:255'],
            CustomFieldType::Textarea => ['string', 'max:5000'],
        }];
    }

    /** @return array<string, string> */
    private function validationAttributes(): array
    {
        $attributes = [];

        foreach ($this->customFields() as $field) {
            $attributes['custom.'.$field->key] = (string) $field->label;
        }

        foreach (array_keys($this->authors) as $index) {
            $attributes["authors.{$index}.name"] = __('submission.authors.name_of', ['position' => $index + 1]);
            $attributes["authors.{$index}.email"] = __('submission.authors.email_of', ['position' => $index + 1]);
        }

        return $attributes;
    }

    /**
     * Turns the action's sentences back into field errors where the field is
     * obvious, and into one form-level error where it is not. Without this a
     * server-only refusal (a presentation type removed from the conference
     * while the author was typing) would look like a button that does nothing.
     *
     * @param  list<string>  $reasons
     */
    private function reportBlockers(array $reasons): void
    {
        foreach ($reasons as $reason) {
            $field = match (true) {
                str_contains($reason, 'title') => 'title',
                str_contains($reason, 'words') || str_contains($reason, 'abstract') => 'abstract',
                str_contains($reason, 'track') => 'track_id',
                str_contains($reason, 'presentation') => 'presentation_preference',
                str_contains($reason, 'author') => 'authors.0.email',
                str_contains($reason, 'terms') => 'agreed',
                default => 'title',
            };

            $this->addError($field, $reason);
        }
    }

    /**
     * False when the window has closed, with the error already on the page and
     * the author's text still in the textarea - which is the whole point.
     *
     * A boolean rather than an abort() or a throw, and the same shape as the
     * passesBotChecks() Task 7 adds, because Livewire rethrows anything that is
     * not a ValidationException (vendor/livewire/livewire/src/Wrapped.php): a
     * SubmissionNotAcceptable out of a Livewire action is a 500 page on a
     * public, unauthenticated endpoint - it would throw away exactly the typing
     * the message is trying to save, and a component test would error instead
     * of asserting the field error.
     */
    private function windowIsOpen(): bool
    {
        if ($this->conference->acceptsSubmissions()) {
            return true;
        }

        $this->addError('title', __('submission.errors.window_closed'));

        return false;
    }

    /**
     * A preview shows the form; it never writes. The badge already tells the
     * member the page is not public - saving from it would create abstracts,
     * burn reference numbers off conferences.submission_counter and queue
     * branded email for a conference, or an organization, the platform has
     * taken offline, while /s/{token} 404s so the author never sees any of it.
     */
    private function isWritable(): bool
    {
        if (! $this->isPreview) {
            return true;
        }

        $this->addError('title', __('submission.errors.preview_readonly'));

        return false;
    }

    /** @return \Illuminate\Support\Collection<string, string> */
    private function presentationOptions(): \Illuminate\Support\Collection
    {
        /** @var list<string> $offered */
        $offered = $this->conference->presentation_types ?? [];

        return collect(PresentationPreference::cases())
            ->filter(fn (PresentationPreference $case): bool => in_array($case->value, $offered, true))
            ->mapWithKeys(fn (PresentationPreference $case): array => [$case->value => $case->getLabel()]);
    }

    /** @var \Illuminate\Database\Eloquent\Collection<int, CustomField>|null */
    private ?\Illuminate\Database\Eloquent\Collection $customFieldCache = null;

    /** @var \Illuminate\Database\Eloquent\Collection<int, Track>|null */
    private ?\Illuminate\Database\Eloquent\Collection $trackCache = null;

    /**
     * Livewire builds a fresh component for every request, so these two are
     * per-request memos and cannot go stale between requests. They exist
     * because spec section 10 gives this page the same 300 ms budget as the
     * conference page, and one render plus one validate asked for the same two
     * lists five times.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CustomField>
     */
    private function customFields(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->customFieldCache ??= $this->conference->customFields()->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Track> */
    private function tracks(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->trackCache ??= $this->conference->tracks()->get();
    }

    /** @return array<string, mixed> */
    private function blankAuthor(bool $isCorresponding): array
    {
        return [
            'name' => '',
            'email' => '',
            'affiliation' => '',
            'is_presenter' => $isCorresponding,
            'is_corresponding' => $isCorresponding,
        ];
    }

    private function fillFromSubmission(Submission $submission): void
    {
        $this->title = (string) $submission->title;
        $this->abstract = (string) $submission->abstract;
        $this->track_id = $submission->track_id === null ? null : (int) $submission->track_id;
        $this->presentation_preference = $submission->presentation_preference?->value ?? '';
        $this->contact_phone = (string) $submission->contact_phone;
        $this->custom = (array) ($submission->custom_field_values ?? []);
        // Editing an abstract that was already submitted means the terms were
        // already accepted; asking again on every edit is friction with no
        // added consent.
        $this->agreed = $submission->submitted_at !== null;

        $this->authors = $submission->authors->map(fn ($author): array => [
            'name' => (string) $author->name,
            'email' => (string) $author->email,
            'affiliation' => (string) $author->affiliation,
            'is_presenter' => (bool) $author->is_presenter,
            'is_corresponding' => (bool) $author->is_corresponding,
        ])->values()->all();

        if ($this->authors === []) {
            $this->authors = [$this->blankAuthor(isCorresponding: true)];
        }
    }

    /** The same rule the public conference page applies (Plan 2, Task 4). */
    private function canPreview(Organization $organization): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasVerifiedEmail()
            && $user->roleIn($organization) !== null;
    }
}
```

`SubmissionWindow` is imported because the view switches on it; `windowIsOpen()` adding the error and returning false — and the caller returning early rather than throwing — is what turns "the deadline passed while I was typing" into a red message on a page the author can copy their text out of. It is a boolean and not a throw for the same reason `isWritable()` is, and the same reason the `passesBotChecks()` Task 7 adds is: Livewire rethrows every exception that is not a `ValidationException` (`vendor/livewire/livewire/src/Wrapped.php`; only `SupportValidation` stops propagation), so a `SubmissionNotAcceptable` out of a Livewire action would be a 500 on a public, unauthenticated endpoint — and a component test asserting `assertHasErrors()` would error instead of asserting.

`use App\Exceptions\SubmissionNotAcceptable;` stays in the import list: `submit()` still catches it around `SubmitAbstract::handle()`, and `saveDraft()` catches it around `SendSubmissionStatusLink::handle()`.

- [ ] **Step 5: Write the Blade views**

`resources/views/livewire/public/submission-form.blade.php`
```blade
@php use App\Enums\SubmissionWindow; @endphp

<div class="mx-auto max-w-3xl px-4 py-10">
    @if ($isPreview)
        <div class="mb-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <span class="font-semibold">{{ __('submission.preview.badge') }}</span>
            {{ __('submission.preview.body', ['organization' => $organization->name]) }}
        </div>
    @endif

    <p class="text-sm font-semibold uppercase tracking-wide text-[var(--org-accent)]">{{ __('submission.page.eyebrow') }}</p>
    <h1 class="mt-2 text-3xl font-semibold tracking-tight">{{ $conference->name }}</h1>
    <p class="mt-2 text-slate-600">{{ $organization->name }}</p>

    @if ($window !== SubmissionWindow::Open)
        <div class="mt-8 rounded-lg border border-slate-300 bg-white p-6">
            <p class="text-lg font-semibold text-slate-700">
                @switch($window)
                    @case(SubmissionWindow::Upcoming)
                        {{ __('submission.window.upcoming', [
                            'date' => $conference->opensAtInConferenceTimezone()?->format('j F Y, H:i'),
                            'timezone' => $conference->timezone,
                        ]) }}
                        @break
                    @case(SubmissionWindow::Closed)
                        {{ __('submission.window.closed') }}
                        @break
                    @default
                        {{ __('submission.window.not_configured') }}
                @endswitch
            </p>
            <a href="{{ route('conference.show', [$organization, $conference]) }}"
               class="mt-4 inline-block text-sm font-medium text-[var(--org-primary)] hover:underline">
                {{ __('submission.window.back') }}
            </a>
        </div>
    @else
        <form wire:submit="submit" class="mt-8 space-y-8" novalidate>
            {{-- Task 7 inserts the honeypot, the fill-time field and the Turnstile widget here. --}}

            <section class="rounded-lg border border-slate-200 bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold">{{ __('submission.sections.abstract') }}</h2>

                <div>
                    <label for="title" class="block text-sm font-medium">{{ __('submission.fields.title') }}</label>
                    <input id="title" type="text" wire:model.blur="title" maxlength="255"
                           class="mt-1 w-full rounded-lg border-slate-300" required>
                    @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                {{--
                    The live counter is Alpine (Livewire 4 ships and starts it),
                    calling the same function the server uses - resources/js/word-count.js
                    exports window.cassCountWords and App\Support\Text\WordCounter
                    applies the identical two rules. The initial value comes from
                    the server so the number is right before a single key is
                    pressed, and wire:model.blur means typing never round-trips.
                --}}
                <div x-data="{ words: {{ $wordCount }}, limit: {{ (int) $conference->word_limit }} }">
                    <div class="flex items-baseline justify-between">
                        <label for="abstract" class="block text-sm font-medium">{{ __('submission.fields.abstract') }}</label>
                        <span class="text-sm" :class="words > limit ? 'font-semibold text-red-600' : 'text-slate-500'">
                            <span x-text="words"></span> / <span x-text="limit"></span> {{ __('submission.fields.words') }}
                        </span>
                    </div>
                    <textarea id="abstract" rows="14" wire:model.blur="abstract"
                              x-on:input="words = window.cassCountWords($event.target.value)"
                              class="mt-1 w-full rounded-lg border-slate-300 font-sans" required></textarea>
                    @error('abstract') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                @if ($tracks->isNotEmpty())
                    <div>
                        <label for="track" class="block text-sm font-medium">{{ __('submission.fields.track') }}</label>
                        <select id="track" wire:model="track_id" class="mt-1 w-full rounded-lg border-slate-300">
                            <option value="">{{ __('submission.fields.track_none') }}</option>
                            @foreach ($tracks as $track)
                                <option value="{{ $track->id }}">{{ $track->name }}</option>
                            @endforeach
                        </select>
                        @error('track_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif

                <fieldset>
                    <legend class="block text-sm font-medium">{{ __('submission.fields.presentation_preference') }}</legend>
                    <div class="mt-2 space-y-2">
                        @foreach ($presentationOptions as $value => $label)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" name="presentation_preference" value="{{ $value }}"
                                       wire:model="presentation_preference" class="border-slate-300">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @error('presentation_preference') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </fieldset>
            </section>

            @include('livewire.public.partials.authors')

            <section class="rounded-lg border border-slate-200 bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold">{{ __('submission.sections.contact') }}</h2>
                <div>
                    <label for="contact_phone" class="block text-sm font-medium">{{ __('submission.fields.contact_phone') }}</label>
                    <input id="contact_phone" type="tel" wire:model.blur="contact_phone" maxlength="40"
                           class="mt-1 w-full rounded-lg border-slate-300">
                    <p class="mt-1 text-sm text-slate-500">{{ __('submission.fields.contact_phone_help') }}</p>
                    @error('contact_phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </section>

            @if ($customFields->isNotEmpty())
                @include('livewire.public.partials.custom-fields')
            @endif

            {{-- Task 7 inserts the files section here. --}}

            <section class="rounded-lg border border-slate-200 bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold">{{ __('submission.sections.agreement') }}</h2>
                @if ($conference->terms)
                    <div class="max-h-48 overflow-y-auto rounded border border-slate-200 bg-slate-50 p-3 text-sm whitespace-pre-line text-slate-700">{{ $conference->terms }}</div>
                @endif
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" wire:model="agreed" class="mt-0.5 rounded border-slate-300">
                    <span>{{ __('submission.fields.agreed') }}</span>
                </label>
                @error('agreed') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </section>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" wire:loading.attr="disabled"
                        class="rounded-lg bg-[var(--org-primary)] px-6 py-3 font-semibold text-[var(--org-on-primary)] hover:opacity-90">
                    {{ __('submission.buttons.submit') }}
                </button>
                <button type="button" wire:click="saveDraft" wire:loading.attr="disabled"
                        class="rounded-lg border border-slate-300 px-6 py-3 font-medium text-slate-700 hover:bg-slate-50">
                    {{ __('submission.buttons.save_draft') }}
                </button>
                <span wire:loading class="text-sm text-slate-500">{{ __('submission.buttons.working') }}</span>
            </div>

            <p class="text-sm text-slate-500">{{ __('submission.buttons.draft_help') }}</p>
        </form>
    @endif
</div>
```

`resources/views/livewire/public/partials/authors.blade.php`
```blade
<section class="rounded-lg border border-slate-200 bg-white p-6">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold">{{ __('submission.sections.authors') }}</h2>
        <button type="button" wire:click="addAuthor"
                class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium hover:bg-slate-50">
            {{ __('submission.authors.add') }}
        </button>
    </div>
    <p class="mt-1 text-sm text-slate-500">{{ __('submission.authors.help') }}</p>

    <ol class="mt-4 space-y-4">
        {{--
            Keyed by index on purpose: removing a row renumbers the array, and a
            key derived from the row's own data would let Livewire's morph reuse
            the wrong input when two authors share an empty email.
        --}}
        @foreach ($authors as $index => $author)
            <li class="rounded-lg border border-slate-200 p-4" wire:key="author-{{ $index }}">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="author-name-{{ $index }}" class="block text-sm font-medium">{{ __('submission.authors.name') }}</label>
                        <input id="author-name-{{ $index }}" type="text" wire:model.blur="authors.{{ $index }}.name"
                               class="mt-1 w-full rounded-lg border-slate-300">
                        @error("authors.{$index}.name") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="author-email-{{ $index }}" class="block text-sm font-medium">{{ __('submission.authors.email') }}</label>
                        <input id="author-email-{{ $index }}" type="email" wire:model.blur="authors.{{ $index }}.email"
                               class="mt-1 w-full rounded-lg border-slate-300">
                        @error("authors.{$index}.email") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="author-affiliation-{{ $index }}" class="block text-sm font-medium">{{ __('submission.authors.affiliation') }}</label>
                        <input id="author-affiliation-{{ $index }}" type="text" wire:model.blur="authors.{{ $index }}.affiliation"
                               class="mt-1 w-full rounded-lg border-slate-300">
                        @error("authors.{$index}.affiliation") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-4 text-sm">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model="authors.{{ $index }}.is_presenter" class="rounded border-slate-300">
                        {{ __('submission.authors.is_presenter') }}
                    </label>
                    <label class="flex items-center gap-2">
                        {{-- A radio, not a checkbox: exactly one corresponding author. --}}
                        <input type="radio" name="corresponding" @checked($author['is_corresponding'])
                               wire:click="makeCorresponding({{ $index }})" class="border-slate-300">
                        {{ __('submission.authors.is_corresponding') }}
                    </label>
                    @if (count($authors) > 1)
                        <button type="button" wire:click="removeAuthor({{ $index }})"
                                class="ml-auto text-red-600 hover:underline">{{ __('submission.authors.remove') }}</button>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
</section>
```

`resources/views/livewire/public/partials/custom-fields.blade.php`
```blade
@php use App\Enums\CustomFieldType; @endphp

<section class="rounded-lg border border-slate-200 bg-white p-6 space-y-4">
    <h2 class="text-lg font-semibold">{{ __('submission.sections.custom_fields') }}</h2>

    @foreach ($customFields as $field)
        @php $id = 'custom-'.$field->key; @endphp
        <div wire:key="custom-{{ $field->key }}">
            @if ($field->type === CustomFieldType::Checkbox)
                <label class="flex items-start gap-2 text-sm">
                    <input id="{{ $id }}" type="checkbox" wire:model="custom.{{ $field->key }}" class="mt-0.5 rounded border-slate-300">
                    <span>{{ $field->label }}@if ($field->required)<span class="text-red-600"> *</span>@endif</span>
                </label>
            @else
                <label for="{{ $id }}" class="block text-sm font-medium">
                    {{ $field->label }}@if ($field->required)<span class="text-red-600"> *</span>@endif
                </label>

                @switch($field->type)
                    @case(CustomFieldType::Textarea)
                        <textarea id="{{ $id }}" rows="4" wire:model.blur="custom.{{ $field->key }}"
                                  class="mt-1 w-full rounded-lg border-slate-300"></textarea>
                        @break
                    @case(CustomFieldType::Select)
                        <select id="{{ $id }}" wire:model="custom.{{ $field->key }}" class="mt-1 w-full rounded-lg border-slate-300">
                            <option value="">{{ __('submission.fields.choose') }}</option>
                            @foreach ((array) ($field->options ?? []) as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                        @break
                    @case(CustomFieldType::Number)
                        <input id="{{ $id }}" type="number" step="any" wire:model.blur="custom.{{ $field->key }}"
                               class="mt-1 w-full rounded-lg border-slate-300">
                        @break
                    @default
                        <input id="{{ $id }}" type="text" maxlength="255" wire:model.blur="custom.{{ $field->key }}"
                               class="mt-1 w-full rounded-lg border-slate-300">
                @endswitch
            @endif

            @if ($field->help_text)
                <p class="mt-1 text-sm text-slate-500">{{ $field->help_text }}</p>
            @endif
            @error('custom.'.$field->key) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
    @endforeach
</section>
```

- [ ] **Step 6: Write `resources/js/word-count.js` and import it**

```js
// The browser half of App\Support\Text\WordCounter. The two must agree, because
// the counter next to the textarea is what an author trusts and the server rule
// is what actually refuses the submission. Same two rules, same order:
//
//   1. split on whitespace *including* the characters a paste from Word brings
//      with it (U+00A0, U+202F, U+3000). JavaScript expresses that as the
//      Unicode property \p{White_Space}, which already covers all of them;
//      PCRE has no such property, which is why the PHP side lists them by hand.
//      Both sides split on the same set - they just spell it differently.
//   2. count a token only if it contains a letter or a digit in any script.
//
// It hangs off `window` rather than registering an Alpine component, because
// Livewire injects and starts Alpine from a classic script before this deferred
// module runs - an `alpine:init` listener here would be registered too late.
// An inline `x-data` expression that calls a global has no such ordering
// problem: nothing calls it until the author types.
const SEPARATORS = /\p{White_Space}+/u;
const HAS_LETTER_OR_DIGIT = /[\p{L}\p{N}]/u;

export function countWords(text) {
    if (typeof text !== 'string') {
        return 0;
    }

    return text
        .trim()
        .split(SEPARATORS)
        .filter((token) => token !== '' && HAS_LETTER_OR_DIGIT.test(token))
        .length;
}

window.cassCountWords = countWords;
```

`resources/js/app.js` becomes:
```js
import './bootstrap';
import './countdown';
import './word-count';
```

- [ ] **Step 7: Write `lang/en/submission.php`**

Spec section 10 requires every user-facing string in a language file, and a view whose labels render as `submission.fields.title` is not a view anyone can test against. So the strings land with the view that uses them, and Task 12 is the *sweep* — a grep for anything Plan 3 left hardcoded, plus the keys Task 8's status page adds.

Task 3 already put `lang/en/mail.php` there, so the directory exists; `mkdir -p` is idempotent and makes the step runnable on its own:

```bash
cd /c/Users/ahmed/Documents/CASS && mkdir -p lang/en
```

`lang/en/submission.php`
```php
<?php

declare(strict_types=1);

/*
 * Every string on the public submission form and (from Task 8) the author
 * status page. Spec section 10: locale `en` only in v1, with all strings here
 * so Arabic is a copy of this file and not a branch in a Blade view.
 */

return [

    'page' => [
        'eyebrow' => 'Submit an abstract',
    ],

    'window' => [
        'upcoming' => 'Submissions open on :date (:timezone)',
        'closed' => 'Submissions are closed',
        'not_configured' => 'Submission dates have not been announced yet',
        'back' => 'Back to the conference page',
    ],

    'preview' => [
        'badge' => 'Preview.',
        'body' => 'This page is not visible to the public. Only members of :organization can see it.',
    ],

    'sections' => [
        'abstract' => 'Your abstract',
        'authors' => 'Authors',
        'contact' => 'Contact',
        'custom_fields' => 'Additional questions',
        'files' => 'Files',
        'agreement' => 'Terms',
    ],

    'fields' => [
        'title' => 'Title',
        'abstract' => 'Abstract',
        'words' => 'words',
        'track' => 'Track',
        'track_none' => 'No particular track',
        'presentation_preference' => 'Presentation preference',
        'contact_phone' => 'Contact phone number',
        'contact_phone_help' => 'Used only if the organizers need to reach you quickly about this abstract.',
        'choose' => 'Choose one…',
        'agreed' => 'I have read and accept the terms above, and I confirm every author listed has agreed to this submission.',
    ],

    'authors' => [
        'add' => 'Add an author',
        'remove' => 'Remove',
        'help' => 'List every author in the order they should appear. Mark exactly one as the corresponding author — that is the address we will write to.',
        'name' => 'Name',
        'email' => 'Email',
        'affiliation' => 'Affiliation',
        'is_presenter' => 'Will present',
        'is_corresponding' => 'Corresponding author',
        'name_of' => 'name of author :position',
        'email_of' => 'email of author :position',
    ],

    'buttons' => [
        'submit' => 'Submit abstract',
        'save_draft' => 'Save draft',
        'working' => 'Working…',
        'draft_help' => 'A draft is not considered until you submit it. We will email you a link so you can come back to it.',
    ],

    'errors' => [
        'word_limit' => 'The abstract is :words words. The limit is :limit words.',
        'window_closed' => 'Submissions for this conference are closed. Copy your text somewhere safe before leaving this page.',
        'preview_readonly' => 'This is a preview of a page that is not public yet, so nothing can be submitted from it. Publish the conference first.',
    ],

    'flash' => [
        'draft_saved' => 'Your draft is saved. We have emailed you a link so you can come back to it.',
        'draft_updated' => 'Your draft is saved.',
        'submitted' => 'Your abstract is submitted. Its reference is :reference.',
    ],

];
```

Task 7 adds the `files.*` and `errors.*` keys its own features need, and Task 8 adds `status.*`.

- [ ] **Step 8: Run the tests**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Public/SubmissionFormTest.php > /tmp/t.log 2>&1; echo "rc=$?"; tail -4 /tmp/t.log
```

Expected: `rc=0`, `23 passed`.

If `hides the track field entirely when the conference has no tracks` fails on the negative half, check that nothing else on the page prints the word "Track" — the assertion is deliberately against the rendered label from `submission.fields.track`, which is the only place it should appear.

If `renders the submission form with a bounded number of queries` fails at 8 or more, `customFields()` or `tracks()` has lost its memo and the page is asking for the same list on every call — which is the thing spec section 10's 300 ms budget cannot afford on the one page of the two that is Livewire.

If `requires an address on the row that is actually ticked as corresponding` passes an empty address, `saveDraft()` is still validating `authors.0.email` instead of the ticked index.

- [ ] **Step 9: Build the assets, Pint, Larastan, full suite, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && npm run build 2>&1 | tail -3 && \
./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?"; \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log
```

Expected: Vite prints `✓ built in …`, `stan rc=0`, `tests rc=0`, `363 passed`.

```bash
cd /c/Users/ahmed/Documents/CASS && git add -A && git commit -q -m "feat(public): the abstract submission form with a live word count, authors and custom fields

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

---

### Task 7: Files, bot protection and the CTA that finally points somewhere

Spec 5.3's last paragraph — "Bot protection: honeypot field, per-IP rate limit, and Cloudflare Turnstile when keys are configured" — plus the file attachments and the one `href` Plan 2 left at `#`.

**Files:**
- Create: `app/Support/Turnstile.php`, `resources/views/livewire/public/partials/files.blade.php`, `config/livewire.php`
- Modify: `app/Livewire/Public/SubmissionForm.php`, `resources/views/livewire/public/submission-form.blade.php`, `lang/en/submission.php`, `config/cass.php`, `.env.example`, `phpunit.xml`
- Modify: `resources/views/public/partials/submit-cta.blade.php` (exactly one `href`), `app/Http/Controllers/Public/ConferenceController.php` (one line)
- Test: `tests/Unit/TurnstileTest.php`, additions to `tests/Feature/Public/SubmissionFormTest.php` and `tests/Feature/Public/ConferencePageTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/TurnstileTest.php`
```php
<?php

declare(strict_types=1);

use App\Support\Turnstile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config()->set('cass.turnstile.site_key', 'site-key');
    config()->set('cass.turnstile.secret_key', 'secret-key');
});

it('is off until both keys are configured', function (?string $site, ?string $secret, bool $expected) {
    config()->set('cass.turnstile.site_key', $site);
    config()->set('cass.turnstile.secret_key', $secret);

    expect(Turnstile::isConfigured())->toBe($expected);
})->with([
    [null, null, false],
    ['site-key', null, false],
    [null, 'secret-key', false],
    ['', '', false],
    ['site-key', 'secret-key', true],
]);

it('waves everything through when it is not configured', function () {
    Http::fake();
    config()->set('cass.turnstile.site_key', null);
    config()->set('cass.turnstile.secret_key', null);

    expect(Turnstile::verify(null, '203.0.113.7'))->toBeTrue();

    Http::assertNothingSent();
});

it('posts the secret, the response and the client address and accepts a success', function () {
    Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => true], 200)]);

    expect(Turnstile::verify('a-token', '203.0.113.7'))->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->url() === Turnstile::VERIFY_URL
        && $request['secret'] === 'secret-key'
        && $request['response'] === 'a-token'
        && $request['remoteip'] === '203.0.113.7'
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
});

it('refuses an explicit failure and an empty token', function () {
    Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']], 200)]);

    expect(Turnstile::verify('a-token', '203.0.113.7'))->toBeFalse();

    // An empty token never leaves the process: there is nothing to verify and
    // Cloudflare would only answer the same thing more slowly.
    Http::fake();
    expect(Turnstile::verify('', '203.0.113.7'))->toBeFalse()
        ->and(Turnstile::verify(null, '203.0.113.7'))->toBeFalse();
});

it('lets a submission through when cloudflare itself is unreachable, and says so in the log', function () {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    // Fail *open*, deliberately. The alternative is that an outage at
    // Cloudflare silently refuses every abstract in the last hour before a
    // deadline - which is the exact moment it would hurt most - while the
    // honeypot, the minimum fill time and the per-IP throttle are all still
    // running. An explicit `success: false` is still a refusal; only an
    // unreachable verifier is waved through, and it is logged so the outage is
    // visible rather than inferred.
    expect(Turnstile::verify('a-token', '203.0.113.7'))->toBeTrue();

    Log::shouldHaveReceived('warning')->once();
});

it('refuses when cloudflare answers with a server error', function () {
    Http::fake([Turnstile::VERIFY_URL => Http::response('', 500)]);

    expect(Turnstile::verify('a-token', '203.0.113.7'))->toBeFalse();
});
```

Append to `tests/Feature/Public/SubmissionFormTest.php`, adding `use Illuminate\Support\Facades\Http;` and `use Illuminate\Support\Facades\Storage;` to its import block first:

```php
// --- Files ---------------------------------------------------------------

it('attaches a pdf to the abstract when it is submitted', function () {
    Storage::fake('local');

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('uploads', [uploadedFixturePdf()])
        ->call('submit')
        ->assertHasNoErrors();

    $submission = Submission::query()->firstOrFail();

    expect($submission->files)->toHaveCount(1)
        ->and($submission->files->first()?->original_name)->toBe('abstract.pdf');

    Storage::disk('local')->assertExists((string) $submission->files->first()?->path);
});

it('shows the count, size and type limits the conference set', function () {
    $this->conference->forceFill(['max_files' => 2, 'allowed_file_types' => ['pdf']])->save();

    get(submitUrl($this->conference))
        ->assertOk()
        ->assertSee('PDF')
        ->assertSee('2')
        ->assertSee('10 MB');
});

it('refuses a renamed image and leaves the abstract as a draft', function () {
    Storage::fake('local');

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('uploads', [new Illuminate\Http\UploadedFile(base_path('tests/Fixtures/not-really.pdf'), 'abstract.pdf', 'application/pdf', null, true)])
        ->call('submit')
        ->assertHasErrors(['uploads']);

    // The row exists as a draft - the author's typing is not thrown away - but
    // it was not submitted and no reference was burnt.
    $submission = Submission::query()->firstOrFail();

    expect($submission->status)->toBe(SubmissionStatus::Draft)
        ->and($submission->reference)->toBeNull()
        ->and($submission->files)->toHaveCount(0)
        ->and($this->conference->refresh()->submission_counter)->toBe(0);
});

it('refuses more files than the conference allows before touching the database', function () {
    Storage::fake('local');
    $this->conference->forceFill(['max_files' => 1])->save();

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('uploads', [uploadedFixturePdf(), uploadedFixturePdf('second.pdf')])
        ->call('submit')
        ->assertHasErrors(['uploads']);

    expect(Submission::query()->count())->toBe(0);
});

it('drops a pending upload the author changed their mind about', function () {
    Storage::fake('local');

    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->set('uploads', [uploadedFixturePdf(), uploadedFixturePdf('second.pdf')])
        ->assertCount('uploads', 2)
        ->call('removeUpload', 0)
        ->assertCount('uploads', 1);
});

// --- Bot protection ------------------------------------------------------

it('silently swallows a filled honeypot', function () {
    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('website_confirm', 'http://spam.example')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    // It looks like it worked, and nothing was written. Telling a bot which
    // check it failed is telling it which check to remove.
    expect(Submission::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('refuses a form that was filled faster than a human could, and accepts it four seconds later', function () {
    // phpunit.xml pins CASS_SUBMISSION_MIN_SECONDS=0 for the suite, because
    // openedAt is #[Locked] - a test cannot back-date it any more than a
    // browser can, and Livewire answers ->set() on a locked property with
    // CannotUpdateLockedPropertyException. So the one test that owns the gate
    // switches it on itself.
    config()->set('cass.submission_min_seconds', 4);

    // BOTH components are mounted at the same instant, before the clock moves:
    // openedAt is stamped in mount(), so a component created after the jump
    // would be "opened" at the advanced instant and refused all over again -
    // which is why the only honest way to test the patient half is to mount it
    // first and wait.
    $tooFast = fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]));
    $patient = fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]));

    $tooFast->call('submit')->assertHasErrors();

    expect(Submission::query()->count())->toBe(0);

    Carbon::setTestNow(now()->addSeconds(5));

    $patient->call('submit')->assertHasNoErrors();

    expect(Submission::query()->count())->toBe(1);

    Carbon::setTestNow();
});

it('blocks the sixth save or submit from one address in a minute', function () {
    foreach (range(1, 5) as $i) {
        fillForm(livewire(SubmissionForm::class, [
            'organization' => $this->organization,
            'conference' => $this->conference,
        ]))->call('saveDraft')->assertHasNoErrors();
    }

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))->call('submit')->assertHasErrors();

    expect(Submission::query()->count())->toBe(5);
});

it('renders the turnstile widget only when both keys are configured', function () {
    get(submitUrl($this->conference))->assertOk()->assertDontSee('challenges.cloudflare.com', escape: false);

    config()->set('cass.turnstile.site_key', 'site-key');
    config()->set('cass.turnstile.secret_key', 'secret-key');

    get(submitUrl($this->conference))
        ->assertOk()
        ->assertSee('challenges.cloudflare.com', escape: false)
        ->assertSee('site-key');
});

// Two tests, not one with two halves: Http::fake() MERGES stub sets and
// PendingRequest::buildStubHandler() takes ->filter()->first(), so a second
// fake() for the same URL never wins over the first.
it('refuses a submission whose turnstile token fails verification', function () {
    config()->set('cass.turnstile.site_key', 'site-key');
    config()->set('cass.turnstile.secret_key', 'secret-key');
    Http::fake([App\Support\Turnstile::VERIFY_URL => Http::response(['success' => false], 200)]);

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('turnstileToken', 'a-token')
        ->call('submit')
        ->assertHasErrors(['turnstileToken']);

    expect(Submission::query()->count())->toBe(0);
});

it('accepts a submission whose turnstile token verifies', function () {
    config()->set('cass.turnstile.site_key', 'site-key');
    config()->set('cass.turnstile.secret_key', 'secret-key');
    Http::fake([App\Support\Turnstile::VERIFY_URL => Http::response(['success' => true], 200)]);

    fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))
        ->set('turnstileToken', 'a-token')
        ->call('submit')
        ->assertHasNoErrors();

    expect(Submission::query()->count())->toBe(1);
});

it('redeems a turnstile token only once, however many times a submit is refused', function () {
    config()->set('cass.turnstile.site_key', 'site-key');
    config()->set('cass.turnstile.secret_key', 'secret-key');

    // One stub, deliberately: what proves the fix is the call COUNT, because a
    // second redemption of the same token answers `timeout-or-duplicate` at
    // Cloudflare and the widget is inside wire:ignore, so nothing would mint a
    // replacement for minutes.
    Http::fake([App\Support\Turnstile::VERIFY_URL => Http::response(['success' => true], 200)]);

    $component = fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]))->set('turnstileToken', 'a-token');

    // Verification runs before $this->validate(), so this refusal has already
    // been past Cloudflare once.
    $component->set('abstract', implode(' ', array_fill(0, 251, 'word')))
        ->call('submit')
        ->assertHasErrors(['abstract']);

    $component->set('abstract', 'Background. Methods. Results. Conclusion.')
        ->call('submit')
        ->assertHasNoErrors();

    Http::assertSentCount(1);

    expect(Submission::query()->count())->toBe(1);
});

it('reuses the same draft when a submit is retried after a rejected file', function () {
    Storage::fake('local');

    $component = fillForm(livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
    ]));

    $component
        ->set('uploads', [new Illuminate\Http\UploadedFile(base_path('tests/Fixtures/not-really.pdf'), 'abstract.pdf', 'application/pdf', null, true)])
        ->call('submit')
        ->assertHasErrors(['uploads']);

    // Livewire's _finishUpload() APPENDS to an array property, so the rejected
    // file has to be cleared before the good one is attached.
    $component->set('uploads', [])
        ->set('uploads', [uploadedFixturePdf()])
        ->call('submit')
        ->assertHasNoErrors();

    // One abstract, one token, one file - not two of each. submit() adopts the
    // row it just created into $this->submission, so the retry edits it.
    expect(Submission::query()->count())->toBe(1)
        ->and(Submission::query()->firstOrFail()->files)->toHaveCount(1);
});

it('refuses an oversized temporary upload at the livewire endpoint', function () {
    // config/livewire.php caps temporary_file_upload at max:10240 (KB), the
    // same 10 MB the form enforces. Without that file the package default is
    // max:12288 with no type rule, on a throttle:60,1 endpoint that any
    // anonymous visitor to /submit can reach.
    livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->set('uploads', [Illuminate\Http\UploadedFile::fake()->create('huge.pdf', 11 * 1024)])
        ->assertHasErrors(['uploads.0']);
});

function uploadedFixturePdf(string $name = 'abstract.pdf'): Illuminate\Http\UploadedFile
{
    $source = base_path('tests/Fixtures/abstract.pdf');

    if ($name === 'abstract.pdf') {
        return new Illuminate\Http\UploadedFile($source, $name, 'application/pdf', null, true);
    }

    // A distinct body, so two uploads in one submission are not a duplicate.
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$name;
    file_put_contents($path, file_get_contents($source).'%% '.$name."\n");

    return new Illuminate\Http\UploadedFile($path, $name, 'application/pdf', null, true);
}
```

Add `use Illuminate\Http\UploadedFile;`, `use Illuminate\Support\Facades\Http;` and `use Illuminate\Support\Facades\Storage;` to that file's imports.

Append to `tests/Feature/Public/ConferencePageTest.php`:

```php
it('links the open call for abstracts at the submission form', function () {
    $conference = Conference::factory()->for($this->organization)->published()->create();

    get(conferenceUrl($conference))
        ->assertOk()
        ->assertSee('Submit abstract')
        // Plan 2 shipped this CTA pointing at `#`. Plan 3 replaces exactly that
        // one href; this is the assertion that it happened.
        ->assertSee(route('conference.submit', [$this->organization, $conference]), escape: false)
        ->assertDontSee('href="#"', escape: false);
});

it('does not link the form when the window is not open', function () {
    $conference = Conference::factory()->for($this->organization)->closed()->create();

    get(conferenceUrl($conference))
        ->assertOk()
        ->assertDontSee(route('conference.submit', [$this->organization, $conference]), escape: false);
});
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Unit/TurnstileTest.php tests/Feature/Public/SubmissionFormTest.php tests/Feature/Public/ConferencePageTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found" /tmp/t.log | head -3
```

Expected: `rc=1`, `Class "App\Support\Turnstile" not found`.

- [ ] **Step 3: Write `app/Support/Turnstile.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile, in the twenty lines it actually takes. No package: a
 * wrapper for this would bring a middleware and a Blade component, and the
 * widget here lives inside a Livewire form whose own action does the
 * verification.
 *
 * Spec 5.3: "Cloudflare Turnstile when keys are configured". With no keys it is
 * transparently absent - no widget, no HTTP call, no refusal - so local
 * development and the test suite are unaffected and a deployment turns it on by
 * setting two environment variables.
 */
final class Turnstile
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function isConfigured(): bool
    {
        return filled(config('cass.turnstile.site_key')) && filled(config('cass.turnstile.secret_key'));
    }

    public static function siteKey(): ?string
    {
        $key = config('cass.turnstile.site_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * True when the request may proceed.
     *
     * The failure modes, and why each answers the way it does:
     *
     * - **Not configured** -> true. There is nothing to verify.
     * - **No token** -> false. The widget is on the page; a submit without one
     *   is a client that did not run it.
     * - **`success: false`** -> false. Cloudflare looked and said no.
     * - **A non-2xx answer, or no answer at all** -> **true, and logged**. This
     *   is the deliberate fail-open. An outage at Cloudflare would otherwise
     *   refuse every abstract in the last hour before a deadline, which is
     *   exactly when it would cost the most, and the honeypot, the minimum fill
     *   time and the per-IP throttle are all still running underneath. The log
     *   line is what makes the outage visible rather than inferred from a quiet
     *   submission count.
     */
    public static function verify(?string $token, string $clientIp): bool
    {
        if (! self::isConfigured()) {
            return true;
        }

        if (! is_string($token) || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->connectTimeout(3)
                ->post(self::VERIFY_URL, [
                    'secret' => (string) config('cass.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $clientIp,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Turnstile verification could not reach Cloudflare; allowing the request.', [
                'message' => $exception->getMessage(),
            ]);

            return true;
        }

        if ($response->serverError()) {
            // Cloudflare answered, badly. Treated as a refusal rather than an
            // outage: an answer that parses to nothing is not the same as no
            // answer, and this is the shape a misconfigured secret produces.
            return false;
        }

        return $response->json('success') === true;
    }
}
```

- [ ] **Step 4: Add the knobs to `config/cass.php` and `.env.example`**

`config/cass.php`, next to `max_file_bytes`:

```php
    // Spec section 9: "submission 5/min/IP". Applied inside the Livewire
    // component (a Livewire action is one POST to /livewire/update, so route
    // middleware cannot tell a save from a submit) with App\Support\ClientIp.
    'submission_rate_limit' => (int) env('CASS_SUBMISSION_RATE_LIMIT', 5),

    // A form nobody could have read, let alone filled, in this many seconds was
    // not filled by a person. Four is low enough that a determined author
    // pasting a prepared abstract still gets through.
    'submission_min_seconds' => (int) env('CASS_SUBMISSION_MIN_SECONDS', 4),
```

(`status_page_rate_limit` is already there — Task 4 Step 8 added it next to `max_file_bytes`, because the limiter that reads it is registered with the `/s/{token}` route in Task 4.)

`.env.example`, in the CASS block:

```
# Bot protection on the public submission form (spec 5.3 and 9).
CASS_SUBMISSION_RATE_LIMIT=5
CASS_SUBMISSION_MIN_SECONDS=4
# Livewire's temporary-upload endpoint: requests per minute per address.
CASS_UPLOAD_RATE_LIMIT=20
```

`phpunit.xml`, next to the existing `TURNSTILE_SITE_KEY` line:

```xml
        <!-- Both keys empty, so Turnstile is off for every test that does not
             deliberately switch it on with config()->set(). -->
        <env name="TURNSTILE_SECRET_KEY" value=""/>
        <!-- The minimum fill time is a property of mount(): openedAt is
             #[Locked], so no test can back-date it - Livewire answers a set()
             on a locked property with CannotUpdateLockedPropertyException. Off
             for the suite, so every Task 6 case keeps passing unchanged; the
             one test that owns the gate switches it back on with
             config()->set() and moves the clock. -->
        <env name="CASS_SUBMISSION_MIN_SECONDS" value="0"/>
```

The minimum fill time is the one guard a Livewire component test cannot satisfy, because a test mounts and calls in the same instant. Pinning it to `0` in `phpunit.xml` is what keeps Task 6's twenty-one cases green after this task inserts `passesBotChecks()` ahead of every other check, and `phpunit.browser.xml` (Task 13 Step 3) carries the identical line because its `<php>` block mirrors `phpunit.xml` exactly. Production keeps the four-second default from `.env.example`.

Finally, `config/livewire.php` — a **new** file. This task opens the application's first public write surface, and Livewire's temporary-upload endpoint is part of it: without this file, `temporary_file_upload` keeps the package's defaults (`vendor/livewire/livewire/src/Features/SupportFileUploads/FileUploadConfiguration.php`), which are `['required', 'file', 'max:12288']` with no type rule, `throttle:60,1`, and `config('filesystems.default')` — `local`, rooted at `storage/app/private`, inside the production `cass-storage` volume. The 5/min limiter this task adds lives *inside* `saveDraft()`/`submit()` and never sees an upload, so an anonymous visitor who loads `/submit` could push roughly 720 MB a minute of arbitrary content into that volume.

```php
<?php

declare(strict_types=1);

/*
 * Only the temporary-upload block is overridden; everything else falls through
 * to vendor/livewire/livewire/config/livewire.php via mergeConfigFrom. Because
 * that merge is shallow, this array has to be COMPLETE - a partial one replaces
 * the package's whole block.
 *
 * This endpoint accepts bytes from anonymous visitors on
 * /c/{org}/{conf}/submit, so it carries the same 10 MB cap as the form. No
 * `extensions` rule: the SAME endpoint serves the organizer's
 * FileUpload::make('logo_path') in EditOrganizationProfile, and a pdf-only rule
 * there would refuse every logo. Type is enforced where it belongs, in
 * SubmissionForm::uploadRules() and StoreSubmissionFile's content sniff.
 */
return [
    'temporary_file_upload' => [
        // null, so it follows filesystems.default (local -> storage/app/private,
        // inside the cass-storage volume).
        'disk' => null,
        'rules' => ['required', 'file', 'max:10240'],
        'directory' => null,
        'middleware' => 'throttle:'.env('CASS_UPLOAD_RATE_LIMIT', 20).',1',
        // The package's list, unchanged: Filament previews an uploaded logo
        // through livewire.preview-file, which checks it.
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
```

`CASS_UPLOAD_RATE_LIMIT` is the one `env()` call outside `config/cass.php` and `bootstrap/app.php` that this plan adds, and it is declared in `.env.example` above, so Task 12 Step 4's completeness check stays clean.

- [ ] **Step 5: Extend `app/Livewire/Public/SubmissionForm.php`**

Add the imports:

```php
use App\Actions\Submissions\DeleteSubmissionFile;
use App\Actions\Submissions\StoreSubmissionFile;
use App\Exceptions\SubmissionFileRejected;
use App\Models\SubmissionFile;
use App\Support\ClientIp;
use App\Support\Turnstile;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
```

Add the trait and the four new properties:

```php
    use WithFileUploads;

    /**
     * Pending uploads, not yet stored. They stay in Livewire's temporary-upload
     * directory until a submission row exists to attach them to - which for a
     * brand new abstract is only after SaveSubmissionDraft has run.
     *
     * @var list<TemporaryUploadedFile>
     */
    public array $uploads = [];

    /** Honeypot. A real author never sees it, so a value in it is a bot. */
    public string $website_confirm = '';

    /**
     * When the form was mounted. #[Locked] so the client cannot backdate it,
     * which is the only thing that makes the minimum-fill-time check mean
     * anything.
     */
    #[Locked]
    public int $openedAt = 0;

    public string $turnstileToken = '';

    /**
     * A Turnstile token is single-use. Once Cloudflare has said yes for this
     * component instance, a later validation failure must not re-redeem it:
     * the second answer is `timeout-or-duplicate`, and the widget sits inside
     * wire:ignore, so nothing would mint a replacement until its own
     * refresh-expired timer fires minutes later. #[Locked] because it is a
     * server-side fact about a past HTTP call, not a form field.
     */
    #[Locked]
    public bool $humanVerified = false;
```

In `mount()`, after `$this->token = $token;`:

```php
        $this->openedAt = now()->timestamp;
```

Fold the bot checks into the guard already at the top of `saveDraft()` and `submit()`, so all three refusals leave by the same door:

```php
        if (! $this->isWritable() || ! $this->passesBotChecks() || ! $this->windowIsOpen()) {
            return null;
        }
```

(and delete the `// Task 7 inserts ...` comment above each one.)

...and add the guard methods:

```php
    /**
     * Spec 5.3: honeypot, per-IP rate limit, Turnstile when configured, plus a
     * minimum fill time.
     *
     * A filled honeypot returns *true from the caller's point of view* -
     * `handledAsBot()` sets a redirect and the caller stops - because telling a
     * bot which check it failed is telling it which check to remove. Every
     * other refusal is a visible error, because a person hitting one has done
     * nothing wrong and needs to know what happened.
     */
    private function passesBotChecks(): bool
    {
        $key = 'submission:'.ClientIp::from(request());

        // A separate key for the penalties. RateLimiter::hit() sets the decay
        // only on the *first* hit for a key (Illuminate\Cache\RateLimiter::
        // increment uses cache->add), so mixing a 600-second penalty and the
        // 60-second budget on one key would make the limit five per ten minutes
        // for everyone behind that address - not the 5/min/IP spec section 9
        // gives real authors, who on a conference NAT share one IP.
        $penaltyKey = 'submission-penalty:'.ClientIp::from(request());

        if ($this->website_confirm !== '') {
            RateLimiter::hit($penaltyKey, 600);
            $this->handledAsBot();

            return false;
        }

        if (now()->timestamp - $this->openedAt < (int) config('cass.submission_min_seconds')) {
            RateLimiter::hit($penaltyKey, 600);
            $this->addError('title', __('submission.errors.too_fast'));

            return false;
        }

        if (RateLimiter::tooManyAttempts($penaltyKey, (int) config('cass.submission_rate_limit'))
            || RateLimiter::tooManyAttempts($key, (int) config('cass.submission_rate_limit'))) {
            $this->addError('title', __('submission.errors.too_many', [
                'seconds' => max(RateLimiter::availableIn($key), RateLimiter::availableIn($penaltyKey)),
            ]));

            return false;
        }

        RateLimiter::hit($key, 60);

        // Only once per component instance. This method runs before
        // $this->validate(), so a submit that fails on the word limit has
        // already been here - and re-redeeming the same token would answer
        // `timeout-or-duplicate`, locking the author out of their own form.
        if (! $this->humanVerified) {
            if (! Turnstile::verify($this->turnstileToken === '' ? null : $this->turnstileToken, ClientIp::from(request()))) {
                // The widget mints a single-use token; a failed verification
                // means the author has to solve it again, so clear it and ask
                // the widget - which lives inside wire:ignore - for a new one.
                $this->turnstileToken = '';
                $this->dispatch('turnstile-reset');
                $this->addError('turnstileToken', __('submission.errors.turnstile'));

                return false;
            }

            $this->humanVerified = true;
        }

        return true;
    }

    /** Looks exactly like success and writes nothing. */
    private function handledAsBot(): void
    {
        session()->flash('status', __('submission.flash.draft_saved'));

        $this->redirect(route('conference.show', [$this->organization, $this->conference]), navigate: false);
    }
```

The minimum fill time is the one guard a Livewire component test cannot satisfy, because a test mounts and calls in the same instant and `openedAt` is `#[Locked]` — Livewire answers a `->set()` on a locked property with `CannotUpdateLockedPropertyException`, so a test cannot back-date it any more than a browser can. `phpunit.xml` (Step 4) and `phpunit.browser.xml` (Task 13 Step 3) both pin `CASS_SUBMISSION_MIN_SECONDS=0`, which is what keeps Task 6's twenty-three cases green now that this guard runs ahead of every other check. The only test that exercises the gate sets `cass.submission_min_seconds` itself, mounts both components before moving the clock, and asserts both halves. Production keeps the four-second default from `.env.example`.

Add the upload handling. `updatedUploads()` gives immediate feedback on the two things that need no database; the content sniff stays in `StoreSubmissionFile`:

```php
    /**
     * Runs on every change to the uploads array. Size and count are cheap and
     * are the two an author gets wrong most often, so they are answered here
     * rather than after the whole form is filled in.
     */
    public function updatedUploads(): void
    {
        $this->validate($this->uploadRules(), [], ['uploads' => __('submission.files.label')]);
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
        $this->resetErrorBag('uploads');
    }

    /** Edit mode only: drop a file that is already stored. */
    public function deleteFile(string $ulid, DeleteSubmissionFile $delete): void
    {
        if ($this->submission === null || ! $this->submission->isOpenToAuthor()) {
            return;
        }

        $file = $this->submission->files()->where('ulid', $ulid)->first();

        if ($file instanceof SubmissionFile) {
            $delete->handle($file);
            $this->submission->unsetRelation('files');
        }
    }

    /** @return array<string, mixed> */
    private function uploadRules(): array
    {
        $remaining = max(0, (int) $this->conference->max_files - $this->storedFileCount());

        return [
            'uploads' => ['array', 'max:'.$remaining],
            'uploads.*' => [
                'file',
                // Kilobytes, which is what the `max` rule speaks.
                'max:'.(int) floor((int) config('cass.max_file_bytes') / 1024),
                'extensions:'.implode(',', array_map('strtolower', (array) ($this->conference->allowed_file_types ?? ['pdf']))),
            ],
        ];
    }

    private function storedFileCount(): int
    {
        return $this->submission?->files()->count() ?? 0;
    }

    /**
     * Called after the row exists and before the submit transition. A rejection
     * here leaves the abstract as a saved draft with a visible error rather
     * than throwing the author's typing away - which is why the caller checks
     * the return value instead of catching an exception.
     */
    private function storeUploads(Submission $submission, StoreSubmissionFile $store): bool
    {
        foreach ($this->uploads as $upload) {
            try {
                $store->handle($submission, $upload);
            } catch (SubmissionFileRejected $exception) {
                $this->addError('uploads', $exception->getMessage());

                return false;
            }
        }

        $this->uploads = [];

        return true;
    }
```

Then wire it into the two buttons. `saveDraft()` gains one call on **each** of its two branches — after the save and before the redirect — because the status-page edit form carries the same files section, and a return before `storeUploads()` would drop an attachment behind a success flash.

On the edit branch, replacing the `// Task 7 stores pending uploads here` comment:

```php
            if (! $this->storeUploads($this->submission, $store)) {
                return null;
            }
```

and on the new-draft branch, after the `$sendLink->handle(...)` block (the row has already been adopted into `$this->submission`, so a retry edits it rather than minting a second one):

```php
        if (! $this->storeUploads($link->submission, $store)) {
            return null;
        }
```

and `submit()` gains, between the save and the `try`:

```php
        // Validated before the row is touched (count, size, extension) and
        // stored after it exists (content sniff). A content mismatch therefore
        // leaves a draft behind, which is the friendliest failure available:
        // the author fixes the file and presses Submit again - and because the
        // branch above adopted the row into $this->submission, that second
        // press edits the same abstract instead of creating another one.
        if (! $this->storeUploads($submission, $store)) {
            return null;
        }
```

Both methods take `StoreSubmissionFile $store` as an extra injected argument, and `submit()` also calls `$this->validate($this->uploadRules())` as part of its rule set — add `...$this->uploadRules()` to the array `submitRules()` returns, and `$this->validate($this->uploadRules())` to `saveDraft()` before the save.

Finally, `render()` passes the two things the new view sections need:

```php
            'storedFiles' => $this->submission?->files()->get() ?? collect(),
            'turnstileSiteKey' => Turnstile::siteKey(),
```

- [ ] **Step 6: Write `resources/views/livewire/public/partials/files.blade.php`**

```blade
<section class="rounded-lg border border-slate-200 bg-white p-6 space-y-4">
    <h2 class="text-lg font-semibold">{{ __('submission.sections.files') }}</h2>
    <p class="text-sm text-slate-500">
        {{ __('submission.files.limits', [
            'count' => (int) $conference->max_files,
            'types' => strtoupper(implode(', ', (array) $conference->allowed_file_types)),
            'size' => (int) round(((int) config('cass.max_file_bytes')) / 1048576),
        ]) }}
    </p>

    @if ($storedFiles->isNotEmpty())
        <ul class="space-y-2">
            @foreach ($storedFiles as $file)
                <li class="flex items-center justify-between rounded border border-slate-200 px-3 py-2 text-sm" wire:key="file-{{ $file->ulid }}">
                    <span>{{ $file->original_name }} <span class="text-slate-400">({{ number_format($file->size / 1024) }} KB)</span></span>
                    <button type="button" wire:click="deleteFile('{{ $file->ulid }}')"
                            wire:confirm="{{ __('submission.files.confirm_delete') }}"
                            class="text-red-600 hover:underline">{{ __('submission.files.remove') }}</button>
                </li>
            @endforeach
        </ul>
    @endif

    <div>
        <label for="uploads" class="block text-sm font-medium">{{ __('submission.files.label') }}</label>
        {{-- wire:model (not .blur): a file input fires `change`, and the
             upload has to start when the file is chosen, not when the field
             loses focus. --}}
        <input id="uploads" type="file" multiple wire:model="uploads"
               accept="{{ collect((array) $conference->allowed_file_types)->map(fn ($t) => '.'.$t)->implode(',') }}"
               class="mt-1 w-full text-sm">
        <div wire:loading wire:target="uploads" class="mt-1 text-sm text-slate-500">{{ __('submission.files.uploading') }}</div>
        @error('uploads') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        @error('uploads.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    @if ($uploads !== [])
        <ul class="space-y-2">
            @foreach ($uploads as $index => $upload)
                <li class="flex items-center justify-between rounded border border-dashed border-slate-300 px-3 py-2 text-sm" wire:key="upload-{{ $index }}">
                    <span>{{ $upload->getClientOriginalName() }}</span>
                    <button type="button" wire:click="removeUpload({{ $index }})"
                            class="text-red-600 hover:underline">{{ __('submission.files.remove') }}</button>
                </li>
            @endforeach
        </ul>
    @endif
</section>
```

- [ ] **Step 7: Add the three blocks to `submission-form.blade.php`**

Replace `{{-- Task 7 inserts the honeypot, the fill-time field and the Turnstile widget here. --}}` with:

```blade
            {{-- Honeypot. Hidden from sight and from screen readers, out of the
                 tab order, and with autocomplete off so a password manager does
                 not fill it in and lock a real author out. --}}
            <div class="hidden" aria-hidden="true">
                <label for="website_confirm">{{ __('submission.fields.honeypot') }}</label>
                <input id="website_confirm" type="text" wire:model="website_confirm" tabindex="-1" autocomplete="off">
            </div>

            @if ($turnstileSiteKey !== null)
                {{-- wire:ignore: the widget is rendered by Cloudflare's script
                     into this div, and a Livewire morph would replace it with an
                     empty one on the next update. The callback writes the token
                     straight into the component with $wire.set. --}}
                <div wire:ignore>
                    <div class="cf-turnstile"
                         data-sitekey="{{ $turnstileSiteKey }}"
                         data-callback="cassTurnstileCallback"></div>
                    <script>
                        window.cassTurnstileCallback = (token) => window.Livewire.find('{{ $this->getId() }}').set('turnstileToken', token, false);
                        // $this->dispatch('turnstile-reset') reaches
                        // dispatchGlobal(), which is a window CustomEvent. A
                        // refused verification burns the token, and the widget
                        // is inside wire:ignore, so nothing else would mint a
                        // replacement before its own refresh-expired timer.
                        window.addEventListener('turnstile-reset', () => window.turnstile && window.turnstile.reset());
                    </script>
                    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                </div>
                @error('turnstileToken') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            @endif
```

Replace `{{-- Task 7 inserts the files section here. --}}` with:

```blade
            @if ((int) $conference->max_files > 0)
                @include('livewire.public.partials.files')
            @endif
```

- [ ] **Step 8: Add the new language keys**

Append to `lang/en/submission.php`:

```php
    'files' => [
        'label' => 'Attach files',
        'limits' => 'Up to :count file(s), :types, :size MB each.',
        'remove' => 'Remove',
        'confirm_delete' => 'Remove this file from your abstract?',
        'uploading' => 'Uploading…',
    ],
```

and, inside the existing `errors` array:

```php
        'too_fast' => 'That was quicker than a person can fill this in. Please take a moment and try again.',
        'too_many' => 'Too many attempts from this connection. Please try again in :seconds seconds.',
        'turnstile' => 'Please complete the "I am human" check and try again.',
```

and, inside the existing `fields` array:

```php
        'honeypot' => 'Leave this field empty',
```

- [ ] **Step 9: Replace the one `href`**

`resources/views/public/partials/submit-cta.blade.php`, the `SubmissionWindow::Open` branch:

```blade
            <a href="{{ route('conference.submit', [$conference->organization, $conference]) }}"
```

Nothing else in that file changes — the comment at the top of it said this would be the only edit, and it is.

`$conference->organization` would be a lazy load, and `ConferencePageTest` bounds the public page to four queries. Keep it at three by handing the controller's already-resolved organization to the model. In `app/Http/Controllers/Public/ConferenceController.php`, after `$conference->load('tracks');`:

```php
        // The scoped route binding resolved this conference *through*
        // $organization, but Eloquent does not set the inverse relation, so the
        // CTA's route() call would lazily load the same row again. The public
        // page has a 300 ms budget and a query-count test; this keeps both.
        $conference->setRelation('organization', $organization);
```

- [ ] **Step 10: Run the tests, build, Pint, Larastan, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && \
php artisan test tests/Unit/TurnstileTest.php tests/Feature/Public > /tmp/t.log 2>&1; echo "public rc=$?"; tail -4 /tmp/t.log
```

Expected: `public rc=0`. The file has gained nine cases in this task beyond the file-handling ones: the honeypot, the minimum fill time (both halves now asserted), the per-IP block, the widget-rendering case, the two Turnstile verification cases, the single-redemption case, the rejected-file retry, and the oversized temporary upload.

If `blocks the sixth save or submit from one address in a minute` is flaky, check that nothing else in the file hits the same limiter key: `RateLimiter` uses the array cache store (`phpunit.xml` sets `CACHE_STORE=array`), which is per-test, so a leak across tests is a sign the key is being built from something other than `ClientIp::from(request())`.

If every case in the file fails with `CannotUpdateLockedPropertyException`, something is still calling `->set('openedAt', …)`: that property is `#[Locked]`, the gate is pinned to `0` in `phpunit.xml`, and no test may write it.

```bash
cd /c/Users/ahmed/Documents/CASS && npm run build 2>&1 | tail -3 && \
./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?"; \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log
```

Expected: `stan rc=0`, `tests rc=0`, `392 passed`.

```bash
cd /c/Users/ahmed/Documents/CASS && git add -A && git commit -q -m "feat(public): file attachments, honeypot, throttle, Turnstile and the real submit link

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

---

### Task 8: The author status page at `/s/{token}`, with edit and withdraw

Spec 5.3 step 5. One URL, whose path segment is the credential: there is no login, no session and no account, so everything this page shows or allows is decided by whether the SHA-256 of that segment matches a stored hash.

**Files:**
- Create: `app/Livewire/Public/SubmissionStatus.php`, `resources/views/livewire/public/submission-status.blade.php`
- Modify: `resources/views/components/layouts/conference.blade.php`, `lang/en/submission.php`
- Already done in Task 4, and **not** touched again here: the `/s/{token}` route in `routes/web.php`, the `submission-status` limiter in `app/Providers/AppServiceProvider.php`, and `cass.status_page_rate_limit`. They had to land there because `Submission::statusUrl()` resolves the route name from Task 5 onwards.
- Test: `tests/Feature/Public/SubmissionStatusPageTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Public/SubmissionStatusPageTest.php`
```php
<?php

declare(strict_types=1);

use App\Actions\Submissions\IssueSubmissionToken;
use App\Enums\SubmissionStatus;
use App\Livewire\Public\SubmissionForm;
use App\Livewire\Public\SubmissionStatus as StatusPage;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\Track;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');

    $this->organization = Organization::factory()->approved()->create(['name' => 'Gulf Pediatric Society']);
    $this->conference = Conference::factory()->for($this->organization)->published()->create([
        'name' => 'Gulf Pediatric Critical Care 2026',
        'word_limit' => 250,
        'presentation_types' => ['oral', 'poster'],
    ]);
    $this->submission = Submission::factory()
        ->for($this->conference)
        ->submitted()
        ->withCorrespondingAuthor('sara@example.org', 'Dr Sara Al-Harbi')
        ->create(['title' => 'Early mobilisation after cardiac surgery']);
    $this->submission->forceFill(['reference' => 'GPCC26-017'])->save();

    $this->token = app(IssueSubmissionToken::class)->handle($this->submission);
});

it('shows the reference, status, title, authors and deadline', function () {
    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee('GPCC26-017')
        ->assertSee('Early mobilisation after cardiac surgery')
        ->assertSee('Dr Sara Al-Harbi')
        ->assertSee('sara@example.org')
        ->assertSee(SubmissionStatus::Submitted->getLabel())
        ->assertSee($this->conference->deadlineInConferenceTimezone()?->format('j F Y, H:i'))
        ->assertSee('Gulf Pediatric Society')
        // The page carries author names, affiliations and addresses behind a
        // URL anyone can paste into a browser. Search engines must not index it.
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});

it('lists files behind fresh signed links and serves them', function () {
    $sha = hash('sha256', 'x');
    Storage::disk('local')->put(substr($sha, 0, 2).'/f.pdf', '%PDF-1.4 test');
    $file = SubmissionFile::factory()->for($this->submission)->create([
        'original_name' => 'abstract.pdf',
        'path' => substr($sha, 0, 2).'/f.pdf',
        'sha256' => $sha,
    ]);

    $response = get('/s/'.$this->token)->assertOk()->assertSee('abstract.pdf');

    // The link on the page is a signed URL that actually works, not a route
    // the browser will 403 on.
    preg_match('#(/files/'.$file->ulid.'\?[^"\']+)#', $response->getContent() ?: '', $matches);

    expect($matches[1] ?? null)->not->toBeNull();

    get(html_entity_decode((string) $matches[1]))->assertOk();
});

it('404s an unknown token and anything that is not one', function () {
    get('/s/'.str_repeat('a', 64))->assertNotFound();
    // Route constraint: exactly 64 of [A-Za-z0-9].
    get('/s/short')->assertNotFound();
    get('/s/'.str_repeat('a', 63).'-')->assertNotFound();
    get('/s/'.str_repeat('a', 65))->assertNotFound();
});

it('does not let one submission token open another', function () {
    $other = Submission::factory()->for($this->conference)->submitted()->withCorrespondingAuthor('other@example.org')->create([
        'title' => 'Somebody else entirely',
    ]);
    app(IssueSubmissionToken::class)->handle($other);

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee('Early mobilisation after cardiac surgery')
        ->assertDontSee('Somebody else entirely');
});

it('stops working once the link has been reissued', function () {
    get('/s/'.$this->token)->assertOk();

    $fresh = app(IssueSubmissionToken::class)->handle($this->submission);

    get('/s/'.$this->token)->assertNotFound();
    get('/s/'.$fresh)->assertOk();
});

it('blocks the twenty-first request from one address in a minute', function () {
    foreach (range(1, 20) as $i) {
        get('/s/'.$this->token)->assertOk();
    }

    get('/s/'.$this->token)->assertStatus(429);
});

it('opens the form prefilled when the author chooses to edit', function () {
    Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);

    livewire(StatusPage::class, ['token' => $this->token])
        ->assertDontSeeLivewire(SubmissionForm::class)
        ->call('startEditing')
        ->assertSeeLivewire(SubmissionForm::class)
        ->assertSee('Early mobilisation after cardiac surgery')
        ->call('cancelEditing')
        ->assertDontSeeLivewire(SubmissionForm::class);
});

it('saves an edit through the nested form and keeps the status and reference', function () {
    // No ->set('openedAt', …): it is #[Locked], and phpunit.xml pins
    // CASS_SUBMISSION_MIN_SECONDS=0 so the gate is off for the suite anyway.
    livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
        'submission' => $this->submission,
        'token' => $this->token,
    ])
        ->assertSet('title', 'Early mobilisation after cardiac surgery')
        ->set('title', 'Early mobilisation after cardiac surgery: a pilot')
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->assertRedirectContains('/s/'.$this->token);

    expect($this->submission->refresh())
        ->title->toBe('Early mobilisation after cardiac surgery: a pilot')
        ->status->toBe(SubmissionStatus::Submitted)
        ->reference->toBe('GPCC26-017');
});

it('attaches and removes a file from the status page edit form', function () {
    // saveDraft() has two branches and the files section is on both forms, so
    // an attachment made here must not be dropped behind a success flash.
    livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
        'submission' => $this->submission,
        'token' => $this->token,
    ])
        ->set('uploads', [new Illuminate\Http\UploadedFile(base_path('tests/Fixtures/abstract.pdf'), 'abstract.pdf', 'application/pdf', null, true)])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $file = $this->submission->refresh()->files()->firstOrFail();

    expect($file->original_name)->toBe('abstract.pdf');
    Storage::disk('local')->assertExists((string) $file->path);

    livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
        'submission' => $this->submission,
        'token' => $this->token,
    ])->call('deleteFile', $file->ulid);

    expect($this->submission->refresh()->files()->count())->toBe(0);
    Storage::disk('local')->assertMissing((string) $file->path);
});

it('submits a draft from the status page and keeps the author on the same link', function () {
    // Spec 5.3's whole reason for the draft email: save, come back, submit.
    $draft = Submission::factory()
        ->for($this->conference)
        ->withCorrespondingAuthor('draft@example.org', 'Dr Omar Khan')
        ->create(['title' => 'A work in progress', 'abstract' => 'Background methods results conclusion.']);
    $token = app(IssueSubmissionToken::class)->handle($draft);

    livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
        'submission' => $draft,
        'token' => $token,
    ])
        // fillFromSubmission() derives `agreed` from submitted_at, so a draft
        // starts unticked and the author has to agree on the way out.
        ->assertSet('agreed', false)
        ->set('presentation_preference', 'oral')
        ->set('agreed', true)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirectContains('/s/'.$token);

    expect($draft->refresh()->status)->toBe(SubmissionStatus::Submitted)
        ->and($draft->reference)->not->toBeNull()
        // SubmitAbstract was handed the existing token, so the link the author
        // bookmarked from the draft email still opens the abstract.
        ->and(Submission::findByPlainToken($token)?->is($draft))->toBeTrue();
});

it('reports a refusal only the action can make, on the field it belongs to', function () {
    // Every form rule passes - this abstract was filled in through this very
    // form and is already submitted. The only thing left that refuses is
    // SubmitAbstract::blockers()'s "This abstract has already been submitted.",
    // and reportBlockers() maps that sentence onto the abstract field (it
    // contains the word "abstract", not "title"). Without reportBlockers the
    // button would silently do nothing, and no form rule can produce this case.
    livewire(SubmissionForm::class, [
        'organization' => $this->organization,
        'conference' => $this->conference,
        'submission' => $this->submission,
        'token' => $this->token,
    ])->call('submit')->assertHasErrors(['abstract']);

    expect($this->submission->refresh()->status)->toBe(SubmissionStatus::Submitted)
        ->and($this->submission->reference)->toBe('GPCC26-017')
        // No second reference was burnt.
        ->and($this->conference->refresh()->submission_counter)->toBe(0);
});

it('offers neither edit nor withdraw once the window has closed', function () {
    Carbon::setTestNow($this->conference->submission_deadline->copy()->addMinute());

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee('GPCC26-017')
        ->assertDontSee(__('submission.status.edit'))
        ->assertDontSee(__('submission.status.withdraw'));

    livewire(StatusPage::class, ['token' => $this->token])
        ->call('startEditing')
        ->assertDontSeeLivewire(SubmissionForm::class);

    Carbon::setTestNow();
});

it('withdraws on request and refuses to do it twice', function () {
    livewire(StatusPage::class, ['token' => $this->token])
        ->call('withdraw')
        ->assertHasNoErrors();

    expect($this->submission->refresh()->status)->toBe(SubmissionStatus::Withdrawn)
        ->and($this->submission->withdrawn_at)->not->toBeNull()
        // The number stays: an organizer may already have printed it.
        ->and($this->submission->reference)->toBe('GPCC26-017');

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee(SubmissionStatus::Withdrawn->getLabel())
        ->assertDontSee(__('submission.status.withdraw'));

    livewire(StatusPage::class, ['token' => $this->token])
        ->call('withdraw')
        ->assertHasErrors();
});

it('refuses to withdraw after the deadline', function () {
    Carbon::setTestNow($this->conference->submission_deadline->copy()->addMinute());

    livewire(StatusPage::class, ['token' => $this->token])->call('withdraw')->assertHasErrors();

    expect($this->submission->refresh()->status)->toBe(SubmissionStatus::Submitted);

    Carbon::setTestNow();
});

it('shows a draft as unfinished and pushes the author to submit it', function () {
    $draft = Submission::factory()->for($this->conference)->withCorrespondingAuthor('draft@example.org')->create();
    $token = app(IssueSubmissionToken::class)->handle($draft);

    get('/s/'.$token)
        ->assertOk()
        ->assertSee(SubmissionStatus::Draft->getLabel())
        ->assertSee(__('submission.status.draft_warning'))
        ->assertSee(__('submission.status.edit'));
});

it('renders a neutral block for a status plan 3 does not drive yet', function (SubmissionStatus $status) {
    $this->submission->forceFill(['status' => $status])->save();

    get('/s/'.$this->token)
        ->assertOk()
        ->assertSee($status->getLabel())
        ->assertSee(__('submission.status.decision_pending'))
        // Plan 5 replaces this block with the decision letter. Until it does,
        // the page must not offer an edit or a withdrawal it cannot honour.
        ->assertDontSee(__('submission.status.edit'))
        ->assertDontSee(__('submission.status.withdraw'));
})->with([
    SubmissionStatus::UnderReview,
    SubmissionStatus::Accepted,
    SubmissionStatus::Rejected,
    SubmissionStatus::Waitlisted,
]);

it('404s when the conference is no longer public', function () {
    get('/s/'.$this->token)->assertOk();

    $this->organization->forceFill(['status' => App\Enums\OrganizationStatus::Suspended])->save();

    // Consistent with the conference page and the short link: a suspended
    // organization goes offline entirely, and the author's own link is part of
    // "entirely".
    get('/s/'.$this->token)->assertNotFound();
});
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Public/SubmissionStatusPageTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found" /tmp/t.log | head -3
```

Expected: `rc=1`, `Class "App\Livewire\Public\SubmissionStatus" not found`.

- [ ] **Step 3: Write `app/Livewire/Public/SubmissionStatus.php`**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Actions\Submissions\WithdrawSubmission;
use App\Exceptions\SubmissionNotAcceptable;
use App\Models\Submission;
use App\Support\Branding\OrganizationTheme;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Spec 5.3 step 5. There is no account here: the 64 characters in the URL are
 * the whole credential, and everything this page shows or allows follows from
 * the single indexed lookup in mount().
 *
 * Two notes on what is deliberately *not* done:
 *
 * - No constant-time comparison. The lookup is an equality test on a unique
 *   index over a SHA-256 hash of 256 bits of entropy; a timing oracle on an
 *   index probe is not a path to guessing one, and pretending otherwise would
 *   mean loading every row to compare in PHP.
 * - No distinction between "no such token" and "token for something you may not
 *   see". Both are 404, from the same line.
 */
class SubmissionStatus extends Component
{
    #[Locked]
    public Submission $submission;

    /**
     * The plaintext token. It is in the component snapshot, which is signed but
     * not encrypted - and that is fine here specifically because the same value
     * is in the address bar of the page the snapshot belongs to. It is passed
     * down to the nested form so an edit keeps the author on the same URL.
     */
    #[Locked]
    public string $token;

    public bool $editing = false;

    public function mount(string $token): void
    {
        $submission = Submission::findByPlainToken($token);

        abort_if($submission === null, 404);

        // The same visibility rule as the conference page and the short link: a
        // draft, archived or suspended conference is offline to everyone
        // outside the panel, and an author's own link is not an exception.
        abort_unless(
            $submission->conference->isPubliclyVisible()
                && $submission->conference->organization->isApproved(),
            404,
        );

        $this->submission = $submission;
        $this->token = $token;
    }

    public function startEditing(): void
    {
        $this->editing = $this->submission->isOpenToAuthor();
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        $this->submission->refresh();
    }

    public function withdraw(WithdrawSubmission $withdraw): void
    {
        try {
            // No $actor: nobody is logged in. WithdrawSubmission records "by
            // author" in the activity log for exactly this call - and, because
            // there is no actor, it also enforces the submission window, which
            // the organizer's call deliberately does not. The window check
            // belongs there and not here: the button being hidden after the
            // deadline is not a check, and this component is reachable by
            // anyone holding the token.
            $this->submission = $withdraw->handle($this->submission);
        } catch (SubmissionNotAcceptable $exception) {
            $this->addError('withdraw', $exception->getMessage());

            return;
        }

        $this->editing = false;
        session()->flash('status', __('submission.status.withdrawn_flash'));
    }

    public function render(): mixed
    {
        $conference = $this->submission->conference;
        $organization = $conference->organization;
        $theme = OrganizationTheme::for($organization);

        return view('livewire.public.submission-status', [
            'conference' => $conference,
            'organization' => $organization,
            'theme' => $theme,
            'authors' => $this->submission->authors()->get(),
            'files' => $this->submission->files()->get(),
            'canChange' => $this->submission->isOpenToAuthor(),
        ])->layout('components.layouts.conference', [
            'organization' => $organization,
            'conference' => $conference,
            'theme' => $theme,
            // This page prints author names, affiliations and email addresses
            // behind nothing but a URL. Keeping it out of search indexes is the
            // difference between a private link and a published one.
            'noindex' => true,
        ]);
    }
}
```

- [ ] **Step 4: Write `resources/views/livewire/public/submission-status.blade.php`**

```blade
<div class="mx-auto max-w-3xl px-4 py-10">
    @if (session('status'))
        <div class="mb-6 rounded-lg border border-green-300 bg-green-50 p-4 text-green-900">{{ session('status') }}</div>
    @endif

    <p class="text-sm font-semibold uppercase tracking-wide text-[var(--org-accent)]">{{ $conference->name }}</p>
    <h1 class="mt-2 text-3xl font-semibold tracking-tight">{{ $submission->title }}</h1>

    <dl class="mt-6 grid gap-4 sm:grid-cols-3">
        <div>
            <dt class="text-sm font-medium text-slate-500">{{ __('submission.status.reference') }}</dt>
            <dd class="mt-1 font-mono text-lg font-semibold">{{ $submission->reference ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-slate-500">{{ __('submission.status.state') }}</dt>
            <dd class="mt-1">
                <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-800">
                    {{ $submission->status->getLabel() }}
                </span>
            </dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-slate-500">{{ __('submission.status.deadline') }}</dt>
            <dd class="mt-1 font-medium">
                {{ $conference->deadlineInConferenceTimezone()?->format('j F Y, H:i') }} ({{ $conference->timezone }})
            </dd>
        </div>
    </dl>

    @if ($submission->status === App\Enums\SubmissionStatus::Draft)
        <div class="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-900">
            {{ __('submission.status.draft_warning') }}
        </div>
    @elseif ($submission->status === App\Enums\SubmissionStatus::Withdrawn)
        <div class="mt-6 rounded-lg border border-red-300 bg-red-50 p-4 text-red-900">
            {{ __('submission.status.withdrawn_notice', [
                'date' => $submission->withdrawn_at?->setTimezone($conference->timezone)->format('j F Y, H:i'),
            ]) }}
        </div>
    @elseif (! $submission->status->isDrivenInPlan3())
        {{-- Plan 5 replaces this block with the decision letter from the
             matching decision_* template. Until then the page states the
             status and nothing it cannot stand behind. --}}
        <div class="mt-6 rounded-lg border border-slate-300 bg-slate-50 p-4 text-slate-700">
            {{ __('submission.status.decision_pending') }}
        </div>
    @endif

    @error('withdraw') <p class="mt-4 text-sm text-red-600">{{ $message }}</p> @enderror

    @if ($editing)
        <div class="mt-8">
            <button type="button" wire:click="cancelEditing" class="text-sm font-medium text-slate-600 hover:underline">
                {{ __('submission.status.cancel_edit') }}
            </button>
            {{-- A nested Livewire component: same form, same validation, same
                 actions. wire:key is the submission's ULID so the child is not
                 re-mounted on every parent render. --}}
            @livewire('public.submission-form', [
                'organization' => $organization,
                'conference' => $conference,
                'submission' => $submission,
                'token' => $token,
            ], key('form-'.$submission->ulid))
        </div>
    @else
        <section class="mt-8 rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-lg font-semibold">{{ __('submission.sections.authors') }}</h2>
            <ol class="mt-3 space-y-2 text-sm">
                @foreach ($authors as $author)
                    <li class="flex flex-wrap items-baseline gap-2">
                        <span class="font-medium">{{ $author->name }}</span>
                        <span class="text-slate-500">{{ $author->email }}</span>
                        @if ($author->affiliation)<span class="text-slate-500">· {{ $author->affiliation }}</span>@endif
                        @if ($author->is_presenter)<span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ __('submission.authors.is_presenter') }}</span>@endif
                        @if ($author->is_corresponding)<span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ __('submission.authors.is_corresponding') }}</span>@endif
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="mt-6 rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-lg font-semibold">{{ __('submission.fields.abstract') }}</h2>
            <p class="mt-3 whitespace-pre-line text-slate-700">{{ $submission->abstract }}</p>
            <p class="mt-3 text-sm text-slate-500">
                {{ $submission->word_count }} {{ __('submission.fields.words') }}
                @if ($submission->track) · {{ $submission->track->name }} @endif
                @if ($submission->presentation_preference) · {{ $submission->presentation_preference->getLabel() }} @endif
            </p>
        </section>

        @if ($files->isNotEmpty())
            <section class="mt-6 rounded-lg border border-slate-200 bg-white p-6">
                <h2 class="text-lg font-semibold">{{ __('submission.sections.files') }}</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($files as $file)
                        {{-- A fresh signed URL on every render, valid for
                             cass.file_url_minutes. Nothing about it is stored. --}}
                        <li>
                            <a href="{{ $file->temporaryUrl() }}" class="text-[var(--org-primary)] hover:underline">{{ $file->original_name }}</a>
                            <span class="text-slate-400">({{ number_format($file->size / 1024) }} KB)</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($canChange)
            <div class="mt-8 flex flex-wrap items-center gap-3">
                <button type="button" wire:click="startEditing"
                        class="rounded-lg bg-[var(--org-primary)] px-6 py-3 font-semibold text-[var(--org-on-primary)] hover:opacity-90">
                    {{ __('submission.status.edit') }}
                </button>
                <button type="button" wire:click="withdraw"
                        wire:confirm="{{ __('submission.status.confirm_withdraw') }}"
                        class="rounded-lg border border-red-300 px-6 py-3 font-medium text-red-700 hover:bg-red-50">
                    {{ __('submission.status.withdraw') }}
                </button>
            </div>
            <p class="mt-3 text-sm text-slate-500">{{ __('submission.status.until_deadline') }}</p>
        @endif
    @endif

    <p class="mt-10 text-sm text-slate-500">{{ __('submission.status.keep_link') }}</p>
</div>
```

- [ ] **Step 5: Add `noindex` to the conference layout**

`resources/views/components/layouts/conference.blade.php`: extend the props line and add one tag in the head.

```blade
@props(['organization', 'conference', 'theme', 'noindex' => false])
```

and, immediately after the `<meta name="description" ...>` line:

```blade
    @if ($noindex)
        <meta name="robots" content="noindex, nofollow">
    @endif
```

The public conference page passes nothing and keeps its default of `false`, so Plan 2's behaviour is unchanged.

- [ ] **Step 6: Check the route, which already exists**

Nothing to write here. `Route::get('/s/{token}', SubmissionStatus::class)` — with the `[A-Za-z0-9]{64}` constraint that makes anything which could not be a token a 404 from the router, the `throttle:submission-status` middleware, and the `submission-status` limiter in `app/Providers/AppServiceProvider.php` — was registered in **Task 4 Step 7**, together with `cass.status_page_rate_limit` and `CASS_STATUS_PAGE_RATE_LIMIT` in Task 4 Step 8. It had to be: `Submission::statusUrl()` resolves the route **name**, and Task 5's actions render status links, so a route registered here would make every Task 5 test throw `RouteNotFoundException`.

This step is one command, to prove the class Task 4 named now exists and the route resolves to it:

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan route:list --except-vendor | grep "s/{token}"
```

Expected: one line, `GET|HEAD  s/{token} ... submission.status › App\Livewire\Public\SubmissionStatus`.

- [ ] **Step 7: Add the status-page language keys**

Append to `lang/en/submission.php`:

```php
    'status' => [
        'reference' => 'Reference',
        'state' => 'Status',
        'deadline' => 'Submission deadline',
        'edit' => 'Edit this abstract',
        'cancel_edit' => 'Cancel and go back',
        'withdraw' => 'Withdraw this abstract',
        'confirm_withdraw' => 'Withdraw this abstract? The organizers will be able to see that it was withdrawn, and you cannot undo this yourself.',
        'withdrawn_flash' => 'Your abstract has been withdrawn.',
        'withdrawn_notice' => 'This abstract was withdrawn on :date and will not be reviewed.',
        'draft_warning' => 'This is a draft. It has not been submitted, and a draft is not reviewed. Open it and press "Submit abstract" before the deadline.',
        'decision_pending' => 'The organizers are handling this abstract. They will email you when there is a decision, and the letter will appear here.',
        'until_deadline' => 'You can edit or withdraw until the submission deadline.',
        'keep_link' => 'Keep this link. It is the only way back to this abstract, and anyone who has it can edit it — please do not forward it.',
    ],
```

- [ ] **Step 8: Run the tests, build, Pint, Larastan, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && \
php artisan test tests/Feature/Public/SubmissionStatusPageTest.php > /tmp/t.log 2>&1; echo "status rc=$?"; tail -4 /tmp/t.log && \
php artisan route:list --except-vendor | grep -E "submission|conference.submit|files"
```

Expected: `status rc=0`, `20 passed`, and three route lines: `c/{organization}/{conference:slug}/submit`, `s/{token}` and `files/{ulid}`. The three cases beyond the page itself are the ones that cover the *rest* of spec 5.3's loop through this page: `attaches and removes a file from the status page edit form` (the edit branch of `saveDraft()` stores uploads too), `submits a draft from the status page and keeps the author on the same link` (the whole point of the draft email), and `reports a refusal only the action can make, on the field it belongs to` (the one path through `reportBlockers()` that no form rule can produce).

```bash
cd /c/Users/ahmed/Documents/CASS && npm run build 2>&1 | tail -3 && \
./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?"; \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log
```

Expected: `stan rc=0`, `tests rc=0`, `412 passed`.

```bash
cd /c/Users/ahmed/Documents/CASS && git add -A && git commit -q -m "feat(public): the author status page with edit and withdraw

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

---

### Task 9: The organizer's submission resource — list, view, withdraw, resend and CSV export

Spec section 4's "View submissions and files" for every organization member, plus the two organizer-side tools an author flow always ends up needing.

**Why this resource turns Filament's tenant scoping off, and what replaces it.** `Submission` has no `organization_id`: it reaches the tenant through `conference`. Filament's tenancy is a global scope plus two model observers (`Filament\Resources\Resource\Concerns\BelongsToTenant`), and its `created` observer ends in `$relationship->save($tenant)` for any ownership relation that is not `BelongsTo`, `BelongsToThrough` or `BelongsToMany`. A `HasOneThrough` named `organization` would satisfy the *query* half (the scope's `default` branch is `whereHas($name, fn ($q) => $q->whereKey($tenant))`) and then fatal on the *creation* half — `HasOneThrough` has no `save()` — every time a submission is created while the panel is booted with a tenant, which is every panel test and every future console command run inside a tenant.

So `SubmissionResource` sets `$isScopedToTenant = false` and scopes `getEloquentQuery()` by hand through `conference`. That method is also what `resolveRecordRouteBinding()` uses (`HasRoutes::getRecordRouteBindingEloquentQuery()` returns `static::getEloquentQuery()`), so the list, the view page and every record URL are covered by the one override. `SubmissionPolicy::view()` is the second, independent gate, and Step 1's tests assert both — a foreign record key must 404, not 403-by-luck.

`Submission::organization()` still exists as a `HasOneThrough`, because the house rule is that every tenant-owned model has one and because the infolist and the export read it. Nothing in Filament is told to use it.

**Files:**
- Create: `app/Filament/Organizer/Resources/Submissions/SubmissionResource.php`, `Pages/ListSubmissions.php`, `Pages/ViewSubmission.php`, `Schemas/SubmissionInfolist.php`, `Tables/SubmissionsTable.php`, `Tables/SubmissionActions.php`
- Create: `app/Actions/Submissions/ExportSubmissionsCsv.php`
- Modify: `app/Models/Submission.php` (`organization()`), `app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceInfolist.php`, `app/Notifications/NewSubmissionNotice.php` (one line), `composer.json`
- Test: `tests/Feature/Organizer/SubmissionResourceTest.php`

- [ ] **Step 1: Promote openspout to a direct dependency**

```bash
cd /c/Users/ahmed/Documents/CASS && php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar require openspout/openspout:"^4" --no-interaction 2>&1 | tail -5 && \
php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar show openspout/openspout | head -3 && \
git diff --stat composer.lock
```

Expected: composer reports nothing to install or update (v4.32.0 is already there as a dependency of `filament/actions` — fact 1), `composer.json` gains the entry, and `composer.lock` changes only in the `content-hash` and in the package's own `"type"` position. If composer wants to *download* it, stop: something removed `filament/actions` and the whole panel is broken.

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Organizer/SubmissionResourceTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use App\Filament\Organizer\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Organizer\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Organizer\Resources\Submissions\SubmissionResource;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\Track;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->published()->create([
        'name' => 'Alpha Annual Meeting',
        'reference_prefix' => 'AAM26',
    ]);
    $this->submission = Submission::factory()
        ->for($this->conference)
        ->submitted()
        ->withCorrespondingAuthor('sara@example.org', 'Dr Sara Al-Harbi')
        ->create(['title' => 'Early mobilisation after cardiac surgery']);
    $this->submission->forceFill(['reference' => 'AAM26-017'])->save();
});

it('lists only the submissions of the current tenant', function () {
    $theirs = withoutTenant(fn () => Submission::factory()->submitted()->create(['title' => 'Somebody else entirely']));

    livewire(ListSubmissions::class)
        ->assertCanSeeTableRecords([$this->submission])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('shows the columns an organizer triages on', function () {
    Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);
    $this->submission->forceFill(['track_id' => $this->conference->tracks()->first()?->id])->save();

    livewire(ListSubmissions::class)
        ->assertCanRenderTableColumn('reference')
        ->assertCanRenderTableColumn('title')
        ->assertCanRenderTableColumn('status')
        ->assertCanRenderTableColumn('submitted_at')
        ->assertSee('AAM26-017')
        ->assertSee('Dr Sara Al-Harbi')
        ->assertSee('Neurocritical care');
});

it('filters by conference, status and track', function () {
    $other = Conference::factory()->for($this->organization)->published()->create(['name' => 'Alpha Winter School']);
    $otherSubmission = Submission::factory()->for($other)->submitted()->create(['title' => 'Winter abstract']);
    $draft = Submission::factory()->for($this->conference)->create(['title' => 'Still a draft']);

    livewire(ListSubmissions::class)
        ->filterTable('conference_id', $this->conference->id)
        ->assertCanSeeTableRecords([$this->submission, $draft])
        ->assertCanNotSeeTableRecords([$otherSubmission]);

    livewire(ListSubmissions::class)
        ->filterTable('status', SubmissionStatus::Draft->value)
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$this->submission]);

    // The third filter the name promises. A track belongs to a conference, so
    // the option list is every track of every conference the tenant owns -
    // which is exactly what SubmissionsTable's track_id filter builds.
    $track = Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);
    $tracked = Submission::factory()->for($this->conference)->submitted()->create([
        'title' => 'Tracked abstract',
        'track_id' => $track->id,
    ]);

    livewire(ListSubmissions::class)
        ->filterTable('track_id', $track->id)
        ->assertCanSeeTableRecords([$tracked])
        ->assertCanNotSeeTableRecords([$this->submission, $draft, $otherSubmission]);
});

it('searches on the title, the reference and an author email', function () {
    $other = Submission::factory()->for($this->conference)->submitted()->withCorrespondingAuthor('omar@example.org')->create(['title' => 'Something unrelated']);
    $other->forceFill(['reference' => 'AAM26-099'])->save();

    foreach (['Early mobilisation', 'AAM26-017', 'sara@example.org'] as $term) {
        livewire(ListSubmissions::class)
            ->searchTable($term)
            ->assertCanSeeTableRecords([$this->submission])
            ->assertCanNotSeeTableRecords([$other]);
    }
});

it('opens a read-only view with the abstract, authors, answers and files', function () {
    $sha = hash('sha256', 'x');
    Storage::disk('local')->put(substr($sha, 0, 2).'/f.pdf', '%PDF-1.4 test');
    SubmissionFile::factory()->for($this->submission)->create([
        'original_name' => 'abstract.pdf', 'path' => substr($sha, 0, 2).'/f.pdf', 'sha256' => $sha,
    ]);
    $this->submission->forceFill([
        'custom_field_values' => ['ethics_approval_number' => 'IRB-2026-14'],
        'presentation_preference' => PresentationPreference::Poster,
    ])->save();

    livewire(ViewSubmission::class, ['record' => $this->submission->getRouteKey()])
        ->assertOk()
        ->assertSee('AAM26-017')
        ->assertSee('Early mobilisation after cardiac surgery')
        ->assertSee('Dr Sara Al-Harbi')
        ->assertSee('sara@example.org')
        ->assertSee('IRB-2026-14')
        ->assertSee('abstract.pdf')
        ->assertSee(PresentationPreference::Poster->getLabel());
});

it('links a file through a signed url that actually works', function () {
    $sha = hash('sha256', 'y');
    Storage::disk('local')->put(substr($sha, 0, 2).'/g.pdf', '%PDF-1.4 test');
    $file = SubmissionFile::factory()->for($this->submission)->create([
        'original_name' => 'appendix.pdf', 'path' => substr($sha, 0, 2).'/g.pdf', 'sha256' => $sha,
    ]);

    $html = livewire(ViewSubmission::class, ['record' => $this->submission->getRouteKey()])->assertOk()->html();

    preg_match('#(/files/'.$file->ulid.'\?[^"\']+)#', $html, $matches);

    expect($matches[1] ?? null)->not->toBeNull();

    $this->get(html_entity_decode((string) $matches[1]))->assertOk();
});

it('withdraws on the organizer side and records who did it', function () {
    livewire(ViewSubmission::class, ['record' => $this->submission->getRouteKey()])
        ->callAction('withdraw')
        ->assertHasNoActionErrors();

    expect($this->submission->refresh()->status)->toBe(SubmissionStatus::Withdrawn);

    $activity = Spatie\Activitylog\Models\Activity::query()->where('description', 'submission.withdrawn')->firstOrFail();

    expect($activity->causer_id)->toBe($this->user->id)
        ->and($activity->properties['by'] ?? null)->toBe('organizer');
});

it('resends a status link with a new token and kills the old one', function () {
    $old = app(App\Actions\Submissions\IssueSubmissionToken::class)->handle($this->submission);

    livewire(ViewSubmission::class, ['record' => $this->submission->getRouteKey()])
        ->callAction('resendLink')
        ->assertHasNoActionErrors();

    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('sara@example.org')
        && $mail->templateKey === 'submission_received');

    expect(Submission::findByPlainToken($old))->toBeNull();
});

it('exports the visible rows as csv and neutralises a spreadsheet formula', function () {
    // An author-supplied title beginning with `=` is executed by Excel when the
    // file is opened. It has to arrive as text.
    $this->submission->forceFill(['title' => '=HYPERLINK("https://evil.example","click")'])->save();
    withoutTenant(fn () => Submission::factory()->submitted()->create(['title' => 'Another tenant']));

    // callTableAction, not callAction: `export` is a table *header* action, and
    // the table helpers are what add the table context Filament resolves the
    // action name in (vendor/filament/tables/src/Testing/TestsActions.php).
    //
    // Livewire turns the StreamedResponse the action returns into a `download`
    // effect, running the stream callback and base64-encoding what it wrote
    // (SupportFileDownloads::call()) - so the assertion below is on the real
    // bytes a browser would have saved, not on a response object.
    $component = livewire(ListSubmissions::class)
        ->callTableAction('export')
        ->assertFileDownloaded();

    $csv = base64_decode((string) data_get($component->effects, 'download.content'), true);

    expect($csv)->toContain('AAM26-017')
        ->toContain('sara@example.org')
        ->toContain("'=HYPERLINK")
        ->not->toContain('Another tenant')
        // The BOM, so Excel opens UTF-8 without mangling an Arabic affiliation.
        ->and(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF");
});

// --- Cross-tenant -------------------------------------------------------

it('404s a submission from another organization', function () {
    $theirs = withoutTenant(fn () => Submission::factory()->submitted()->create());

    // Through the route, not livewire(): Filament's resolveRecord() throws
    // ModelNotFoundException, and Livewire's test harness disables exception
    // handling for everything except HttpException and AuthorizationException
    // (RequestBroker), so a livewire() call would error instead of asserting.
    // Only a real request turns it into the 404 a stranger actually sees -
    // which is the form Plan 2's ConferenceResourceTest already uses.
    get(SubmissionResource::getUrl('view', ['record' => $theirs->getRouteKey()], tenant: $this->organization))
        ->assertNotFound();
});

it('answers for its own organization file and refuses another one', function () {
    // SubmissionFilePolicy is the only thing between an organizer and a signed
    // URL for another tenant's uploaded PDF, and it is the gate the view page's
    // Gate::allows('view', $record) calls before minting one.
    $sha = hash('sha256', 'mine');
    $mine = SubmissionFile::factory()->for($this->submission)->create([
        'original_name' => 'mine.pdf',
        'path' => substr($sha, 0, 2).'/mine.pdf',
        'sha256' => $sha,
    ]);

    $theirFile = withoutTenant(function () {
        $theirs = Submission::factory()->submitted()->create();
        $sha = hash('sha256', 'theirs');

        return SubmissionFile::factory()->for($theirs)->create([
            'original_name' => 'theirs.pdf',
            'path' => substr($sha, 0, 2).'/theirs.pdf',
            'sha256' => $sha,
        ]);
    });

    $policy = app(App\Policies\SubmissionFilePolicy::class);

    // The positive half is what makes the negative half mean something: a
    // policy that answered false to everything would pass the negatives and
    // break every download.
    expect($policy->view($this->user, $mine))->toBeTrue()
        ->and($policy->view($this->user, $theirFile))->toBeFalse()
        // Files are attached and removed by the author, never by an organizer.
        ->and($policy->create($this->user))->toBeFalse()
        ->and($policy->update($this->user, $mine))->toBeFalse()
        ->and($policy->delete($this->user, $mine))->toBeFalse()
        ->and($policy->deleteAny($this->user))->toBeFalse();
});

it('refuses the actions on another organization submission', function () {
    $theirs = withoutTenant(fn () => Submission::factory()->submitted()->withCorrespondingAuthor('victim@example.org')->create());

    expect(fn () => app(App\Policies\SubmissionPolicy::class)->view($this->user, $theirs))->not->toThrow(Throwable::class);

    expect(app(App\Policies\SubmissionPolicy::class)->view($this->user, $theirs))->toBeFalse()
        ->and(app(App\Policies\SubmissionPolicy::class)->withdraw($this->user, $theirs))->toBeFalse()
        ->and(app(App\Policies\SubmissionPolicy::class)->resendLink($this->user, $theirs))->toBeFalse();

    Mail::assertNothingQueued();
});

it('hides the resource entirely from someone who is not a member', function () {
    $outsider = User::factory()->create();
    withoutTenant(fn () => Organization::factory()->approved()->create()->addMember($outsider, OrganizationRole::Owner));

    actingAs($outsider);

    expect(SubmissionResource::canViewAny())->toBeFalse();
});

it('lets a plain member read submissions but never edit or delete one', function () {
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    actingAs($member);

    livewire(ListSubmissions::class)->assertCanSeeTableRecords([$this->submission]);

    $policy = app(App\Policies\SubmissionPolicy::class);

    expect($policy->view($member, $this->submission))->toBeTrue()
        ->and($policy->update($member, $this->submission))->toBeFalse()
        ->and($policy->delete($member, $this->submission))->toBeFalse()
        ->and($policy->deleteAny($member))->toBeFalse()
        ->and($policy->forceDeleteAny($member))->toBeFalse()
        ->and($policy->restoreAny($member))->toBeFalse();
});

// --- Counts on the conference page --------------------------------------

it('shows the four submission counts and a link on the conference view', function () {
    Submission::factory()->count(2)->for($this->conference)->create();
    Submission::factory()->for($this->conference)->withdrawn()->create();

    livewire(App\Filament\Organizer\Resources\Conferences\Pages\ViewConference::class, [
        'record' => $this->conference->getRouteKey(),
    ])
        ->assertOk()
        ->assertSee('Submissions')
        // total 4, draft 2, submitted 1, withdrawn 1
        ->assertSee('4')
        // Through the helper, so the test breaks if the query-string shape
        // drifts away from the one ListRecords actually binds.
        ->assertSee(SubmissionResource::urlForConference($this->conference), escape: false);
});

it('names the export file after the moment it was taken', function () {
    Carbon::setTestNow('2026-09-11 08:30:00');

    livewire(ListSubmissions::class)
        ->callTableAction('export')
        ->assertFileDownloaded('submissions-2026-09-11-083000.csv');

    Carbon::setTestNow();
});
```

- [ ] **Step 3: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/SubmissionResourceTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found" /tmp/t.log | head -3
```

Expected: `rc=1`, `Class "App\Filament\Organizer\Resources\Submissions\Pages\ListSubmissions" not found`.

- [ ] **Step 4: Add `organization()` to `app/Models/Submission.php`**

Import `use Illuminate\Database\Eloquent\Relations\HasOneThrough;` and add after `conference()`:

```php
    /**
     * The tenant, two hops away. A `hasOneThrough` read in the child-to-
     * grandparent direction: `conference_id` on this row points at a conference
     * whose `organization_id` points at the organization.
     *
     * It exists because the house rule is that every tenant-owned model can
     * name its organization, and because the infolist and the CSV export read
     * it. **Filament is deliberately not told to use it for tenancy** - see the
     * comment on SubmissionResource::$isScopedToTenant.
     *
     * @return HasOneThrough<Organization, Conference, $this>
     */
    public function organization(): HasOneThrough
    {
        return $this->hasOneThrough(
            Organization::class,
            Conference::class,
            'id',              // conferences.id ...
            'id',              // organizations.id ...
            'conference_id',   // ... matched from submissions.conference_id
            'organization_id', // ... and from conferences.organization_id
        );
    }
```

- [ ] **Step 5: Write `app/Actions/Submissions/ExportSubmissionsCsv.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\Submission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spec 5.6 names CSV and XLSX for the ranking table (Plan 5). This is the
 * submission list's half: CSV only, streamed, and never buffered - the caller
 * hands in the table's own already-scoped and already-filtered query, so an
 * organizer exports exactly the rows they can see.
 *
 * openspout writes to `php://output` inside a StreamedResponse, so a conference
 * with five thousand abstracts costs one chunk of rows in memory rather than
 * the whole file. Livewire turns a StreamedResponse returned from an action
 * into a download (Plan 2 fact 13).
 */
class ExportSubmissionsCsv
{
    private const HEADERS = [
        'Reference', 'Conference', 'Title', 'Status', 'Track', 'Presentation preference',
        'Corresponding author', 'Corresponding email', 'All authors', 'Affiliations',
        'Contact phone', 'Word count', 'Files', 'Extra answers', 'Submitted at', 'Last edited at',
    ];

    /**
     * @param  Builder<Submission>  $query
     */
    public function handle(Builder $query, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($query): void {
            $options = new Options;
            // Excel needs the BOM to open a UTF-8 file without mangling an
            // Arabic affiliation. It is openspout's default; stated so nobody
            // "tidies" it away.
            $options->SHOULD_ADD_BOM = true;

            $writer = new Writer($options);
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(self::HEADERS));

            $query
                ->with(['conference', 'track', 'authors', 'files'])
                ->reorder()
                ->chunkById(200, function (Collection $submissions) use ($writer): void {
                    foreach ($submissions as $submission) {
                        $writer->addRow(Row::fromValues($this->row($submission)));
                    }
                });

            $writer->close();
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return list<string> */
    private function row(Submission $submission): array
    {
        $corresponding = $submission->correspondingAuthor();
        $timezone = (string) $submission->conference->timezone;

        return array_map($this->guard(...), [
            (string) $submission->reference,
            (string) $submission->conference->name,
            (string) $submission->title,
            $submission->status->getLabel(),
            (string) ($submission->track?->name ?? ''),
            $submission->presentation_preference?->getLabel() ?? '',
            (string) ($corresponding?->name ?? ''),
            (string) ($corresponding?->email ?? ''),
            $submission->authors->pluck('name')->implode('; '),
            $submission->authors->pluck('affiliation')->filter()->unique()->implode('; '),
            (string) $submission->contact_phone,
            (string) $submission->word_count,
            $submission->files->pluck('original_name')->implode('; '),
            $this->extraAnswers($submission),
            $submission->submitted_at?->setTimezone($timezone)->format('Y-m-d H:i') ?? '',
            $submission->last_edited_at?->setTimezone($timezone)->format('Y-m-d H:i') ?? '',
        ]);
    }

    /**
     * The conference's own extra questions, flattened into one column. A column
     * per field would be wrong the moment two conferences are exported together
     * - which the "all conferences" filter allows - and a spreadsheet with
     * shifting columns is worse than one readable cell.
     */
    private function extraAnswers(Submission $submission): string
    {
        /** @var array<string, mixed> $values */
        $values = $submission->custom_field_values ?? [];
        $parts = [];

        foreach ($values as $key => $value) {
            $printable = match (true) {
                is_bool($value) => $value ? 'yes' : 'no',
                is_scalar($value) => (string) $value,
                default => json_encode($value) ?: '',
            };

            $parts[] = $key.': '.$printable;
        }

        return implode('; ', $parts);
    }

    /**
     * CSV injection. Excel and LibreOffice execute a cell that begins with
     * `=`, `+`, `-`, `@`, a tab or a carriage return, and every text column
     * here was typed by an author nobody vetted. A leading apostrophe makes the
     * cell text, which is what it always was.
     */
    private function guard(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
```

- [ ] **Step 6: Write the resource, its table, its actions and its pages**

`app/Filament/Organizer/Resources/Submissions/SubmissionResource.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Submissions;

use App\Filament\Organizer\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Organizer\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Organizer\Resources\Submissions\Schemas\SubmissionInfolist;
use App\Filament\Organizer\Resources\Submissions\Tables\SubmissionsTable;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** @extends \Filament\Resources\Resource<Submission> */
class SubmissionResource extends Resource
{
    protected static ?string $model = Submission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 2;

    /**
     * **Read the comment before changing this.**
     *
     * Filament's tenancy is a global scope *and* two model observers
     * (Resource/Concerns/BelongsToTenant). The `created` observer ends in
     * `$relationship->save($tenant)` for any ownership relation that is not
     * BelongsTo, BelongsToThrough or BelongsToMany. `Submission` has no
     * `organization_id`; it reaches the tenant through `conference`, so its
     * `organization()` is a HasOneThrough - which would scope queries correctly
     * (the scope's default branch is a whereHas) and then fatal on creation,
     * because HasOneThrough has no save(). That fires on every submission
     * created while this panel is booted with a tenant, which is every panel
     * test.
     *
     * So scoping is done by hand, once, in getEloquentQuery() below - which is
     * also what resolveRecordRouteBinding() uses
     * (HasRoutes::getRecordRouteBindingEloquentQuery()), so the list, the view
     * page and every generated record URL are all covered. SubmissionPolicy is
     * the second, independent gate.
     */
    protected static bool $isScopedToTenant = false;

    // No $recordRouteKeyName: the model's route key is the ULID and Filament
    // builds URLs from getRouteKey(), so the two agree (Plan 2 fact 16).

    public static function infolist(Schema $schema): Schema
    {
        return SubmissionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubmissionsTable::configure($table);
    }

    /**
     * @return Builder<Submission>
     */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        $query = parent::getEloquentQuery()->with(['conference', 'track', 'authors']);

        // whereRaw(false) rather than an empty result by luck: outside a tenant
        // this resource has no meaning, and returning every submission on the
        // platform because getTenant() happened to be null is the failure mode
        // this whole comment exists to prevent.
        if (! $tenant instanceof Organization) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'conference',
            fn (Builder $conference): Builder => $conference->where('organization_id', $tenant->getKey()),
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubmissions::route('/'),
            'view' => ViewSubmission::route('/{record}'),
        ];
    }

    /**
     * Used by the link from the conference view page. The query string has to
     * be the table filter's own shape: Filament 5's ListRecords binds
     * `public ?array $tableFilters` as `#[Url(as: 'filters')]`, and a single
     * SelectFilter's state is `['value' => ...]`. A bare `?conference_id=`
     * reaches no property at all, is silently dropped, and the organizer lands
     * on every conference's submissions.
     */
    public static function urlForConference(Conference $conference): string
    {
        return static::getUrl('index', [
            'filters' => ['conference_id' => ['value' => $conference->getKey()]],
        ]);
    }
}
```

`app/Filament/Organizer/Resources/Submissions/Tables/SubmissionsTable.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Submissions\Tables;

use App\Enums\SubmissionStatus;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        // Filament::getTenant() is typed Model|null, so narrow before calling
        // anything Organization-specific: without this, $tenant->conferences()
        // is a call on Illuminate\Database\Eloquent\Model and Larastan level 6
        // reports method.notFound. Same narrowing as
        // SubmissionResource::getEloquentQuery() and ConferenceForm.
        $tenant = Filament::getTenant();
        $tenant = $tenant instanceof Organization ? $tenant : null;

        return $table
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Draft'),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(80),
                TextColumn::make('corresponding_author')
                    ->label('Corresponding author')
                    // Not a relation column: the corresponding author is one
                    // row of a HasMany chosen by a flag, and `authors.name`
                    // would print every author's name joined together.
                    ->state(fn (Submission $record): string => $record->correspondingAuthor()?->name ?? '-')
                    ->description(fn (Submission $record): ?string => $record->correspondingAuthor()?->email)
                    // Searching an email has to reach the related table.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'authors',
                        fn (Builder $authors): Builder => $authors->where('email', 'like', "%{$search}%"),
                    )),
                TextColumn::make('conference.name')->label('Conference')->sortable()->toggleable(),
                TextColumn::make('track.name')->label('Track')->placeholder('-')->toggleable(),
                TextColumn::make('status')->badge()->sortable(),
                // Timestamps are UTC; show them in the conference's own zone
                // (spec section 10), the way Plan 2's conference table does.
                TextColumn::make('submitted_at')->label('Submitted')->dateTime('j M Y, H:i')->sortable()
                    ->timezone(fn (Submission $record): string => $record->conference->timezone)
                    ->description(fn (Submission $record): string => $record->conference->timezone)
                    ->placeholder('Not submitted'),
                TextColumn::make('files_count')->counts('files')->label('Files')->badge()->color('gray')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('conference_id')
                    ->label('Conference')
                    // The tenant's conferences only. `->relationship()` would
                    // query every conference on the platform, because this
                    // resource is not tenant-scoped by Filament (see
                    // SubmissionResource::$isScopedToTenant).
                    ->options(fn (): array => $tenant === null
                        ? []
                        : $tenant->conferences()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('status')->options(SubmissionStatus::class)->multiple(),
                SelectFilter::make('track_id')
                    ->label('Track')
                    ->options(fn (): array => $tenant === null
                        ? []
                        : Track::query()
                            ->whereHas('conference', fn (Builder $q): Builder => $q->where('organization_id', $tenant->getKey()))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                SubmissionActions::resendLink(),
                SubmissionActions::withdraw(),
            ])
            ->headerActions([
                SubmissionActions::export(),
            ])
            ->emptyStateHeading('No submissions yet')
            ->emptyStateDescription('Abstracts appear here as soon as authors start sending them.');
    }
}
```

`app/Filament/Organizer/Resources/Submissions/Tables/SubmissionActions.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Submissions\Tables;

use App\Actions\Submissions\ExportSubmissionsCsv;
use App\Actions\Submissions\SendSubmissionStatusLink;
use App\Actions\Submissions\WithdrawSubmission;
use App\Exceptions\SubmissionNotAcceptable;
use App\Filament\Organizer\Resources\Submissions\SubmissionResource;
use App\Models\Submission;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One definition of each organizer-side action, reused by the table row and by
 * the view page's header - the same pattern Plan 2's ConferenceStatusActions
 * established, for the same reason: the rules must not drift between the two
 * places an organizer meets them.
 */
class SubmissionActions
{
    public static function withdraw(): Action
    {
        return Action::make('withdraw')
            ->label('Withdraw')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Withdraw this abstract?')
            ->modalDescription('Use this when the author has asked you to withdraw it. The reference is kept, the abstract stays visible to you, and the author sees the withdrawal on their status page. It cannot be undone here.')
            ->visible(fn (Submission $record): bool => $record->status->isOpenToAuthor() && Gate::allows('withdraw', $record))
            ->action(function (Submission $record, WithdrawSubmission $withdraw): void {
                Gate::authorize('withdraw', $record);

                /** @var User $actor */
                $actor = auth()->user();

                try {
                    $withdraw->handle($record, $actor);
                } catch (SubmissionNotAcceptable $exception) {
                    Notification::make()->danger()->title('Not withdrawn')->body(e($exception->getMessage()))->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('Abstract withdrawn')->send();
            });
    }

    public static function resendLink(): Action
    {
        return Action::make('resendLink')
            ->label('Resend status link')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Email a new status link?')
            ->modalDescription('The corresponding author gets a fresh link to their abstract. **Any link they already have stops working**, which is the point when a link has been lost or forwarded.')
            ->visible(fn (Submission $record): bool => Gate::allows('resendLink', $record))
            ->action(function (Submission $record, SendSubmissionStatusLink $send): void {
                Gate::authorize('resendLink', $record);

                try {
                    $log = $send->handle($record);
                } catch (SubmissionNotAcceptable $exception) {
                    Notification::make()->danger()->title('Nothing sent')->body(e($exception->getMessage()))->persistent()->send();

                    return;
                }

                // Filament renders a notification body as sanitised HTML whose
                // shared config keeps `style` and `class` (Plan 2 fact 15), and
                // an author's address is author-supplied - escape it.
                Notification::make()->success()->title('Link sent')->body('A new link is on its way to '.e($log->to_email).'.')->send();
            });
    }

    public static function export(): Action
    {
        return Action::make('export')
            ->label('Export CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => Gate::allows('export', Submission::class))
            ->action(function (HasTable $livewire, ExportSubmissionsCsv $export): ?StreamedResponse {
                Gate::authorize('export', Submission::class);

                // The table's own query: already tenant-scoped by
                // SubmissionResource::getEloquentQuery() and already carrying
                // whatever filters and search the organizer is looking at, so
                // the file matches the screen. Filament types it `?Builder`
                // with no generic (Tables\Contracts\HasTable), which Larastan
                // level 6 will not hand to a `Builder<Submission>` parameter -
                // hence the annotation and the null guard.
                /** @var \Illuminate\Database\Eloquent\Builder<Submission>|null $query */
                $query = $livewire->getFilteredTableQuery();

                if ($query === null) {
                    Notification::make()->danger()->title('Nothing to export')->send();

                    return null;
                }

                return $export->handle($query, 'submissions-'.now()->format('Y-m-d-His').'.csv');
            });
    }

    /** @return list<Action> */
    public static function all(): array
    {
        return [static::resendLink(), static::withdraw()];
    }

    public static function backToList(): Action
    {
        return Action::make('backToList')
            ->label('All submissions')
            ->icon(Heroicon::OutlinedInbox)
            ->color('gray')
            ->url(fn (): string => SubmissionResource::getUrl('index'));
    }
}
```

`app/Filament/Organizer/Resources/Submissions/Pages/ListSubmissions.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Submissions\Pages;

use App\Filament\Organizer\Resources\Submissions\SubmissionResource;
use Filament\Resources\Pages\ListRecords;

class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;

    // No header actions: nobody creates an abstract from the panel. The export
    // lives in the table header, next to the filters it exports.
}
```

`app/Filament/Organizer/Resources/Submissions/Pages/ViewSubmission.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Submissions\Pages;

use App\Filament\Organizer\Resources\Submissions\SubmissionResource;
use App\Filament\Organizer\Resources\Submissions\Tables\SubmissionActions;
use Filament\Resources\Pages\ViewRecord;

class ViewSubmission extends ViewRecord
{
    protected static string $resource = SubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SubmissionActions::backToList(),
            ...SubmissionActions::all(),
        ];
    }
}
```

`app/Filament/Organizer/Resources/Submissions/Schemas/SubmissionInfolist.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Submissions\Schemas;

use App\Models\CustomField;
use App\Models\Submission;
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
            Section::make('Abstract')->columns(3)->components([
                TextEntry::make('reference')->fontFamily('mono')->placeholder('Not submitted'),
                TextEntry::make('status')->badge(),
                TextEntry::make('conference.name')->label('Conference'),
                TextEntry::make('title')->columnSpanFull(),
                // The abstract is plain text (the column is, and the form is a
                // textarea), so this is an escaped entry and never HTML.
                TextEntry::make('abstract')->columnSpanFull()->prose(),
                TextEntry::make('word_count')->suffix(' words'),
                TextEntry::make('track.name')->label('Track')->placeholder('-'),
                TextEntry::make('presentation_preference')->label('Presentation preference')->badge()->placeholder('-'),
            ]),

            Section::make('Authors')->components([
                RepeatableEntry::make('authors')
                    ->hiddenLabel()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('email')->copyable(),
                        TextEntry::make('affiliation')->placeholder('-'),
                        IconEntry::make('is_corresponding')->label('Corresponding')->boolean(),
                    ]),
                TextEntry::make('contact_phone')->label('Contact phone')->placeholder('-'),
            ]),

            Section::make('Extra answers')
                ->visible(fn (Submission $record): bool => filled($record->custom_field_values))
                ->components([
                    TextEntry::make('custom_field_values')
                        ->hiddenLabel()
                        ->listWithLineBreaks()
                        ->bulleted()
                        // Keyed by custom_fields.key; print the label the
                        // organizer wrote, not the derived key.
                        ->state(function (Submission $record): array {
                            $labels = $record->conference->customFields()->pluck('label', 'key');
                            $lines = [];

                            foreach ((array) $record->custom_field_values as $key => $value) {
                                $printable = match (true) {
                                    is_bool($value) => $value ? 'Yes' : 'No',
                                    is_scalar($value) => (string) $value,
                                    default => json_encode($value) ?: '',
                                };

                                $lines[] = ($labels[$key] ?? $key).': '.$printable;
                            }

                            return $lines;
                        }),
                ]),

            Section::make('Files')
                ->visible(fn (Submission $record): bool => $record->files()->exists())
                ->components([
                    RepeatableEntry::make('files')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('original_name')
                                ->label('File')
                                // A fresh 30-minute signed URL per render. The
                                // policy is checked before one is minted, so an
                                // organizer cannot hand out a link to a file
                                // they may not read (the signature itself is
                                // the capability once it exists).
                                ->url(fn (SubmissionFile $record): ?string => Gate::allows('view', $record)
                                    ? $record->temporaryUrl()
                                    : null)
                                ->openUrlInNewTab(),
                            TextEntry::make('size')->formatStateUsing(fn (int $state): string => number_format($state / 1024).' KB'),
                            TextEntry::make('mime')->label('Type'),
                        ]),
                ]),

            Section::make('Activity')->columns(3)->components([
                TextEntry::make('submitted_at')->dateTime('j M Y, H:i')
                    ->timezone(fn (Submission $record): string => $record->conference->timezone)
                    ->placeholder('Not submitted'),
                TextEntry::make('last_edited_at')->label('Last edited')->dateTime('j M Y, H:i')
                    ->timezone(fn (Submission $record): string => $record->conference->timezone)
                    ->placeholder('-'),
                TextEntry::make('withdrawn_at')->label('Withdrawn')->dateTime('j M Y, H:i')
                    ->timezone(fn (Submission $record): string => $record->conference->timezone)
                    ->placeholder('-'),
            ]),
        ]);
    }
}
```

`CustomField` is imported for the label lookup's type; if Larastan reports it unused after the closure above, drop the import rather than the closure.

- [ ] **Step 7: Add the counts to the conference view**

In `app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceInfolist.php`, import `use App\Filament\Organizer\Resources\Submissions\SubmissionResource;` and add this section immediately after the `Conference` section:

```php
            // A section rather than a relation manager: the conference page
            // needs four numbers and a way through to the list, and a relation
            // manager would be a second table of the same rows with its own
            // filters, its own authorization surface and its own tests.
            Section::make('Submissions')
                ->headerActions([
                    \Filament\Actions\Action::make('viewSubmissions')
                        ->label('Open the list')
                        ->icon(\Filament\Support\Icons\Heroicon::OutlinedInbox)
                        ->color('gray')
                        ->url(fn (Conference $record): string => SubmissionResource::urlForConference($record)),
                ])
                ->columns(4)
                ->components([
                    TextEntry::make('submissions_total')->label('Total')->badge()
                        ->state(fn (Conference $record): int => self::counts($record)['total']),
                    TextEntry::make('submissions_draft')->label('Drafts')->badge()->color('gray')
                        ->state(fn (Conference $record): int => self::counts($record)['draft']),
                    TextEntry::make('submissions_submitted')->label('Submitted')->badge()->color('info')
                        ->state(fn (Conference $record): int => self::counts($record)['submitted']),
                    TextEntry::make('submissions_withdrawn')->label('Withdrawn')->badge()->color('danger')
                        ->state(fn (Conference $record): int => self::counts($record)['withdrawn']),
                ]),
```

and, next to the existing `$blockers` WeakMap, the same memoisation for the counts — four entries asking for the same grouped query four times is four queries:

```php
    /**
     * @var WeakMap<Conference, array{total: int, draft: int, submitted: int, withdrawn: int}>|null
     */
    private static ?WeakMap $counts = null;

    /** @return array{total: int, draft: int, submitted: int, withdrawn: int} */
    private static function counts(Conference $record): array
    {
        self::$counts ??= new WeakMap;

        return self::$counts[$record] ??= $record->submissionCounts();
    }
```

- [ ] **Step 8: Point the member notice at the submission**

In `app/Notifications/NewSubmissionNotice.php`, replace the import and the one `->action(...)` call Task 3 marked:

```php
use App\Filament\Organizer\Resources\Submissions\SubmissionResource;
```

```php
            ->action('Open it in the organizer panel', SubmissionResource::getUrl(
                'view',
                ['record' => $this->submission],
                panel: 'organizer',
                tenant: $conference->organization,
            ))
```

Delete the paragraph of comment above it that explained the placeholder, and the now-unused `ConferenceResource` import. `tests/Feature/Mail/EmailLogPipelineTest.php` already asserts the link contains `/org/` and never `/s/`, so it covers the swap.

- [ ] **Step 9: Run the tests, Pint, Larastan, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && \
php artisan test tests/Feature/Organizer/SubmissionResourceTest.php tests/Feature/Mail > /tmp/t.log 2>&1; echo "panel rc=$?"; tail -4 /tmp/t.log
```

Expected: `panel rc=0`. One case is new beyond the resource itself: `answers for its own organization file and refuses another one`, which is the cross-tenant case for `SubmissionFilePolicy` — the gate that decides who may mint a signed download URL, and the only one of this plan's four policies that had no test of its own. `filters by conference, status and track` asserts all three filters, including the `track_id` block that fails unless `SubmissionsTable` actually defines that filter.

If `404s a submission from another organization` returns 403 instead of 404, the record was found and refused by the policy — which means `getEloquentQuery()` is not scoping. Fix the query, not the test: a 403 tells an outsider the ULID exists.

```bash
cd /c/Users/ahmed/Documents/CASS && \
./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?"; \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log
```

Expected: `stan rc=0`, `tests rc=0`, `428 passed`.

```bash
cd /c/Users/ahmed/Documents/CASS && git add -A && git commit -q -m "feat(organizer): submission resource with withdraw, resend and CSV export

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

---

### Task 10: The per-conference email template editor

Spec 5.2 ("Email templates start from platform defaults and can be edited per conference with placeholders") and 5.9 (the eleven keys). This is the item Plan 2's self-review handed to Plan 3.

**The shape, and why it is a page rather than a relation manager.** `email_templates` holds only *overrides*: a conference that has never customised anything has zero rows, and this screen has to show all eleven keys with their state. A `RelationManager`'s table is bound to its relationship query, so it cannot list eleven things when the relation returns two. Filament 5.8 tables do accept array records (fact 10: `Table::records()`, `HasRecords::getTableRecords()`'s `hasQuery() === false` branch, `ArrayRecord::getKeyName()`), so the screen is an array-backed table on a resource page — the same `Pages\` pattern `ConferenceShortLink` already uses at `conferences/{record}/share`, registered here at `conferences/{record}/emails`.

**Files:**
- Create: `app/Filament/Organizer/Resources/Conferences/Pages/ConferenceEmailTemplates.php`, `resources/views/filament/organizer/pages/email-templates.blade.php`
- Modify: `app/Filament/Organizer/Resources/Conferences/ConferenceResource.php`, `app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php`, `app/Filament/Organizer/Resources/Conferences/Tables/ConferencesTable.php`, `app/Actions/Mail/RenderEmailTemplate.php`
- Test: `tests/Feature/Organizer/ConferenceEmailTemplatesTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Organizer/ConferenceEmailTemplatesTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\EmailTemplateKey;
use App\Enums\OrganizationRole;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceEmailTemplates;
use App\Models\Conference;
use App\Models\EmailTemplate;
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

    $this->conference = Conference::factory()->for($this->organization)->create(['name' => 'Alpha Annual Meeting']);
});

function templatesPage(Conference $conference): \Livewire\Features\SupportTesting\Testable
{
    return livewire(ConferenceEmailTemplates::class, ['record' => $conference->getRouteKey()]);
}

it('lists all eleven template keys of spec 5.9', function () {
    $page = templatesPage($this->conference)->assertOk();

    expect(EmailTemplateKey::cases())->toHaveCount(11);

    foreach (EmailTemplateKey::cases() as $key) {
        $page->assertSee($key->getLabel());
    }
});

it('marks each key as a platform default until it is overridden', function () {
    templatesPage($this->conference)
        ->assertSee('Platform default')
        ->assertDontSee('Customised');

    EmailTemplate::factory()->for($this->conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Our own subject',
        'body' => 'Our own body',
    ]);

    templatesPage($this->conference)
        ->assertSee('Customised')
        ->assertSee('Platform default');
});

it('shows the platform default subject in the row', function () {
    templatesPage($this->conference)
        ->assertSee('Abstract {{reference}} received');
});

it('edits a template and stores it against this conference only', function () {
    // callTableAction($name, $record, $data): the second argument is the record
    // *key*, which for an array-backed table is the key the collection is keyed
    // by (fact 10) - here the template key itself.
    templatesPage($this->conference)
        ->callTableAction('edit', EmailTemplateKey::SubmissionReceived->value, [
            'subject' => 'We have your abstract, {{author_name}}',
            'body' => 'Dear {{author_name}}, your reference is {{reference}}.',
        ])
        ->assertHasNoActionErrors();

    $template = EmailTemplate::query()->firstOrFail();

    expect($template->conference_id)->toBe($this->conference->id)
        ->and($template->key)->toBe('submission_received')
        ->and($template->subject)->toBe('We have your abstract, {{author_name}}');
});

it('refuses a subject or body that is empty', function () {
    templatesPage($this->conference)
        ->callTableAction('edit', EmailTemplateKey::SubmissionReceived->value, ['subject' => '', 'body' => ''])
        ->assertHasActionErrors(['subject', 'body']);

    expect(EmailTemplate::query()->count())->toBe(0);
});

it('warns about a placeholder the key does not declare', function () {
    templatesPage($this->conference)
        ->callTableAction('edit', EmailTemplateKey::SubmissionReceived->value, [
            'subject' => 'Hello {{reviewer_name}}',
            'body' => 'Body',
        ])
        ->assertHasActionErrors(['subject']);
});

it('resets a template back to the platform default by deleting the override', function () {
    EmailTemplate::factory()->for($this->conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Our own subject',
        'body' => 'Our own body',
    ]);

    templatesPage($this->conference)
        ->callTableAction('reset', EmailTemplateKey::SubmissionReceived->value)
        ->assertHasNoActionErrors();

    expect(EmailTemplate::query()->count())->toBe(0);

    templatesPage($this->conference)->assertDontSee('Our own subject');
});

it('offers neither edit nor reset for the two platform-wide keys', function () {
    foreach ([EmailTemplateKey::OrganizationApproved, EmailTemplateKey::OrganizationRejected] as $key) {
        templatesPage($this->conference)
            ->assertTableActionHidden('edit', $key->value)
            ->assertTableActionHidden('reset', $key->value);
    }

    templatesPage($this->conference)
        ->assertTableActionVisible('edit', EmailTemplateKey::SubmissionReceived->value)
        ->assertSee('Platform-wide');
});

it('previews the finished email with sample values', function () {
    EmailTemplate::factory()->for($this->conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Abstract {{reference}}',
        'body' => 'Dear {{author_name}}, **{{title}}** is received.',
    ]);

    templatesPage($this->conference)
        ->mountTableAction('edit', EmailTemplateKey::SubmissionReceived->value)
        // The sample values of EmailTemplateKey::sampleValues(), so the
        // organizer reads a finished sentence instead of braces.
        ->assertSee('GPCC26-017')
        ->assertSee('Dr Sara Al-Harbi')
        // The legend of what they may use here.
        ->assertSee('{{status_link}}');
});

it('is invisible and unreachable from another organization', function () {
    $theirs = withoutTenant(fn () => Conference::factory()->create());

    // Through the route, not livewire(): Filament's resolveRecord() throws
    // ModelNotFoundException, which Livewire's test harness rethrows instead of
    // rendering (RequestBroker disables exception handling for everything but
    // HttpException and AuthorizationException), so only a real request turns
    // it into the 404 a stranger sees.
    get(ConferenceResource::getUrl('emails', ['record' => $theirs->getRouteKey()], tenant: $this->organization))
        ->assertNotFound();
});

it('lets a plain member edit templates, because spec section 4 says so', function () {
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    actingAs($member);

    templatesPage($this->conference)
        ->callTableAction('edit', EmailTemplateKey::SubmissionDraftSaved->value, [
            'subject' => 'Member subject',
            'body' => 'Member body',
        ])
        ->assertHasNoActionErrors();

    expect(EmailTemplate::query()->where('key', 'submission_draft_saved')->exists())->toBeTrue();
});

it('is linked from the conference view page', function () {
    livewire(App\Filament\Organizer\Resources\Conferences\Pages\ViewConference::class, [
        'record' => $this->conference->getRouteKey(),
    ])
        ->assertOk()
        ->assertSee(ConferenceResource::getUrl('emails', ['record' => $this->conference]), escape: false);
});

it('refuses a link placeholder in a subject line', function () {
    // {{status_link}} is a legal *body* placeholder for this key and an illegal
    // subject one: a rendered subject is stored in email_logs.subject, listed
    // in the admin panel and carried in a clear-text SMTP header, so it must
    // never be able to hold the author's bearer token.
    templatesPage($this->conference)
        ->callTableAction('edit', EmailTemplateKey::SubmissionDraftSaved->value, [
            'subject' => 'Your abstract {{status_link}}',
            'body' => 'Open {{status_link}} to edit it.',
        ])
        ->assertHasActionErrors(['subject']);

    expect(EmailTemplate::query()->where('key', 'submission_draft_saved')->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Organizer/ConferenceEmailTemplatesTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found" /tmp/t.log | head -3
```

Expected: `rc=1`, `Class "App\Filament\Organizer\Resources\Conferences\Pages\ConferenceEmailTemplates" not found`.

- [ ] **Step 3: Write the page**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Actions\Mail\RenderEmailTemplate;
use App\Actions\Mail\ResetEmailTemplate;
use App\Actions\Mail\SaveEmailTemplate;
use App\Enums\EmailTemplateKey;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Models\Conference;
use App\Models\EmailTemplate;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * All eleven template keys of spec 5.9, with their state, an editor, a live
 * preview and a reset.
 *
 * The table is **array-backed** (fact 10): `Table::records()` takes a closure,
 * Filament keys each array record by `ArrayRecord::getKeyName()` or by the
 * collection key, and the row actions receive `array $record`. That is what
 * makes it possible to list eleven keys when `email_templates` contains none -
 * the database holds overrides, not templates.
 */
class ConferenceEmailTemplates extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ConferenceResource::class;

    protected string $view = 'filament.organizer.pages.email-templates';

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
        return 'Email templates';
    }

    public function getSubheading(): ?string
    {
        return 'These are the emails CASS sends about this conference. Leave one alone and it uses the CASS wording; edit it and this conference uses yours.';
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
                TextColumn::make('label')->label('Email')->weight('semibold')
                    ->description(fn (array $record): string => $record['subject']),
                TextColumn::make('state')->label('Wording')->badge()
                    ->color(fn (array $record): string => match ($record['state']) {
                        'Customised' => 'success',
                        'Platform-wide' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('sends')->label('Sent when')->wrap(),
            ])
            ->recordActions([
                $this->editAction(),
                $this->resetAction(),
            ])
            ->paginated(false)
            ->emptyStateHeading('No templates');
    }

    /**
     * One array record per key. Keyed by the key itself, so
     * HasRecords::getTableRecords() stamps `__key` with it and every row action
     * receives an argument the enum can be rebuilt from.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function rows(): Collection
    {
        $render = app(RenderEmailTemplate::class);
        $conference = $this->getConference();
        $overrides = $conference->emailTemplates()->pluck('key')->all();

        return collect(EmailTemplateKey::cases())
            ->mapWithKeys(function (EmailTemplateKey $key) use ($render, $conference, $overrides): array {
                $template = $render->template($key, $conference);

                return [$key->value => [
                    'key' => $key->value,
                    'label' => $key->getLabel(),
                    'subject' => $template->subject,
                    'body' => $template->body,
                    'state' => match (true) {
                        ! $key->isConferenceScoped() => 'Platform-wide',
                        in_array($key->value, $overrides, true) => 'Customised',
                        default => 'Platform default',
                    },
                    'sends' => self::sendsWhen($key),
                ]];
            });
    }

    private function editAction(): Action
    {
        return Action::make('edit')
            ->label('Edit')
            ->icon(Heroicon::OutlinedEnvelopeOpen)
            ->visible(fn (array $record): bool => EmailTemplateKey::from($record['key'])->isConferenceScoped()
                && Gate::allows('create', EmailTemplate::class))
            ->modalHeading(fn (array $record): string => 'Edit: '.$record['label'])
            ->modalWidth('4xl')
            ->fillForm(fn (array $record): array => [
                'subject' => $record['subject'],
                'body' => $record['body'],
            ])
            ->schema(fn (array $record): array => [
                Section::make('Placeholders')
                    ->description('Type these exactly as shown. Anything else is left in the email as you typed it, which is how a typo shows up here instead of in an author\'s inbox.')
                    ->collapsible()
                    ->components([
                        Placeholder::make('legend')
                            ->hiddenLabel()
                            ->content(new HtmlString(collect(EmailTemplateKey::from($record['key'])->placeholders())
                                ->map(fn (string $name): string => '<code>{{'.e($name).'}}</code>')
                                ->implode(' '))),
                    ]),

                TextInput::make('subject')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->helperText('A link cannot go in a subject line.')
                    // Wrapped in a closure that *returns* the rule. Filament
                    // evaluates a Closure rule
                    // (Forms\Components\Concerns\CanBeValidated::getRules() does
                    // `$rule = $this->evaluate($rule)`), so an unwrapped custom
                    // rule is called by the closure evaluator instead of by the
                    // validator - and its first parameter, `string $attribute`,
                    // is unresolvable, so the modal throws
                    // BindingResolutionException the moment it validates. This
                    // is the idiom Filament's own code uses.
                    ->rule(fn (): Closure => $this->placeholderRule($record['key'], subject: true)),

                Textarea::make('body')
                    ->required()
                    ->rows(14)
                    ->maxLength(10000)
                    ->live(onBlur: true)
                    ->helperText('Markdown: **bold**, _italic_, - lists, and [links](https://example.org). Raw HTML is removed.')
                    ->rule(fn (): Closure => $this->placeholderRule($record['key'])),

                Section::make('Preview')
                    ->description('With sample values, in the layout the recipient sees.')
                    ->components([
                        Placeholder::make('preview')
                            ->hiddenLabel()
                            ->content(fn (Get $get): HtmlString => $this->preview(
                                EmailTemplateKey::from($record['key']),
                                (string) $get('subject'),
                                (string) $get('body'),
                            )),
                    ]),
            ])
            ->action(function (array $record, array $data, SaveEmailTemplate $save): void {
                Gate::authorize('create', EmailTemplate::class);

                $save->handle(
                    $this->getConference(),
                    EmailTemplateKey::from($record['key']),
                    (string) $data['subject'],
                    (string) $data['body'],
                );

                Notification::make()->success()->title('Template saved')
                    ->body('This conference now uses your wording for '.e($record['label']).'.')
                    ->send();
            });
    }

    private function resetAction(): Action
    {
        return Action::make('reset')
            ->label('Reset to default')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Reset to the CASS wording?')
            ->modalDescription('Your version of this email is deleted and the conference goes back to the platform default. This cannot be undone.')
            ->visible(fn (array $record): bool => $record['state'] === 'Customised'
                && Gate::allows('deleteAny', EmailTemplate::class))
            ->action(function (array $record, ResetEmailTemplate $reset): void {
                Gate::authorize('deleteAny', EmailTemplate::class);

                $reset->handle($this->getConference(), EmailTemplateKey::from($record['key']));

                Notification::make()->success()->title('Back to the CASS wording')->send();
            });
    }

    /**
     * A placeholder the key does not declare would render literally in a real
     * email. Reject it here, where the organizer can still see why.
     *
     * `$subject` narrows the list to EmailTemplateKey::subjectPlaceholders(),
     * which drops `status_link` and `review_link`: a rendered subject is stored
     * verbatim in `email_logs.subject`, listed in the admin panel and carried in
     * a clear-text SMTP header, so a bearer credential must not be
     * substitutable into one.
     */
    private function placeholderRule(string $key, bool $subject = false): Closure
    {
        $allowed = $subject
            ? EmailTemplateKey::from($key)->subjectPlaceholders()
            : EmailTemplateKey::from($key)->placeholders();

        return static function (string $attribute, mixed $value, Closure $fail) use ($allowed): void {
            preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', (string) $value, $matches);

            $unknown = array_values(array_unique(array_diff($matches[1], $allowed)));

            if ($unknown !== []) {
                $fail('This email does not have '.implode(', ', array_map(fn (string $n): string => '{{'.$n.'}}', $unknown)).'. Use only the placeholders listed above.');
            }
        };
    }

    /**
     * The preview renders the text that is **on screen**, not the text that is
     * stored - RenderEmailTemplate::handle() reads the database, and a preview
     * of the saved version while the organizer is typing is a preview that
     * lies. It reaches the same substitution and the same escaping through
     * RenderEmailTemplate::fill(), so the two cannot drift.
     */
    private function preview(EmailTemplateKey $key, string $subject, string $body): HtmlString
    {
        $render = app(RenderEmailTemplate::class);
        $values = $key->sampleValues();

        return new HtmlString(
            '<p style="font-weight:600;margin-bottom:.75rem">'.e($render->fill($subject, $values, escape: false)).'</p>'
            // str_replace, exactly as RenderEmailTemplate::renderBody() does it,
            // so raw HTML an organizer types is inert in the preview for the
            // same reason it is inert in the delivered email.
            .Markdown::parse(str_replace('<', '&lt;', $render->fill($body, $values, escape: true)))->toHtml()
        );
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToConference')
                ->label('Back to the conference')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(fn (): string => ConferenceResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    private static function sendsWhen(EmailTemplateKey $key): string
    {
        return match ($key) {
            EmailTemplateKey::SubmissionReceived => 'To the corresponding author when an abstract is submitted.',
            EmailTemplateKey::SubmissionDraftSaved => 'To the corresponding author when a draft is saved, and when you resend a status link.',
            EmailTemplateKey::ReviewerInvitation => 'To a reviewer you invite. Not sent yet — reviewing arrives in a later release.',
            EmailTemplateKey::ReviewerReminder => 'To reviewers with outstanding work, 7, 3 and 1 days before the review deadline. Not sent yet.',
            EmailTemplateKey::ReviewerOverdue => 'To reviewers once the review deadline has passed. Not sent yet.',
            EmailTemplateKey::DecisionAcceptedOral,
            EmailTemplateKey::DecisionAcceptedPoster,
            EmailTemplateKey::DecisionWaitlisted,
            EmailTemplateKey::DecisionRejected => 'When you send decision emails. Not sent yet — decisions arrive in a later release.',
            EmailTemplateKey::OrganizationApproved,
            EmailTemplateKey::OrganizationRejected => 'Sent by the platform when an organization is approved or rejected. Not editable per conference.',
        };
    }
}
```

The page's `preview()` needs `RenderEmailTemplate` to expose its substitution. In `app/Actions/Mail/RenderEmailTemplate.php`, change the visibility and the name of the existing private `substitute()` to a public `fill()` — the body is unchanged — and update the two call sites in `renderSubject()` and `renderBody()`:

```php
    /**
     * Public so the template editor can preview text the organizer has typed
     * but not yet saved. handle() renders what is *stored*; a preview has to
     * render what is *on screen*, and re-implementing the substitution rules in
     * the editor is exactly how a preview starts lying about what will be sent.
     *
     * @param  array<string, string|null>  $values
     */
    public function fill(string $text, array $values, bool $escape): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            function (array $match) use ($values, $escape): string {
                $name = $match[1];

                if (! isset($values[$name])) {
                    return $match[0];
                }

                $value = (string) $values[$name];

                return $escape ? strtr($value, self::VALUE_ESCAPES) : $value;
            },
            $text,
        );
    }
```

```php
        $rendered = $this->fill($subject, $values, escape: false);
```

```php
        return str_replace('<', '&lt;', $this->fill($body, $values, escape: true));
```

`tests/Unit/RenderEmailTemplateTest.php` is unchanged: it exercises `handle()`, which still routes through `fill()`, so the rename is covered by the tests that already exist rather than by new ones.

- [ ] **Step 4: Write `resources/views/filament/organizer/pages/email-templates.blade.php`**

```blade
<x-filament-panels::page>
    {{-- The panel loads no Tailwind utilities (backlog: the organizer theme),
         so spacing here is an inline style, the same way ConferenceShortLink
         does it. --}}
    <div style="display:flex;flex-direction:column;gap:1.5rem">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
```

- [ ] **Step 5: Register the page and link it**

`ConferenceResource::getPages()` gains one entry, between `short-link` and `edit`:

```php
            'emails' => ConferenceEmailTemplates::route('/{record}/emails'),
```

with the import `use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceEmailTemplates;`.

`ConferenceStatusActions` gains a sibling of `share()`, and `all()` includes it so the view, edit and table rows all offer it:

```php
    public static function emails(): Action
    {
        return Action::make('emails')
            ->label('Email templates')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->visible(fn (Conference $record): bool => Gate::allows('view', $record))
            ->url(fn (Conference $record): string => ConferenceResource::getUrl('emails', ['record' => $record]));
    }
```

```php
    /** @return list<Action> */
    public static function all(): array
    {
        return [static::share(), static::emails(), static::publish(), static::close(), static::archive()];
    }
```

`ConferencesTable::configure()` adds `ConferenceStatusActions::emails(),` to its `recordActions()` list, next to `share()`.

- [ ] **Step 6: Run the tests, Pint, Larastan, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && \
php artisan test tests/Feature/Organizer > /tmp/t.log 2>&1; echo "organizer rc=$?"; tail -4 /tmp/t.log
```

Expected: `organizer rc=0`.

If every edit case dies with `BindingResolutionException` naming `$attribute`, a `->rule()` is not wrapped in a closure that returns the rule: Filament evaluates a `Closure` rule, so a bare custom rule is called by the closure evaluator instead of by the validator.

If the very first save throws `MassAssignmentException` for `key`, `SaveEmailTemplate` is back on `firstOrNew(['key' => ...])` — which mass-assigns a column that is deliberately not fillable.

If `callTableAction('edit', '<key>')` cannot find the row, the array records are not keyed the way `HasRecords::getTableRecords()` expects: `rows()` must return a collection **keyed by the template key string**, because that is what becomes `__key` and therefore the record key a table action is addressed by. Do not "fix" it by adding a `__key` entry to each array *and* keying the collection — one or the other.

```bash
cd /c/Users/ahmed/Documents/CASS && \
./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?"; \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log
```

Expected: `stan rc=0`, `tests rc=0`, `441 passed`.

```bash
cd /c/Users/ahmed/Documents/CASS && git add -A && git commit -q -m "feat(organizer): per-conference email templates with preview and reset

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

---

### Task 11: The platform admin's email log, and submission counts on the admin conference list

Spec 5.9 ("logged to `email_logs` with status and error") needs somewhere to read the log, and spec section 4 gives the platform admin "see all organizations and conferences". Both are read-only.

**Files:**
- Create: `app/Filament/Admin/Resources/EmailLogs/EmailLogResource.php`, `Pages/ListEmailLogs.php`, `Pages/ViewEmailLog.php`, `Schemas/EmailLogInfolist.php`, `Tables/EmailLogsTable.php`
- Modify: `app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php`
- Test: `tests/Feature/Admin/EmailLogsTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/EmailLogsTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\EmailLogStatus;
use App\Enums\OrganizationRole;
use App\Filament\Admin\Resources\EmailLogs\EmailLogResource;
use App\Filament\Admin\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Filament\Admin\Resources\EmailLogs\Pages\ViewEmailLog;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_platform_admin' => true]);
    actingAs($this->admin);
    // Both, in this order, exactly as tests/Feature/Admin/ConferencesTest.php
    // and OrganizationApprovalTest.php do it. bootCurrentPanel() is what runs
    // Panel::boot() - tenancy observers, assets, colors, icons, SPA config,
    // render hooks and plugins - so a page tested without it is not the page a
    // real request renders.
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Gulf Pediatric Society']);
    $this->conference = Conference::factory()->for($this->organization)->published()->create();
});

it('lists every tenant log row, newest first', function () {
    $old = EmailLog::factory()->sent()->create(['to_email' => 'first@example.org', 'created_at' => now()->subDay()]);
    $new = EmailLog::factory()->for($this->organization)->for($this->conference)->create(['to_email' => 'second@example.org']);

    livewire(ListEmailLogs::class)
        ->assertCanSeeTableRecords([$old, $new])
        ->assertCanRenderTableColumn('to_email')
        ->assertCanRenderTableColumn('status')
        ->assertCanRenderTableColumn('mailable')
        ->assertSee('Gulf Pediatric Society');
});

it('filters by status and by organization and searches by recipient', function () {
    $failed = EmailLog::factory()->failed()->create(['to_email' => 'bounced@example.org']);
    $sent = EmailLog::factory()->sent()->for($this->organization)->create(['to_email' => 'fine@example.org']);

    livewire(ListEmailLogs::class)
        ->filterTable('status', EmailLogStatus::Failed->value)
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$sent]);

    livewire(ListEmailLogs::class)
        ->filterTable('organization_id', $this->organization->id)
        ->assertCanSeeTableRecords([$sent])
        ->assertCanNotSeeTableRecords([$failed]);

    livewire(ListEmailLogs::class)
        ->searchTable('bounced@example.org')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$sent]);
});

it('shows the error text and the context on the view page', function () {
    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    $log = EmailLog::factory()
        ->failed('Expected response code "250" but got code "550", with message "550 5.1.1 User unknown"')
        ->for($this->organization)
        ->for($this->conference)
        ->for($submission)
        ->create(['to_email' => 'nobody@example.org', 'template_key' => 'submission_received']);

    livewire(ViewEmailLog::class, ['record' => $log->getRouteKey()])
        ->assertOk()
        ->assertSee('nobody@example.org')
        ->assertSee('550 5.1.1 User unknown')
        ->assertSee('submission_received')
        ->assertSee('Gulf Pediatric Society');
});

it('is read-only: no create, edit or delete anywhere', function () {
    $log = EmailLog::factory()->create();
    $policy = app(App\Policies\EmailLogPolicy::class);

    expect(EmailLogResource::getPages())->toHaveKeys(['index', 'view'])
        ->and(EmailLogResource::getPages())->not->toHaveKey('create')
        ->and(EmailLogResource::getPages())->not->toHaveKey('edit')
        ->and($policy->create($this->admin))->toBeFalse()
        ->and($policy->update($this->admin, $log))->toBeFalse()
        ->and($policy->delete($this->admin, $log))->toBeFalse()
        ->and($policy->deleteAny($this->admin))->toBeFalse()
        ->and($policy->restoreAny($this->admin))->toBeFalse()
        ->and($policy->forceDeleteAny($this->admin))->toBeFalse();
});

it('is invisible to anyone who is not a platform admin', function () {
    $organizer = User::factory()->create();
    $this->organization->addMember($organizer, OrganizationRole::Owner);
    actingAs($organizer);

    expect(EmailLogResource::canAccess())->toBeFalse()
        ->and(app(App\Policies\EmailLogPolicy::class)->viewAny($organizer))->toBeFalse();

    // And the route itself, not just the navigation item.
    $this->get(EmailLogResource::getUrl('index', panel: 'admin'))->assertForbidden();
});

it('counts submissions on the admin conference list', function () {
    Submission::factory()->count(3)->for($this->conference)->submitted()->create();

    livewire(App\Filament\Admin\Resources\Conferences\Pages\ListConferences::class)
        ->assertCanRenderTableColumn('submissions_count')
        ->assertSee('3');
});
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/Admin/EmailLogsTest.php > /tmp/t.log 2>&1; echo "rc=$?"; grep -E "Error|not found" /tmp/t.log | head -3
```

Expected: `rc=1`, `Class "App\Filament\Admin\Resources\EmailLogs\EmailLogResource" not found`.

- [ ] **Step 3: Write the resource**

`app/Filament/Admin/Resources/EmailLogs/EmailLogResource.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmailLogs;

use App\Filament\Admin\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Filament\Admin\Resources\EmailLogs\Pages\ViewEmailLog;
use App\Filament\Admin\Resources\EmailLogs\Schemas\EmailLogInfolist;
use App\Filament\Admin\Resources\EmailLogs\Tables\EmailLogsTable;
use App\Models\EmailLog;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Spec 5.9: every outgoing message, with its status and its error. Read-only,
 * platform-admin only, and deliberately **not** tenant-scoped - a password
 * reset and an organization approval have no organization at all, and the
 * question this screen answers ("did that email go out?") is asked by the
 * person who runs the SMTP account.
 *
 * @extends \Filament\Resources\Resource<EmailLog>
 */
class EmailLogResource extends Resource
{
    protected static ?string $model = EmailLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $recordTitleAttribute = 'to_email';

    protected static ?string $navigationLabel = 'Email log';

    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->is_platform_admin;
    }

    public static function table(Table $table): Table
    {
        return EmailLogsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EmailLogInfolist::configure($schema);
    }

    /** @return Builder<EmailLog> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['organization', 'conference', 'submission']);
    }

    public static function getPages(): array
    {
        // index and view only. There is no create, no edit and no delete page,
        // and EmailLogPolicy answers false to every one of those abilities -
        // Filament treats a missing policy method as ALLOW (Plan 2 fact 7), so
        // both halves are needed.
        return [
            'index' => ListEmailLogs::route('/'),
            'view' => ViewEmailLog::route('/{record}'),
        ];
    }
}
```

`app/Filament/Admin/Resources/EmailLogs/Tables/EmailLogsTable.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmailLogs\Tables;

use App\Enums\EmailLogStatus;
use App\Models\EmailLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmailLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Queued')->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('to_email')->label('To')->searchable()->copyable(),
                TextColumn::make('subject')->limit(60)->wrap()->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('template_key')->label('Template')->placeholder('-')->toggleable(),
                // The class name without its namespace: the full one is 40
                // characters of App\Notifications\ in every row.
                TextColumn::make('mailable')->label('Sent by')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->tooltip(fn (EmailLog $record): string => (string) $record->mailable)
                    ->toggleable(),
                TextColumn::make('organization.name')->label('Organization')->placeholder('Platform')->toggleable(),
                TextColumn::make('sent_at')->label('Sent')->dateTime('j M Y, H:i')->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(EmailLogStatus::class)->multiple(),
                SelectFilter::make('organization_id')
                    ->label('Organization')
                    ->relationship('organization', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Nothing sent yet');
    }
}
```

`app/Filament/Admin/Resources/EmailLogs/Schemas/EmailLogInfolist.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmailLogs\Schemas;

use App\Enums\EmailLogStatus;
use App\Filament\Admin\Resources\Conferences\ConferenceResource;
use App\Models\Conference;
use App\Models\EmailLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmailLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Message')->columns(3)->components([
                TextEntry::make('to_email')->label('To')->copyable(),
                TextEntry::make('status')->badge(),
                TextEntry::make('template_key')->label('Template')->placeholder('-'),
                TextEntry::make('subject')->columnSpanFull(),
                TextEntry::make('mailable')->label('Sent by')->columnSpanFull(),
                TextEntry::make('ulid')->label('Correlation id')
                    ->helperText('Travels with the message as the X-CASS-Log header, so an SMTP log line can be matched to this row.')
                    ->copyable(),
            ]),

            Section::make('Context')->columns(3)->components([
                TextEntry::make('organization.name')->label('Organization')->placeholder('Platform-wide'),
                TextEntry::make('conference.name')->label('Conference')
                    ->placeholder('-')
                    ->url(fn (EmailLog $record): ?string => $record->conference instanceof Conference
                        ? ConferenceResource::getUrl('view', ['record' => $record->conference], panel: 'admin')
                        : null),
                TextEntry::make('submission.reference')->label('Submission')->placeholder('-'),
            ]),

            Section::make('Delivery')->columns(2)->components([
                TextEntry::make('created_at')->label('Queued')->dateTime('j M Y, H:i:s'),
                TextEntry::make('sent_at')->label('Sent')->dateTime('j M Y, H:i:s')->placeholder('Not sent'),
                TextEntry::make('error')
                    ->columnSpanFull()
                    ->color('danger')
                    // Raw SMTP text, shown as escaped text and never as markup.
                    ->prose()
                    ->visible(fn (EmailLog $record): bool => $record->status === EmailLogStatus::Failed)
                    ->placeholder('-'),
            ]),
        ]);
    }
}
```

`app/Filament/Admin/Resources/EmailLogs/Pages/ListEmailLogs.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmailLogs\Pages;

use App\Filament\Admin\Resources\EmailLogs\EmailLogResource;
use Filament\Resources\Pages\ListRecords;

class ListEmailLogs extends ListRecords
{
    protected static string $resource = EmailLogResource::class;

    /** Read-only: no create action. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
```

`app/Filament/Admin/Resources/EmailLogs/Pages/ViewEmailLog.php`
```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmailLogs\Pages;

use App\Filament\Admin\Resources\EmailLogs\EmailLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEmailLog extends ViewRecord
{
    protected static string $resource = EmailLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
```

- [ ] **Step 4: Add the count column to the admin conference list**

In `app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php`, add after the `status` column:

```php
                TextColumn::make('submissions_count')
                    ->counts('submissions')
                    ->label('Abstracts')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
```

`->counts()` adds a `withCount` to the query, so this costs one sub-select and no N+1.

- [ ] **Step 5: Run the tests, Pint, Larastan, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && \
php artisan test tests/Feature/Admin > /tmp/t.log 2>&1; echo "admin rc=$?"; tail -4 /tmp/t.log && \
./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?"; \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log
```

Expected: `admin rc=0`, `stan rc=0`, `tests rc=0`, `447 passed`.

```bash
cd /c/Users/ahmed/Documents/CASS && git add -A && git commit -q -m "feat(admin): read-only email log and submission counts on the conference list

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

---

### Task 12: The language sweep, the environment file and the runbook

Spec section 10: "Locale `en` only in v1, with all strings in language files so Arabic can be added without code changes." Tasks 3, 6, 7 and 8 put their strings in `lang/en/*.php` as they went; this task is the audit that proves nothing was missed, plus the operational documentation the deploy actually needs.

Plans 1 and 2 hardcoded English in the landing page, the conference page, the countdown script and the poster. **That stays a backlog item**: rewriting Plan 2's views here would mean re-testing Plan 2's assertions in Plan 3's branch, and the backlog entry already names it as the prerequisite for any Arabic work. What this task guarantees is that **Plan 3 added nothing to that debt**.

**Files:**
- Modify: `lang/en/submission.php`, `lang/en/mail.php`, `.env.example`, `docs/runbooks/deploy-production.md`, `docker/nginx.conf`
- Test: `tests/Feature/LanguageCoverageTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/LanguageCoverageTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\EmailTemplateKey;

/**
 * Spec section 10. This is the test that makes "add Arabic by copying lang/en"
 * true rather than aspirational: it reads the files Plan 3 wrote, pulls every
 * __('submission.*') and __('mail.*') key out of them, and fails on any key
 * that does not resolve. Laravel returns the key itself for a miss, which
 * renders as `submission.fields.title` in a label and passes every other test
 * in the suite.
 */

/** Every file Plan 3 added or rewrote that may contain a translated string. */
$plan3Sources = [
    'resources/views/livewire/public/submission-form.blade.php',
    'resources/views/livewire/public/submission-status.blade.php',
    'resources/views/livewire/public/partials/authors.blade.php',
    'resources/views/livewire/public/partials/custom-fields.blade.php',
    'resources/views/livewire/public/partials/files.blade.php',
    'app/Livewire/Public/SubmissionForm.php',
    'app/Livewire/Public/SubmissionStatus.php',
];

it('resolves every translation key plan 3 uses', function () use ($plan3Sources) {
    $missing = [];

    foreach ($plan3Sources as $relative) {
        $path = base_path($relative);

        expect(file_exists($path))->toBeTrue("Expected {$relative} to exist.");

        preg_match_all("/__\\(\\s*'((?:submission|mail)\\.[a-z0-9_.]+)'/", (string) file_get_contents($path), $matches);

        foreach (array_unique($matches[1]) as $key) {
            if (! Lang::has($key)) {
                $missing[] = "{$key} (used in {$relative})";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('has a subject and a body for every template key', function () {
    foreach (EmailTemplateKey::cases() as $key) {
        expect(Lang::has('mail.templates.'.$key->value.'.subject'))->toBeTrue("mail.templates.{$key->value}.subject is missing")
            ->and(Lang::has('mail.templates.'.$key->value.'.body'))->toBeTrue("mail.templates.{$key->value}.body is missing");
    }
});

it('leaves no visible english hardcoded in the pages plan 3 added', function () use ($plan3Sources) {
    // Do NOT strip directives and tags with hand-written regexes. All three
    // failure shapes are in this plan's own views: `@php use
    // App\Enums\SubmissionWindow; @endphp` has no parentheses for a
    // `/@[a-z]+\s*\(.*?\)/` to match, `@foreach ((array) ($field->options ??
    // []) as $option)` defeats a non-greedy `.*?\)` because the first `)`
    // closes `(array`, and `:class="words > limit ? '…' : '…'"` ends a
    // `/<[^>]+>/` early on the `>` inside the attribute. Each of those leaves a
    // three-or-more-word "text node" that no language key can ever fix, which
    // would leave this task unable to reach rc=0 and the implementer deleting
    // the one test that proves spec section 10.
    //
    // So let Blade's own compiler turn every directive and echo into PHP, drop
    // the PHP and the script/style bodies (code, not prose), and let
    // strip_tags() - which tracks quotes, so an Alpine expression containing
    // `>` survives - leave only the text the page really prints.
    $allowed = ['KB', 'MB', 'PDF'];
    $offenders = [];

    foreach ($plan3Sources as $relative) {
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
```

`use Illuminate\Support\Facades\Blade;` and `use Illuminate\Support\Facades\Lang;` go at the top of the file with the `EmailTemplateKey` import.

- [ ] **Step 2: Run it and fix what it finds**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test tests/Feature/LanguageCoverageTest.php > /tmp/t.log 2>&1; echo "rc=$?"; sed -n '/Failed asserting/,+12p' /tmp/t.log | head -30
```

Expected on the first run: a list of keys used by the views but not yet in `lang/en/submission.php`, and possibly a hardcoded sentence the third case caught. Two different fixes, and only these two:

- **A missing key.** Add it to `lang/en/submission.php`, under the group its name says, with the English text that was in the view.
- **A flagged text node.** Move the sentence into `lang/en/submission.php` and echo it with `{{ __('…') }}`.

Never widen `$allowed` and never weaken the sweep to silence a finding: `$allowed` is for `KB`, `MB` and `PDF` — things that are not prose — and the sweep is the only thing standing behind spec section 10. Re-run until `rc=0`.

- [ ] **Step 3: Confirm `lang/en/submission.php` is complete**

After Step 2 the file should contain every group the four tasks introduced. Read it once and check the shape against this list; anything here that is missing is a key a view is not using and should be deleted rather than kept:

- `page.eyebrow`
- `window.{upcoming,closed,not_configured,back}`
- `preview.{badge,body}`
- `sections.{abstract,authors,contact,custom_fields,files,agreement}`
- `fields.{title,abstract,words,track,track_none,presentation_preference,contact_phone,contact_phone_help,choose,agreed,honeypot}`
- `authors.{add,remove,help,name,email,affiliation,is_presenter,is_corresponding,name_of,email_of}`
- `files.{label,limits,remove,confirm_delete,uploading}`
- `buttons.{submit,save_draft,working,draft_help}`
- `errors.{word_limit,window_closed,preview_readonly,too_fast,too_many,turnstile}`
- `flash.{draft_saved,draft_updated,submitted}`
- `status.{reference,state,deadline,edit,cancel_edit,withdraw,confirm_withdraw,withdrawn_flash,withdrawn_notice,draft_warning,decision_pending,until_deadline,keep_link}`

```bash
cd /c/Users/ahmed/Documents/CASS && php -r 'var_export(array_keys(require "lang/en/submission.php"));' && echo && \
php -r '$k = require "lang/en/mail.php"; echo count($k["templates"]), " template defaults", PHP_EOL;'
```

Expected: the eleven group names above, then `11 template defaults`.

- [ ] **Step 4: Finish `.env.example`**

Every variable Plan 3 reads must be in it (spec section 9). After Tasks 4 and 7 it should already have `CASS_MAX_FILE_BYTES`, `CASS_FILE_URL_MINUTES`, `CASS_STATUS_PAGE_RATE_LIMIT`, `CASS_SUBMISSION_RATE_LIMIT`, `CASS_SUBMISSION_MIN_SECONDS` and `CASS_UPLOAD_RATE_LIMIT`, and `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` have been there since Plan 1. Replace the two bare Turnstile lines with lines that say what they are for:

```
# Cloudflare Turnstile on the public submission form. Leave both empty and the
# widget is absent, no verification call is made, and the honeypot, the minimum
# fill time and the per-IP throttle are the whole of the bot protection. Set
# both (Cloudflare dashboard -> Turnstile -> add a widget for cass.towardpcc.com)
# and the widget appears and is verified server-side.
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
```

Then prove nothing is missing:

```bash
cd /c/Users/ahmed/Documents/CASS && \
grep -ohrE "env\('(CASS_[A-Z0-9_]+|TURNSTILE_[A-Z0-9_]+|TRUSTED_PROXIES)'" config/ bootstrap/ | sed -E "s/env\('//;s/'//" | sort -u > /tmp/used.txt && \
grep -oE '^#? *[A-Z0-9_]+=' .env.example | tr -d '#= ' | sort -u > /tmp/declared.txt && \
comm -23 /tmp/used.txt /tmp/declared.txt
```

Expected: no output — `comm -23` prints the variables this application reads that `.env.example` does not declare, and there should be none. Three narrowings, every one of them load-bearing:

- **Only `CASS_*`, `TURNSTILE_*` and `TRUSTED_PROXIES`.** The rest of `config/` is Laravel's own, and an unfiltered scan prints about ninety driver variables (`AUTH_GUARD`, `REDIS_QUEUE`, `SQS_SUFFIX`, `AWS_ENDPOINT`, `SESSION_SAME_SITE`, `LOG_DAILY_DAYS` …) that the framework defaults and that the Laravel skeleton deliberately leaves out of `.env.example`. Run it unfiltered and it prints 88 lines here: noise, not a finding. Spec section 9's "`.env.example` lists every variable" means every variable *this* application reads.
- **`bootstrap/` as well as `config/`.** `TRUSTED_PROXIES` is read in `bootstrap/app.php`, not in a config file, and it is exactly the kind of variable this check exists for. `grep -rn "env(" app/ routes/` finds nothing, so those two directories are the whole surface.
- **`#`-commented lines count as declared.** `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` ship commented out under `DB_CONNECTION=sqlite`; they are documented, which is what the requirement asks for.

`CASS_UPLOAD_RATE_LIMIT` (read in `config/livewire.php`, added by Task 7) is the one variable this plan puts outside `config/cass.php`, and the first filter catches it.

- [ ] **Step 5: Update `docs/runbooks/deploy-production.md`**

Extend the `## Every release` step 3 list of tables with Plan 3's, so the "until they exist, these pages 500" warning stays accurate:

````markdown
   Plan 3 is such a release: it adds five tables (`submissions`, `submission_authors`, `submission_files`, `email_templates`, `email_logs`) and two columns on `conferences` (`reference_prefix`, `submission_counter`). Until they exist, `/c/{org}/{conference}/submit`, `/s/{token}`, `/files/{ulid}`, the organizer submission list and the admin email log all return 500, **and every outgoing email fails**, because the mail listener writes an `email_logs` row before the message is sent.
````

Then replace the single-URL timing loop in `## Every release` step 5 with one that covers both pages spec section 10 puts on the 300 ms budget:

````markdown
   ```bash
   C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
   for U in "/c/<org>/<conference>" "/c/<org>/<conference>/submit"; do
     echo "$U"
     for i in 1 2 3 4 5; do
       sudo docker exec "$C" php -r '$c = stream_context_create(["http" => ["header" => "Host: cass.towardpcc.com\r\n"]]); $t = microtime(true); file_get_contents("http://127.0.0.1:8080".$argv[1], false, $c); printf("%.3f\n", microtime(true) - $t);' "$U"
     done
   done
   ```

   The same 0.300 s median for both. Spec section 10 puts the submission form on
   the same budget as the conference page, and it is the one page on that budget
   that is Livewire rather than plain Blade, so it is the one to re-measure after
   every change to the form.
````

Add these four sections after `## Poster PDF fonts`:

````markdown
## Cloudflare Turnstile

The public submission form carries a Turnstile widget only when **both**
`TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` are set in the Coolify
environment. With neither set, the form still has its honeypot, its four-second
minimum fill time and its 5/min/IP throttle, and no request is made to
Cloudflare.

To turn it on: Cloudflare dashboard → Turnstile → Add widget, hostname
`cass.towardpcc.com`, mode Managed. Copy the site key and the secret key into
Coolify, redeploy, and open the form as a logged-out visitor — the widget should
render above the "Submit abstract" button.

Verification **fails open** when Cloudflare itself is unreachable: a connection
error is logged at `warning` with the message "Turnstile verification could not
reach Cloudflare" and the submission is allowed. An explicit `success: false`
from Cloudflare, or a 5xx from it, is still a refusal. That choice is
deliberate — an outage at Cloudflare in the last hour before a deadline would
otherwise refuse every abstract — so if the log fills with that warning, treat
it as an incident rather than as noise.

## Author uploads and private storage

Submission files live on the `local` disk, whose root is
`storage/app/private`. `docker-compose.production.yml` mounts the named volume
`cass-storage` at `/var/www/html/storage/app`, so uploads are already inside it
and survive a redeploy. Nothing else needs mounting, and **nothing serves that
directory**: files are reachable only through `/files/{ulid}`, which requires a
signature that expires after `CASS_FILE_URL_MINUTES` (30).

Paths are content-addressed: `{first two characters of the sha256}/{ulid}.{ext}`.
Two submissions holding identical bytes still own separate objects, so deleting
one never breaks the other.

To see how much the volume holds:

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec "$C" du -sh storage/app/private
sudo docker exec "$C" sh -c 'find storage/app/private -type f | wc -l'
```

Back it up with the database, not separately: a file with no row is unreachable
and a row with no file is a broken link, so the two have to be restored from the
same moment.

The request-body ceilings are `client_max_body_size 110m` (nginx) and
`post_max_size=110M` (PHP), sized for the maximum ten files at 10 MB a
conference may allow in **one** Livewire upload POST — Livewire sends every file
of a `multiple` input in a single request. They live in the image, so raising a
conference's `max_files` above 10 would need a rebuild, not just a setting.

## Author status links

The 64 characters after `/s/` are a bearer credential: anyone holding them can
edit or withdraw that abstract until the deadline. nginx logs that path
redacted (see the `cass` log_format in `docker/nginx.conf`), but **Cloudflare
still sees the full URI** — do not enable Logpush for this zone without a
transform rule that strips it, and never paste a `/s/...` URL into a ticket.
To take a leaked link out of circulation, open the submission in the organizer
panel and use **Resend status link**, which rotates the token and kills the old
one.

## Email triage

Every message the application sends writes a row to `email_logs` — templated
conference mail, the member notice, the organization approval mail, password
resets and verification mail alike. The admin panel lists them at
`/admin/email-logs`, filterable by status and organization and searchable by
recipient.

The three statuses mean exactly this:

| Status | Meaning |
|---|---|
| `sent` | The transport accepted the message. Not the same as delivered — check the mailbox's own logs for a bounce. |
| `failed` | The queued job threw, and `error` holds the exception message. Only templated mail (`App\Mail\TemplatedMail`) can reach this state: it has a `failed()` hook. |
| `queued` | The row was written and the job has not reported back. A few seconds is normal. Hours is not. |

A row **stuck at `queued`** is either a stopped queue worker or a *notification*
that failed: `Illuminate\Notifications\Notification` has no per-message failure
hook, so a transport error on one leaves its row where it was. Check both:

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec "$C" supervisorctl status
sudo docker exec "$C" su-exec app php artisan queue:failed
```

Every delivered message carries an `X-CASS-Log` header holding the `ulid` of its
`email_logs` row, plus `X-CASS-Template`, `X-CASS-Organization` and (for a
submission) `X-CASS-Conference` and `X-CASS-Submission`. When the owner's
mailbox shows a bounce, that header is how the bounce is matched to a row —
search the log for the ulid rather than guessing from the subject line.

To re-send an author's link after a bounce is fixed, do **not** replay the queue
job: open the submission in the organizer panel and use **Resend status link**,
which mints a fresh token. The token in the bounced message is already dead.
````

- [ ] **Step 6: Keep the author's token out of the access log**

`docker/nginx.conf` logs with nginx's built-in `combined` format, which writes `$request` — method, **full URI** and protocol — verbatim to stdout, where Coolify collects it. `/s/{token}` puts a bearer credential in the path and `/files/{ulid}` carries a 30-minute signature in the query, so as it stands every author token in production is written to the container log stream.

Above the `server` block (the file is included from nginx's `http {}` context, so `map` and `log_format` are legal there):

```nginx
# The /s/ path segment IS the author's credential and a /files/ signature is a
# 30-minute one (spec section 9). Keep both out of the log stream Coolify
# collects; the status code and the client address are what triage needs.
map $request_uri $cass_logged_uri {
    "~^/s/[A-Za-z0-9]{64}"         "/s/[redacted]";
    "~^(?<cass_path>/files/[^?]*)" "$cass_path?[signed]";
    default                        $request_uri;
}

# Redacting the request line is only half of it. SecurityHeaders sends
# `Referrer-Policy: strict-origin-when-cross-origin`, which means a same-origin
# request carries the FULL referring URL - so every subresource the status page
# pulls and every /livewire/update POST it makes would otherwise write the same
# 64-character credential into this log, one field to the right. Only the /s/
# token is redacted here: an ordinary referer is triage data worth keeping.
map $http_referer $cass_logged_referer {
    ""                                              "-";
    "~*^(?<cass_ref>https?://[^/]+)/s/[a-z0-9]{64}" "$cass_ref/s/[redacted]";
    default                                         $http_referer;
}

log_format cass '$remote_addr - $remote_user [$time_local] '
                '"$request_method $cass_logged_uri $server_protocol" '
                '$status $body_bytes_sent "$cass_logged_referer" "$http_user_agent"';
```

and change `access_log /dev/stdout;` to `access_log /dev/stdout cass;`.

```bash
cd /c/Users/ahmed/Documents/CASS && MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd)/docker/nginx.conf:/etc/nginx/conf.d/default.conf:ro" nginx:alpine nginx -t 2>&1 | tail -3
```

Expected: `syntax is ok` and `test is successful`. Mount it at **`conf.d`**, not at `http.d`: the official `nginx:alpine` image includes only `/etc/nginx/conf.d/*.conf` and has no `/etc/nginx/http.d` directory at all, so a file mounted there is never read and `nginx -t` prints "syntax is ok" for the stock config however broken this file is (confirmed by mounting a deliberately broken copy at both paths). Production is the other way round — `apk add nginx` on Alpine includes `/etc/nginx/http.d/*.conf`, which is where `Dockerfile:39` copies it — but both are the same `http {}` context, so the `conf.d` mount parses the file exactly as production does. `MSYS_NO_PATHCONV=1` stops Git Bash rewriting the container-side path.

- [ ] **Step 7: Run the tests, Pint, Larastan, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log && \
./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?"
```

Expected: `tests rc=0`, `450 passed`, `stan rc=0`.

```bash
cd /c/Users/ahmed/Documents/CASS && git add -A && git commit -q -m "docs: language coverage test, runbook sections, redacted access log

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

---

### Task 13: One end-to-end browser run of the submission form, in CI

Spec section 12: "**Browser**: one end-to-end submission run through the public form (Pest browser plugin or Playwright) executed in CI." One test, one flow, the one that matters: an author lands on the conference page, clicks through, fills the form, agrees, submits, and reads a reference number on the status page.

**File uploads are deliberately out of scope for the browser test.** `Pest\Browser\Drivers\LaravelHttpServer::handleRequest()` builds the Laravel request with `Request::create($absoluteUrl, $method, $parameters, $cookies, [], $serverVariables, $rawBody)` — the files argument is a literal `[]` carrying a `// @TODO files...` comment (v5.0.1, line 257) — and it fills `$parameters` from the body only when the content type is `application/x-www-form-urlencoded` (line 244). Livewire's temporary-upload POST is `multipart/form-data`, so the file never reaches PHP: `/livewire/upload-file` would see zero files, the pending-upload list would never render, and the job would hang until the wait timed out. Attachments are proved instead by `tests/Feature/Public/SubmissionFileDownloadTest.php` in Task 4 (which drives `StoreSubmissionFile` with a real `UploadedFile` built from `tests/Fixtures/abstract.pdf`) and by `tests/Feature/Public/SubmissionFormTest.php` in Task 7 (which sets a real `UploadedFile` on `uploads` and asserts the stored row and the disk path). The conference in this test therefore sets `max_files => 0`, so the form renders no upload section at all.

**It does run on Windows, after one line of `php.ini`.** The plugin requires `ext-sockets`, which this machine's PHP ships as `php_sockets.dll` but leaves commented out (fact 2). Turning it on is Step 1. Once it is on, the local command in Step 7 works; if it ever does not, the CI job in Step 6 is the authoritative gate and nothing about the plan depends on the local run.

**The one thing that is genuinely awkward, and how it is handled.** The plugin boots Playwright from a *test-collection* hook, not from a test-run hook (fact 3): `UsesBrowserTestCaseMethodFilter::accept()` starts the server while Pest is loading each test file, before PHPUnit's group filtering. Tagging the test `->group('browser')` and excluding that group would therefore **still** start Playwright on every `php artisan test`. So `tests/Browser` is left out of `phpunit.xml` entirely and gets its own `phpunit.browser.xml`. The group tag is applied anyway, so `--group=browser` keeps working if the file is ever moved into the main suite.

**Files:**
- Create: `phpunit.browser.xml`, `tests/Browser/SubmitAbstractTest.php`
- Modify: `composer.json`/`composer.lock`, `package.json`/`package-lock.json`, `tests/Pest.php`, `.gitignore`, `.github/workflows/ci.yml`
- Modify: `resources/views/livewire/public/submission-form.blade.php` (one `id` and one `name` on the agreement checkbox)

- [ ] **Step 1: Turn on `ext-sockets`**

```bash
cd /c/Users/ahmed/Documents/CASS && \
grep -n '^;extension=sockets' /c/Users/ahmed/AppData/Local/php84/php.ini && \
sed -i 's/^;extension=sockets/extension=sockets/' /c/Users/ahmed/AppData/Local/php84/php.ini && \
php -r 'var_dump(extension_loaded("sockets"));'
```

Expected: the grep prints `944:;extension=sockets`, then `bool(true)`.

This is a change to the machine, not to the repository, and it is required: once the plugin is in `require-dev`, a plain `composer install` fails the platform check without it. Do **not** reach for `--ignore-platform-req=ext-sockets` instead — that flag would have to be repeated by every future `composer install` in every future session, and it would be recorded nowhere.

- [ ] **Step 2: Install the plugin and Playwright**

```bash
cd /c/Users/ahmed/Documents/CASS && \
php /c/Users/ahmed/AppData/Local/composer-bin/composer.phar require --dev pestphp/pest-plugin-browser:"^5" --no-interaction 2>&1 | tail -8
```

Expected: it installs `pestphp/pest-plugin-browser` v5.0.x plus the amphp packages (`amphp/amp`, `amphp/http-server`, `amphp/websocket-client` and their dependencies) and `symfony/process`. If it reports `requires ext-sockets * -> it is missing`, Step 1 did not take — check `php --ini` points at the file that was edited.

```bash
cd /c/Users/ahmed/Documents/CASS && \
npm i -D playwright 2>&1 | tail -3 && \
npx playwright install --with-deps chromium 2>&1 | tail -5 && \
ls node_modules/.bin | grep -i playwright
```

Expected: `playwright` appears in `node_modules/.bin`. The plugin runs `./node_modules/.bin/playwright run-server` from the directory holding `package.json` (fact 4), so `npx playwright` alone is not enough — it has to be a real dependency.

Keep the downloaded browsers out of the repository:

```bash
cd /c/Users/ahmed/Documents/CASS && printf '\n# Pest browser plugin\n/tests/Browser/Screenshots\n' >> .gitignore && tail -4 .gitignore
```

(Playwright puts browser binaries in the user profile, not the project, so only the plugin's failure screenshots need ignoring — `Pest\Browser\Support\Screenshot::dir()` is `tests/Browser/Screenshots`.)

- [ ] **Step 3: Give the browser suite its own configuration**

`phpunit.browser.xml`
```xml
<?xml version="1.0" encoding="UTF-8"?>
<!--
    The browser suite, deliberately NOT part of phpunit.xml.

    The plugin starts Playwright from a test-*collection* hook
    (UsesBrowserTestCaseMethodFilter::accept(), reached from
    Pest\Repositories\TestRepository::set() as each test file is loaded), which
    runs before PHPUnit applies --group / --exclude-group. So a group tag alone
    would not keep Playwright out of a plain `php artisan test`; the file has to
    be outside the suite that run loads. phpunit.xml lists only tests/Unit and
    tests/Feature, so tests/Browser is never even read there.

    The <php> block mirrors phpunit.xml exactly. It has to: the plugin serves
    the application in-process (Pest\Browser\Drivers\LaravelHttpServer), so the
    browser's requests hit this same SQLite :memory: connection and this same
    array mailer, and a divergence here is a test passing against a different
    application from the one the rest of the suite tests.
-->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Browser">
            <directory>tests/Browser</directory>
        </testsuite>
    </testsuites>
    <php>
        <ini name="memory_limit" value="512M"/>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_MAINTENANCE_DRIVER" value="file"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="BROADCAST_CONNECTION" value="null"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="DB_URL" value=""/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="PULSE_ENABLED" value="false"/>
        <env name="TELESCOPE_ENABLED" value="false"/>
        <env name="NIGHTWATCH_ENABLED" value="false"/>
        <env name="TURNSTILE_SITE_KEY" value=""/>
        <env name="TURNSTILE_SECRET_KEY" value=""/>
        <!-- The same line phpunit.xml carries, for the same reason: openedAt is
             stamped in mount() and is #[Locked], and Playwright fills a form in
             well under four seconds. -->
        <env name="CASS_SUBMISSION_MIN_SECONDS" value="0"/>
    </php>
</phpunit>
```

`tests/Pest.php` gains one line, so a browser test gets the same `TestCase` and `RefreshDatabase` as the rest:

```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');
```

- [ ] **Step 4: Give the agreement checkbox an `id` and a `name`**

The plugin resolves a field by id, name, label or placeholder. Every input on the form already has an `id` except the agreement checkbox, and the presentation radios are addressed by their shared `name`. In `resources/views/livewire/public/submission-form.blade.php`, in the agreement section:

```blade
                <label class="flex items-start gap-2 text-sm">
                    <input id="agreed" name="agreed" type="checkbox" wire:model="agreed" class="mt-0.5 rounded border-slate-300">
```

(`name` as well as `id`, because `check('agreed')` tries the name first and a checkbox with neither is addressable only by CSS selector.)

- [ ] **Step 5: Write the browser test**

`tests/Browser/SubmitAbstractTest.php`
```php
<?php

declare(strict_types=1);

use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;

/**
 * Spec section 12's one browser test. It is not a second copy of
 * tests/Feature/Public/SubmissionFormTest.php - that file already proves every
 * rule. This proves the parts no Livewire component test can reach: that the
 * CTA on the conference page really navigates, that the built JavaScript loads
 * and Livewire boots, and that a browser ends up on a page showing a reference
 * number. File attachments are deliberately not part of it - see the note in
 * the task preamble.
 */
beforeEach(function () {
    // Pinned in phpunit.browser.xml as well; set here so the test does not
    // depend on a config file it does not own. The application runs *in this
    // process* (fact 5), so a config change here reaches the server that
    // answers the browser.
    config()->set('cass.submission_min_seconds', 0);

    $this->organization = Organization::factory()->approved()->create([
        'name' => 'Gulf Pediatric Society',
        'primary_color' => '#0F4C8A',
    ]);

    $this->conference = Conference::factory()->for($this->organization)->published()->create([
        'name' => 'Gulf Pediatric Critical Care 2026',
        'slug' => 'gpcc26',
        'reference_prefix' => 'GPCC26',
        'word_limit' => 250,
        // Zero, so the files partial - which is wrapped in
        // @if ((int) $conference->max_files > 0) - renders nothing at all and
        // there is no upload input for the browser to be asked to feed.
        'max_files' => 0,
        'allowed_file_types' => ['pdf'],
        'presentation_types' => ['oral', 'poster'],
        'terms' => 'Presenting authors must register for the conference.',
    ]);

    Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);
});

it('takes an author from the call for abstracts to a reference number', function () {
    $page = visit('/c/'.$this->organization->slug.'/gpcc26')
        ->assertSee('Gulf Pediatric Critical Care 2026')
        ->assertSee('Submit abstract')
        // Plan 2 shipped this as href="#"; Plan 3 Task 7 pointed it here. A
        // click is the only test that proves the link actually goes somewhere.
        ->click('Submit abstract')
        ->assertPathEndsWith('/gpcc26/submit')
        ->assertSee('Neurocritical care');

    $page
        ->type('title', 'Early mobilisation after paediatric cardiac surgery')
        ->type('abstract', 'Background. We studied early mobilisation. Methods. A prospective cohort of 120 children. Results. Ventilator-free days increased. Conclusion. Early mobilisation is feasible and safe.')
        ->radio('presentation_preference', 'oral')
        ->type('author-name-0', 'Dr Sara Al-Harbi')
        ->type('author-email-0', 'sara@example.org')
        ->type('author-affiliation-0', 'King Fahad Specialist Hospital')
        ->type('contact_phone', '+966500000000')
        ->check('agreed')
        ->press('Submit abstract')
        // The redirect to /s/{token} happens after the action returns, so wait
        // for the thing that can only exist on the other side of it.
        ->waitForText('GPCC26-001')
        ->assertPathBeginsWith('/s/')
        ->assertSee('Early mobilisation after paediatric cardiac surgery')
        ->assertSee('Dr Sara Al-Harbi')
        ->assertSee(SubmissionStatus::Submitted->getLabel());

    $submission = Submission::query()->firstOrFail();

    expect($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($submission->reference)->toBe('GPCC26-001')
        // 23 whitespace-separated tokens, every one containing a letter or a
        // digit - the same count App\Support\Text\WordCounter produces from the
        // abstract alone ("Ventilator-free" is one word), and the same one the
        // live counter in resources/js/word-count.js shows.
        ->and($submission->word_count)->toBe(23)
        ->and($submission->authors)->toHaveCount(1);
})->group('browser');
```

The group tag does nothing in this configuration — `phpunit.browser.xml` runs the whole directory — and is there so that `--group=browser` and `--exclude-group=browser` keep working if the file is ever moved into `tests/Feature`.

- [ ] **Step 6: Add the CI job**

Two edits to `.github/workflows/ci.yml`.

First, the existing `test` job's extension list, because `composer install` now has a platform requirement it did not have:

```yaml
          extensions: mbstring, intl, gd, zip, pdo_mysql, sqlite3, pdo_sqlite, sockets
```

Second, a new job after `test`. It is separate so the existing `test` job stays fast and so a Playwright flake never hides a real unit failure:

```yaml
  browser:
    runs-on: ubuntu-latest
    needs: test
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: mbstring, intl, gd, zip, sqlite3, pdo_sqlite, sockets
          coverage: none
      - uses: actions/setup-node@v4
        with:
          node-version: '24'
          cache: npm
      - run: composer install --no-interaction --prefer-dist --no-progress
      - run: npm ci
      # The browsers themselves, plus the shared libraries Chromium needs on a
      # bare runner. Only chromium: the suite has one test and three engines
      # would triple the job for nothing.
      - run: npx playwright install --with-deps chromium
      # The page is rendered by Blade with @vite, so the manifest has to exist
      # or every request 500s on ViteManifestNotFoundException.
      - run: npm run build
      - run: cp .env.example .env && php artisan key:generate
      - name: Browser test
        run: ./vendor/bin/pest --configuration=phpunit.browser.xml
      - name: Upload failure screenshots
        if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-screenshots
          path: tests/Browser/Screenshots
          if-no-files-found: ignore
```

No MySQL service: the browser suite runs on SQLite in memory, which is what `phpunit.browser.xml` pins, and the `test` job already runs the whole suite against MySQL 8.4.

- [ ] **Step 7: Run it locally**

On this machine, from Git Bash:

```bash
cd /c/Users/ahmed/Documents/CASS && npm run build > /tmp/build.log 2>&1; echo "build rc=$?" && \
./vendor/bin/pest --configuration=phpunit.browser.xml > /tmp/browser.log 2>&1; echo "browser rc=$?"; tail -20 /tmp/browser.log
```

Expected: `build rc=0`, `browser rc=0`, `1 passed`. The first run is slow (Playwright starts a server and a browser); afterwards it is a few seconds.

If it fails, in this order:

- `Playwright is not installed. Please run [npm install playwright && npx playwright install]` — Step 2's `npm i -D playwright` did not land, or `node_modules/.bin/playwright` is missing. Check `ls node_modules/.bin | grep playwright`.
- `The process with arguments [...] is not running or has stopped unexpectedly` — the server started and died. Run `./node_modules/.bin/playwright run-server --host 127.0.0.1 --port 9999 --mode launchServer` by hand and read what it prints; on Windows this is usually a missing browser binary, fixed by re-running `npx playwright install chromium`.
- `ext-sockets` in any message — Step 1.
- `Vite manifest not found` — `npm run build` was not run in this checkout.
- A timeout on `waitForText('GPCC26-001')` — the submit was refused. The page will still show the field errors; open the screenshot the plugin saved under `tests/Browser/Screenshots`, and re-check that `cass.submission_min_seconds` is 0 in `beforeEach`.
- If anyone adds an `->attach(...)` here later, it will hang: the plugin's in-process server discards uploaded files (see the note in the preamble). Prove uploads in `tests/Feature`, not here.

**And confirm the main suite is untouched by all of this** — that the browser test is genuinely invisible to `php artisan test`, which is the whole point of the separate configuration:

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log; grep -ci playwright /tmp/t.log
```

Expected: `tests rc=0`, `450 passed` (unchanged — the browser test is not in this suite), and `0` mentions of Playwright. A non-zero count means `tests/Browser` leaked into `phpunit.xml`.

- [ ] **Step 8: Pint, Larastan, commit**

```bash
cd /c/Users/ahmed/Documents/CASS && \
./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?"
```

Expected: `stan rc=0`. `phpstan.neon` analyses `app`, `config`, `database` and `routes` — **not** `tests` — so nothing in `tests/Browser` is analysed and `visit()` needs no annotation. Do not add `tests` to `paths` here to "improve coverage": that is a separate decision with a few hundred findings behind it, and it is not this plan's.

Pint *does* format `tests/`, so the browser test is expected to come out of `pint` unchanged; if it reformats, take the reformatting.

```bash
cd /c/Users/ahmed/Documents/CASS && git add -A && git commit -q -m "test: one end-to-end browser run of the submission form, with its own CI job

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && git log --oneline -1
```

---

### Task 14: MySQL, the backlog, the final gate and the pull request

**Files:**
- Modify: `docs/superpowers/plans/backlog.md`
- No application code changes.

- [ ] **Step 1: Run the whole suite against MySQL, not only SQLite**

Four things in this plan behave differently on MySQL and are the reason this run is the real gate, not a formality:

1. `submissions.custom_field_values` is a JSON column (MySQL 8 rejects a literal `DEFAULT` on one, which is why it is nullable and written by the action).
2. `AllocateReference` relies on `lockForUpdate()`, which SQLite ignores entirely — this is the only run where the row lock is real.
3. `char(64)` on `access_token_hash` and `sha256` behaves differently from SQLite's typeless columns, and `unique` on a `char` is where a stray trailing space would surface.
4. The `unique(['submission_id', 'sha256'])` that `StoreSubmissionFile` catches as a duplicate is a real constraint violation only here.

```bash
cd /c/Users/ahmed/Documents/CASS && docker compose -f docker-compose.dev.yml up -d && sleep 15 && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php artisan test > /tmp/mysql.log 2>&1; echo "mysql rc=$?"; tail -4 /tmp/mysql.log
```

Expected: `mysql rc=0`, `450 passed`.

- [ ] **Step 2: Prove the concurrency the unit test can only simulate**

`tests/Unit/AllocateReferenceTest.php` runs two allocations sequentially in one transaction, which asserts the re-read but not the lock. Prove the lock once, by hand, against MySQL. Write `/tmp/race.php`:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$conference = App\Models\Conference::query()->firstOrFail();
$conference->forceFill(['submission_counter' => 0, 'reference_prefix' => 'RACE26'])->save();

// Two processes is what would really happen; two connections inside one
// process is close enough to prove the lock, because lockForUpdate() blocks on
// the row rather than on the process.
$second = app('db')->connection()->getPdo();

$references = [];

Illuminate\Support\Facades\DB::transaction(function () use ($conference, &$references): void {
    $references[] = app(App\Actions\Submissions\AllocateReference::class)->handle($conference);
});

Illuminate\Support\Facades\DB::transaction(function () use ($conference, &$references): void {
    $references[] = app(App\Actions\Submissions\AllocateReference::class)->handle($conference);
});

echo implode(' ', $references), PHP_EOL;
echo 'counter=', $conference->refresh()->submission_counter, PHP_EOL;
```

```bash
cd /c/Users/ahmed/Documents/CASS && \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=cass DB_USERNAME=cass DB_PASSWORD=cass \
  php /tmp/race.php
```

Expected: `RACE26-001 RACE26-002` and `counter=2`. (`php -` does not work on this machine, which is why this is a file.)

- [ ] **Step 3: Confirm the routes and the panel pages this plan added**

```bash
cd /c/Users/ahmed/Documents/CASS && php artisan route:list --except-vendor | grep -E "submit|/s/|files|submissions|emails|email-logs"
```

Expected:

- `GET c/{organization}/{conference:slug}/submit` → `conference.submit`
- `GET s/{token}` → `submission.status` (registered in Task 4, component written in Task 8)
- `GET files/{ulid}` → `files.download`
- two organizer resource routes under `org/{tenant:slug}/submissions`: index and `{record}`
- one organizer conference page: `org/{tenant:slug}/conferences/{record}/emails`
- two admin resource routes: `admin/email-logs` and `admin/email-logs/{record}`

- [ ] **Step 4: Update `docs/superpowers/plans/backlog.md`**

Remove the two entries Plan 3 actually closed, under `## Organizer features (Plan 2 onwards)` and `## Public site polish (Plan 2 or 3)`:

- "Per-conference email templates (spec 5.2, 5.9) are not built in Plan 2. **Plan 3** adds …" — done, Tasks 3 and 10.
- "The public conference page CTA links to `#` in its open state. Plan 3 replaces that one `href` …" — done, Task 7 Step 9.

Then rename the second of those headers, so nothing is left addressed to plans that have shipped:

```markdown
## Public site polish (Plan 6, launch polish)
```

Plan 3 closed only the CTA entry there. It touched none of the other five — the illustration `width`/`height`, `route('filament.organizer.auth.login')`, the pagination `@source` entry, the contact-mailable escaping and the conference `og:image` — because it added one public page and one status page and left Plans 1 and 2's views alone on purpose (see "Spec section 10 is now half done" below). The pagination `@source` entry is still not due: neither `/c/{org}/{conference}/submit` nor `/s/{token}` paginates anything.

Leave everything else, and append a new section:

```markdown
## Submissions (deferred by Plan 3)

- **Reviewer visibility of submission files** (spec section 4, "View submissions and files … assigned or pool only"): `SubmissionFilePolicy` answers for organization members and the platform admin only. Plan 4 adds the reviewer case, and has to decide whether a blind review hides the file's *name* too — an author who calls their PDF `al-harbi-kfsh-final.pdf` has deanonymised themselves.
- **Decision letters on the status page** (spec 5.3 step 5, "After decisions, it shows the decision letter"): `/s/{token}` renders a neutral "the organizers are handling this" block for `under_review`, `accepted`, `rejected` and `waitlisted`. **Plan 5** replaces that one block with the rendered `decision_*` template. The four statuses and the four templates already exist.
- **The seven template keys nothing sends.** Plan 3 ships platform defaults and a per-conference editor for all eleven keys of spec 5.9 because the editor is one screen, but only `submission_received` and `submission_draft_saved` are sent. Plan 4 sends `reviewer_invitation`, `reviewer_reminder` and `reviewer_overdue`; Plan 5 sends the four `decision_*`. The editor tells the organizer which is which ("Not sent yet").
- **`organization_approved` / `organization_rejected` are platform-wide.** They appear in the editor as "Platform-wide" and cannot be overridden per conference, because the emails are sent by Plan 1's `OrganizationApproved` / `OrganizationRejected` notifications at a moment when no conference exists, and those notifications carry their own hardcoded copy. **Plan 6** moves them onto `SendTemplatedEmail`; the defaults are already in `lang/en/mail.php`, so that move is a deletion rather than a piece of writing.
- **A failed *notification* stays at `queued` in `email_logs`.** `Illuminate\Mail\SendQueuedMailable::failed()` calls `$mailable->failed()`, so `TemplatedMail` marks itself failed; `Illuminate\Notifications\Notification` has no equivalent hook, so a transport error on `OrganizationApproved` or `NewSubmissionNotice` leaves the row where it was. Documented in the runbook's email triage table. Fixing it properly means a `JobFailed` listener that can correlate a failed job back to a log row.
- **`email_logs` is never pruned.** `short_link_visits` has `CASS_SHORT_LINK_VISIT_RETENTION_DAYS` and a nightly `model:prune`; this table does not, and it grows by one row per email for ever. Add `Prunable` and a retention variable before the table passes a few hundred thousand rows.
- **XLSX export.** Spec 5.6 asks for CSV *and* XLSX on the ranking table. Plan 3's submission list exports CSV only; openspout is now a direct dependency and writes both, so Plan 5 adds a writer, not a package.
- **Per-abstract QR codes and public abstract pages** are spec section 14 (v2 backlog). `short_links.target_type/target_id` is already polymorphic and `Submission` would be a valid target; nothing points one at a submission yet.
- **Open question for the owner:** Turnstile currently **fails open** when Cloudflare is unreachable — a `ConnectionException` is logged at `warning` and the submission is allowed; an explicit `success: false` or a 5xx from Cloudflare is still a refusal. The reasoning is that an outage would otherwise refuse every abstract in the last hour before a deadline, which is when it would cost the most, while the honeypot, the four-second minimum fill time and the 5/min/IP throttle all keep running. **This is the plan author's choice, not a recorded owner decision** — the spec says only "Turnstile when keys are configured". Ask before launch; flipping it to fail-closed is `return false;` in place of the `return true;` in the `catch (ConnectionException …)` branch of `App\Support\Turnstile::verify()`, plus inverting `it('lets a submission through when cloudflare itself is unreachable, and says so in the log')` in `tests/Unit/TurnstileTest.php`.
- **Open question for the owner: organizer withdraw and resend-link go beyond spec section 4.** That table gives "Submit / edit / withdraw abstract" to the author alone, but `SubmissionPolicy::withdraw()` and `resendLink()` mirror `view()`, which is every organization member down to a plain `member`. So any member can withdraw an abstract — irreversibly; nothing in either panel turns `Withdrawn` back — and can mint a new status token, which kills the link the author is already holding. Both are logged with the actor. Confirm before launch, or narrow both policy methods to owner/admin.
- **The platform admin cannot read a submission (spec section 4).** `SubmissionPolicy::before()` and `SubmissionFilePolicy::before()` answer `true` for `is_platform_admin`, but the organizer panel is membership-gated (`User::canAccessPanel('organizer')` requires `organizations()->exists()`, `canAccessTenant()` requires membership), so there is no route in. **Plan 6** adds a read-only admin `App\Filament\Admin\Resources\Submissions\SubmissionResource` (index + view, `$isScopedToTenant = false`, `canAccess()` = `is_platform_admin`), minting file links through `SubmissionFilePolicy` exactly as the organizer infolist does — which is also what the hard purge needs to act on.
- **Decided:** `SubmissionResource` sets `$isScopedToTenant = false` and scopes `getEloquentQuery()` by hand through `conference`. `Submission` has no `organization_id`, and Filament's tenancy `created` observer ends in `$relationship->save($tenant)` for any ownership relation that is not `BelongsTo`, `BelongsToThrough` or `BelongsToMany` — a `HasOneThrough` would scope queries correctly and then fatal on every submission created while the panel is booted with a tenant. If `staudenmeir/belongs-to-through` is ever added for another reason, Filament handles `BelongsToThrough` natively and this override can go.
- **The platform-admin hard purge (Plan 6) now has files to delete.** The `RESTRICT` foreign key from `submissions` to `conferences` still stops a stray `forceDelete()`, and the purge action must call `DeleteSubmissionFile` for every file before removing the rows, or the `cass-storage` volume keeps objects nothing references.
- **Content-Security-Policy (Plan 6) has three new things to allow** on the public pages: the inline `style` attribute carrying the organization's CSS variables (already true in Plan 2), the inline `<script>` that defines the Turnstile callback, and `https://challenges.cloudflare.com` as a script source. A nonce strategy that forgets the third turns the widget into a form nobody can submit.
- **Livewire temporary uploads linger.** `config/livewire.php` (Task 7) caps the endpoint at 10 MB and `CASS_UPLOAD_RATE_LIMIT` requests a minute, and leaves `cleanup` on — but that cleanup is *opportunistic*: `FileUploadController::_finishUpload()` sweeps files older than 24 hours only when the **next** upload arrives, so a quiet period after a busy one leaves abandoned files in `storage/app/private/livewire-tmp` inside the `cass-storage` volume. Add a scheduled sweep if the volume grows between deadlines.
- **Spec section 10 is now half done.** Plan 3's public views, Livewire components and email templates are entirely in `lang/en/*.php`, and `tests/Feature/LanguageCoverageTest.php` fails if a new key is used without being defined. Plans 1 and 2 still hardcode English in the landing page, the about page, the conference page, the countdown script and the poster template; extract those before any Arabic work.
```

- [ ] **Step 5: Final local gate**

```bash
cd /c/Users/ahmed/Documents/CASS && \
./vendor/bin/pint --test; echo "pint rc=$?"; \
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; echo "stan rc=$?"; \
npm run build > /tmp/build.log 2>&1; echo "build rc=$?"; \
php artisan test > /tmp/t.log 2>&1; echo "tests rc=$?"; tail -3 /tmp/t.log; \
./vendor/bin/pest --configuration=phpunit.browser.xml > /tmp/browser.log 2>&1; echo "browser rc=$?"
```

Expected: `pint rc=0`, `stan rc=0`, `build rc=0`, `tests rc=0` with `450 passed`, `browser rc=0`.

- [ ] **Step 6: Commit and push**

```bash
cd /c/Users/ahmed/Documents/CASS && git add -A && git commit -q -m "docs: Plan 3 backlog entries and deferrals

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" && \
git push -u origin plan-3-submissions && git log --oneline main..plan-3-submissions | wc -l
```

Expected: the push succeeds and the count is 14 (one commit per task).

- [ ] **Step 7: Open the pull request**

```bash
cd /c/Users/ahmed/Documents/CASS && gh pr create --base main --head plan-3-submissions \
  --title "Plan 3: author submissions, the status page, files and templated email" \
  --body "$(cat <<'BODY'
Implements Plan 3 (`docs/superpowers/plans/2026-09-11-plan-3-submissions.md`), covering spec sections 5.3, 5.9, the `/c/{org}/{conference}/submit`, `/s/{token}` and `/files/{ulid}` rows of section 6, and the submission parts of 3, 4, 7, 8, 9, 10, 12 and 13.

## For authors

- `/c/{org}/{conference}/submit`: one branded form with a live word count against the conference's own limit, a track select, only the presentation types that conference offers, an authors list with exactly one corresponding author, the conference's own extra questions rendered by type, PDF attachments, and the terms.
- **Save draft** needs only a title and a corresponding address, emails a link, and lands the author on that link. **Submit** validates everything again server-side, assigns a reference like `GPCC26-017` under a row lock, emails the confirmation and notifies the organization members who opted in.
- `/s/{token}`: reference, status, authors, abstract, files behind 30-minute signed URLs, and edit and withdraw until the deadline. The token is 64 random characters stored only as a SHA-256 hash; the plaintext exists in the emailed link and nowhere else.
- Bot protection: a honeypot, a four-second minimum fill time, 5/min/IP, and Cloudflare Turnstile when both keys are configured — verified server-side, and transparently absent when they are not.

## For organizers

- A tenant-scoped submission list with filters by conference, status and track, search on title, reference and author email, a read-only view with the authors, the extra answers and signed file links, and two actions: **Withdraw** (logged, with the actor) and **Resend status link** (a fresh token; the old link dies, which is the point).
- **Export CSV** of exactly the rows on screen, streamed, with a UTF-8 BOM and a leading apostrophe in front of anything a spreadsheet would execute.
- Submission counts on the conference view, and a per-conference editor for all eleven email template keys of spec 5.9 with a placeholder legend, a live preview and "Reset to default".

## Platform

- Every outgoing message — including Plan 1's notifications and Filament's password resets — is written to `email_logs` with its status, its error and a correlation id that travels in an `X-CASS-Log` header. Read-only in the admin panel, filterable by status and organization.
- Files are content-addressed on the private disk (`{sha256 prefix}/{ulid}.{ext}`), validated by size, count, extension **and** MIME sniffed from the content, and reachable only through a signed, expiring route.

## Verification

- Pest: SQLite in memory and MySQL 8.4, both green. One Playwright browser run of the whole flow in its own CI job.
- Larastan level 6 and Pint (strict types) clean.
- Cross-tenant negative cases on the submission resource, the email-template page and every policy; cross-*submission* cases on the token route; signature expiry and tampering on the download route.
- `tests/Feature/LanguageCoverageTest.php` fails if any string Plan 3 added is not in `lang/en/`.

## Deferred on purpose

- Decision letters on the status page and the four `decision_*` sends are Plan 5; the three reviewer sends are Plan 4. All seven templates ship with defaults and an editor that says "Not sent yet".
- Reviewer visibility of files is Plan 4. The platform-admin hard purge is still Plan 6, and now has files to unlink.
- Full details in `docs/superpowers/plans/backlog.md` under "Submissions (deferred by Plan 3)".

🤖 Generated with [Claude Code](https://claude.com/claude-code)
BODY
)"
```

- [ ] **Step 8: Watch CI to green**

```bash
cd /c/Users/ahmed/Documents/CASS && gh pr checks --watch --interval 20; echo "checks rc=$?"
```

Expected: `checks rc=0` with `test`, `browser`, `image` and `smoke` all passing. If one fails, read it with `gh run view --log-failed`:

- `test` fails at `composer install` with `ext-sockets` → the extension line was not added to the job's `extensions:` list (Task 13 Step 6, first edit).
- `browser` fails at `npx playwright install` → a runner image change; pin the Playwright version in `package.json` rather than loosening the install.
- `browser` fails inside the test → download the `playwright-screenshots` artifact; it is the page as Chromium saw it at the moment of failure.
- `smoke` fails on a 500 from `/` → the container has the new code but not the new tables. `smoke` runs `migrate --force` before its assertions, so this means a migration itself failed; the container log at the end of the job has the SQL.
- `image` fails → unexpected for this plan, which adds no PHP extension and no runtime dependency. Check `.dockerignore` did not gain an entry that removed `lang/`.

- [ ] **Step 9: Report and stop**

Print the PR URL and stop. **Do not merge and do not deploy** — the owner merges and runs the migrations.

```bash
cd /c/Users/ahmed/Documents/CASS && gh pr view --json url,state,title --jq '"\(.state) \(.title)\n\(.url)"'
```

---

## Self-review against the spec

### Section 5.3 — Author submission

| Requirement | Where |
|---|---|
| Author reaches the form from the conference page or the short link | Task 7 Step 9 (the one `href` in `submit-cta.blade.php`), Task 13 (a browser click proves it navigates) |
| Title, abstract with a live word count against the limit | Task 6 (the Alpine counter calling `window.cassCountWords`, which is `resources/js/word-count.js`, which applies the same two rules as `App\Support\Text\WordCounter`), Task 2 (`WordCounterTest`: hyphens, statistics, non-breaking spaces, Arabic, emoji) |
| Track | Task 6 (select shown only when the conference has tracks; a cross-conference id is refused by the form rule, by `SaveSubmissionDraft` and by `SubmitAbstract::blockers()`) |
| Presentation preference | Task 1 (`PresentationPreference`), Task 6 (only the types in `conferences.presentation_types`) |
| Authors list: name, email, affiliation, presenter flag, corresponding flag | Task 6 (add/remove rows, a radio for the corresponding author, the first row prefilled), Task 5 (`SaveSubmissionDraft::syncAuthors()` always produces exactly one corresponding author) |
| Contact phone | Task 1 (column), Task 6 (field) |
| Custom fields | Task 6 (rendered by `CustomFieldType`, required flags, per-type rules), Task 5 (`SubmitAbstract::customFieldBlockers()` re-checks type and required against the stored row) |
| Files | Task 4 (`StoreSubmissionFile`, `DeleteSubmissionFile`, the private disk), Task 7 (the form's upload field, the limits shown, the count/size/extension check before the database is touched) |
| Agreement checkbox with the conference terms | Task 6 (terms rendered above it), Task 5 (`SubmitAbstract::blockers($submission, $agreed)` — the flag is a parameter because it is consent, not a column) |
| Draft save creates `draft` and emails a status link | Task 5 (`SaveSubmissionDraft`), Task 3 (`SendTemplatedEmail`, `submission_draft_saved`), Task 6 (the button, the redirect to the same link the email carries) |
| Submit validates everything server-side (word limit, deadline, file MIME by content, sizes) | Task 5 (`SubmitAbstract::blockers()` checks eleven rules against the **stored** row), Task 4 (`SniffedMimeType` from the content, never the name or the client's header), Task 2 (`SniffedMimeTypeTest` with a renamed PNG) |
| Reference number like `CPDS26-017` | Task 1 (`ReferencePrefix`, the two columns), Task 2 (`AllocateReference` under `lockForUpdate()`), Task 14 Step 2 (the lock proved against MySQL) |
| Confirmation email to the corresponding author | Task 5, Task 3 (`submission_received`) |
| Notification to organization members who opted in | Task 3 (`NewSubmissionNotice`), Task 5 (`SubmitAbstract::notifiableMembers()` reading `organization_members.notify_on_submission`) |
| `/s/{token}` shows status and files, allows edit or withdraw until the deadline | Task 8 |
| After decisions it shows the decision letter | **Deferred to Plan 5.** The page renders a neutral status block for `under_review`, `accepted`, `rejected` and `waitlisted`, which Plan 5 replaces with the rendered `decision_*` template. Recorded in the backlog (Task 14 Step 4) and in "What this plan does NOT build" |
| Bot protection: honeypot, per-IP rate limit, Turnstile when keys are configured | Task 7 (`passesBotChecks()`, plus a minimum fill time the spec does not ask for and which costs nothing) |

### Section 5.9 — Emails

| Requirement | Where |
|---|---|
| Eleven template keys | Task 1 (`EmailTemplateKey`), Task 3 (`lang/en/mail.php` has a default for every one; `RenderEmailTemplateTest` fails if one is missing) |
| Ten placeholders | Task 1 (`placeholders()` per key), Task 3 (`RenderEmailTemplate`; a unit test asserts no default uses a placeholder its key does not declare) |
| Editable per conference | Task 1 (`email_templates`), Task 3 (`SaveEmailTemplate`, `ResetEmailTemplate`), Task 10 (the editor, the legend, the live preview, the reset) |
| All mail queued | Task 3 (`TemplatedMail implements ShouldQueue`; `NewSubmissionNotice implements ShouldQueue`; Plan 1's notifications already were) |
| Logged to `email_logs` with status and error | Task 1 (table), Task 3 (`SendTemplatedEmail` writes the row, `RecordOutgoingEmail` covers everything else, `TemplatedMail::failed()` records the error), Task 11 (the admin view) |
| Rendered in a branded layout | Task 3 (`resources/views/mail/templated.blade.php` with the organization's logo and `OrganizationTheme::primary`) |

### Section 6 — URL scheme

| Route | Where |
|---|---|
| `/c/{org}/{conference}/submit` | Task 6 (`conference.submit`, registered as `/c/{organization}/{conference:slug}/submit` with `->scopeBindings()` — the same explicit binding field `conference.show` uses, because the model's route key is the ULID) |
| `/s/{token}` | Task 4 registers the route (`submission.status`, constrained to `[A-Za-z0-9]{64}`, throttled 20/min/IP), because `Submission::statusUrl()` resolves its name from Task 5 onwards; Task 8 writes the component behind it |
| `/files/{ulid}` | Task 4 (`files.download`, `signed` middleware, no extension in the path because `docker/nginx.conf` answers static extensions from disk) |
| `/org/{tenant}/submissions/...` | Task 9 |
| `/org/{tenant}/conferences/{record}/emails` | Task 10 |
| `/admin/email-logs`, `/admin/email-logs/{record}` | Task 11 |
| `/invite/{token}`, custom domain `/{conference}` | **Plans 4 and 6.** Not registered here |

### Section 8 — Data storage

| Requirement | Where |
|---|---|
| Foreign keys on every relation, indexes on every foreign key | Task 1. `constrained()` creates the index; the composites are explicit |
| `(conference_id, status)` | Task 1 (`submissions`) — the index spec section 8 names, which Plan 2's self-review recorded as "not applicable yet" |
| Files on the private disk, content-addressed `{sha256 prefix}/{ulid}.{ext}` | Task 4 (`StoreSubmissionFile`), asserted byte for byte in `SubmissionFileDownloadTest` |
| Downloads only through signed routes | Task 4 (`signed` middleware; unsigned, expired and tampered URLs all 403 in the tests) |
| Validated by size (10 MB), count, extension and sniffed MIME | Task 4 (all four, in that order, so a 10 MB upload to a conference that accepts no files is refused before a byte is hashed) |
| Never executed or served directly | Task 4 (`storage/app/private` is not under `public/`; the download response carries `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff` and `Content-Security-Policy: default-src 'none'; sandbox`) |
| Behaviour on MySQL | Task 14 Step 1 (whole suite) and Step 2 (the row lock, which SQLite cannot exercise) |

### Section 9 — Security

| Requirement | Where |
|---|---|
| Rate limit: submission 5/min/IP | Task 7 (`cass.submission_rate_limit`, keyed by `ClientIp::from()` inside the component, because a Livewire action is one POST to `/livewire/update` and route middleware cannot tell a save from a submit) |
| All tokens stored as SHA-256 hashes, plaintext persisted nowhere | Task 2 (`SubmissionToken`, `IssueSubmissionToken`; a test asserts the plaintext is not recoverable from any attribute on the row). The plaintext exists in the emailed link and in the URL the author pastes; `email_logs.subject` is scrubbed of it on the way in (Task 3) and `EmailTemplateKey::subjectPlaceholders()` keeps `{{status_link}}` out of a subject line at all (Tasks 1 and 10), and the nginx access log redacts the `/s/` path (Task 12). Cloudflare still sees the full URI — said out loud in the runbook |
| Signed, expiring file URLs; no public uploads directory | Task 4 |
| Policies on every model | Task 1 (`SubmissionPolicy`, `SubmissionFilePolicy`, `EmailTemplatePolicy`, `EmailLogPolicy`), each with every ability Filament may call including `deleteAny`, `restoreAny` and `forceDeleteAny` — a missing method is ALLOW (Plan 2 fact 7). Each has a cross-tenant negative case, `SubmissionFilePolicy`'s in Task 9. The platform-admin abilities on `SubmissionPolicy` and `SubmissionFilePolicy` are declared but have no panel behind them yet — see the backlog entry |
| Feature tests for cross-tenant and cross-conference access on every resource **and every public token route** | Tasks 8, 9, 10, 11. The token route's cases: one submission's token cannot open another's, a reissued token kills the old one, a suspended organization takes the author's own link offline |
| CSRF on all forms | Livewire and Filament handle it; the public routes carry the `web` group |
| Audit log for the things that matter | Task 1 (`Submission` logs `status`, `reference`, `title`, `submitted_at`, `withdrawn_at` — never the abstract, never the token hash), Task 5 (`submission.submitted`, `submission.withdrawn` with `by: author|organizer`) |
| `.env.example` lists every variable | Task 12 Step 4, with a `comm` check that config reads nothing undeclared |
| Error pages never expose stack traces in production | Unchanged from Plan 1 |

**Additions beyond the spec** (recorded so a reviewer knows they were deliberate):

- **A four-second minimum fill time** (Task 7). Spec 5.3 names three bot defences; this is a fourth that costs one integer and one comparison and catches the naive scripts the other three do not.
- **A CSV export of the submission list, with a formula-injection guard** (Task 9). Spec 5.6 puts CSV and XLSX on the *ranking* table, which is Plan 5; a submission-list export is not in the spec at all. It is here because an organizer triaging abstracts before reviewing starts has no other way to get the authors and the answers out, and it is the same `ExportSubmissionsCsv` that Plan 5 will point at the ranking query. The guard is a second addition on top of that: every text column in it was typed by an author nobody vetted, and Excel executes a cell beginning with `=`, `+`, `-`, `@`, a tab or a carriage return.
- **Organizer-side withdraw and resend-link** (Task 9). Spec section 4's table gives "Submit / edit / withdraw abstract" to the author alone. Support work needs both anyway — authors email "please withdraw mine" and lose their link — so `SubmissionPolicy::withdraw()` and `resendLink()` mirror `view()`, which by spec section 4 is every organization member, down to a plain `member`. Both are audited (`submission.withdrawn` records `by: organizer` and the causer), a withdrawal keeps the reference and `submitted_at`, and a resend re-mails only the corresponding author. **Two consequences to note:** nothing in either panel reverses `SubmissionStatus::Withdrawn` (`isOpenToAuthor()` excludes it), and a resend invalidates the link the author already holds. If the owner would rather keep these to `owner`/`admin`, change both methods to `in_array($user->roleIn($submission->conference->organization), [OrganizationRole::Owner, OrganizationRole::Admin], true)` and extend Task 9's `lets a plain member read submissions but never edit or delete one` with `->and($policy->withdraw($member, $this->submission))->toBeFalse()`. Recorded as an open question in the backlog.
- **A redacted nginx access log** (Task 12). `/s/{token}` puts a bearer credential in a URL path and `/files/{ulid}` a 30-minute signature in a query string; nginx's stock `combined` format would write both to the log stream Coolify collects. The `cass` `log_format` replaces them. Cloudflare still sees the full URI, which the runbook says out loud.
- **`X-CASS-*` headers on outgoing mail** (Task 3). Not in the spec. They are what makes the runbook's email triage a lookup rather than a guess, and a ULID in a header reveals nothing.
- **`noindex` on the status page** (Task 8). The page prints author names, affiliations and addresses behind nothing but a URL; a search engine that finds one finds them all.
- **A throttle on `/files/{ulid}`** (Task 4). A signed URL is a bearer capability with a 30-minute life; without this, one leaked link streams a 10 MB PDF off a four-worker pool as fast as it can be requested.
- **`Content-Security-Policy: default-src 'none'; sandbox` on the download response** (Task 4). It is the one response in the application whose body is bytes an anonymous stranger uploaded.

### Section 10 — Non-functional (submission parts)

| Requirement | Where |
|---|---|
| Timestamps stored UTC; times shown in the conference's timezone | Task 1 (casts), Task 8 (the status page's deadline and withdrawal date), Task 9 (`->timezone()` on every date-time column and entry), Task 9 (the CSV renders both timestamps in the conference zone) |
| All strings in language files | Task 3 (`lang/en/mail.php`), Tasks 6, 7, 8 (`lang/en/submission.php`), Task 12 (`LanguageCoverageTest`, which fails on a key used but not defined and on a three-word text node left in a Blade file). **Plans 1 and 2's own hardcoded English is untouched and stays a backlog item** |
| Accessibility: labelled inputs, keyboard navigation | Task 6 (every input has a `<label for>`; the corresponding-author control is a radio group, which is what "exactly one" means to a screen reader; the honeypot is `aria-hidden` and `tabindex="-1"`) |
| Public pages render fast | Task 7 Step 9 (`$conference->setRelation('organization', $organization)` keeps Plan 2's query-count test at three queries after the CTA gained a `route()` call), Task 6 Step 1 (`renders the submission form with a bounded number of queries`, on `/submit` — the other page spec section 10 names, and the only one of the two that is Livewire; `customFields()` and `tracks()` are memoised per request for it), Task 12 Step 5 (the runbook times both URLs after every deploy) |

### Section 12 — Testing

| Requirement | Where |
|---|---|
| Unit: word counting | Task 2 (`WordCounterTest`, twelve cases) |
| Unit: reference number generation | Task 1 (`ReferencePrefixTest`), Task 2 (`AllocateReferenceTest`, including past 999 and a stale in-memory conference) |
| Unit: placeholder rendering | Task 3 (`RenderEmailTemplateTest`: escaping, an unknown placeholder left literal, raw HTML neutralised while Markdown survives, subject header injection, override precedence, cross-conference isolation) |
| Unit: token issue and hash | Task 2 (`SubmissionTokenTest`) |
| Unit: MIME sniffing | Task 2 (`SniffedMimeTypeTest`, PDF fixture against a renamed PNG) |
| Feature: every flow in section 5.3 | Tasks 4-8 |
| Feature: deadline enforcement before and after, window closed, unpublished conference 404, suspended organization 404 | Task 6 (the form), Task 8 (the status page) |
| Feature: honeypot, throttle, Turnstile success and failure with `Http::fake` | Task 7 |
| Feature: file size, count, type and MIME rejection | Task 4 |
| Feature: cross-conference token misuse, signed URL expiry and tampering | Tasks 4 and 8 |
| Feature: emails queued with the right template and logged | Task 3 (`TemplatedMailTest`, `EmailLogPipelineTest`), Task 5 |
| Feature: the organization notice reaches only opted-in members | Task 5 |
| Feature: a per-conference override is used instead of the default | Task 3 |
| Panel: list, view, withdraw, resend, export, with cross-tenant invisibility | Task 9 |
| Panel: email templates edit and reset, with cross-tenant invisibility | Task 10 |
| Admin: email log | Task 11 |
| Browser: one end-to-end run, in CI | Task 13 |
| Test-first, shown failing, exit code is the gate | Every task: failing test, expected failure text, implementation, passing run. No task commits on a red suite — the Task 3 pipeline case that used to call `SubmitAbstract::notifiableMembers()` before Task 5 existed now writes the pivot query out, and Task 5 adds `selects exactly the members who opted in` to pin the shared definition |
| No placeholder assertions | Counts are asserted through distinctive values (`GPCC26-017`, `IRB-2026-14`), signed URLs are followed and asserted to return 200 rather than merely matched by shape, and the CSV is decoded rather than measured |

### Section 13 — Brand

| Requirement | Where |
|---|---|
| Organization colours drive the public pages | Tasks 6 and 8 (`OrganizationTheme::cssVariables()` through the conference layout, exactly as Plan 2's conference page does) |
| IBM Plex Mono for reference numbers and codes | Task 8 (`font-mono` on the reference), Task 9 (`->fontFamily('mono')` on the panel column) |
| Branded email | Task 3 (the organization's logo and primary colour at the top of every templated message) |

### Explicit deferrals

1. **Reviewer invitation, assignment and review** (spec 5.4, 5.5): Plan 4, including the three reviewer template keys, which ship here with defaults and an editor and are sent by nothing.
2. **Scoring, ranking and decisions** (spec 5.6): Plan 5, including the four `decision_*` keys and the decision letter on the status page. `SubmissionStatus` declares `under_review`, `accepted`, `rejected` and `waitlisted` so neither plan has to migrate an enum that is in a column and three indexes.
3. **Reviewer visibility of submission files** (spec section 4): Plan 4. `SubmissionFilePolicy` answers for organization members and the platform admin only.
4. **Organization member management** (spec section 4): still Plan 4. Until it lands, "every member with `notify_on_submission` true" is one mailbox in production; the opt-out is tested with members created directly.
5. **`organization_approved` / `organization_rejected` on this pipeline**: Plan 6. Their defaults are in `lang/en/mail.php` and the editor marks them "Platform-wide"; Plan 1's notifications still carry their own copy.
6. **XLSX export** (spec 5.6): Plan 5. openspout is now a direct dependency and writes both formats.
7. **Custom domains** (5.8), **legacy import** (5.10), **the platform-admin hard purge** and **Content-Security-Policy**: Plan 6. The purge now has files to unlink and the CSP now has an inline Turnstile callback and a Cloudflare script source to allow — both recorded in the backlog.
8. **Per-abstract QR codes and public abstract pages**: spec section 14, v2.
9. **`email_logs` retention.** No `Prunable`, no retention variable. Recorded in the backlog with the `short_link_visits` precedent to copy.
10. **Plans 1 and 2's hardcoded English.** Untouched. Plan 3 adds none of its own and ships the test that keeps it that way.

### Type consistency check

- `WordCounter::count(string): int` — called by `SaveSubmissionDraft`, `SubmitAbstract::blockers()`, `SubmissionForm::render()` and `submitRules()`, and mirrored by `countWords()` in `resources/js/word-count.js`.
- `SubmissionToken::{generate,hash}(): string`, `SubmissionToken::LENGTH = 64` — called by `IssueSubmissionToken`, `SaveSubmissionDraft` (which mints the hash with the INSERT rather than updating a placeholder in a UNIQUE column), `Submission::findByPlainToken()` and `SubmissionFactory`.
- `IssueSubmissionToken::handle(Submission): string` — the *reissue* path only: called by `SubmitAbstract::handle()` and `SendSubmissionStatusLink` when the caller holds no plaintext. It is deliberately not on the create path.
- `Submission::findByPlainToken(string): ?self` — called by `SubmissionStatus::mount()` and six tests.
- `ReferencePrefix::derive(string, ?CarbonInterface): string` — called by `Conference::booted()` and `Conference::referencePrefix()`.
- `AllocateReference::handle(Conference): string` and `static format(string, int): string` — called by `SubmitAbstract::handle()` inside its transaction, and by nothing else.
- `SniffedMimeType::{forPath,forStream,matches,allowedExtensions}` — called by `StoreSubmissionFile` and by `SniffedMimeTypeTest`. `EXPECTED` keys equal the `allowed_file_types` options in `ConferenceForm`.
- `SaveSubmissionDraft::handle(Conference, array, ?Submission = null): SubmissionLink` — called by `SubmissionForm::saveDraft()`, `SubmissionForm::submit()`, `UpdateSubmission::handle()` and the tests.
- `SubmitAbstract::blockers(Submission, bool): list<string>` and `handle(Submission, bool, ?string = null): Submission` — called by `SubmissionForm::submit()`.
- `SubmitAbstract::notifiableMembers(Conference): Collection<int, User>` and `placeholderValues(Submission, string, string): array<string, string|null>` — both static and public, called by `SubmitAbstract::handle()`, `SendSubmissionStatusLink::handle()` and `SubmitAbstractTest`.
- `UpdateSubmission::handle(Submission, array): Submission` — called by `SubmissionForm::saveDraft()` and `submit()` in edit mode.
- `WithdrawSubmission::handle(Submission, ?User = null): Submission` — called by `SubmissionStatus::withdraw()` (no actor, so the submission window is enforced) and `SubmissionActions::withdraw()` (an actor, so it is not: an author who emails after the deadline still has to be taken off the programme).
- `SendSubmissionStatusLink::handle(Submission, ?string = null): EmailLog` — called by `SubmissionForm::saveDraft()` (with the token it just minted) and `SubmissionActions::resendLink()` (without, so a new one is minted).
- `StoreSubmissionFile::handle(Submission, UploadedFile): SubmissionFile` and `DeleteSubmissionFile::handle(SubmissionFile): void` — called by `SubmissionForm::storeUploads()` and `SubmissionForm::deleteFile()`.
- `ExportSubmissionsCsv::handle(Builder<Submission>, string): StreamedResponse` — called by `SubmissionActions::export()` with `$livewire->getFilteredTableQuery()`.
- `RenderEmailTemplate::handle(EmailTemplateKey, ?Conference, array): RenderedTemplate`, `template(EmailTemplateKey, ?Conference): RenderedTemplate` and `fill(string, array, bool): string` — called by `SendTemplatedEmail`, `ConferenceEmailTemplates::rows()` and `ConferenceEmailTemplates::preview()`.
- `SendTemplatedEmail::handle(EmailTemplateKey, Conference, string, array, ?Submission = null): EmailLog` — called by `SubmitAbstract` and `SendSubmissionStatusLink`.
- `SaveEmailTemplate::handle(Conference, EmailTemplateKey, string, string): EmailTemplate` and `ResetEmailTemplate::handle(Conference, EmailTemplateKey): void` — called by `ConferenceEmailTemplates`.
- `DefaultTemplates::for(EmailTemplateKey): RenderedTemplate` and `all(): array<string, RenderedTemplate>` — called by `RenderEmailTemplate::template()` and `RenderEmailTemplateTest`.
- `TemplatedMail::__construct(string $logUlid, string $subjectLine, string $body, Organization $organization, string $templateKey)` — the property is `$subjectLine`, never `$subject`, because `Illuminate\Mail\Mailable` already owns `$subject`.
- `RecordOutgoingEmail::{sending,sent}` and `RecordOutgoingEmail::LOG_HEADER` — registered in `AppServiceProvider::boot()` with `Event::listen`.
- `Turnstile::{isConfigured,siteKey,verify}` and `Turnstile::VERIFY_URL` — called by `SubmissionForm::passesBotChecks()`, `SubmissionForm::render()` and `TurnstileTest`.
- `Conference::{referencePrefix,submissionCounts,submissions,emailTemplates}` — called by `AllocateReference`, `ConferenceInfolist`, `SubmissionResource` and the tests. `submissionCounts()` returns `array{total:int, draft:int, submitted:int, withdrawn:int}` and is memoised per record in `ConferenceInfolist`.
- `Submission::{correspondingAuthor,isOpenToAuthor,statusUrl,organization,getRouteKeyName}` — `getRouteKeyName()` returns `ulid`, which is what every Filament-generated URL carries; `organization()` is a `HasOneThrough` that Filament is deliberately not told to use.
- `SubmissionFile::{temporaryUrl,extension,readStream,getRouteKeyName}` — `temporaryUrl(?int $minutes = null)` defaults to `cass.file_url_minutes`.
- `SubmissionLink::{submission,token,url}` — `token` is null on every save after the first, and `url()` is null with it.
- `SubmissionNotAcceptable::$reasons` is `list<string>`, and `because(string ...)` is the one-liner constructor. `SubmissionFileRejected` has six named constructors and no `$reasons`.
- Route names defined by this plan and referenced from it: `conference.submit` (Task 6), `submission.status` (Task 4), `files.download` (Task 4). Names from earlier plans referenced here: `conference.show`, `landing`, `privacy`, `terms`, `filament.organizer.auth.login`.
- Rate-limiter names defined by this plan: `file-download` and `submission-status`, both registered in Task 4 alongside the two routes that use them. The `submission` and `submission-penalty` limiters are `RateLimiter::hit()` keys inside the component, not named limiters, because they are applied per action rather than per route — and they are two keys rather than one because `hit()` sets the decay only on a key's first hit, so a 600-second penalty sharing a key with the 60-second budget would turn spec section 9's 5/min/IP into five per ten minutes for everyone behind that address.
