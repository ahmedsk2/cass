<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\OrganizationInvitation;
use App\Models\User;

/**
 * The row survives with `revoked_at` stamped rather than being deleted, so the
 * person holding the emailed link reads "this invitation has been withdrawn"
 * instead of a 404 (Task 2).
 */
class RevokeInvitation
{
    public function handle(OrganizationInvitation $invitation, User $actor): OrganizationInvitation
    {
        if ($invitation->revoked_at === null) {
            $invitation->forceFill(['revoked_at' => now()])->save();

            activity()
                ->performedOn($invitation)
                ->causedBy($actor)
                ->withProperties(['email' => $invitation->email])
                ->log('organization.invitation_revoked');
        }

        return $invitation;
    }
}
