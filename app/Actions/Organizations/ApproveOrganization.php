<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Actions\Mail\SendTemplatedEmail;
use App\Enums\EmailTemplateKey;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;

class ApproveOrganization
{
    public function __construct(private readonly SendTemplatedEmail $send) {}

    public function handle(Organization $organization, User $admin): void
    {
        $organization->forceFill([
            'status' => OrganizationStatus::Approved,
            'status_reason' => null,
            'approved_at' => now(),
            'approved_by' => $admin->id,
        ])->save();

        activity()->performedOn($organization)->causedBy($admin)->log('organization.approved');

        // SendTemplatedEmail rather than the Plan 1 notification this replaces.
        // The copy now lives once, in lang/en/mail.php, where the platform
        // admin already sees it in the template editor - and a transport
        // failure is visible, because TemplatedMail::failed() flips its
        // email_logs row while Illuminate\Notifications\Notification has no
        // failed() hook at all and would have left the row at `queued` for
        // ever.
        foreach ($organization->owners()->get() as $owner) {
            $this->send->handle(
                EmailTemplateKey::OrganizationApproved,
                $organization,
                (string) $owner->email,
                [
                    'organization' => (string) $organization->name,
                    'status_link' => url('/org/'.$organization->slug),
                ],
            );
        }
    }
}
