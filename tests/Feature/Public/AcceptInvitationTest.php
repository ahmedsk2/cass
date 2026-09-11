<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Livewire\Public\AcceptInvitation;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ReviewerInvitation;
use App\Models\User;
use App\Support\Tokens\InvitationToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->plain = InvitationToken::generate();
    $this->invitation = OrganizationInvitation::factory()->for($this->organization)->create([
        'email' => 'new@example.org',
        'role' => OrganizationRole::Admin,
        'token_hash' => InvitationToken::hash($this->plain),
    ]);
});

it('404s on a token that resolves to nothing', function () {
    get('/invite/'.InvitationToken::generate())->assertNotFound();
    // A path that could not be a token never reaches the database: the route
    // constraint is [A-Za-z0-9]{64}.
    get('/invite/short')->assertNotFound();
});

it('shows the invitation and an account form to a stranger', function () {
    get('/invite/'.$this->plain)
        ->assertOk()
        ->assertSee('Alpha Society')
        ->assertSee('new@example.org')
        ->assertSee(__('members.invite.create_account'));
});

it('creates a verified account, attaches the member and signs them in', function () {
    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->set('name', 'Dr Layla Ahmed')
        ->set('password', 'correct-horse-99')
        ->set('password_confirmation', 'correct-horse-99')
        ->call('createAccount')
        ->assertHasNoErrors()
        ->assertRedirect('/org/'.$this->organization->slug);

    $user = User::query()->where('email', 'new@example.org')->firstOrFail();

    expect($user->name)->toBe('Dr Layla Ahmed')
        // Clicking a 64-character secret that only ever reached this mailbox IS
        // the proof VerifyEmail asks for, so no second verification email.
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->roleIn($this->organization))->toBe(OrganizationRole::Admin)
        ->and(Auth::id())->toBe($user->id)
        ->and($this->invitation->fresh()?->accepted_at)->not->toBeNull()
        ->and($this->invitation->fresh()?->accepted_by)->toBe($user->id);

    expect(Activity::query()->where('description', 'organization.invitation_accepted')->count())->toBe(1);
});

it('refuses a weak password and creates nothing', function () {
    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->set('name', 'Dr Layla Ahmed')
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('createAccount')
        ->assertHasErrors(['password']);

    expect(User::query()->where('email', 'new@example.org')->exists())->toBeFalse()
        ->and($this->invitation->fresh()?->accepted_at)->toBeNull();
});

it('accepts for an existing account on its password without signing it in', function () {
    $existing = User::factory()->create(['email' => 'new@example.org', 'password' => 'correct-horse-99']);

    get('/invite/'.$this->plain)->assertOk()->assertSee(__('members.invite.sign_in'));

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->set('password', 'correct-horse-99')
        ->call('signIn')
        ->assertHasNoErrors()
        ->assertRedirect($this->invitation->fresh()?->landingUrl());

    expect($existing->fresh()?->roleIn($this->organization))->toBe(OrganizationRole::Admin)
        ->and($this->invitation->fresh()?->accepted_by)->toBe($existing->id)
        // No session: the password is VERIFIED here, never used to authenticate.
        // Both the admin and organizer panels enable multi-factor
        // authentication, and Filament challenges that factor only inside its
        // own Login page - so an Auth::attempt() here would be a factor-free
        // front door for anyone holding the link. The redirect lands on the
        // panel, whose Authenticate middleware sends them to the login that
        // does challenge it.
        ->and(Auth::check())->toBeFalse();
});

it('refuses a wrong password without saying whether the account exists', function () {
    User::factory()->create(['email' => 'new@example.org', 'password' => 'correct-horse-99']);

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->set('password', 'wrong-password-11')
        ->call('signIn')
        ->assertHasErrors(['password']);

    expect(Auth::check())->toBeFalse()
        ->and($this->invitation->fresh()?->accepted_at)->toBeNull();
});

it('accepts with one click when the right person is already signed in', function () {
    $existing = User::factory()->create(['email' => 'new@example.org']);
    actingAs($existing);

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->call('accept')
        ->assertHasNoErrors()
        ->assertRedirect('/org/'.$this->organization->slug);

    expect($existing->fresh()?->roleIn($this->organization))->toBe(OrganizationRole::Admin);
});

