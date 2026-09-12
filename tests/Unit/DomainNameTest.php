<?php

declare(strict_types=1);

use App\Support\Domains\DomainName;

// No RefreshDatabase: this class touches nothing but a string and config().

beforeEach(function () {
    config()->set('app.url', 'https://cass.towardpcc.com');
});

it('normalises a hostname the way a resolver would', function (string $input, string $expected) {
    expect(DomainName::normalise($input))->toBe($expected);
})->with([
    // Case, whitespace and the root label's trailing dot are all noise: DNS is
    // case-insensitive and `example.org.` and `example.org` are the same name.
    ['Abstracts.Example.ORG', 'abstracts.example.org'],
    ['  abstracts.example.org  ', 'abstracts.example.org'],
    ['abstracts.example.org.', 'abstracts.example.org'],
    // An organizer who pastes a URL instead of a hostname is the single most
    // likely input on this field, and refusing it teaches nothing.
    ['https://abstracts.example.org/', 'abstracts.example.org'],
    ['http://abstracts.example.org/submit?x=1', 'abstracts.example.org'],
    // intl is installed (php -m), so an Arabic or accented domain becomes the
    // A-label the resolver and the certificate will actually use.
    ['müller.example.org', 'xn--mller-kva.example.org'],
]);

it('refuses a hostname that cannot be a custom domain', function (string $input, string $reason) {
    expect(DomainName::problem($input))->toBe($reason);
})->with([
    ['', 'domain.errors.required'],
    ['localhost', 'domain.errors.not_a_domain'],
    // An IP literal has no zone to put a TXT record in.
    ['203.0.113.7', 'domain.errors.not_a_domain'],
    ['abstracts..example.org', 'domain.errors.not_a_domain'],
    ['-abstracts.example.org', 'domain.errors.not_a_domain'],
    ['abstracts.example.org/submit', 'domain.errors.not_a_domain'],
    [str_repeat('a', 64).'.example.org', 'domain.errors.label_too_long'],
    [str_repeat('a.', 130).'org', 'domain.errors.too_long'],
    // The platform's own host, and anything under it. Claiming
    // cass.towardpcc.com would point the trusted-host list and the routing
    // middleware at the platform itself; claiming a subdomain of it would let
    // an organizer serve pages from a name that looks like ours.
    ['cass.towardpcc.com', 'domain.errors.platform_host'],
    ['CASS.TOWARDPCC.COM', 'domain.errors.platform_host'],
    ['org.cass.towardpcc.com', 'domain.errors.platform_host'],
]);

it('accepts a plain second-level and a deep subdomain', function () {
    expect(DomainName::problem('example.org'))->toBeNull()
        ->and(DomainName::problem('abstracts.cpds.example.org'))->toBeNull()
        ->and(DomainName::problem('abstracts.example.co.uk'))->toBeNull();
});

it('builds the two records an organizer has to publish', function () {
    config()->set('cass.domains.cname_target', 'cass.towardpcc.com');

    expect(DomainName::txtRecordName('abstracts.example.org'))->toBe('_cass-verify.abstracts.example.org')
        ->and(DomainName::cnameTarget())->toBe('cass.towardpcc.com');
});
