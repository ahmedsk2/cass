<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Support\Reviews\ReviewerScope;
use Filament\Facades\Filament;

/**
 * Governs where a signed download URL may be *minted* inside a panel. The
 * download route itself is authorized by the signature (spec section 8), which
 * is the capability; this keeps an organizer from generating one for another
 * tenant's file in the first place.
 *
 * The answer is organization members, the platform admin, and - since Plan 4
 * Task 6 - a reviewer whose pool or assignments cover the abstract the file
 * hangs off ("assigned or pool only", spec section 4). That last case is
 * ReviewerScope and nothing else, so the reviewer panel's file links and its
 * queue cannot come to disagree.
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

        // Two panels ask this. In the organizer panel the answer is tenant
        // membership; in the reviewer panel there is no tenant at all and the
        // answer is "does this person review anywhere" - view() below is what
        // keeps one reviewer out of another's files.
        return ($tenant instanceof Organization && $user->roleIn($tenant) !== null)
            || $user->isActiveReviewer();
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
        $submission = $file->submission;
        $organization = $submission?->conference?->organization;

        if ($submission === null || $organization === null) {
            return false;
        }

        if ($user->roleIn($organization) !== null) {
            return true;
        }

        return ReviewerScope::allows($user, $submission);
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
