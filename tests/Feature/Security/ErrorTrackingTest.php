<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Sentry\ClientBuilder;
use Sentry\Event;
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
        ->and(config('sentry.breadcrumbs'))->toBe(['livewire' => false, 'cache' => false, 'http_client_requests' => false, 'sql_bindings' => false]);

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
