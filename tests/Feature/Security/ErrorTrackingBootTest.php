<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\get;

use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Laravel\Features\ConsoleIntegration;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

// Production once SENTRY_LARAVEL_DSN is set: with a DSN at boot the package binds its event handlers and
// HTTP middleware and decorates the route dispatchers, none of which ErrorTrackingTest reaches (it sets
// its DSN after boot). A .invalid host never resolves, so nothing can be sent.
beforeAll(function () {
    $_SERVER['SENTRY_LARAVEL_DSN'] = $_ENV['SENTRY_LARAVEL_DSN'] = 'https://public@sentry.invalid/1';
    putenv('SENTRY_LARAVEL_DSN=https://public@sentry.invalid/1');
});

afterAll(function () {
    unset($_SERVER['SENTRY_LARAVEL_DSN']);
    $_ENV['SENTRY_LARAVEL_DSN'] = '';
    putenv('SENTRY_LARAVEL_DSN=');
});

it('serves the site and the panel logins with the tracker wired at boot', function (string $path) {
    expect(config('sentry.dsn'))->not->toBeEmpty();
    get($path)->assertOk();
})->with(['/', '/admin/login', '/org/login', '/review/login']);

it('keeps log context, command lines, query values and trace headers out of what the wired tracker records', function () {
    // Keeps every event it is given instead of sending it, as in ErrorTrackingTest.
    $transport = new class implements TransportInterface
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
    app()->extend(ClientBuilder::class, fn (ClientBuilder $builder): ClientBuilder => $builder->setTransport($transport));
    app()->forgetInstance(HubInterface::class);
    app(HubInterface::class);

    // Breadcrumbs from the package's own handlers, so a category or a setting renamed in an update fails here.
    Log::warning('Mail to sara@example.org failed', ['userId' => 7]);
    // As if command_info were lost in an update: the scrubber still drops the command line.
    config()->set('sentry.breadcrumbs.command_info', true);
    (new ConsoleIntegration(app()))->commandStarting(new CommandStarting(
        'cass:demo-seed',
        new ArgvInput(['artisan', 'cass:demo-seed', '--owner-email=sara@example.org', '--reviewer-password=a-demo-password']),
        new NullOutput,
    ));
    DB::table('users')->where('email', 'sara@example.org')->exists();
    Http::fake();
    Http::get('https://hc-ping.example/a-secret-check');
    config()->set('queue.default', 'database');
    dispatch(static fn () => null);
    report(new RuntimeException('tracker test'));

    $sent = $transport->events[0]->getBreadcrumbs();
    $crumbs = collect($sent)->keyBy(fn (Breadcrumb $crumb): string => $crumb->getCategory());
    expect($crumbs->keys()->all())->toContain('log.warning', 'artisan.command', 'db.sql.query')->not->toContain('http')
        ->and($crumbs['log.warning']->getMessage())->toBe('Mail to [email] failed')
        ->and($crumbs['log.warning']->getMetadata())->toBe([])
        ->and($crumbs['artisan.command']->getMetadata())->toBe([])
        ->and($crumbs['db.sql.query']->getMetadata())->not->toHaveKey('bindings')
        ->and((string) json_encode(array_map(fn (Breadcrumb $crumb): array => [$crumb->getMessage(), $crumb->getMetadata()], $sent)))
        ->not->toContain('sara@example.org')->not->toContain('a-demo-password')
        ->and(json_decode((string) DB::table('jobs')->value('payload'), true))->toBeArray()
        ->not->toHaveKey('sentry_baggage_data')->not->toHaveKey('sentry_trace_parent_data');
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('baggage') && ! $request->hasHeader('sentry-trace'));
});
