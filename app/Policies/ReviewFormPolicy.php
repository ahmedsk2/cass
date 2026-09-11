<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\ReviewForm;
use App\Models\User;
use Filament\Facades\Filament;

class ReviewFormPolicy
{
    public function viewAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }

    public function view(User $user, ReviewForm $reviewForm): bool
    {
        return $user->roleIn($reviewForm->conference->organization) !== null;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ReviewForm $reviewForm): bool
    {
        return $this->view($user, $reviewForm) && ! $reviewForm->isLocked();
    }

    public function delete(User $user, ReviewForm $reviewForm): bool
    {
        return false;
    }

    /**
     * Filament checks a bulk action with the record-less `*Any` abilities and
     * treats a policy that does not define one as allowed, so all three are
     * spelled out: a review form is the record of what reviewers were asked and
     * nobody deletes one, which makes restoring and force-deleting moot too.
     */
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
