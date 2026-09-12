<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\OrganizationStatus;
use App\Enums\ReviewStatus;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Submission;
use App\Models\SubmissionDecision;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Notifications\OrganizationApproved;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Support\Testing\Fakes\NotificationFake;

/** The three reviewer addresses the seeder owns, as an operator reads them. */
const DEMO_REVIEWERS = [
    'demo.reviewer1@example.com',
    'demo.reviewer2@example.com',
    'demo.reviewer3@example.com',
];

beforeEach(function () {
    // StoreSubmissionFile writes to the private `local` disk; faking it keeps
    // fifteen generated PDFs out of storage/app/private and lets the assertions
    // below read the bytes back.
    Storage::fake('local');

    config(['cass.admin_email' => 'owner@example.org']);

    $this->owner = User::factory()->platformAdmin()->create([
        'email' => 'owner@example.org',
        'name' => 'Demo Owner',
    ]);
});

it('seeds an open conference with abstracts and PDFs, and sends nothing', function () {
    $this->artisan('cass:demo-seed', ['--stage' => 'open', '--reviewer-password' => 'demo-password-1234'])
        ->assertSuccessful();

    $organization = Organization::query()->where('slug', 'demo-society')->firstOrFail();

    expect($organization->is_demo)->toBeTrue()
        ->and($organization->status)->toBe(OrganizationStatus::Approved)
        ->and($organization->primary_color)->toBe('#176BB8')
        ->and($organization->accent_color)->toBe('#0F4C8A')
        ->and($organization->publish_contact_email)->toBeFalse()
        ->and($organization->owners()->whereKey($this->owner->getKey())->exists())->toBeTrue();

    $conference = Conference::query()->where('slug', 'demo-2027')->firstOrFail();

    expect($conference->status)->toBe(ConferenceStatus::Open)
        ->and($conference->organization_id)->toBe($organization->id)
        ->and($conference->reference_prefix)->toBe('DEMO27')
        ->and($conference->word_limit)->toBe(300)
        ->and($conference->timezone)->toBe('Asia/Riyadh')
        ->and($conference->blind_review)->toBeTrue()
        ->and($conference->tracks()->count())->toBe(3)
        ->and($conference->customFields()->count())->toBe(2)
        ->and($conference->customFields()->where('hide_from_reviewers', true)->count())->toBe(1)
        // PublishConference mints the short link, which is what the poster and
        // the QR code print.
        ->and($conference->shortLink()->exists())->toBeTrue();

    expect($conference->submissions()->where('status', SubmissionStatus::Submitted)->count())->toBe(12)
        ->and($conference->submissions()->where('status', SubmissionStatus::Draft)->count())->toBe(2)
        ->and($conference->submissions()->where('status', SubmissionStatus::Withdrawn)->count())->toBe(1);

    $references = $conference->submissions()
        ->where('status', SubmissionStatus::Submitted)
        ->orderBy('id')
        ->pluck('reference')
        ->all();

    expect($references)->toBe([
        'DEMO27-001', 'DEMO27-002', 'DEMO27-003', 'DEMO27-004', 'DEMO27-005', 'DEMO27-006',
        'DEMO27-007', 'DEMO27-008', 'DEMO27-009', 'DEMO27-010', 'DEMO27-011', 'DEMO27-012',
    ]);

    // Two or three authors each, exactly one of them corresponding, and every
    // address inside the RFC 2606 example.com domain.
    $submission = $conference->submissions()->where('reference', 'DEMO27-001')->firstOrFail();

    expect($submission->authors()->count())->toBeGreaterThanOrEqual(2)
        ->and($submission->authors()->where('is_corresponding', true)->count())->toBe(1)
        ->and($submission->authors()->where('email', 'not like', '%@example.com')->count())->toBe(0)
        ->and($submission->word_count)->toBeGreaterThanOrEqual(150)
        ->and($submission->word_count)->toBeLessThanOrEqual(250)
        ->and($submission->custom_field_values)->toHaveKey('ethics_approval_reference');

    // One file per abstract, sniffed as a PDF by StoreSubmissionFile itself.
    expect(SubmissionFile::query()->count())->toBe(15);

    foreach (SubmissionFile::query()->get() as $file) {
        Storage::disk('local')->assertExists((string) $file->path);
        expect($file->mime)->toBe('application/pdf')
            ->and(substr((string) Storage::disk('local')->get((string) $file->path), 0, 5))->toBe('%PDF-');
    }

    // Nothing left the building and nothing was logged as if it had.
    //
    // The seeder goes through the real actions, so the real actions raise their
    // real notifications - `assertSentTimes` below is the proof that
    // ApproveOrganization actually ran rather than being skipped. What makes
    // the run silent is that the seeder installed the two fakes first, so a
    // notification never reaches a channel and a mailable never reaches the
    // queue that a production worker would drain with the real mail
    // configuration. The one thing a fake would NOT have stopped is the
    // `email_logs` row SendTemplatedEmail writes itself, and that is what the
    // count on the last line is about.
    expect(Mail::getFacadeRoot())->toBeInstanceOf(MailFake::class)
        ->and(Notification::getFacadeRoot())->toBeInstanceOf(NotificationFake::class);

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
    Notification::assertSentTimes(OrganizationApproved::class, 1);

    expect(EmailLog::query()->count())->toBe(0);
});

