<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomField;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

class CustomFieldPolicy
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

    public function view(User $user, CustomField $customField): bool
    {
        return $user->roleIn($customField->conference->organization) !== null;
    }

    public function update(User $user, CustomField $customField): bool
    {
        return $this->view($user, $customField);
    }

    public function delete(User $user, CustomField $customField): bool
    {
        return $this->view($user, $customField);
    }

    public function reorder(User $user): bool
    {
        return $this->viewAny($user);
    }
}
