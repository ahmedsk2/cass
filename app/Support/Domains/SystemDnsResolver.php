<?php

declare(strict_types=1);

namespace App\Support\Domains;

use App\Contracts\DnsResolver;
use RuntimeException;

/**
 * dns_get_record() over the container's resolver. Nothing caches: verification
 * happens when a human clicks a button, and a cached negative answer is the
 * one thing guaranteed to waste that human's afternoon.
 */
final class SystemDnsResolver implements DnsResolver
{
    public function txtRecords(string $name): array
    {
        // The @ is load-bearing and is the only one in this application.
        // dns_get_record() emits a PHP warning on SERVFAIL and on a timeout
        // *and* returns false, so without it a DNS outage becomes an
        // ErrorException in an organizer's face instead of the sentence
        // VerifyCustomDomain is about to write. The false is still checked.
        $records = @dns_get_record($name, DNS_TXT);

        if ($records === false) {
            throw new RuntimeException("DNS lookup failed for [{$name}].");
        }

        $values = [];

        foreach ($records as $record) {
            // PHP gives a TXT record two shapes: `txt` is every string in the
            // record joined, `entries` is the list. A value longer than 255
            // octets arrives as several entries and only `txt` has the whole
            // thing - a 64-character token never splits, but reading both
            // costs nothing and makes this correct for a longer value later.
            if (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = $record['txt'];
            }

            foreach ((array) ($record['entries'] ?? []) as $entry) {
                if (is_string($entry)) {
                    $values[] = $entry;
                }
            }
        }

        return array_values(array_unique($values));
    }
}
