<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Conference;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

class ConferencePolicy
{
    /**
     * Spec section 4 gives the platform admin every conference capability, and
     * conferences otherwise live only inside the tenant-scoped organizer
     * panel. Note this also makes `forceDelete` true for a platform admin: no
     * ForceDeleteAction may be added to either panel until the
     * application-code purge exists (backlog, Plan 6).
     */
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    /**
     * Spec section 4: every organization member (owner, admin or member) can
     * create and edit conferences; only owners and admins can archive or
     * delete one.
     */
    public function viewAny(User $user): bool
    {
        return $this->isMemberOfCurrentTenant($user);
    }

    public function create(User $user): bool
    {
        return $this->isMemberOfCurrentTenant($user);
    }

    public function view(User $user, Conference $conference): bool
    {
        return $user->roleIn($conference->organization) !== null;
    }

    public function update(User $user, Conference $conference): bool
    {
        return $this->view($user, $conference);
    }

    public function publish(User $user, Conference $conference): bool
    {
        return $this->view($user, $conference);
    }

    public function close(User $user, Conference $conference): bool
    {
        return $this->view($user, $conference);
    }

    public function archive(User $user, Conference $conference): bool
    {
        return $this->canManage($user, $conference);
    }

    public function delete(User $user, Conference $conference): bool
    {
        return $this->canManage($user, $conference);
    }

    public function restore(User $user, Conference $conference): bool
    {
        return $this->canManage($user, $conference);
    }

    /** Hard purge is a platform-admin action (spec section 3), never an organizer one. */
    public function forceDelete(User $user, Conference $conference): bool
    {
        return false;
    }

    /**
     * Filament authorizes a resource page's `DeleteBulkAction` with
     * `deleteAny()` and never with the per-record `delete()`, and it treats a
     * *missing* policy method as ALLOW (fact 7), so this has to mirror
     * delete() or every member could bulk-delete the whole organization.
     */
    public function deleteAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization
            && ($user->roleIn($tenant)?->canManageOrganization() ?? false);
    }

    /** No bulk restore or purge UI exists in either panel; keep both closed. */
    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function canManage(User $user, Conference $conference): bool
    {
        return $user->roleIn($conference->organization)?->canManageOrganization() ?? false;
    }

    private function isMemberOfCurrentTenant(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }
}
