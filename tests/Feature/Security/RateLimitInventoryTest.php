<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Auth\Login;
use App\Models\Organization;
use App\Models\User;
use App\Support\ClientIp;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Livewire\livewire;

/**
 * Spec section 9: "Rate limits: submission 5/min/IP, login 5/min/email+IP,
 * invitation accept 10/min/IP, contact form 3/min/IP."
 *
 * Three of the four were met by Plans 1-4. This file is the inventory that
 * says so, and the login case is the one that was not.
 */
it('registers the four named limiters the routes ask for', function (string $limiter) {
    expect(RateLimiter::limiter($limiter))->not->toBeNull($limiter);
})->with(['conference-assets', 'file-download', 'submission-status', 'invitation-accept']);

it('keys panel login on the email and the cloudflare client address', function () {
    Filament::setCurrentPanel('organizer');

    $page = livewire(Login::class);

    $first = $page->instance()->rateLimitKeyFor('someone@example.org', '203.0.113.7');
    $sameEmailOtherIp = $page->instance()->rateLimitKeyFor('someone@example.org', '198.51.100.4');
    $otherEmailSameIp = $page->instance()->rateLimitKeyFor('other@example.org', '203.0.113.7');

    // Both halves matter. IP alone is what the package does, and behind
    // Cloudflare that is one bucket for the whole internet: five wrong
    // passwords anywhere would lock out every organizer for a minute.
    expect($first)->not->toBe($sameEmailOtherIp)
        ->and($first)->not->toBe($otherEmailSameIp)
        // Case-folded: an attacker retrying with a capital letter must not get
        // a fresh budget.
        ->and($page->instance()->rateLimitKeyFor('SOMEONE@example.org', '203.0.113.7'))->toBe($first);
});

it('honours the cloudflare header the rest of the application honours', function () {
    // Against a request that is actually trusted, and through getRateLimitKey()
    // - the method the package calls. Comparing rateLimitKeyFor() with two
    // different literal IPs is injective by construction and would still pass
    // if getRateLimitKey() read request()->ip(), which behind Cloudflare is one
    // bucket for the whole internet: five wrong passwords anywhere would lock
    // out every organizer for a minute.
    //
    // Trusted proxies are global static state on the Request class, set by the
    // TrustProxies middleware during a real request, so this sets its own and
    // puts back whatever was there - the idiom tests/Unit/ClientIpTest.php uses.
    $proxies = Request::getTrustedProxies();
    $headerSet = Request::getTrustedHeaderSet();
    Request::setTrustedProxies(['10.0.0.0/8'], Request::HEADER_X_FORWARDED_FOR);

    try {
        Filament::setCurrentPanel('organizer');

        $request = Request::create('/org/login', server: ['REMOTE_ADDR' => '10.0.0.5']);
        $request->headers->set('CF-Connecting-IP', '203.0.113.7');
        app()->instance('request', $request);

        $login = new Login;
        $login->data = ['email' => 'a@example.org'];

        expect(ClientIp::from($request))->toBe('203.0.113.7')
            ->and((fn (): string => $this->getRateLimitKey('authenticate'))->call($login))
            ->toBe($login->rateLimitKeyFor('a@example.org', '203.0.113.7'))
            ->and((fn (): string => $this->getRateLimitKey('authenticate'))->call($login))
            ->not->toBe($login->rateLimitKeyFor('a@example.org', '10.0.0.5'));
    } finally {
        Request::setTrustedProxies($proxies, $headerSet);
    }
});

it('locks one email out after five wrong passwords and leaves another alone', function () {
    // A member of an organization, not a bare user: the organizer panel's
    // canAccessPanel() is `$this->organizations()->exists()`
    // (app/Models/User.php:108), and Filament refuses a user who may not enter
    // the panel with the SAME "credentials do not match" error as a wrong
    // password - so a bare user could never prove that the correct password
    // still works once the limiter has let go.
    $organization = Organization::factory()->approved()->create();
    $organization->addMember(
        User::factory()->create(['email' => 'owner@example.org', 'password' => bcrypt('correct-horse-battery')]),
        OrganizationRole::Owner,
    );

    Filament::setCurrentPanel('organizer');

    // Five attempts fit inside the budget: WithRateLimiting checks
    // tooManyAttempts() BEFORE hit(), so the sixth call is the refused one.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        livewire(Login::class)
            ->fillForm(['email' => 'owner@example.org', 'password' => 'wrong'])
            ->call('authenticate')
            ->assertHasFormErrors();
    }

    // The sixth is refused by the limiter. Filament::authenticate() catches
    // TooManyRequestsException, sends a notification and returns null BEFORE
    // the form is validated (Auth/Pages/Login.php:68-75), so there is no form
    // error to assert - the proof is the notification plus the fact that the
    // CORRECT password no longer signs anybody in.
    livewire(Login::class)
        ->fillForm(['email' => 'owner@example.org', 'password' => 'correct-horse-battery'])
        ->call('authenticate')
        ->assertNotified();

    expect(Auth::guest())->toBeTrue()
        ->and(RateLimiter::tooManyAttempts(
            app(Login::class)->rateLimitKeyFor('owner@example.org', ClientIp::from(request())),
            5,
        ))->toBeTrue();

    // A different address is unaffected, which is the whole point of adding
    // the email to the key.
    $organization->addMember(
        User::factory()->create(['email' => 'other@example.org', 'password' => bcrypt('correct-horse-battery')]),
        OrganizationRole::Member,
    );

    livewire(Login::class)
        ->fillForm(['email' => 'other@example.org', 'password' => 'correct-horse-battery'])
        ->call('authenticate')
        ->assertHasNoFormErrors();
});

it('still stops one address spraying many accounts', function () {
    config()->set('cass.security.login_ip_limit', 5);

    Filament::setCurrentPanel('organizer');

    foreach (range(1, 6) as $n) {
        livewire(Login::class)
            ->fillForm(['email' => "victim{$n}@example.org", 'password' => 'Password123!'])
            ->call('authenticate');
    }

    // A fresh, never-tried address from the same client is refused: the
    // per-email bucket is empty, the per-IP one is not. Without the wide
    // bucket, keying only on email+IP would hand one client five attempts per
    // minute for EVERY address - password spraying with no ceiling at all.
    livewire(Login::class)
        ->fillForm(['email' => 'victim7@example.org', 'password' => 'Password123!'])
        ->call('authenticate')
        ->assertNotified()
        ->assertHasNoFormErrors();
});
