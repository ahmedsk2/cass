<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * The membership question the tenant-owned policies ask: whether a user holds
 * a role in an organization - any role, or one that manages it - and the same
 * of the organizer panel's current tenant.
 *
 * Membership and nothing else. The platform admin's grant stays with each
 * policy: a before(), an inline check, or none at all. The organization's
 * status is not read: what a pending or suspended organization may not do is
 * refused by the Actions. And the walk from a record to its organization stays
 * in the policy, which is why the organization is non-nullable here, as it is
 * in User::roleIn(): what a soft-deleted or tenant-scoped hop means is the
 * walking policy's decision. Most guard the hop and answer "no"; a few walk it
 * unguarded and throw.
 *
 * No memo: User::roleIn() is a query, and a cached answer would outlive a
 * RemoveMember or ChangeMemberRole earlier in the same request. Protected,
 * because a public policy method is an ability the Gate would answer.
 */
trait ChecksOrganizationMembership
{
    /** Any role: owner, admin or member. */
    protected function isMemberOf(User $user, Organization $organization): bool
    {
        return $user->roleIn($organization) !== null;
    }

    /** Owner or admin, the two roles that manage the organization. */
    protected function managesOrganization(User $user, Organization $organization): bool
    {
        return $user->roleIn($organization)?->canManageOrganization() ?? false;
    }

    /** False without a tenant, which is the reviewer panel's case. */
    protected function isMemberOfCurrentTenant(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $this->isMemberOf($user, $tenant);
    }

    protected function managesCurrentTenant(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $this->managesOrganization($user, $tenant);
    }
}
