<?php

declare(strict_types=1);

namespace App\Actions\Decisions;

use App\Actions\Mail\RenderEmailTemplate;
use App\Actions\Mail\SendTemplatedEmail;
use App\Actions\Submissions\IssueSubmissionToken;
use App\Enums\Decision;
use App\Exceptions\DecisionNotAcceptable;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Submission;
use App\Models\SubmissionDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One decision letter: claim the row, render it, queue it, store it.
 *
 * Split out of SendDecisionEmails because the per-row "Resend" needs exactly
 * this and nothing else, and a resend that took a different path from the bulk
 * send is how the stored letter and the delivered letter come apart.
 *
 * **This action rotates the author's status token, and that is not a side
 * effect - it is the only way to put a working link in the letter.** Spec
 * section 9 stores `submissions.access_token_hash` as a SHA-256 of a plaintext
 * that exists only inside an emailed link, so nothing here can reconstruct the
 * token from the confirmation email. IssueSubmissionToken mints a new one and
 * replaces the hash, which kills the older link - exactly as Plan 3's "Resend
 * status link" does, and for the same reason. Every decided author gets a
 * letter, so every decided author gets a live link. The send-emails modal says
 * so before the organizer clicks, and the runbook says so again.
 *
 * **The letter is rendered twice and stored once, and the two renders differ in
 * exactly one value.** SendTemplatedEmail renders internally and returns an
 * EmailLog carrying the delivered (and /s/-redacted) subject, but not the body -
 * the body IS the email. So the Markdown is rendered here for storage and the
 * subject is taken from the log, which is what the author actually saw.
 * RenderEmailTemplate is a pure function of the template row and the value bag,
 * both unchanged between the two calls inside one request.
 *
 * The one difference is `status_link`, which is withheld from the STORED render.
 * The delivered body carries the real link - that is the email. The stored body
 * must not: this task's own option 2, "store the plaintext token", is refused by
 * spec section 9, and a letter row holding the live /s/ token is that refusal
 * broken - submission_decisions would become a plaintext bearer-token store for
 * every notified author, kept for ever, in a column nothing treats as a
 * credential. RenderEmailTemplate::fill() skips a null value with isset()
 * (app/Actions/Mail/RenderEmailTemplate.php:118-120), so `{{status_link}}` stays
 * literal in the stored copy and Task 8 substitutes it from the token the reader
 * already has in their URL.
 *
 * Widening SendTemplatedEmail's return type to serve one caller would change the
 * one pipeline every email in this application goes through.
 */
class SendOneDecisionEmail
{
    public function __construct(
        private readonly RenderEmailTemplate $render,
        private readonly SendTemplatedEmail $send,
        private readonly IssueSubmissionToken $issueToken,
    ) {}

    /**
     * @return list<string> empty when the letter can be sent
     */
    public function blockers(Submission $submission): array
    {
        if ($submission->decision === null) {
            return [__('decisions.errors.not_decided')];
        }

        $conference = $submission->conference;

        if ($conference === null) {
            return [__('decisions.errors.no_conference')];
        }

        // The letter's whole point is the link in it, so the send is gated on
        // the exact condition the status page enforces rather than on a
        // hand-copied status list: SubmissionStatus::mount() aborts 404 unless
        // the conference is publicly visible AND its organization is approved
        // (app/Livewire/Public/SubmissionStatus.php:65-74, with
        // ConferenceStatus::isPublic() at app/Enums/ConferenceStatus.php:69-71
        // excluding Archived). Sending from an archived conference - or one
        // whose organization was suspended - rotates the author's token to
        // produce a link that does not open, and kills the old one on the way.
        // The ranking page is deliberately visible for Archived (Task 4) so a
        // committee can still read what it decided; SENDING from it is not, and
        // this is the same intent as ApplyDecision's own conference-status
        // window, expressed against the condition that actually breaks.
        if (! $conference->isPubliclyVisible() || $conference->organization?->isApproved() !== true) {
            return [__('decisions.errors.conference_not_sending', [
                'status' => mb_strtolower($conference->status->getLabel()),
            ])];
        }

        if ($submission->currentDecision() === null) {
            // The denormalised column without a history row: only reachable by
            // a hand-written UPDATE, and a letter with no row to store itself
            // on would silently lose the audit.
            return [__('decisions.errors.no_history')];
        }

        $author = $submission->correspondingAuthor();

        // Not just null. Mailable::setAddress() silently drops an empty
        // address and the message then fails inside the queue worker with "An
        // email must have a To, Cc, or Bcc header", leaving an email_logs row
        // stuck at `queued` and nobody told - the exact failure
        // SendSubmissionStatusLink guards against.
        if ($author === null || filter_var((string) $author->email, FILTER_VALIDATE_EMAIL) === false) {
            return [__('decisions.errors.no_author_email')];
        }

        return [];
    }

