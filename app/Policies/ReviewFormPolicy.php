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
}
