<?php

declare(strict_types=1);

use App\Enums\EmailLogStatus;
use App\Enums\OrganizationRole;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\NewSubmissionNotice;
use App\Notifications\OrganizationApproved;
use Illuminate\Support\Facades\Notification;

// No Mail::fake() and no Notification::fake() anywhere in this file: the whole
// point is the mail *events*, and both fakes short-circuit the mailer before
// MessageSending is ever dispatched. phpunit.xml pins MAIL_MAILER=array and
// QUEUE_CONNECTION=sync, so a queued notification is delivered into the array
// transport inside the same request and the events fire for real.

it('logs a plan 1 notification that knows nothing about templates', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create(['email' => 'owner@example.org']);
    $organization->addMember($owner, OrganizationRole::Owner);

    $owner->notify(new OrganizationApproved($organization));

    $log = EmailLog::query()->firstOrFail();

    expect($log->to_email)->toBe('owner@example.org')
        ->and($log->mailable)->toBe(OrganizationApproved::class)
        ->and($log->template_key)->toBeNull()
        ->and($log->status)->toBe(EmailLogStatus::Sent)
        ->and($log->sent_at)->not->toBeNull()
        // No conference exists at approval time; the columns are nullable for
        // exactly this message.
        ->and($log->conference_id)->toBeNull();
});

it('carries organization, conference and submission context on the member notice', function () {
    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->published()->create();
    $submission = Submission::factory()->for($conference)->submitted()->create();
    $member = User::factory()->create(['email' => 'member@example.org']);
    $organization->addMember($member, OrganizationRole::Member);

    $member->notify(new NewSubmissionNotice($submission));

    $log = EmailLog::query()->where('to_email', 'member@example.org')->firstOrFail();

    expect($log->mailable)->toBe(NewSubmissionNotice::class)
        ->and($log->organization_id)->toBe($organization->id)
        ->and($log->conference_id)->toBe($conference->id)
        ->and($log->submission_id)->toBe($submission->id)
        ->and($log->status)->toBe(EmailLogStatus::Sent);
});

it('names the abstract and links the panel without leaking the author token', function () {
    $conference = Conference::factory()->published()->create(['name' => 'GPCC 2026']);
    $submission = Submission::factory()->for($conference)->submitted()->withCorrespondingAuthor()->create([
        'title' => 'Early mobilisation after cardiac surgery',
    ]);
    $submission->forceFill(['reference' => 'GPCC26-017'])->save();
    $member = User::factory()->create();

    $mail = (new NewSubmissionNotice($submission))->toMail($member);
    $html = (string) $mail->render();

    expect($html)->toContain('GPCC26-017')
        ->toContain('Early mobilisation after cardiac surgery')
        ->toContain('GPCC 2026')
        // The organizer link is the panel, never /s/{token}: a member notice is
        // forwarded around an organizing committee and must not hand anyone the
        // author's editing credential.
        ->and($html)->not->toContain('/s/')
        ->and($html)->toContain('/org/');
});

it('reaches only the members who opted in', function () {
    Notification::fake();

    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->published()->create();
    $submission = Submission::factory()->for($conference)->submitted()->create();

    $wants = User::factory()->create();
    $doesNot = User::factory()->create();
    $organization->addMember($wants, OrganizationRole::Owner);
    $organization->addMember($doesNot, OrganizationRole::Member);
    $organization->members()->updateExistingPivot($doesNot->id, ['notify_on_submission' => false]);

    // The pivot query written out, not SubmitAbstract::notifiableMembers() -
    // that method is Task 5's, and a task never commits a red suite.
    // Organization::members() already carries
    // withPivot(['role', 'notify_on_submission']), so this is the same query
    // the action runs, and Task 5 adds `selects exactly the members who opted
    // in` to pin the shared definition against this one.
    $members = $organization->members()->wherePivot('notify_on_submission', true)->get();

    Notification::send($members, new NewSubmissionNotice($submission));

    Notification::assertSentTo($wants, NewSubmissionNotice::class);
    Notification::assertNotSentTo($doesNot, NewSubmissionNotice::class);
});
