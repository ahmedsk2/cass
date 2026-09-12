<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Exceptions\CustomDomainRefused;
use App\Models\Organization;
use App\Models\User;
use App\Support\Domains\DomainName;
use Illuminate\Support\Facades\DB;

/**
 * THE writer of organizations.custom_domain and organizations.custom_domain_token.
 *
 * The token is stored as plaintext, deliberately, and Task 1's decision 1
 * argues it at length: it is published in public DNS, it authorises nothing,
 * and the screen has to be able to show it again tomorrow. Hashing it would
 * make the feature impossible rather than safer.
 */
class ClaimCustomDomain
{
    public function handle(Organization $organization, string $input, User $actor): Organization
    {
        $domain = DomainName::normalise($input);
        $problem = DomainName::problem($input);

        if ($problem !== null) {
            throw CustomDomainRefused::because(__($problem));
        }

        // An organization the platform has not approved (or has suspended) must
        // not be able to put a host into the trusted-host list or to mail every
        // platform admin by pressing Verify. Its pages 404 anyway (approval is
        // only checked at render today); this keeps the routing surface and the
        // notification out of its reach too.
        if (! $organization->isApproved()) {
            throw CustomDomainRefused::because(__('domain.errors.not_approved'));
        }

        // The column is UNIQUE (Plan 1's migration), so the alternative to
        // this read is a QueryException with a MySQL error string in it.
        // withTrashed(): a soft-deleted organization still holds the row and
        // still holds the unique index.
        $taken = Organization::query()
            ->withTrashed()
            ->where('custom_domain', $domain)
            ->whereKeyNot($organization->getKey())
            ->exists();

        if ($taken) {
            throw CustomDomainRefused::because(__('domain.errors.taken', [
                'contact' => (string) config('cass.platform_contact_email'),
            ]));
        }

        return DB::transaction(function () use ($organization, $domain, $actor): Organization {
            $previous = $organization->custom_domain;

            $organization->forceFill([
                'custom_domain' => $domain,
                // 32 bytes of randomness as hex is 64 characters, which is
                // exactly what string('custom_domain_token', 64) holds. Minted
                // on every claim, including a re-claim of the same domain:
                // the only reason to press the button twice is that the record
                // was lost, and handing back the old value helps nobody.
                'custom_domain_token' => bin2hex(random_bytes(32)),
                // A changed domain is an unverified domain. Leaving the
                // timestamp would leave the trusted-host list and the routing
                // middleware answering for a name this organization no longer
                // claims.
                'custom_domain_verified_at' => null,
            ])->save();

            activity()
                ->performedOn($organization)
                ->causedBy($actor)
                ->withProperties(['domain' => $domain, 'previous' => $previous])
                ->log('organization.custom_domain_claimed');

            return $organization->refresh();
        });
    }
}
