<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Organization $organization, public string $reason, public bool $wasApproved = false) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @param  User  $notifiable */
    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->organization->name;

        return (new MailMessage)
            ->subject($this->wasApproved ? "{$name} has been suspended on CASS" : "Update on your CASS registration for {$name}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->wasApproved
                ? "**{$name}** has been suspended and its conferences are no longer public."
                : "We could not approve **{$name}** at this time.")
            ->line("Reason: {$this->reason}")
            ->line('Reply to this email with more details and we will take another look.');
    }
}
