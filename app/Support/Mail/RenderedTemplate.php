<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * A subject line and a Markdown body, either as an organizer typed them or as
 * RenderEmailTemplate finished them. Readonly so nothing downstream can edit a
 * rendered message in place and leave the `email_logs` subject disagreeing with
 * what was actually sent.
 */
final readonly class RenderedTemplate
{
    public function __construct(
        public string $subject,
        public string $body,
    ) {}
}
