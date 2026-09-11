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

        // Never let a bearer token into a subject line, whatever a template
        // said.
        //
        // Redacted *once*, here, and used for both the row and the delivered
        // message. Redacting only the logged copy would be redacting the wrong
        // one: the row sits behind the admin panel, while the Subject header
        // travels in clear text through every relay between this process and
        // the author's provider. The body still carries the real link - that is
        // the email.
        //
        // Two bearer-token URL shapes now: Plan 3's author status link and Plan
        // 4's member/reviewer invitation link. Both are credentials, and a
        // Subject header travels in clear text through every relay between here
        // and the recipient - and is stored verbatim in email_logs, which an
        // organizer can read. The editor's subjectPlaceholders() is the braces;
        // this is the belt, for any path that does not go through the editor.
        $subject = (string) preg_replace(
            ['#/s/[A-Za-z0-9]{64}#', '#/invite/[A-Za-z0-9]{64}#'],
            ['/s/[redacted]', '/invite/[redacted]'],
            $rendered->subject,
        );

        $log = new EmailLog;
        $log->forceFill([
            'organization_id' => $conference->organization_id,
            'conference_id' => $conference->getKey(),
            'submission_id' => $submission?->getKey(),
            'template_key' => $key->value,
            'mailable' => TemplatedMail::class,
            'to_email' => $toEmail,
            // Redacted above and trimmed to EmailLog::SUBJECT_MAX_LENGTH by the
            // model's own mutator, in that order: trimming first could cut a
            // token in half and leave the half the pattern no longer matches.
            'subject' => $subject,
            'status' => EmailLogStatus::Queued,
        ]);
        $log->save();

        Mail::to($toEmail)->queue(new TemplatedMail(
            logUlid: (string) $log->ulid,
            subjectLine: $subject,
            body: $rendered->body,
            organization: $conference->organization,
            templateKey: $key->value,
        ));

        return $log;
    }
}
