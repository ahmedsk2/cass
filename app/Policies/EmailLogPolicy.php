<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmailLog;
use App\Models\User;

/**
 * Platform-admin only, and read-only even for them. The log spans every tenant
 * by design (a platform-wide password reset has no organization), so there is
 * no tenant-scoped view of it and no `before()` shortcut - the one rule is
 * stated on every method.
 */
class EmailLogPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_platform_admin;
    }

    public function view(User $user, EmailLog $log): bool
    {
        return (bool) $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EmailLog $log): bool
    {
        return false;
    }

    public function delete(User $user, EmailLog $log): bool
    {
        return false;
    }

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
