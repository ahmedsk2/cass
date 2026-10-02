<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * THE writer of an organization's profile and branding: the organizer's tenant
 * profile page and the platform admin's edit page both save through here.
 *
 * The custom-domain columns are not in ATTRIBUTES and are not writable here:
 * ClaimCustomDomain, VerifyCustomDomain and ReleaseCustomDomain own them. Nor
 * are `slug`, `status` or `purpose` - the slug is in every public URL, the
 * status has ApproveOrganization and RejectOrganization, and the purpose is
 * what the organization wrote when it registered.
 */
class UpdateOrganizationProfile
{
    /** @var list<string> */
    public const ATTRIBUTES = [
        'name', 'type', 'country', 'website', 'contact_email', 'publish_contact_email',
        'logo_path', 'primary_color', 'accent_color',
    ];

    public function __construct(private readonly DeleteOrganizationLogo $logos) {}

    /** @param  array<string, mixed>  $data */
    public function handle(Organization $organization, array $data, User $actor): Organization
    {
        $previousLogo = $organization->logo_path;

        DB::transaction(function () use ($organization, $data, $actor, $previousLogo): void {
            $organization->fill(Arr::only($data, self::ATTRIBUTES));

            $changed = array_keys($organization->getDirty());

            $organization->save();

            if ($previousLogo !== null && $previousLogo !== $organization->logo_path) {
                // afterCommit() rather than a line after this closure, because
                // both callers are Filament save() methods that open a
                // transaction of their own the day a panel turns on
                // ->databaseTransactions(): this then waits for THAT commit,
                // and a rollback discards it. DeleteOrganizationLogo holds the
                // rest of the rule.
                DB::afterCommit(fn (): bool => $this->logos->handle($previousLogo));
            }

            if ($changed === []) {
                return;
            }

            // Names, not values: the row holds the values, the model's own
            // LogsActivity entry already carries the old and new name, and a
            // contact address does not need a second copy in the audit log.
            activity()
                ->performedOn($organization)
                ->causedBy($actor)
                ->withProperties(['changed' => $changed])
                ->log('organization.profile_updated');
        });

        return $organization;
    }
}
