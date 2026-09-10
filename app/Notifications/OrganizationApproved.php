<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Organization $organization) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @param  User  $notifiable */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->organization->name} is approved on CASS")
            ->greeting("Hello {$notifiable->name},")
            ->line("**{$this->organization->name}** has been approved. You can now create and publish conferences.")
            ->action('Open your dashboard', url('/org/'.$this->organization->slug))
            ->line('If you have questions, reply to this email.');
    }
}
