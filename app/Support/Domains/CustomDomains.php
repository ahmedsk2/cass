<?php

declare(strict_types=1);

namespace App\Support\Domains;

use Illuminate\Support\Facades\Cache;

/**
 * THE list of verified custom domains. Task 3 gives it verifiedHosts() and
 * organizationFor(); this task needs only the cache key and the invalidation,
 * because the two actions that change a domain have to forget it.
 */
final class CustomDomains
{
    public const CACHE_KEY = 'cass:custom-domains';

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
