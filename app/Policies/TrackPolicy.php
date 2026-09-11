<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\Track;
use App\Models\User;
use Filament\Facades\Filament;

class TrackPolicy
{
    public function viewAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Track $track): bool
    {
        return $user->roleIn($track->conference->organization) !== null;
    }

    public function update(User $user, Track $track): bool
    {
        return $this->view($user, $track);
    }

    public function delete(User $user, Track $track): bool
    {
        return $this->view($user, $track);
    }

    /**
     * Filament checks a bulk action with the record-less `*Any` abilities and
     * treats a policy that does not define one as allowed, so all three are
     * spelled out even where the answer is always "no". deleteAny mirrors
     * delete(); tracks are not soft-deletable, so there is nothing to restore
     * or force-delete.
     */
    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return $this->viewAny($user);
    }
}
