<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Auth\Login;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\PlatformAdminSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->unenrolled = User::factory()->platformAdmin()->withoutSecondFactor()->create();

    // An enrolled admin who may also sign in at /org and /review: an organization owner and an active reviewer.
    $this->enrolledEverywhere = function (): User {
        $admin = User::factory()->platformAdmin()->create(['email' => 'admin@example.org']);
        $organization = Organization::factory()->approved()->create();
        $organization->addMember($admin, OrganizationRole::Owner);
        $conference = Conference::factory()->for($organization)->closed()->create(['review_deadline' => now()->addMonth()]);
        ConferenceReviewer::factory()->for($conference)->create(['user_id' => $admin->id]);

        return $admin;
    };

    // Six digits the admin's app shows at no moment within four minutes of now.
    $this->wrongCode = function (User $admin): string {
        $app = AppAuthentication::make();

        do {
            $code = $app->getCurrentCode($admin, $app->generateSecret());
        } while ($app->verifyCode($code, $admin->getAppAuthenticationSecret()));

        return $code;
    };

    // The password step at the current panel's sign-in, which the other cases skip with actingAs().
    $this->signIn = fn () => livewire(Login::class)
        ->fillForm(['email' => 'admin@example.org', 'password' => 'password'])
        ->call('authenticate');
});

it('sends a platform admin without a second factor from every admin page to set one up', function (string $path) {
    actingAs($this->unenrolled)->get($path)->assertRedirect('/admin/multi-factor-authentication/set-up');
})->with(['dashboard' => '/admin', 'organizations' => '/admin/organizations', 'profile' => '/admin/profile']);

it('puts the second-factor check on every admin page but the sign-in ones', function () {
    $open = ['filament.admin.auth.login', 'filament.admin.auth.password-reset.request', 'filament.admin.auth.password-reset.reset', 'filament.admin.auth.multi-factor-authentication.set-up-required'];
    $router = app(Router::class);
    $pages = collect($router->getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'filament.admin.')
            && in_array('GET', $route->methods(), true)
            && ! in_array($route->getName(), $open, true));
    // Resolved after each route's withoutMiddleware(), so a page that drops the check fails too.
    expect($pages)->not->toBeEmpty();
    $pages->each(fn (Route $route) => expect($router->gatherRouteMiddleware($route))->toContain(EnsureMultiFactorAuthenticationIsEnabled::class));
});

it('shows that admin the set-up page, which offers the authenticator app', function () {
    actingAs($this->unenrolled)->get('/admin/multi-factor-authentication/set-up')
        ->assertOk()
        ->assertSee(__('filament-panels::auth/multi-factor/pages/set-up-required-multi-factor-authentication.heading'))
        ->assertSee(__('filament-panels::auth/multi-factor/app/provider.management_schema.actions.label'));
});

it('refuses the set-up page to a signed-in user who is not a platform admin', function () {
    $owner = User::factory()->create();
    Organization::factory()->approved()->create()->addMember($owner, OrganizationRole::Owner);
    actingAs($owner)->get('/admin/multi-factor-authentication/set-up')->assertForbidden();
});

