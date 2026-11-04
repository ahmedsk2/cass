<?php

declare(strict_types=1);

use App\Support\Monitoring\ScrubTrackerEvent;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Laravel\Features\HttpClientIntegration;
use Sentry\Stacktrace;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    // Keeps every event it is given instead of sending it.
    $this->transport = new class implements TransportInterface
    {
        /** @var list<Event> */
        public array $events = [];

        public function send(Event $event): Result
        {
            $this->events[] = $event;

            return new Result(ResultStatus::success(), $event);
        }

        public function close(?int $timeout = null): Result
        {
            return new Result(ResultStatus::success());
        }
    };

    // The hub the application builds from config/sentry.php, now with a DSN and the transport above: the
    // package binds ClientBuilder afresh on every resolution, and its hub makes itself the SDK's current one.
    $this->track = function (): void {
        config()->set('sentry.dsn', 'https://public@o0.ingest.sentry.io/1');
        app()->extend(ClientBuilder::class, fn (ClientBuilder $builder): ClientBuilder => $builder->setTransport($this->transport));
        app()->forgetInstance(HubInterface::class);
        app(HubInterface::class);
    };
});

it('pins the privacy settings, and reports quietly without a DSN', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.max_request_body_size'))->toBe('never')
        ->and(config('sentry.traces_sample_rate'))->toBeNull()
        ->and(config('sentry.breadcrumbs'))->toBe(['livewire' => false, 'cache' => false, 'http_client_requests' => false, 'sql_bindings' => false, 'command_info' => false]);

    // No DSN in the test environment (phpunit.xml), so this is dropped by the SDK.
    report(new RuntimeException('tracker test'));
    expect(app(HubInterface::class)->getClient()?->getOptions()->getDsn())->toBeNull();
});

it('sends a reported exception once a DSN is set, and none of Laravel\'s unreported ones', function () {
    ($this->track)();
    report(new RuntimeException('tracker test'));
    // Both are in the handler's internal dontReport list (validation, every HTTP exception).
    report(ValidationException::withMessages(['title' => 'Required.']));
    report(new NotFoundHttpException);

    expect($this->transport->events)->toHaveCount(1)
        ->and($this->transport->events[0]->getExceptions()[0]->getValue())->toBe('tracker test');
});

it('adds no trace headers to outgoing requests or queued jobs, and gives up on a slow tracker quickly', function () {
    // With a DSN at boot the package makes this method global Http middleware; tests boot without one.
    ($this->track)();
    $request = app(HttpClientIntegration::class)
        ->attachTracingHeadersToRequest(new PsrRequest('GET', 'https://hc-ping.example/test-check'));

    expect($request->hasHeader('baggage'))->toBeFalse()
        ->and($request->hasHeader('sentry-trace'))->toBeFalse()
        ->and(config('sentry.trace_propagation_targets'))->toBe([])
        // Off, so neither the Http middleware nor the queue payload hook is registered at boot.
        ->and(config('sentry.tracing.http_client_requests'))->toBeFalse()
        ->and(config('sentry.tracing.queue_jobs'))->toBeFalse()
        ->and(config('sentry.tracing.queue_job_transactions'))->toBeFalse()
        ->and(config('sentry.http_connect_timeout'))->toBe(1)
        ->and(config('sentry.http_timeout'))->toBe(2);
});

