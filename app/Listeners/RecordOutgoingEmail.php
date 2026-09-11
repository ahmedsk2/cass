<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\EmailLogStatus;
use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Email;

/**
 * Spec 5.9: "All mail is queued, logged to `email_logs` with status and error".
 *
 * Two halves of one send. MessageSending writes the row for anything that does
 * not already have one (Plan 1's notifications, Filament's password reset and
 * verification mail) and stamps the correlation header; MessageSent flips the
 * row to `sent`. The same Symfony Email instance reaches both events (fact 7),
 * which is what makes a header the correlation key rather than a static map
 * that would leak across a long-running queue worker.
 *
 * Failures: a templated send is marked failed by TemplatedMail::failed(). A
 * *notification* has no such hook, so a transport failure leaves its row at
 * `queued`. That is a known, documented gap (runbook, "Email triage"), not an
 * oversight: a row stuck at `queued` with no `sent_at` is exactly the signal
 * the admin panel's status filter exists to surface.
 */
class RecordOutgoingEmail
{
    public const LOG_HEADER = 'X-CASS-Log';

    private const CONTEXT_HEADERS = [
        'organization_id' => 'X-CASS-Organization',
        'conference_id' => 'X-CASS-Conference',
        'submission_id' => 'X-CASS-Submission',
        'template_key' => 'X-CASS-Template',
    ];

    public function sending(MessageSending $event): void
    {
        $headers = $event->message->getHeaders();

        // SendTemplatedEmail already wrote the row and knows far more about it
        // than this listener could reconstruct.
        if ($headers->has(self::LOG_HEADER)) {
            return;
        }

        $log = new EmailLog;
        $log->forceFill([
            ...$this->context($event->message),
            'mailable' => $this->source($event->data),
            'to_email' => $this->firstRecipient($event->message),
            'subject' => (string) $event->message->getSubject(),
            'status' => EmailLogStatus::Queued,
        ]);
        $log->save();

        $headers->addTextHeader(self::LOG_HEADER, (string) $log->ulid);
    }

    public function sent(MessageSent $event): void
    {
        $header = $event->message->getHeaders()->get(self::LOG_HEADER);

        if ($header === null) {
            return;
        }

        EmailLog::query()
            ->where('ulid', $header->getBodyAsString())
            ->where('status', EmailLogStatus::Queued->value)
            ->update([
                'status' => EmailLogStatus::Sent->value,
                'sent_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * `template_key` stays a string; the three ids are cast to int or null so a
     * header that is somehow not numeric does not become a foreign key of 0.
     *
     * @return array<string, int|string|null>
     */
    private function context(Email $message): array
    {
        $context = [];

        foreach (self::CONTEXT_HEADERS as $column => $header) {
            $value = $message->getHeaders()->get($header)?->getBodyAsString();

            $context[$column] = match (true) {
                $value === null || $value === '' => null,
                $column === 'template_key' => $value,
                ctype_digit($value) => (int) $value,
                default => null,
            };
        }

        return $context;
    }

    /**
     * Laravel puts the originating class in the message data:
     * `__laravel_mailable` for a Mailable (Mailable.php:400) and
     * `__laravel_notification` for a Notification (MailChannel.php:157).
     * Anything else - a raw Mail::raw() - is recorded as such rather than left
     * blank, because "which code sent this" is the first question triage asks.
     *
     * @param  array<string, mixed>  $data
     */
    private function source(array $data): string
    {
        $source = $data['__laravel_mailable'] ?? $data['__laravel_notification'] ?? null;

        return is_string($source) ? $source : 'raw';
    }

    private function firstRecipient(Email $message): string
    {
        $to = $message->getTo();

        return $to === [] ? 'unknown' : $to[0]->getAddress();
    }
}