it('refuses to accept while signed in as somebody else', function () {
    $other = User::factory()->create(['email' => 'someone.else@example.org']);
    actingAs($other);

    get('/invite/'.$this->plain)->assertOk()->assertSee('someone.else@example.org');

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->call('accept')
        ->assertHasErrors(['token']);

    // The whole point: accepting as the wrong account would be a way to have
    // an address you do not control marked verified on an account you do.
    expect($other->fresh()?->roleIn($this->organization))->toBeNull()
        ->and($this->invitation->fresh()?->accepted_at)->toBeNull();
});

it('marks an existing unverified account verified on accept', function () {
    $existing = User::factory()->unverified()->create(['email' => 'new@example.org']);
    actingAs($existing);

    livewire(AcceptInvitation::class, ['token' => $this->plain])->call('accept')->assertHasNoErrors();

    expect($existing->fresh()?->hasVerifiedEmail())->toBeTrue();
});

it('explains an expired, revoked or already accepted invitation instead of 404ing', function () {
    $expired = InvitationToken::generate();
    OrganizationInvitation::factory()->for($this->organization)->expired()
        ->create(['token_hash' => InvitationToken::hash($expired)]);

    get('/invite/'.$expired)->assertOk()->assertSee(__('members.invite.expired'));

    $revoked = InvitationToken::generate();
    OrganizationInvitation::factory()->for($this->organization)->revoked()
        ->create(['token_hash' => InvitationToken::hash($revoked)]);

    get('/invite/'.$revoked)->assertOk()->assertSee(__('members.invite.revoked'));

    $this->invitation->forceFill(['accepted_at' => now()])->save();
    get('/invite/'.$this->plain)->assertOk()->assertSee(__('members.invite.already_accepted'));
});

it('refuses to accept an expired invitation even by calling the action directly', function () {
    $existing = User::factory()->create(['email' => 'new@example.org']);
    actingAs($existing);

    $this->invitation->forceFill(['expires_at' => now()->subMinute()])->save();

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->call('accept')
        ->assertHasErrors(['token']);

    expect($existing->fresh()?->roleIn($this->organization))->toBeNull();
});

it('uses the token once: a second accept changes nothing', function () {
    $existing = User::factory()->create(['email' => 'new@example.org']);
    actingAs($existing);

    livewire(AcceptInvitation::class, ['token' => $this->plain])->call('accept')->assertHasNoErrors();

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->call('accept')
        ->assertHasErrors(['token']);

    expect(OrganizationInvitation::query()->whereNotNull('accepted_at')->count())->toBe(1);
});

it('lands a reviewer in the reviewer panel and creates the reviewership', function () {
    $conference = Conference::factory()->for($this->organization)->published()->create();
    $token = InvitationToken::generate();
    ReviewerInvitation::factory()->for($conference)->create([
        'name' => 'Dr Omar Khan',
        'email' => 'omar@example.org',
        'affiliation' => 'KFSH',
        'token_hash' => InvitationToken::hash($token),
    ]);

    get('/invite/'.$token)->assertOk()->assertSee($conference->name)->assertSee('Dr Omar Khan');

    livewire(AcceptInvitation::class, ['token' => $token])
        ->set('name', 'Dr Omar Khan')
        ->set('password', 'correct-horse-99')
        ->set('password_confirmation', 'correct-horse-99')
        ->call('createAccount')
        ->assertHasNoErrors()
        ->assertRedirect('/review');

    $user = User::query()->where('email', 'omar@example.org')->firstOrFail();
    $reviewer = ConferenceReviewer::query()->firstOrFail();

    expect($reviewer->user_id)->toBe($user->id)
        ->and($reviewer->conference_id)->toBe($conference->id)
        ->and($reviewer->affiliation)->toBe('KFSH')
        ->and($user->isActiveReviewer($conference))->toBeTrue()
        ->and($user->roleIn($this->organization))->toBeNull();
});

it('refuses to create a second account at an address that already has one', function () {
    // The view's `@elseif ($accountExists)` branch is a convenience, not a gate:
    // createAccount() is a public Livewire method, and an account created in
    // another tab since this page rendered is an ordinary race. users.email is
    // unique, so without the check in forNewAccount() this is a 500.
    User::factory()->create(['email' => 'new@example.org']);

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->set('name', 'Dr Layla Ahmed')
        ->set('password', 'correct-horse-99')
        ->set('password_confirmation', 'correct-horse-99')
        ->call('createAccount')
        ->assertHasErrors(['token']);

    expect(User::query()->where('email', 'new@example.org')->count())->toBe(1)
        ->and($this->invitation->fresh()?->accepted_at)->toBeNull();
});

