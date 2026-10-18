# CASS v2 Plan 9: Phase-B Operations (B2 Admin Second Factor, B6 Dead-Man Heartbeats, B7 Error Tracking, B5 Monitoring Runbook)

> **Status: DRAFT, written 2026-10-18 against `main` at `48e452b`, not yet reviewed.**
> Tasks 1 to 4 were prototyped in a scratch copy of that tree, red then green in plan order; after each one the whole suite, Larastan and Pint ran clean, and `composer audit` found no advisory after the install.

**Goal:** the operations floor of the expert brief's phase B. The platform-admin panel requires a second factor: an admin without an authenticator app is sent from every admin page to Filament's set-up page, and organizers and reviewers are untouched (B2). `cass:health --ping` GETs `CASS_HEARTBEAT_URL` only when every row is OK, scheduled hourly, so a red row, a dead scheduler and a dead host all look the same from outside: silence, then an alert; the two backup cron lines ping their own URLs (B6). Once `SENTRY_LARAVEL_DSN` is set, every exception Laravel reports goes to a Sentry-protocol tracker with request bodies, personal data, form fields, frame arguments, addresses, query values and link tokens kept out (B7). The runbook gains "Monitoring": the uptime monitor on `/up`, the three heartbeats, the tracker check and what to do when each alerts (B5).

**Architecture.** One owner per seam:
- The second factor: `AdminPanelProvider` (`isRequired: true`), enforced by Filament's own route middleware. `UserFactory::platformAdmin()` is an enrolled admin; `withoutSecondFactor()` is the admin before enrolment.
- The heartbeat: `HealthCommand` (`--ping`, a private `ping()`), scheduled in `routes/console.php`; the URL is `config('cass.heartbeat_url')`.
- Reporting: `bootstrap/app.php` (`Integration::handles()`) with the settings in `config/sentry.php`; `App\Support\Monitoring\ScrubTrackerEvent`, the `before_send` that is the last look at every event; `config/database.php` masks query values at the source.
- The procedures: runbook "Monitoring", "Backups" (the pings) and "Platform admin second factor"; `docs/HANDOVER.md`; decision 0015.

**Tech stack**, from `composer.lock` at `48e452b`: PHP 8.4.26 (8.4 in the image), Laravel 13.37.0, Filament 5.10.2 (pragmarx/google2fa 9.1.0 behind its app authentication), Livewire 4.4.7, Pest 5.3.2 with pest-plugin-laravel 5.0.1, PHPUnit 13.4.2, Larastan 3.13.0 (level 6), Pint 1.32.1. New in Task 3: `sentry/sentry-laravel` `^4.29`, resolved on 2026-10-18 to 4.29.0 with sentry/sentry 4.34.0, nyholm/psr7 1.8.2, symfony/options-resolver 8.1.0 and symfony/psr-http-message-bridge 8.1.9; jean85/pretty-package-versions 2.1.1 moves from the lock's dev section to its production one.

