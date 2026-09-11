<?php

declare(strict_types=1);

use App\Models\Conference;
use App\Models\Organization;
use App\Models\ShortLink;
use App\Models\ShortLinkVisit;

use function Pest\Laravel\get;
use function Pest\Laravel\withServerVariables;

// Every redirect assertion here also pins the status to 302. assertRedirect()
// accepts any 3xx, and a 301 is cached by browsers and by Cloudflare: the
// second scan of a poster would never reach the app again and scan counting
// would quietly stop.
it('redirects to the conference page and counts the scan', function () {
    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->published()->create();
    $link = ShortLink::forTarget($conference);

    get('/q/'.$link->code)
        ->assertRedirect($conference->publicUrl())->assertStatus(302);

    $link->refresh();
    expect($link->clicks)->toBe(1)
        ->and(ShortLinkVisit::count())->toBe(1)
        ->and(ShortLinkVisit::first()?->visited_at)->not->toBeNull();
});

it('records nothing beyond a timestamp', function () {
    $conference = Conference::factory()->for(Organization::factory()->approved())->published()->create();
    $link = ShortLink::forTarget($conference);

    get('/q/'.$link->code);

    $columns = array_keys(ShortLinkVisit::first()?->getAttributes() ?? []);
    sort($columns);

    expect($columns)->toBe(['id', 'short_link_id', 'visited_at']);
});

it('accepts a lower case code from a hand-typed url', function () {
    $conference = Conference::factory()->for(Organization::factory()->approved())->published()->create();
    $link = ShortLink::forTarget($conference);

    get('/q/'.strtolower($link->code))->assertRedirect($conference->publicUrl())->assertStatus(302);
});

it('returns 404 for an unknown code without creating a visit', function () {
    get('/q/ZZZZZZZZ')->assertNotFound();

    expect(ShortLinkVisit::count())->toBe(0);
});

it('does not redirect to or count a conference the public cannot see', function (Conference $conference) {
    $link = ShortLink::forTarget($conference);

    get('/q/'.$link->code)->assertNotFound();

    expect($link->fresh()?->clicks)->toBe(0)
        ->and(ShortLinkVisit::count())->toBe(0);
})->with([
    // One row per branch of the controller's guard. Without the archived and
    // organization-status rows, dropping the isApproved() clause would keep
    // counting scans for a page that is 404 and nothing would fail.
    'draft' => fn () => Conference::factory()->for(Organization::factory()->approved())->create(),
    'archived' => fn () => Conference::factory()->for(Organization::factory()->approved())->archived()->create(),
    'pending organization' => fn () => Conference::factory()->for(Organization::factory())->published()->create(),
    'suspended organization' => fn () => Conference::factory()->for(Organization::factory()->suspended())->published()->create(),
]);

it('does not redirect to a soft-deleted conference', function () {
    $conference = Conference::factory()->for(Organization::factory()->approved())->published()->create();
    $link = ShortLink::forTarget($conference);
    $conference->delete();

    get('/q/'.$link->code)->assertNotFound();

    expect($link->fresh()?->clicks)->toBe(0)
        ->and(ShortLinkVisit::count())->toBe(0);
});

it('caps counting per client ip but never refuses the redirect', function () {
    // Spec 5.7: every scan redirects and every visit is counted. A poster in a
    // lecture hall is scanned by a hundred people behind one NAT address, so
    // the cap may only stop *counting*, never answer 429 to a real visitor.
    //
    // REMOTE_ADDR is inside TRUSTED_PROXIES here because App\Support\ClientIp
    // only reads CF-Connecting-IP from a request that came through the proxy
    // tier - which is what production looks like behind Cloudflare.
    config(['cass.short_link_rate_limit' => 2]);

    $conference = Conference::factory()->for(Organization::factory()->approved())->published()->create();
    $link = ShortLink::forTarget($conference);

    foreach (range(1, 3) as $ignored) {
        withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->get('/q/'.$link->code, ['CF-Connecting-IP' => '203.0.113.10'])
            ->assertRedirect($conference->publicUrl())->assertStatus(302);
    }

    // A different client has its own budget, so one scanner cannot spend the
    // whole venue's.
    withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->get('/q/'.$link->code, ['CF-Connecting-IP' => '198.51.100.20'])
        ->assertRedirect($conference->publicUrl())->assertStatus(302);

    expect($link->fresh()?->clicks)->toBe(3)
        ->and(ShortLinkVisit::count())->toBe(3);
});

it('gives a direct caller no extra budget however it forges CF-Connecting-IP', function () {
    // The header is only trusted from the proxy tier, so a peer talking to the
    // origin directly spends one budget - its own address - whatever it claims.
    config(['cass.short_link_rate_limit' => 2, 'cass.short_link_rate_limit_per_link' => 60]);

    $conference = Conference::factory()->for(Organization::factory()->approved())->published()->create();
    $link = ShortLink::forTarget($conference);

    foreach (range(1, 5) as $index) {
        withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
            ->get('/q/'.$link->code, ['CF-Connecting-IP' => '198.51.100.'.$index])
            ->assertRedirect($conference->publicUrl())->assertStatus(302);
    }

    expect($link->fresh()?->clicks)->toBe(2)
        ->and(ShortLinkVisit::count())->toBe(2);
});

it('caps counting per link so spoofed addresses cannot grow the visit table', function () {
    // CF-Connecting-IP is supplied by the client, so the per-IP budget alone
    // can be minted without limit: one host would otherwise write a row per
    // request forever, and the sharing page has to read that window back.
    config(['cass.short_link_rate_limit' => 60, 'cass.short_link_rate_limit_per_link' => 3]);

    $conference = Conference::factory()->for(Organization::factory()->approved())->published()->create();
    $link = ShortLink::forTarget($conference);

    foreach (range(1, 5) as $index) {
        withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->get('/q/'.$link->code, ['CF-Connecting-IP' => '203.0.113.'.$index])
            ->assertRedirect($conference->publicUrl())->assertStatus(302);
    }

    expect($link->fresh()?->clicks)->toBe(3)
        ->and(ShortLinkVisit::count())->toBe(3);
});
