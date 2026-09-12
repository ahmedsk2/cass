<?php

declare(strict_types=1);

use App\Actions\Decisions\ApplyDecision;
use App\Actions\Decisions\SendDecisionEmails;
use App\Actions\Mail\SaveEmailTemplate;
use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\EmailTemplateKey;
use App\Enums\OrganizationRole;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceRanking;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;
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
        'status' => ConferenceStatus::Reviewing,
    ]);

    $this->accepted = Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral)
        ->withCorrespondingAuthor('sara@example.org', 'Dr Sara Al-Harbi')
        ->create(['title' => 'Early mobilisation after cardiac surgery']);
    $this->accepted->forceFill(['reference' => 'AAM26-001'])->save();

    $this->rejected = Submission::factory()->for($this->conference)->decided(Decision::Rejected)
        ->withCorrespondingAuthor('omar@example.org', 'Dr Omar Khan')
        ->create(['title' => 'A second abstract']);
    $this->rejected->forceFill(['reference' => 'AAM26-002'])->save();

    $this->undecided = Submission::factory()->for($this->conference)->submitted()
        ->withCorrespondingAuthor('nobody@example.org', 'Dr Nobody')
        ->create(['title' => 'Still waiting']);
    $this->undecided->forceFill(['reference' => 'AAM26-003'])->save();
});

it('sends one letter per decided abstract, using the matching template', function () {
    $report = app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    expect($report['sent'])->toBe(2)
        ->and($report['skipped'])->toBe([]);

    Mail::assertQueued(TemplatedMail::class, 2);

    // The right key per decision, which is the whole point of
    // Decision::templateKey().
    expect(EmailLog::query()->pluck('template_key')->sort()->values()->all())
        ->toBe([EmailTemplateKey::DecisionAcceptedOral->value, EmailTemplateKey::DecisionRejected->value])
        ->and(EmailLog::query()->pluck('to_email')->sort()->values()->all())
        ->toBe(['omar@example.org', 'sara@example.org']);

    // Nothing went to the abstract with no decision.
    Mail::assertNotQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('nobody@example.org'));
});

it('fills every placeholder the key declares, and links to a working status page', function () {
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    $decision = $this->accepted->fresh()?->currentDecision();

    expect($decision?->letter_markdown)->toBeString()
        ->toContain('Dr Sara Al-Harbi')
        ->toContain('Early mobilisation after cardiac surgery')
        ->toContain('AAM26-001')
        ->toContain('Alpha Annual Meeting')
        ->toContain(Decision::AcceptedOral->getLabel())
        // Every placeholder resolved except the one that is withheld on
        // purpose. RenderEmailTemplate leaves an unknown placeholder literal,
        // so a missing value shows up as `{{...}}` here rather than as a hole
        // in an author's letter.
        ->not->toContain('{{author_name}}')
        ->not->toContain('{{reference}}')
        ->not->toContain('{{conference}}')
        ->not->toContain('{{decision}}');

    // The STORED letter must not be a copy of the author's credential: it is
    // kept for ever, and the token in it is live until the next send. Task 8
    // fills the link in from the token the reader already has in their URL.
    expect((string) $decision?->letter_markdown)
        ->toContain('{{status_link}}')
        ->not->toMatch('#/s/[A-Za-z0-9]{64}#');

    // The link the AUTHOR received really opens the status page - which is the
    // whole reason the token is rotated when the letter is sent. The plaintext
    // now exists in exactly one place, the queued message, which is the point.
    $plain = null;

    Mail::assertQueued(TemplatedMail::class, function (TemplatedMail $mail) use (&$plain): bool {
        if (preg_match('#/s/([A-Za-z0-9]{64})#', $mail->body, $matches) === 1) {
            $plain = $matches[1];
        }

        return true;
    });

    expect($plain)->toBeString();

    $this->get('/s/'.$plain)->assertOk();
});

it('rotates the status token, which kills the older link', function () {
    $before = $this->accepted->access_token_hash;

    app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    // Spec section 9 stores the token hashed, so there is no way to put a
    // working link in a second email except to mint a new one - the same rule
    // Plan 3's "Resend status link" follows. Recorded in the runbook and as an
    // owner question.
    expect($this->accepted->fresh()?->access_token_hash)->not->toBe($before);
});