    public function handle(Submission $submission, ?User $actor = null): EmailLog
    {
        $reasons = $this->blockers($submission);

        if ($reasons !== []) {
            // Every refusal happens HERE, before the claim below, so a skipped
            // row is left exactly as pending() found it and a second click
            // reaches it once the address (or the conference status) is fixed.
            throw new DecisionNotAcceptable($reasons);
        }

        /** @var Decision $decision */
        $decision = $submission->decision;
        /** @var SubmissionDecision $row */
        $row = $submission->currentDecision();
        /** @var Conference $conference */
        $conference = $submission->conference;
        $author = $submission->correspondingAuthor();

        $key = $decision->templateKey();

        // CLAIM the row before the token is rotated or anything is queued. A
        // conditional UPDATE is the only thing that makes a double click, two
        // organizers clicking at once, or a retried request idempotent:
        // SendDecisionEmails reads pending() and the stamp used to be written
        // only after the mail was queued, so two overlapping runs both saw the
        // same rows, both minted a token - IssueSubmissionToken REPLACES the
        // hash - and both queued a letter, leaving the author with two emails
        // of which the first one's link was already dead. The second caller now
        // matches zero rows and skips instead.
        $previousNotifiedAt = $submission->decision_notified_at;
        $claimedAt = now();

        $claimed = Submission::query()
            ->whereKey($submission->getKey())
            ->where('decision', $decision->value)
            ->when(
                $previousNotifiedAt === null,
                fn (Builder $query): Builder => $query->whereNull('decision_notified_at'),
                fn (Builder $query): Builder => $query->where('decision_notified_at', $previousNotifiedAt),
            )
            ->update(['decision_notified_at' => $claimedAt]);

        if ($claimed === 0) {
            // Somebody else is sending this row, or the decision changed under
            // us since pending() read it.
            throw new DecisionNotAcceptable([__('decisions.errors.already_sending')]);
        }

        // TWO blocks, and the line between them is the queue push.
        //
        // Everything up to and including $this->send->handle() is still
        // reversible from this application's point of view: nothing has left
        // the building, so a failure there releases the claim and pending()
        // finds the row again on the next click.
        try {
            $token = $this->issueToken->handle($submission);
            $values = self::placeholderValues($submission, (string) $author?->name, $decision, $token);

            // The DELIVERED body carries the real link - that is the email.
            $log = $this->send->handle($key, $conference, (string) $author?->email, $values, $submission);
        } catch (Throwable $exception) {
            // Put the row back the way pending() found it. A claim that
            // outlives a failed send is a row nobody will ever be told about
            // again - worse than the duplicate it was guarding against.
            $this->releaseClaim($submission, $claimedAt, $previousNotifiedAt);

            throw $exception;
        }

        $letterStored = true;

        try {
            // The STORED body must not. `status_link => null` makes
            // RenderEmailTemplate::fill() skip the placeholder with isset()
            // (app/Actions/Mail/RenderEmailTemplate.php:118-120), so
            // `{{status_link}}` is left literal and Task 8 substitutes it from
            // the token the reader already holds in their own URL. A
            // letter_markdown carrying the live /s/{64} token would make
            // submission_decisions a plaintext bearer-token store for every
            // notified author - option 2 of this task's own three, refused by
            // spec section 9.
            $stored = $this->render->handle($key, $conference, ['status_link' => null] + $values);

            DB::transaction(function () use ($submission, $row, $stored, $log, $claimedAt): void {
                $row->forceFill([
                    // The DELIVERED subject: SendTemplatedEmail redacts any /s/
                    // token out of it before both the log row and the message
                    // (app/Actions/Mail/SendTemplatedEmail.php:52), so this is
                    // what the author saw in their inbox.
                    'letter_subject' => $log->subject,
                    'letter_markdown' => $stored->body,
                    'notified_at' => $claimedAt,
                ])->save();

                // The claim above already wrote decision_notified_at; re-saving
                // the stale in-memory model would clobber a concurrent claim.
                // syncOriginal() so a later line in the same request reads the
                // fresh value without a second query.
                $submission->forceFill(['decision_notified_at' => $claimedAt])->syncOriginal();
            });
        } catch (Throwable $exception) {
            // PAST the queue push, so the claim is KEPT. The author's token has
            // already been rotated and the message is already on the queue:
            // releasing the claim here would put the row back in pending(), and
            // the next "Send decision emails" would mint a THIRD token and queue
            // a second letter whose link kills the one in the first. The letter
            // columns on the history row stay null, which /s/{token} reads as
            // "decision pending" - a page that is behind, rather than a second
            // email that is wrong - and the organizer's route back is the
            // per-row Resend, which is visible for a notified row precisely
            // here. Logged rather than swallowed silently, with the two ids an
            // operator needs to find the row.
            //
            // Swallowed rather than rethrown, and for the same reason a row
            // with no usable address is skipped rather than fatal: this is one
            // row's problem, and a bulk run of two hundred letters must not
            // abandon the other hundred and ninety-nine over it. The audit
            // entry below records that the letter went out WITHOUT its stored
            // copy, so the trail does not claim more than happened.
            $letterStored = false;

            Log::error('The decision letter was queued but could not be stored.', [
                'submission_id' => $submission->getKey(),
                'email_log_ulid' => (string) $log->ulid,
                'exception' => $exception,
            ]);
        }

        // One activity entry per letter, in the action that sends it, so the
        // bulk path and the resend path cannot record different things. Spec
        // section 9 wants the decision trail auditable, and this is the step
        // that rotates the author's access token - the most consequential thing
        // an organizer does to a person who has no account. The
        // conference-level `conference.decisions_sent` entry stays: that one is
        // the run, this one is the row. The actor is PASSED IN, never read from
        // auth() here, which is the rule every activity() call in app/ follows.
        activity()
            ->performedOn($submission)
            ->causedBy($actor)
            ->withProperties([
                'decision' => $decision->value,
                'email_log_ulid' => (string) $log->ulid,
                // Never the address and never the token: the activity log is
                // readable by the platform admin. email_logs already holds the
                // recipient, behind EmailLogPolicy.
                'token_rotated' => true,
                // False when the mail was queued but the stored copy could not
                // be written: /s/{token} then shows "decision pending" to an
                // author who is holding the letter, and a per-row Resend is the
                // fix. Recorded so the trail says which of the two happened.
                'letter_stored' => $letterStored,
            ])
            ->log('submission.decision_letter_sent');

        return $log;
    }

