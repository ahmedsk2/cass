<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Support\Reviews\ReviewerScope;
use Filament\Facades\Filament;

/**
 * Spec section 4: every organization member can view submissions and files;
 * nobody in a panel creates or edits an abstract, because the author owns the
 * text and reaches it with a token. `withdraw` and `resendLink` are the two
 * custom abilities the organizer does have, and both are logged.
 */
class SubmissionPolicy
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
        // answer is "does this person review anywhere" - the row-level view()
        // below is what actually keeps one reviewer out of another's pool.
        return ($tenant instanceof Organization && $user->roleIn($tenant) !== null)
            || $user->isActiveReviewer();
    }

    /**
     * `conference` resolves to null more often than the non-nullable column
     * suggests, and a gate has to answer "no" rather than fatal on 500:
     * ConferenceResource is tenant-scoped, so Filament registers a *global
     * scope* on the Conference model
     * (Resources/Resource/Concerns/BelongsToTenant::registerTenancyModelGlobalScope),
     * and while the organizer panel is booted with a tenant another
     * organization's conference does not resolve at all. A soft-deleted
     * conference does the same thing in production - the case
     * Submission::isOpenToAuthor() already guards for.
     *
     * The *organization* is guarded for the same reason and not because of the
     * same cause: Organization soft deletes too, so the second hop is null
     * whenever the tenant itself is in the bin while its conference row
     * survives. roleIn() takes a non-nullable Organization, so walking the
     * whole chain unguarded turns a gate that should answer "no" into a
     * TypeError 500. Both hops stay guarded now that a reviewer reaches this
     * method too: dropping either one would turn the reviewer's "no" into that
     * same TypeError before ReviewerScope is ever consulted.
     */
    public function view(User $user, Submission $submission): bool
    {
        $conference = $submission->conference;
        $organization = $conference?->organization;

        if ($conference === null || $organization === null) {
            return false;
        }

        if ($user->roleIn($organization) !== null) {
            return true;
        }

        // Spec section 4: a reviewer sees assigned-or-pool only.
        return ReviewerScope::allows($user, $submission);
    }

    /**
     * Authors submit; organizers do not type abstracts for them. Filament
     * treats a missing method as ALLOW (fact 17), so both are spelled out.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Submission $submission): bool
    {
        return false;
    }

    /**
     * Withdrawal is the organizer's tool; deletion is not. Note what these
     * `false`s do and do not cover: before() returns true for a platform admin
     * and short-circuits every one of them, so they refuse organization members
     * only. The platform admin has no submissions screen today - when Plan 6
     * gives them one, deletion has to be refused there, in before() or in the
     * resource, because these methods will never be consulted for that role.
     */
    public function delete(User $user, Submission $submission): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Submission $submission): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Submission $submission): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function withdraw(User $user, Submission $submission): bool
    {
        return $this->view($user, $submission);
    }

    public function resendLink(User $user, Submission $submission): bool
    {
        return $this->view($user, $submission);
    }

    /** Exporting is reading every row at once, so it needs the list right. */
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }
}
