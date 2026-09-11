<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
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

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }

    public function view(User $user, Submission $submission): bool
    {
        return $user->roleIn($submission->conference->organization) !== null;
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

    /** Withdrawal is the organizer's tool; deletion is not, in any panel. */
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
