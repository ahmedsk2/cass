<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
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
 * ShouldBeEncrypted because the 64-hex token this carries is the whole
 * credential: anybody holding it can POST /invite/{token} and take an Owner or
 * Admin membership of this organization. Without it the plaintext sits in
 * jobs.payload while the job is queued and - after a final failure - in
 * failed_jobs.payload, which routes/console.php keeps for 720 hours, longer
 * than the 14-day invitation expiry. SendQueuedNotifications reads the
 * interface off the NOTIFICATION (:111) exactly as SendQueuedMailable reads it
 * off the mailable, so this is the same closure Task 8 put on TemplatedMail and
 * ContactMessage.
 */
class MemberInvitation extends Notification implements ShouldBeEncrypted, ShouldQueue
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
