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

    /**
     * The same null guard as SubmissionPolicy::view(): Conference and
     * Organization both soft delete and the foreign key only restricts a hard
     * delete, so either hop can be null while this override row survives.
     * roleIn() takes a non-nullable Organization, so an unguarded walk is a
     * TypeError 500 out of a gate whose answer should simply be "no".
     */
    public function view(User $user, EmailTemplate $template): bool
    {
        $organization = $template->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
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