it('closes submissions, attaches reviewers and computes scores at the reviewing stage', function () {
    $this->artisan('cass:demo-seed', ['--stage' => 'reviewing', '--reviewer-password' => 'demo-password-1234'])
        ->assertSuccessful();

    $conference = Conference::query()->where('slug', 'demo-2027')->firstOrFail();

    expect($conference->status)->toBe(ConferenceStatus::Reviewing)
        ->and($conference->activeReviewers()->count())->toBe(3);

    foreach (DEMO_REVIEWERS as $email) {
        $reviewer = User::query()->where('email', $email)->firstOrFail();

        expect($reviewer->hasVerifiedEmail())->toBeTrue()
            ->and($reviewer->isActiveReviewer($conference))->toBeTrue()
            ->and(Hash::check('demo-password-1234', (string) $reviewer->password))->toBeTrue();
    }

    // Two reviewers over eight abstracts, and a third with drafts only, so the
    // progress meter and the reminder command both have something to say.
    expect(Review::query()->where('status', ReviewStatus::Submitted)->count())->toBe(16)
        ->and(Review::query()->where('status', ReviewStatus::Draft)->count())->toBe(2);

    $thirdReviewer = User::query()->where('email', 'demo.reviewer3@example.com')->firstOrFail();

    expect(Review::query()->where('reviewer_user_id', $thirdReviewer->getKey())->where('status', ReviewStatus::Submitted)->count())->toBe(0);

    expect($conference->submissions()->whereNotNull('score')->count())->toBe(8)
        ->and($conference->submissions()->where('status', SubmissionStatus::UnderReview)->count())->toBe(8)
        ->and($conference->submissions()->where('status', SubmissionStatus::Submitted)->count())->toBe(4)
        ->and($conference->submissions()->where('review_count', 2)->count())->toBe(8);

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
    expect(Notification::getFacadeRoot())->toBeInstanceOf(NotificationFake::class)
        ->and(EmailLog::query()->count())->toBe(0);
});

it('applies decisions without sending them and without marking the conference decided', function () {
    $this->artisan('cass:demo-seed', ['--stage' => 'decided', '--reviewer-password' => 'demo-password-1234'])
        ->assertSuccessful();

    $conference = Conference::query()->where('slug', 'demo-2027')->firstOrFail();

    // The owner clicks "Send decision emails" and "Mark decided" themselves -
    // that is the half of the loop this stage sets up rather than performs.
    expect($conference->status)->toBe(ConferenceStatus::Reviewing);

    expect(Submission::query()->where('decision', Decision::AcceptedOral)->count())->toBe(4)
        ->and(Submission::query()->where('decision', Decision::AcceptedPoster)->count())->toBe(4)
        ->and(Submission::query()->where('decision', Decision::Waitlisted)->count())->toBe(2)
        ->and(Submission::query()->where('decision', Decision::Rejected)->count())->toBe(2)
        ->and(Submission::query()->whereNotNull('decision_notified_at')->count())->toBe(0)
        ->and(SubmissionDecision::query()->count())->toBe(12)
        ->and(SubmissionDecision::query()->whereNotNull('notified_at')->count())->toBe(0);

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
    expect(Notification::getFacadeRoot())->toBeInstanceOf(NotificationFake::class)
        ->and(EmailLog::query()->count())->toBe(0);
});

