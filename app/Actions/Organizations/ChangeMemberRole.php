<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Exceptions\MemberChangeRefused;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ChangeMemberRole
{
    /** @return list<string> empty when the change may be made */
    public function blockers(Organization $organization, User $member, OrganizationRole $role, User $actor): array
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
            // Nobody edits their own role. An owner who wants to step down asks
            // another owner, which is also what stops the last owner demoting
            // themselves into an organization nobody can administer.
            $reasons[] = __('members.errors.own_role');
        }

        if ($role === OrganizationRole::Owner && $actorRole !== OrganizationRole::Owner) {
            $reasons[] = __('members.errors.owner_grants_owner');
        }

        if ($currentRole === OrganizationRole::Owner && $actorRole !== OrganizationRole::Owner) {
            $reasons[] = __('members.errors.owner_only_changes_owner');
        }

        if ($currentRole === OrganizationRole::Owner && $role !== OrganizationRole::Owner
            && $organization->owners()->count() <= 1) {
            $reasons[] = __('members.errors.last_owner');
        }

        return array_values(array_unique($reasons));
    }

    public function handle(Organization $organization, User $member, OrganizationRole $role, User $actor): void
    {
        $reasons = $this->blockers($organization, $member, $role, $actor);

        if ($reasons !== []) {
            throw new MemberChangeRefused($reasons);
        }

        $from = $member->roleIn($organization);

        DB::transaction(function () use ($organization, $member, $role, $from): void {
            // blockers() above counts the owners with a plain read, and this is
            // the write it authorised: two managers demoting the two remaining
            // owners in the same instant both pass `owners()->count() <= 1` and
            // leave an organization nobody can administer - which Plan 4 ships
            // no platform-admin members screen to repair. Re-count inside the
            // transaction under a row lock, the same conditional-write
            // discipline as ReviewForm::lockIfUnlocked(). SQLite compiles
            // `for update` away, so the concurrency case belongs to the MySQL
            // suite; the rule itself is still asserted by the blockers() tests.
            if ($from === OrganizationRole::Owner
                && $role !== OrganizationRole::Owner
                && $organization->owners()->lockForUpdate()->count() <= 1) {
                throw new MemberChangeRefused([__('members.errors.last_owner')]);
            }

            // updateExistingPivot rather than a second withPivotValue relation:
            // withPivotValue() also *constrains the query* (fact 22), so a
            // relation built with it could not be used to write a different
            // value.
            $organization->members()->updateExistingPivot($member->getKey(), ['role' => $role->value]);

            // A demotion takes the invitations they already minted with it. An
            // invitation is authority exercised later: a link created while
            // somebody could create it must not still install an Owner days
            // after they lost the right to. Promoting to Owner withdraws
            // nothing. Demoting from Owner to Admin - who still manages the
            // organization - withdraws only the Owner-level invitations they
            // could no longer mint. Anything below a manager withdraws all of
            // them. AcceptInvitation::blockers() re-checks the same rule at
            // accept time, for rows this action never saw.
            if ($role !== OrganizationRole::Owner) {
                $organization->invitations()
                    ->where('invited_by', $member->getKey())
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at')
                    ->when(
                        $role->canManageOrganization(),
                        fn (Builder $query): Builder => $query->where('role', OrganizationRole::Owner->value),
                    )
                    ->update(['revoked_at' => now()]);
            }
        });

        activity()
            ->performedOn($organization)
            ->causedBy($actor)
            ->withProperties(['user_id' => $member->getKey(), 'from' => $from?->value, 'to' => $role->value])
            ->log('organization.role_changed');
    }
}
