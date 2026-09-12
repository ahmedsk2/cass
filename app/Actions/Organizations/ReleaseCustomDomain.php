<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use App\Support\Domains\CustomDomains;
use Illuminate\Support\Facades\DB;

/**
 * All three columns back to null, so the name is immediately claimable by
 * anybody - including by the organization that just gave it up, which is what
 * a typo in the domain field looks like from the outside.
 *
 * Nothing tells Coolify. The host stays on the resource and Traefik keeps a
 * certificate for it until an admin removes it; that is a cleanup step in the
 * runbook, not an application concern, and leaving it is harmless: the
 * middleware answers 404 for a host with no verified organization behind it.
 */
class ReleaseCustomDomain
{
    public function handle(Organization $organization, User $actor): Organization
    {
        $previous = $organization->custom_domain;

        $organization = DB::transaction(function () use ($organization, $previous, $actor): Organization {
            $organization->forceFill([
                'custom_domain' => null,
                'custom_domain_token' => null,
                'custom_domain_verified_at' => null,
            ])->save();

            activity()
                ->performedOn($organization)
                ->causedBy($actor)
                ->withProperties(['domain' => $previous])
                ->log('organization.custom_domain_released');

            return $organization->refresh();
        });

        // After the COMMIT, not inside it: verifiedHosts() re-populates the
        // cache key on any request that lands between a forget and the commit,
        // which would put the released host back in the trusted list for the
        // full CASS_DOMAIN_CACHE_SECONDS.
        CustomDomains::forget();

        return $organization;
    }
}
