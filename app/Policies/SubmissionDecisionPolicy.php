<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\SubmissionDecision;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * The decision history is **append-only** for everyone the panel lets in.
 *
 * Reading it is reading a submission: any role in the conference's
 * organization. Writing it is not a thing a screen does - ApplyDecision appends
 * a row and SendOneDecisionEmail fills in the letter columns, both through
 * `SubmissionPolicy::decide` and `ConferencePolicy::sendDecisions` - so
 * `create`, `update` and every delete ability is false here. A decision that
 * can be deleted is a decision that can be denied, and spec section 9 requires
 * the opposite.
 *
 * Every ability Filament might ask for is spelled out, including the three bulk
 * ones: a policy without the method means ALLOW, not 403
 * (vendor/filament/filament/src/helpers.php:59-93). Note what these `false`s do
 * NOT cover: before() returns true for a platform admin and short-circuits all
 * of them, so when Plan 6 gives the admin panel a screen over these rows,
 * deletion has to be refused there or in before().
 */
class SubmissionDecisionPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_platform_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }

    /**
     * The same nullable walk SubmissionPolicy::view() makes, and for the same
     * two reasons: the conference is invisible under another tenant's global
     * scope and both it and the organization soft delete, so either hop can be
     * null while this row survives. A gate answers "no"; it does not 500.
     */
    public function view(User $user, SubmissionDecision $decision): bool
    {
        $organization = $decision->submission?->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SubmissionDecision $decision): bool
    {
        return false;
    }

    public function delete(User $user, SubmissionDecision $decision): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, SubmissionDecision $decision): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, SubmissionDecision $decision): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
