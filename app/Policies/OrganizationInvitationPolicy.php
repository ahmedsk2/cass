<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Spec section 4: "Manage organization members" belongs to the platform admin,
 * the org owner and the org admin - and to nobody else.
 *
 * The Members page is NOT hidden from a plain member: Members::canAccess()
 * deliberately admits every member of the tenant, because a plain member needs
 * that page to turn their own submission notifications off. What is gated is
 * everything on it - the invite action, the five row actions, and the pending
 * invitation rows themselves, which Members::rows() builds only when viewAny()
 * below says yes. An invitee's address and the role they were granted are as
 * much "manage members" as the buttons are.
 */
class OrganizationInvitationPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->managesCurrentTenant($user);
    }

    public function create(User $user): bool
    {
        return $this->managesCurrentTenant($user);
    }

    /**
     * The `$organization !== null` hop is not defensive noise: Organization
     * soft-deletes (app/Models/Organization.php:27) while its invitations
     * survive, and User::roleIn() type-hints a non-nullable Organization
     * (app/Models/User.php:60) - so walking the relation unguarded turns a gate
     * that must answer "no" into a TypeError 500. SubmissionPolicy has carried
     * this same guard, and this same comment, since Plan 3.
     */
    public function view(User $user, OrganizationInvitation $invitation): bool
    {
        $organization = $invitation->organization;

        return $organization !== null
            && ($user->roleIn($organization)?->canManageOrganization() ?? false);
    }

    /** Resending replaces the token on the row, so it is an update. */
    public function update(User $user, OrganizationInvitation $invitation): bool
    {
        return $this->view($user, $invitation);
    }

    /** Revoking. The row survives; `revoked_at` is stamped. */
    public function delete(User $user, OrganizationInvitation $invitation): bool
    {
        return $this->view($user, $invitation);
    }

    /** Fact 25: a missing method is ALLOW, so every bulk ability is stated. */
    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, OrganizationInvitation $invitation): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, OrganizationInvitation $invitation): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function managesCurrentTenant(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization
            && ($user->roleIn($tenant)?->canManageOrganization() ?? false);
    }
}