it('scrubs an event: frame arguments, addresses, headers, the query string and link tokens', function () {
    $frame = new Frame('App\Actions\Submissions\SubmitAbstract::handle', 'app/Actions/Submissions/SubmitAbstract.php', 40, null, null, ['data' => ['title' => 'A study of night shifts']]);
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('550 5.1.1 <sara@example.org>: Recipient address rejected'), new Stacktrace([$frame]))]);
    $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_ERROR, Breadcrumb::TYPE_DEFAULT, 'log.error', 'Mail to sara@example.org failed')]);
    $event->setRequest([
        'method' => 'GET', 'url' => 'https://cass.towardpcc.com/s/a-plain-status-token?email=sara@example.org', 'query_string' => 'email=sara@example.org',
        'headers' => ['cf-connecting-ip' => ['203.0.113.7'], 'referer' => ['https://cass.towardpcc.com/invite/a-plain-invitation-token']],
    ]);

    $scrubbed = ScrubTrackerEvent::beforeSend($event);
    expect($scrubbed->getExceptions()[0]->getValue())->toBe('550 5.1.1 <[email]>: Recipient address rejected')
        ->and($scrubbed->getExceptions()[0]->getStacktrace()?->getFrames()[0]->getVars())->toBe([])
        ->and($scrubbed->getBreadcrumbs()[0]->getMessage())->toBe('Mail to [email] failed')
        ->and($scrubbed->getRequest())->toBe(['method' => 'GET', 'url' => 'https://cass.towardpcc.com/s/[token]']);
});

it('sends a reported exception without frame arguments or addresses', function () {
    ($this->track)();
    $deliver = static function (array $author): void {
        throw new RuntimeException('Could not deliver to '.$author['email']);
    };
    try {
        $deliver(['email' => 'sara@example.org', 'name' => 'Sara Ahmed']);
    } catch (RuntimeException $exception) {
        report($exception);
    }

    $sent = $this->transport->events[0]->getExceptions()[0];
    $frames = $sent->getStacktrace()?->getFrames() ?? [];
    expect($sent->getValue())->toBe('Could not deliver to [email]')
        ->and($frames)->not->toBeEmpty()
        ->and(array_merge(...array_map(fn (Frame $frame): array => $frame->getVars(), $frames)))->toBe([]);
});

it('keeps only the method and the tokenless path of a request', function (string $url, string $expected) {
    $event = Event::createEvent();
    $event->setRequest([
        'method' => 'POST', 'url' => $url, 'query_string' => 'email=sara@example.org',
        'cookies' => ['cass_session' => 'a-session-id'], 'env' => ['REMOTE_ADDR' => '203.0.113.7'],
        'data' => ['title' => 'A study of night shifts'],
    ]);
    expect(ScrubTrackerEvent::beforeSend($event)->getRequest())->toBe(['method' => 'POST', 'url' => $expected]);
})->with([
    'status page' => ['https://cass.towardpcc.com/s/a-plain-status-token?email=sara@example.org', 'https://cass.towardpcc.com/s/[token]'],
    'invitation' => ['https://cass.towardpcc.com/invite/a-plain-invitation-token#accept', 'https://cass.towardpcc.com/invite/[token]'],
]);

it('drops the metadata of log and command breadcrumbs, and masks addresses in the rest', function () {
    $event = Event::createEvent();
    $event->setBreadcrumb([
        new Breadcrumb(Breadcrumb::LEVEL_ERROR, Breadcrumb::TYPE_DEFAULT, 'log.error', 'Mail to sara@example.org failed', ['userId' => 7]),
        new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'artisan.command', 'Starting Artisan command: cass:demo-seed', ['input' => 'cass:demo-seed --owner-email=sara@example.org --reviewer-password=a-demo-password']),
        new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'notification.sent', 'Sent notification', ['notifiable' => 'sara@example.org', 'attempts' => 2]),
    ]);
    $crumbs = ScrubTrackerEvent::beforeSend($event)->getBreadcrumbs();
    expect($crumbs[0]->getMessage())->toBe('Mail to [email] failed')
        ->and($crumbs[0]->getMetadata())->toBe([])
        ->and($crumbs[1]->getMetadata())->toBe([])
        ->and($crumbs[2]->getMetadata())->toBe(['notifiable' => '[email]', 'attempts' => 2]);
});

it('leaves query bindings out of a database error message', function () {
    $message = '';
    try {
        // users.email is NOT NULL: an insert that fails, with a name among its bindings.
        DB::table('users')->insert(['name' => 'Sara Ahmed', 'email' => null, 'password' => 'unused']);
    } catch (QueryException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toContain('insert into')->not->toContain('Sara Ahmed');
});