**Spec and brief:** `docs/reviews/2026-10-16-expert-brief.md`, phase-B rows B2, B5, B6 and B7 (their "Done when" is each task's acceptance test), "Question 4, operations", question 5's Turnstile row, and "What was reviewed" (the MFA option's name, confirmed in fact 2); `docs/superpowers/specs/2026-09-10-cass-v2-design.md` sections 8 and 9.

**Environment facts for every command below**
- `$REPO` is the tree you implement in: this worktree, `/c/Users/khawl/Desktop/Ahmed/claude/projects/CASS/.claude/worktrees/nifty-vaughan-0e33f3`, branch `plan-9-phase-b-ops` from `main` at `48e452b`, unless the run gives your task its own `CASS-wt-N` worktree and branch, which are then yours. No command below sets `$REPO`, and shell variables do not survive between Bash calls: begin every call with `REPO=<that path>;` or write the path itself in its place (never hard-code this worktree's path into a command block). Git Bash; start every command with `cd $REPO` after `export PATH="/c/Users/khawl/Desktop/Ahmed/claude/tools/php84:/c/Users/khawl/Desktop/Ahmed/claude/tools/composer:$PATH"`.
- Composer is `php /c/Users/khawl/Desktop/Ahmed/claude/tools/composer/composer.phar`, never `composer.bat`; only Task 3 runs it. `npm run build` has run; run it again before testing only if `public/build/manifest.json` is missing, and never with `npm run dev` running (`public/hot` makes the panels link the dev server).
- `php -` hangs on this machine. The production hook refuses a Bash command whose text names the host's tools (its remote shell, `deploy.sh`, the deployment platform): write commit messages and the PR body with the Write tool, edit docs with the Edit tool, and keep those words out of every Bash command.
- Baseline is the count `php artisan test` reports on the fresh branch: 1,381 passed, 1 skipped at `48e452b` (`/tmp/t-plan9-baseline.log`). Each task ends at "baseline + k"; counts are a guide and `rc=0` is the gate.
- **Order.** Stages `[[1],[2],[3],[4],[5],[6]]`. Tasks 1 and 2 touch disjoint files and may run as a parallel pair; Task 5 touches only the runbook and may pair with Task 3 or Task 4. Task 3 adds its compose line after Task 2's, Task 4 extends Task 3's test file and `config/sentry.php`, and Task 6 runs last. Each "baseline + k" assumes plan order; a task run in its own worktree counts from that worktree's base.
- Red and green runs, each to its own log (the gates below overwrite `/tmp/t-task-N.log`, and the red log is the test-first proof a report cites): `cd $REPO && php artisan test --compact --filter='<filter>' > /tmp/t-task-N-red.log 2>&1; echo "rc=$?"`, then the same command to `/tmp/t-task-N-green.log` once the code is in. Read a log with `tail -n 60 /tmp/t-task-N.log` in Bash, or with the Read tool at `C:\Users\khawl\AppData\Local\Temp\t-task-N.log`; read `/tmp/pr-task-6.log`, which holds the pull request URL, the same way.
- **The gates**, N being the task number, each judged by its exit code alone:
  ```bash
  cd $REPO && php artisan test > /tmp/t-task-N.log 2>&1; echo "test rc=$?"
  ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/phpstan-task-N.log 2>&1; echo "stan rc=$?"
  ./vendor/bin/pint --test > /tmp/pint-task-N.log 2>&1; echo "pint rc=$?"
  ```
- **Commits:** write the message with the Write tool to `C:\Users\khawl\AppData\Local\Temp\commit-plan9-task-N.txt`, the folder Git Bash calls `/tmp` (the Write and Read tools turn a bare `/tmp/...` into `C:\tmp\...`, which git never reads), ending with the trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`; `git add` the task's files by name, then `test -s /tmp/commit-plan9-task-N.txt && git commit -F /tmp/commit-plan9-task-N.txt`. Never with a gate red, and never while any test in the suite fails.
- New files: every PHP file begins `<?php`, a blank line, `declare(strict_types=1);`, a blank line, then the namespace for a class; code blocks below start after that. Imports are listed in prose: write one `use` statement per class in the order given (Pint's `ordered_imports`), then a blank line and any `use function` lines. Every test boots a fresh application, which rebuilds the Sentry hub and makes it the SDK's current one (fact 9), so no test needs an `afterEach`.

**What this plan does NOT build**
- A required second factor on the organizer or reviewer panel. B2 is the platform admin's, who passes every policy through `before()`; the other panels keep it optional.
- A screen to reset someone else's second factor. A lost phone is a recovery code, or a host-side `tinker` the owner runs (Task 5's runbook section).
- Any change to `docker/backup.sh` or `docker/storage-backup.sh`: the backup pings are `curl` on the two cron lines, which the owner edits on the host.
- The off-host backup and its heartbeat (B8), and the free-space row in `cass:health` (C3, still an open question in the backlog).
- Tracing, profiling, logs or metrics in the tracker: errors only.
- Turnstile's fail-open warning as a tracker event, release tagging, `zend.exception_ignore_args` for the server log, and `APP_PREVIOUS_KEYS` in the compose file: backlog, Task 6.
- A `composer audit` step in CI (C1): `ci.yml` has none today (fact 13), so Task 3 runs it locally.
- A migration or a policy. None is needed.

## Owner summary (review point 1)

#### Behaviour changes a user would notice
- **Platform admin:** after this release, signing in at `/admin` opens "Set up two-factor authentication" instead of the dashboard, and every admin page, Profile included, sends you back there until an authenticator app is set up. Setting it up shows eight recovery codes once: keep them outside the platform (a password manager, or paper). From then on each sign-in asks for the app's six-digit code or a recovery code. Locally, `admin@cass.test` is sent to the same page.
- **Enrol in the same session as the deploy.** Until you do, anyone holding the admin password could enrol their own phone first.
- Organizers and reviewers see no change: the second factor stays optional on `/org` and `/review`, also for a platform admin who is a member there.
- **Heartbeat:** once `CASS_HEARTBEAT_URL` is set, the server GETs it every hour, and only when every `cass:health` row is OK. Nothing shows on the site.
- **Error tracker:** once `SENTRY_LARAVEL_DSN` is set, every exception Laravel reports goes to the tracker, without request bodies, IP addresses, the signed-in user, cookies, headers, form fields, query strings, link tokens, email addresses or query values. Without the DSN nothing is sent.
- Database error messages in the server log no longer carry the query's values (an abstract's title, an author's address), DSN or not.
- With the admin always enrolled, rotating `APP_KEY` locks the admin out (a 500 at sign-in) until the factor is cleared on the host. The runbook says how; `APP_PREVIOUS_KEYS` would prevent it, but the compose file does not pass it yet (backlog).

#### Policies touched or added
None.

#### Migrations
None.

#### Environment variables
All optional; unset, nothing is sent.
- `CASS_HEARTBEAT_URL`: the hourly health check's ping URL. A secret: set it only in the application's environment in Coolify, then redeploy.
- `SENTRY_LARAVEL_DSN`: the tracker's DSN. A secret, set the same way.
- `SENTRY_RELEASE`, `SENTRY_ENVIRONMENT`: listed in `.env.example` only; the compose file does not pass them.
- The two backup ping URLs are not environment variables: they live in root's crontab on the host.

#### Sensitive files touched
- `bootstrap/app.php`: reported exceptions also go to the tracker (`Integration::handles()`).
- `config/sentry.php` (new): the tracker's settings; the privacy ones are fixed in code, not in the environment.
- `config/database.php`: query values masked in database error messages, on the sqlite and mysql connections.
- `config/cass.php`: `heartbeat_url`.
- `docker-compose.production.yml`: passes the two optional secrets to the app container; approving this summary is the go-ahead to edit it.
- Not on the list, but worth your eye: `app/Providers/Filament/AdminPanelProvider.php` (the requirement itself) and `composer.json` with `composer.lock` (one new production dependency, `composer audit` clean).

#### Open questions for the owner (each ships the behaviour stated)
1. **Which error tracker?** Ships the Sentry SDK, which works with Sentry's hosted free plan (1 user, 5,000 errors a month, 30 days of history), a self-hosted GlitchTip or Bugsink: the choice is only where the DSN points. Choosing Flare instead swaps Tasks 3 and 4 for `spatie/laravel-flare` and `FLARE_KEY`, re-planned before implementation.
2. **Which heartbeat and uptime services?** Recommended: Healthchecks.io for the three heartbeats (free: 20 checks, grace periods, email and push alerts) and UptimeRobot (free: 50 monitors, every 5 minutes) or Better Stack for `/up`. The code and the runbook work with any service that gives a ping URL.
3. **Should Turnstile's "could not reach Cloudflare" warning reach the tracker as an event?** The expert brief makes that the condition for failing open. It ships as a log line, which the tracker sees only as a breadcrumb; the backlog holds the change for after B1.

## File structure created or changed by this plan

```
app/Providers/Filament/AdminPanelProvider.php, database/factories/UserFactory.php   changed, T1: isRequired; platformAdmin() enrolled, withoutSecondFactor()
app/Console/Commands/HealthCommand.php, config/cass.php, routes/console.php         changed, T2: --ping and ping(); heartbeat_url; the hourly entry
.env.example, docker-compose.production.yml          changed, T2 (CASS_HEARTBEAT_URL) and T3 (SENTRY_LARAVEL_DSN; SENTRY_RELEASE and SENTRY_ENVIRONMENT commented in .env.example)
composer.json, composer.lock, bootstrap/app.php, phpunit.xml  changed, T3: the package, Integration::handles(), no DSN under test
config/sentry.php                                    created, T3; before_send added in T4
app/Support/Monitoring/ScrubTrackerEvent.php         created, T4: the before_send
config/database.php                                  changed, T4: mask_bindings_in_exception_messages on sqlite and mysql
tests/Feature/Admin/SecondFactorRequiredTest.php (T1), tests/Feature/Security/ErrorTrackingTest.php (T3, T4)  created
tests/Unit/HealthCommandTest.php                     changed, T2: six cases
docs/runbooks/deploy-production.md                   changed, T5
docs/decisions/0015-error-tracking-and-heartbeats.md created, T6
docs/HANDOVER.md, docs/superpowers/plans/backlog.md, docs/launch-checklist.md  changed, T6
```

## Facts verified before writing this plan

Read at `48e452b` on 2026-10-18; vendor paths are under `vendor/`. "Prototype" means the behaviour was run in the scratch tree, not only read.

1. **Versions** as in Tech stack: `composer.lock:2477` laravel/framework, `:1423` filament/filament, `:3631` livewire, `:11249` pest, `:12675` phpunit, `:10772` larastan, `:10941` pint, `:4597` pragmarx/google2fa, `:10654` jean85/pretty-package-versions (dev today). `sentry/sentry-laravel` 4.29.0 (2026-10-08) requires `illuminate/support ^13.0` among others and sentry/sentry `^4.34.0` (Packagist); Laravel 13 is supported since 4.22.0 (release notes). Baseline 1,381 passed, 1 skipped; the prototype ended at 1,399 passed, 1 skipped. `origin/main` moved to `48e452b` after the branch was cut (#40, playwright, npm files only): no file in common.
2. **Filament's required second factor** (`filament/filament/src/`): `Panel/Concerns/HasAuth.php:666-673` is `multiFactorAuthentication($providers, $setUpRequiredAction = SetUpRequiredMultiFactorAuthentication::class, $isRequired = false)`. When required, every page route gets `Auth/MultiFactor/Http/Middleware/EnsureMultiFactorAuthenticationIsEnabled` (`Pages/Concerns/HasRoutes.php:88-96`; resource pages through `Resources/Pages/Page.php:194-201`; the profile through `Auth/Pages/EditProfile.php:124-135`), which sends a user with no enabled provider, through `redirect()->guest()` (`EnsureMultiFactorAuthenticationIsEnabled.php:12-19`), to `{panel}/multi-factor-authentication/set-up` (`HasAuth.php:44`, `:52`, `:353-365`), a route registered only when required and outside the page middleware (`../routes/web.php:101-110`). An enrolled user who opens it is sent to `intended(profile)` (`Auth/MultiFactor/Pages/SetUpRequiredMultiFactorAuthentication.php:22-27`). Prototype `route:list`: every admin GET route but login, logout, the two password-reset pages and the set-up page carries the middleware, after `Authenticate`, so a non-admin still meets 403 first. It is not Livewire persistent middleware (`FilamentServiceProvider.php:128-138`): an open page keeps answering its Livewire calls; every page load is checked.
3. **App authentication** (`filament/filament/src/Auth/MultiFactor/App/AppAuthentication.php`): enabled means a filled secret (`:73-80`); `generateSecret()` is Google2FA's 16-character key (`:134-137`); recovery codes number 8 (`:44`, `:170-173`) and are stored hashed (`:120-132`); the set-up action saves both in one transaction (`Actions/SetUpAppAuthenticationAction.php:220-226`); Disable stays offered to an enrolled user (`:326-338`). `User` casts both columns encrypted (`app/Models/User.php:46-47`; not fillable, and factory states are unguarded) and admits only a verified admin (`:118`). `PlatformAdminSeeder` force-fills four columns, never the secret (`database/seeders/PlatformAdminSeeder.php:22-28`); nothing else in `database/` reads them. Strings: `filament/filament/resources/lang/en/auth/multi-factor/pages/set-up-required-multi-factor-authentication.php:7` and `filament/filament/resources/lang/en/auth/multi-factor/app/actions/set-up.php:5`. Prototype: after an `APP_KEY` change without `previous_keys`, reading an enrolled admin's secret throws `DecryptException` ("The MAC is invalid.") and `GET /admin` answers 500; with the old key in `previous_keys` it reads. `docker-compose.production.yml` does not pass `APP_PREVIOUS_KEYS`.
4. **Who signs in as an admin in tests:** 21 test files call `platformAdmin()`; `tests/Feature/Admin/EmailLogsTest.php:23` sets `is_platform_admin` directly but drives the panel only through Livewire, which runs no route middleware, and its one HTTP request is an organizer's 403. No browser test signs in (`tests/Browser/CspTest.php:30-36`).
5. **`cass:health`:** signature `app/Console/Commands/HealthCommand.php:47`, rows and exit code `:51-77` (`--json` prints one line, `:64-65`), docblock `:33-34`; the five-minute scheduler heartbeat `routes/console.php:60-62`; `beforeEach` `tests/Unit/HealthCommandTest.php:16-19`. A command's schedule test sits in its own test file (`tests/Feature/Console/SweepTemporaryUploadsTest.php:66-72`), so the new entry's goes in `HealthCommandTest`, not a new `ScheduleTest.php`. Prototype `schedule:list`: `0 * * * *  php artisan cass:health --json --ping`.
6. **Not `->pingOnSuccess()`:** `Illuminate/Console/Scheduling/Event.php:590-599` pings with a Guzzle client from the container (`:607-615`), not the Http facade, and reports a failed ping as an exception. `Http::retry()` sleeps through `Sleep::usleep()` (`Illuminate/Support/helpers.php:311-341`), so `Sleep::fake()` keeps the failure case instant; `Http::fake()` merges stub sets (`tests/Feature/Public/SubmissionFormTest.php:835`), and a stub that throws `ConnectionException` is how `tests/Unit/TurnstileTest.php:83` fakes an unreachable host.
7. **The deploy script** probes `/admin/login=200` and runs `cass:health` without `--ping` (`tools/claude/deploy.sh:60-68`, `:251-253`): neither change reaches it.
8. **Compose:** a variable reaches the container only through `${...}` in `docker-compose.production.yml` (`:3`; Turnstile `:48-49`, the caps `:54-59`); the app container is on the non-internal `coolify` network (`:63-65`), the way SMTP already leaves. `filled('')` is false, so `${CASS_HEARTBEAT_URL:-}` means no ping, and an empty DSN means none (fact 10).
9. **The package** (`sentry/sentry-laravel/src/Sentry/Laravel/`): `ServiceProvider.php:45-58` strips the Laravel-only keys and `:282-354` hands every other `config/sentry.php` key to the SDK, binding `ClientBuilder` afresh on every resolution (`:284`); the hub is a singleton that makes itself the SDK's current hub (`:356-432`), resolved at every boot (`:92`); events and breadcrumbs are wired only with a DSN (`:96-114`, `BaseServiceProvider.php:21-26`). The merge is by top-level key (`Illuminate/Support/ServiceProvider.php:163-172`), and a breadcrumb key left out defaults to on (`Features/Feature.php:177-182`, `EventHandler.php:127-131`). sentry/sentry needs ext-curl and ext-mbstring: the image's PHP is built `--with-curl` and `--enable-mbstring` (docker-library/php `8.4/alpine3.24/fpm/Dockerfile:147`, `:158`). The dist archive ships no tests; the package's `test/Sentry/TestCase.php` (GitHub, tag 4.29.0) captures events in a recording `before_send`, and Task 3 records them in a transport instead so the scrubber still runs.
10. **Reporting:** `Integration::handles()` registers a `reportable` that captures each reported exception (`Integration.php:59-64`, `:246-261`); Laravel's handler first drops its internal dontReport list (`Illuminate/Foundation/Exceptions/Handler.php:170-183`: authentication, authorization, every `HttpException`, validation and more). SDK options (`sentry/sentry/src/Options.php`): `max_request_body_size` accepts `none`, `never`, `small`, `medium`, `always` (`:1310`; default `medium`, `:1415`); `send_default_pii` defaults to false (`:1402`); `data_collection` null keeps the legacy rules (`:1417`); an empty DSN becomes none (`:1485-1507`).
11. **What the settings alone let through** (source and prototype): Livewire breadcrumbs carry `$component->all()`, the request's updates and mount data (`Features/LivewirePackageIntegration.php:93-169`; off with `breadcrumbs.livewire`, `:43-71`); cache breadcrumbs carry the key (`Features/CacheIntegration.php:89-118`), and rate-limiter keys hold client IPs and an invitee's address (`app/Livewire/Public/AcceptInvitation.php:98`); every frame carries its arguments (`sentry/sentry/src/FrameBuilder.php:171-226`; prototype: they arrived), because `docker/php.ini`, the image's only ini (`Dockerfile:59`), leaves `zend.exception_ignore_args` off; the legacy rules send the full URL with its query string (`DataCollection/HttpUrlCollector.php:24-26`) and filter only six headers (`DataCollection/HttpHeaderCollector.php:12-19`, `:62-73`), not `CF-Connecting-IP` or `Referer`; a query error's message embeds its bindings unless the connection sets `mask_bindings_in_exception_messages` (`Illuminate/Database/QueryException.php:58-92`, `Connection.php:864-872`), which neither connection in `config/database.php:37-67` does (prototype: a failed `schedule:list` printed a cache key among its bindings).
12. **`before_send` runs last** (`sentry/sentry/src/Client.php:342`, `:355`), after the request integration and the scope. The API used: `Event.php:611-621`, `:831-879`, `ExceptionDataBag.php:74`, `Frame.php:249`, `Breadcrumb.php:230`, `ClientBuilder.php:123` (`setTransport()`). A callable array survives `config:cache` (prototype).
13. **CI** (`.github/workflows/ci.yml`): setup-php's default production ini turns `zend.exception_ignore_args` on there, so in CI only the synthetic-frame case proves argument removal; there is no `composer audit` step yet; `tests/Unit/EnvExampleTest.php:44-61` fails on any `env()` in `config/*.php` or `bootstrap/app.php` missing from `.env.example`, commented lines included.
14. **Not verified:** whether `${SOURCE_COMMIT:-}` in the compose file reaches the container on the host's Coolify 4.1.2. Coolify documents `SOURCE_COMMIT` as predefined and its `main` source writes it into a compose deployment's runtime `.env`, but a compose reference may surface as an empty application variable that shadows it; release tagging waits (backlog). Free plans, read on 2026-10-18: Sentry Developer 1 user, 5k errors, 30-day lookback; Healthchecks.io 20 checks; UptimeRobot 50 monitors every 5 minutes.

### Task 1: The admin panel requires a second factor (B2)
Row B2: a platform admin without a second factor reaches no admin page. `isRequired: true` puts Filament's own middleware on every page route of the panel, Profile included, and registers the set-up page outside it (fact 2). `platformAdmin()` now stores what Filament's set-up action stores (fact 3), so the 21 test files that sign in as an admin stay green (fact 4), and `withoutSecondFactor()` is the admin before enrolment. The organizer and reviewer panels are the negative control.

**Files:** create `tests/Feature/Admin/SecondFactorRequiredTest.php`; modify `database/factories/UserFactory.php`, `app/Providers/Filament/AdminPanelProvider.php`.
**Interfaces:** consumes nothing. Produces `UserFactory::platformAdmin()` (now enrolled), `UserFactory::withoutSecondFactor()`, and the set-up URL `/admin/multi-factor-authentication/set-up` that Task 5's runbook describes.

- [ ] **Step 1: The factory states and the failing test.** The states are test fixtures, so they arrive with the test; alone they change nothing a test sees. In `UserFactory`, import `Filament\Auth\MultiFactor\App\AppAuthentication` after `App\Models\User`, add the property after `$password`, and replace `platformAdmin()` with the two methods:
```php
    /**
     * Hashed once per run, as $password is: the recovery codes every enrolled
     * factory admin holds.
     *
     * @var list<string>|null
     */
    protected static ?array $recoveryCodes = null;
// platformAdmin() becomes, and withoutSecondFactor() follows it:
    /**
     * A platform administrator enrolled with an authenticator app, as the admin
     * panel requires: the secret and the hashed recovery codes Filament's set-up
     * action stores. withoutSecondFactor() is the same admin before enrolment.
     */
    public function platformAdmin(): static
    {
        return $this->state(fn (): array => [
            'is_platform_admin' => true,
            'app_authentication_secret' => AppAuthentication::make()->generateSecret(),
            'app_authentication_recovery_codes' => static::$recoveryCodes ??= array_map(
                static fn (string $code): string => Hash::make($code),
                AppAuthentication::make()->generateRecoveryCodes(),
            ),
        ]);
    }

    /**
     * No second factor yet: the admin panel sends this user to set one up.
     */
    public function withoutSecondFactor(): static
    {
        return $this->state(fn (): array => [
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ]);
    }
```
The test. Imports: `App\Enums\OrganizationRole`, `App\Models\Conference`, `App\Models\ConferenceReviewer`, `App\Models\Organization`, `App\Models\User`, `Database\Seeders\PlatformAdminSeeder`, `Filament\Auth\MultiFactor\App\AppAuthentication`; `use function Pest\Laravel\actingAs;`, `use function Pest\Laravel\get;`.
```php
beforeEach(function () {
    $this->unenrolled = User::factory()->platformAdmin()->withoutSecondFactor()->create();
});

it('sends a platform admin without a second factor from every admin page to set one up', function (string $path) {
    actingAs($this->unenrolled)->get($path)->assertRedirect('/admin/multi-factor-authentication/set-up');
})->with(['dashboard' => '/admin', 'organizations' => '/admin/organizations', 'profile' => '/admin/profile']);

it('shows that admin the set-up page, which offers the authenticator app', function () {
    actingAs($this->unenrolled)->get('/admin/multi-factor-authentication/set-up')
        ->assertOk()
        ->assertSee(__('filament-panels::auth/multi-factor/pages/set-up-required-multi-factor-authentication.heading'))
        ->assertSee(__('filament-panels::auth/multi-factor/app/actions/set-up.label'));
});

it('lets an enrolled platform admin in, and sends them from the set-up page to their profile', function () {
    $admin = User::factory()->platformAdmin()->create();
    $app = AppAuthentication::make();

    // The factory's secret has the real shape: a code generated from it verifies.
    expect($app->isEnabled($admin))->toBeTrue()
        ->and($app->verifyCode($app->getCurrentCode($admin), $admin->getAppAuthenticationSecret()))->toBeTrue()
        ->and($admin->getAppAuthenticationRecoveryCodes())->toHaveCount(8);

    actingAs($admin)->get('/admin')->assertOk();
    get('/admin/multi-factor-authentication/set-up')->assertRedirect('/admin/profile');
});

it('asks for no second factor outside the admin panel', function () {
    // The same unenrolled admin, also an organization owner and an active reviewer.
    $organization = Organization::factory()->approved()->create();
    $organization->addMember($this->unenrolled, OrganizationRole::Owner);
    $conference = Conference::factory()->for($organization)->closed()->create(['review_deadline' => now()->addMonth()]);
    ConferenceReviewer::factory()->for($conference)->create(['user_id' => $this->unenrolled->id]);

    actingAs($this->unenrolled)->get("/org/{$organization->slug}")->assertOk();
    get('/review')->assertOk();
});

it('keeps an enrolled admin\'s second factor when the admin seeder runs again', function () {
    config(['cass.admin_email' => 'admin@example.org', 'cass.admin_password' => 'a-long-admin-password']);
    $admin = User::factory()->platformAdmin()->create(['email' => 'admin@example.org']);

    $this->seed(PlatformAdminSeeder::class);

    expect($admin->fresh()?->getAppAuthenticationSecret())->toBe($admin->getAppAuthenticationSecret());
});
```

- [ ] **Step 2: Run it red.** `cd $REPO && php artisan test --compact --filter='SecondFactorRequiredTest' > /tmp/t-task-1-red.log 2>&1; echo "rc=$?"`. Expected: `rc=1`, 5 failed, 2 passed. The three redirect cases fail with "Expected response status code [201, 301, 302, 303, 307, 308] but received 200."; the set-up page case with "Expected response status code [200] but received 404." (the route exists only when required); the enrolled case on its second request with "Expected response status code [201, 301, 302, 303, 307, 308] but received 404.". The negative control and the seeder case pass: they pin behaviour that must not change.

- [ ] **Step 3: Require it.** In `AdminPanelProvider::panel()` the `->multiFactorAuthentication([...])` call becomes:
```php
            // Required here, and only here: the platform admin passes every policy
            // through before(), so a password alone must not open this panel. A
            // signed-in admin with no factor is sent to Filament's set-up page.
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ], isRequired: true)
```

- [ ] **Step 4: Green, the gates (N=1), commit.** The filter to `/tmp/t-task-1-green.log`: `rc=0`, 7 passed. Then `test rc=0` at baseline + 7 (1,388 passed, 1 skipped; `PanelAccessTest`, `SecurityHeadersTest` and the admin tests sign in as the enrolled factory admin), `stan rc=0`, `pint rc=0`. Commit the three files with the subject `feat(admin): the platform-admin panel requires a second factor` and the body "Every admin page sends a platform admin without an authenticator app to Filament's set-up page; the organizer and reviewer panels are unchanged. The factory's platformAdmin() is now enrolled, and withoutSecondFactor() is the admin before enrolment."

### Task 2: The hourly heartbeat, `cass:health --ping` (B6)
Row B6: a ping only when every row is OK, scheduled hourly, with tests for the schedule entry and for silence on a red row. Not `->pingOnSuccess()` (fact 6). The ping is a private method of the command, its only caller; the backup crons ping with `curl` on the host. A failed ping is a warning in the log and never changes the exit code, which the rows decide; the URL is a secret and never reaches the log. `.env.example` changes here because `EnvExampleTest` fails otherwise (fact 13), and the compose file because a variable reaches the container only through it (fact 8).

**Files:** modify `app/Console/Commands/HealthCommand.php`, `config/cass.php`, `routes/console.php`, `.env.example`, `docker-compose.production.yml`, `tests/Unit/HealthCommandTest.php`.
**Interfaces:** consumes nothing. Produces `cass:health --ping`, `config('cass.heartbeat_url')` from `CASS_HEARTBEAT_URL`, and the entry `cass:health --json --ping` at `0 * * * *`, which Task 5's runbook and Task 6's HANDOVER name; Task 3 adds its compose line after this task's.

- [ ] **Step 1: The failing tests.** The imports of `tests/Unit/HealthCommandTest.php` become, in this order: `Illuminate\Console\Scheduling\Event`, `Illuminate\Console\Scheduling\Schedule`, `Illuminate\Foundation\Testing\RefreshDatabase`, `Illuminate\Http\Client\ConnectionException`, `Illuminate\Http\Client\Request`, `Illuminate\Support\Facades\Artisan`, `Illuminate\Support\Facades\Cache`, `Illuminate\Support\Facades\DB`, `Illuminate\Support\Facades\Http`, `Illuminate\Support\Facades\Log`, `Illuminate\Support\Facades\Storage`, `Illuminate\Support\Sleep`; `use function Pest\Laravel\artisan;` stays. Append:
```php
it('pings the heartbeat URL once, with a GET, when every check passed, and prints one JSON line', function () {
    Http::fake();
    config()->set('cass.heartbeat_url', 'https://hc-ping.example/test-check');

    expect(Artisan::call('cass:health', ['--json' => true, '--ping' => true]))->toBe(0);
    $lines = preg_split('/\R/', trim(Artisan::output())) ?: [];

    // The scheduled run is `--json --ping`: the ping adds nothing to stdout.
    expect($lines)->toHaveCount(1)
        ->and(json_decode($lines[0], true))->toBeArray()->toHaveCount(6);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'https://hc-ping.example/test-check');
});

