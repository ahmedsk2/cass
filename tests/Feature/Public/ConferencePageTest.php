<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create([
        'name' => 'Gulf Pediatric Society',
        'primary_color' => '#0F4C8A',
        'accent_color' => '#B45309',
    ]);
});

function conferenceUrl(Conference $conference): string
{
    return "/c/{$conference->organization->slug}/{$conference->slug}";
}

it('shows an open conference with its call, dates, tracks, terms and branding', function () {
    $conference = Conference::factory()->for($this->organization)->published()->create([
        'name' => 'Annual Pediatric Critical Care Meeting',
        'short_description' => 'Abstracts on paediatric intensive care are invited.',
        // Everything RichText exists to remove, in one string: a script, an
        // inline style, a utility class the app itself compiles, an event
        // handler and a third-party media element. The blockquote is there to
        // prove the sanitiser keeps the parts of the editor's schema that the
        // toolbar does not show but a paste can produce.
        'description' => '<p style="position:fixed;inset:0" class="fixed inset-0">Full call for abstracts.</p>'
            .'<blockquote>Abstracts must be in English.</blockquote>'
            .'<video src="https://tracker.example/v.mp4" poster="https://tracker.example/p.gif"></video>'
            .'<img src="x" onerror="alert(2)"><script>alert(1)</script>',
        'venue' => 'King Fahad Convention Centre',
        'city' => 'Riyadh',
        'terms' => 'Presenting authors must register.',
        'word_limit' => 400,
    ]);
    Track::factory()->for($conference)->create(['name' => 'Neurocritical care']);

    get(conferenceUrl($conference))
        ->assertOk()
        ->assertSee('Annual Pediatric Critical Care Meeting')
        ->assertSee('Abstracts on paediatric intensive care are invited.')
        ->assertSee('Full call for abstracts.', escape: false)
        ->assertSee('Abstracts must be in English.')
        ->assertDontSee('alert(1)', escape: false)
        ->assertDontSee('onerror', escape: false)
        ->assertDontSee('position:fixed', escape: false)
        ->assertDontSee('class="fixed inset-0"', escape: false)
        ->assertDontSee('tracker.example', escape: false)
        ->assertSee('King Fahad Convention Centre')
        ->assertSee('Neurocritical care')
        ->assertSee('Presenting authors must register.')
        ->assertSee('400 words')
        ->assertSee('--org-primary:#0F4C8A', escape: false)
        ->assertSee('--org-on-primary:#FFFFFF', escape: false)
        ->assertSee('Gulf Pediatric Society');
});

it('renders the public page with a bounded number of queries', function () {
    // Spec section 10 gives this page a 300 ms server budget, which is why it
    // is plain Blade. Bound the query count so a lazily loaded relation in the
    // layout, or an N+1 over tracks, fails here rather than in production.
    $conference = Conference::factory()->for($this->organization)->published()->create();
    Track::factory()->count(5)->for($conference)->create();
    $url = conferenceUrl($conference);

    DB::enableQueryLog();
    get($url)->assertOk();

    // organization binding, scoped conference binding, tracks.
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(4);
});

it('shows a countdown target for an open window', function () {
    $conference = Conference::factory()->for($this->organization)->published()->create();

    get(conferenceUrl($conference))
        ->assertOk()
        ->assertSee('data-countdown', escape: false)
        ->assertSee($conference->submission_deadline->toIso8601String(), escape: false)
        ->assertSee('Submit abstract');
});

it('says when submissions open instead of offering the form', function () {
    $conference = Conference::factory()->for($this->organization)->upcomingWindow()->published()->create([
        'submission_opens_at' => now()->addWeek(),
        'submission_deadline' => now()->addMonths(2),
    ]);

    get(conferenceUrl($conference))
        ->assertOk()
        ->assertSee('Submissions open on')
        ->assertDontSee('Submit abstract');
});

it('says submissions are closed for a closed conference', function () {
    $conference = Conference::factory()->for($this->organization)->closed()->create();

    get(conferenceUrl($conference))
        ->assertOk()
        ->assertSee('Submissions are closed')
        ->assertDontSee('Submit abstract');
});

// Every negative case below pairs its 404 with a request that must succeed on
// the same URL pattern. Without that control they all pass before Step 8
// registers the route, so they could never be shown failing.

