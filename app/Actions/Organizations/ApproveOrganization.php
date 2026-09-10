<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationApproved;
use Illuminate\Support\Facades\Notification;

class ApproveOrganization
{
    public function handle(Organization $organization, User $admin): void
    {
        $organization->forceFill([
            'status' => OrganizationStatus::Approved,
            'status_reason' => null,
            'approved_at' => now(),
            'approved_by' => $admin->id,
        ])->save();

        activity()->performedOn($organization)->causedBy($admin)->log('organization.approved');

        Notification::send($organization->owners()->get(), new OrganizationApproved($organization));
    }
}
