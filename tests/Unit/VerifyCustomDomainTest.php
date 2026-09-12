<?php

declare(strict_types=1);

use App\Actions\Organizations\ClaimCustomDomain;
use App\Actions\Organizations\ReleaseCustomDomain;
use App\Actions\Organizations\VerifyCustomDomain;
use App\Contracts\DnsResolver;
use App\Exceptions\CustomDomainRefused;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CustomDomainVerified;
use App\Support\Domains\FakeDnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.url', 'https://cass.towardpcc.com');
    config()->set('cass.domains.cname_target', 'cass.towardpcc.com');

    Notification::fake();

    $this->dns = new FakeDnsResolver;
    // The first container binding in this application (Task 1 decision 3).
    // A concrete class could not be substituted at all: dns_get_record() is a
    // global function, not a method.
    app()->instance(DnsResolver::class, $this->dns);

    $this->organization = Organization::factory()->approved()->create();
    $this->actor = User::factory()->create();
});

it('claims a domain, mints a token and leaves it unverified', function () {
    $organization = app(ClaimCustomDomain::class)->handle($this->organization, ' Abstracts.Example.ORG ', $this->actor);

    expect($organization->custom_domain)->toBe('abstracts.example.org')
        // 32 random bytes as hex. The column is string(64) and this is exactly
        // 64 characters - see the migration, which sized it for a hash.
        ->and($organization->custom_domain_token)->toHaveLength(64)
        ->and($organization->custom_domain_token)->toMatch('/^[0-9a-f]{64}$/')
        ->and($organization->custom_domain_verified_at)->toBeNull()
        ->and($organization->hasVerifiedCustomDomain())->toBeFalse();

    expect(Activity::query()->where('description', 'organization.custom_domain_claimed')->exists())->toBeTrue();
});

it('refuses a domain another organization already claimed', function () {
    $other = withoutTenant(fn (): Organization => Organization::factory()->approved()->create());
    app(ClaimCustomDomain::class)->handle($other, 'abstracts.example.org', $this->actor);

    // The column is UNIQUE, so the alternative to this check is a
    // QueryException in an organizer's face. Answer the same way whether the
    // other claim is verified or not: an unverified claim is somebody
    // mid-setup, and stealing the name from under them is worse than asking
    // them to finish.
    expect(fn () => app(ClaimCustomDomain::class)->handle($this->organization, 'Abstracts.Example.org', $this->actor))
        ->toThrow(CustomDomainRefused::class);
});

it('lets an organization re-claim the domain it already holds, and rotates the token', function () {
    $first = app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);
    $token = (string) $first->custom_domain_token;

    $second = app(ClaimCustomDomain::class)->handle($this->organization->fresh(), 'abstracts.example.org', $this->actor);

    // Same domain, new token: the only reason to press "claim" again is that
    // the record was lost, and handing back the same string would leave an
    // organizer staring at a value they already published.
    expect($second->custom_domain)->toBe('abstracts.example.org')
        ->and($second->custom_domain_token)->not->toBe($token);
});

it('un-verifies when the domain changes', function () {
    app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);
    $this->dns->set('_cass-verify.abstracts.example.org', [$this->organization->fresh()?->custom_domain_token]);
    app(VerifyCustomDomain::class)->handle($this->organization->fresh(), $this->actor);

    expect($this->organization->fresh()?->hasVerifiedCustomDomain())->toBeTrue();

    app(ClaimCustomDomain::class)->handle($this->organization->fresh(), 'submit.example.org', $this->actor);

    // A verified flag that survives a domain change would leave the trusted
    // host list and the routing middleware answering for a name this
    // organization no longer claims.
    expect($this->organization->fresh()?->custom_domain)->toBe('submit.example.org')
        ->and($this->organization->fresh()?->custom_domain_verified_at)->toBeNull();
});