    /**
     * Put `decision_notified_at` back where pending() found it, and only if
     * this call is still the one holding the claim - `where decision_notified_at
     * = $claimedAt` - so a release cannot clobber a claim somebody else has
     * taken in the meantime.
     */
    private function releaseClaim(Submission $submission, mixed $claimedAt, mixed $previousNotifiedAt): void
    {
        Submission::query()
            ->whereKey($submission->getKey())
            ->where('decision_notified_at', $claimedAt)
            ->update(['decision_notified_at' => $previousNotifiedAt]);
    }

    /**
     * The spec 5.9 placeholder bag for the four decision keys, which declare
     * exactly: author_name, title, reference, conference, organization,
     * decision, status_link (app/Enums/EmailTemplateKey.php:71-74). One method
     * so a template that starts using one of them does not need a second call
     * site updated - the same shape as
     * SubmitAbstract::placeholderValues().
     *
     * @return array<string, string|null>
     */
    public static function placeholderValues(
        Submission $submission,
        string $authorName,
        Decision $decision,
        string $token,
    ): array {
        $conference = $submission->conference;

        return [
            'author_name' => $authorName,
            'title' => (string) $submission->title,
            'reference' => $submission->reference,
            'conference' => (string) $conference?->name,
            'organization' => (string) $conference?->organization?->name,
            // The sentence fragment an author reads: "has been **accepted for
            // oral presentation**".
            'decision' => $decision->getLabel(),
            'status_link' => $submission->statusUrl($token),
        ];
    }
}
