<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Support\Domains\CustomDomains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.url', 'https://cass.towardpcc.com');
    CustomDomains::forget();
});

it('lists only verified domains', function () {
    $verified = Organization::factory()->approved()->create();
    $verified->forceFill([
        'custom_domain' => 'abstracts.example.org',
        'custom_domain_token' => str_repeat('a', 64),
        'custom_domain_verified_at' => now(),
    ])->save();

    $claimed = Organization::factory()->approved()->create();
    $claimed->forceFill([
        'custom_domain' => 'pending.example.org',
        'custom_domain_token' => str_repeat('b', 64),
    ])->save();

    expect(CustomDomains::verifiedHosts())->toBe(['abstracts.example.org']);
});

it('leaves a soft-deleted organization out of the list', function () {
    $organization = Organization::factory()->approved()->create();
    $organization->forceFill([
        'custom_domain' => 'abstracts.example.org',
        'custom_domain_token' => str_repeat('a', 64),
        'custom_domain_verified_at' => now(),
    ])->save();
    $organization->delete();

    CustomDomains::forget();

    // A deleted organization's pages 404 anyway, but leaving the host in the
    // trusted list means the request gets that far, which is a routing surface
    // nothing owns.
    expect(CustomDomains::verifiedHosts())->toBe([]);
});

it('caches the list and forgets it on demand', function () {
    $organization = Organization::factory()->approved()->create();
    $organization->forceFill([
        'custom_domain' => 'abstracts.example.org',
        'custom_domain_token' => str_repeat('a', 64),
        'custom_domain_verified_at' => now(),
    ])->save();

    expect(CustomDomains::verifiedHosts())->toBe(['abstracts.example.org']);

    // Written straight through the query builder so no model event and no
    // action can invalidate the cache for us: this asserts the cache, not the
    // invalidation. where(), not whereKey(): whereKey() is an Eloquent builder
    // method, and Query\Builder::__call would turn it into dynamicWhere() -
    // `where "key" = ?`, which SQLite quietly reads as the string 'key' and
    // updates nothing at all.
    DB::table('organizations')
        ->where($organization->getKeyName(), $organization->getKey())
        ->update(['custom_domain' => 'moved.example.org']);

    expect(CustomDomains::verifiedHosts())->toBe(['abstracts.example.org']);

    CustomDomains::forget();

    expect(CustomDomains::verifiedHosts())->toBe(['moved.example.org']);
});

it('answers an empty list rather than throwing when the lookup fails', function () {
    // Not DB::shouldReceive(): Eloquent holds the DatabaseManager directly
    // (Model::setConnectionResolver), so swapping the facade changes nothing,
    // and with no organizations in the test the real query would return []
    // either way. Break the cache instead - Cache::remember is the first line
    // inside the same try - which is the same class of failure from
    // verifiedHosts()'s point of view.
    Cache::shouldReceive('remember')->andThrow(new RuntimeException('cache gone'));

    // The trusted-host closure runs on EVERY request, before anything can
    // render an error page. A throw here is a 500 on the main site because a
    // custom domain's lookup failed.
    expect(CustomDomains::verifiedHosts())->toBe([]);
});

it('leaves a suspended organization out of the trusted list', function () {
    $organization = Organization::factory()->approved()->create();
    $organization->forceFill([
        'custom_domain' => 'abstracts.example.org',
        'custom_domain_token' => str_repeat('a', 64),
        'custom_domain_verified_at' => now(),
    ])->save();

    expect(CustomDomains::verifiedHosts())->toBe(['abstracts.example.org']);

    $organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
    CustomDomains::forget();

    // An organization the platform has suspended keeps neither a routing
    // surface nor a place in TrustHosts. Its domain 400s within
    // CASS_DOMAIN_CACHE_SECONDS, which is the same direction the rest of this
    // task fails in.
    expect(CustomDomains::verifiedHosts())->toBe([]);
});

it('produces anchored, quoted trusted-host patterns', function () {
    // bootstrap/app.php turns each host into a PATTERN. Symfony wraps every
    // TrustHosts entry as `{...}i` and matches it UNANCHORED, so a bare
    // `abstracts.example.org` would also trust `abstracts.example.org.evil.test`
    // and would treat every dot as "any character".
    foreach (['cass.towardpcc.com', 'abstracts.example.org'] as $host) {
        $pattern = '{^'.preg_quote($host).'$}i';

        expect(preg_match($pattern, $host))->toBe(1)
            ->and(preg_match($pattern, $host.'.evil.test'))->toBe(0)
            ->and(preg_match($pattern, str_replace('.', 'X', $host)))->toBe(0);
    }
});

it('puts the platform host first and every verified domain after it in the trusted-host list', function () {
    // TrustHosts is a no-op under runningUnitTests() (TrustHosts:98-101), so
    // the closure in bootstrap/app.php runs nowhere else in this suite - and a
    // closure that forgot verifiedHosts() is HTTP 400 on every custom domain
    // in production with a green suite. hosts() is public and reads the static
    // the bootstrap file set, so it can be called directly.
    $organization = Organization::factory()->approved()->create();
    $organization->forceFill([
        'custom_domain' => 'abstracts.example.org',
        'custom_domain_token' => str_repeat('a', 64),
        'custom_domain_verified_at' => now(),
    ])->save();

    CustomDomains::forget();

    $hosts = app(TrustHosts::class)->hosts();

    expect($hosts[0] ?? null)->toBe('^'.preg_quote('cass.towardpcc.com').'$')
        ->and($hosts)->toContain('^'.preg_quote('abstracts.example.org').'$');
});

it('still trusts the platform host when the domain lookup throws', function () {
    // The direction this has to fail in: the main site keeps working and
    // custom domains 400, never the other way round.
    Cache::shouldReceive('remember')->andThrow(new RuntimeException('cache gone'));

    expect(app(TrustHosts::class)->hosts())
        ->toBe(['^'.preg_quote('cass.towardpcc.com').'$']);
});

it('resolves a host to its organization, case-insensitively, and nothing else', function () {
    $organization = Organization::factory()->approved()->create();
    $organization->forceFill([
        'custom_domain' => 'abstracts.example.org',
        'custom_domain_token' => str_repeat('a', 64),
        'custom_domain_verified_at' => now(),
    ])->save();

    expect(CustomDomains::organizationFor('ABSTRACTS.Example.org')?->is($organization))->toBeTrue()
        ->and(CustomDomains::organizationFor('abstracts.example.org.')?->is($organization))->toBeTrue()
        ->and(CustomDomains::organizationFor('other.example.org'))->toBeNull()
        ->and(CustomDomains::organizationFor('cass.towardpcc.com'))->toBeNull();
});
