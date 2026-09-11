<?php

declare(strict_types=1);

use App\Actions\Reviewers\InviteReviewerList;
use App\Enums\EmailTemplateKey;
use App\Enums\OrganizationRole;
use App\Enums\ReviewerStatus;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceReviewers;
use App\Filament\Organizer\Resources\Conferences\Pages\ViewConference;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\ReviewerInvitation;
use App\Models\Submission;
use App\Models\User;
use App\Support\Reviews\ReviewerList;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Mail::fake();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->closed()->create([
        'name' => 'Alpha Annual Meeting',
        'review_deadline' => now()->addMonth(),
    ]);
});

function reviewersPage(Conference $conference): object
{
    return livewire(ConferenceReviewers::class, ['record' => $conference->getRouteKey()]);
}

it('invites one reviewer, queues the templated email and logs it', function () {
    reviewersPage($this->conference)
        ->callAction('invite', data: [
            'name' => 'Dr Omar Khan',
            'email' => 'Omar@Example.ORG',
            'affiliation' => 'KFSH',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $invitation = ReviewerInvitation::query()->firstOrFail();

    expect($invitation->email)->toBe('omar@example.org')
        ->and($invitation->name)->toBe('Dr Omar Khan')
        ->and($invitation->affiliation)->toBe('KFSH')
        ->and($invitation->conference_id)->toBe($this->conference->id)
        ->and($invitation->invited_by)->toBe($this->user->id)
        ->and($invitation->token_hash)->toHaveLength(64);

    Mail::assertQueued(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->hasTo('omar@example.org')
            && $mail->templateKey === EmailTemplateKey::ReviewerInvitation->value
            // {{review_link}} in an invitation is the ACCEPT url, because the
            // reviewer has no account and no queue yet (spec 5.4 step 1).
            && str_contains($mail->body, '/invite/'),
    );

    $log = EmailLog::query()->firstOrFail();
    expect($log->template_key)->toBe('reviewer_invitation')
        ->and($log->conference_id)->toBe($this->conference->id)
        ->and($log->to_email)->toBe('omar@example.org');
});

it('renders the conference and deadline into the invitation', function () {
    reviewersPage($this->conference)->callAction('invite', data: [
        'name' => 'Dr Omar Khan', 'email' => 'omar@example.org', 'affiliation' => null,
    ]);

    Mail::assertQueued(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => str_contains($mail->body, 'Dr Omar Khan')
            && str_contains($mail->body, 'Alpha Annual Meeting')
            && str_contains($mail->body, 'Alpha Society')
            && str_contains($mail->body, $this->conference->reviewDeadlineInConferenceTimezone()->format('j F Y')),
    );
});

it('invites a pasted list, reports the bad lines and skips the duplicates', function () {
    ReviewerInvitation::factory()->for($this->conference)->create(['email' => 'already@example.org']);

    reviewersPage($this->conference)
        ->callAction('inviteList', data: ['list' => implode("\n", [
            'Dr Omar Khan <omar@example.org>',
            'sara@example.org',
            'omar@example.org',
            'rubbish',
            'already@example.org',
        ])])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(ReviewerInvitation::query()->pluck('email')->sort()->values()->all())
        ->toBe(['already@example.org', 'omar@example.org', 'sara@example.org'])
        // The duplicate line and the existing invitation both refresh rather
        // than insert, so three addresses means three rows.
        ->and(ReviewerInvitation::query()->count())->toBe(3);

    Mail::assertQueued(TemplatedMail::class, 3);
});

it('caps one paste at a hundred and says what it did not send', function () {
    // The textarea takes 20,000 characters and InviteReviewerList loops
    // synchronously inside one Livewire POST that php-fpm abandons at 60
    // seconds, so an uncapped paste is a 504 halfway through a half-delivered
    // batch - and, because ReviewerInvitationPolicy::create() admits every
    // organization member, an uncapped bulk-mail primitive besides.
    $text = collect(range(1, 150))->map(fn (int $i): string => "r{$i}@example.org")->implode("\n");

    reviewersPage($this->conference)
        ->callAction('inviteList', data: ['list' => $text])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(ReviewerInvitation::query()->count())->toBe(ReviewerList::MAX_ENTRIES);

    Mail::assertQueued(TemplatedMail::class, ReviewerList::MAX_ENTRIES);
});

it('stops the whole run once the hourly send limit trips, and says so once', function () {
    // Not once per remaining line: the limit is about the run, and forty copies
    // of one sentence in a notification body is not a report.
    $limit = (int) config('cass.invitations.send_rate_limit');
    RateLimiter::clear('invitation-send:'.$this->user->getKey());

    // A live invitation sent before the limit tripped, whose address is
    // deliberately the first line of the paste below. InviteReviewer re-invites
    // by refreshing this very row - new token_hash, new expires_at - so if the
    // limiter were consulted AFTER that write, this refused run would silently
    // kill a link the reviewer was emailed an hour ago, and would leave phantom
    // "Invited" rows for b@ and c@ that no mail ever matched. Reading the
    // persisted values before and after, rather than comparing to the in-memory
    // model, keeps the assertion honest about sub-second precision.
    $live = ReviewerInvitation::factory()->for($this->conference)->create([
        'email' => 'a@example.org',
        'name' => 'Dr Aya Nasser',
    ]);
    $hashBefore = (string) $live->token_hash;
    $expiresBefore = (string) $live->fresh()?->expires_at?->toDateTimeString();

    foreach (range(1, $limit) as $ignored) {
        RateLimiter::hit('invitation-send:'.$this->user->getKey(), 3600);
    }

    $result = app(InviteReviewerList::class)->handle(
        $this->conference,
        "a@example.org\nb@example.org\nc@example.org",
        $this->user,
    );

    expect($result['invited'])->toBe(0)
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0])->toBe(__('reviewer.errors.send_limit'))
        ->and(ReviewerInvitation::query()->count())->toBe(1)
        ->and((string) $live->fresh()?->token_hash)->toBe($hashBefore)
        ->and((string) $live->fresh()?->expires_at?->toDateTimeString())->toBe($expiresBefore);

    Mail::assertNothingQueued();
});

