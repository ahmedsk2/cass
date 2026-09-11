<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\ReviewerInvitation;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Spec section 4 gives "Invite reviewers, assign, decide" to *every*
 * organization member, including a plain `member` - unlike member management
 * above, which is owner and admin only. The two policies therefore answer
 * differently for the same person on purpose.
 */
class ReviewerInvitationPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->isMemberOfCurrentTenant($user);
    }

    public function create(User $user): bool
    {
        return $this->isMemberOfCurrentTenant($user);
    }

    /**
     * The same null guard as SubmissionPolicy::view(), for the same reason, and
     * BOTH hops of it. The organizer panel's tenant-scoped ConferenceResource
     * puts a global scope on the Conference model, so another tenant's
     * conference - and a soft-deleted one - resolves to null here; and
     * Organization soft-deletes too (app/Models/Organization.php:27), so the
     * second hop is null whenever the tenant itself is in the bin while its
     * conference row survives. User::roleIn() type-hints a non-nullable
     * Organization (app/Models/User.php:60), so walking the chain unguarded
     * turns a gate that must answer "no" into a TypeError 500. A gate answers
     * "no", it does not fatal.
     */
    public function view(User $user, ReviewerInvitation $invitation): bool
    {
        $organization = $invitation->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    public function update(User $user, ReviewerInvitation $invitation): bool
    {
        return $this->view($user, $invitation);
    }

    public function delete(User $user, ReviewerInvitation $invitation): bool
    {
        return $this->view($user, $invitation);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ReviewerInvitation $invitation): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ReviewerInvitation $invitation): bool
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
