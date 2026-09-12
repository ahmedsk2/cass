<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ShouldBeEncrypted for the same reason TemplatedMail is, with personal data in
 * place of a credential: the three promoted properties are a visitor's name,
 * address and message, and SerializesModels leaves plain strings alone. Without
 * the interface they sit in cleartext in jobs.payload and, on a final failure,
 * in failed_jobs.payload - the same table, kept for the same 720 hours
 * (routes/console.php).
 *
 * The same APP_KEY caveat applies: drain the queue before rotating it.
 */
class ContactMessage extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public string $senderName, public string $senderEmail, public string $body) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'CASS contact form: '.$this->senderName,
            replyTo: [new Address($this->senderEmail, $this->senderName)],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.contact-message');
    }
}
