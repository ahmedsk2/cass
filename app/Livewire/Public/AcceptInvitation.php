<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Actions\Invitations\AcceptInvitation as AcceptInvitationAction;
use App\Contracts\Invitation;
use App\Exceptions\InvitationNotAcceptable;
use App\Models\ReviewerInvitation;
use App\Models\User;
use App\Support\ClientIp;
use App\Support\Invitations\InvitationLookup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Spec 5.4 step 2 and the `/invite/{token}` row of section 6, for member and
 * reviewer invitations alike.
 *
 * The component holds **only the token**, and re-resolves the invitation on
 * every action. That is one indexed read per click, and it is what makes
 * "revoked while the page was open" and "accepted in another tab" refuse
 * instead of succeeding against a stale snapshot. The token is #[Locked] and
 * lives in the component's signed snapshot, which is fine here for the same
 * reason it is fine on the status page: the identical value is in the address
 * bar of the page that snapshot belongs to.
 */
#[Layout('components.layouts.public')]
#[Title('Your invitation')]
class AcceptInvitation extends Component
{
    #[Locked]
    public string $token;

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        // A token that resolves to nothing is a flat 404, exactly like
        // /s/{token}. A token that resolves to an expired or withdrawn
        // invitation is a page with a sentence on it - see the Task 2 preamble.
        abort_if($this->resolve($token) === null, 404);

        $this->token = $token;
        $this->name = $this->invitation()?->invitedName() ?? '';
    }

    /** One-click accept for a signed-in account whose address matches. */
    public function accept(AcceptInvitationAction $action): mixed
    {
        if (! $this->withinRateLimit()) {
            return null;
        }

        $user = Auth::user();

        if (! $user instanceof User) {
            $this->addError('token', __('members.invite.blocked.not_signed_in'));

            return null;
        }

        return $this->complete($action, fn (Invitation $invitation): mixed => $action->handle($invitation, $user));
    }

    /** An account already exists at this address: check the password, then accept. */
    public function signIn(AcceptInvitationAction $action): mixed
    {
        if (! $this->withinRateLimit()) {
            return null;
        }

        $invitation = $this->invitation();

        if ($invitation === null) {
            $this->addError('token', __('members.invite.blocked.revoked'));

            return null;
        }

        $this->validate(['password' => ['required', 'string']]);

        // Spec section 9: login is 5/min/email+IP. Keyed on both, so one
        // address cannot spend another account's budget and one attacker cannot
        // get a fresh budget per guessed address. Kept even though nothing here
        // signs anybody in: the password check below is still an oracle.
        $key = 'invite-login:'.$invitation->invitedEmail().'|'.ClientIp::from(request());

        if (RateLimiter::tooManyAttempts($key, (int) config('cass.invitations.login_rate_limit'))) {
            $this->addError('password', __('members.invite.too_many_attempts'));

            return null;
        }

        RateLimiter::hit($key, 60);

        // Verify, do NOT authenticate. Auth::attempt() here would hand out a
        // panel session without the multi-factor challenge that
        // Filament\Auth\Pages\Login::authenticate() is the only place to run:
        // AdminPanelProvider and OrganizerPanelProvider both call
        // ->multiFactorAuthentication([AppAuthentication::make()->recoverable()])
        // and no Filament HTTP middleware re-checks the factor per request, so
        // an invitation link plus a password would be a second, factor-free
        // front door into those panels for anyone with mailbox access - who can
        // also reset the password, and who MFA exists to stop. The token proves
        // the mailbox, which is what the *invitation* needs; issuing the
        // *session* is the panel login's job.
        $user = User::query()->where('email', $invitation->invitedEmail())->first();

        if (! $user instanceof User || ! Hash::check($this->password, (string) $user->password)) {
            $this->addError('password', __('members.invite.wrong_password'));

            return null;
        }

        RateLimiter::clear($key);

        // Accept first, then land them on the panel - not the other way round.
        // User::canAccessPanel('organizer') answers organizations()->exists()
        // (app/Models/User.php:108), which is false until the membership exists,
        // so redirecting to the panel login BEFORE accepting would meet "these
        // credentials do not match our records" and the invitation could never
        // be accepted at all. Accepting makes it true; complete()'s redirect to
        // landingUrl() then meets Filament\Http\Middleware\Authenticate, which
        // stores the intended URL and sends them to the login that DOES
        // challenge the second factor.
        return $this->complete($action, fn (Invitation $current): mixed => $action->handle($current, $user));
    }

    /** No account at this address: the four-field form of spec 5.4 step 2. */
    public function createAccount(AcceptInvitationAction $action): mixed
    {
        if (! $this->withinRateLimit()) {
            return null;
        }

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        return $this->complete($action, function (Invitation $invitation) use ($action): mixed {
            $user = $action->forNewAccount($invitation, trim($this->name), $this->password);

            Auth::login($user);
            // The `session()` helper, not `request()->session()`: a Livewire
            // action is dispatched without the session bound onto the Request
            // instance the container hands back, so `request()->session()`
            // throws "Session store not set on request." The helper resolves
            // the store itself, and is what RegisterOrganization::register()
            // already does after its own Auth::login().
            session()->regenerate();

            return $user;
        });
    }

    public function signOut(): mixed
    {
        Auth::logout();
        // See createAccount(): the helper, for the same reason.
        session()->invalidate();
        session()->regenerateToken();

        return $this->redirect(route('invitation.accept', ['token' => $this->token]), navigate: false);
    }

    public function render(): mixed
    {
        $invitation = $this->invitation();

        // Revoked between the page load and this render: nothing to show and
        // nothing to accept, so treat it the way mount() treats an unknown one.
        abort_if($invitation === null, 404);

        $signedIn = Auth::user();

        return view('livewire.public.accept-invitation', [
            'invitation' => $invitation,
            'status' => $invitation->invitationStatus(),
            'isReviewer' => $invitation instanceof ReviewerInvitation,
            'signedInUser' => $signedIn instanceof User ? $signedIn : null,
            'matchesSignedIn' => $signedIn instanceof User
                && mb_strtolower(trim((string) $signedIn->email)) === $invitation->invitedEmail(),
            'accountExists' => User::query()->where('email', $invitation->invitedEmail())->exists(),
        ]);
    }

    /**
     * Runs the accept, turns an InvitationNotAcceptable into a field error, and
     * redirects to wherever that invitation lands people.
     *
     * @param  callable(Invitation): mixed  $work
     */
    private function complete(AcceptInvitationAction $action, callable $work): mixed
    {
        $invitation = $this->invitation();

        if ($invitation === null) {
            $this->addError('token', __('members.invite.blocked.revoked'));

            return null;
        }

        try {
            $work($invitation);
        } catch (InvitationNotAcceptable $exception) {
            $this->addError('token', $exception->getMessage());

            return null;
        }

        return $this->redirect($invitation->landingUrl(), navigate: false);
    }

    /**
     * Spec section 9's "invitation accept 10/min/IP", spent inside the
     * component as well as on the route: a Livewire action is one POST to
     * /livewire/update, which no middleware on /invite/{token} ever sees.
     */
    private function withinRateLimit(): bool
    {
        $key = 'invitation-accept|'.ClientIp::from(request());

        if (RateLimiter::tooManyAttempts($key, (int) config('cass.invitations.accept_rate_limit'))) {
            $this->addError('token', __('members.invite.too_many_attempts'));

            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }

    private function invitation(): ?Invitation
    {
        return $this->resolve($this->token);
    }

    private function resolve(string $token): ?Invitation
    {
        return app(InvitationLookup::class)->find($token);
    }
}
