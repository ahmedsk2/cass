<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

class ConferenceReviewerPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        // True in the organizer panel for a member of the tenant, and true in
        // the reviewer panel - which has no tenant at all - for anyone who is
        // an active reviewer somewhere. The row-level view() below is what
        // actually keeps one reviewer out of another's row.
        return $this->isMemberOfCurrentTenant($user) || $user->isActiveReviewer();
    }

    /**
     * Both hops guarded, as in SubmissionPolicy: the conference can be null
     * behind the tenant scope, and the organization can be null on its own
     * because Organization soft-deletes while this row survives. roleIn() takes
     * a non-nullable Organization, so an unguarded walk is a 500 where a "no"
     * belongs.
     */
    public function view(User $user, ConferenceReviewer $reviewer): bool
    {
        if ($reviewer->user_id === $user->getKey()) {
            return true;
        }

        $organization = $reviewer->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    /** Reviewers are created by accepting an invitation, never by a form. */
    public function create(User $user): bool
    {
        return false;
    }

    /** "Remove reviewer" is an update: the row survives with status `removed`. */
    public function update(User $user, ConferenceReviewer $reviewer): bool
    {
        $organization = $reviewer->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    public function delete(User $user, ConferenceReviewer $reviewer): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ConferenceReviewer $reviewer): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ConferenceReviewer $reviewer): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function isMemberOfCurrentTenant(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }
}
