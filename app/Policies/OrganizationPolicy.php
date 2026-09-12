<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

/**
 * The first OrganizationPolicy in this application, and it exists mainly to
 * close a door.
 *
 * NO before(). Filament treats a MISSING policy method as allowed
 * (vendor/filament/filament/src/helpers.php), so a policy that exists has to
 * be exhaustive - and a before() answering true for a platform admin would
 * answer `forceDelete` too, which is exactly what this class must not do. An
 * Organization cannot be force-deleted by anybody through any UI: its
 * conferences are RESTRICT-ed onto it, so Filament's own ForceDeleteAction
 * would run $record->forceDelete() straight into a QueryException. Destroying
 * one is `purge`, which App\Actions\Organizations\PurgeOrganization implements
 * in application code.
 *
 * The shape is App\Policies\EmailLogPolicy's: one rule, stated on every method,
 * and the same `(bool)` cast on every answer - UserFactory::definition() never
 * writes `is_platform_admin`, so a freshly created model returns null for the
 * attribute however the column is defaulted, and a bool return type would
 * TypeError rather than refuse.
 */
class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_platform_admin;
    }

    public function view(User $user, Organization $organization): bool
    {
        return (bool) $user->is_platform_admin || $user->roleIn($organization) !== null;
    }

    /** Organizations are created by RegisterOrganization, never from a panel. */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * The organizer panel's tenant profile page checks the same rule in its own
     * canView(); this is here so the answer is the same however it is asked.
     */
    public function update(User $user, Organization $organization): bool
    {
        return (bool) $user->is_platform_admin || ($user->roleIn($organization)?->canManageOrganization() ?? false);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Organization $organization): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Organization $organization): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Spec section 3. Platform admin only, and never an organizer: an owner who
     * wants their data gone asks the platform, which is also the moment
     * somebody checks whether a conference is mid-review.
     */
    public function purge(User $user, Organization $organization): bool
    {
        return (bool) $user->is_platform_admin;
    }
}