it('stamps the submission and the decision row, and is safe to run again', function () {
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    $submission = $this->accepted->fresh();

    expect($submission?->decision_notified_at)->not->toBeNull()
        ->and($submission?->currentDecision()?->notified_at)->not->toBeNull()
        ->and($submission?->currentDecision()?->letter_subject)->toBeString()->not->toBe('');

    $report = app(SendDecisionEmails::class)->handle($this->conference->fresh() ?? $this->conference, $this->user);

    expect($report['sent'])->toBe(0);
    Mail::assertQueued(TemplatedMail::class, 2);
});

it('sends each letter once even when two runs overlap on the same rows', function () {
    // The double-click / two-organizers case. The models are NOT refreshed
    // between the runs, so the second run sees exactly the state the first one
    // started from - which is what a second overlapping request sees too. The
    // conditional-UPDATE claim in SendOneDecisionEmail is the only thing that
    // makes this two letters and not four; without it both runs would mint a
    // token (IssueSubmissionToken REPLACES the hash) and the link in the first
    // pair of letters would already be dead.
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    Mail::assertQueued(TemplatedMail::class, 2);

    expect(EmailLog::query()->count())->toBe(2)
        ->and($this->accepted->fresh()?->decisions()->count())->toBe(1);
});

it('writes one audit entry per letter, naming who sent it', function () {
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    // Spec section 9: the decision trail is auditable, and sending is the step
    // that rotates an author's access token. The run has its own entry; each
    // letter has one too, so "who re-sent this letter and rotated this author's
    // link" has an answer.
    expect(Activity::query()->where('description', 'submission.decision_letter_sent')->count())->toBe(2)
        ->and(Activity::query()->where('description', 'conference.decisions_sent')->count())->toBe(1);

    $entry = Activity::query()
        ->where('description', 'submission.decision_letter_sent')
        ->latest('id')
        ->first();

    expect($entry?->causer_id)->toBe($this->user->id)
        // getProperty(), not getExtraProperty(): spatie/laravel-activitylog 5.1
        // renamed it (vendor/spatie/laravel-activitylog/src/Models/Activity.php:66).
        ->and($entry?->getProperty('token_rotated'))->toBeTrue()
        // Never the address and never the token: this log is readable by the
        // platform admin.
        ->and(json_encode($entry?->properties))->not->toContain('sara@example.org');
});

it('sends nothing once the conference is off the public site, and leaves the tokens alone', function () {
    $before = $this->accepted->access_token_hash;
    $this->conference->forceFill(['status' => ConferenceStatus::Archived])->save();

    // An archived conference's status page is a 404
    // (app/Livewire/Public/SubmissionStatus.php:65-74), so a letter carrying a
    // fresh {{status_link}} into it is a promise nothing keeps - and minting
    // that link would kill the one the author already has. The ranking page
    // stays visible for Archived on purpose; sending from it does not.
    $report = app(SendDecisionEmails::class)->handle($this->conference->fresh() ?? $this->conference, $this->user);

    expect($report['sent'])->toBe(0)
        ->and(array_keys($report['skipped']))->toBe(['AAM26-001', 'AAM26-002'])
        ->and($this->accepted->fresh()?->access_token_hash)->toBe($before)
        ->and($this->accepted->fresh()?->decision_notified_at)->toBeNull();

    Mail::assertNothingQueued();
});

it('queues a second letter after a decision is changed and resent', function () {
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);
    Mail::assertQueued(TemplatedMail::class, 2);

    // The change-after-send rule, end to end: ApplyDecision (Task 6) puts the
    // row back in the queue by nulling decision_notified_at, and the next click
    // picks it up because pending() filters on that column. Two tasks own the
    // two halves, so only this case proves the join - without it an
    // implementer who forgot the null-out, or who filtered pending() on the
    // decision ROW instead of the column, would ship a corrected decision that
    // is never re-sent and nothing would go red.
    app(ApplyDecision::class)->handle(
        $this->accepted->fresh() ?? $this->accepted,
        Decision::Rejected,
        $this->user,
        'The programme was rebuilt.',
        changeAfterSend: true,
    );

    $report = app(SendDecisionEmails::class)->handle($this->conference->fresh() ?? $this->conference, $this->user);

    expect($report['sent'])->toBe(1);
    Mail::assertQueued(TemplatedMail::class, 3);

    $submission = $this->accepted->fresh();

    expect($submission?->decisions()->count())->toBe(2)
        ->and($submission?->currentDecision()?->decision)->toBe(Decision::Rejected)
        ->and($submission?->currentDecision()?->letter_markdown)->toBeString()
        // decisions() is newest first, so last() is the superseded row - it
        // keeps the letter it announced, which is the audit.
        ->and($submission?->decisions()->get()->last()?->letter_markdown)->toBeString()
        ->and(EmailLog::query()->where('template_key', EmailTemplateKey::DecisionRejected->value)->count())->toBe(2);
});

