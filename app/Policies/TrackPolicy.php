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

    public function reorder(User $user): bool
    {
        return $this->viewAny($user);
    }
}
