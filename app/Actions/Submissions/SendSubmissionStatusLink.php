<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Actions\Mail\SendTemplatedEmail;
use App\Enums\EmailTemplateKey;
use App\Enums\SubmissionStatus;
use App\Exceptions\SubmissionNotAcceptable;
use App\Models\EmailLog;
use App\Models\Submission;
use App\Support\Tokens\SubmissionToken;

/**
 * Two jobs, one class: the email an author gets when a draft is first saved,
 * and the "Resend status link" an organizer clicks when the author says the
 * link never arrived.
 *
 * `$plainToken` follows exactly the rule SubmitAbstract::handle() follows: pass
 * the token you already hold and the author's existing link keeps working; pass
 * null - or anything that does not match the stored hash - and a new one is
 * minted, which kills every link already in circulation. The check matters
 * because the value goes into a link this class *emails*: an unverified token
 * from another abstract would hand this author an editing credential for
 * somebody else's work.
 * The public form passes the token SaveSubmissionDraft just returned, so the
 * "your draft is saved" email and the page the author is redirected to are the
 * same URL. The organizer's "Resend status link" passes null, because support
 * resends a link precisely when the old one was lost or forwarded and leaving
 * it alive would defeat both reasons.
 */
class SendSubmissionStatusLink
{
    public function __construct(
        private readonly IssueSubmissionToken $issueToken,
        private readonly SendTemplatedEmail $sendTemplatedEmail,
    ) {}

    public function handle(Submission $submission, ?string $plainToken = null): EmailLog
    {
        $author = $submission->correspondingAuthor();

        // Not just null. Mailable::setAddress() silently drops an empty
        // address, and the message then fails at send time inside the queue
        // worker with "An email must have a To, Cc, or Bcc header", leaving an
        // email_logs row stuck at `queued` and nobody told. The form's own
        // rule - the ticked author's address is required - is what stops this
        // arising; this is the guard that makes it impossible.
        if ($author === null || filter_var((string) $author->email, FILTER_VALIDATE_EMAIL) === false) {
            throw SubmissionNotAcceptable::because('This abstract has no corresponding author with a usable email address to send a link to.');
        }

        $token = SubmissionToken::matches($submission->access_token_hash, $plainToken)
            ? (string) $plainToken
            : $this->issueToken->handle($submission);

        // The wording has to match what the author will actually see when they
        // follow the link: "your draft is saved" over a submitted abstract
        // would make them submit it again.
        $key = $submission->status === SubmissionStatus::Draft
            ? EmailTemplateKey::SubmissionDraftSaved
            : EmailTemplateKey::SubmissionReceived;

        return $this->sendTemplatedEmail->handle(
            $key,
            $submission->conference,
            (string) $author->email,
            SubmitAbstract::placeholderValues($submission, (string) $author->name, $token),
            $submission,
        );
    }
}
