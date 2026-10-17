<?php

declare(strict_types=1);

use App\Actions\Submissions\SendSubmissionStatusLink;
use App\Livewire\Public\SubmissionForm;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Mail::fake();
    // Colours as SubmissionFormTest sets them: the layout reads both.
    $this->organization = Organization::factory()->approved()->create(['primary_color' => '#0F4C8A', 'accent_color' => '#B45309']);
    $this->conference = Conference::factory()->for($this->organization)->published()->create();
    // A fresh form each time, so every save is a new draft that emails its link.
    $this->saveDraft = fn (string $email) => livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->set('title', 'A work in progress')->set('authors.0.email', $email)
        ->call('saveDraft')->assertHasNoErrors()->assertRedirectContains('/s/');
    $this->linksTo = fn (string $email): int => EmailLog::query()->where('to_email', $email)->where('template_key', 'submission_draft_saved')->count();
});

it('emails one address three links a day, saves a fourth draft without one, and emails again a day later', function () {
    foreach (range(1, 3) as $save) {
        ($this->saveDraft)('sara@example.org');
    }
    expect(($this->linksTo)('sara@example.org'))->toBe(3)->and(session('status'))->toBe(__('submission.flash.draft_saved'));

    // 23 hours on, still inside the day: a shorter window would email here.
    $this->travel(23)->hours();
    ($this->saveDraft)('sara@example.org');
    expect(Submission::query()->count())->toBe(4)
        ->and(($this->linksTo)('sara@example.org'))->toBe(3)
        ->and(session('status'))->toBe(__('submission.flash.draft_not_emailed'))
        // Three sends, three hits: the address-cap refusal spent nothing of the shared IP budget.
        ->and(RateLimiter::attempts('status-link:127.0.0.1'))->toBe(3);
    Mail::assertQueued(TemplatedMail::class, 3);

    // A day and a minute after the first three, the address may have one again.
    $this->travel(61)->minutes();
    ($this->saveDraft)('sara@example.org');
    expect(($this->linksTo)('sara@example.org'))->toBe(4)->and(session('status'))->toBe(__('submission.flash.draft_saved'));
});

it('counts a failed link and a submit receipt to the address, and no other template', function () {
    EmailLog::factory()->failed()->create(['to_email' => 'sara@example.org', 'template_key' => 'submission_received']);
    EmailLog::factory()->create(['to_email' => 'sara@example.org', 'template_key' => 'submission_draft_saved']);
    EmailLog::factory()->count(3)->create(['to_email' => 'sara@example.org', 'template_key' => 'reviewer_invitation']);

    ($this->saveDraft)('sara@example.org');
    expect(session('status'))->toBe(__('submission.flash.draft_saved'));
    ($this->saveDraft)('sara@example.org');
    expect(session('status'))->toBe(__('submission.flash.draft_not_emailed'));
    Mail::assertQueued(TemplatedMail::class, 1);
});

it('caps the links one client IP can send for a day, whoever they are addressed to', function () {
    config()->set('cass.status_link.per_ip_per_day', 2);

    ($this->saveDraft)('one@example.org');
    ($this->saveDraft)('two@example.org');
    // 23 hours on, still inside this IP's day: a shorter decay would email here.
    $this->travel(23)->hours();
    ($this->saveDraft)('three@example.org');
    expect(Submission::query()->count())->toBe(3)
        ->and(($this->linksTo)('three@example.org'))->toBe(0)
        ->and(session('status'))->toBe(__('submission.flash.draft_not_emailed'));
    Mail::assertQueued(TemplatedMail::class, 2);

    // Keyed by IP: another client still gets its link while this one is capped.
    expect(app(SendSubmissionStatusLink::class)->handleForAuthor(Submission::query()->latest('id')->firstOrFail(), null, '203.0.113.7'))->not->toBeNull();

    // 24 hours after this IP's first link its count is gone.
    $this->travel(61)->minutes();
    ($this->saveDraft)('four@example.org');
    expect(($this->linksTo)('four@example.org'))->toBe(1);
});

it('keys the address cap on the address alone, across organizations', function () {
    EmailLog::factory()->count(3)->create([
        'organization_id' => $this->organization->id, 'conference_id' => $this->conference->id,
        'to_email' => 'sara@example.org', 'template_key' => 'submission_draft_saved',
    ]);

    // Another organization's form: the cap follows the address, whoever sends to it.
    $other = Organization::factory()->approved()->create(['primary_color' => '#0F4C8A', 'accent_color' => '#B45309']);
    livewire(SubmissionForm::class, ['organization' => $other, 'conference' => Conference::factory()->for($other)->published()->create()])
        ->set('title', 'Elsewhere')->set('authors.0.email', 'sara@example.org')
        ->call('saveDraft')->assertHasNoErrors()->assertRedirectContains('/s/');
    expect(session('status'))->toBe(__('submission.flash.draft_not_emailed'));

    // And only that address: a colleague on the same form still gets a link.
    ($this->saveDraft)('omar@example.org');
    expect(($this->linksTo)('omar@example.org'))->toBe(1)->and(session('status'))->toBe(__('submission.flash.draft_saved'));
    Mail::assertQueued(TemplatedMail::class, 1);
});

it('keys the IP cap on the Cloudflare client address, not the proxy in front of it', function () {
    // Trusted proxies are static state on Request, so this sets its own and puts
    // back what was there, as tests/Feature/Security/RateLimitInventoryTest.php does.
    $proxies = Request::getTrustedProxies();
    $headerSet = Request::getTrustedHeaderSet();
    Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
    // Every request of this test arrives through that proxy, carrying the client's address.
    app()->rebinding('request', fn ($app, Request $request) => $request->headers->set('CF-Connecting-IP', '203.0.113.7'));

    try {
        ($this->saveDraft)('sara@example.org');
    } finally {
        Request::setTrustedProxies($proxies, $headerSet);
    }

    expect(RateLimiter::attempts('status-link:203.0.113.7'))->toBe(1)
        ->and(RateLimiter::attempts('status-link:127.0.0.1'))->toBe(0);
});

it('still says no link was emailed when the first save stopped at a refused file', function () {
    Storage::fake('local');
    EmailLog::factory()->count(3)->create(['to_email' => 'sara@example.org', 'template_key' => 'submission_draft_saved']);
    // A PNG under a .pdf name: the upload gate refuses it after the draft is written.
    $renamedImage = UploadedFile::fake()->createWithContent('abstract.pdf', (string) file_get_contents(base_path('tests/Fixtures/not-really.pdf')));

    $form = livewire(SubmissionForm::class, ['organization' => $this->organization, 'conference' => $this->conference])
        ->set('title', 'A work in progress')->set('authors.0.email', 'sara@example.org')->set('uploads', [$renamedImage])
        ->call('saveDraft')->assertHasErrors(['uploads']);

    // The retry edits the same draft, and the page it lands on says what the first save did.
    $form->set('uploads', [])->call('saveDraft')->assertHasNoErrors()->assertRedirectContains('/s/');
    expect(Submission::query()->count())->toBe(1)
        ->and(session('status'))->toBe(__('submission.flash.draft_not_emailed'));
    Mail::assertNothingQueued();
});
