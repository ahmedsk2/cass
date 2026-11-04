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

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->unenrolled = User::factory()->platformAdmin()->withoutSecondFactor()->create();
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

it('asks an enrolled platform admin for the app code: the password alone signs nobody in', function () {
    // The other cases sign in with actingAs(), which skips this prompt; Login overrides authenticate().
    $admin = User::factory()->platformAdmin()->create(['email' => 'admin@example.org']);
    Filament::setCurrentPanel('admin');

    $login = livewire(Login::class)
        ->fillForm(['email' => 'admin@example.org', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertNoRedirect()
        ->assertNotSet('userUndertakingMultiFactorAuthentication', null);
    expect(Auth::guest())->toBeTrue();

    $login->set('data.multiFactor.app.code', AppAuthentication::make()->getCurrentCode($admin))->call('authenticate');
    expect(Auth::id())->toBe($admin->id);
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