it('verifies when the txt record carries the token', function () {
    // A real platform admin, because Notification::assertSentTo() throws
    // "No notifiable given." on an empty collection (NotificationFake:68-72)
    // and neither UserFactory::definition() nor OrganizationFactory::approved()
    // creates one.
    $admin = User::factory()->platformAdmin()->create();

    $organization = app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);

    // Real zones carry other TXT records at the same name; the token has to be
    // found among them, not be the only one.
    $this->dns->set('_cass-verify.abstracts.example.org', [
        'v=spf1 include:example.net ~all',
        (string) $organization->custom_domain_token,
    ]);

    $verified = app(VerifyCustomDomain::class)->handle($organization, $this->actor);

    expect($verified->custom_domain_verified_at)->not->toBeNull()
        ->and($verified->hasVerifiedCustomDomain())->toBeTrue()
        ->and($this->dns->queriedNames())->toBe(['_cass-verify.abstracts.example.org']);

    Notification::assertSentTo($admin, CustomDomainVerified::class);

    expect(Activity::query()->where('description', 'organization.custom_domain_verified')->exists())->toBeTrue();
});

it('tolerates a provider that wraps the value in quotes and pads it', function () {
    $organization = app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);
    $this->dns->set('_cass-verify.abstracts.example.org', ['  "'.$organization->custom_domain_token.'"  ']);

    // Several DNS panels store the quotes an operator typed. Refusing a record
    // that is correct apart from its punctuation produces a support request
    // nobody can diagnose from a screenshot.
    expect(app(VerifyCustomDomain::class)->handle($organization, $this->actor)->hasVerifiedCustomDomain())->toBeTrue();
});

it('refuses when the record is missing, wrong, or the zone does not resolve', function (array $records, string $reason) {
    $organization = app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);
    $this->dns->set('_cass-verify.abstracts.example.org', $records);

    // Both reasons name the record they looked at, so the expectation has to
    // carry the same replacement the action passes - otherwise this asserts
    // against a sentence with a literal ":name" in it, which nothing produces.
    expect(fn () => app(VerifyCustomDomain::class)->handle($organization, $this->actor))
        ->toThrow(CustomDomainRefused::class, __($reason, ['name' => '_cass-verify.abstracts.example.org']));

    expect($organization->fresh()?->custom_domain_verified_at)->toBeNull();
    Notification::assertNothingSent();
})->with([
    'no records at all' => [[], 'domain.errors.no_record'],
    'the wrong token' => [[str_repeat('b', 64)], 'domain.errors.token_mismatch'],
    'somebody else\'s spf' => [['v=spf1 -all'], 'domain.errors.token_mismatch'],
]);

it('reports a resolver failure as a failure and not as a mismatch', function () {
    $organization = app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);
    $this->dns->fail('_cass-verify.abstracts.example.org');

    // SERVFAIL and "no such record" are different answers and an organizer can
    // act on only one of them. Collapsing both into "wrong token" sends them
    // to re-type a record that is already correct.
    expect(fn () => app(VerifyCustomDomain::class)->handle($organization, $this->actor))
        ->toThrow(CustomDomainRefused::class, __('domain.errors.lookup_failed'));
});

it('refuses to verify an organization with no domain claimed', function () {
    expect(fn () => app(VerifyCustomDomain::class)->handle($this->organization, $this->actor))
        ->toThrow(CustomDomainRefused::class, __('domain.errors.none_claimed'));
});

it('releases a domain and clears all three columns', function () {
    $organization = app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);
    $this->dns->set('_cass-verify.abstracts.example.org', [(string) $organization->custom_domain_token]);
    app(VerifyCustomDomain::class)->handle($organization, $this->actor);

    $released = app(ReleaseCustomDomain::class)->handle($organization->fresh(), $this->actor);

    expect($released->custom_domain)->toBeNull()
        ->and($released->custom_domain_token)->toBeNull()
        ->and($released->custom_domain_verified_at)->toBeNull();

    // The name has to become claimable again, immediately, by anybody -
    // including the organization that just gave it up.
    $other = withoutTenant(fn (): Organization => Organization::factory()->approved()->create());
    expect(app(ClaimCustomDomain::class)->handle($other, 'abstracts.example.org', $this->actor)->custom_domain)
        ->toBe('abstracts.example.org');

    expect(Activity::query()->where('description', 'organization.custom_domain_released')->exists())->toBeTrue();
});
