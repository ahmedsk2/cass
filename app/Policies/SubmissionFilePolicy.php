<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\SubmissionFile;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Governs where a signed download URL may be *minted* inside a panel. The
 * download route itself is authorized by the signature (spec section 8), which
 * is the capability; this keeps an organizer from generating one for another
 * tenant's file in the first place.
 *
 * Plan 4 adds the reviewer case ("assigned or pool only", spec section 4).
 * Today the answer is organization members and the platform admin.
 */
class SubmissionFilePolicy
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
     * Same null guard as SubmissionPolicy::view(), for the same reason: the
     * organizer panel's tenant-scoped ConferenceResource puts a global scope on
     * the Conference model, so another tenant's conference - and a soft-deleted
     * one - resolves to null here. This gate decides who may mint a signed
     * download URL; it denies, it does not fatal.
     *
     * All three hops are guarded, because all three models soft delete:
     * `submission()` applies Submission's SoftDeletes scope (which is why
     * SubmissionFileController treats the same relation as nullable), and
     * Organization deletes as well.
     */
    public function view(User $user, SubmissionFile $file): bool
    {
        $organization = $file->submission?->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    /** Files are attached and removed by the author, through the status page. */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SubmissionFile $file): bool
    {
        return false;
    }

    public function delete(User $user, SubmissionFile $file): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * The single-record halves are spelled out beside the bulk ones because
     * Filament treats a policy method it asks for and does not find as ALLOW
     * (fact 17), and SubmissionPolicy spells both out. Unreachable while no
     * resource exposes a SubmissionFile record; a default of "yes" is not the
     * thing to leave lying around for the resource that eventually does.
     */
    public function restore(User $user, SubmissionFile $file): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, SubmissionFile $file): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
