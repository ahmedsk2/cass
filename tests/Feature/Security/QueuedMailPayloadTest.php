<?php

declare(strict_types=1);

use App\Actions\Mail\SendTemplatedEmail;
use App\Actions\Organizations\InviteMember;
use App\Enums\EmailLogStatus;
use App\Enums\EmailTemplateKey;
use App\Enums\OrganizationRole;
use App\Mail\ContactMessage;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\MemberInvitation;
use App\Notifications\SendQueuedNotificationsWithLog;
use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

it('declares every queued token carrier encrypted', function (string $class) {
    // SendQueuedMailable::__construct() sets shouldBeEncrypted from the
    // MAILABLE (:76), SendQueuedNotifications::__construct() from the
    // NOTIFICATION (:111), and Queue::jobShouldBeEncrypted() reads that
    // property (:293-300) - so the interface on the message is what encrypts
    // the job.
    expect(is_subclass_of($class, ShouldBeEncrypted::class))->toBeTrue($class);
})->with([TemplatedMail::class, ContactMessage::class, MemberInvitation::class, SendQueuedNotificationsWithLog::class]);

it('encrypts the queued invitation, so an Owner membership cannot be lifted out of the jobs table', function () {
    config()->set('queue.default', 'database');

    $organization = Organization::factory()->approved()->create();
    $owner = User::factory()->create();
    $organization->addMember($owner, OrganizationRole::Owner);

    app(InviteMember::class)->handle($organization, 'invitee@example.org', OrganizationRole::Admin, $owner);

    $payload = (string) DB::table('jobs')->value('payload');

    // The plaintext in the link is what POST /invite/{token} accepts, and
    // failed_jobs keeps the same payload for 720 hours (routes/console.php:15)
    // - longer than the 14-day invitation expiry.
    $invitation = OrganizationInvitation::query()->where('email', 'invitee@example.org')->firstOrFail();

    expect($payload)->not->toBe('')
        ->and($payload)->not->toMatch('/[0-9a-f]{64}/')
        ->and($payload)->not->toContain((string) $invitation->token_hash)
        ->and(json_decode($payload, true)['data']['command'])->not->toStartWith('O:');
});

it('encrypts the queued payload, so a token cannot be read out of the jobs table', function () {
    config()->set('queue.default', 'database');

    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->create();
    $token = str_repeat('t', 64);

    app(SendTemplatedEmail::class)->handle(
        EmailTemplateKey::SubmissionReceived,
        $conference,
        'author@example.org',
        [
            'author_name' => 'Sara Al-Harbi',
            'title' => 'Early mobilisation',
            'reference' => 'AAM26-001',
            'conference' => (string) $conference->name,
            'organization' => (string) $organization->name,
            'deadline' => '1 January 2027',
            'status_link' => url('/s/'.$token),
        ],
    );

    $payload = (string) DB::table('jobs')->value('payload');
    $decoded = json_decode($payload, true);

    expect($payload)->not->toBe('')
        // /s/{token} opens an abstract with no account at all
        // (routes/web.php:116), and failed_jobs keeps the same payload for 720
        // hours (routes/console.php:14).
        ->and($payload)->not->toContain($token)
        // displayName (Queue.php:178) and commandName (:207) are metadata
        // Laravel never encrypts - displayName is literally
        // get_class($this->mailable) (SendQueuedMailable::displayName()), so
        // 'TemplatedMail' IS in the payload and asserting otherwise can never
        // pass. `command` is the serialized mailable and is what carries the
        // body: it is an encrypted blob rather than a PHP serialization.
        ->and($decoded['data']['commandName'])->toBe(SendQueuedMailable::class)
        ->and($decoded['data']['command'])->not->toStartWith('O:')
        ->and(base64_decode($decoded['data']['command'], true))->not->toBeFalse();
});

it('encrypts every queued notification, so a password-reset token cannot be read out of the jobs table either', function () {
    config()->set('queue.default', 'database');

    $user = User::factory()->create(['email' => 'forgot@example.org']);
    $token = str_repeat('r', 64);

    // Exactly what Filament's RequestPasswordReset does. ResetPassword is
    // Filament's class, queued, with the plaintext token in a public property
    // and in the URL - and it does not implement ShouldBeEncrypted, so
    // without the job doing it the token sat in jobs.payload and, after a
    // final failure, in failed_jobs.payload for 720 hours.
    $notification = app(ResetPassword::class, ['token' => $token]);
    $notification->url = url('/org/password-reset/reset?token='.$token);

    $user->notify($notification);

    $payload = (string) DB::table('jobs')->value('payload');
    $decoded = json_decode($payload, true);

    expect($payload)->not->toBe('')
        ->and($payload)->not->toContain($token)
        ->and($decoded['data']['commandName'])->toBe(SendQueuedNotificationsWithLog::class)
        ->and($decoded['data']['command'])->not->toStartWith('O:');

    // And the worker still decrypts it and delivers the link.
    expect(Artisan::call('queue:work', [
        '--stop-when-empty' => true,
        '--sleep' => 0,
        '--timeout' => 0,
        '--memory' => 2048,
    ]))->toBe(0);

    $delivered = Mail::mailer('array')->getSymfonyTransport()->messages();

    expect($delivered)->toHaveCount(1)
        ->and((string) $delivered->first()?->getOriginalMessage()->getTextBody())->toContain($token)
        ->and(EmailLog::query()->sole()->status)->toBe(EmailLogStatus::Sent)
        ->and(DB::table('jobs')->count())->toBe(0);
});