it('re-invites by refreshing the live row instead of creating a second link', function () {
    reviewersPage($this->conference)->callAction('invite', data: ['name' => 'Dr Omar Khan', 'email' => 'omar@example.org', 'affiliation' => null]);
    $first = ReviewerInvitation::query()->firstOrFail();

    reviewersPage($this->conference->fresh())
        ->callTableAction('resend', 'invitation:'.$first->getKey())
        ->assertHasNoTableActionErrors();

    expect(ReviewerInvitation::query()->count())->toBe(1)
        ->and($first->fresh()?->token_hash)->not->toBe($first->token_hash);

    Mail::assertQueued(TemplatedMail::class, 2);
});

it('withdraws an invitation and drops it off the list', function () {
    $invitation = ReviewerInvitation::factory()->for($this->conference)->create(['email' => 'omar@example.org']);

    reviewersPage($this->conference)
        ->callTableAction('revoke', 'invitation:'.$invitation->getKey())
        ->assertHasNoTableActionErrors();

    expect($invitation->fresh()?->revoked_at)->not->toBeNull();

    reviewersPage($this->conference->fresh())->assertDontSee('omar@example.org');
});

it('lists accepted reviewers with their state', function () {
    $reviewer = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['name' => 'Dr Omar Khan', 'email' => 'omar@example.org'])->id,
        'affiliation' => 'KFSH',
    ]);

    reviewersPage($this->conference)
        ->assertSee('Dr Omar Khan')
        ->assertSee('omar@example.org')
        ->assertSee('KFSH')
        ->assertSee(ReviewerStatus::Active->getLabel())
        ->assertTableActionVisible('remove', 'reviewer:'.$reviewer->getKey());
});

