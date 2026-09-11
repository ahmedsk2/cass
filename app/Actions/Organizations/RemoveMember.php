<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Exceptions\MemberChangeRefused;
use App\Models\Organization;
use App\Models\User;

class RemoveMember
{
    /** @return list<string> empty when the member may be removed */
    public function blockers(Organization $organization, User $member, User $actor): array
    {
        $reasons = [];
        $actorRole = $actor->roleIn($organization);
        $currentRole = $member->roleIn($organization);

        if ($currentRole === null) {
            $reasons[] = __('members.errors.not_a_member');
        }

        if ($actorRole === null || ! $actorRole->canManageOrganization()) {
            $reasons[] = __('members.errors.not_allowed');
        }

        if ($member->is($actor)) {
            $reasons[] = __('members.errors.own_membership');
        }

        if ($currentRole === OrganizationRole::Owner && $actorRole !== OrganizationRole::Owner) {
            $reasons[] = __('members.errors.owner_only_changes_owner');
        }

        if ($currentRole === OrganizationRole::Owner && $organization->owners()->count() <= 1) {
            $reasons[] = __('members.errors.last_owner');
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Detaches the pivot row only. The `users` row survives, because the person
     * may be a member of another organization, a reviewer on a conference, or
     * simply somebody with an account - and because their name still has to
     * render beside the conferences they published.
     */
    public function handle(Organization $organization, User $member, User $actor): void
    {
        $reasons = $this->blockers($organization, $member, $actor);

        if ($reasons !== []) {
            throw new MemberChangeRefused($reasons);
        }

        $role = $member->roleIn($organization);

        // An invitation is the removed member's authority, exercised later. A
        // removed owner must not still be able to install an owner through a
        // link they minted while they could, so every live invitation of theirs
        // goes with them. Revoked rather than deleted, so whoever holds the
        // emailed link meets Task 2's sentence rather than a 404.
        // AcceptInvitation::blockers() re-checks the same rule at accept time.
        $organization->invitations()
            ->where('invited_by', $member->getKey())
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $organization->members()->detach($member->getKey());

        activity()
            ->performedOn($organization)
            ->causedBy($actor)
            ->withProperties(['user_id' => $member->getKey(), 'role' => $role?->value])
            ->log('organization.member_removed');
    }
}
