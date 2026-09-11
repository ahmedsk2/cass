<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Exceptions\MemberChangeRefused;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\MemberInvitation;
use App\Support\Tokens\InvitationToken;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Invite, re-invite and resend are all this one method.
 *
 * There is no unique index on (organization_id, email) - a revoked invitation
 * keeps its row, so a unique key would make "invite, revoke, invite again" fail
 * at the database. Uniqueness of the *live* invitation is here instead: the
 * newest un-accepted, un-revoked row for that address is refreshed with a new
 * token and a new expiry rather than joined by a second one, so an organizer
 * who clicks Invite twice does not put two working links in one inbox.
 */
class InviteMember
{
    /** @return list<string> empty when the invitation may be sent */
    public function blockers(Organization $organization, string $email, OrganizationRole $role, User $actor): array
    {
        $reasons = [];
        $email = mb_strtolower(trim($email));
        $actorRole = $actor->roleIn($organization);

        if ($actorRole === null || ! $actorRole->canManageOrganization()) {
            $reasons[] = __('members.errors.not_allowed');
        }

        if ($role === OrganizationRole::Owner && $actorRole !== OrganizationRole::Owner) {
            $reasons[] = __('members.errors.owner_grants_owner');
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $reasons[] = __('members.errors.bad_email');
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null && $existing->roleIn($organization) !== null) {
            $reasons[] = __('members.errors.already_a_member', ['email' => $email]);
        }

        return array_values(array_unique($reasons));
    }

    public function handle(Organization $organization, string $email, OrganizationRole $role, User $actor): OrganizationInvitation
    {
        $reasons = $this->blockers($organization, $email, $role, $actor);

        if ($reasons !== []) {
            throw new MemberChangeRefused($reasons);
        }

        $email = mb_strtolower(trim($email));
        $plain = InvitationToken::generate();

        // The same guard, the same bucket and the same ordering as
        // InviteReviewer::handle(). The same bucket because it is one actor's
        // mail budget, not one table's: an invitation of either kind is a
        // bulk-mail primitive, and anyone can self-register an organization at
        // /register - User::canAccessTenant() checks membership, not approval -
        // so this action is reachable by anyone with an address, and no route or
        // Livewire throttle covers it.
        //
        // Consulted BEFORE the forceFill below, and that ordering is
        // load-bearing for the reason it is there: this method re-invites by
        // refreshing the live row in place, so a limiter that threw afterwards
        // would already have invalidated a link that was emailed an hour ago,
        // for a message that then never leaves.
        $key = 'invitation-send:'.$actor->getKey();

        if (RateLimiter::tooManyAttempts($key, (int) config('cass.invitations.send_rate_limit'))) {
            throw new MemberChangeRefused([__('members.errors.send_limit')]);
        }

        RateLimiter::hit($key, 3600);

        $invitation = OrganizationInvitation::query()
            ->where('organization_id', $organization->getKey())
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->latest('id')
            ->first() ?? new OrganizationInvitation;

        $invitation->forceFill([
            'organization_id' => $organization->getKey(),
            'email' => $email,
            'role' => $role,
            'token_hash' => InvitationToken::hash($plain),
            'invited_by' => $actor->getKey(),
            'expires_at' => now()->addDays((int) config('cass.invitations.expiry_days')),
            'accepted_at' => null,
            'accepted_by' => null,
            'revoked_at' => null,
        ])->save();

        // On demand, because the invitee has no account yet by definition
        // (fact 20). Queued, and picked up by RecordOutgoingEmail through the
        // X-CASS-Organization header the notification adds.
        Notification::route('mail', $email)
            ->notify(new MemberInvitation($organization, $role, $plain, (string) $actor->name));

        activity()
            ->performedOn($invitation)
            ->causedBy($actor)
            ->withProperties(['email' => $email, 'role' => $role->value])
            ->log('organization.member_invited');

        return $invitation;
    }
}