it('sends a guest from the set-up page to the admin sign-in', function () {
    get('/admin/multi-factor-authentication/set-up')->assertRedirect('/admin/login');
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

it('asks an enrolled platform admin for the app code at every panel\'s sign-in: the password alone signs nobody in', function (string $panel) {
    // Login overrides authenticate(). The three panels share one session guard, and /admin asks only whether the
    // account is enrolled, so a sign-in at /org or /review that skipped the code would open /admin too.
    $admin = ($this->enrolledEverywhere)();
    Filament::setCurrentPanel($panel);

    $login = ($this->signIn)()
        ->assertHasNoFormErrors()
        ->assertNoRedirect()
        ->assertNotSet('userUndertakingMultiFactorAuthentication', null);
    expect(Auth::guest())->toBeTrue();

    $login->set('data.multiFactor.app.code', AppAuthentication::make()->getCurrentCode($admin))->call('authenticate');
    expect(Auth::id())->toBe($admin->id);
})->with(['admin', 'organizer', 'reviewer']);

it('refuses an app code two steps old at every panel\'s sign-in', function (string $panel) {
    // One 30-second step either side of now, not Filament's default of eight (four minutes each way).
    $admin = ($this->enrolledEverywhere)();
    Filament::setCurrentPanel($panel);
    $totp = app(Google2FA::class);
    $stale = $totp->oathTotp($admin->getAppAuthenticationSecret(), $totp->getTimestamp() - 2);

    ($this->signIn)()
        ->set('data.multiFactor.app.code', $stale)
        ->call('authenticate')
        ->assertHasFormErrors(['multiFactor.app.code']);
    expect(Auth::guest())->toBeTrue();
})->with(['admin', 'organizer', 'reviewer']);

it('refuses a wrong app code after the password', function () {
    $admin = User::factory()->platformAdmin()->create(['email' => 'admin@example.org']);
    Filament::setCurrentPanel('admin');

    ($this->signIn)()
        ->set('data.multiFactor.app.code', ($this->wrongCode)($admin))
        ->call('authenticate')
        ->assertHasFormErrors(['multiFactor.app.code']);
    expect(Auth::guest())->toBeTrue();
});

it('refuses every app code for 15 minutes after five attempts, and logs the lockout once', function () {
    Log::spy();
    $admin = User::factory()->platformAdmin()->create(['email' => 'admin@example.org']);
    Filament::setCurrentPanel('admin');
    $login = ($this->signIn)();

    // A minute apart, so the password's own limit (five a minute) never answers first.
    foreach (range(1, 5) as $attempt) {
        $this->travel(61)->seconds();
        $login->set('data.multiFactor.app.code', ($this->wrongCode)($admin))->call('authenticate')->assertHasFormErrors(['multiFactor.app.code']);
    }

    // Filament's own lock would have lifted a minute after each attempt; this one refuses the right code too.
    foreach (range(1, 2) as $attempt) {
        $this->travel(61)->seconds();
        $login->set('data.multiFactor.app.code', AppAuthentication::make()->getCurrentCode($admin))->call('authenticate')->assertNotified();
    }
    expect(Auth::guest())->toBeTrue();
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => str_starts_with($message, 'Second-factor sign-in locked') && $context['user_id'] === $admin->id,
    );

    $this->travel(15)->minutes();
    $login->set('data.multiFactor.app.code', AppAuthentication::make()->getCurrentCode($admin))->call('authenticate');
    expect(Auth::id())->toBe($admin->id);
});

it('signs an enrolled admin in with a recovery code once, and refuses it the second time', function () {
    // The factory keeps only hashes, so this admin gets two codes whose plain text the test knows.
    $admin = User::factory()->platformAdmin()->create([
        'email' => 'admin@example.org',
        'app_authentication_recovery_codes' => [Hash::make('recovery-code-one'), Hash::make('recovery-code-two')],
    ]);
    Filament::setCurrentPanel('admin');
    $useRecoveryCode = fn () => ($this->signIn)()
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', 'recovery-code-one')
        ->call('authenticate');

    $useRecoveryCode()->assertHasNoFormErrors();
    expect(Auth::id())->toBe($admin->id)
        ->and($admin->fresh()?->getAppAuthenticationRecoveryCodes())->toHaveCount(1);

    Auth::logout();
    $useRecoveryCode()->assertHasFormErrors(['multiFactor.app.recoveryCode']);
    expect(Auth::guest())->toBeTrue();
});

it('signs out an admin session opened before the password changed', function () {
    // Enrolling ends no session; a new password does (runbook "Platform admin second factor").
    $admin = User::factory()->platformAdmin()->create();

    actingAs($admin)->withSession(['password_hash_web' => 'a-hash-from-before-the-password-change'])
        ->get('/admin')
        ->assertRedirect('/admin/login');
    expect(Auth::guest())->toBeTrue();
});

it('signs out an admin browser remembered before the password changed', function () {
    $admin = User::factory()->platformAdmin()->create(['remember_token' => 'a-remember-token']);
    $cookie = Auth::guard('web')->getRecallerName();

    // The cookie carries the password hash it was issued with: with today's hash it still gets in.
    $this->withCookie($cookie, $admin->id.'|a-remember-token|'.$admin->getAuthPassword())->get('/admin')->assertOk();
    Auth::forgetGuards();
    $this->flushSession();

    $this->withCookie($cookie, $admin->id.'|a-remember-token|a-hash-from-before-the-password-change')
        ->get('/admin')
        ->assertRedirect('/admin/login');
    expect(Auth::guest())->toBeTrue();
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

it('lets the runbook\'s host-side clear unlock an admin after an APP_KEY rotation', function () {
    // Both columns are encrypted with APP_KEY. Under a new key without APP_PREVIOUS_KEYS neither can be read,
    // and the runbook's statement, this one, nulls both without reading them.
    User::factory()->platformAdmin()->create(['email' => 'admin@example.org']);
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('app.previous_keys', []);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
    $admin = fn (): User => User::query()->where('email', 'admin@example.org')->firstOrFail();

    actingAs($admin())->get('/admin')->assertStatus(500);

    $admin()->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
    actingAs($admin())->get('/admin')->assertRedirect('/admin/multi-factor-authentication/set-up');
});