it('removes a reviewer, deletes their assignments, keeps their reviews and logs it', function () {
    $user = User::factory()->create();
    $reviewer = ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $user->id]);
    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    ReviewAssignment::factory()->for($submission)->create(['reviewer_user_id' => $user->id]);
    $review = Review::factory()->for($submission)->submitted()->create(['reviewer_user_id' => $user->id]);

    reviewersPage($this->conference)
        ->callTableAction('remove', 'reviewer:'.$reviewer->getKey())
        ->assertHasNoTableActionErrors();

    expect($reviewer->fresh()?->status)->toBe(ReviewerStatus::Removed)
        ->and($reviewer->fresh()?->removed_at)->not->toBeNull()
        ->and($user->fresh()?->isActiveReviewer($this->conference))->toBeFalse()
        // The assignment goes: leaving it would make the coverage summary say
        // this abstract has a reviewer when it does not.
        ->and(ReviewAssignment::query()->count())->toBe(0)
        // The review stays: it is the record of what the committee was told.
        ->and(Review::query()->whereKey($review->getKey())->exists())->toBeTrue()
        ->and(Activity::query()->where('description', 'reviewer.removed')->count())->toBe(1);
});

it('invites a removed reviewer again in one click', function () {
    $user = User::factory()->create(['email' => 'omar@example.org', 'name' => 'Dr Omar Khan']);
    $reviewer = ConferenceReviewer::factory()->for($this->conference)->removed()->create(['user_id' => $user->id]);

    reviewersPage($this->conference)
        ->callTableAction('reinvite', 'reviewer:'.$reviewer->getKey())
        ->assertHasNoTableActionErrors();

    expect(ReviewerInvitation::query()->where('email', 'omar@example.org')->count())->toBe(1);

    Mail::assertQueued(TemplatedMail::class);
});

it('refuses to invite somebody who is already an active reviewer', function () {
    $user = User::factory()->create(['email' => 'omar@example.org']);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $user->id]);

    reviewersPage($this->conference)
        ->callAction('invite', data: ['name' => null, 'email' => 'omar@example.org', 'affiliation' => null])
        ->assertNotified();

    expect(ReviewerInvitation::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('is reachable from the conference view and from the generated url', function () {
    get(ConferenceResource::getUrl('reviewers', ['record' => $this->conference]))->assertOk();

    livewire(ViewConference::class, [
        'record' => $this->conference->getRouteKey(),
    ])->assertActionVisible('reviewers');
});

it('does not open the reviewers page of another organization conference', function () {
    $theirs = withoutTenant(fn (): Conference => Conference::factory()->closed()->create());

    // Through the route, not livewire(). Filament's InteractsWithRecord
    // ::resolveRecord() throws ModelNotFoundException when the tenant-scoped
    // query excludes the record (vendor/filament/filament/src/Resources/Pages/
    // Concerns/InteractsWithRecord.php:42-44), and Livewire's test harness
    // rethrows everything except HttpException and AuthorizationException
    // (vendor/livewire/livewire/src/Features/SupportTesting/RequestBroker.php:29),
    // so livewire(...)->assertNotFound() would ERROR rather than assert. Only a
    // real request turns it into the 404. This repo already records the same
    // trap in tests/Feature/Organizer/ConferenceEmailTemplatesTest.php:188-193.
    get(ConferenceResource::getUrl('reviewers', ['record' => $theirs]))->assertNotFound();
});

it('refuses every reviewer action to somebody with no role in this organization', function () {
    $invitation = ReviewerInvitation::factory()->for($this->conference)->create();
    $outsider = User::factory()->create();
    actingAs($outsider);

    expect($outsider->can('create', ReviewerInvitation::class))->toBeFalse()
        ->and($outsider->can('update', $invitation))->toBeFalse()
        ->and($outsider->can('delete', $invitation))->toBeFalse();
});