it('pings nothing without --ping or without a URL', function (array $options, ?string $url) {
    Http::fake();
    config()->set('cass.heartbeat_url', $url);

    artisan('cass:health', $options)->assertExitCode(0);

    Http::assertNothingSent();
})->with([
    'without --ping' => [[], 'https://hc-ping.example/test-check'],
    'without a URL' => [['--ping' => true], null],
]);

it('pings nothing when a check failed', function () {
    // The point of a dead-man heartbeat: a red row is silence, and the heartbeat
    // service turns the silence into an alert.
    Http::fake();
    config()->set('cass.heartbeat_url', 'https://hc-ping.example/test-check');
    Cache::put('cass:scheduler-heartbeat', now()->subHour()->toIso8601String(), 3600);

    artisan('cass:health', ['--ping' => true])->assertExitCode(1);

    Http::assertNothingSent();
});

it('keeps the exit code when the ping fails, and logs a warning without the URL', function () {
    // Http::fake() merges stub sets, so this test fakes only the failure.
    Http::fake(fn () => throw new ConnectionException('Could not resolve host for https://hc-ping.example/test-check'));
    Sleep::fake();
    Log::spy();
    config()->set('cass.heartbeat_url', 'https://hc-ping.example/test-check');

    artisan('cass:health', ['--ping' => true])->assertExitCode(0);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => ! str_contains((string) json_encode($context), 'hc-ping.example/test-check'),
    );
});

