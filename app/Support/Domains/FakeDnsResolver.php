<?php

declare(strict_types=1);

namespace App\Support\Domains;

use App\Contracts\DnsResolver;
use RuntimeException;

/**
 * The test double, in app/ rather than tests/ so Larastan analyses it
 * (phpstan.neon lists app, config, database, routes - not tests) and so the
 * two implementations are provably the same shape.
 *
 * It is never bound in production: AppServiceProvider binds SystemDnsResolver,
 * and a test replaces the instance.
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    private array $records = [];

    /** @var list<string> */
    private array $failing = [];

    /** @var list<string> */
    private array $queried = [];

    /** @param list<string> $values */
    public function set(string $name, array $values): self
    {
        // No array_values(): the parameter is already a list, and Larastan
        // level 6 reports the re-indexing as a call with no effect.
        $this->records[strtolower($name)] = $values;

        return $this;
    }

    public function fail(string $name): self
    {
        $this->failing[] = strtolower($name);

        return $this;
    }

    public function txtRecords(string $name): array
    {
        $key = strtolower($name);
        $this->queried[] = $key;

        if (in_array($key, $this->failing, true)) {
            throw new RuntimeException("DNS lookup failed for [{$name}].");
        }

        return $this->records[$key] ?? [];
    }

    /**
     * Every name this resolver was asked about, in order. The tests assert on
     * it so that a second lookup added later - a CNAME check, a retry loop -
     * is a visible change rather than a silent one.
     *
     * @return list<string>
     */
    public function queriedNames(): array
    {
        return $this->queried;
    }
}
