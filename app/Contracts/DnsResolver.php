<?php

declare(strict_types=1);

namespace App\Contracts;

use RuntimeException;

/**
 * One question, asked once per organizer click: what TXT records exist at this
 * name?
 *
 * An interface rather than a concrete class because the thing behind it is a
 * *global function* (dns_get_record) and there is nothing to substitute in a
 * test otherwise - see Task 1's decision 3. It is deliberately narrow: this
 * application never needs A, CNAME, MX or anything else (Task 1 decision 2
 * argues why the CNAME is not checked), and an interface that grows a method
 * per record type is an interface nobody can fake in one line.
 */
interface DnsResolver
{
    /**
     * Every TXT string published at $name, in whatever order the resolver
     * returned them. An empty array means the name resolved and has no TXT
     * records; that is a different answer from a failure, which throws.
     *
     * @return list<string>
     *
     * @throws RuntimeException when the lookup itself failed - SERVFAIL, a
     *                          timeout, no reachable resolver. The caller has
     *                          to tell an organizer "we could not ask" rather
     *                          than "your record is wrong".
     */
    public function txtRecords(string $name): array;
}