it('schedules cass:health --json --ping every hour', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'cass:health'));

    expect($event)->not->toBeNull()
        ->and((string) $event?->command)->toContain('--json')->toContain('--ping')
        ->and($event?->expression)->toBe('0 * * * *');
});
```

- [ ] **Step 2: Run them red.** `cd $REPO && php artisan test --compact --filter='HealthCommandTest' > /tmp/t-task-2-red.log 2>&1; echo "rc=$?"`. Expected: `rc=2`, 5 failed, 9 passed (the file's eight and the "without --ping" case). Four fail with `InvalidOptionException` 'The "--ping" option does not exist.'; the schedule case with "Expecting null not to be null".

- [ ] **Step 3: The option, the setting, the entry.** `HealthCommand`: import `Illuminate\Support\Facades\Http` and `Illuminate\Support\Facades\Log` after `Illuminate\Support\Facades\DB`. In the class docblock, the two lines from " * For a human at a terminal" to "calls it." become ` * For a human at a terminal, for a host cron through `--json`, and hourly` and ` * from routes/console.php as `--json --ping`, the dead-man heartbeat.`. The signature, the call before `handle()`'s `return`, and the method after `handle()`:
```php
    protected $signature = 'cass:health
        {--json : Machine-readable output}
        {--ping : GET the heartbeat URL (CASS_HEARTBEAT_URL) when every check passed}';
// handle(), directly before `return $failed === [] ? self::SUCCESS : self::FAILURE;`:
        if ($failed === [] && $this->option('ping') === true) {
            $this->ping();
        }

// after handle():
    /**
     * The heartbeat service alerts when this GET stops arriving, so a failed ping
     * is a warning in the log and never changes the exit code: the rows decide
     * that. Here rather than in a class of its own because this is its only
     * caller; the backup crons ping with curl on the host. The URL is a secret
     * (anyone holding it can mark the check healthy), so the log never shows it.
     */
    private function ping(): void
    {
        $url = (string) config('cass.heartbeat_url');

        if ($url === '') {
            return;
        }

        try {
            Http::timeout(10)->retry(2, 500)->get($url);
        } catch (Throwable $e) {
            Log::warning('The heartbeat ping failed; the heartbeat service alerts if the next one fails too.', [
                'error' => str_replace($url, '[CASS_HEARTBEAT_URL]', $e->getMessage()),
            ]);
        }
    }
```
`config/cass.php`, after the `email_log_retention_days` line, a blank line and the first part below. `routes/console.php`: import `App\Console\Commands\HealthCommand` before `SendReviewerRemindersCommand`; append a blank line and the second part. `.env.example`, after the `TRUSTED_PROXIES` line: the third part. `docker-compose.production.yml`, in the `app` service's `environment:` after `TURNSTILE_SECRET_KEY: ${TURNSTILE_SECRET_KEY:-}`: the YAML block. That file is one `tools/claude/README.md` lets an agent edit only with the owner's go-ahead, which approving this plan's summary gives; without it, leave the file alone, and Task 5's runbook says the variable needs its compose line first.
```php
    // The dead-man heartbeat: `cass:health --ping`, hourly from
    // routes/console.php, GETs this URL only when every check passed, and the
    // heartbeat service alerts when the GETs stop. A secret (anyone holding it
    // can mark the check healthy), so it lives only in the deployment's
    // environment. Unset or empty: no ping.
    'heartbeat_url' => env('CASS_HEARTBEAT_URL'),
// routes/console.php, appended:
// The dead-man heartbeat (runbook "Monitoring"). The ping is the command's
// own (--ping), sent only when every row is OK, so a red row and a dead
// scheduler look the same from outside: no ping, then an alert. Not
// ->pingOnSuccess(): Event::pingCallback() sends that with its own Guzzle
// client after a subprocess no test can drive, where --ping goes through the
// Http facade and its silence on a red row is tested. Hourly: the service's
// grace period absorbs a slow run, and the five-minute closure above is what
// tells cass:health itself that the scheduler is alive.
Schedule::command(HealthCommand::class, ['--json', '--ping'])->hourly();
// .env.example, after TRUSTED_PROXIES:
# Dead-man heartbeat: cass:health --ping (hourly) GETs this URL when every check passed.
# A secret: set it only in the deployment's environment. Empty: no ping.
CASS_HEARTBEAT_URL=
```
```yaml
      # Optional. The hourly cass:health --ping heartbeat URL, a secret. Empty is
      # right here, unlike the caps below: an empty URL means no ping.
      CASS_HEARTBEAT_URL: ${CASS_HEARTBEAT_URL:-}
