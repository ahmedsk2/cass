<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\EmailLogStatus;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Support\Branding\OrganizationTheme;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every conference-scoped email. The subject and the Markdown body arrive
 * already rendered from RenderEmailTemplate, so this class decides nothing
 * about wording - only about branding, headers and what happens when the send
 * fails.
 *
 * The property is `$subjectLine`, not `$subject`: Mailable already owns
 * `$subject`, and shadowing it makes envelope() fight the parent.
 */
class TemplatedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $logUlid,
        public string $subjectLine,
        public string $body,
        public Organization $organization,
        public string $templateKey,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    /**
     * X-CASS-Log is the correlation id RecordOutgoingEmail reads back when
     * MessageSent fires (fact 7). It stays on the delivered message on purpose:
     * the runbook's triage section uses it to match an SMTP log line to a row
     * in email_logs, and a ULID reveals nothing about volume.
     */
    public function headers(): Headers
    {
        // No X-CASS-Organization here, deliberately. RecordOutgoingEmail reads
        // the context headers only for a message that has *no* X-CASS-Log,
        // which a templated send always has - SendTemplatedEmail wrote the row,
        // with organization_id, conference_id and submission_id on it, before
        // queueing. So the tenant's auto-increment id was read by nothing on
        // this path and travelled in clear text to every author anyway.
        return new Headers(text: [
            'X-CASS-Log' => $this->logUlid,
            'X-CASS-Template' => $this->templateKey,
        ]);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.templated', with: [
            'theme' => OrganizationTheme::for($this->organization),
        ]);
    }

    /**
     * Called by Illuminate\Mail\SendQueuedMailable::failed() (fact 8). An
     * update() rather than a find-and-save: the row may have been written by a
     * different process, and there is nothing on the model worth loading.
     *
     * Guarded on `queued`, exactly as RecordOutgoingEmail::sent() is guarded:
     * a job that throws *after* the transport accepted the message still ends
     * up here, and rewriting a row MessageSent has already flipped to `sent`
     * would have the admin panel report a delivered email as failed and send
     * support chasing a message the author already has.
     */
    public function failed(Throwable $exception): void
    {
        EmailLog::query()->where('ulid', $this->logUlid)->where('status', EmailLogStatus::Queued->value)->update([
            'status' => EmailLogStatus::Failed->value,
            'error' => Str::limit($exception->getMessage(), 2000, ''),
            'updated_at' => now(),
        ]);
    }
}
