<?php

declare(strict_types=1);

use App\Enums\EmailLogStatus;
use App\Enums\OrganizationRole;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\MemberInvitation;
use App\Notifications\NewSubmissionNotice;
use App\Notifications\OrganizationRegistered;
use App\Notifications\SendQueuedNotificationsWithLog;
use App\Support\Tokens\InvitationToken;
use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

// No Mail::fake() and no Notification::fake() here, for the reason
// EmailLogPipelineTest gives: both fakes short-circuit the mailer before
// MessageSending fires, and MessageSending is what writes a notification's row.
// Instead the default mailer is swapped for a real Symfony transport that
// refuses whichever recipients the test names - the same exception an SMTP
// server that is down or rejecting the login throws.

/**
 * @param  Closure(string): bool  $refuses  given each recipient address, true to throw
 */
function refuseMailTo(Closure $refuses): void
{
    Mail::extend('refusing', fn () => new class($refuses) extends AbstractTransport
    {
        public function __construct(private Closure $refuses)
        {
            parent::__construct();
        }

        protected function doSend(SentMessage $message): void
        {
            foreach ($message->getEnvelope()->getRecipients() as $recipient) {
                if (($this->refuses)($recipient->getAddress())) {
                    throw new TransportException('Connection could not be established with host "smtp.example.org:587"');
                }
            }
        }

        public function __toString(): string
        {
            return 'refusing://';
        }
    });

    config()->set('mail.mailers.refusing', ['transport' => 'refusing']);
    config()->set('mail.default', 'refusing');
}

/**
 * What supervisord runs in production (docker/supervisord.conf: --tries=3),
 * until the queue is empty. --memory is raised because the worker stops itself
 * when the PROCESS passes the limit, and this process is the whole test suite;
 * --timeout=0 because a worker that times a job out kills its own process.
 */
function workTheQueue(): void
{
    expect(Artisan::call('queue:work', [
        '--stop-when-empty' => true,
        '--tries' => 3,
        '--sleep' => 0,
        '--timeout' => 0,
        '--memory' => 2048,
    ]))->toBe(0);
}

it('marks a notification row failed when its queued job fails', function () {
    refuseMailTo(fn (string $address): bool => true);

    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->published()->create();
    $submission = Submission::factory()->for($conference)->submitted()->create();
    $member = User::factory()->create(['email' => 'member@example.org']);
    $organization->addMember($member, OrganizationRole::Member);

    // QUEUE_CONNECTION=sync (phpunit.xml): SyncQueue::handleException() calls
    // $job->fail() - the same path the worker takes on a final attempt - and
    // then rethrows to the caller.
    expect(fn () => $member->notify(new NewSubmissionNotice($submission)))
        ->toThrow(TransportException::class);

    $log = EmailLog::query()->sole();

    expect($log->mailable)->toBe(NewSubmissionNotice::class)
        ->and($log->status)->toBe(EmailLogStatus::Failed)
        ->and($log->error)->toContain('smtp.example.org')
        ->and($log->sent_at)->toBeNull();
});