it('uses the conference override when the organizer has written one', function () {
    app(SaveEmailTemplate::class)->handle(
        $this->conference,
        EmailTemplateKey::DecisionAcceptedOral,
        'Your abstract {{reference}} at {{conference}}',
        "Dear {{author_name}},\n\nThe committee's answer is **{{decision}}**.\n\n[Your abstract]({{status_link}})",
    );

    app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    $decision = $this->accepted->fresh()?->currentDecision();

    expect($decision?->letter_markdown)->toContain("The committee's answer is")
        ->and($decision?->letter_subject)->toBe('Your abstract AAM26-001 at Alpha Annual Meeting');
});

it('keeps the letter that was sent even after the template is rewritten', function () {
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);
    $sent = $this->accepted->fresh()?->currentDecision()?->letter_markdown;

    app(SaveEmailTemplate::class)->handle(
        $this->conference,
        EmailTemplateKey::DecisionAcceptedOral,
        'Completely different subject',
        'Completely different body for {{author_name}}.',
    );

    // The whole reason the Markdown is stored rather than re-rendered on read.
    expect($this->accepted->fresh()?->currentDecision()?->letter_markdown)->toBe($sent);
});

it('skips an abstract with no usable author address and leaves it in the queue', function () {
    SubmissionAuthor::query()->where('submission_id', $this->rejected->id)->update(['email' => '']);

    $report = app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    expect($report['sent'])->toBe(1)
        ->and(array_keys($report['skipped']))->toBe(['AAM26-002'])
        // Null, not stamped: fix the address and click again.
        ->and($this->rejected->fresh()?->decision_notified_at)->toBeNull();
});

it('stops at the chunk size and says how many are left', function () {
    config()->set('cass.decisions.send_chunk', 1);

    $report = app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    expect($report['sent'])->toBe(1)
        ->and($report['remaining'])->toBe(1);

    Mail::assertQueued(TemplatedMail::class, 1);
});

// --- The panel ----------------------------------------------------------

it('sends every letter from the ranking page behind a typed confirmation', function () {
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('sendDecisionEmails', data: ['confirm' => __('decisions.send.confirm_word')])
        ->assertHasNoTableActionErrors();

    Mail::assertQueued(TemplatedMail::class, 2);
});

it('refuses the send when the confirmation word is wrong', function () {
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('sendDecisionEmails', data: ['confirm' => 'yes please'])
        ->assertHasTableActionErrors(['confirm']);

    Mail::assertNothingQueued();
});

it('resends one letter from its own row', function () {
    app(SendDecisionEmails::class)->handle($this->conference, $this->user);
    $firstLetter = $this->accepted->fresh()?->currentDecision()?->notified_at;

    $this->travel(1)->minutes();

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('resendDecision', $this->accepted->fresh())
        ->assertHasNoTableActionErrors();

    Mail::assertQueued(TemplatedMail::class, 3);

    // A resend does not append to the history - the decision has not changed -
    // it overwrites the letter on the row that is already current, because the
    // author is now holding the newer email.
    expect($this->accepted->fresh()?->decisions()->count())->toBe(1)
        ->and($this->accepted->fresh()?->currentDecision()?->notified_at?->greaterThan($firstLetter))->toBeTrue();
});

it('offers the send only to an owner or an admin', function () {
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    actingAs($member);
    bootOrganizerPanel($this->organization);

    // Spec section 4 lets a plain member decide; sending a letter to every
    // author in the conference is narrowed to owner/admin and recorded as an
    // owner question.
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->assertTableActionHidden('sendDecisionEmails')
        ->assertTableActionVisible('decide', $this->undecided);
});

it('sends nothing for another organization conference', function () {
    $theirs = withoutTenant(function (): Conference {
        $conference = Conference::factory()->create(['status' => ConferenceStatus::Reviewing]);
        $submission = Submission::factory()->for($conference)->decided(Decision::AcceptedOral)
            ->withCorrespondingAuthor('stranger@example.org')->create();
        $submission->forceFill(['reference' => 'XXX26-001'])->save();

        return $conference;
    });

    app(SendDecisionEmails::class)->handle($this->conference, $this->user);

    Mail::assertNotQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('stranger@example.org'));
    expect($theirs->submissions()->first()?->decision_notified_at)->toBeNull();
});
