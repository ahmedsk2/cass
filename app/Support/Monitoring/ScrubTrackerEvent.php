<?php

declare(strict_types=1);

namespace App\Support\Monitoring;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Stacktrace;

/**
 * config/sentry.php's before_send: the last look at an event before it leaves for the tracker. The settings
 * there keep request bodies, personal data and the breadcrumbs that carry form fields out; this removes what
 * they let through.
 *
 * - Frame arguments. The image leaves zend.exception_ignore_args off, so every trace records them and the SDK
 *   sends them: an Action's $data would arrive whole, abstract and authors included.
 * - Email addresses in exception messages and breadcrumbs: a mail server names the address it refused, and
 *   MySQL quotes a duplicate value.
 * - The request beyond its method and path: headers (CF-Connecting-IP and Referer among them), the query
 *   string (a password-reset link carries an address) and the bearer token in /s/{token} and /invite/{token}.
 */
final class ScrubTrackerEvent
{
    private const string ADDRESS = '/[^\s@<>()\[\]"\',;:]+@[^\s@<>()\[\]"\',;:]+\.[a-z]{2,}/i';

    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::maskAddresses($exception->getValue()));
            self::dropArguments($exception->getStacktrace());
        }

        self::dropArguments($event->getStacktrace());

        $event->setBreadcrumb(array_map(
            static fn (Breadcrumb $crumb): Breadcrumb => $crumb->getMessage() === null
                ? $crumb
                : $crumb->withMessage(self::maskAddresses($crumb->getMessage())),
            $event->getBreadcrumbs(),
        ));

        $request = $event->getRequest();

        if ($request !== []) {
            $url = is_string($request['url'] ?? null)
                ? (string) preg_replace(['/[?#].*$/s', '#/(s|invite)/[^/]+#'], ['', '/$1/[token]'], $request['url'])
                : null;

            $event->setRequest(array_filter(['method' => $request['method'] ?? null, 'url' => $url]));
        }

        return $event;
    }

    private static function maskAddresses(string $text): string
    {
        return (string) preg_replace(self::ADDRESS, '[email]', $text);
    }

    private static function dropArguments(?Stacktrace $stacktrace): void
    {
        foreach ($stacktrace?->getFrames() ?? [] as $frame) {
            $frame->setVars([]);
        }
    }
}
