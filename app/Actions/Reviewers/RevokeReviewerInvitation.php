<?php

declare(strict_types=1);

namespace App\Actions\Reviewers;

use App\Models\ReviewerInvitation;
use App\Models\User;

class RevokeReviewerInvitation
{
    /** The row survives so /invite/{token} can explain rather than 404 (Task 2). */
    public function handle(ReviewerInvitation $invitation, User $actor): ReviewerInvitation
    {
        if ($invitation->revoked_at === null) {
            $invitation->forceFill(['revoked_at' => now()])->save();

            activity()
                ->performedOn($invitation)
                ->causedBy($actor)
                ->withProperties(['email' => $invitation->email])
                ->log('reviewer.invitation_revoked');
        }

        return $invitation;
    }
}
