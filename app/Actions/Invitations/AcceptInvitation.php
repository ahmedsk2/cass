<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Contracts\Invitation;
use App\Enums\OrganizationRole;
use App\Exceptions\InvitationNotAcceptable;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one path by which an invitation of either kind becomes a membership or a
 * reviewership.
 *
 * `blockers()` is a pure read the page calls to decide what to render;
 * `handle()` re-checks everything and throws, because the page is a convenience
 * and a hand-made Livewire call is not.
 */
class AcceptInvitation
{
    /**
     * @param  User|null  $user  the account that would accept, when there is one yet
     * @return list<string> empty when the invitation may be accepted by that user
     */
    public function blockers(Invitation $invitation, ?User $user): array
    {
        $reasons = [];
        $status = $invitation->invitationStatus();

        if (! $status->isOpen()) {
            $reasons[] = __('members.invite.blocked.'.$status->value);
        }

        if ($user !== null && mb_strtolower(trim((string) $user->email)) !== $invitation->invitedEmail()) {
            // Refusing here is what stops "accept while signed in as someone
            // else" being a way to have an address you do not control marked
            // verified on an account you do.
            $reasons[] = __('members.invite.blocked.wrong_account', ['email' => $invitation->invitedEmail()]);
        }

        // An invitation is the inviter's authority, exercised later. Task 3's
        // RemoveMember and ChangeMemberRole withdraw the invitations a departing
        // or demoted member minted; this is the belt to those braces, because a
        // link created before somebody lost the right to create it must not
        // still install an Owner days afterwards - and because a role can also
        // change by a path neither of those actions owns.
        //
        // `invited_by` is nullable and is only null on rows nothing minted
        // through InviteMember (the factory default, and any future import), so
        // a row with no recorded inviter has no authority to re-check and is not
        // blocked on that ground. The organization hop is null-guarded because
        // Organization soft-deletes while its invitations survive, and
        // User::roleIn() takes a non-nullable Organization.
        if ($invitation instanceof OrganizationInvitation && $invitation->invited_by !== null) {
            $organization = $invitation->organization;
            $inviterRole = $organization === null ? null : $invitation->inviter?->roleIn($organization);

            if ($inviterRole === null
                || ! $inviterRole->canManageOrganization()
                || ($invitation->role === OrganizationRole::Owner && $inviterRole !== OrganizationRole::Owner)) {
                $reasons[] = __('members.invite.blocked.inviter_gone');
            }
        }

        return $reasons;
    }

    public function handle(Invitation $invitation, User $user): Invitation
    {
        $reasons = $this->blockers($invitation, $user);

        if ($reasons !== []) {
            throw new InvitationNotAcceptable($reasons);
        }

        return DB::transaction(function () use ($invitation, $user): Invitation {
            // Fact 6: a forceFill plus a save, no notification. Following a
            // 64-character secret that only ever reached this mailbox is the
            // proof VerifyEmail asks for - see the Task 2 preamble.
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            $invitation->grantTo($user);
            $invitation->markAccepted($user);

            return $invitation;
        });
    }

    /**
     * The short account form of spec 5.4 step 2. The password has already been
     * validated by the caller against Password::defaults(); this class owns the
     * two things a form cannot: the address comes from the *invitation*, never
     * from the request, and the account is created verified inside the same
     * transaction that grants the membership.
     */
    public function forNewAccount(Invitation $invitation, string $name, string $password): User
    {
        $reasons = $this->blockers($invitation, null);

        if ($reasons !== []) {
            throw new InvitationNotAcceptable($reasons);
        }

        // `users.email` is unique
        // (database/migrations/0001_01_01_000000_create_users_table.php:19) and
        // the page's `@elseif ($accountExists)` branch is a convenience, not a
        // gate: createAccount() is a public Livewire method reachable whatever
        // the view rendered, and an account created in another tab since this
        // page loaded is an ordinary race. Refuse here, where every caller
        // reaches it, so this is a sentence rather than an unhandled
        // UniqueConstraintViolationException 500 with the invitation unaccepted.
        if (User::query()->where('email', $invitation->invitedEmail())->exists()) {
            throw InvitationNotAcceptable::because(__('members.invite.blocked.account_exists'));
        }

        return DB::transaction(function () use ($invitation, $name, $password): User {
            /** @var User $user */
            $user = User::query()->create([
                'name' => $name,
                'email' => $invitation->invitedEmail(),
                'password' => $password,
            ]);

            $this->handle($invitation, $user);

            return $user;
        });
    }
}
