<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Ownership only. Every *rule* about reviews - whether the submission is in the
 * reviewer's pool, whether the review deadline has passed, whether the form is
 * locked - lives in App\Support\Reviews\ReviewerScope and in the three action
 * classes of Task 7, because a policy is handed a model and no clock and no
 * conference state.
 *
 * `submit` and `reopen` are custom abilities so that Filament's
 * `Gate::allows()` in an action's `visible()` reads the same way as everything
 * else in this codebase; both mirror `update`.
 */
class ReviewPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->isMemberOfCurrentTenant($user) || $user->isActiveReviewer();
    }

    /**
     * The reviewer who wrote it, or a member of the organization that owns the
     * conference - spec section 4 gives organizers the "decide" capability, and
     * Plan 5's ranking table reads reviews through this gate. Plan 4 has no
     * organizer-facing screen that shows a review's content; it shows counts.
     *
     * All three hops guarded, as in SubmissionPolicy: the organization hop is
     * null whenever the tenant is soft-deleted while the review survives, and
     * roleIn() takes a non-nullable Organization.
     */
    public function view(User $user, Review $review): bool
    {
        if ($review->reviewer_user_id === $user->getKey()) {
            return true;
        }

        $organization = $review->submission?->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    /** Whether this person may ever write a review at all. */
    public function create(User $user): bool
    {
        return $user->isActiveReviewer();
    }

    public function update(User $user, Review $review): bool
    {
        return $review->reviewer_user_id === $user->getKey();
    }

    public function submit(User $user, Review $review): bool
    {
        return $this->update($user, $review);
    }

    public function reopen(User $user, Review $review): bool
    {
        return $this->update($user, $review);
    }

    /** Nobody deletes a review, in any panel. Withdrawing an abstract does not either. */
    public function delete(User $user, Review $review): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Review $review): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Review $review): bool
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