it('is idempotent by slug and points a second run at the reset command', function () {
    $this->artisan('cass:demo-seed', ['--stage' => 'open', '--reviewer-password' => 'demo-password-1234'])
        ->assertSuccessful();

    $this->artisan('cass:demo-seed', ['--stage' => 'open', '--reviewer-password' => 'demo-password-1234'])
        ->expectsOutputToContain('cass:demo-reset')
        ->assertSuccessful();

    expect(Organization::withTrashed()->where('slug', 'demo-society')->count())->toBe(1)
        ->and(Conference::query()->count())->toBe(1)
        ->and(Submission::query()->count())->toBe(15);
});

it('refuses a slug collision with an organization that is not the demo one', function () {
    Organization::factory()->approved()->create(['name' => 'Real Society', 'slug' => 'demo-society']);

    $this->artisan('cass:demo-seed', ['--stage' => 'open'])
        ->expectsOutputToContain('demo-society')
        ->assertFailed();

    expect(Conference::query()->count())->toBe(0)
        ->and(Organization::query()->where('slug', 'demo-society')->value('is_demo'))->toBeFalsy();
});

it('refuses when the owner email has no account', function () {
    config(['cass.admin_email' => 'nobody@example.org']);

    $this->artisan('cass:demo-seed', ['--stage' => 'open'])
        ->expectsOutputToContain('nobody@example.org')
        ->assertFailed();

    expect(Organization::query()->where('slug', 'demo-society')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'nobody@example.org')->exists())->toBeFalse();
});

it('takes the owner from --owner-email and never touches that account', function () {
    $other = User::factory()->create(['email' => 'chair@example.org', 'name' => 'Demo Chair']);
    $password = (string) $other->password;

    $this->artisan('cass:demo-seed', ['--stage' => 'open', '--owner-email' => 'chair@example.org'])
        ->assertSuccessful();

    $organization = Organization::query()->where('slug', 'demo-society')->firstOrFail();

    expect($organization->owners()->whereKey($other->getKey())->exists())->toBeTrue()
        ->and($organization->owners()->whereKey($this->owner->getKey())->exists())->toBeFalse()
        ->and((string) $other->fresh()?->password)->toBe($password);
});

it('prints a generated reviewer password once, and the summary the operator needs', function () {
    $status = Artisan::call('cass:demo-seed', ['--stage' => 'reviewing']);
    $output = Artisan::output();

    expect($status)->toBe(0);

    preg_match('/Reviewer password\s*[|:]?\s*([A-Za-z0-9]{16})\b/', $output, $matches);

    $password = $matches[1] ?? '';

    expect($password)->toHaveLength(16)
        // Printed once, so an operator who scrolls past it has to re-seed.
        ->and(substr_count($output, $password))->toBe(1);

    $reviewer = User::query()->where('email', 'demo.reviewer1@example.com')->firstOrFail();

    expect(Hash::check($password, (string) $reviewer->password))->toBeTrue();

    $conference = Conference::query()->where('slug', 'demo-2027')->firstOrFail();

    expect($output)->toContain($conference->publicUrl())
        ->toContain('/org/demo-society')
        ->toContain('/review')
        ->toContain('owner@example.org')
        ->toContain('demo.reviewer3@example.com')
        ->toContain('cass:demo-reset --confirm');
});

it('refuses a stage it does not know', function () {
    $this->artisan('cass:demo-seed', ['--stage' => 'finished'])
        ->expectsOutputToContain('finished')
        ->assertFailed();

    expect(Organization::query()->where('slug', 'demo-society')->exists())->toBeFalse();
});