```

- [ ] **Step 4: Green, the gates (N=2), commit.** `--filter='HealthCommandTest|EnvExampleTest'` to `/tmp/t-task-2-green.log`: `rc=0`, 15 passed. Then `test rc=0` at baseline + 13 (1,394), `stan rc=0`, `pint rc=0`. Commit the six files (five without the compose go-ahead; then drop the body's last clause) with the subject `feat(health): cass:health --ping, the hourly dead-man heartbeat` and the body "GETs CASS_HEARTBEAT_URL only when every row is OK; a failed ping is a warning without the URL and leaves the exit code alone. Scheduled hourly as --json --ping; the production compose file passes the optional variable." CI's `image` job runs on the pull request because a compose file changed.

### Task 3: Report exceptions to a Sentry-protocol tracker (B7, the reporting)
Row B7, the owner's choice of service still open (question 1): `sentry/sentry-laravel` talks to Sentry, GlitchTip and Bugsink alike. The hook is `Integration::handles()`, so Laravel's dontReport list applies first (fact 10). `config/sentry.php` keeps only what this project sets: the privacy settings are in code, and the breadcrumbs that carry form fields, cache keys and outgoing URLs are off (fact 11). With no DSN nothing is sent; `phpunit.xml` keeps every test DSN-less whatever `.env` holds. The test builds the application's own client with a DSN and a transport that keeps events instead of sending them (fact 9).

**Files:** modify `composer.json`, `composer.lock`, `bootstrap/app.php`, `.env.example`, `docker-compose.production.yml`, `phpunit.xml`; create `config/sentry.php`, `tests/Feature/Security/ErrorTrackingTest.php`.
**Interfaces:** consumes Task 2's compose line (this one follows it). Produces the package, `config/sentry.php` (Task 4 adds `before_send`), `SENTRY_LARAVEL_DSN`, and the test file's `$this->transport` and `$this->track`, which Task 4's cases use.

- [ ] **Step 1: Install the package.** Nothing reports yet: no DSN and no hook.
```bash
cd $REPO && php /c/Users/khawl/Desktop/Ahmed/claude/tools/composer/composer.phar require "sentry/sentry-laravel:^4.29" --no-interaction --no-progress > /tmp/composer-task-3.log 2>&1; echo "composer rc=$?"
php /c/Users/khawl/Desktop/Ahmed/claude/tools/composer/composer.phar audit --no-interaction > /tmp/audit-task-3.log 2>&1; echo "audit rc=$?"
git status --short
```
Expected: `composer rc=0` and "Lock file operations: 5 installs, 0 updates, 0 removals" (the five packages of Tech stack; a later 4.x is fine, and the commit body records what resolved); `audit rc=0` with "No security vulnerability advisories found."; `git status` shows `composer.json` (`"sentry/sentry-laravel": "^4.29"`) and `composer.lock` modified and nothing else new: the `filament:upgrade` hook re-publishes only git-ignored assets.

- [ ] **Step 2: The failing test.** Imports: `Illuminate\Validation\ValidationException`, `Sentry\ClientBuilder`, `Sentry\Event`, `Sentry\State\HubInterface`, `Sentry\Transport\Result`, `Sentry\Transport\ResultStatus`, `Sentry\Transport\TransportInterface`, `Symfony\Component\HttpKernel\Exception\NotFoundHttpException`.
```php
beforeEach(function () {
    // Keeps every event it is given instead of sending it.
    $this->transport = new class implements TransportInterface
    {
        /** @var list<Event> */
        public array $events = [];

        public function send(Event $event): Result
        {
            $this->events[] = $event;

            return new Result(ResultStatus::success(), $event);
        }

        public function close(?int $timeout = null): Result
        {
            return new Result(ResultStatus::success());
        }
    };

    // The hub the application builds from config/sentry.php, now with a DSN and
    // the transport above: the package binds ClientBuilder afresh on every
    // resolution, and its hub singleton makes itself the SDK's current hub.
    $this->track = function (): void {
        config()->set('sentry.dsn', 'https://public@o0.ingest.sentry.io/1');
        app()->extend(ClientBuilder::class, fn (ClientBuilder $builder): ClientBuilder => $builder->setTransport($this->transport));
        app()->forgetInstance(HubInterface::class);
        app(HubInterface::class);
    };
});

it('pins the privacy settings, and reports quietly without a DSN', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.max_request_body_size'))->toBe('never')
        ->and(config('sentry.traces_sample_rate'))->toBeNull()
        ->and(config('sentry.breadcrumbs'))->toBe(['livewire' => false, 'cache' => false, 'http_client_requests' => false, 'sql_bindings' => false]);

    // No DSN in the test environment (phpunit.xml), so this is dropped by the SDK.
    report(new RuntimeException('tracker test'));

    expect(app(HubInterface::class)->getClient()?->getOptions()->getDsn())->toBeNull();
});

it('sends a reported exception once a DSN is set, and none of Laravel\'s unreported ones', function () {
    ($this->track)();

    report(new RuntimeException('tracker test'));
    // Both are in the handler's internal dontReport list (validation, every HTTP exception).
    report(ValidationException::withMessages(['title' => 'Required.']));
    report(new NotFoundHttpException);

    expect($this->transport->events)->toHaveCount(1)
        ->and($this->transport->events[0]->getExceptions()[0]->getValue())->toBe('tracker test');
});
```

- [ ] **Step 3: Run it red.** `cd $REPO && php artisan test --compact --filter='ErrorTrackingTest' > /tmp/t-task-3-red.log 2>&1; echo "rc=$?"`. Expected: `rc=1`, 2 failed: "Failed asserting that null is identical to 'never'." (the package's own config has no such key) and "Failed asserting that actual size 0 matches expected size 1." (nothing hooks the handler).

- [ ] **Step 4: The settings and the hook.** Create `config/sentry.php` (no namespace; `declare` only) with the first block below. `bootstrap/app.php`: import `Sentry\Laravel\Integration` after `Illuminate\Session\Middleware\StartSession`, and the `//` inside `->withExceptions(...)` becomes the second part. `.env.example`: append a blank line and the third part. `docker-compose.production.yml`, after Task 2's `CASS_HEARTBEAT_URL` line, the YAML block (the same go-ahead as Task 2). `phpunit.xml`, after `<env name="TURNSTILE_SECRET_KEY" value=""/>`, the XML block.
```php
/*
 * Error tracking through the Sentry SDK, whose protocol Sentry, GlitchTip and
 * Bugsink all accept (docs/decisions/0015-error-tracking-and-heartbeats.md).
 * A key left out keeps the package's default, from
 * vendor/sentry/sentry-laravel/config/sentry.php; the merge is by top-level
 * key, so `breadcrumbs` below replaces that list.
 *
 * Authors' abstracts, names and email addresses must never reach the tracker:
 * no request bodies, no personal data (IP address, signed-in user, cookies), no
 * SQL bindings, and none of the breadcrumbs that carry them.
 */
return [

    // A secret, set only in the deployment's environment. Unset or empty: nothing is sent.
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // Optional, for grouping events by release (the deployed commit, say).
    'release' => env('SENTRY_RELEASE'),

    // Unset: APP_ENV.
    'environment' => env('SENTRY_ENVIRONMENT'),

    'send_default_pii' => false,

    'max_request_body_size' => 'never',

    // Errors only: no performance traces, profiles, logs or metrics.
    'traces_sample_rate' => null,
    'profiles_sample_rate' => null,
    'enable_logs' => false,
    'enable_metrics' => false,

    'breadcrumbs' => [
        // A component's every public property and every update: an abstract's
        // text and its authors' names and addresses, on the submission form.
        'livewire' => false,
        // Rate-limiter keys hold client IP addresses and an invitee's address.
        'cache' => false,
        // Outgoing requests include the heartbeat URL, which is a secret.
        'http_client_requests' => false,
        'sql_bindings' => false,
    ],

];
// bootstrap/app.php, inside ->withExceptions():
        // Every exception Laravel reports also goes to the error tracker
        // (config/sentry.php); with no DSN the SDK drops it. The handler's
        // dontReport list (validation, authentication, every HTTP exception
        // such as a 404 or a 429) still applies first.
        Integration::handles($exceptions);
// .env.example, appended:
# --- Error tracking ---
# Sentry, GlitchTip or Bugsink: one SDK, the Sentry protocol (config/sentry.php). The DSN
# is a secret: set it only in the deployment's environment. Empty: nothing is reported.
SENTRY_LARAVEL_DSN=
# Optional: a release name to group events by, and an environment other than APP_ENV.
# SENTRY_RELEASE=
# SENTRY_ENVIRONMENT=
```
```yaml
      # Optional. The error tracker's DSN (config/sentry.php), a secret. Empty:
      # nothing is reported.
      SENTRY_LARAVEL_DSN: ${SENTRY_LARAVEL_DSN:-}
```
```xml
        <!-- No DSN, whatever .env holds, so no test reports to a real tracker;
             tests/Feature/Security/ErrorTrackingTest.php sets one in config. -->
        <env name="SENTRY_LARAVEL_DSN" value=""/>
```

