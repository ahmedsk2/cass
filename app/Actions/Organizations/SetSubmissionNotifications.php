<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Exceptions\MemberChangeRefused;
use App\Models\Organization;
use App\Models\User;

/**
 * `organization_members.notify_on_submission`, the column Plan 1 created and
 * Plan 3's SubmitAbstract::notifiableMembers() reads. A person sets their own
 * preference and nobody else's: an owner switching a colleague's mail off is a
 * support ticket waiting to happen, and there is no reason for it.
 */
class SetSubmissionNotifications
{
    public function handle(Organization $organization, User $member, bool $enabled, User $actor): void
    {
        if (! $member->is($actor)) {
            throw new MemberChangeRefused([__('members.errors.own_notifications')]);
        }

        if ($member->roleIn($organization) === null) {
            throw new MemberChangeRefused([__('members.errors.not_a_member')]);
        }

        $organization->members()->updateExistingPivot($member->getKey(), [
            'notify_on_submission' => $enabled,
        ]);
    }
}