it('hides a draft conference from the public until it is published', function () {
    $conference = Conference::factory()->for($this->organization)->create();

    get(conferenceUrl($conference))->assertNotFound();

    $conference->forceFill(['status' => ConferenceStatus::Open])->save();
    get(conferenceUrl($conference))->assertOk();
});

it('hides an archived conference from the public', function () {
    $conference = Conference::factory()->for($this->organization)->published()->create();

    get(conferenceUrl($conference))->assertOk();

    $conference->forceFill(['status' => ConferenceStatus::Archived])->save();
    get(conferenceUrl($conference))->assertNotFound();
});

it('lets an organization member preview a draft with a banner', function () {
    $conference = Conference::factory()->for($this->organization)->create();
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);

    actingAs($member)->get(conferenceUrl($conference))
        ->assertOk()
        ->assertSee('Preview')
        ->assertSee('not visible to the public');
});

it('does not let a member of another organization preview a draft', function () {
    $conference = Conference::factory()->for($this->organization)->create();
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    $outsider = User::factory()->create();
    Organization::factory()->approved()->create()->addMember($outsider, OrganizationRole::Owner);

    actingAs($member)->get(conferenceUrl($conference))->assertOk();
    actingAs($outsider)->get(conferenceUrl($conference))->assertNotFound();
});

it('does not let an unverified member preview a draft', function () {
    // The organizer panel runs Filament's EnsureEmailIsVerified on every tenant
    // route (spec section 9), and RegisterOrganization logs a new owner in
    // before they verify, so this preview must apply the same rule.
    $conference = Conference::factory()->for($this->organization)->create();
    $verified = User::factory()->create();
    $unverified = User::factory()->unverified()->create();
    $this->organization->addMember($verified, OrganizationRole::Member);
    $this->organization->addMember($unverified, OrganizationRole::Member);

    actingAs($verified)->get(conferenceUrl($conference))->assertOk();
    actingAs($unverified)->get(conferenceUrl($conference))->assertNotFound();
});

it('hides published conferences of an organization that is not approved', function () {
    $pending = Organization::factory()->create();
    $conference = Conference::factory()->for($pending)->published()->create();

    get(conferenceUrl($conference))->assertNotFound();

    $pending->forceFill(['status' => OrganizationStatus::Approved])->save();
    get(conferenceUrl($conference))->assertOk();
});

it('takes a suspended organization conference offline but keeps the member preview', function () {
    // Owner decision: suspension takes every public conference page offline,
    // because the suspension email already promises exactly that. Members keep
    // the preview so they can see what the public no longer can.
    $suspended = Organization::factory()->suspended()->create();
    $conference = Conference::factory()->for($suspended)->published()->create();
    $member = User::factory()->create();
    $suspended->addMember($member, OrganizationRole::Member);

    get(conferenceUrl($conference))->assertNotFound();

    actingAs($member)->get(conferenceUrl($conference))
        ->assertOk()
        ->assertSee('not visible to the public');
});

it('does not leak a conference through another organization slug', function () {
    $conference = Conference::factory()->for($this->organization)->published()->create();
    $other = Organization::factory()->approved()->create();

    get(conferenceUrl($conference))->assertOk();
    get("/c/{$other->slug}/{$conference->slug}")->assertNotFound();
});

it('keeps the organization contact address off the public page by default', function () {
    // contact_email is the address the platform uses to reach an organization,
    // so for one that never edits its profile it is whatever the owner
    // registered with - a personal address that is also their login. It reaches
    // the public page only when the organizer explicitly opts in.
    $this->organization->forceFill([
        'contact_email' => 'sara@personal.example.org',
        'publish_contact_email' => false,
    ])->save();
    $conference = Conference::factory()->for($this->organization)->published()->create();

    get(conferenceUrl($conference))
        ->assertOk()
        ->assertDontSee('sara@personal.example.org')
        ->assertDontSee('mailto:', escape: false)
        ->assertSee(route('contact'), escape: false);
});

it('publishes the contact address once the organization opts in', function () {
    $this->organization->forceFill([
        'contact_email' => 'abstracts@gps.example.org',
        'publish_contact_email' => true,
    ])->save();
    $conference = Conference::factory()->for($this->organization)->published()->create();

    get(conferenceUrl($conference))
        ->assertOk()
        ->assertSee('mailto:abstracts@gps.example.org', escape: false)
        ->assertSee('Contact the organizers');
});
