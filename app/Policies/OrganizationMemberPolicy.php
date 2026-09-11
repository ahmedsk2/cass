<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Who may act on a membership row. *What* the act may be - the last-owner rule,
 * the own-row rule, who may grant the owner role - lives in
 * ChangeMemberRole::blockers() and RemoveMember::blockers(), because those need
 * the target role and a count and a policy is handed neither.
 *
 * A new policy on a model nothing authorized against before, so it changes no
 * existing behaviour. It is deliberately not an OrganizationPolicy: the admin
 * panel's OrganizationResource explicitly works without one (see its
 * canAccess() docblock) and adding one would start being consulted by its row
 * actions.
 */
class OrganizationMemberPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    /** Every member sees the list; only some of them can change it. */
    public function viewAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }

    /**
     * `$organization !== null` is load-bearing, not defensive noise:
     * `$member->organization` is null whenever the tenant is soft-deleted
     * (app/Models/Organization.php:27) while its pivot rows survive, and
     * User::roleIn() type-hints a non-nullable Organization
     * (app/Models/User.php:60) - so the unguarded walk turns a gate that must
     * answer "no" into a TypeError 500. SubmissionPolicy has carried the same
     * guard since Plan 3.
     */
    public function view(User $user, OrganizationMember $member): bool
    {
        $organization = $member->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    /** Members arrive by accepting an invitation, never by a create form. */
    public function create(User $user): bool
    {
        return false;
    }

    /** Changing a role. */
    public function update(User $user, OrganizationMember $member): bool
    {
        $organization = $member->organization;

        return $organization !== null
            && ($user->roleIn($organization)?->canManageOrganization() ?? false);
    }

    /** Removing someone from the organization. */
    public function delete(User $user, OrganizationMember $member): bool
    {
        return $this->update($user, $member);
    }

    /** Your own "email me about new submissions" switch, and nobody else's. */
    public function updateNotifications(User $user, OrganizationMember $member): bool
    {
        return $member->user_id === $user->getKey();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, OrganizationMember $member): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, OrganizationMember $member): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