it('refuses an owner invitation minted by somebody who has since been demoted', function () {
    // An invitation is the inviter's authority, exercised later. A demoted owner
    // must not still be able to install an owner through a link they made while
    // they could. Task 3 withdraws those rows on the demotion itself; this is
    // the belt to that braces, and it also covers a role changed by any other
    // path.
    $founder = User::factory()->create();
    $second = User::factory()->create();
    $this->organization->addMember($founder, OrganizationRole::Owner);
    $this->organization->addMember($second, OrganizationRole::Owner);

    $token = InvitationToken::generate();
    OrganizationInvitation::factory()->for($this->organization)->create([
        'email' => 'accomplice@example.org',
        'role' => OrganizationRole::Owner,
        'invited_by' => $founder->id,
        'token_hash' => InvitationToken::hash($token),
    ]);

    // The demotion itself, at the model level: addMember() is
    // syncWithoutDetaching and sync() updates the pivot of an id it already
    // holds. Task 3's ChangeMemberRole is the screen-level version of this and
    // additionally withdraws the rows; the rule under test here is that even if
    // the row survives, accepting it does not.
    $this->organization->addMember($founder, OrganizationRole::Admin);

    $invitee = User::factory()->create(['email' => 'accomplice@example.org']);
    actingAs($invitee);

    livewire(AcceptInvitation::class, ['token' => $token])
        ->call('accept')
        ->assertHasErrors(['token'])
        ->assertSee(__('members.invite.blocked.inviter_gone'));

    expect($invitee->fresh()?->roleIn($this->organization))->toBeNull();
});

it('throttles the accept page at ten a minute per address', function () {
    // Spec section 9. The GET is what an enumeration attack loops, so the route
    // middleware is where it is counted. No RateLimiter::clear() here: the array
    // cache store is fresh per test, and the route limiter's key is
    // md5('invitation-accept'.$ip)
    // (ThrottleRequests::handleRequestUsingNamedLimiter,
    // vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php:134),
    // not the component's literal 'invitation-accept|'.$ip - so clearing that
    // string here would clear the wrong bucket and prove nothing.
    foreach (range(1, (int) config('cass.invitations.accept_rate_limit')) as $ignored) {
        get('/invite/'.$this->plain)->assertOk();
    }

    get('/invite/'.$this->plain)->assertStatus(429);
});

it('throttles the livewire actions on their own budget, which no route middleware sees', function () {
    // The other half of the same rule, and the half that matters: a Livewire
    // action is one POST to /livewire/update, which no middleware on
    // /invite/{token} ever sees, so without withinRateLimit() the accept button
    // is an unlimited oracle however tight the route throttle is.
    //
    // Nobody is signed in, so every call is refused for the same reason and the
    // invitation is never consumed - which means the ONLY thing that can change
    // the message on the eleventh call is the component's own limiter.
    foreach (range(1, (int) config('cass.invitations.accept_rate_limit')) as $ignored) {
        livewire(AcceptInvitation::class, ['token' => $this->plain])
            ->call('accept')
            ->assertHasErrors(['token'])
            ->assertSee(__('members.invite.blocked.not_signed_in'));
    }

    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->call('accept')
        ->assertHasErrors(['token'])
        ->assertSee(__('members.invite.too_many_attempts'));

    expect($this->invitation->fresh()?->accepted_at)->toBeNull();
});

it('throttles the sign-in attempt separately, per email and address', function () {
    User::factory()->create(['email' => 'new@example.org', 'password' => 'correct-horse-99']);

    foreach (range(1, (int) config('cass.invitations.login_rate_limit')) as $ignored) {
        livewire(AcceptInvitation::class, ['token' => $this->plain])
            ->set('password', 'wrong-password-11')
            ->call('signIn')
            ->assertHasErrors(['password']);
    }

    // Spec section 9's "login 5/min/email+IP". The message changes, so the
    // sixth attempt is refused rather than checked.
    livewire(AcceptInvitation::class, ['token' => $this->plain])
        ->set('password', 'correct-horse-99')
        ->call('signIn')
        ->assertHasErrors(['password']);

    expect(Auth::check())->toBeFalse();
});
