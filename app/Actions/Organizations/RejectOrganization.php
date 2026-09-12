<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Actions\Mail\SendTemplatedEmail;
use App\Enums\EmailTemplateKey;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;

class RejectOrganization
{
    public function __construct(private readonly SendTemplatedEmail $send) {}

    public function handle(Organization $organization, User $admin, string $reason): void
    {
        $wasApproved = $organization->isApproved();

        $organization->forceFill([
            'status' => OrganizationStatus::Suspended,
            'status_reason' => $reason,
            'approved_at' => null,
            'approved_by' => null,
        ])->save();

        activity()->performedOn($organization)->causedBy($admin)->withProperties(['reason' => $reason])->log('organization.rejected');

        // Two different letters, as the notification this replaces had: telling
        // an organization that WAS approved "we could not approve you" is
        // wrong. A union-typed context carries no room for a boolean, so the
        // branch is a second template key rather than a flag on one.
        $key = $wasApproved
            ? EmailTemplateKey::OrganizationSuspended
            : EmailTemplateKey::OrganizationRejected;

        foreach ($organization->owners()->get() as $owner) {
            $this->send->handle(
                $key,
                $organization,
                (string) $owner->email,
                [
                    'organization' => (string) $organization->name,
                    // Spec 5.1 step 4 is "rejects with a reason … owner
                    // emailed": an owner who is not told why cannot act on it.
                    'reason' => $reason,
                    'status_link' => url('/org/'.$organization->slug),
                ],
            );
        }
    }
}
