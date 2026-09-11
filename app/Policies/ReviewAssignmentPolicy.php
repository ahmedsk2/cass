<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Assignment is an organizer capability (spec section 4, "Invite reviewers,
 * assign, decide"). A reviewer can *see* that they were assigned - that is
 * their queue - but every write here belongs to the organization.
 */
class ReviewAssignmentPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->isMemberOfCurrentTenant($user) || $user->isActiveReviewer();
    }

    public function view(User $user, ReviewAssignment $assignment): bool
    {
        if ($assignment->reviewer_user_id === $user->getKey()) {
            return true;
        }

        return $this->belongsToAnOrganizationOf($user, $assignment);
    }

    public function create(User $user): bool
    {
        return $this->isMemberOfCurrentTenant($user);
    }

    public function update(User $user, ReviewAssignment $assignment): bool
    {
        return $this->belongsToAnOrganizationOf($user, $assignment);
    }

    /** Unassigning really does delete the row (Task 8). */
    public function delete(User $user, ReviewAssignment $assignment): bool
    {
        return $this->belongsToAnOrganizationOf($user, $assignment);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ReviewAssignment $assignment): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ReviewAssignment $assignment): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Three hops, all three guarded: the submission can be null behind a scope,
     * the conference can be null behind the tenant scope, and the organization
     * can be null on its own because Organization soft-deletes while this row
     * survives. roleIn() takes a non-nullable Organization, so the unguarded
     * walk is a TypeError 500 where a "no" belongs - the same guard
     * SubmissionPolicy has carried since Plan 3.
     */
    private function belongsToAnOrganizationOf(User $user, ReviewAssignment $assignment): bool
    {
        $organization = $assignment->submission?->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    private function isMemberOfCurrentTenant(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }
}
