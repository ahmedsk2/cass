<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Organizer\Resources\Submissions\SubmissionResource;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * Spec 5.3 step 4: "notification email to organization members who opted in".
 *
 * Deliberately *not* a templated email. It is internal, it is not addressed to
 * an author, and there is no reason an organizer should be able to edit the
 * wording of the message that tells their own committee that work has arrived.
 * It still lands in `email_logs` - RecordOutgoingEmail picks it up and reads
 * the context headers added below.
 */
class NewSubmissionNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Submission $submission) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @param  User  $notifiable */
    public function toMail(object $notifiable): MailMessage
    {
        $conference = $this->submission->conference;
        $author = $this->submission->correspondingAuthor();

        return (new MailMessage)
            ->subject("New abstract for {$conference->name}: {$this->submission->reference}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A new abstract has been submitted to **{$conference->name}**.")
            ->line("**Reference:** {$this->submission->reference}")
            ->line("**Title:** {$this->submission->title}")
            // `->` and not `?->`: the null coalesce already swallows a property
            // read on null, so `?->` here is redundant and Larastan says so
            // (nullsafe.neverNull). This way a recorded author with a blank
            // name or email also falls back instead of printing nothing.
            ->line('**Corresponding author:** '.($author->name ?? 'not recorded').' ('.($author->email ?? 'not recorded').')')
            // The panel, never /s/{token}: a committee forwards this mail, and
            // the author's status link is an editing credential.
            ->action('Open it in the organizer panel', SubmissionResource::getUrl(
                'view',
                ['record' => $this->submission],
                panel: 'organizer',
                tenant: $conference->organization,
            ))
            ->line('You are receiving this because "Email me about new submissions" is on for your membership.')
            ->withSymfonyMessage(function (Email $message) use ($conference): void {
                // Read by RecordOutgoingEmail so this row is filterable in the
                // admin panel by organization and conference.
                $headers = $message->getHeaders();
                $headers->addTextHeader('X-CASS-Organization', (string) $conference->organization_id);
                $headers->addTextHeader('X-CASS-Conference', (string) $conference->getKey());
                $headers->addTextHeader('X-CASS-Submission', (string) $this->submission->getKey());
            });
    }
}