- [ ] **Step 5: Green, the gates (N=3), commit.** `--filter='ErrorTrackingTest|EnvExampleTest'` to `/tmp/t-task-3-green.log`: `rc=0`, 3 passed. Then `test rc=0` at baseline + 15 (1,396), `stan rc=0`, `pint rc=0`. Commit the eight files with the subject `feat(monitoring): report exceptions to a Sentry-protocol tracker` and the body "sentry/sentry-laravel 4.29.0 (sentry/sentry 4.34.0) through Integration::handles(); config/sentry.php fixes the privacy settings in code: no personal data, no request bodies, no Livewire, cache or HTTP-client breadcrumbs, errors only. SENTRY_LARAVEL_DSN is optional: without it nothing is sent. composer audit: no advisories."

### Task 4: Nothing personal leaves for the tracker (B7, the privacy)
The settings leave four paths open (fact 11): frame arguments, addresses in exception messages and breadcrumbs, request headers, query strings and link tokens, and query values in database error messages. The first three close in `ScrubTrackerEvent`, Sentry's `before_send`, which runs after everything else (fact 12); the fourth closes at the source, `mask_bindings_in_exception_messages` on both connections, which keeps those values out of the server log too. In CI the ini drops frame arguments anyway (fact 13), so the synthetic-frame case is the one that proves their removal everywhere.

**Files:** create `app/Support/Monitoring/ScrubTrackerEvent.php`; modify `config/sentry.php`, `config/database.php`, `tests/Feature/Security/ErrorTrackingTest.php`.
**Interfaces:** consumes Task 3's `$this->track`, `$this->transport` and `config/sentry.php`. Produces `App\Support\Monitoring\ScrubTrackerEvent::beforeSend(Event $event, ?EventHint $hint = null): Event` as `sentry.before_send`, which Task 5's runbook and Task 6's decision name.

- [ ] **Step 1: The failing tests.** The test file's imports become, in this order: `App\Support\Monitoring\ScrubTrackerEvent`, `Illuminate\Database\QueryException`, `Illuminate\Support\Facades\DB`, `Illuminate\Validation\ValidationException`, `Sentry\Breadcrumb`, `Sentry\ClientBuilder`, `Sentry\Event`, `Sentry\ExceptionDataBag`, `Sentry\Frame`, `Sentry\Stacktrace`, `Sentry\State\HubInterface`, `Sentry\Transport\Result`, `Sentry\Transport\ResultStatus`, `Sentry\Transport\TransportInterface`, `Symfony\Component\HttpKernel\Exception\NotFoundHttpException`. Append:
```php
it('scrubs an event: frame arguments, addresses, headers, the query string and link tokens', function () {
    $frame = new Frame('App\Actions\Submissions\SubmitAbstract::handle', 'app/Actions/Submissions/SubmitAbstract.php', 40, null, null, ['data' => ['title' => 'A study of night shifts']]);
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('550 5.1.1 <sara@example.org>: Recipient address rejected'), new Stacktrace([$frame]))]);
    $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_ERROR, Breadcrumb::TYPE_DEFAULT, 'log.error', 'Mail to sara@example.org failed')]);
    $event->setRequest([
        'method' => 'GET', 'url' => 'https://cass.towardpcc.com/s/a-plain-status-token?email=sara@example.org', 'query_string' => 'email=sara@example.org',
        'headers' => ['cf-connecting-ip' => ['203.0.113.7'], 'referer' => ['https://cass.towardpcc.com/invite/a-plain-invitation-token']],
    ]);

    $scrubbed = ScrubTrackerEvent::beforeSend($event);

    expect($scrubbed->getExceptions()[0]->getValue())->toBe('550 5.1.1 <[email]>: Recipient address rejected')
        ->and($scrubbed->getExceptions()[0]->getStacktrace()?->getFrames()[0]->getVars())->toBe([])
        ->and($scrubbed->getBreadcrumbs()[0]->getMessage())->toBe('Mail to [email] failed')
        ->and($scrubbed->getRequest())->toBe(['method' => 'GET', 'url' => 'https://cass.towardpcc.com/s/[token]']);
});

it('sends a reported exception without frame arguments or addresses', function () {
    ($this->track)();
    $deliver = static function (array $author): void {
        throw new RuntimeException('Could not deliver to '.$author['email']);
    };

    try {
        $deliver(['email' => 'sara@example.org', 'name' => 'Sara Ahmed']);
    } catch (RuntimeException $exception) {
        report($exception);
    }

    $sent = $this->transport->events[0]->getExceptions()[0];
    $frames = $sent->getStacktrace()?->getFrames() ?? [];

    expect($sent->getValue())->toBe('Could not deliver to [email]')
        ->and($frames)->not->toBeEmpty()
        ->and(array_merge(...array_map(fn (Frame $frame): array => $frame->getVars(), $frames)))->toBe([]);
});

it('leaves query bindings out of a database error message', function () {
    $message = '';

    try {
        // users.email is NOT NULL: an insert that fails, with a name among its bindings.
        DB::table('users')->insert(['name' => 'Sara Ahmed', 'email' => null, 'password' => 'unused']);
    } catch (QueryException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toContain('insert into')->not->toContain('Sara Ahmed');
});
```

- [ ] **Step 2: Run them red.** `cd $REPO && php artisan test --compact --filter='ErrorTrackingTest' > /tmp/t-task-4-red.log 2>&1; echo "rc=$?"`. Expected: `rc=2`, 3 failed, 2 passed: `Error` 'Class "App\Support\Monitoring\ScrubTrackerEvent" not found'; "Failed asserting that two strings are identical." ('Could not deliver to sara@example.org'); "Expecting 'SQLSTATE[23000]: Integrity co…used))' not to contain 'Sara Ahmed'.".

- [ ] **Step 3: The scrubber and the masked values.** Create `app/Support/Monitoring/ScrubTrackerEvent.php`, namespace `App\Support\Monitoring`, imports `Sentry\Breadcrumb`, `Sentry\Event`, `Sentry\EventHint`, `Sentry\Stacktrace`, with the class below. `config/sentry.php`: `use App\Support\Monitoring\ScrubTrackerEvent;` after `declare`, with a blank line either side; the docblock's last line, ` * SQL bindings, and none of the breadcrumbs that carry them.`, becomes ` * SQL bindings, and none of the breadcrumbs that carry them. before_send` and ` * removes the rest: frame arguments, addresses, headers and link tokens.`; after `'max_request_body_size' => 'never',` a blank line and `'before_send' => [ScrubTrackerEvent::class, 'beforeSend'],`. `config/database.php`: in the `sqlite` connection after `'transaction_mode' => 'DEFERRED',`, and in the `mysql` connection after its `'options'` entry (the `]) : [],` line), the last part.
```php
/**
 * config/sentry.php's before_send: the last look at an event before it leaves
 * for the tracker. The settings there keep request bodies, personal data and the
 * breadcrumbs that carry form fields out; this removes what they let through.
 *
 * - Frame arguments. The image leaves zend.exception_ignore_args off, so every
 *   trace records them and the SDK sends them: an Action's $data would arrive
 *   whole, abstract and authors included.
 * - Email addresses in exception messages and breadcrumbs: a mail server names
 *   the address it refused, and MySQL quotes a duplicate value.
 * - The request beyond its method and path: headers (CF-Connecting-IP and
 *   Referer among them), the query string (a password-reset link carries an
 *   address) and the bearer token in /s/{token} and /invite/{token}.
 */
final class ScrubTrackerEvent
{
    private const string ADDRESS = '/[^\s@<>()\[\]"\',;:]+@[^\s@<>()\[\]"\',;:]+\.[a-z]{2,}/i';

    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::maskAddresses($exception->getValue()));
            self::dropArguments($exception->getStacktrace());
        }

        self::dropArguments($event->getStacktrace());

        $event->setBreadcrumb(array_map(
            static fn (Breadcrumb $crumb): Breadcrumb => $crumb->getMessage() === null
                ? $crumb
                : $crumb->withMessage(self::maskAddresses($crumb->getMessage())),
            $event->getBreadcrumbs(),
        ));

        $request = $event->getRequest();

        if ($request !== []) {
            $url = is_string($request['url'] ?? null)
                ? (string) preg_replace(['/[?#].*$/s', '#/(s|invite)/[^/]+#'], ['', '/$1/[token]'], $request['url'])
                : null;

            $event->setRequest(array_filter(['method' => $request['method'] ?? null, 'url' => $url]));
        }

        return $event;
    }

    private static function maskAddresses(string $text): string
    {
        return (string) preg_replace(self::ADDRESS, '[email]', $text);
    }

    private static function dropArguments(?Stacktrace $stacktrace): void
    {
        foreach ($stacktrace?->getFrames() ?? [] as $frame) {
            $frame->setVars([]);
        }
    }
}
// config/database.php, sqlite (the mysql connection takes the same key under `// As on sqlite above.`):
            // A failed query's message otherwise carries its bindings (an
            // abstract's title, an author's address) into the log and the error
            // tracker; QueryException::getBindings() still has them.
            'mask_bindings_in_exception_messages' => true,
