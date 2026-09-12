<?php

declare(strict_types=1);

namespace App\Support\Domains;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * THE list of verified custom domains, and the only lookup from a host to an
 * organization. Three readers and no others: the trusted-host closure in
 * bootstrap/app.php, ResolveCustomDomain, and the two actions that invalidate
 * the cache.
 */
final class CustomDomains
{
    public const CACHE_KEY = 'cass:custom-domains';

    /**
     * Every host with a verified domain, lower-cased.
     *
     * Cached because the trusted-host closure reads it on EVERY request
     * (fact 14) and a query per request is avoidable. Guarded because a query
     * per request that throws is a 500 on the main site whenever the database
     * blinks - and the platform's own host is added by the closure separately,
     * so an empty answer here leaves cass.towardpcc.com working and custom
     * domains 400ing, which is the correct direction to fail.
     *
     * @return list<string>
     */
    public static function verifiedHosts(): array
    {
        $seconds = max(1, (int) config('cass.domains.cache_seconds'));

        try {
            /** @var list<string> $hosts */
            $hosts = Cache::remember(self::CACHE_KEY, $seconds, static fn (): array => Organization::query()
                // An organization the platform has refused or suspended keeps
                // neither a routing surface nor a place in TrustHosts. Its
                // pages 404 anyway (approval is checked at render), and this
                // takes the host out of the trusted list within
                // CASS_DOMAIN_CACHE_SECONDS.
                ->where('status', OrganizationStatus::Approved)
                ->whereNotNull('custom_domain')
                ->whereNotNull('custom_domain_verified_at')
                ->orderBy('custom_domain')
                ->pluck('custom_domain')
                ->map(static fn (mixed $host): string => strtolower((string) $host))
                ->all());

            return $hosts;
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * The organization a request's Host header belongs to, or null.
     *
     * Deliberately NOT cached as a model: an Organization carries a status, a
     * soft-delete flag and branding that the page then renders, and a cached
     * copy of all that would be stale for up to a minute in the one place
     * where suspending an organization has to take its pages offline
     * immediately (the owner's recorded decision, backlog).
     */
    public static function organizationFor(string $host): ?Organization
    {
        $normalised = DomainName::normalise($host);

        if ($normalised === '' || ! in_array($normalised, self::verifiedHosts(), true)) {
            return null;
        }

        return Organization::query()
            ->where('custom_domain', $normalised)
            ->whereNotNull('custom_domain_verified_at')
            ->first();
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
