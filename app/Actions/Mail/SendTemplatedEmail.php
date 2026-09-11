<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Enums\EmailLogStatus;
use App\Enums\EmailTemplateKey;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Submission;
use Illuminate\Support\Facades\Mail;

/**
 * The one way this application sends a conference-scoped email.
 *
 * The `email_logs` row is written **here**, before the mailable is queued,
 * rather than in the MessageSending listener, for two reasons: this is the only
 * place that knows the organization, the conference, the submission and the
 * template key, and TemplatedMail::failed() needs a row id to mark failed when
 * the transport throws - at which point MessageSending has fired but
 * MessageSent never will.
 */
class SendTemplatedEmail
{
    public function __construct(private readonly RenderEmailTemplate $render) {}

    /**
     * @param  array<string, string|null>  $values
     */
    public function handle(
        EmailTemplateKey $key,
        Conference $conference,
        string $toEmail,
        array $values,
        ?Submission $submission = null,
    ): EmailLog {
        $rendered = $this->render->handle($key, $conference, $values);

        $log = new EmailLog;
        $log->forceFill([
            'organization_id' => $conference->organization_id,
            'conference_id' => $conference->getKey(),
            'submission_id' => $submission?->getKey(),
            'template_key' => $key->value,
            'mailable' => TemplatedMail::class,
            'to_email' => $toEmail,
            // Never store an author's bearer token, whatever a template said.
            // EmailTemplateKey::subjectPlaceholders() keeps `status_link` out of
            // the editor's subject field; this is the belt to that braces, for
            // any path that does not go through the editor at all. The row is
            // listed in the admin panel and is never pruned.
            //
            // Redacted here and trimmed to EmailLog::SUBJECT_MAX_LENGTH by the
            // model's own mutator, in that order: trimming first could cut a
            // token in half and leave the half this pattern no longer matches.
            'subject' => (string) preg_replace('#/s/[A-Za-z0-9]{64}#', '/s/[redacted]', $rendered->subject),
            'status' => EmailLogStatus::Queued,
        ]);
        $log->save();

        Mail::to($toEmail)->queue(new TemplatedMail(
            logUlid: (string) $log->ulid,
            subjectLine: $rendered->subject,
            body: $rendered->body,
            organization: $conference->organization,
            templateKey: $key->value,
        ));

        return $log;
    }
}
