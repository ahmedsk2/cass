<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationRegistered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Organization $organization, public User $owner) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New organization awaiting approval: {$this->organization->name}")
            ->line("{$this->owner->name} ({$this->owner->email}) registered **{$this->organization->name}**.")
            ->line('Type: '.$this->organization->type->getLabel())
            ->line('Country: '.(config('cass.countries')[$this->organization->country] ?? $this->organization->country))
            ->line('Website: '.($this->organization->website ?: 'not given'))
            ->line('Purpose: '.$this->organization->purpose)
            ->action('Review in admin panel', url('/admin/organizations/'.$this->organization->id));
    }
}
