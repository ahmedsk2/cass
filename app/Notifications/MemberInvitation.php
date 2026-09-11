<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * Deliberately *not* a spec 5.9 template key and deliberately not editable per
 * conference: it is a platform message about a platform account, sent before
 * the recipient has any relationship with the organization beyond this
 * invitation, and it has nothing to do with any one conference.
 *
 * It still lands in `email_logs`: RecordOutgoingEmail writes a row for every
 * message and reads the X-CASS-Organization header added below, exactly as it
 * does for NewSubmissionNotice.
 *
 * The plaintext token travels in the queued job payload for as long as the job
 * is queued. That is the same exposure Plan 3's status links already have, and
 * it is written down in the runbook rather than pretended away.
 */
class MemberInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Organization $organization,
        public OrganizationRole $role,
        public string $token,
        public string $inviterName,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('invitation.accept', ['token' => $this->token]);

        return (new MailMessage)
            ->subject(__('members.mail.subject', ['organization' => $this->organization->name]))
            ->greeting(__('members.mail.greeting'))
            ->line(__('members.mail.intro', [
                'inviter' => $this->inviterName,
                'organization' => $this->organization->name,
                'role' => $this->role->getLabel(),
            ]))
            ->action(__('members.mail.action'), $url)
            ->line(__('members.mail.expiry', ['days' => (int) config('cass.invitations.expiry_days')]))
            ->line(__('members.mail.ignore'))
            ->withSymfonyMessage(function (Email $message): void {
                $message->getHeaders()->addTextHeader('X-CASS-Organization', (string) $this->organization->getKey());
            });
    }
}
