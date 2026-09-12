<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Contracts\DnsResolver;
use App\Exceptions\CustomDomainRefused;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CustomDomainVerified;
use App\Support\Domains\CustomDomains;
use App\Support\Domains\DomainName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Spec 5.8. THE only writer of organizations.custom_domain_verified_at.
 *
 * One TXT lookup, one comparison, one timestamp, one email to the platform
 * admin and one activity entry. It does not check the CNAME - Task 1's
 * decision 2 explains why a proxied CNAME cannot be checked from outside - and
 * it does not talk to Coolify: the admin does that by hand, from the runbook.
 */
class VerifyCustomDomain
{
    public function __construct(private readonly DnsResolver $dns) {}

    public function handle(Organization $organization, User $actor): Organization
    {
        // Same guard as ClaimCustomDomain, and for the same reason: every
        // verify click emails every is_platform_admin user and adds a host to
        // the trusted-host list. An organization the platform has declined or
        // suspended reaches neither.
        if (! $organization->isApproved()) {
            throw CustomDomainRefused::because(__('domain.errors.not_approved'));
        }

        $domain = $organization->custom_domain;
        $token = $organization->custom_domain_token;

        if ($domain === null || $token === null || $token === '') {
            throw CustomDomainRefused::because(__('domain.errors.none_claimed'));
        }

        $name = DomainName::txtRecordName($domain);

        try {
            $records = $this->dns->txtRecords($name);
        } catch (RuntimeException $exception) {
            // "We could not ask" and "your record is wrong" are different
            // answers and an organizer can only act on one of them. report()
            // so a persistent resolver failure is visible to the platform,
            // because ten organizers seeing this is an outage, not ten typos.
            report($exception);

            throw CustomDomainRefused::because(__('domain.errors.lookup_failed'));
        }

        if ($records === []) {
            throw CustomDomainRefused::because(__('domain.errors.no_record', ['name' => $name]));
        }

        if (! $this->carriesToken($records, $token)) {
            throw CustomDomainRefused::because(__('domain.errors.token_mismatch', ['name' => $name]));
        }

        // Re-verification IS the Verify button - nothing in this application
        // re-checks a record on a schedule - so a second click on a domain that
        // is already verified is a legitimate action and still answers success.
        // It just writes nothing and mails nobody: the admin has already added
        // this host to Coolify, and the only bound on the button is the
        // 10-per-minute per-actor limiter, which is 14,400 letters a day to
        // every is_platform_admin user and 14,400 rows in the audit log.
        if ($organization->custom_domain_verified_at !== null) {
            return $organization;
        }

        $organization = DB::transaction(function () use ($organization, $domain, $actor): Organization {
            $organization->forceFill(['custom_domain_verified_at' => now()])->save();

            activity()
                ->performedOn($organization)
                ->causedBy($actor)
                ->withProperties(['domain' => $domain])
                ->log('organization.custom_domain_verified');

            Notification::send(
                User::query()->where('is_platform_admin', true)->get(),
                new CustomDomainVerified($organization, $domain),
            );

            return $organization->refresh();
        });

        // The trusted-host list and the routing middleware both read a cached
        // list (Task 3). Forgetting it is what makes the domain work in the
        // same second it is verified rather than up to
        // CASS_DOMAIN_CACHE_SECONDS later - but AFTER the commit, not inside
        // it: verifiedHosts() re-populates the key on any request that lands
        // between the forget and the COMMIT, which would cache the pre-commit
        // answer and leave the just-verified domain 404ing for the full TTL.
        CustomDomains::forget();

        return $organization;
    }

    /**
     * Several DNS panels store the quotation marks an operator typed, and some
     * pad the value. A record that is correct apart from its punctuation is a
     * support request nobody can diagnose from a screenshot, so trim both.
     *
     * hash_equals() rather than ===: the comparison is against a value an
     * outsider can choose, and a constant-time compare costs nothing here.
     *
     * @param  list<string>  $records
     */
    private function carriesToken(array $records, string $token): bool
    {
        foreach ($records as $record) {
            if (hash_equals($token, trim(trim($record), '"\''))) {
                return true;
            }
        }

        return false;
    }
}
