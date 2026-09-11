<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Spec section 4 puts "create / edit conferences, forms, templates" in reach of
 * every organization member, so this mirrors ConferencePolicy's membership rule
 * rather than its owner/admin rule.
 */
class EmailTemplatePolicy
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

    public function view(User $user, EmailTemplate $template): bool
    {
        return $user->roleIn($template->conference->organization) !== null;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, EmailTemplate $template): bool
    {
        return $this->view($user, $template);
    }

    /** "Reset to default" is a delete of the override row. */
    public function delete(User $user, EmailTemplate $template): bool
    {
        return $this->view($user, $template);
    }

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
}
