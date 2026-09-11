<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Models\Submission;

/**
 * A submission plus - only when one was just minted - the plaintext access
 * token for it. The token is null on every save after the first, because the
 * plaintext of an existing token does not exist anywhere to return.
 *
 * It is a separate object rather than a second return value or a transient
 * model attribute so that a caller cannot accidentally persist it: there is
 * exactly one property holding a secret, it is readonly, and it never touches
 * the model.
 */
final readonly class SubmissionLink
{
    public function __construct(
        public Submission $submission,
        public ?string $token,
    ) {}

    public function url(): ?string
    {
        return $this->token === null ? null : $this->submission->statusUrl($this->token);
    }
}