```

- [ ] **Step 4: Green, the gates (N=4), commit.** The filter to `/tmp/t-task-4-green.log`: `rc=0`, 5 passed. Then `test rc=0` at baseline + 18 (1,399), `stan rc=0`, `pint rc=0`. Commit the four files with the subject `feat(monitoring): scrub tracker events and mask query values in database errors` and the body "ScrubTrackerEvent, Sentry's before_send, drops frame arguments, headers, query strings and link tokens and masks addresses; mask_bindings_in_exception_messages on the sqlite and mysql connections keeps query values out of error messages, the server log included."

### Task 5: Runbook "Monitoring", the backup pings and the admin's second factor (B5)
Docs only, with the Edit tool, in `docs/runbooks/deploy-production.md`. "Monitoring" stays near 70 lines, each part under 40. Services are named neutrally: the summary's recommendation is the owner's to take.

**Files:** modify `docs/runbooks/deploy-production.md`.
**Interfaces:** consumes the names Tasks 1 to 4 fixed: the set-up page, `cass:health --json --ping`, `CASS_HEARTBEAT_URL`, `SENTRY_LARAVEL_DSN`, `config/sentry.php`, `ScrubTrackerEvent`. Produces the sections "Monitoring" and "Platform admin second factor", which Task 6's HANDOVER rows cite. If Task 2 or 3 left the compose file alone, add to "Monitoring" that each variable first needs its line in `docker-compose.production.yml`.

- [ ] **Step 1: Setup and health.** "One-time setup" step 4: after "optional `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY`" insert ", optional `CASS_HEARTBEAT_URL` and `SENTRY_LARAVEL_DSN` (both secrets, see **Monitoring**)". Step 7 becomes "7. Log in at https://cass.towardpcc.com/admin/login. The panel opens "Set up two-factor authentication" first: set up the authenticator app and keep the recovery codes off the platform (see **Platform admin second factor**), then change the admin password under Profile." In "Health", after the paragraph ending "is a monitor.", add "`--ping` also GETs `CASS_HEARTBEAT_URL` when every row is OK; the scheduler runs `cass:health --json --ping` hourly (see **Monitoring**)."

- [ ] **Step 2: "Monitoring".** Insert before `## Rollback`:
````markdown
## Monitoring

Three outside signals: an uptime monitor on `/up`, three dead-man heartbeats and an
error tracker. The services are the owner's accounts. Every ping URL and the DSN is a
secret, held by the service and by the application's environment in Coolify or root's
crontab, never by this repository; `docs/HANDOVER.md` names the checks only.

### Uptime

An uptime service (UptimeRobot's free plan checks every 5 minutes; Better Stack also
has a free plan) requests `https://cass.towardpcc.com/up` every 1 to 5 minutes and
alerts by email and by its phone app. A 200 proves PHP answers behind Cloudflare and
Traefik. It proves nothing about the queue, the scheduler, the disk, pending
migrations or mail: that is `cass:health`, reported by the hourly heartbeat. Test it
with the service's test alert, or point the monitor at `/up-missing` for one check.

### Heartbeats

A heartbeat service (Healthchecks.io's free plan holds 20 checks) alerts when a ping
is later than its period plus its grace time. Create three checks:

| Check | Period | Grace | Pinged when this succeeded | Its URL lives in |
|---|---|---|---|---|
| hourly health | 1 hour | 20 minutes | the scheduler's `cass:health --json --ping`, every row OK | `CASS_HEARTBEAT_URL`, the application's environment (redeploy after setting it) |
| nightly dump | 1 day | 1 hour | the 03:17 `cass-backup` cron line | root's crontab (**Backups**) |
| weekly storage archive | 7 days | 2 hours | the Sunday 03:47 `cass-storage-backup` cron line | root's crontab (**Backups**) |

"hourly health" goes quiet on a red `cass:health` row, a dead scheduler, a stopped
container or a dead host: all look the same from outside. A failed ping is a warning
in the log and the next hour tries again. First:

```bash
C=$(cass_container app)
sudo docker exec "$C" su-exec app php artisan cass:health
sudo docker exec "$C" supervisorctl status
```

then the **Health** row that is red. A quiet backup check: `sudo tail -n 40
/var/log/cass-backup.log`, then the script by hand (**Backups**). To prove a check
alerts, pause it in the service, wait out its grace time, and resume it.

### Error tracker

Exceptions Laravel reports go to the tracker `SENTRY_LARAVEL_DSN` names (Sentry, or a
self-hosted GlitchTip or Bugsink): set it in the application's environment and
redeploy. An event carries the exception, its stack trace without arguments, the
route, the request's method and path, and breadcrumbs (SQL without values, log lines,
queue jobs). It never carries a request body, an IP address, the signed-in user,
cookies, headers, a query string, form fields, cache keys, a link token or an email
address (`config/sentry.php`, `App\Support\Monitoring\ScrubTrackerEvent`). Laravel's
unreported exceptions stay out: validation, authentication and authorization
failures, 404s, 429s and every other HTTP exception. After the redeploy:

```bash
C=$(cass_container app)
sudo docker exec "$C" su-exec app php artisan tinker --execute='report(new RuntimeException("tracker test"));'
```

The event appears in the tracker within a minute. Sentry's free plan has one user,
5,000 errors a month and 30 days of history; an error raised on every request spends
that in an afternoon, so turn on the service's usage alert.

### When an alert fires

| Alert | First look | Then |
|---|---|---|
| `/up` down | `sudo docker ps`, the deployment list in Coolify | **Rollback** if a release did it; otherwise the host |
| hourly health late | `cass:health`, `supervisorctl status` | the red **Health** row |
| nightly dump or weekly archive late | `/var/log/cass-backup.log` | the script by hand (**Backups**) |
| a new issue in the tracker | its stack trace and route | a fix through a pull request, never an edit in the container |
````

- [ ] **Step 3: The backup pings.** In "Backups", append ` && curl -fsS -m 10 --retry 3 -o /dev/null <nightly dump ping URL>` to the nightly cron line and to its `CASS_MYSQL_CONTAINER` variant, and ` && curl -fsS -m 10 --retry 3 -o /dev/null <weekly storage archive ping URL>` to the Sunday line; `&&` runs the ping only when the script exited 0, so a failed or refused backup is silence. After the first cron block add: "The URL after `curl` is the heartbeat check's (see **Monitoring**) and a secret: anyone holding it can mark the backup done. It lives only in root's crontab, and `sudo crontab -l` shows it."

- [ ] **Step 4: The second factor and key rotation.** In "Secret rotation", "Rotating `APP_KEY` invalidates all sessions and the encrypted two-factor secrets; announce a re-login and re-enrolment." becomes "Rotating `APP_KEY` invalidates all sessions and makes every stored two-factor secret unreadable: an enrolled user's sign-in answers 500 until the factor is cleared and set up again (see **Platform admin second factor**). `APP_PREVIOUS_KEYS` would keep them readable, but the compose file does not pass it yet (backlog, "Monitoring"). Announce a re-login and re-enrolment." Then insert before `## Trusted proxies`:
````markdown
## Platform admin second factor

The admin panel requires an authenticator app (`AdminPanelProvider`); the organizer
and reviewer panels offer it without requiring it. The first sign-in after the release,
and any sign-in by an admin with no second factor, opens "Set up two-factor
authentication" at `/admin/multi-factor-authentication/set-up`: scan the code, type one
code back, and keep the eight recovery codes it shows once somewhere that is not this
platform. Enrol in the same session as the deploy: until then, whoever holds the
password could enrol first.

- Lost phone, codes at hand: type a recovery code at the code prompt, then in Profile
  disable the app and set it up again on the new phone, with new recovery codes.
- Lost phone and codes, or an `APP_KEY` rotated without `APP_PREVIOUS_KEYS`: the owner
  clears the factor on the host (a production write), then signs in and enrols again.

```bash
C=$(cass_container app)
sudo docker exec "$C" su-exec app php artisan tinker --execute='App\Models\User::query()->where("email", "<admin email>")->firstOrFail()->forceFill(["app_authentication_secret" => null, "app_authentication_recovery_codes" => null])->save();'
```
````

- [ ] **Step 5: The gates (N=5), commit.** `git status --short` lists only the runbook. Expected: `test rc=0` at baseline + 18 (no code changed), `stan rc=0`, `pint rc=0`. Commit it with `docs: runbook Monitoring, the backup pings and the admin second factor`.

### Task 6: The record, the final gates and the pull request
**Files:** create `docs/decisions/0015-error-tracking-and-heartbeats.md` (Write tool); modify `docs/HANDOVER.md`, `docs/superpowers/plans/backlog.md`, `docs/launch-checklist.md` (Edit tool).
**Interfaces:** consumes every name above and the PR number `gh` prints. Produces the owner's next steps in `docs/HANDOVER.md`.

- [ ] **Step 1: The decision.** In the format of decisions 0013 and 0014 (`docs/decisions/README.md`):
```markdown
# Error tracking speaks the Sentry protocol; the DSN and ping URLs stay out of the repository

- **Context.** Nothing reported an exception, a dead scheduler or a skipped backup to anyone (`docs/reviews/2026-10-16-expert-brief.md`, question 4 and work order B5 to B7). The brief left the tracker to the owner: Flare, a self-hosted GlitchTip, or similar.
- **Decision.** Engineering default, pending the owner's choice of service (Plan 9 summary, 2026-10-18): `sentry/sentry-laravel`, because one SDK and one variable, `SENTRY_LARAVEL_DSN`, work with Sentry's hosted free plan, a self-hosted GlitchTip and Bugsink. The privacy floor is in code, not the environment: no request bodies, no personal data, no Livewire, cache or HTTP-client breadcrumbs, query values masked at the connection, and `App\Support\Monitoring\ScrubTrackerEvent` dropping frame arguments, headers, query strings and link tokens and masking addresses. Heartbeats are pings on success: `cass:health --json --ping` hourly to `CASS_HEARTBEAT_URL`, and `curl` after each backup cron. The DSN and the three ping URLs are secrets, held by the application's environment and root's crontab; this repository and `docs/HANDOVER.md` name the checks only.
- **Consequence.** An event shows the line, the route and the stack, never the abstract or the author; a data-dependent error is reproduced from the database. Choosing Flare later means `spatie/laravel-flare` and `FLARE_KEY` under the same privacy rules.
- **To reverse.** Remove `Integration::handles()` from `bootstrap/app.php`, then `sentry/sentry-laravel`, `config/sentry.php` and `ScrubTrackerEvent`, and delete `tests/Feature/Security/ErrorTrackingTest.php`; drop `--ping` from the hourly entry in `routes/console.php` and its cases in `tests/Unit/HealthCommandTest.php`.
```

