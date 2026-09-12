<?php

declare(strict_types=1);

namespace App\Actions\Demo;

use App\Actions\Mail\SendTemplatedEmail;
use App\Enums\EmailTemplateKey;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Submission;

/**
 * The stand-in `cass:demo-seed` binds over App\Actions\Mail\SendTemplatedEmail
 * for the length of its run.
 *
 * `Mail::fake()` and `Notification::fake()` between them stop every message
 * leaving - including the queued ones, which matters most, because in
 * production the queue worker is a separate process holding the real mail
 * configuration and would happily deliver anything this process pushed. Neither
 * of them stops the `email_logs` row, though: SendTemplatedEmail writes it
 * itself, before the mailable is queued, precisely so that TemplatedMail::failed()
 * has a row id to mark. A demo run must not leave a hundred rows in an
 * organizer's mail log describing letters nobody was ever sent, so the writer
 * itself is replaced rather than the transport under it.
 *
 * The returned EmailLog is deliberately **unsaved**. Every caller inside the
 * seeder (SubmitAbstract, InviteReviewer) ignores the return value; nothing in
 * the seeder calls SendOneDecisionEmail, which is the one caller that reads it.
 */
final class SilentTemplatedEmail extends SendTemplatedEmail
{
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
        return new EmailLog;
    }
}
