<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\Submission;
use App\Support\Tokens\SubmissionToken;

/**
 * Spec section 9: author access tokens are stored as SHA-256 hashes and the
 * plaintext appears only in the emailed link.
 *
 * Calling this a second time is the whole of "Resend status link": a new
 * plaintext is minted, the stored hash is replaced, and every link already in
 * circulation stops resolving. That is deliberate - a resend is what support
 * does when an author says the link leaked or was lost, and leaving the old one
 * alive would defeat both reasons.
 */
class IssueSubmissionToken
{
    /** @return string the plaintext token; the caller must use it immediately */
    public function handle(Submission $submission): string
    {
        $plain = SubmissionToken::generate();

        $submission->forceFill(['access_token_hash' => SubmissionToken::hash($plain)])->save();

        return $plain;
    }
}