- [ ] **Step 2: Backlog and checklist.** In `docs/launch-checklist.md` section 2, the second-factor item (its four lines, from "- [ ] **Two-factor authentication" to "this platform**.") becomes the first block below. In `docs/superpowers/plans/backlog.md`, insert the second block before "## Open questions for the owner (raised by Plans 7 and 8)".
```markdown
- [ ] **Two-factor authentication on the platform admin account.** All three
      panels offer `AppAuthentication::make()->recoverable()`, and the admin
      panel requires it: the first sign-in opens the set-up page. Enrol there,
      and **store the recovery codes somewhere that is not this platform**.
```
```markdown
## Monitoring (deferred by Plan 9)

- **Turnstile's fail-open warning is a log line, not a tracker event.** The expert brief accepts failing open only if an hour of it is seen (question 5); `Log::warning()` reaches the tracker only as a breadcrumb. After B1, add a `report()` in `App\Support\Turnstile` or a warning-level `sentry` channel in production's log stack. Decision: `docs/decisions/0015-error-tracking-and-heartbeats.md`.
- **Tracker events carry no release.** `SENTRY_RELEASE: ${SOURCE_COMMIT:-}` in the compose file would tag each event with the deployed commit, but whether the reference reaches the container, or surfaces as an empty variable that shadows the real one, is unverified. Try it in a quiet release and read the release on the runbook's tracker-test event.
- **`APP_PREVIOUS_KEYS` does not reach the container**, so an `APP_KEY` rotation makes every stored second factor unreadable (a 500 at sign-in) until cleared on the host. One compose line, `APP_PREVIOUS_KEYS: ${APP_PREVIOUS_KEYS:-}`, ends it.
- **Server logs keep frame arguments.** The image leaves `zend.exception_ignore_args` off, so a logged trace shows the start of each string argument; `ScrubTrackerEvent` cleans tracker events only. `zend.exception_ignore_args=On` in `docker/php.ini` ends it, with an image rebuild.
```

- [ ] **Step 3: HANDOVER, with `#NN` for the pull request.** In the phase-B table the Status cells become: B2 "Engineer part done (#NN): the admin panel requires a second factor. Owner next, in the deploy session: sign in at `/admin`, set up the authenticator app on the page that opens, keep the recovery codes off the platform (runbook "Platform admin second factor")"; B5 "Runbook "Monitoring" written (#NN). Owner next: an uptime monitor on `/up`, then its test alert"; B6 "Engineer part done (#NN): `cass:health --json --ping` hourly; the cron pings are in runbook "Backups". Owner next: the three checks (hourly health, nightly dump, weekly storage archive), `CASS_HEARTBEAT_URL` set and redeployed, the two cron lines edited, one check paused to see it alert"; B7 "Engineer part done (#NN): the Sentry SDK, scrubbed. Owner next: pick the service, set `SENTRY_LARAVEL_DSN`, redeploy, run the runbook's tracker test". In "Where things stand", after the Backups row, add the row below. In "How the work is done", "Plans 1–8 and the backlog" becomes "Plans 1–9 and the backlog".
```markdown
| Monitoring | Not wired yet; the code is in #NN and idle until configured: `cass:health --json --ping` runs hourly and pings once `CASS_HEARTBEAT_URL` is set, and reported exceptions reach the tracker once `SENTRY_LARAVEL_DSN` is set. Owner steps in runbook "Monitoring": the uptime monitor, the three heartbeat checks (hourly health, nightly dump, weekly storage archive), the two cron pings, the DSN. |
```

- [ ] **Step 4: The final gates (N=6), commit.** Expected: `test rc=0` at baseline + 18 (1,399 passed, 1 skipped), `stan rc=0`, `pint rc=0`. Commit the four docs with `docs: decision 0015, the monitoring backlog, HANDOVER for plan 9 (#NN)`.

- [ ] **Step 5: Push and open the pull request.** Write `C:\Users\khawl\AppData\Local\Temp\pr-plan9.md` (Write tool) with the body below; if Task 2 or 3 left the compose file alone, drop its Sensitive-files bullet and its clause under Environment variables. Then:
```bash
cd $REPO && git push -u origin plan-9-phase-b-ops > /tmp/push-task-6.log 2>&1; echo "push rc=$?"
gh pr create --base main --head plan-9-phase-b-ops --title "Plan 9: phase-B operations (B2, B5, B6, B7)" --body-file /tmp/pr-plan9.md > /tmp/pr-task-6.log 2>&1; echo "pr rc=$?"
```
```markdown
**Summary.** The operations floor of the expert brief's phase B. B2: the platform-admin panel requires a second factor; an admin without one is sent from every admin page to Filament's set-up page, and organizers and reviewers are unaffected. B6: `cass:health --ping` GETs `CASS_HEARTBEAT_URL` only when every row is OK, scheduled hourly as `--json --ping`; the backup cron pings are in the runbook. B7: once `SENTRY_LARAVEL_DSN` is set, reported exceptions go to a Sentry-protocol tracker (`sentry/sentry-laravel` 4.29), without bodies, personal data, form fields, frame arguments, addresses, query values or link tokens. B5: runbook "Monitoring".

**Sensitive files.**
- bootstrap/app.php: `Integration::handles($exceptions)` sends reported exceptions to the tracker.
- config/sentry.php (new): the tracker's settings, the privacy ones fixed in code.
- config/database.php: `mask_bindings_in_exception_messages` on the sqlite and mysql connections.
- config/cass.php: `heartbeat_url`.
- docker-compose.production.yml: passes the optional `CASS_HEARTBEAT_URL` and `SENTRY_LARAVEL_DSN` to the app container.
- Also worth a look, though not on the list: app/Providers/Filament/AdminPanelProvider.php (`isRequired: true`), composer.json and composer.lock (one production dependency; `composer audit` clean, /tmp/audit-task-3.log).

**Migrations.** None.

**Environment variables.** `CASS_HEARTBEAT_URL` and `SENTRY_LARAVEL_DSN`, both optional secrets, in .env.example, the compose file and the runbook; `SENTRY_RELEASE` and `SENTRY_ENVIRONMENT`, optional, in .env.example only.

**Docs changed.** docs/runbooks/deploy-production.md (Monitoring, Backups, Platform admin second factor, One-time setup, Health, Secret rotation), docs/HANDOVER.md, docs/decisions/0015-error-tracking-and-heartbeats.md (new), docs/superpowers/plans/backlog.md, docs/launch-checklist.md, .env.example.

**Gate logs.** /tmp/t-task-1.log to /tmp/t-task-6.log, /tmp/phpstan-task-6.log, /tmp/pint-task-6.log, all rc=0; the red runs are /tmp/t-task-1-red.log to /tmp/t-task-4-red.log.

**Tests added (baseline + 18).** tests/Feature/Admin/SecondFactorRequiredTest.php (7), six cases in tests/Unit/HealthCommandTest.php, tests/Feature/Security/ErrorTrackingTest.php (5).

**Anything skipped.** Turnstile's fail-open warning stays a log line; tracker events carry no release; `APP_PREVIOUS_KEYS` is not passed to the container; server logs keep frame arguments (backlog, "Monitoring"). The owner's steps (enrolment, the three checks, the uptime monitor, the DSN) are in docs/HANDOVER.md.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

- [ ] **Step 6: The number.** Replace every `#NN` in `docs/HANDOVER.md` with the number `gh` printed, and rewrite the "Open pull requests" row from `gh pr list --state open` at that moment: this pull request first ("#N (Plan 9, the phase-B operations B2, B5, B6 and B7; awaiting the owner's merge)."), then one clause per other open pull request. Commit with `docs: HANDOVER records plan 9 (#N)` and push. Then watch CI, in the background because the arm64 `image` job runs (a compose file changed) and takes about half an hour: `cd $REPO && gh pr checks <N> --watch --interval 60 > /tmp/ci-task-6.log 2>&1; echo "ci rc=$?"`. Expected: `push rc=0`, then `ci rc=0`; on a red job, read its log with `gh run view --log-failed` and report it rather than retrying.

## Verification

- After Task 6 the gates (N=6) exit 0, with the suite at baseline + 18.
- CI is green on the pull request: SQLite and MySQL suites (the masked-bindings case meets MySQL's NOT NULL error there for the first time), the smoke build of the image with the new production dependency, and the arm64 `image` job.
- Every red step failed for the reason stated, before its code: `/tmp/t-task-1-red.log` to `/tmp/t-task-4-red.log`.
- The pull request lists the sensitive files, no migration, the variables, the tests, what was skipped, the docs and the gate logs, for the owner's review point 2.

## Deferred

- `docs/superpowers/plans/backlog.md`, "Monitoring (deferred by Plan 9)": Turnstile's warning as an event, release tagging, `APP_PREVIOUS_KEYS`, frame arguments in the server log (Task 6, Step 2).
- Owner question 3 ships the log line; a yes is the first backlog entry. Questions 1 and 2 are the owner's choices of service, and Flare means re-planning Tasks 3 and 4 before implementation.
