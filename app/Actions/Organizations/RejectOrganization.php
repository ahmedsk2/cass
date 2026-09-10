<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationRejected;
use Illuminate\Support\Facades\Notification;

class RejectOrganization
{
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

        Notification::send($organization->owners()->get(), new OrganizationRejected($organization, $reason, $wasApproved));
    }
}