it('keeps one row through three attempts and marks it failed after the last, through an encrypted payload', function () {
    $attempts = 0;
    refuseMailTo(function (string $address) use (&$attempts): bool {
        $attempts++;

        return true;
    });
    config()->set('queue.default', 'database');

    $organization = Organization::factory()->approved()->create();

    Notification::route('mail', 'invited@example.org')->notify(new MemberInvitation(
        $organization,
        OrganizationRole::Admin,
        InvitationToken::generate(),
        'Dr Owner',
    ));

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

    // The container binding is what puts this class on the job, and Plan 6's
    // encryption still holds: SendQueuedNotifications::__construct() copies
    // ShouldBeEncrypted off the notification and the subclass inherits it.
    expect($payload['data']['commandName'])->toBe(SendQueuedNotificationsWithLog::class)
        ->and($payload['data']['command'])->not->toStartWith('O:');

    workTheQueue();

    // One row, not three. Every attempt renders a new Symfony message and
    // fires MessageSending again, and until the row was keyed by the
    // notification's id each attempt wrote a row of its own.
    $log = EmailLog::query()->sole();

    expect($attempts)->toBe(3)
        ->and($log->to_email)->toBe('invited@example.org')
        ->and($log->status)->toBe(EmailLogStatus::Failed)
        ->and($log->error)->toContain('smtp.example.org')
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('reports a notification that succeeds on a retry as sent, with no row left behind at queued', function () {
    $attempts = 0;
    refuseMailTo(function (string $address) use (&$attempts): bool {
        return ++$attempts === 1;
    });
    config()->set('queue.default', 'database');

    $admin = User::factory()->platformAdmin()->create(['email' => 'admin@example.org']);
    $owner = User::factory()->create();
    $organization = Organization::factory()->create();

    $admin->notify(new OrganizationRegistered($organization, $owner));

    workTheQueue();

    $log = EmailLog::query()->sole();

    expect($attempts)->toBe(2)
        ->and($log->status)->toBe(EmailLogStatus::Sent)
        ->and($log->sent_at)->not->toBeNull()
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('reuses the row when a failed notification is retried from failed_jobs', function () {
    $refusing = true;
    refuseMailTo(function (string $address) use (&$refusing): bool {
        return $refusing;
    });
    config()->set('queue.default', 'database');

    $admin = User::factory()->platformAdmin()->create(['email' => 'admin@example.org']);
    $admin->notify(new OrganizationRegistered(Organization::factory()->create(), User::factory()->create()));

    workTheQueue();

    expect(EmailLog::query()->sole()->status)->toBe(EmailLogStatus::Failed);

    // The transport is fixed and support runs the runbook's
    // `queue:retry all`. The retried job carries the same notification id,
    // so it must find the failed row rather than insert a second one with
    // the same ULID - which the unique key would turn into an exception
    // inside MessageSending, failing the very retry that was meant to work.
    $refusing = false;
    expect(Artisan::call('queue:retry', ['id' => ['all']]))->toBe(0);

    workTheQueue();

    // Still one row, and still `failed` with the first try's error: sent()
    // flips only a `queued` row, which is how a retried templated email has
    // always read too (runbook, "Sending decision emails").
    expect(EmailLog::query()->sole())
        ->status->toBe(EmailLogStatus::Failed)
        ->error->toContain('smtp.example.org')
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('marks only the row of the recipient whose message failed', function () {
    refuseMailTo(fn (string $address): bool => $address === 'broken@example.org');
    config()->set('queue.default', 'database');

    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->published()->create();
    $submission = Submission::factory()->for($conference)->submitted()->create();
    $broken = User::factory()->create(['email' => 'broken@example.org']);
    $fine = User::factory()->create(['email' => 'fine@example.org']);
    $organization->addMember($broken, OrganizationRole::Owner);
    $organization->addMember($fine, OrganizationRole::Member);

    // ONE notification object to two members - SubmitAbstract's shape.
    // NotificationSender::queueNotification() gives each notifiable its own
    // id, and that id is the whole correlation.
    Notification::send(collect([$broken, $fine]), new NewSubmissionNotice($submission));

    workTheQueue();

    $rows = EmailLog::query()->orderBy('to_email')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->to_email)->toBe('broken@example.org')
        ->and($rows[0]->status)->toBe(EmailLogStatus::Failed)
        ->and($rows[1]->to_email)->toBe('fine@example.org')
        ->and($rows[1]->status)->toBe(EmailLogStatus::Sent)
        ->and($rows[1]->error)->toBeNull();
});

it('marks a failed filament password reset too, a notification this application does not own', function () {
    refuseMailTo(fn (string $address): bool => true);

    $user = User::factory()->create(['email' => 'forgot@example.org']);

    // Exactly what RequestPasswordReset does (vendor/filament/filament/src/
    // Auth/Pages/PasswordReset/RequestPasswordReset.php): resolve the class
    // from the container, set the url, notify.
    $notification = app(ResetPassword::class, ['token' => Str::random(64)]);
    $notification->url = 'https://cass.test/org/password-reset/reset?token=x';

    expect(fn () => $user->notify($notification))->toThrow(TransportException::class);

    expect(EmailLog::query()->sole())
        ->mailable->toBe(ResetPassword::class)
        ->status->toBe(EmailLogStatus::Failed);
});

it('leaves the mail row alone when another channel of the same notification fails', function () {
    $user = User::factory()->create();
    $notification = new NewSubmissionNotice(Submission::factory()->submitted()->create());
    $notification->id = (string) Str::uuid();

    $log = EmailLog::factory()->create(['ulid' => EmailLog::ulidForNotification($notification->id)]);

    // NotificationSender::queueNotification() queues one job per channel, all
    // with the same id. Only a failed MAIL job may touch the mail row.
    (new SendQueuedNotificationsWithLog($user, $notification, ['database']))
        ->failed(new RuntimeException('the database channel failed'));

    expect($log->refresh()->status)->toBe(EmailLogStatus::Queued);
});
