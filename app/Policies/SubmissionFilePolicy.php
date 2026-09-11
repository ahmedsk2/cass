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
     */
    public function view(User $user, SubmissionFile $file): bool
    {
        $conference = $file->submission->conference;

        return $conference !== null && $user->roleIn($conference->organization) !== null;
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

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
